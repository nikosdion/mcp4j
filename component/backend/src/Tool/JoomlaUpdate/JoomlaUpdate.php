<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Tool\JoomlaUpdate;

defined('_JEXEC') || die;

use Akeeba\Component\Mcp4j\Administrator\Bridge\AbstractTool;
use Akeeba\Component\Mcp4j\Administrator\Mapper\JoomlaUpdate\JoomlaUpdateMapper;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\McpTool;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\ToolRequirements;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Exception\ToolException;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Plugin\PluginHelper;

/**
 * MCP tools for the Joomla! automated core-update endpoints (health check, available-update check, prepare,
 * finalize, and the two success/failure notification hooks).
 *
 * Ported from the original `joomla-mcp-php` project's `src/Server/JoomlaUpdate/JoomlaUpdate.php` — but see
 * `.plans/13-media-joomlaupdate.md`'s "Read before coding" section: the original's URLs
 * (`v1/joomlaupdate/update`, `/update/prepare`, ...) do not exist in Joomla core, which routes these under
 * `v1/joomlaupdate/healthcheck`, `.../updates`, `.../updates/{version}` (`getUpdate`/`prepareUpdate`) and
 * `.../updates/notification/{type}`, and the original never sent the required `X-JUpdate-Token` header —
 * every one of its six tools was non-functional against a real site. This port defines each tool's
 * behaviour directly from the core models/views listed in the plan instead of the original's (broken) HTTP
 * calls; method names, tool names/descriptions and annotations are still copied verbatim where the
 * original's intent is unambiguous.
 *
 * **Authorisation.** Real Joomla core Web Services API requests to this component authenticate via a
 * shared-secret `X-JUpdate-Token` header (`Api\Controller\BaseController::validateUpdateToken()`) rather
 * than a normal user identity — appropriate for the Joomla Update Service's own automated health-check
 * ping, meaningless for an MCP client acting as a Joomla user. This package instead requires the calling
 * user's own global `core.admin` right, exactly like the backend `com_joomlaupdate` UI itself
 * (`administrator/components/com_joomlaupdate/src/Dispatcher/Dispatcher.php`: "checked for global
 * core.admin rights - not the usual core.manage for the component"). `prepare`/`finalize` additionally
 * refuse outright unless the site's own Global Configuration → Joomla Update settings have automated
 * updates enabled ({@see JoomlaUpdateMapper::automatedUpdatesEnabled()}) — the same hard gate core's own
 * token validation enforces before it even looks at the token.
 *
 * **This class never actually runs a core update in this package's own test suite** — `prepare`/`finalize`
 * are destructive and irreversible against a real site. E2E coverage is limited to the refusal/validation
 * and read-only paths; see the plan and the final report.
 *
 * @since  0.0.1
 */
#[ToolRequirements(components: ['com_joomlaupdate'], minJoomla: '5.4.0')]
class JoomlaUpdate extends AbstractTool
{
	private const OPTION = 'com_joomlaupdate';

