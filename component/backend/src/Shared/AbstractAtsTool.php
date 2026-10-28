<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Shared;

defined('_JEXEC') || die;

use Akeeba\Component\ATS\Administrator\Helper\Permissions;
use Akeeba\Component\ATS\Administrator\Table\PostTable;
use Akeeba\Component\ATS\Administrator\Table\TicketTable;
use Akeeba\Component\Mcp4j\Administrator\Bridge\AbstractTool;
use Akeeba\Component\Mcp4j\Administrator\Bridge\Support\Pagination;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Exception\ToolException;
use Joomla\CMS\Access\Access;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Model\AdminModel;
use Joomla\CMS\MVC\Model\ListModel;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\ParameterType;
use Joomla\Registry\Registry;

/**
 * Joomla-and-ATS-coupled machinery shared by every Akeeba Ticket System (`com_ats`) tool class.
 *
 * ATS is a third-party extension: none of its classes exist on a site that doesn't have it installed, so
 * every method here is only ever reached after {@see \Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\ToolRequirements}
 * has already confirmed `com_ats` is installed and enabled (see `.plans/16a-tickets-core.md`). This file
 * is owned by plan 16a; plan 16b (attachments, manager notes) extends it by subclassing — see
 * `.plans/00-conventions.md` §8.
 *
 * @since  0.0.1
 */
abstract class AbstractAtsTool extends AbstractTool
{
	/**
	 * Memoises {@see self::bootAts()} for the lifetime of the current request — every tool method calls it
	 * as its first statement, so without memoising, every single call would re-run the language/version
	 * bootstrap for no benefit. Left `false` if {@see self::bootAts()} throws, so a subsequent call (there
	 * usually isn't one, but nothing stops it) retries rather than silently skipping the version gate.
	 */
	private static bool $atsBooted = false;

	/**
	 * Per-request memoisation for {@see self::userInfo()}, keyed `"$userId:$catid"`.
	 *
	 * @var  array<string, ?array<string, mixed>>
	 */
	private array $userInfoCache = [];

	/**
	 * Boots `com_ats` the way its own Dispatcher would for a real request — none of which ever runs for
	 * us, since our request is dispatched to `com_mcp4j`, not `com_ats`.
	 *
	 * In order: (1) boot the component (registers its classes/tables/services), (2) load its language
	 * files — both administrator and, like ATS's own Dispatcher does even in the API application, the
	 * site-side file, (3) replicate `Dispatcher::loadVersion()` so `ATS_VERSION`/`ATS_DATE`/`ATS_PRO` are
	 * defined before any ATS class that reads them runs, (4) the version gate.
	 *
	 * @throws  ToolException  ATS is older than 5.6.0 (the category-access fixes every tool here relies
	 *                         on were introduced in that release).
	 */
	protected function bootAts(): void
	{
		if (self::$atsBooted)
		{
			return;
		}

		$this->repairSessionRegistry();

		$this->getApplication()->bootComponent('com_ats');

		$this->loadLanguage('com_ats');
		$this->getApplication()->getLanguage()->load('com_ats', JPATH_SITE);

		$versionFile = JPATH_ADMINISTRATOR . '/components/com_ats/version.php';

		if (@is_file($versionFile))
		{
			include_once $versionFile;
		}

		if (!defined('ATS_VERSION'))
		{
			define('ATS_VERSION', 'dev');
		}

		if (!defined('ATS_DATE'))
		{
			define('ATS_DATE', gmdate('Y-m-d'));
		}

		if (!defined('ATS_PRO'))
		{
			define('ATS_PRO', @is_dir(JPATH_ADMINISTRATOR . '/components/com_ats/src/CliCommand') ? '1' : '0');
		}

		// canAccessCategory() was added in ATS 5.6.0 together with the category-access-level fixes every
		// method below depends on (see .plans/16a-tickets-core.md "Facts verified in the ATS source" #2)
		// — do not parse ATS_VERSION, dev builds look like "5.6.1-dev202609010429-revf3eeaa7d".
		if (!method_exists(Permissions::class, 'canAccessCategory'))
		{
			throw new ToolException('Akeeba Ticket System 5.6.0 or later is required for this tool.');
		}

		self::$atsBooted = true;
	}

