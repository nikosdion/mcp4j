<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Tool\Tickets;

defined('_JEXEC') || die;

use Akeeba\Component\ATS\Administrator\Helper\Permissions;
use Akeeba\Component\ATS\Administrator\Model\AttachmentsModel;
use Akeeba\Component\ATS\Administrator\Table\AttachmentTable;
use Akeeba\Component\Mcp4j\Administrator\Bridge\Support\ItemNormalizer;
use Akeeba\Component\Mcp4j\Administrator\Mapper\Tickets\AttachmentInliner;
use Akeeba\Component\Mcp4j\Administrator\Mapper\Tickets\AttachmentMapper;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\McpTool;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\Schema;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\ToolRequirements;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Exception\ToolException;
use Akeeba\Component\Mcp4j\Administrator\Shared\AbstractAtsTool;

/**
 * MCP tools for Akeeba Ticket System (ATS) Pro post attachments.
 *
 * Ported from the original `joomla-mcp-php` project's `src/Server/Tickets/Attachments.php` — method
 * names, tool names/descriptions, parameters and annotations copied verbatim (see
 * `.plans/00-conventions.md` §2), with two deliberate exceptions:
 *
 * - `tickets_attachments_save_to_file` is **not ported**. In the original it wrote the downloaded
 *   attachment to a path on the *MCP client's* disk. Run here — inside the web server that also serves
 *   the site — the same tool would let any caller with a valid API token write arbitrary bytes to any
 *   filesystem path the PHP process user can write to: an unauthenticated-file-write-adjacent primitive
 *   with no safe server-side equivalent. There is no stub method and no tool of this name; see
 *   `.plans/16b-tickets-pro.md` "Deviations from the original" #1 and `.plans/00-conventions.md` §2.
 * - `tickets_attachments_download`'s description no longer promises "vision-ready content" — see
 *   {@see \Akeeba\Component\Mcp4j\Administrator\Mapper\Tickets\AttachmentInliner}'s docblock for why.
 *
 * Every method here requires ATS Pro ({@see AbstractAtsTool::requireAtsPro()}) — attachments are a Pro-only
 * ATS feature; on ATS Core every tool answers the tool error "This tool requires Akeeba Ticket System
 * Pro." (see `.plans/16b-tickets-pro.md` "Deviations from the original" #4).
 *
 * @since  0.0.1
 */
#[ToolRequirements(components: ['com_ats'])]
class Attachments extends AbstractAtsTool
{
	/**
	 * Memoises the post -> ATS category lookup used by {@see self::listAttachments()}, which lists
	 * attachments across every post and would otherwise re-load the same post's ticket once per
	 * attachment.
	 *
	 * @var  array<int, int>
	 */
	private array $postCatidCache = [];

