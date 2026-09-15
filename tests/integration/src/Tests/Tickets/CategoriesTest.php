<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Integration\Tests\Tickets;

use Akeeba\Mcp4j\Tests\Integration\AbstractToolTestCase;

/**
 * End-to-end coverage for `tickets_categories_list`/`tickets_categories_read`
 * ({@see \Akeeba\Component\Mcp4j\Administrator\Tool\Tickets\Categories}).
 *
 * Requires the Akeeba Ticket System (ATS) extension — see tests/integration/README.md, "Third-party
 * extensions". `requireToolsOrSkip()` self-skips this whole class whenever `com_ats` isn't installed
 * (the registry hides every `tickets_*` tool from `tools/list` in that case, per
 * `#[ToolRequirements(components: ['com_ats'])]`) — expected on the Joomla 4.4 matrix entry, since ATS
 * 5.6.x refuses to install there, and on any run without the ATS zip in
 * `tests/integration/inbox/extensions/` at all.
 */
class CategoriesTest extends AbstractToolTestCase
{
	private array $ats;

	protected function setUp(): void
	{
		parent::setUp();

		$this->requireToolsOrSkip(['tickets_categories_list', 'tickets_categories_read']);

		$this->ats = AtsFixtures::ensure();

		if (!($this->ats['installed'] ?? false))
		{
			$this->markTestSkipped('Akeeba Ticket System is not installed on this site.');
		}
	}

	public function testListContainsFixtureCategoryAndHasListShape(): void
	{
		$result = $this->callToolOk('tickets_categories_list', ['filterSearch' => 'MCP4j ATS']);
		$this->assertListShape($result);

		$ids = array_column($result['items'], 'id');
		$this->assertContains($this->ats['categoryId'], $ids);
	}

	public function testReadFixtureCategory(): void
	{
		$result = $this->callToolOk('tickets_categories_read', ['id' => $this->ats['categoryId']]);

		$this->assertSame('com_ats', $result['extension']);
		$this->assertSame('MCP4j ATS Tests', $result['title']);
	}

	public function testReadForeignCategoryIsNotFound(): void
	{
		$this->assertNotFound(
			$this->callTool('tickets_categories_read', ['id' => $this->ats['foreignCategoryId']])
		);
	}

	public function testReadNonexistentIdIsNotFound(): void
	{
		$this->assertNotFound($this->callTool('tickets_categories_read', ['id' => 999999]));
	}

	public function testLowPrivilegeUserIsRefusedBothTools(): void
	{
		$this->assertPermissionDenied($this->callToolAsLowPriv('tickets_categories_list'));
		$this->assertPermissionDenied(
			$this->callToolAsLowPriv('tickets_categories_read', ['id' => $this->ats['categoryId']])
		);
	}
}
