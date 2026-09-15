<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Tool\Tickets;

defined('_JEXEC') || die;

use Akeeba\Component\ATS\Administrator\Helper\Permissions;
use Akeeba\Component\ATS\Administrator\Model\PostsModel;
use Akeeba\Component\ATS\Administrator\Model\TicketModel;
use Akeeba\Component\ATS\Administrator\Model\TicketsModel;
use Akeeba\Component\Mcp4j\Administrator\Bridge\Support\ItemNormalizer;
use Akeeba\Component\Mcp4j\Administrator\Bridge\Support\Pagination;
use Akeeba\Component\Mcp4j\Administrator\Bridge\Support\TagIds;
use Akeeba\Component\Mcp4j\Administrator\Mapper\Tickets\PostMapper;
use Akeeba\Component\Mcp4j\Administrator\Mapper\Tickets\TicketMapper;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\McpTool;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\Schema;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\ToolRequirements;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Exception\ToolException;
use Akeeba\Component\Mcp4j\Administrator\Shared\AbstractAtsTool;
use Joomla\CMS\Helper\TagsHelper;
use Joomla\Component\Fields\Administrator\Helper\FieldsHelper;

/**
 * MCP tools for Akeeba Ticket System (ATS) support tickets.
 *
 * Ported from the original `joomla-mcp-php` project's `src/Server/Tickets/Tickets.php` — method names,
 * tool names/descriptions, parameters and annotations copied verbatim (see `.plans/00-conventions.md`
 * §2); only the bodies differ (ATS's own administrator models instead of HTTP calls to its JSON:API), plus
 * the deviations recorded in the plan's final report (content_html is really stored on create,
 * filterCategory/filterUser/created_on/modified_on actually work, timestamp/author overrides require
 * `core.admin`, nonexistent and invisible tickets both answer `Not found`, custom fields live under
 * `com_fields`, `*_delete` returns the framework-wide shape instead of `bool`).
 *
 * @since  0.0.1
 */
