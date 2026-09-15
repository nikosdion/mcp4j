<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Integration\Tests\Tickets;

use Akeeba\Mcp4j\Tests\Integration\AbstractToolTestCase;

/**
 * End-to-end coverage for the `tickets_posts_*` tools
 * ({@see \Akeeba\Component\Mcp4j\Administrator\Tool\Tickets\Posts}).
 *
 * Requires the Akeeba Ticket System (ATS) extension — see `tests/integration/README.md`, "Third-party
 * extensions", and {@see CategoriesTest}'s docblock for why this whole class self-skips cleanly on the
 * Joomla 4.4 matrix entry and on any run without the ATS zip present.
 */
class PostsTest extends AbstractToolTestCase
{
	private array $ats;

	protected function setUp(): void
	{
		parent::setUp();

		$this->requireToolsOrSkip(
			[
				'tickets_posts_list',
				'tickets_posts_list_for_ticket',
				'tickets_posts_read',
				'tickets_posts_create',
				'tickets_posts_update',
				'tickets_posts_delete',
				'tickets_tickets_create',
				'tickets_tickets_delete',
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

	public function testCreatePostAddsAReplyInCreationOrder(): void
	{
		$title  = $this->uniqueName('MCP4j Post Create');
		$ticket = $this->createTicket($title);
		$id     = $ticket['id'];
		$this->registerCleanup(fn () => $this->callTool('tickets_tickets_delete', ['id' => $id]));

		$post = $this->callToolOk(
			'tickets_posts_create',
			['ticketId' => $id, 'content_html' => '<p>My reply</p>']
		);

		$this->assertSame($id, $post['ticket_id']);
		$this->assertSame($this->ats['adminId'], $post['created_by_info']['id'] ?? null);

		$posts = $this->callToolOk('tickets_posts_list_for_ticket', ['ticketId' => $id]);
		$this->assertCount(2, $posts['items']);
		$this->assertStringContainsString('Opening post', $posts['items'][0]['content_html']);
		$this->assertStringContainsString('My reply', $posts['items'][1]['content_html']);
	}

	public function testListHasStandardShape(): void
	{
		$title  = $this->uniqueName('MCP4j Post List');
		$ticket = $this->createTicket($title);
		$id     = $ticket['id'];
		$this->registerCleanup(fn () => $this->callTool('tickets_tickets_delete', ['id' => $id]));

		$post = $this->callToolOk('tickets_posts_create', ['ticketId' => $id, 'content_html' => '<p>Reply</p>']);

		$list = $this->callToolOk('tickets_posts_list', ['pageLimit' => 100]);
		$this->assertListShape($list);

		if ($list['pagination']['total'] <= 100)
		{
			$this->assertContains($post['id'], array_column($list['items'], 'id'));
		}
	}

	public function testUpdatePostChangesContentAndModifiedBy(): void
	{
		$title  = $this->uniqueName('MCP4j Post Update');
		$ticket = $this->createTicket($title);
		$id     = $ticket['id'];
		$this->registerCleanup(fn () => $this->callTool('tickets_tickets_delete', ['id' => $id]));

		$post = $this->callToolOk('tickets_posts_create', ['ticketId' => $id, 'content_html' => '<p>Original</p>']);

		$updated = $this->callToolOk(
			'tickets_posts_update',
			['id' => $post['id'], 'content_html' => '<p>Edited</p>']
		);
		$this->assertStringContainsString('Edited', $updated['content_html']);
		$this->assertSame($this->ats['adminId'], $updated['modified_by']);

		$read = $this->callToolOk('tickets_posts_read', ['id' => $post['id']]);
		$this->assertStringContainsString('Edited', $read['content_html']);
	}

	public function testCreatePostWithCreatedOverride(): void
	{
		$title  = $this->uniqueName('MCP4j Post CreatedOverride');
		$ticket = $this->createTicket($title);
		$id     = $ticket['id'];
		$this->registerCleanup(fn () => $this->callTool('tickets_tickets_delete', ['id' => $id]));

		$post = $this->callToolOk(
			'tickets_posts_create',
			['ticketId' => $id, 'content_html' => '<p>Imported</p>', 'created' => '2021-05-06 07:08:09']
		);

		$read = $this->callToolOk('tickets_posts_read', ['id' => $post['id']]);
		$this->assertSame('2021-05-06 07:08:09', $read['created']);
	}

	public function testDeletePostReturnsToOnePost(): void
	{
		$title  = $this->uniqueName('MCP4j Post Delete');
		$ticket = $this->createTicket($title);
		$id     = $ticket['id'];
		$this->registerCleanup(fn () => $this->callTool('tickets_tickets_delete', ['id' => $id]));

		$post = $this->callToolOk('tickets_posts_create', ['ticketId' => $id, 'content_html' => '<p>Reply</p>']);

		$deleted = $this->callToolOk('tickets_posts_delete', ['id' => $post['id']]);
		$this->assertSame(['deleted' => true, 'id' => $post['id']], $deleted);

		$this->assertNotFound($this->callTool('tickets_posts_read', ['id' => $post['id']]));

		$posts = $this->callToolOk('tickets_posts_list_for_ticket', ['ticketId' => $id]);
		$this->assertCount(1, $posts['items']);
	}

	public function testReadAndListForNonexistentAreNotFound(): void
	{
		$this->assertNotFound($this->callTool('tickets_posts_read', ['id' => 999999]));
		$this->assertNotFound($this->callTool('tickets_posts_list_for_ticket', ['ticketId' => 999999]));
	}

	public function testLowPrivilegeUserIsRefused(): void
	{
		$this->assertPermissionDenied($this->callToolAsLowPriv('tickets_posts_list'));
		$this->assertPermissionDenied(
			$this->callToolAsLowPriv('tickets_posts_create', ['ticketId' => 999999, 'content_html' => '<p>x</p>'])
		);
	}
}
