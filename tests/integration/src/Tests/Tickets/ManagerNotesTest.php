<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Integration\Tests\Tickets;

use Akeeba\Mcp4j\Tests\Integration\AbstractToolTestCase;

/**
 * End-to-end coverage for the `tickets_notes_*` tools
 * ({@see \Akeeba\Component\Mcp4j\Administrator\Tool\Tickets\ManagerNotes}).
 *
 * Manager notes are an Akeeba Ticket System **Pro** feature — every tool in this class answers the tool
 * error "This tool requires Akeeba Ticket System Pro." on an ATS Core install
 * ({@see self::testAtsCoreRefusesEveryTool()}); the rest of this class self-skips cleanly unless a Pro
 * build is present. See {@see \Akeeba\Mcp4j\Tests\Integration\Tests\Tickets\CategoriesTest}'s docblock for
 * why this whole class self-skips on the Joomla 4.4 matrix entry and on any run without the ATS zip.
 */
class ManagerNotesTest extends AbstractToolTestCase
{
	private array $ats;

	protected function setUp(): void
	{
		parent::setUp();

		$this->requireToolsOrSkip(
			[
				'tickets_notes_list_for_ticket',
				'tickets_notes_read',
				'tickets_notes_create',
				'tickets_notes_delete',
				'tickets_tickets_create',
				'tickets_tickets_delete',
				'tickets_tickets_read',
			]
		);

		$this->ats = AtsFixtures::ensure();

		if (!($this->ats['installed'] ?? false))
		{
			$this->markTestSkipped('Akeeba Ticket System is not installed on this site.');
		}
	}

	private function createTicket(string $title): array
	{
		return $this->callToolOk(
			'tickets_tickets_create',
			[
				'catid'        => $this->ats['categoryId'],
				'title'        => $title,
				'content_html' => '<p>Opening post</p>',
			]
		);
	}

	public function testAtsCoreRefusesEveryTool(): void
	{
		if ($this->ats['pro'] ?? false)
		{
			$this->markTestSkipped('This test only applies when ATS Pro is not present.');
		}

		$expected = 'This tool requires Akeeba Ticket System Pro.';

		$this->assertSame($expected, $this->callToolError('tickets_notes_list_for_ticket', ['ticketId' => 1]));
		$this->assertSame($expected, $this->callToolError('tickets_notes_read', ['id' => 1]));
		$this->assertSame(
			$expected,
			$this->callToolError('tickets_notes_create', ['ticketId' => 1, 'note_html' => '<p>x</p>'])
		);
		$this->assertSame($expected, $this->callToolError('tickets_notes_delete', ['id' => 1]));
	}

	public function testManagerNoteLifecycle(): void
	{
		if (!($this->ats['pro'] ?? false))
		{
			$this->markTestSkipped('Requires Akeeba Ticket System Pro.');
		}

		$title    = $this->uniqueName('MCP4j Note');
		$ticket   = $this->createTicket($title);
		$ticketId = $ticket['id'];
		$this->registerCleanup(fn () => $this->callTool('tickets_tickets_delete', ['id' => $ticketId]));

		// 1. create -> ticket_id, created_by_info.id = adminId.
		$note = $this->callToolOk(
			'tickets_notes_create',
			['ticketId' => $ticketId, 'note_html' => '<p>Internal note</p>']
		);
		$this->assertSame($ticketId, $note['ticket_id']);
		$this->assertSame($this->ats['adminId'], $note['created_by_info']['id'] ?? null);

		// 2. list_for_ticket -> 1 item; read -> note_html contains "Internal note", has modified_by_info.
		$list = $this->callToolOk('tickets_notes_list_for_ticket', ['ticketId' => $ticketId]);
		$this->assertCount(1, $list['items']);

		$read = $this->callToolOk('tickets_notes_read', ['id' => $note['id']]);
		$this->assertStringContainsString('Internal note', $read['note_html']);
		$this->assertArrayHasKey('modified_by_info', $read);

		// 3. tickets_tickets_read(includePosts: true) has no "notes" key.
		$ticketRead = $this->callToolOk('tickets_tickets_read', ['id' => $ticketId, 'includePosts' => true]);
		$this->assertArrayNotHasKey('notes', $ticketRead);

		// 4. delete -> read Not found, list -> 0 items.
		$deleted = $this->callToolOk('tickets_notes_delete', ['id' => $note['id']]);
		$this->assertSame(['deleted' => true, 'id' => $note['id']], $deleted);

		$this->assertNotFound($this->callTool('tickets_notes_read', ['id' => $note['id']]));

		$listAfter = $this->callToolOk('tickets_notes_list_for_ticket', ['ticketId' => $ticketId]);
		$this->assertCount(0, $listAfter['items']);
	}

	// 5. read(999999), list_for_ticket(999999) -> Not found.
	public function testReadAndListForNonexistentAreNotFound(): void
	{
		if (!($this->ats['pro'] ?? false))
		{
			$this->markTestSkipped('Requires Akeeba Ticket System Pro.');
		}

		$this->assertNotFound($this->callTool('tickets_notes_read', ['id' => 999999]));
		$this->assertNotFound($this->callTool('tickets_notes_list_for_ticket', ['ticketId' => 999999]));
	}

	/**
	 * 6. Low-priv: tickets_notes_list_for_ticket, tickets_notes_create -> Permission denied.
	 *
	 * requireAtsManager() is checked before requireAtsPro() in every tool method, so a low-privilege user
	 * is refused identically whether or not ATS Pro is present.
	 */
	public function testLowPrivilegeUserIsRefused(): void
	{
		$this->assertPermissionDenied($this->callToolAsLowPriv('tickets_notes_list_for_ticket', ['ticketId' => 1]));
		$this->assertPermissionDenied(
			$this->callToolAsLowPriv('tickets_notes_create', ['ticketId' => 1, 'note_html' => '<p>x</p>'])
		);
	}
}
