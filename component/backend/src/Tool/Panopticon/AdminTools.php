<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Tool\Panopticon;

defined('_JEXEC') || die;

use Akeeba\Component\Mcp4j\Administrator\Mapper\Panopticon\AdminToolsMapper;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\McpTool;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\Schema;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\ToolRequirements;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Exception\ToolException;
use Akeeba\Component\Mcp4j\Administrator\Shared\Panopticon\AbstractPanopticonTool;
use Akeeba\Component\Mcp4j\Administrator\Shared\Panopticon\AdminToolsTrait;

/**
 * MCP tools driving the Akeeba Panopticon Connector's Admin Tools coverage: IP unblocking, the
 * install-time WAF plugin/`.htaccess` kill switches, temporary Super User creation, and the PHP File
 * Change Scanner.
 *
 * Ported from the original `joomla-mcp-php` project's `src/Server/Panopticon/AdminTools.php` — tool
 * names/descriptions, parameters and annotations are copied verbatim from there (see
 * `.plans/00-conventions.md` §2); every method body is a from-scratch reimplementation of the connector's
 * `AdmintoolsController` against Admin Tools' own `com_admintools` models directly. See
 * `.plans/15-panopticon-admintools.md` for the full rationale and the final report for the list of
 * deviations.
 *
 * Several of these tools only work against **Admin Tools Professional** — the Core build excludes
 * `UnblockipModel`, `ScansModel`, `ScanModel`, `ScanalertsModel` and `ScanalertModel` outright (see
 * `AdminToolsTrait::requireAdminToolsPro()`), so those tools throw a clear, consistent
 * `ToolException('This feature requires Admin Tools Professional.')` on a Core-only install rather than a
 * confusing "class not found" fatal.
 *
 * **`panopticon_admintools_tempsuperuser` is the most dangerous tool in this class** — it mints a brand
 * new, fully-privileged Super User account and returns its password in the tool result. See that method's
 * own docblock for the rules this class follows to keep that safe. It is gated on Super User **and**
 * `core.admin` on `com_users` (stricter than every other Panopticon tool's single Super User gate) and
 * genuinely deserves a component-level opt-out (e.g. an `allow_tempsuperuser` option, default off) —
 * adding that requires editing `config.xml`, which is outside `.plans/00-conventions.md` §8's boundaries
 * for this plan; flagged for a follow-up plan instead of added here.
 *
 * The PHP File Change Scanner (`panopticon_admintools_scanner_start`/`_scanner_step`) keeps its working
 * state in the Joomla session under `com_admintools.filescanner.*` (queues of file paths still to scan, a
 * few counters) exactly as Admin Tools' own backend UI does — MCP4j's `SessionManager` persists the whole
 * session into `#__mcp4j_sessions.session_data` (`LONGTEXT`) between calls of the *same* MCP session, which
 * is what lets `_scanner_step` "just work" without the caller ever having to echo scanner state back (the
 * connector's own HTTP API required exactly that, which is why the original `joomla-mcp-php` tool of the
 * same name could never actually work — see the plan's "Deviations" section). That session row can grow
 * large (thousands of queued file paths) while a scan is mid-flight; expected, not a bug.
 *
 * @since  0.0.1
 */
#[ToolRequirements(components: ['com_panopticon', 'com_admintools'])]
class AdminTools extends AbstractPanopticonTool
{
	use AdminToolsTrait;

