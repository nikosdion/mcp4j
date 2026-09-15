<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Tool\Tickets;

defined('_JEXEC') || die;

use Akeeba\Component\ATS\Administrator\Helper\Permissions;
use Akeeba\Component\ATS\Administrator\Model\PostModel;
use Akeeba\Component\ATS\Administrator\Model\PostsModel;
use Akeeba\Component\Mcp4j\Administrator\Bridge\Support\ItemNormalizer;
use Akeeba\Component\Mcp4j\Administrator\Mapper\Tickets\PostMapper;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\McpTool;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\Schema;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\ToolRequirements;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Exception\ToolException;
use Akeeba\Component\Mcp4j\Administrator\Shared\AbstractAtsTool;

/**
 * MCP tools for Akeeba Ticket System (ATS) ticket posts (replies).
 *
 * Ported from the original `joomla-mcp-php` project's `src/Server/Tickets/Posts.php` — method names, tool
 * names/descriptions, parameters and annotations copied verbatim (see `.plans/00-conventions.md` §2); only
 * the bodies differ. Posts need no checkout (`#__ats_posts` has no `checked_out` column); creating one
 * runs `PostTable::onAfterStore()`, which updates the parent ticket's `modified`/status/auto-assignment —
 * that is expected ATS behaviour and is kept as-is.
 *
 * @since  0.0.1
 */
#[ToolRequirements(components: ['com_ats'])]
class Posts extends AbstractAtsTool
{
	/**
	 * Memoises the ticket -> category lookup used by {@see self::listPosts()}, which lists posts across
	 * every ticket and would otherwise re-load the same ticket once per post.
	 *
	 * @var  array<int, int>
	 */
	private array $ticketCatidCache = [];