#[ToolRequirements(components: ['com_ats'])]
class Tickets extends AbstractAtsTool
{
	#[McpTool(
		name: 'tickets_tickets_list',
		description: 'List ATS (Akeeba Ticket System) support tickets',
		readOnlyHint: true,
	)]
	public function listTickets(
		#[Schema(description: 'Search filter for tickets')]
		?string $filterSearch = null,
		#[Schema(description: 'Filter by category ID')]
		?int $filterCategory = null,
		#[Schema(description: 'Filter by ticket status', enum: [null, 'O', 'P', 'C'])]
		?string $filterStatus = null,
		#[Schema(description: 'Filter by the ID of the user who created the ticket')]
		?int $filterUser = null,
		#[Schema(description: 'Filter by priority ID')]
		?int $filterPriority = null,
		#[Schema(description: 'Field to order results by', enum: [null, 'id', 'title', 'status', 'priority', 'catid', 'created_by', 'created_on', 'modified_on'])]
		?string $orderBy = null,
		#[Schema(description: 'Sort direction', enum: [null, 'ASC', 'DESC'])]
		?string $orderDirection = null,
		#[Schema(description: 'Maximum number of items to return per page', minimum: 1)]
		?int $pageLimit = null,
		#[Schema(description: 'Starting record offset for pagination (0-based)', minimum: 0)]
		?int $pageOffset = null
	): array
	{
		$this->bootAts();
		$this->requireAtsManager();

		try
		{
			$state = TicketMapper::listState(
				$filterSearch,
				$filterCategory,
				$filterStatus,
				$filterUser,
				$filterPriority,
				$orderBy,
				$orderDirection
			);
		}
		catch (\InvalidArgumentException $e)
		{
			throw new ToolException($e->getMessage(), 0, $e);
		}

		// security.md M4: TicketsModel's own filtering is a plain Joomla content view-access level, not
		// the per-category manage-ACL check tickets_tickets_read enforces via requireViewTicket() ->
		// getTicketPrivileges(). Restrict the query to exactly the categories Permissions::isManager()
		// (via getManagerCategories()) says this caller actually manages, on top of the per-row
		// ownership/invitation/privacy check listPageVisible() applies below.
		$userId            = (int) $this->getUser()->id;
		$allowedCategories = array_values(array_unique(array_map(
			'intval',
			Permissions::getManagerCategories($userId)
		)));

		if ($filterCategory !== null)
		{
			// Confirms $filterCategory is a real com_ats category before deciding whether it's denied —
			// requireAtsCategory() throws "Not found" for a nonexistent/foreign id, matching every other
			// tool in this category; a real-but-denied category is a "Permission denied", matching
			// createTicket()'s own core.create denial for the same asset shape.
			$this->requireAtsCategory($filterCategory);

			if (!in_array($filterCategory, $allowedCategories, true))
			{
				throw $this->denied('core.manage', $filterCategory);
			}

			$state['filter.catid'] = [$filterCategory];
		}
		elseif ($allowedCategories === [])
		{
			// TicketsModel::getListQuery() treats an EMPTY filter.catid as "no category filter at all"
			// (whereIn() is skipped when the array is empty), which would otherwise return every category's
			// tickets instead of none — verified by reading its filter block directly. Short-circuit here
			// rather than relying on whereIn([]) to fail closed.
			[$limit, $offset] = Pagination::normalise($pageLimit, $pageOffset);

			return [
				'items'      => [],
				'pagination' => ['total' => 0, 'limit' => $limit, 'offset' => $offset],
			];
		}
		else
		{
			$state['filter.catid'] = $allowedCategories;
		}

		/** @var TicketsModel $model */
		$model = $this->createModel('com_ats', 'Tickets');

		return $this->listPageVisible(
			$model,
			$state,
			$pageLimit,
			$pageOffset,
			fn (object $item): bool => $this->ticketRowVisible($item),
			fn (object $item): array => $this->toTicketRow($item, TicketMapper::LIST_FIELDS)
		);
	}

	#[McpTool(
		name: 'tickets_tickets_open',
		description: 'List open ATS (Akeeba Ticket System) support tickets, sorted by most recently modified first',
		readOnlyHint: true,
	)]
	public function listOpenTickets(
		#[Schema(description: 'Search filter for tickets')]
		?string $filterSearch = null,
		#[Schema(description: 'Filter by category ID')]
		?int $filterCategory = null,
		#[Schema(description: 'Filter by the ID of the user who created the ticket')]
		?int $filterUser = null,
		#[Schema(description: 'Filter by priority ID')]
		?int $filterPriority = null,
		#[Schema(description: 'Maximum number of items to return per page', minimum: 1)]
		?int $pageLimit = null,
		#[Schema(description: 'Starting record offset for pagination (0-based)', minimum: 0)]
		?int $pageOffset = null
	): array
	{
		return $this->listTickets(
			filterSearch: $filterSearch,
			filterCategory: $filterCategory,
			filterStatus: 'O',
			filterUser: $filterUser,
			filterPriority: $filterPriority,
			orderBy: 'modified_on',
			orderDirection: 'DESC',
			pageLimit: $pageLimit,
			pageOffset: $pageOffset,
		);
	}

	#[McpTool(
		name: 'tickets_tickets_read',
		description: 'Read details of a specific ATS (Akeeba Ticket System) support ticket, optionally including its posts',
		readOnlyHint: true,
	)]
	public function readTicket(
		#[Schema(description: 'The ID of the ticket to retrieve')]
		int $id,
		#[Schema(description: 'When true, embeds the ticket posts in the response')]
		bool $includePosts = false
	): array
	{
		$this->bootAts();
		$this->requireAtsManager();

		$ticket = $this->loadTicketTable($id);
		$this->requireViewTicket($ticket);

		/** @var TicketModel $model */
		$model = $this->createModel('com_ats', 'Ticket');
		$item  = $model->getItem($id);

		if (!$item || empty($item->id))
		{
			throw new ToolException(sprintf('Not found: ticket #%d', $id));
		}

		$row = $this->toTicketRow($item, TicketMapper::ITEM_FIELDS);

		if ($includePosts)
		{
			$catid = (int) $ticket->catid;

			/** @var PostsModel $postsModel */
			$postsModel = $this->createModel('com_ats', 'Posts');

			$posts = $this->listAll(
				$postsModel,
				[
					'filter.ticket_id' => $id,
					'filter.enabled'   => 1,
					'list.ordering'    => 'p.created',
					'list.direction'   => 'ASC',
				],
				fn (object $post): array => $this->toPostRow($post, PostMapper::LIST_FIELDS, $catid)
			);

			$row['posts'] = $posts['items'];
		}

		return $row;
	}

	#[McpTool(
		name: 'tickets_tickets_create',
		description: 'Create a new ATS (Akeeba Ticket System) support ticket',
		destructiveHint: true,
	)]
	public function createTicket(
		#[Schema(description: 'The category ID for the ticket', minimum: 1)]
		int $catid,
		#[Schema(description: 'The subject/title of the ticket', minLength: 1)]
		string $title,
		#[Schema(description: 'The opening message body as HTML')]
		string $content_html,
		#[Schema(description: 'The priority level of the ticket (when ticket priorities are enabled)')]
		?int $priority = null,
		#[Schema(description: 'Whether the ticket is publicly visible (0 = private, 1 = public)', enum: [0, 1])]
		?int $public = null,
		#[Schema(description: 'When the ticket was originally created', format: 'date-time')]
		?string $created = null,
		#[Schema(description: 'The ID of the Joomla user who originally created the ticket')]
		?int $created_by = null,
		#[Schema(description: 'When the ticket was last modified', format: 'date-time')]
		?string $modified = null,
		#[Schema(description: 'The ID of the Joomla user who last modified the ticket')]
		?int $modified_by = null
	): array
	{
		$this->bootAts();
		$this->requireAtsManager();

		$this->requireAtsCategory($catid);

		$acl = Permissions::getAclPrivileges($catid, (int) $this->getUser()->id);

		if (!($acl['core.create'] ?? false))
		{
			throw $this->denied('core.create', $catid);
		}

		$this->requireOverridePermission([$created_by, $created, $modified, $modified_by]);

		try
		{
			$ticketData = TicketMapper::createTicketData($catid, $title, $priority, $public, $created_by);
			$timestamps = TicketMapper::timestampColumns($created, $modified, $modified_by);
		}
		catch (\InvalidArgumentException $e)
		{
			throw new ToolException($e->getMessage(), 0, $e);
		}

		// Mirrors the backend TicketController::save() flow: a new ticket is saved with
		// ticket.savingNew (which strips the post fieldset from its form), then its opening post is
		// saved separately through the Post model — see .plans/16a-tickets-core.md "Facts verified" #5.
		/** @var TicketModel $ticketModel */
		$ticketModel = $this->createModel('com_ats', 'Ticket');
		$ticketModel->setState('ticket.savingNew', true);

		$ticketId = $this->saveItem($ticketModel, $ticketData, null, 'ticket');

		$postId = null;

		try
		{
			/** @var \Akeeba\Component\ATS\Administrator\Model\PostModel $postModel */
			$postModel = $this->createModel('com_ats', 'Post');

			try
			{
				$postId = $this->saveItem(
					$postModel,
					TicketMapper::firstPostData($ticketId, $content_html, $created_by),
					null,
					'post'
				);
			}
			catch (ToolException $e)
			{
				try
				{
					$pks = [$ticketId];
					$this->createModel('com_ats', 'Ticket')->delete($pks);
				}
				catch (\Throwable $ignored)
				{
					// The cleanup delete is best-effort; the original save error is what matters.
				}

				throw $e;
			}
		}
		finally
		{
			$this->getApplication()->setUserState('com_ats.edit.ticket.data', null);
		}

		$this->applyColumnOverrides('#__ats_tickets', $ticketId, $timestamps);

		if ($created !== null && $postId !== null)
		{
			$this->applyColumnOverrides('#__ats_posts', $postId, ['created' => $timestamps['created']]);
		}

		return $this->readTicket($ticketId);
	}

	#[McpTool(
		name: 'tickets_tickets_update',
		description: 'Update an existing ATS (Akeeba Ticket System) support ticket',
		idempotentHint: true,
	)]
	public function updateTicket(
		#[Schema(description: 'The ID of the ticket to update')]
		int $id,
		#[Schema(description: 'The new subject/title of the ticket')]
		?string $title = null,
		#[Schema(description: 'The new status of the ticket', enum: ['O', 'P', 'C'])]
		?string $status = null,
		#[Schema(description: 'When the ticket was originally created', format: 'date-time')]
		?string $created = null,
		#[Schema(description: 'The ID of the Joomla user who originally created the ticket')]
		?int $created_by = null,
		#[Schema(description: 'When the ticket was last modified', format: 'date-time')]
		?string $modified = null,
		#[Schema(description: 'The ID of the Joomla user who last modified the ticket')]
		?int $modified_by = null,
		#[Schema(description: 'Joomla custom field values as a key-value map (field name => value). Existing field values are preserved automatically; only specify fields you want to change.')]
		?array $com_fields = null
	): array
	{
		$this->bootAts();
		$this->requireAtsManager();

		$ticket = $this->loadTicketTable($id);
		$priv   = $this->requireViewTicket($ticket);

		$catid  = (int) $ticket->catid;
		$userId = (int) $this->getUser()->id;
		$acl    = Permissions::getAclPrivileges($catid, $userId);

		$allowEdit = ($acl['core.edit'] ?? false)
			|| (($acl['core.edit.own'] ?? false) && (int) $ticket->created_by === $userId);

		if (!$allowEdit)
		{
			throw $this->denied('core.edit', $catid);
		}

		// ticket.xml disables (filter="unset") the status field for anyone lacking edit.state — the
		// value would otherwise silently vanish rather than being applied or rejected.
		if ($status !== null && !($priv['edit.state'] ?? false))
		{
			throw $this->denied('core.edit.state', $catid);
		}

		$this->requireOverridePermission([$created_by, $created, $modified, $modified_by]);

		/** @var TicketModel $model */
		$model   = $this->createModel('com_ats', 'Ticket');
		$current = $model->getItem($id);

		$existing = [];

		foreach (FieldsHelper::getFields('com_ats.ticket', $current, true) as $field)
		{
			$existing[$field->name] = $field->rawvalue;
		}

		try
		{
			$fields     = TicketMapper::mergeComFields($existing, $com_fields);
			$data       = TicketMapper::updateTicketData($title, $status, $created_by);
			$timestamps = TicketMapper::timestampColumns($created, $modified, $modified_by);
		}
		catch (\InvalidArgumentException $e)
		{
			throw new ToolException($e->getMessage(), 0, $e);
		}

		if ($fields !== [])
		{
			$data['com_fields'] = $fields;
		}

		// TicketModel::getItem()'s ->tags is a TagsHelper *instance*, not a plain id list, so the
		// bridge's own current-record normalisation of it would otherwise merge in as an empty list and
		// silently clear every tag on save — repopulate it explicitly, the way ATS's own API does on
		// every PATCH.
		$data['tags'] = TagIds::fromMixed((new TagsHelper())->getTagIds($id, 'com_ats.ticket'));

		/** @var TicketModel $saveModel */
		$saveModel = $this->createModel('com_ats', 'Ticket');

		$this->saveItem($saveModel, $data, $id, 'ticket');

		// ticket.xml's `created` field uses filter="user_utc" and TicketModel::prepareTable() never
		// touches modified*/created on an update, so overrides are always applied directly, after the
		// form-validated save.
		$this->applyColumnOverrides('#__ats_tickets', $id, $timestamps);

		return $this->readTicket($id);
	}

	#[McpTool(
		name: 'tickets_tickets_delete',
		description: 'Delete an ATS (Akeeba Ticket System) support ticket',
		destructiveHint: true,
	)]
	public function deleteTicket(
		#[Schema(description: 'The ID of the ticket to delete')]
		int $id
	): array
	{
		$this->bootAts();
		$this->requireAtsManager();

		$ticket = $this->loadTicketTable($id);
		$this->requireViewTicket($ticket);

		// TicketModel::canDelete() checks getTicketPrivileges()['delete'] itself;
		// TicketTable::onAfterDelete() cascades to the ticket's posts and manager notes. ATS tickets have
		// no trashed state, so there is no trash-first step (the original deleted directly too).
		$this->deleteItem($this->createModel('com_ats', 'Ticket'), $id, 'ticket');

		return ['deleted' => true, 'id' => $id];
	}

	/**
	 * Shapes a raw ticket item (a `TicketTable`, from `TicketModel::getItem()`, or a plain query-row
	 * object, from `TicketsModel::getItems()`) into the whitelisted output record.
	 *
	 * @return  array<string, mixed>
	 */
	private function toTicketRow(object $item, array $fields): array
	{
		$arr   = ItemNormalizer::toArray($item);
		$id    = (int) ($arr['id'] ?? 0);
		$catid = (int) ($arr['catid'] ?? 0);

		// Re-queried directly rather than trusting a normalised `tags` key: TicketModel::getItem() sets
		// ->tags to a TagsHelper *instance* (see updateTicket()'s docblock), which does not normalise to
		// a plain id list either way — querying fresh works identically for both getItem() and
		// getItems() rows.
		$arr['tags'] = TagIds::fromMixed((new TagsHelper())->getTagIds($id, 'com_ats.ticket'));

		$arr['com_fields'] = $this->comFieldsFor($id, $catid);

		$arr['created_by_info']  = $this->userInfo((int) ($arr['created_by'] ?? 0), $catid);
		$arr['modified_by_info'] = $this->userInfo((int) ($arr['modified_by'] ?? 0), $catid);
		$arr['assigned_to_info'] = $this->userInfo((int) ($arr['assigned_to'] ?? 0), $catid);

		return $this->shape($arr, $fields, TicketMapper::INT_FIELDS, TicketMapper::JSON_FIELDS);
	}

	/**
	 * @return  array<string, mixed>  Custom field `name => rawvalue` pairs for the given ticket.
	 */
	private function comFieldsFor(int $id, int $catid): array
	{
		$fields = FieldsHelper::getFields('com_ats.ticket', (object) ['id' => $id, 'catid' => $catid], true);

		$result = [];

		foreach ($fields as $field)
		{
			$result[$field->name] = $field->rawvalue;
		}

		return $result;
	}

	/**
	 * Shapes a raw post item the same way {@see \Akeeba\Component\Mcp4j\Administrator\Tool\Tickets\Posts}
	 * does — duplicated rather than shared because it is small and each class's `$catid` comes from a
	 * different place (here, always the enclosing ticket, already known).
	 *
	 * @return  array<string, mixed>
	 */
	private function toPostRow(object $item, array $fields, int $catid): array
	{
		$arr = ItemNormalizer::toArray($item);

		$arr['created_by_info']  = $this->userInfo((int) ($arr['created_by'] ?? 0), $catid);
		$arr['modified_by_info'] = $this->userInfo((int) ($arr['modified_by'] ?? 0), $catid);

		return $this->shape($arr, $fields, PostMapper::INT_FIELDS);
	}
}
