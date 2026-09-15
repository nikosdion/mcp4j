<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Tool\Tickets;

defined('_JEXEC') || die;

use Akeeba\Component\ATS\Administrator\Model\ManagernoteModel;
use Akeeba\Component\ATS\Administrator\Model\ManagernotesModel;
use Akeeba\Component\ATS\Administrator\Table\ManagernoteTable;
use Akeeba\Component\Mcp4j\Administrator\Bridge\Support\ItemNormalizer;
use Akeeba\Component\Mcp4j\Administrator\Mapper\Tickets\ManagerNoteMapper;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\McpTool;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\Schema;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\ToolRequirements;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Exception\ToolException;
use Akeeba\Component\Mcp4j\Administrator\Shared\AbstractAtsTool;

/**
 * MCP tools for Akeeba Ticket System (ATS) Pro internal manager notes.
 *
 * Ported from the original `joomla-mcp-php` project's `src/Server/Tickets/ManagerNotes.php` — method
 * names, tool names/descriptions, parameters and annotations copied verbatim (see
 * `.plans/00-conventions.md` §2); only the bodies differ. No e-mails are sent for a note created via
 * these tools — manager-note e-mails are fired only by the backend controller's `postSaveHook()`, which
 * we never call (see `.plans/16b-tickets-pro.md` "Facts verified" #7, matching 16a's ticket/post
 * behaviour).
 *
 * Every method here requires ATS Pro ({@see AbstractAtsTool::requireAtsPro()}) — manager notes are a
 * Pro-only ATS feature; on ATS Core every tool answers the tool error "This tool requires Akeeba Ticket
 * System Pro." (see `.plans/16b-tickets-pro.md` "Deviations from the original" #4).
 *
 * @since  0.0.1
 */
#[ToolRequirements(components: ['com_ats'])]
class ManagerNotes extends AbstractAtsTool
{
	#[McpTool(
		name: 'tickets_notes_list_for_ticket',
		description: 'List all internal manager notes for an ATS (Akeeba Ticket System) ticket. Requires ATS Pro.',
		readOnlyHint: true,
	)]
	public function listNotesForTicket(
		#[Schema(description: 'The ID of the ticket whose manager notes to list')]
		int $ticketId
	): array
	{
		$this->bootAts();
		$this->requireAtsManager();
		$this->requireAtsPro();

		$ticket = $this->loadTicketTable($ticketId);
		$priv   = $this->requireViewTicket($ticket);

		$catid = (int) $ticket->catid;

		if (!($priv['notes.read'] ?? false))
		{
			throw $this->denied('ats.notes.read', $catid);
		}

		/** @var ManagernotesModel $model */
		$model = $this->createModel('com_ats', 'Managernotes');

		return $this->listAll(
			$model,
			['filter.ticket_id' => $ticketId],
			fn (object $item): array => $this->toNoteRow($item, ManagerNoteMapper::LIST_FIELDS, $catid)
		);
	}

	#[McpTool(
		name: 'tickets_notes_read',
		description: 'Read details of a specific ATS (Akeeba Ticket System) manager note. Requires ATS Pro.',
		readOnlyHint: true,
	)]
	public function readNote(
		#[Schema(description: 'The ID of the manager note to retrieve')]
		int $id
	): array
	{
		$this->bootAts();
		$this->requireAtsManager();
		$this->requireAtsPro();

		$note   = $this->loadNoteTable($id);
		$ticket = $this->loadTicketTable((int) $note->ticket_id);
		$priv   = $this->requireViewTicket($ticket);

		$catid = (int) $ticket->catid;

		if (!($priv['notes.read'] ?? false))
		{
			throw $this->denied('ats.notes.read', $catid);
		}

		/** @var ManagernoteModel $model */
		$model = $this->createModel('com_ats', 'Managernote');

		return $this->toNoteRow(
			(object) $this->loadItem($model, $id, 'manager note'),
			ManagerNoteMapper::ITEM_FIELDS,
			$catid
		);
	}

	#[McpTool(
		name: 'tickets_notes_create',
		description: 'Create an internal manager note on an ATS (Akeeba Ticket System) ticket. Requires ATS Pro.',
		destructiveHint: true,
	)]
	public function createNote(
		#[Schema(description: 'The ID of the ticket to add a manager note to')]
		int $ticketId,
		#[Schema(description: 'The note body as HTML')]
		string $note_html
	): array
	{
		$this->bootAts();
		$this->requireAtsManager();
		$this->requireAtsPro();

		$ticket = $this->loadTicketTable($ticketId);
		$priv   = $this->requireViewTicket($ticket);

		$catid = (int) $ticket->catid;

		if (!($priv['notes.create'] ?? false))
		{
			throw $this->denied('ats.notes.create', $catid);
		}

		/** @var ManagernoteModel $model */
		$model = $this->createModel('com_ats', 'Managernote');
		$newId = $this->saveItem($model, ManagerNoteMapper::createData($ticketId, $note_html), null, 'manager note');

		return $this->readNote($newId);
	}

	#[McpTool(
		name: 'tickets_notes_delete',
		description: 'Delete an ATS (Akeeba Ticket System) manager note. Requires ATS Pro.',
		destructiveHint: true,
	)]
	public function deleteNote(
		#[Schema(description: 'The ID of the manager note to delete')]
		int $id
	): array
	{
		$this->bootAts();
		$this->requireAtsManager();
		$this->requireAtsPro();

		$note   = $this->loadNoteTable($id);
		$ticket = $this->loadTicketTable((int) $note->ticket_id);
		$priv   = $this->requireViewTicket($ticket);

		$catid = (int) $ticket->catid;

		if (!($priv['notes.delete'] ?? false))
		{
			throw $this->denied('ats.notes.delete', $catid);
		}

		$this->deleteItem($this->createModel('com_ats', 'Managernote'), $id, 'manager note');

		return ['deleted' => true, 'id' => $id];
	}

	/**
	 * @throws  ToolException  `Not found: manager note #<id>`.
	 */
	private function loadNoteTable(int $id): ManagernoteTable
	{
		$model = $this->createModel('com_ats', 'Managernote');

		/** @var ManagernoteTable $table */
		$table = $model->getTable('Managernote', 'Administrator');

		if ($id <= 0 || !$table->load($id) || !$table->getId())
		{
			throw new ToolException(sprintf('Not found: manager note #%d', $id));
		}

		return $table;
	}

	/**
	 * @return  array<string, mixed>
	 */
	private function toNoteRow(object $item, array $fields, int $catid): array
	{
		$arr = ItemNormalizer::toArray($item);

		$arr['created_by_info']  = $this->userInfo((int) ($arr['created_by'] ?? 0), $catid);
		$arr['modified_by_info'] = $this->userInfo((int) ($arr['modified_by'] ?? 0), $catid);

		return $this->shape($arr, $fields, ManagerNoteMapper::INT_FIELDS);
	}
}
