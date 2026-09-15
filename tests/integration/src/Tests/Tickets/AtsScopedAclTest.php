<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Integration\Tests\Tickets;

use Akeeba\Mcp4j\Tests\Integration\AbstractToolTestCase;

/**
 * Regression coverage for security.md findings M4 (`tickets_tickets_list`/`tickets_posts_list`/
 * `tickets_attachments_list` leak private/department-restricted content) and M5
 * (`tickets_categories_list` enumerates every department regardless of ACL).
 *
 * Fixture shape (see `tests/integration/docker/provision-ats-scoped-manager.php`, run once per Docker
 * stack by `run-tests.sh`'s `provision_scoped_manager()`): a Joomla user — the "scoped manager" — who
 * holds `core.manage` on the `com_ats` COMPONENT root (a genuine ATS manager, so
 * `AbstractAtsTool::requireAtsManager()` passes for every tool below) but is explicitly DENIED
 * `core.manage`/`core.admin` at one specific ATS category, "MCP4j Scoped Denied" — Joomla ACL inheritance
 * means that explicit Deny overrides the inherited component-level Allow for that one category only, so
 * `Permissions::isManager()`/`getManagerCategories()` correctly exclude it while every other category
 * (including "MCP4j Scoped Allowed") stays included. This is exactly the "manager explicitly denied a
 * specific department" scenario M4/M5 describe.
 *
 * Every test here self-skips (via {@see callToolAsScopedManager()}) when `MCP4J_SCOPED_MANAGER_TOKEN`
 * wasn't provided by the harness (i.e. a test run outside `tests/integration/run-tests.sh`), the same way
 * the low-privilege/Author-group regression tests do.
 */
class AtsScopedAclTest extends AbstractToolTestCase
{
	private int $allowedCatId;

	private int $deniedCatId;

	protected function setUp(): void
	{
		parent::setUp();

		$this->requireToolsOrSkip(
			[
				'tickets_tickets_list',
				'tickets_tickets_create',
				'tickets_tickets_delete',
				'tickets_posts_list',
				'tickets_attachments_list',
				'tickets_categories_list',
			]
		);

		$ats = AtsFixtures::ensure();

		if (!($ats['installed'] ?? false))
		{
			$this->markTestSkipped('Akeeba Ticket System is not installed on this site.');
		}

		// Resolve the two fixed-alias categories provision-ats-scoped-manager.php created, as the Super
		// User (sees every category regardless of ACL) — avoids needing a second DB-touching fixture
		// script just to look up two ids the provisioning script already created.
		$categories = $this->callToolOk('tickets_categories_list', ['filterSearch' => 'MCP4j Scoped']);

		$byTitle = [];

		foreach ($categories['items'] as $category)
		{
			$byTitle[$category['title']] = (int) $category['id'];
		}

		if (!isset($byTitle['MCP4j Scoped Allowed'], $byTitle['MCP4j Scoped Denied']))
		{
			$this->markTestSkipped(
				'ATS scoped-manager fixture categories not found — run via tests/integration/run-tests.sh ' .
				'so provision-ats-scoped-manager.php has run.'
			);
		}

		$this->allowedCatId = $byTitle['MCP4j Scoped Allowed'];
		$this->deniedCatId  = $byTitle['MCP4j Scoped Denied'];
	}

	/**
	 * @return array{id: int, title: string}
	 */
	private function createTicket(int $catid, string $title, array $overrides = []): array
	{
		return $this->callToolOk(
			'tickets_tickets_create',
			array_merge(
				['catid' => $catid, 'title' => $title, 'content_html' => '<p>Fixture</p>'],
				$overrides
			)
		);
	}

	// -----------------------------------------------------------------------------------------------
	// M4 — tickets_tickets_list
	// -----------------------------------------------------------------------------------------------

	public function testTicketsListExcludesDeniedDepartmentIncludesAllowed(): void
	{
		$tag = $this->uniqueName('AtsScoped Tickets');

		$allowed = $this->createTicket($this->allowedCatId, $tag . ' Allowed');
		$this->registerCleanup(fn () => $this->callTool('tickets_tickets_delete', ['id' => $allowed['id']]));

		$denied = $this->createTicket($this->deniedCatId, $tag . ' Denied');
		$this->registerCleanup(fn () => $this->callTool('tickets_tickets_delete', ['id' => $denied['id']]));

		$result = $this->callToolAsScopedManager('tickets_tickets_list', ['filterSearch' => $tag]);
		$this->assertFalse($result['isError'], 'callToolAsScopedManager(tickets_tickets_list) failed: ' . $result['text']);

		$ids = array_column($result['data']['items'], 'id');

		$this->assertContains($allowed['id'], $ids, 'The allowed department ticket must be listed.');
		$this->assertNotContains($denied['id'], $ids, 'security.md M4: the denied department ticket must NOT be listed.');
	}