	/**
	 * Works around a pre-existing bug in this framework's session persistence
	 * ({@see \Akeeba\Component\Mcp4j\Administrator\Support\SessionDataCodec}, out of scope for this plan
	 * to fix — see `.plans/00-conventions.md` §8): the codec round-trips a whole Joomla session's data
	 * through JSON to survive across HTTP requests under the same `Mcp-Session-Id`, which silently
	 * flattens the session's own `'registry'` entry — normally a `\Joomla\Registry\Registry` object that
	 * `CMSApplication::getUserState()`/`setUserState()` read/write through — into a plain array once it's
	 * restored on a later request.
	 *
	 * ATS's `TicketModel::getForm()` calls `$app->setUserState('com_ats.edit.ticket.data', $data)`
	 * unconditionally for every new-ticket save (`TicketModel.php`, guarded only by "is this a new
	 * ticket with non-empty data" — always true for `tickets_tickets_create`), which fatals
	 * (`Call to a member function set() on array`) the moment `'registry'` is anything but a `Registry`
	 * instance or `null`. Repairing it here — restoring a real `Registry`, seeded from whatever scalar
	 * data survived the round-trip — fixes it without touching the framework's session layer.
	 */
	private function repairSessionRegistry(): void
	{
		$session  = $this->getApplication()->getSession();
		$registry = $session->get('registry');

		if ($registry === null || $registry instanceof Registry)
		{
			return;
		}

		$session->set('registry', new Registry(is_array($registry) ? $registry : []));
	}

	/**
	 * Every ATS tool requires the caller to be an ATS manager (`core.admin` or `core.manage` on
	 * `com_ats`) — ATS's own API controllers enforce exactly this, unconditionally, before anything else.
	 *
	 * @throws  ToolException
	 */
	protected function requireAtsManager(): void
	{
		if (!Permissions::isManager(null, (int) $this->getUser()->id))
		{
			throw new ToolException('Permission denied: core.manage on com_ats');
		}
	}

	protected function isAtsPro(): bool
	{
		return defined('ATS_PRO') && ATS_PRO;
	}

	/**
	 * @throws  ToolException  ATS Pro is not installed/active.
	 */
	protected function requireAtsPro(): void
	{
		if (!$this->isAtsPro())
		{
			throw new ToolException('This tool requires Akeeba Ticket System Pro.');
		}
	}

	/**
	 * @throws  ToolException  `Not found: ticket #<id>`.
	 */
	protected function loadTicketTable(int $id): TicketTable
	{
		$model = $this->createModel('com_ats', 'Ticket');

		/** @var TicketTable $table */
		$table = $model->getTable('Ticket', 'Administrator');

		if ($id <= 0 || !$table->load($id) || !$table->getId())
		{
			throw new ToolException(sprintf('Not found: ticket #%d', $id));
		}

		return $table;
	}

	/**
	 * @throws  ToolException  `Not found: post #<id>`.
	 */
	protected function loadPostTable(int $id): PostTable
	{
		$model = $this->createModel('com_ats', 'Post');

		/** @var PostTable $table */
		$table = $model->getTable('Post', 'Administrator');

		if ($id <= 0 || !$table->load($id) || !$table->getId())
		{
			throw new ToolException(sprintf('Not found: post #%d', $id));
		}

		return $table;
	}

	/**
	 * `Permissions::getTicketPrivileges()` honours the category's view access level even for managers
	 * (see the plan's "Facts verified" #2) — a ticket that exists but that the current user cannot see
	 * answers `Not found`, the same way ATS's own API answers 403 for both cases, so ticket IDs can't be
	 * enumerated by comparing error messages.
	 *
	 * @return  array<string, bool>  `Permissions::getTicketPrivileges()`'s result.
	 * @throws  ToolException
	 */
	protected function requireViewTicket(TicketTable $ticket): array
	{
		$privileges = Permissions::getTicketPrivileges($ticket, $this->getUser());

		if (!($privileges['view'] ?? false))
		{
			throw new ToolException(sprintf('Not found: ticket #%d', (int) $ticket->id));
		}

		return $privileges;
	}

	protected function denied(string $action, int $catid): ToolException
	{
		return new ToolException(sprintf('Permission denied: %s on com_ats.category.%d', $action, $catid));
	}

