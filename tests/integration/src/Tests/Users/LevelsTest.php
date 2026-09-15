<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Integration\Tests\Users;

use Akeeba\Mcp4j\Tests\Integration\AbstractToolTestCase;

/**
 * End-to-end coverage for the five `users_levels_*` tools
 * ({@see \Akeeba\Component\Mcp4j\Administrator\Tool\Users\Levels}). See `.plans/05-users.md`'s Tests
 * section for the coverage this class satisfies.
 */
class LevelsTest extends AbstractToolTestCase
{
	public function testListShapeIncludesDefaultLevelsAndRulesIsIntList(): void
	{
		$result = $this->callToolOk('users_levels_list', ['pageLimit' => 100]);
		$this->assertListShape($result);

		$titles = array_column($result['items'], 'title');

		foreach (['Public', 'Registered', 'Special', 'Guest', 'Super Users'] as $expected)
		{
			$this->assertContains($expected, $titles, "The stock \"$expected\" access level must be present.");
		}

		foreach ($result['items'] as $item)
		{
			$this->assertArrayHasKey('rules', $item);
			$this->assertIsArray($item['rules']);

			foreach ($item['rules'] as $groupId)
			{
				$this->assertIsInt($groupId);
			}
		}
	}

	public function testFullLifecycle(): void
	{
		$title = $this->uniqueName('MCP4j Level Lifecycle');

		$created = $this->callToolOk('users_levels_create', ['title' => $title, 'rules' => [2]]);
		$id      = $created['id'];
		$this->registerCleanup(fn () => $this->callTool('users_levels_delete', ['id' => $id]));

		$this->assertSame($title, $created['title']);
		$this->assertSame([2], $created['rules']);

		$read = $this->callToolOk('users_levels_read', ['id' => $id]);
		$this->assertSame([2], $read['rules']);

		$updated = $this->callToolOk('users_levels_update', ['id' => $id, 'rules' => [2, 3]]);
		$this->assertSame([2, 3], $updated['rules']);

		$readAfterRules = $this->callToolOk('users_levels_read', ['id' => $id]);
		$this->assertSame([2, 3], $readAfterRules['rules']);

		// Partial update: only "title" changes — "rules" must be unaffected.
		$renamedTitle    = $this->uniqueName('MCP4j Level Renamed');
		$titleOnlyUpdate = $this->callToolOk('users_levels_update', ['id' => $id, 'title' => $renamedTitle]);
		$this->assertSame($renamedTitle, $titleOnlyUpdate['title']);
		$this->assertSame([2, 3], $titleOnlyUpdate['rules'], 'A partial update must not touch fields it was not given.');

		$deleted = $this->callToolOk('users_levels_delete', ['id' => $id]);
		$this->assertSame(['deleted' => true, 'id' => $id], $deleted);

		$this->assertNotFound($this->callTool('users_levels_read', ['id' => $id]));
	}

	public function testDeletingAnInUseLevelIsRefused(): void
	{
		// Access level 1 ("Public") is used by categories/tags/etc. installed on every Joomla site.
		// Joomla's own wording for this differs by version (4.4: "...because it is being used by
		// content."; 5.4/6.x fall through to this tool's own "...it is in use..." message) — "use" is the
		// substring common to both.
		$result = $this->callTool('users_levels_delete', ['id' => 1]);

		$this->assertTrue($result['isError'], 'Deleting an in-use access level must be refused.');
		$this->assertStringContainsStringIgnoringCase('use', $result['text']);

		$stillThere = $this->callToolOk('users_levels_read', ['id' => 1]);
		$this->assertSame(1, $stillThere['id']);
	}

	public function testReadNonexistentIdIsNotFound(): void
	{
		$this->assertNotFound($this->callTool('users_levels_read', ['id' => 999999999]));
	}

	public function testLowPrivilegeUserIsRefusedReadAndWrite(): void
	{
		$this->assertPermissionDenied($this->callToolAsLowPriv('users_levels_list'));

		$title       = $this->uniqueName('MCP4j Level LowPriv');
		$writeResult = $this->callToolAsLowPriv('users_levels_create', ['title' => $title, 'rules' => [2]]);
		$this->assertPermissionDenied($writeResult);

		// Prove nothing was created, as the super user.
		$search = $this->callToolOk('users_levels_list', ['filterSearch' => $title]);
		$this->assertSame([], $search['items']);
	}
}