	#[McpTool(
		name: 'joomlaupdate_healthcheck',
		description: 'Check if the Joomla update system is healthy',
		readOnlyHint: true,
	)]
	public function healthcheck(): array
	{
		$this->requireAuthorised('core.admin', 'root.1');
		$this->ensureSessionRegistryIsObject();

		/** @var \Joomla\Component\Joomlaupdate\Administrator\Model\UpdateModel $model */
		$model = $this->createModel(self::OPTION, 'Update');

		$params = ComponentHelper::getParams(self::OPTION)->toArray();
		$db     = $this->getDatabase();

		// Deliberately does NOT call $model->updateLastHealthCheck(): that timestamp is core's own signal
		// to the Joomla Update Service that *it* successfully reached this site — a read performed through
		// this MCP tool must not fake that signal on the site's behalf. See the class/plan docblocks.
		return $this->shape(
			[
				'php_version'                => PHP_VERSION,
				'db_type'                    => $db->getName(),
				'db_version'                 => JoomlaUpdateMapper::normaliseDbVersion($db->getVersion()),
				'cms_version'                => JVERSION,
				'server_os'                  => php_uname('s') . ' ' . php_uname('r'),
				'update_requirement_state'   => $model->getAutoUpdateRequirementsState(),
				'automated_updates_enabled'  => JoomlaUpdateMapper::automatedUpdatesEnabled($params),
			],
			JoomlaUpdateMapper::HEALTHCHECK_FIELDS
		);
	}

	#[McpTool(
		name: 'joomlaupdate_getupdate',
		description: 'Get available Joomla update information',
		readOnlyHint: true,
	)]
	public function getUpdate(): array
	{
		$this->requireAuthorised('core.admin', 'root.1');
		$this->ensureSessionRegistryIsObject();

		/** @var \Joomla\Component\Joomlaupdate\Administrator\Model\UpdateModel $model */
		$model  = $this->createModel(self::OPTION, 'Update');
		$params = ComponentHelper::getParams(self::OPTION)->toArray();

		try
		{
			// Network I/O against the Joomla Update Service — kept out of any loop, and only ever called
			// from this tool and joomlaupdate_prepare (see the plan's gotchas).
			$latest = $model->getAutoUpdateVersion();
		}
		catch (\Throwable $e)
		{
			throw new ToolException(sprintf('Cannot check for Joomla updates: %s', $e->getMessage()), 0, $e);
		}

		$requirementsOk = $model->getAutoUpdateRequirementsState();

		return [
			'currentVersion'          => JVERSION,
			'latestVersion'           => $latest,
			'availableUpdate'         => JoomlaUpdateMapper::availableUpdate($latest, JVERSION, $requirementsOk),
			'automatedUpdatesEnabled' => JoomlaUpdateMapper::automatedUpdatesEnabled($params),
		];
	}

	#[McpTool(
		name: 'joomlaupdate_prepare',
		description: 'Prepare the Joomla update for installation'
	)]
	public function prepare(): array
	{
		$this->requireAuthorised('core.admin', 'root.1');
		$this->ensureSessionRegistryIsObject();

		/** @var \Joomla\Component\Joomlaupdate\Administrator\Model\UpdateModel $model */
		$model  = $this->createModel(self::OPTION, 'Update');
		$params = ComponentHelper::getParams(self::OPTION)->toArray();

		if (!JoomlaUpdateMapper::automatedUpdatesEnabled($params))
		{
			throw new ToolException('Automated updates are disabled on this site. Enable them in System → Global Configuration → Joomla Update first.');
		}

		try
		{
			$latest = $model->getAutoUpdateVersion();
		}
		catch (\Throwable $e)
		{
			throw new ToolException(sprintf('Cannot check for Joomla updates: %s', $e->getMessage()), 0, $e);
		}

		$available = JoomlaUpdateMapper::availableUpdate($latest, JVERSION, $model->getAutoUpdateRequirementsState());

		if ($available === null)
		{
			throw new ToolException('No Joomla update is available for this site.');
		}

		try
		{
			$info = $model->prepareAutoUpdate($available);
		}
		catch (\Throwable $e)
		{
			throw new ToolException(sprintf('Cannot prepare the Joomla update: %s', $e->getMessage()), 0, $e);
		}

		$app = $this->getApplication();

		// Recorded so joomlaupdate_finalize (and the notify_* tools) can pick them back up later in the
		// *same* MCP session — the session bridge persists user state across requests, see
		// .claude/mcp-session-handling.md.
		$app->setUserState('com_joomlaupdate.oldversion', JVERSION);
		$app->setUserState('com_joomlaupdate.file', $info['filename'] ?? null);

		// The one-time extraction password is returned deliberately: it is exactly what core's own
		// prepareUpdate() view returns to its authenticated caller, the caller here has already proven
		// core.admin (effectively Super User) rights, and without it the prepared package cannot be
		// extracted by joomlaupdate_finalize. See .plans/13-media-joomlaupdate.md's "Sensitive output"
		// section.
		return [
			'targetVersion' => $available,
			'filename'      => $info['filename'] ?? null,
			'filesize'      => $info['filesize'] ?? null,
			'password'      => $info['password'] ?? null,
		];
	}

	#[McpTool(
		name: 'joomlaupdate_finalize',
		description: 'Finalize the Joomla update installation'
	)]
	public function finalize(): array
	{
		$this->requireAuthorised('core.admin', 'root.1');
		$this->ensureSessionRegistryIsObject();

		/** @var \Joomla\Component\Joomlaupdate\Administrator\Model\UpdateModel $model */
		$model  = $this->createModel(self::OPTION, 'Update');
		$params = ComponentHelper::getParams(self::OPTION)->toArray();

		if (!JoomlaUpdateMapper::automatedUpdatesEnabled($params))
		{
			throw new ToolException('Automated updates are disabled on this site. Enable them in System → Global Configuration → Joomla Update first.');
		}

		$app  = $this->getApplication();
		$file = $app->getUserState('com_joomlaupdate.file');

		if (empty($file))
		{
			throw new ToolException('Nothing to finalize: run joomlaupdate_prepare in this MCP session and extract the update package first.');
		}

		// Mirrors core's own Api\View\Updates\JsonapiView::finalizeUpdate() exactly: neither step aborts
		// the other on failure, every error is collected on the model instead, and the tool always returns
		// (never throws) so the caller can inspect what happened.
		try
		{
			$model->finaliseUpgrade();
		}
		catch (\Throwable $e)
		{
			$model->collectError('finaliseUpgrade', $e);
		}

		PluginHelper::importPlugin('actionlog');

		try
		{
			$model->cleanUp();
		}
		catch (\Throwable $e)
		{
			$model->collectError('cleanUp', $e);
		}

		$model->resetUpdateSource();

		$errors = array_map(static fn (mixed $error): string => (string) $error, $model->getErrors());

		return ['success' => $errors === [], 'errors' => $errors];
	}

	#[McpTool(
		name: 'joomlaupdate_notify_success',
		description: 'Send notification that the Joomla update was successful'
	)]
	public function notifySuccess(): array
	{
		return $this->notify('success');
	}

	#[McpTool(
		name: 'joomlaupdate_notify_failed',
		description: 'Send notification that the Joomla update failed'
	)]
	public function notifyFailed(): array
	{
		return $this->notify('failed');
	}

	private function notify(string $type): array
	{
		$this->requireAuthorised('core.admin', 'root.1');
		$this->ensureSessionRegistryIsObject();

		/** @var \Joomla\Component\Joomlaupdate\Administrator\Model\NotificationModel $model */
		$model = $this->createModel(self::OPTION, 'Notification');

		$app = $this->getApplication();
		$old = $app->getUserState('com_joomlaupdate.oldversion') ?: JVERSION;

		try
		{
			$model->sendNotification($type, $old, JVERSION);
			$success = true;
		}
		catch (\Throwable)
		{
			// Mail delivery failing (no MTA configured, e.g. in the Docker test environment) is not a tool
			// error — it mirrors core's own finalizeUpdate()/notification() views, which also swallow the
			// exception and report success as a plain boolean.
			$success = false;
		}

		return ['success' => $success];
	}

	/**
	 * Works around a session-bridge limitation, not anything specific to Joomla Update: the MCP session
	 * bridge (`Support\SessionDataCodec`/`Helper\SessionManager`, both outside this plan's ownership —
	 * see `.plans/00-conventions.md` §8) snapshots the whole ephemeral Joomla session as JSON on every
	 * request and restores it on the next one, which loses the `Joomla\Registry\Registry` *class* Joomla's
	 * own `CMSApplication` stores its `'registry'` session key as (the object
	 * `getUserState()`/`setUserState()`/`getUserStateFromRequest()` read/write through) — it comes back as
	 * a plain array instead. From the *second* request of every MCP session onward, that breaks not only
	 * this class's own `getUserState()` calls but also any Joomla core code this class calls into that
	 * happens to touch user state indirectly — observed here via
	 * `UpdateModel::getAutoUpdateRequirementsState()` → `getPhpOptions()` → a nested, *not*
	 * `ignore_request`-protected `com_installer` `DatabaseModel` → `ListModel::populateState()` →
	 * `getUserStateFromRequest()` — with a fatal `Call to a member function get() on array`.
	 *
	 * `Tool\Config\Config::ensureSessionRegistryIsObject()` carries the identical fix for the same
	 * underlying bridge limitation, independently discovered by that plan; see this class's docblock and
	 * the final report for the full analysis and why the fix belongs here (call-site level) rather than in
	 * the session bridge itself.
	 */
	private function ensureSessionRegistryIsObject(): void
	{
		$session  = $this->getApplication()->getSession();
		$registry = $session->get('registry');

		if (is_array($registry))
		{
			$session->set('registry', new \Joomla\Registry\Registry($registry));
		}
	}
}