	/**
	 * Re-derives exactly the `'view'` boolean `Permissions::getTicketPrivileges()` computes (see that
	 * method's own docblock in `~/Projects/j5-akeeba/ats/component/backend/src/Helper/Permissions.php`),
	 * but from a plain object carrying `id`/`catid`/`created_by`/`public` rather than a real `TicketTable`
	 * instance.
	 *
	 * security.md M4: the `*_list` tools (unlike their single-record `*_read` siblings) work over raw
	 * query-row `stdClass` objects returned by `TicketsModel`/`PostsModel`/`AttachmentsModel::getItems()`,
	 * never a `TicketTable`. `getTicketPrivileges(TicketTable $ticket, ...)` declares its first parameter
	 * with a strict class type hint, so calling it with a `stdClass` row is a fatal `TypeError`, not a
	 * looser duck-typing pass — loading a real `TicketTable` per candidate row (via
	 * {@see self::loadTicketTable()}) was considered but rejected as an avoidable N+1 query cost for
	 * `Posts`/`Attachments`' list tools, which can touch many distinct tickets per page. Recomputing the
	 * same four lower-level primitives `getTicketPrivileges()` itself calls sidesteps both problems: it
	 * works on any object shape (a raw list row OR a real `TicketTable`, since PHP doesn't care beyond
	 * property access) and needs no extra query beyond what's already in hand.
	 *
	 * Keep this in lockstep with `getTicketPrivileges()`'s own `'view'` expression if ATS ever changes it.
	 */
	protected function ticketRowVisible(object $ticket): bool
	{
		$userId    = (int) $this->getUser()->id;
		$ticketId  = (int) ($ticket->id ?? 0);
		$catid     = (int) ($ticket->catid ?? 0);
		$createdBy = (int) ($ticket->created_by ?? 0);
		$public    = (int) ($ticket->public ?? 0);

		$isOwner        = $createdBy > 0 && $createdBy === $userId;
		$isInvited      = Permissions::isInvited($ticketId, $userId);
		$canSeeCategory = Permissions::canAccessCategory($catid, $this->getUser());
		$canReadPrivate = Permissions::canReadPrivate($catid, $userId);

		return $isOwner || $isInvited || ($canSeeCategory && ($public === 1 || $canReadPrivate));
	}

	/**
	 * Asserts `$catid` is a real `com_ats` category — `Permissions::getAclPrivileges()` falls back to the
	 * `com_ats` component asset for a nonexistent/foreign category id, which would otherwise let a Super
	 * User file a ticket into a `com_content` category.
	 *
	 * @throws  ToolException  `Not found: ticket category #<id>`.
	 */
	protected function requireAtsCategory(int $catid): void
	{
		$db = $this->getDatabase();

		$boundId = $catid;
		$query   = $db->getQuery(true)
			->select('1')
			->from($db->quoteName('#__categories'))
			->where($db->quoteName('id') . ' = :id')
			->where($db->quoteName('extension') . ' = ' . $db->quote('com_ats'))
			->bind(':id', $boundId, ParameterType::INTEGER);

		$exists = (bool) $db->setQuery($query)->loadResult();

		if (!$exists)
		{
			throw new ToolException(sprintf('Not found: ticket category #%d', $catid));
		}
	}

	/**
	 * Re-implements ATS's `UserInfoEnrichmentTrait::getUserInfo()` with an explicit field list — a `User`
	 * object must never be passed straight through to output (see `.plans/00-conventions.md` §5.9).
	 *
	 * Memoised per `userId:catid` within the request, since a single `*_list` response can enrich the
	 * same user (e.g. a prolific ticket creator) many times over.
	 *
	 * @return  ?array{id: int, username: ?string, name: ?string, email: ?string, groups: list<string>, is_manager: bool}
	 *          `null` when `$userId <= 0` (system posts use `created_by = -1`).
	 */
	protected function userInfo(int $userId, ?int $catid): ?array
	{
		if ($userId <= 0)
		{
			return null;
		}

		$cacheKey = $userId . ':' . ($catid ?? '');

		if (array_key_exists($cacheKey, $this->userInfoCache))
		{
			return $this->userInfoCache[$cacheKey];
		}

		$user = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById($userId);

		if (!$user || (int) $user->id <= 0)
		{
			return $this->userInfoCache[$cacheKey] = [
				'id'         => $userId,
				'username'   => null,
				'name'       => null,
				'email'      => null,
				'groups'     => [],
				'is_manager' => false,
			];
		}

		$groups = array_values(array_map(
			static fn (int $gid): string => (string) Access::getGroupTitle($gid),
			Access::getGroupsByUser($userId, false)
		));

		return $this->userInfoCache[$cacheKey] = [
			'id'         => $userId,
			'username'   => $user->username,
			'name'       => $user->name,
			'email'      => $user->email,
			'groups'     => $groups,
			'is_manager' => $catid ? Permissions::isManager($catid, $userId) : false,
		];
	}