	#[McpTool(
		name: 'tickets_posts_list',
		description: 'List all ATS (Akeeba Ticket System) ticket posts (replies) across all tickets',
		readOnlyHint: true,
	)]
	public function listPosts(
		#[Schema(description: 'Maximum number of items to return per page', minimum: 1)]
		?int $pageLimit = null,
		#[Schema(description: 'Starting record offset for pagination (0-based)', minimum: 0)]
		?int $pageOffset = null
	): array
	{
		$this->bootAts();
		$this->requireAtsManager();

		/** @var PostsModel $model */
		$model = $this->createModel('com_ats', 'Posts');

		// PostsModel's own state defaults apply unmodified: published-only (filter.enabled = 1),
		// ordered p.created ASC, restricted to the categories whose view level the user holds.
		return $this->listPage(
			$model,
			[],
			$pageLimit,
			$pageOffset,
			function (object $item): array {
				$catid = $this->catidForTicket((int) ($item->ticket_id ?? 0));

				return $this->toPostRow($item, PostMapper::LIST_FIELDS, $catid);
			}
		);
	}

	#[McpTool(
		name: 'tickets_posts_list_for_ticket',
		description: 'List all posts (replies) for a specific ATS (Akeeba Ticket System) ticket',
		readOnlyHint: true,
	)]
	public function listPostsForTicket(
		#[Schema(description: 'The ID of the ticket whose posts to list')]
		int $ticketId
	): array
	{
		$this->bootAts();
		$this->requireAtsManager();

		$ticket = $this->loadTicketTable($ticketId);
		$this->requireViewTicket($ticket);

		$catid = (int) $ticket->catid;

		/** @var PostsModel $model */
		$model = $this->createModel('com_ats', 'Posts');

		return $this->listAll(
			$model,
			['filter.ticket_id' => $ticketId],
			fn (object $item): array => $this->toPostRow($item, PostMapper::LIST_FIELDS, $catid)
		);
	}

	#[McpTool(
		name: 'tickets_posts_read',
		description: 'Read details of a specific ATS (Akeeba Ticket System) ticket post (reply)',
		readOnlyHint: true,
	)]
	public function readPost(
		#[Schema(description: 'The ID of the post to retrieve')]
		int $id
	): array
	{
		$this->bootAts();
		$this->requireAtsManager();

		$post   = $this->loadPostTable($id);
		$ticket = $this->loadTicketTable((int) $post->ticket_id);
		$this->requireViewTicket($ticket);

		/** @var PostModel $model */
		$model = $this->createModel('com_ats', 'Post');

		return $this->toPostRow(
			(object) $this->loadItem($model, $id, 'post'),
			PostMapper::ITEM_FIELDS,
			(int) $ticket->catid
		);
	}

	#[McpTool(
		name: 'tickets_posts_create',
		description: 'Create a new post (reply) under an ATS (Akeeba Ticket System) ticket',
		destructiveHint: true,
	)]
	public function createPost(
		#[Schema(description: 'The ID of the ticket to reply to')]
		int $ticketId,
		#[Schema(description: 'The reply body as HTML')]
		string $content_html,
		#[Schema(description: 'When the post was originally created', format: 'date-time')]
		?string $created = null,
		#[Schema(description: 'The ID of the Joomla user who originally created the post')]
		?int $created_by = null,
		#[Schema(description: 'When the post was last modified', format: 'date-time')]
		?string $modified = null,
		#[Schema(description: 'The ID of the Joomla user who last modified the post')]
		?int $modified_by = null
	): array
	{
		$this->bootAts();
		$this->requireAtsManager();

		$ticket = $this->loadTicketTable($ticketId);
		$priv   = $this->requireViewTicket($ticket);

		$catid = (int) $ticket->catid;

		if (!($priv['post'] ?? false))
		{
			throw $this->denied('ats.reply', $catid);
		}

		$this->requireOverridePermission([$created_by, $created, $modified, $modified_by]);

		try
		{
			$data       = PostMapper::createData($ticketId, $content_html, $created_by);
			$timestamps = PostMapper::timestampColumns($created, $modified, $modified_by);
		}
		catch (\InvalidArgumentException $e)
		{
			throw new ToolException($e->getMessage(), 0, $e);
		}

		/** @var PostModel $model */
		$model = $this->createModel('com_ats', 'Post');
		$newId = $this->saveItem($model, $data, null, 'post');

		$this->applyColumnOverrides('#__ats_posts', $newId, $timestamps);

		return $this->readPost($newId);
	}

	#[McpTool(
		name: 'tickets_posts_update',
		description: 'Update the content of an existing ATS (Akeeba Ticket System) ticket post',
		idempotentHint: true,
	)]
	public function updatePost(
		#[Schema(description: 'The ID of the post to update')]
		int $id,
		#[Schema(description: 'The new post body as HTML')]
		string $content_html,
		#[Schema(description: 'When the post was originally created', format: 'date-time')]
		?string $created = null,
		#[Schema(description: 'The ID of the Joomla user who originally created the post')]
		?int $created_by = null,
		#[Schema(description: 'When the post was last modified', format: 'date-time')]
		?string $modified = null,
		#[Schema(description: 'The ID of the Joomla user who last modified the post')]
		?int $modified_by = null
	): array
	{
		$this->bootAts();
		$this->requireAtsManager();

		$post   = $this->loadPostTable($id);
		$ticket = $this->loadTicketTable((int) $post->ticket_id);
		$this->requireViewTicket($ticket);

		$catid = (int) $ticket->catid;

		// getPostPrivileges()['edit'] already folds in core.edit.own for the post's own owner (plus
		// ats.edit.own.post and the editable-for-x-minutes grace period) — there is no separate
		// edit.own check to perform here.
		$pp = Permissions::getPostPrivileges($post, $this->getUser());

		if (!($pp['edit'] ?? false))
		{
			throw $this->denied('core.edit', $catid);
		}

		$this->requireOverridePermission([$created_by, $created, $modified, $modified_by]);

		try
		{
			$data       = PostMapper::updateData($content_html, $created_by);
			$timestamps = PostMapper::timestampColumns($created, $modified, $modified_by);
		}
		catch (\InvalidArgumentException $e)
		{
			throw new ToolException($e->getMessage(), 0, $e);
		}

		/** @var PostModel $model */
		$model = $this->createModel('com_ats', 'Post');

		// post.xml has no `id` field (unlike ticket.xml) — see seedModelId()'s docblock for why that
		// alone would otherwise make this silently insert a duplicate post instead of updating this one.
		$this->seedModelId($model, $id);

		$this->saveItem($model, $data, $id, 'post');

		// Applied after the save, because PostModel::prepareTable() rewrites modified*/created_by
		// unconditionally on an update.
		$this->applyColumnOverrides('#__ats_posts', $id, $timestamps);

		return $this->readPost($id);
	}

	#[McpTool(
		name: 'tickets_posts_delete',
		description: 'Delete an ATS (Akeeba Ticket System) ticket post (reply)',
		destructiveHint: true,
	)]
	public function deletePost(
		#[Schema(description: 'The ID of the post to delete')]
		int $id
	): array
	{
		$this->bootAts();
		$this->requireAtsManager();

		$post   = $this->loadPostTable($id);
		$ticket = $this->loadTicketTable((int) $post->ticket_id);
		$this->requireViewTicket($ticket);

		// PostModel::canDelete() checks getPostPrivileges()['delete'] itself.
		$this->deleteItem($this->createModel('com_ats', 'Post'), $id, 'post');

		return ['deleted' => true, 'id' => $id];
	}

	/**
	 * Shapes a raw post item (a `PostTable`, or a plain query-row object) into the whitelisted output
	 * record. `$catid` is the post's ticket's category — needed only to resolve `*_info.is_manager`.
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

	private function catidForTicket(int $ticketId): int
	{
		if (!array_key_exists($ticketId, $this->ticketCatidCache))
		{
			$this->ticketCatidCache[$ticketId] = (int) $this->loadTicketTable($ticketId)->catid;
		}

		return $this->ticketCatidCache[$ticketId];
	}
}
