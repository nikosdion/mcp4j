<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Integration\Tests\Tickets;

use Akeeba\Mcp4j\Tests\Integration\AbstractToolTestCase;

/**
 * End-to-end coverage for the `tickets_tickets_*` tools
 * ({@see \Akeeba\Component\Mcp4j\Administrator\Tool\Tickets\Tickets}).
 *
 * Requires the Akeeba Ticket System (ATS) extension — see `tests/integration/README.md`, "Third-party
 * extensions", and {@see CategoriesTest}'s docblock for why this whole class self-skips cleanly on the
 * Joomla 4.4 matrix entry and on any run without the ATS zip present.
 *
 * Not covered here (documented per `.plans/16a-tickets-core.md`, "Not testable in Docker"): the category
 * view-level IDOR rule — a Super User holds every view level, so this harness cannot exercise
 * `canAccessCategory()`'s restriction; ATS's own `ApiTicketsTest` covers it. E-mail suppression is
 * likewise untestable here (no mail sink in this harness) — see the plan's "Facts verified" #6 for why no
 * tool in this category ever triggers mail regardless.
 */
class TicketsTest extends AbstractToolTestCase
{
	private array $ats;

	protected function setUp(): void
	{
		parent::setUp();

		$this->requireToolsOrSkip(
			[
				'tickets_tickets_list',
				'tickets_tickets_open',
				'tickets_tickets_read',
				'tickets_tickets_create',
				'tickets_tickets_update',
				'tickets_tickets_delete',
				'tickets_posts_list_for_ticket',
				'tickets_posts_read',
			]
		);

		$this->ats = AtsFixtures::ensure();

		if (!($this->ats['installed'] ?? false))
		{
			$this->markTestSkipped('Akeeba Ticket System is not installed on this site.');
		}
	}

	private function createTicket(string $title, array $overrides = []): array
	{
		return $this->callToolOk(
			'tickets_tickets_create',
			array_merge(
				[
					'catid'        => $this->ats['categoryId'],
					'title'        => $title,
					'content_html' => '<p>Hello from MCP4j</p>',
				],
				$overrides
			)
		);
	}

	public function testCreateStoresOpeningPostAndBasicFields(): void
	{
		$title  = $this->uniqueName('MCP4j Ticket Create');
		$ticket = $this->createTicket($title);
		$id     = $ticket['id'];
		$this->registerCleanup(fn () => $this->callTool('tickets_tickets_delete', ['id' => $id]));

		$this->assertSame($title, $ticket['title']);
		$this->assertSame('O', $ticket['status']);
		$this->assertSame(5, $ticket['priority']);
		$this->assertSame($this->ats['adminId'], $ticket['created_by']);
		$this->assertSame('admin', $ticket['created_by_info']['username'] ?? null);

		// Deviation #1: ATS's own API validates content_html but never stores it. We follow the backend
		// controller flow instead, so the opening post really exists.
		$posts = $this->callToolOk('tickets_posts_list_for_ticket', ['ticketId' => $id]);
		$this->assertCount(1, $posts['items']);
		$this->assertStringContainsString('Hello from MCP4j', $posts['items'][0]['content_html']);
	}

	public function testReadIncludesPostsOnlyWhenAsked(): void
	{
		$title  = $this->uniqueName('MCP4j Ticket IncludePosts');
		$ticket = $this->createTicket($title);
		$id     = $ticket['id'];
		$this->registerCleanup(fn () => $this->callTool('tickets_tickets_delete', ['id' => $id]));

		$withoutPosts = $this->callToolOk('tickets_tickets_read', ['id' => $id]);
		$this->assertArrayNotHasKey('posts', $withoutPosts);

		$withPosts = $this->callToolOk('tickets_tickets_read', ['id' => $id, 'includePosts' => true]);
		$this->assertArrayHasKey('posts', $withPosts);
		$this->assertCount(1, $withPosts['posts']);
	}