	/**
	 * Pre-seeds `<modelName>.id` model state ahead of an update — a workaround needed only for ATS forms
	 * that never declare an explicit `id` field (`forms/post.xml`, unlike `forms/ticket.xml`, which does).
	 *
	 * `AbstractTool::saveItem()` (the bridge; out of scope for this plan to edit — see
	 * `.plans/00-conventions.md` §8) determines the primary key to update purely from
	 * `$data[$key] ?? (int) $this->getState($this->getName() . '.id')`
	 * (`AdminModel::save()`) — it relies entirely on the target form carrying `id` through
	 * `Form::filter()` into the validated data. Every model here is created with `ignore_request => true`
	 * (see `.plans/00-conventions.md` §5.4), so `AdminModel::populateState()` — which would otherwise read
	 * `<name>.id` off the request input itself — never runs either. For a form with no `id` field at all,
	 * both paths come up empty, `AdminModel::save()` falls back to primary key `0`, and silently INSERTS a
	 * duplicate row instead of updating the existing one (found by this plan's own E2E suite: post updates
	 * appeared to silently no-op while quietly leaving an extra, orphaned post behind). Call this on the
	 * exact model instance about to be handed to `saveItem()`, immediately before that call.
	 */
	protected function seedModelId(AdminModel $model, int $id): void
	{
		$model->setState($model->getName() . '.id', $id);
	}

	/**
	 * Like the bridge's `AbstractTool::listItems()`, but with a per-row enrichment callback: ATS rows need
	 * `*_info`/`com_fields`/`tags` added before they're whitelisted, which `OutputShaper::shapeList()`'s
	 * fixed field-list contract can't express.
	 *
	 * @param   array<string, mixed>  $state  `setState()` key => value pairs.
	 * @param   callable               $toRow  `(object $item): array<string, mixed>`.
	 *
	 * @return  array{items: list<array>, pagination: array{total: int, limit: int, offset: int}}
	 * @throws  ToolException
	 */
	protected function listPage(ListModel $model, array $state, ?int $pageLimit, ?int $pageOffset, callable $toRow): array
	{
		[$limit, $offset] = Pagination::normalise($pageLimit, $pageOffset);

		foreach ($state as $key => $value)
		{
			$model->setState($key, $value);
		}

		$model->setState('list.start', $offset);
		$model->setState('list.limit', $limit);

		$items = $model->getItems();

		if ($items === false)
		{
			throw new ToolException($this->modelError($model, 'Cannot list items'));
		}

		return [
			'items'      => array_values(array_map($toRow, $items)),
			'pagination' => [
				'total'  => (int) $model->getTotal(),
				'limit'  => $limit,
				'offset' => $offset,
			],
		];
	}

	/**
	 * Like {@see self::listPage()}, but unpaginated — for the "posts of this ticket" nested tools, which
	 * have no paging parameters in the original.
	 *
	 * @param   array<string, mixed>  $state
	 *
	 * @return  array{items: list<array>, pagination: array{total: int, limit: int, offset: int}}
	 * @throws  ToolException
	 */
	protected function listAll(ListModel $model, array $state, callable $toRow): array
	{
		foreach ($state as $key => $value)
		{
			$model->setState($key, $value);
		}

		$model->setState('list.start', 0);
		$model->setState('list.limit', 0);

		$items = $model->getItems();

		if ($items === false)
		{
			throw new ToolException($this->modelError($model, 'Cannot list items'));
		}

		$rows = array_values(array_map($toRow, $items));

		return [
			'items'      => $rows,
			'pagination' => [
				'total'  => count($rows),
				'limit'  => count($rows),
				'offset' => 0,
			],
		];
	}