	#[McpTool(
		name: 'panopticon_admintools_unblock',
		description: 'Unblock an IP address from Admin Tools'
	)]
	public function unblock(
		#[Schema(description: 'The IP address to unblock')]
		?string $ip = null
	): array
	{
		$this->requireSuperUser();
		$this->requireCanManageAdminTools();

		$ip = trim((string) $ip);

		if ($ip === '')
		{
			throw new ToolException('You must provide an IP address.');
		}

		if (strlen($ip) > 255)
		{
			throw new ToolException('Invalid IP address.');
		}

		$this->requireAdminToolsPro('Unblockip');

		$model = $this->createAdminToolsModel('Unblockip');
		$found = $model->unblockIP($ip);

		return ['status' => true, 'found' => (bool) $found];
	}

	#[McpTool(
		name: 'panopticon_admintools_plugin_disable',
		description: 'Disable the Admin Tools system plugin'
	)]
	public function pluginDisable(): array
	{
		$this->requireSuperUser();

		$cp = $this->createAdminToolsModel('Controlpanel');

		clearstatcache();

		if ($cp->isMainPhpDisabled())
		{
			return ['renamed' => true, 'name' => $cp->getRenamedMainPhp()];
		}

		$from = JPATH_PLUGINS . '/system/admintools/services/provider.php';
		$to   = JPATH_PLUGINS . '/system/admintools/services/provider-disable.php';

		if (@rename($from, $to))
		{
			return ['renamed' => true, 'name' => basename($to)];
		}

		return ['renamed' => false, 'name' => null];
	}

	#[McpTool(
		name: 'panopticon_admintools_plugin_enable',
		description: 'Enable the Admin Tools system plugin'
	)]
	public function pluginEnable(): array
	{
		$this->requireSuperUser();

		$cp = $this->createAdminToolsModel('Controlpanel');

		clearstatcache();

		if (!$cp->isMainPhpDisabled())
		{
			return ['renamed' => false, 'name' => null];
		}

		if (!$cp->reenableMainPhp())
		{
			return ['renamed' => true, 'name' => $cp->getRenamedMainPhp()];
		}

		return ['renamed' => false, 'name' => null];
	}

	#[McpTool(
		name: 'panopticon_admintools_htaccess_disable',
		description: 'Disable the Admin Tools .htaccess protection'
	)]
	public function htaccessDisable(): array
	{
		$this->requireSuperUser();

		$from = JPATH_SITE . '/.htaccess';
		$to   = JPATH_SITE . '/.htaccess.admintools';

		clearstatcache();

		$fromExists = file_exists($from);
		$toExists   = file_exists($to);
		$renamed    = null;

		if (AdminToolsMapper::shouldRenameHtaccess($fromExists))
		{
			$renamed = @rename($from, $to);
		}

		return AdminToolsMapper::htaccessDisableResult($fromExists, $toExists, $renamed);
	}

	#[McpTool(
		name: 'panopticon_admintools_htaccess_enable',
		description: 'Enable the Admin Tools .htaccess protection'
	)]
	public function htaccessEnable(): array
	{
		$this->requireSuperUser();

		$from = JPATH_SITE . '/.htaccess.admintools';
		$to   = JPATH_SITE . '/.htaccess';

		clearstatcache();

		$fromExists = file_exists($from);
		$toExists   = file_exists($to);
		$renamed    = null;

		if (AdminToolsMapper::shouldRenameHtaccess($fromExists))
		{
			$renamed = @rename($from, $to);
		}

		return AdminToolsMapper::htaccessEnableResult($fromExists, $toExists, $renamed);
	}

	/**
	 * Creates a brand new, fully-privileged Super User account and returns its password. This is the most
	 * dangerous tool in the whole `pkg_mcp4j` port; the following rules are not optional:
	 *
	 * - The password appears **only** in this method's return value. It must never be passed to
	 *   `Log::add()`, never stored in the session, and never interpolated into an exception message
	 *   ({@see \Akeeba\Component\Mcp4j\Administrator\Mcp\ToolDispatcher::callTool()} only ever logs a
	 *   tool's *arguments* — redacted through `SecretLeakGuard` — never its result, so a clean return value
	 *   here is genuinely the whole story; verified by reading that method before writing this one).
	 * - `expiration` is always bounded to 1 hour .. 7 days by
	 *   {@see AdminToolsMapper::tempSuperUserExpiration()}, however the caller phrases it.
	 * - Gated on Super User **and** `core.admin` on `com_users` — every other tool in this class only
	 *   requires the base Super User check.
	 */
	#[McpTool(
		name: 'panopticon_admintools_tempsuperuser',
		description: 'Create a temporary super user'
	)]
	public function tempSuperUser(
		#[Schema(description: 'Expiration time in seconds')]
		?int $expiration = null
	): array
	{
		$this->requireSuperUser();
		$this->requireAuthorised('core.admin', 'com_users');

		$this->bootAdminTools();

		$tz = (string) $this->getUser()->getParam('timezone', $this->getApplication()->get('offset', 'UTC'));

		$expirationString = AdminToolsMapper::tempSuperUserExpiration(
			$expiration,
			new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
			$tz
		);

		$password = AdminToolsMapper::randomString(32, AdminToolsMapper::PASSWORD_ALPHABET, 'random_int');
		$username = 'tsu_' . AdminToolsMapper::randomString(8, AdminToolsMapper::USERNAME_ALPHABET, 'random_int');

		$groups = $this->superUserGroupIds();

		if ($groups === [])
		{
			throw new ToolException('Cannot determine the Super User groups of this site.');
		}

		$data = [
			'expiration' => $expirationString,
			'name'       => 'Temporary Super User',
			'username'   => $username,
			'password'   => $password,
			'password2'  => $password,
			'email'      => $username . '@nowhere.invalid',
			'groups'     => $groups,
		];

		$model = $this->createAdminToolsModel('Tempsuperuser');

		try
		{
			$saved = $this->withLegacySessionUser(static fn (): bool => (bool) $model->save($data));
		}
		catch (\Throwable $e)
		{
			throw new ToolException('Could not create the temporary Super User: ' . $e->getMessage());
		}

		if (!$saved)
		{
			throw new ToolException('Could not create the temporary Super User: ' . $this->modelError($model, 'Unknown error'));
		}

		$id = (int) $model->getState('tempsuperuser.id');

		return [
			'id'         => $id,
			'username'   => $username,
			'password'   => $password,
			'email'      => $data['email'],
			'expiration' => $expirationString,
			'timezone'   => $tz,
			'groups'     => $groups,
		];
	}

	#[McpTool(
		name: 'panopticon_admintools_scanner_start',
		description: 'Start an Admin Tools security scan'
	)]
	public function scannerStart(): array
	{
		$this->requireSuperUser();
		$this->requireCanManageAdminTools();
		$this->requireAdminToolsPro('Scans');

		try
		{
			$status = (array) $this->createAdminToolsModel('Scans')->startScan('api');
		}
		catch (\Throwable $e)
		{
			throw new ToolException('The file change scanner failed: ' . $e->getMessage());
		}

		$scanId = (int) $this->getApplication()->getSession()->get('com_admintools.filescanner.scanID', 0);

		return AdminToolsMapper::scannerResult($status, $scanId);
	}

	#[McpTool(
		name: 'panopticon_admintools_scanner_step',
		description: 'Execute a step of the Admin Tools security scan'
	)]
	public function scannerStep(): array
	{
		$this->requireSuperUser();
		$this->requireCanManageAdminTools();
		$this->requireAdminToolsPro('Scans');

		$scanId = (int) $this->getApplication()->getSession()->get('com_admintools.filescanner.scanID', 0);

		if ($scanId <= 0)
		{
			throw new ToolException('No scan is in progress in this MCP session. Call panopticon_admintools_scanner_start first.');
		}

		try
		{
			$status = (array) $this->createAdminToolsModel('Scans')->stepScan();
		}
		catch (\Throwable $e)
		{
			throw new ToolException('The file change scanner failed: ' . $e->getMessage());
		}

		return AdminToolsMapper::scannerResult($status, $scanId);
	}

	#[McpTool(
		name: 'panopticon_admintools_scans_list',
		description: 'List Admin Tools security scan results',
		readOnlyHint: true,
	)]
	public function scansList(): array
	{
		$this->requireSuperUser();
		$this->requireCanManageAdminTools();
		$this->requireAdminToolsPro('Scans');

		/** @var \Joomla\CMS\MVC\Model\ListModel $model */
		$model = $this->createAdminToolsModel('Scans');

		return $this->listItems(
			$model,
			AdminToolsMapper::scansListState(),
			null,
			null,
			AdminToolsMapper::SCAN_FIELDS,
			AdminToolsMapper::SCAN_INT_FIELDS
		);
	}

	#[McpTool(
		name: 'panopticon_admintools_scan_read',
		description: 'Read details of a specific security scan',
		readOnlyHint: true,
	)]
	public function scanRead(
		#[Schema(description: 'The ID of the security scan to retrieve')]
		int $id
	): array
	{
		$this->requireSuperUser();
		$this->requireCanManageAdminTools();
		$this->requireAdminToolsPro('Scans');
		$this->requireAdminToolsPro('Scanalerts');

		/** @var \Joomla\CMS\MVC\Model\ListModel $scansModel */
		$scansModel = $this->createAdminToolsModel('Scans');
		$scan       = $this->listItems(
			$scansModel,
			AdminToolsMapper::scanByIdState($id),
			1,
			0,
			AdminToolsMapper::SCAN_FIELDS,
			AdminToolsMapper::SCAN_INT_FIELDS
		);

		if ($scan['items'] === [])
		{
			throw new ToolException(sprintf('Not found: scan #%d', $id));
		}

		/** @var \Joomla\CMS\MVC\Model\ListModel $alertsModel */
		$alertsModel = $this->createAdminToolsModel('Scanalerts');

		// The original tool has no pagination parameters and the connector returned only the alerts list;
		// we return the first 100 alerts plus their own pagination block, so the LLM can tell from
		// alertsPagination.total whether there are more than what's shown here.
		$alerts = $this->listItems(
			$alertsModel,
			AdminToolsMapper::alertsState($id),
			100,
			0,
			AdminToolsMapper::ALERT_LIST_FIELDS,
			AdminToolsMapper::ALERT_INT_FIELDS
		);

		return [
			'scan'             => $scan['items'][0],
			'alerts'           => $alerts['items'],
			'alertsPagination' => $alerts['pagination'],
		];
	}

	#[McpTool(
		name: 'panopticon_admintools_scanalert_read',
		description: 'Read a specific scan alert',
		readOnlyHint: true,
	)]
	public function scanAlertRead(
		#[Schema(description: 'The ID of the security scan')]
		int $scanId,
		#[Schema(description: 'The ID of the scan alert')]
		int $alertId
	): array
	{
		$this->requireSuperUser();
		$this->requireCanManageAdminTools();
		$this->requireAdminToolsPro('Scanalert');

		/** @var \Joomla\CMS\MVC\Model\AdminModel $model */
		$model = $this->createAdminToolsModel('Scanalert');
		$item  = $this->loadItem($model, $alertId, 'scan alert');

		// The connector's own scanalert() action ignores the scan id entirely (it's only ever used to
		// build the id:<n> search filter of the *list* action, never checked against the item it reads) —
		// deliberately corrected here, see the plan's "Deviations" section.
		if ((int) ($item['scan_id'] ?? 0) !== $scanId)
		{
			throw new ToolException(sprintf('Not found: scan alert #%d', $alertId));
		}

		return $this->shape($item, AdminToolsMapper::ALERT_ITEM_FIELDS, AdminToolsMapper::ALERT_INT_FIELDS);
	}
}
