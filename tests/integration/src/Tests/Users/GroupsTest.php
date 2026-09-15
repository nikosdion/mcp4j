<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Integration\Tests\Users;

use Akeeba\Mcp4j\Tests\Integration\AbstractToolTestCase;

/**
 * End-to-end coverage for the five `users_groups_*` tools
 * ({@see \Akeeba\Component\Mcp4j\Administrator\Tool\Users\Groups}). See `.plans/05-users.md`'s Tests
 * section for the coverage this class satisfies.
 */
class GroupsTest extends AbstractToolTestCase
{
	public function testListShapeAndSearchFilterIncludesUserCount(): void
	{
		$title = $this->uniqueName('MCP4j Group Search');

		$created = $this->callToolOk('users_groups_create', ['title' => $title, 'parentId' => 2]);
		$this->registerCleanup(fn () => $this->callTool('users_groups_delete', ['id' => $created['id']]));

		$all = $this->callToolOk('users_groups_list');
		$this->assertListShape($all);

		$filtered = $this->callToolOk('users_groups_list', ['filterSearch' => $title]);
		$this->assertListShape($filtered);

		$titles = array_column($filtered['items'], 'title');
		$this->assertContains($title, $titles);

		foreach ($filtered['items'] as $item)
		{
			$this->assertArrayHasKey('user_count', $item);
			$this->assertIsInt($item['user_count']);
		}
	}

	public function testFullLifecycle(): void
	{
		$title = $this->uniqueName('MCP4j Group Lifecycle');

		$created = $this->callToolOk('users_groups_create', ['title' => $title, 'parentId' => 2]);
		$id      = $created['id'];
		$this->registerCleanup(fn () => $this->callTool('users_groups_delete', ['id' => $id]));

		$this->assertSame($title, $created['title']);
		$this->assertSame(2, $created['parent_id']);

		$read = $this->callToolOk('users_groups_read', ['id' => $id]);
		$this->assertSame($title, $read['title']);
		$this->assertSame(2, $read['parent_id']);

		$renamedTitle = $this->uniqueName('MCP4j Group Renamed');
		$updated      = $this->callToolOk('users_groups_update', ['id' => $id, 'title' => $renamedTitle]);
		$this->assertSame($renamedTitle, $updated['title']);
		$this->assertSame(2, $updated['parent_id'], 'A partial update must not touch fields it was not given.');

		$childTitle = $this->uniqueName('MCP4j Group Child');
		$child      = $this->callToolOk('users_groups_create', ['title' => $childTitle, 'parentId' => $id]);
		$childId    = $child['id'];
		$this->assertSame($id, $child['parent_id']);

		$deletedChild = $this->callToolOk('users_groups_delete', ['id' => $childId]);
		$this->assertSame(['deleted' => true, 'id' => $childId], $deletedChild);

		$deletedParent = $this->callToolOk('users_groups_delete', ['id' => $id]);
		$this->assertSame(['deleted' => true, 'id' => $id], $deletedParent);

		$this->assertNotFound($this->callTool('users_groups_read', ['id' => $childId]));
		$this->assertNotFound($this->callTool('users_groups_read', ['id' => $id]));
	}

	public function testCreateWithNonexistentParentIsNotFound(): void
	{
		$title = $this->uniqueName('MCP4j Group Bad Parent');

		$this->assertNotFound($this->callTool('users_groups_create', ['title' => $title, 'parentId' => 999999]));
	}

	public function testCannotDeleteTheSuperUsersGroupTheCurrentUserBelongsTo(): void
	{
		// Group 8 is the stock "Super Users" group; the token's own user belongs to it.
		$result = $this->callTool('users_groups_delete', ['id' => 8]);
		$this->assertTrue($result['isError'], 'Deleting a group the current user belongs to must be refused.');

		$stillThere = $this->callToolOk('users_groups_read', ['id' => 8]);
		$this->assertSame(8, $stillThere['id']);
	}

	public function testCannotUpdateOrDeleteTheRootGroup(): void
	{
		$updateResult = $this->callTool('users_groups_update', ['id' => 1, 'title' => 'Tampered Root']);
		$this->assertTrue($updateResult['isError'], 'Updating the root group must be refused.');

		$deleteResult = $this->callTool('users_groups_delete', ['id' => 1]);
		$this->assertTrue($deleteResult['isError'], 'Deleting the root group must be refused.');

		$stillThere = $this->callToolOk('users_groups_read', ['id' => 1]);
		$this->assertSame(1, $stillThere['id']);
	}

	public function testLowPrivilegeUserIsRefusedReadAndWrite(): void
	{
		$this->assertPermissionDenied($this->callToolAsLowPriv('users_groups_list'));

		$title       = $this->uniqueName('MCP4j Group LowPriv');
		$writeResult = $this->callToolAsLowPriv('users_groups_create', ['title' => $title, 'parentId' => 2]);
		$this->assertPermissionDenied($writeResult);

		// Prove nothing was created, as the super user.
		$search = $this->callToolOk('users_groups_list', ['filterSearch' => $title]);
		$this->assertSame([], $search['items']);
	}
}