	public function testTicketsListWithExplicitDeniedFilterCategoryIsRefused(): void
	{
		$result = $this->callToolAsScopedManager('tickets_tickets_list', ['filterCategory' => $this->deniedCatId]);

		$this->assertPermissionDenied($result, 'Filtering by an explicitly-denied department must be refused, not silently emptied.');
	}

	public function testTicketsListWithExplicitAllowedFilterCategoryStillWorks(): void
	{
		$tag     = $this->uniqueName('AtsScoped FilterCategory');
		$allowed = $this->createTicket($this->allowedCatId, $tag);
		$this->registerCleanup(fn () => $this->callTool('tickets_tickets_delete', ['id' => $allowed['id']]));

		$result = $this->callToolAsScopedManager(
			'tickets_tickets_list',
			['filterCategory' => $this->allowedCatId, 'filterSearch' => $tag]
		);

		$this->assertFalse($result['isError']);
		$this->assertContains($allowed['id'], array_column($result['data']['items'], 'id'));
	}

	public function testTicketsListIncludesPrivateTicketInAllowedCategory(): void
	{
		// Regression control: the scoped manager IS a full manager of the ALLOWED category (only the
		// OTHER category is denied), so a private ticket there must still be visible — the fix must not
		// over-tighten and hide tickets the manager genuinely has rights to.
		$tag    = $this->uniqueName('AtsScoped Private');
		$ticket = $this->createTicket($this->allowedCatId, $tag, ['public' => 0]);
		$this->registerCleanup(fn () => $this->callTool('tickets_tickets_delete', ['id' => $ticket['id']]));

		$result = $this->callToolAsScopedManager('tickets_tickets_list', ['filterSearch' => $tag]);

		$this->assertFalse($result['isError']);
		$this->assertContains($ticket['id'], array_column($result['data']['items'], 'id'));
	}

	public function testTicketsListPaginationIsCorrectOverTheFilteredSet(): void
	{
		$tag = $this->uniqueName('AtsScoped Paging');
		$ids = [];

		for ($i = 0; $i < 5; $i++)
		{
			$ticket = $this->createTicket($this->allowedCatId, $tag . ' ' . $i);
			$ids[]  = $ticket['id'];
			$this->registerCleanup(fn () => $this->callTool('tickets_tickets_delete', ['id' => $ticket['id']]));
		}

		sort($ids);

		$collected = [];

		foreach ([0, 2, 4] as $offset)
		{
			$page = $this->callToolAsScopedManager(
				'tickets_tickets_list',
				[
					'filterSearch'   => $tag,
					'pageLimit'      => 2,
					'pageOffset'     => $offset,
					'orderBy'        => 'id',
					'orderDirection' => 'ASC',
				]
			);

			$this->assertFalse($page['isError']);
			$this->assertSame(5, $page['data']['pagination']['total'], "total must reflect the FILTERED count at offset {$offset}.");
			$this->assertSame($offset, $page['data']['pagination']['offset']);

			$expectedCount = $offset === 4 ? 1 : 2;
			$this->assertCount($expectedCount, $page['data']['items'], "Unexpected page size at offset {$offset}.");

			array_push($collected, ...array_column($page['data']['items'], 'id'));
		}

		sort($collected);

		$this->assertSame(
			$ids,
			$collected,
			'Paging through the filtered set must return every fixture ticket exactly once, with no duplicates or gaps.'
		);
	}

	// -----------------------------------------------------------------------------------------------
	// M4 — tickets_posts_list
	// -----------------------------------------------------------------------------------------------