	/**
	 * Like {@see self::listPage()}, but additionally filters the model's full matching result set through
	 * a per-row visibility predicate before paginating — security.md M4/M5's fix: `TicketsModel`,
	 * `PostsModel`, `AttachmentsModel` and the generic `com_categories` `CategoriesModel` all filter their
	 * list query by nothing more than a plain Joomla content view-access level (or, for `TicketsModel`,
	 * that plus whatever `filter.catid` the caller sets), never the per-category manage-ACL/privacy check
	 * the equivalent single-record `*_read` tool already enforces via
	 * {@see self::requireViewTicket()}/{@see self::ticketRowVisible()}. This closes that gap generically
	 * for every list tool that needs it.
	 *
	 * Deliberate performance trade-off: `$model` is always fetched UNPAGINATED (`list.start = 0`,
	 * `list.limit = 0`) so the visibility filter runs over the complete matching set, not just one
	 * DB-paginated page — a DB-level LIMIT/OFFSET applied before filtering would silently under-count
	 * `total` and could skip or duplicate rows across pages, since the predicate depends on state
	 * (ownership, invitations, per-category ACL, privacy) the SQL query has no way to express. `total` is
	 * then the count of the FILTERED set, and pagination slices that filtered array, not the raw query
	 * result. Acceptable at ticket-system data volumes; revisit if a site's `#__ats_tickets`/`_posts`/
	 * `_attachments` tables ever grow large enough for this to matter.
	 *
	 * @param   array<string, mixed>  $state
	 * @param   callable               $isVisible  `(object $item): bool` — decides whether a fetched row
	 *                                              belongs in the output at all.
	 * @param   callable               $toRow      `(object $item): array<string, mixed>` — shapes a row
	 *                                              that passed `$isVisible` into its output record. Never
	 *                                              called for a row `$isVisible` rejected, so it's safe for
	 *                                              this to do heavier per-row work (enrichment queries).
	 *
	 * @return  array{items: list<array>, pagination: array{total: int, limit: int, offset: int}}
	 * @throws  ToolException
	 */
	protected function listPageVisible(
		ListModel $model,
		array $state,
		?int $pageLimit,
		?int $pageOffset,
		callable $isVisible,
		callable $toRow
	): array {
		[$limit, $offset] = Pagination::normalise($pageLimit, $pageOffset);

		foreach ($state as $key => $value)
		{
			$model->setState($key, $value);
		}

		$model->setState('list.start', 0);
		$model->setState('list.limit', 0);

		$items = $model->getItems();

		if ($items === false)
		{
			throw new ToolException($this->modelError($model, 'Cannot list items'));
		}

		$visible = array_values(array_filter($items, $isVisible));
		$page    = array_slice($visible, $offset, $limit);

		return [
			'items'      => array_values(array_map($toRow, $page)),
			'pagination' => [
				'total'  => count($visible),
				'limit'  => $limit,
				'offset' => $offset,
			],
		];
	}

	/**
	 * ATS's own API silently strips `created_by`/`created`/`modified`/`modified_by` unless the caller
	 * holds `core.admin` on `com_ats` (see `.plans/16a-tickets-core.md` "Facts verified" #7). We extend
	 * the same gate to all four timestamp-ish overrides, but fail loudly instead of silently dropping
	 * them — see the plan's deviations list.
	 *
	 * @param   array<int, mixed>  $overrides  The raw (possibly-null) override values to check.
	 *
	 * @throws  ToolException  Any value is non-null and the caller lacks `core.admin` on `com_ats`.
	 */
	protected function requireOverridePermission(array $overrides): void
	{
		foreach ($overrides as $value)
		{
			if ($value !== null)
			{
				$this->requireAuthorised('core.admin', 'com_ats');

				return;
			}
		}
	}

	/**
	 * A direct `UPDATE` for columns a save form can't carry (import-time `created`/`modified`/
	 * `modified_by` overrides) — applied AFTER a successful model save, deliberately bypassing the model/
	 * table entirely so none of its hooks (status changes, webhook events, ACL re-checks) run a second
	 * time. `$columns` keys must only ever come from the mappers' own hard-coded lists, never from user
	 * input directly.
	 *
	 * @param   array<string, mixed>  $columns  column => value; `null` values are skipped (no-op).
	 */
	protected function applyColumnOverrides(string $table, int $id, array $columns): void
	{
		$columns = array_filter($columns, static fn ($value) => $value !== null);

		if ($columns === [])
		{
			return;
		}

		$db       = $this->getDatabase();
		$bindVars = [];
		$query    = $db->getQuery(true)->update($db->quoteName($table));

		foreach ($columns as $column => $value)
		{
			$bindVars[$column] = $value;
			$query->set($db->quoteName($column) . ' = :' . $column)
				->bind(':' . $column, $bindVars[$column]);
		}

		$bindId = $id;
		$query->where($db->quoteName('id') . ' = :id')->bind(':id', $bindId, ParameterType::INTEGER);

		$db->setQuery($query)->execute();
	}
}