	public function testListFiltersAndOpenShortcut(): void
	{
		$title  = $this->uniqueName('MCP4j Ticket ListFilter');
		$ticket = $this->createTicket($title);
		$id     = $ticket['id'];
		$this->registerCleanup(fn () => $this->callTool('tickets_tickets_delete', ['id' => $id]));

		$bySearch = $this->callToolOk('tickets_tickets_list', ['filterSearch' => $title]);
		$this->assertSame([$id], array_column($bySearch['items'], 'id'));

		// Deviation #2: filterCategory actually works (ATS's own API ignored it).
		$byCategory = $this->callToolOk(
			'tickets_tickets_list',
			['filterCategory' => $this->ats['categoryId'], 'filterSearch' => $title]
		);
		$this->assertContains($id, array_column($byCategory['items'], 'id'));

		$byClosedStatus = $this->callToolOk('tickets_tickets_list', ['filterStatus' => 'C', 'filterSearch' => $title]);
		$this->assertSame([], $byClosedStatus['items']);

		$open = $this->callToolOk('tickets_tickets_open', ['filterSearch' => $title]);
		$this->assertContains($id, array_column($open['items'], 'id'));
	}

	public function testOrderingByCreatedOnAndInvalidOrderByIsAToolError(): void
	{
		$prefix = 'MCP4j Ticket Order ' . bin2hex(random_bytes(4));

		$first = $this->createTicket($prefix . ' A');
		$this->registerCleanup(fn () => $this->callTool('tickets_tickets_delete', ['id' => $first['id']]));

		// Ensure a distinguishable creation instant between the two fixtures (DATETIME has 1s resolution).
		sleep(1);

		$second = $this->createTicket($prefix . ' B');
		$this->registerCleanup(fn () => $this->callTool('tickets_tickets_delete', ['id' => $second['id']]));

		// Deviation #2: orderBy: created_on actually works (ATS's own API ignored it — "created_on" isn't
		// a real column).
		$asc = $this->callToolOk(
			'tickets_tickets_list',
			['filterSearch' => $prefix, 'orderBy' => 'created_on', 'orderDirection' => 'ASC']
		);
		$this->assertSame([$first['id'], $second['id']], array_column($asc['items'], 'id'));

		$desc = $this->callToolOk(
			'tickets_tickets_list',
			['filterSearch' => $prefix, 'orderBy' => 'created_on', 'orderDirection' => 'DESC']
		);
		$this->assertSame([$second['id'], $first['id']], array_column($desc['items'], 'id'));

		// Deviation #2: an unknown orderBy is a tool error instead of being silently ignored.
		$invalid = $this->callTool('tickets_tickets_list', ['orderBy' => 'password']);
		$this->assertTrue($invalid['isError']);
		$this->assertStringContainsString('Invalid orderBy', $invalid['text']);
	}

	public function testUpdateTitleOnlyLeavesStatusUnchangedThenUpdateStatus(): void
	{
		$title  = $this->uniqueName('MCP4j Ticket Update');
		$ticket = $this->createTicket($title);
		$id     = $ticket['id'];
		$this->registerCleanup(fn () => $this->callTool('tickets_tickets_delete', ['id' => $id]));

		$newTitle = $title . ' (edited)';
		$updated  = $this->callToolOk('tickets_tickets_update', ['id' => $id, 'title' => $newTitle]);
		$this->assertSame($newTitle, $updated['title']);
		$this->assertSame('O', $updated['status'], 'A partial update must not touch fields it was not given.');

		$closed = $this->callToolOk('tickets_tickets_update', ['id' => $id, 'status' => 'C']);
		$this->assertSame('C', $closed['status']);

		$read = $this->callToolOk('tickets_tickets_read', ['id' => $id]);
		$this->assertSame('C', $read['status']);

		$open = $this->callToolOk('tickets_tickets_open', ['filterSearch' => $newTitle]);
		$this->assertSame([], $open['items'], 'A closed ticket must not appear in tickets_tickets_open.');
	}

	public function testUpdateCreatedOverrideRequiresCoreAdminAndIsHonoured(): void
	{
		$title  = $this->uniqueName('MCP4j Ticket TimestampOverride');
		$ticket = $this->createTicket($title);
		$id     = $ticket['id'];
		$this->registerCleanup(fn () => $this->callTool('tickets_tickets_delete', ['id' => $id]));

		$updated = $this->callToolOk('tickets_tickets_update', ['id' => $id, 'created' => '2020-01-02T03:04:05Z']);
		$this->assertSame('2020-01-02 03:04:05', $updated['created']);

		$read = $this->callToolOk('tickets_tickets_read', ['id' => $id]);
		$this->assertSame('2020-01-02 03:04:05', $read['created']);
	}