	public function testPostsListExcludesDeniedDepartmentReplies(): void
	{
		$tag = $this->uniqueName('AtsScoped Posts');

		// tickets_tickets_create always saves an opening post — no need to create a separate reply.
		$allowed = $this->createTicket($this->allowedCatId, $tag . ' Allowed');
		$this->registerCleanup(fn () => $this->callTool('tickets_tickets_delete', ['id' => $allowed['id']]));

		$denied = $this->createTicket($this->deniedCatId, $tag . ' Denied');
		$this->registerCleanup(fn () => $this->callTool('tickets_tickets_delete', ['id' => $denied['id']]));

		$result = $this->callToolAsScopedManager('tickets_posts_list', ['pageLimit' => 100]);
		$this->assertFalse($result['isError'], 'callToolAsScopedManager(tickets_posts_list) failed: ' . $result['text']);

		$ticketIds = array_column($result['data']['items'], 'ticket_id');

		$this->assertContains($allowed['id'], $ticketIds, 'The allowed department post must be listed.');
		$this->assertNotContains($denied['id'], $ticketIds, 'security.md M4: the denied department post must NOT be listed.');
	}

	// -----------------------------------------------------------------------------------------------
	// M4 — tickets_attachments_list (ATS Pro only)
	// -----------------------------------------------------------------------------------------------

	public function testAttachmentsListExcludesDeniedDepartmentAttachments(): void
	{
		$allowedFixture = AtsAttachmentsFixtures::create($this->allowedCatId);

		if (!($allowedFixture['pro'] ?? false))
		{
			$this->markTestSkipped('Requires Akeeba Ticket System Pro.');
		}

		$this->registerCleanup(fn () => $this->callTool('tickets_tickets_delete', ['id' => $allowedFixture['ticketId']]));

		$deniedFixture = AtsAttachmentsFixtures::create($this->deniedCatId);
		$this->registerCleanup(fn () => $this->callTool('tickets_tickets_delete', ['id' => $deniedFixture['ticketId']]));

		$result = $this->callToolAsScopedManager('tickets_attachments_list', ['pageLimit' => 100]);
		$this->assertFalse($result['isError'], 'callToolAsScopedManager(tickets_attachments_list) failed: ' . $result['text']);

		$postIds = array_column($result['data']['items'], 'post_id');

		$this->assertContains($allowedFixture['postId'], $postIds, 'The allowed department attachment must be listed.');
		$this->assertNotContains(
			$deniedFixture['postId'],
			$postIds,
			'security.md M4: the denied department attachment must NOT be listed.'
		);
	}

	// -----------------------------------------------------------------------------------------------
	// M5 — tickets_categories_list
	// -----------------------------------------------------------------------------------------------

	public function testCategoriesListExcludesDeniedDepartment(): void
	{
		$result = $this->callToolAsScopedManager('tickets_categories_list', ['filterSearch' => 'MCP4j Scoped']);
		$this->assertFalse($result['isError'], 'callToolAsScopedManager(tickets_categories_list) failed: ' . $result['text']);

		$titles = array_column($result['data']['items'], 'title');

		$this->assertContains('MCP4j Scoped Allowed', $titles);
		$this->assertNotContains('MCP4j Scoped Denied', $titles, 'security.md M5: the denied department must NOT be listed.');
	}

	// -----------------------------------------------------------------------------------------------
	// Control: an unrestricted (Super User) caller must still see everything — no false-negative
	// regression from the M4/M5 fix.
	// -----------------------------------------------------------------------------------------------

	public function testSuperUserControlSeesBothDepartmentsAndBothTickets(): void
	{
		$tag = $this->uniqueName('AtsScoped Control');

		$allowed = $this->createTicket($this->allowedCatId, $tag . ' Allowed');
		$this->registerCleanup(fn () => $this->callTool('tickets_tickets_delete', ['id' => $allowed['id']]));

		$denied = $this->createTicket($this->deniedCatId, $tag . ' Denied');
		$this->registerCleanup(fn () => $this->callTool('tickets_tickets_delete', ['id' => $denied['id']]));

		$tickets = $this->callToolOk('tickets_tickets_list', ['filterSearch' => $tag]);
		$ids     = array_column($tickets['items'], 'id');
		$this->assertContains($allowed['id'], $ids);
		$this->assertContains($denied['id'], $ids);

		$categories = $this->callToolOk('tickets_categories_list', ['filterSearch' => 'MCP4j Scoped']);
		$titles     = array_column($categories['items'], 'title');
		$this->assertContains('MCP4j Scoped Allowed', $titles);
		$this->assertContains('MCP4j Scoped Denied', $titles);
	}
}