	#[McpTool(
		name: 'tickets_attachments_list',
		description: 'List all ATS (Akeeba Ticket System) ticket attachments. Requires ATS Pro.',
		readOnlyHint: true,
	)]
	public function listAttachments(
		#[Schema(description: 'Maximum number of items to return per page', minimum: 1)]
		?int $pageLimit = null,
		#[Schema(description: 'Starting record offset for pagination (0-based)', minimum: 0)]
		?int $pageOffset = null
	): array
	{
		$this->bootAts();
		$this->requireAtsManager();
		$this->requireAtsPro();

		/** @var AttachmentsModel $model */
		$model = $this->createModel('com_ats', 'Attachments');

		// AttachmentsModel's own state defaults apply unmodified: published-only (filter.enabled = 1),
		// ordered a.created ASC, restricted to the categories whose view level the user holds (via the
		// attachment's post's ticket).
		return $this->listPage(
			$model,
			[],
			$pageLimit,
			$pageOffset,
			function (object $item): array {
				$catid = $this->catidForPost((int) ($item->post_id ?? 0));

				return $this->toAttachmentRow($item, AttachmentMapper::LIST_FIELDS, $catid, false);
			}
		);
	}

	#[McpTool(
		name: 'tickets_attachments_list_for_post',
		description: 'List all attachments for a specific ATS (Akeeba Ticket System) ticket post. Requires ATS Pro.',
		readOnlyHint: true,
	)]
	public function listAttachmentsForPost(
		#[Schema(description: 'The ID of the post whose attachments to list')]
		int $postId
	): array
	{
		$this->bootAts();
		$this->requireAtsManager();
		$this->requireAtsPro();

		$post   = $this->loadPostTable($postId);
		$ticket = $this->loadTicketTable((int) $post->ticket_id);
		$this->requireViewTicket($ticket);

		$catid = (int) $ticket->catid;

		/** @var AttachmentsModel $model */
		$model = $this->createModel('com_ats', 'Attachments');

		return $this->listAll(
			$model,
			['filter.post_id' => $postId],
			fn (object $item): array => $this->toAttachmentRow($item, AttachmentMapper::LIST_FIELDS, $catid, false)
		);
	}

	#[McpTool(
		name: 'tickets_attachments_read',
		description: 'Read metadata of a specific ATS (Akeeba Ticket System) attachment, including its file size. Requires ATS Pro.',
		readOnlyHint: true,
	)]
	public function readAttachment(
		#[Schema(description: 'The ID of the attachment to retrieve')]
		int $id
	): array
	{
		$this->bootAts();
		$this->requireAtsManager();
		$this->requireAtsPro();

		[$attachment, , $ticket] = $this->requireVisibleAttachment($id);

		return $this->toAttachmentRow($attachment, AttachmentMapper::ITEM_FIELDS, (int) $ticket->catid, true);
	}

	#[McpTool(
		name: 'tickets_attachments_download',
		description: 'Download the content of an ATS (Akeeba Ticket System) attachment. Text files (including .php) are returned as plain text; images and other binary content are returned base64-encoded. ZIP archives are extracted and each file returned individually. Requires ATS Pro.',
		readOnlyHint: true,
	)]
	public function downloadAttachment(
		#[Schema(description: 'The ID of the attachment to download')]
		int $id
	): array
	{
		$this->bootAts();
		$this->requireAtsManager();
		$this->requireAtsPro();

		[$attachment] = $this->requireVisibleAttachment($id);

		$path = $attachment->getAbsoluteFilename();

		if ($path === null || !is_readable($path))
		{
			throw new ToolException(sprintf('The file of attachment #%d is missing on the server.', $id));
		}

		try
		{
			$result = AttachmentInliner::inline($path, (string) $attachment->mime_type, (string) $attachment->original_filename);
		}
		catch (\LengthException|\RuntimeException $e)
		{
			throw new ToolException(sprintf('Attachment #%d: %s', $id, $e->getMessage()));
		}

		$result['id'] = $id;

		return $result;
	}

	#[McpTool(
		name: 'tickets_attachments_delete',
		description: 'Delete an ATS (Akeeba Ticket System) attachment. Requires ATS Pro.',
		destructiveHint: true,
	)]
	public function deleteAttachment(
		#[Schema(description: 'The ID of the attachment to delete')]
		int $id
	): array
	{
		$this->bootAts();
		$this->requireAtsManager();
		$this->requireAtsPro();

		[, , $ticket] = $this->requireVisibleAttachment($id);

		$catid = (int) $ticket->catid;

		if (!(Permissions::getAclPrivileges($catid, (int) $this->getUser()->id)['ats.attachment.delete'] ?? false))
		{
			throw $this->denied('ats.attachment.delete', $catid);
		}

		// AttachmentModel::canDelete() additionally checks getPostPrivileges()['delete'] ||
		// ['attachment.delete'] itself; AttachmentTable::onAfterDelete() removes the file from disk. The
		// post's attachment_id CSV column is deliberately NOT updated — ATS's own API doesn't either (see
		// `.plans/16b-tickets-pro.md` "Facts verified" #3).
		$this->deleteItem($this->createModel('com_ats', 'Attachment'), $id, 'attachment');

		return ['deleted' => true, 'id' => $id];
	}

	/**
	 * Loads an attachment `Table` by id, exactly like {@see AbstractAtsTool::loadTicketTable()}/
	 * {@see AbstractAtsTool::loadPostTable()} do for their own resource.
	 *
	 * @throws  ToolException  `Not found: attachment #<id>`.
	 */
	private function loadAttachmentTable(int $id): AttachmentTable
	{
		$model = $this->createModel('com_ats', 'Attachment');

		/** @var AttachmentTable $table */
		$table = $model->getTable('Attachment', 'Administrator');

		if ($id <= 0 || !$table->load($id) || !$table->getId())
		{
			throw new ToolException(sprintf('Not found: attachment #%d', $id));
		}

		return $table;
	}

	/**
	 * Resolves an attachment id into its full access chain, applying both halves of ATS's own access rule
	 * for a single attachment (see `.plans/16b-tickets-pro.md` "Facts verified" #2): the attachment's
	 * post's ticket must pass the ordinary ticket view rule, AND `Permissions::attachmentVisible()` must
	 * allow it. Either failing answers the same `Not found` a nonexistent attachment would, so attachment
	 * ids can't be enumerated.
	 *
	 * @return  array{0: AttachmentTable, 1: \Akeeba\Component\ATS\Administrator\Table\PostTable, 2: \Akeeba\Component\ATS\Administrator\Table\TicketTable}
	 * @throws  ToolException
	 */
	private function requireVisibleAttachment(int $id): array
	{
		$attachment = $this->loadAttachmentTable($id);
		$post       = $this->loadPostTable((int) $attachment->post_id);
		$ticket     = $this->loadTicketTable((int) $post->ticket_id);

		$this->requireViewTicket($ticket);

		if (!Permissions::attachmentVisible($attachment, $this->getUser()))
		{
			throw new ToolException(sprintf('Not found: attachment #%d', $id));
		}

		return [$attachment, $post, $ticket];
	}

	/**
	 * Shapes a raw attachment item (an `AttachmentTable` for the single-record path, or a plain query-row
	 * object for a list) into the whitelisted output record. `mangled_filename` is never copied in — it
	 * simply isn't in {@see AttachmentMapper::LIST_FIELDS}/{@see AttachmentMapper::ITEM_FIELDS}.
	 *
	 * @return  array<string, mixed>
	 */
	private function toAttachmentRow(object $item, array $fields, ?int $catid, bool $withSize): array
	{
		$arr = ItemNormalizer::toArray($item);

		$arr['created_by_info'] = $this->userInfo((int) ($arr['created_by'] ?? 0), $catid);

		if ($withSize)
		{
			// file_size is only meaningful (and only requested) for the single-record read path, where
			// $item is always the real AttachmentTable — delegate path resolution entirely to
			// getAbsoluteFilename() rather than duplicating its subdirectory layout logic here.
			$path = method_exists($item, 'getAbsoluteFilename') ? $item->getAbsoluteFilename() : null;

			$arr['file_size'] = ($path !== null && @file_exists($path)) ? (int) @filesize($path) : 0;
		}

		return $this->shape($arr, $fields, AttachmentMapper::INT_FIELDS);
	}

	private function catidForPost(int $postId): int
	{
		if (!array_key_exists($postId, $this->postCatidCache))
		{
			$post = $this->loadPostTable($postId);

			$this->postCatidCache[$postId] = (int) $this->loadTicketTable((int) $post->ticket_id)->catid;
		}

		return $this->postCatidCache[$postId];
	}
}