	public function testCustomFieldsRegression(): void
	{
		$fixture  = AtsFixtures::fields();
		$ticketId = $fixture['ticketId'];
		$this->registerCleanup(fn () => $this->callTool('tickets_tickets_delete', ['id' => $ticketId]));

		// d9e4257 regression: updating a ticket without touching com_fields must not fail form validation
		// for a required custom field, and must carry the existing value forward unchanged.
		$updated = $this->callToolOk('tickets_tickets_update', ['id' => $ticketId, 'title' => 'x']);
		$this->assertSame('initial', $updated['com_fields'][$fixture['fieldName']] ?? null);

		$changed = $this->callToolOk(
			'tickets_tickets_update',
			['id' => $ticketId, 'com_fields' => [$fixture['fieldName'] => 'changed']]
		);
		$this->assertSame('changed', $changed['com_fields'][$fixture['fieldName']] ?? null);
	}

	public function testCreateIntoForeignCategoryIsNotFoundAndCreatesNothing(): void
	{
		$title = $this->uniqueName('MCP4j Ticket ForeignCategory');

		$result = $this->callTool(
			'tickets_tickets_create',
			[
				'catid'        => $this->ats['foreignCategoryId'],
				'title'        => $title,
				'content_html' => '<p>Should not be created</p>',
			]
		);

		$this->assertNotFound($result, 'Expected "Not found: ticket category" for a non-ATS category.');

		$search = $this->callToolOk('tickets_tickets_list', ['filterSearch' => $title]);
		$this->assertSame([], $search['items']);
	}

	public function testOutputWhitelist(): void
	{
		$title  = $this->uniqueName('MCP4j Ticket Whitelist');
		$ticket = $this->createTicket($title);
		$id     = $ticket['id'];
		$this->registerCleanup(fn () => $this->callTool('tickets_tickets_delete', ['id' => $id]));

		$read = $this->callToolOk('tickets_tickets_read', ['id' => $id]);
		$this->assertArrayNotHasKey('email_uid', $read);
		$this->assertKeysAbsent(
			$read['created_by_info'],
			['password', 'otpKey', 'otep', 'activation', 'params', 'requireReset']
		);

		$list = $this->callToolOk('tickets_tickets_list', ['filterSearch' => $title]);
		$this->assertArrayNotHasKey('email_uid', $list['items'][0]);
		$this->assertKeysAbsent(
			$list['items'][0]['created_by_info'],
			['password', 'otpKey', 'otep', 'activation', 'params', 'requireReset']
		);
	}

	public function testDeleteCascadesToOpeningPostAndBothReadNotFound(): void
	{
		$title  = $this->uniqueName('MCP4j Ticket Delete');
		$ticket = $this->createTicket($title);
		$id     = $ticket['id'];

		$posts  = $this->callToolOk('tickets_posts_list_for_ticket', ['ticketId' => $id]);
		$postId = $posts['items'][0]['id'];

		$deleted = $this->callToolOk('tickets_tickets_delete', ['id' => $id]);
		$this->assertSame(['deleted' => true, 'id' => $id], $deleted);

		$this->assertNotFound($this->callTool('tickets_tickets_read', ['id' => $id]));
		$this->assertNotFound($this->callTool('tickets_posts_read', ['id' => $postId]));
	}

	public function testReadNonexistentIdIsNotFound(): void
	{
		$this->assertNotFound($this->callTool('tickets_tickets_read', ['id' => 999999]));
	}

	public function testLowPrivilegeUserIsRefused(): void
	{
		$this->assertPermissionDenied($this->callToolAsLowPriv('tickets_tickets_list'));

		$title = $this->uniqueName('MCP4j Ticket LowPriv');
		$this->assertPermissionDenied(
			$this->callToolAsLowPriv(
				'tickets_tickets_create',
				['catid' => $this->ats['categoryId'], 'title' => $title, 'content_html' => '<p>Nope</p>']
			)
		);

		$search = $this->callToolOk('tickets_tickets_list', ['filterSearch' => $title]);
		$this->assertSame([], $search['items']);
	}
}
