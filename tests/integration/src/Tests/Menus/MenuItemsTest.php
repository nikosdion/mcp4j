<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Integration\Tests\Menus;

use Akeeba\Mcp4j\Tests\Integration\AbstractToolTestCase;

/**
 * End-to-end coverage for the six `menus_siteitems_*` tools
 * ({@see \Akeeba\Component\Mcp4j\Administrator\Tool\Menus\MenuItems}).
 */
class MenuItemsTest extends AbstractToolTestCase
{
	/**
	 * Creates a fresh site menu and registers its cleanup FIRST, so it is deleted LAST (after any per-item
	 * cleanups a test also registers, which run in reverse registration order) — deleting a menu type
	 * cascades to delete any items still assigned to it, and any mod_menu modules pointed at it.
	 *
	 * @return  array<string, mixed>
	 */
	private function createTestMenu(): array
	{
		$menu = $this->callToolOk('menus_sitemenus_create', [
			'title'    => $this->uniqueName('MCP4j Item Menu'),
			'menutype' => $this->uniqueAlias('mcp'),
		]);
		$this->registerCleanup(fn () => $this->callTool('menus_sitemenus_delete', ['id' => $menu['id']]));

		return $menu;
	}

	public function testListShapeFilterMenutypeSearchAndState(): void
	{
		$menu = $this->createTestMenu();

		$titleA = $this->uniqueName('MCP4j Item Alpha');
		$itemA  = $this->callToolOk('menus_siteitems_create', [
			'title'     => $titleA,
			'menutype'  => $menu['menutype'],
			'type'      => 'url',
			'link'      => 'https://www.example.com/' . $this->uniqueAlias('a'),
			'published' => 1,
		]);
		$this->registerCleanup(fn () => $this->callTool('menus_siteitems_delete', ['id' => $itemA['id']]));

		$titleB = $this->uniqueName('MCP4j Item Beta');
		$itemB  = $this->callToolOk('menus_siteitems_create', [
			'title'     => $titleB,
			'menutype'  => $menu['menutype'],
			'type'      => 'url',
			'link'      => 'https://www.example.com/' . $this->uniqueAlias('b'),
			'published' => 0,
		]);
		$this->registerCleanup(fn () => $this->callTool('menus_siteitems_delete', ['id' => $itemB['id']]));

		$byMenu = $this->callToolOk('menus_siteitems_list', ['filterMenutype' => $menu['menutype']]);
		$this->assertListShape($byMenu);
		$ids = array_column($byMenu['items'], 'id');
		sort($ids);
		$expected = [$itemA['id'], $itemB['id']];
		sort($expected);
		$this->assertSame($expected, $ids, 'filterMenutype must return exactly the two items just created.');

		$bySearch  = $this->callToolOk('menus_siteitems_list', ['filterMenutype' => $menu['menutype'], 'filterSearch' => $titleA]);
		$searchIds = array_column($bySearch['items'], 'id');
		$this->assertSame([$itemA['id']], $searchIds);

		$byState  = $this->callToolOk('menus_siteitems_list', ['filterMenutype' => $menu['menutype'], 'filterState' => 0]);
		$stateIds = array_column($byState['items'], 'id');
		$this->assertSame([$itemB['id']], $stateIds);
	}

	public function testFullLifecycleAndNesting(): void
	{
		$menu = $this->createTestMenu();

		$title = $this->uniqueName('MCP4j Item Lifecycle');
		$link  = 'https://www.example.com/' . $this->uniqueAlias('lifecycle');

		$created = $this->callToolOk('menus_siteitems_create', [
			'title'    => $title,
			'menutype' => $menu['menutype'],
			'type'     => 'url',
			'link'     => $link,
			'note'     => 'Original note',
		]);
		$id = $created['id'];
		$this->registerCleanup(fn () => $this->callTool('menus_siteitems_delete', ['id' => $id]));

		$read = $this->callToolOk('menus_siteitems_read', ['id' => $id]);
		$this->assertSame('url', $read['type']);
		$this->assertSame($link, $read['link']);
		$this->assertSame($menu['menutype'], $read['menutype']);
		$this->assertSame(1, $read['parent_id']);
		$this->assertSame(1, $read['level']);

		// Partial update: only "note" changes — "title"/"link" must be unaffected.
		$updated = $this->callToolOk('menus_siteitems_update', ['id' => $id, 'note' => 'Updated note']);
		$this->assertSame('Updated note', $updated['note']);
		$this->assertSame($title, $updated['title']);
		$this->assertSame($link, $updated['link']);

		$childTitle = $this->uniqueName('MCP4j Item Child');
		$child      = $this->callToolOk('menus_siteitems_create', [
			'title'    => $childTitle,
			'menutype' => $menu['menutype'],
			'type'     => 'url',
			'link'     => 'https://www.example.com/' . $this->uniqueAlias('child'),
			'parentId' => $id,
		]);
		$childId = $child['id'];
		$this->registerCleanup(fn () => $this->callTool('menus_siteitems_delete', ['id' => $childId]));

		$readChild = $this->callToolOk('menus_siteitems_read', ['id' => $childId]);
		$this->assertSame($id, $readChild['parent_id']);
		$this->assertSame(2, $readChild['level']);

		$deletedChild = $this->callToolOk('menus_siteitems_delete', ['id' => $childId]);
		$this->assertSame(['deleted' => true, 'id' => $childId], $deletedChild);

		$deletedParent = $this->callToolOk('menus_siteitems_delete', ['id' => $id]);
		$this->assertSame(['deleted' => true, 'id' => $id], $deletedParent);

		$this->assertNotFound($this->callTool('menus_siteitems_read', ['id' => $childId]));
		$this->assertNotFound($this->callTool('menus_siteitems_read', ['id' => $id]));
	}

	public function testComponentItemLinkAndRequestRoundTrip(): void
	{
		$menu = $this->createTestMenu();

		$title = $this->uniqueName('MCP4j Item Component');

		$created = $this->callToolOk('menus_siteitems_create', [
			'title'    => $title,
			'menutype' => $menu['menutype'],
			'type'     => 'component',
			'link'     => 'index.php?option=com_content&view=category',
			'request'  => ['id' => 2],
		]);
		$id = $created['id'];
		$this->registerCleanup(fn () => $this->callTool('menus_siteitems_delete', ['id' => $id]));

		$this->assertSame('index.php?option=com_content&view=category&id=2', $created['link']);
		$this->assertSame(2, (int) $created['request']['id']);

		$read = $this->callToolOk('menus_siteitems_read', ['id' => $id]);
		$this->assertSame('index.php?option=com_content&view=category&id=2', $read['link']);
		$this->assertSame(2, (int) $read['request']['id']);

		$newTitle = $this->uniqueName('MCP4j Item Component Updated');
		$updated  = $this->callToolOk('menus_siteitems_update', [
			'id'      => $id,
			'title'   => $newTitle,
			'request' => ['id' => 2],
		]);
		$this->assertSame($newTitle, $updated['title']);
		$this->assertSame('index.php?option=com_content&view=category&id=2', $updated['link']);
	}

	public function testComponentItemMissingRequiredRequestFieldFailsValidation(): void
	{
		$menu = $this->createTestMenu();

		$result = $this->callTool('menus_siteitems_create', [
			'title'    => $this->uniqueName('MCP4j Item Missing Request'),
			'menutype' => $menu['menutype'],
			'type'     => 'component',
			'link'     => 'index.php?option=com_content&view=category',
		]);

		$this->assertTrue($result['isError']);
		$this->assertStringContainsString('Validation failed', $result['text']);
	}

	public function testUrlItemWithDisallowedSchemeIsRejected(): void
	{
		$menu  = $this->createTestMenu();
		$title = $this->uniqueName('MCP4j Item JS');

		$result = $this->callTool('menus_siteitems_create', [
			'title'    => $title,
			'menutype' => $menu['menutype'],
			'type'     => 'url',
			'link'     => 'javascript:alert(1)',
		]);
		$this->assertTrue($result['isError']);

		$search = $this->callToolOk('menus_siteitems_list', ['filterMenutype' => $menu['menutype'], 'filterSearch' => $title]);
		$this->assertSame([], $search['items'], 'Nothing must have been created.');
	}

	public function testNonEmptyTagsAreRejected(): void
	{
		$menu = $this->createTestMenu();

		$result = $this->callTool('menus_siteitems_create', [
			'title'    => $this->uniqueName('MCP4j Item Tags'),
			'menutype' => $menu['menutype'],
			'type'     => 'url',
			'link'     => 'https://www.example.com/' . $this->uniqueAlias('tags'),
			'tags'     => [1],
		]);

		$this->assertTrue($result['isError']);
		$this->assertStringContainsString('tags', strtolower($result['text']));
	}

	/**
	 * A fresh Joomla install's "mainmenu" holds the default home item (id 101 on a fresh install, but this
	 * test does not hard-code it — see `.plans/06-menus.md` "Key facts" §11).
	 * `ItemModel::publish()` silently drops the default home item from unpublish/trash requests, so this
	 * tool gives the LLM the real reason up front instead — see `AbstractMenuItemsTool::doDelete()`.
	 */
	public function testDefaultHomeItemCannotBeDeleted(): void
	{
		$list = $this->callToolOk('menus_siteitems_list', ['filterMenutype' => 'mainmenu']);
		$this->assertListShape($list);

		$home = null;

		foreach ($list['items'] as $item)
		{
			if ((int) $item['home'] === 1)
			{
				$home = $item;

				break;
			}
		}

		$this->assertNotNull($home, 'Could not find the default home item in "mainmenu".');

		$result = $this->callTool('menus_siteitems_delete', ['id' => $home['id']]);
		$this->assertTrue($result['isError']);
		$this->assertStringContainsString('home', strtolower($result['text']));

		$read = $this->callToolOk('menus_siteitems_read', ['id' => $home['id']]);
		$this->assertSame(1, $read['published']);
	}

	public function testTypesListsComponentEntries(): void
	{
		$result = $this->callToolOk('menus_siteitems_types');
		$this->assertListShape($result);
		$this->assertNotSame([], $result['items']);

		$found = null;

		foreach ($result['items'] as $item)
		{
			if (($item['request']['option'] ?? null) === 'com_content')
			{
				$found = $item;

				break;
			}
		}

		$this->assertNotNull($found, 'Expected a com_content entry in menu item types.');
		$this->assertDoesNotMatchRegularExpression('/^COM_/', $found['title'], 'Title must be translated, not a raw language key.');
	}

	public function testCreatingInASiteMenuUsingTheAdminToolIsNotFound(): void
	{
		$menu = $this->createTestMenu();

		$result = $this->callTool('menus_adminitems_create', [
			'title'    => $this->uniqueName('MCP4j Item Wrong Client'),
			'menutype' => $menu['menutype'],
			'type'     => 'url',
			'link'     => 'https://www.example.com/' . $this->uniqueAlias('wrongclient'),
		]);

		$this->assertNotFound($result);
	}

	public function testLowPrivilegeUserIsRefused(): void
	{
		$listResult = $this->callToolAsLowPriv('menus_siteitems_list');
		$this->assertPermissionDenied($listResult);

		$title        = $this->uniqueName('MCP4j Item LowPriv');
		$createResult = $this->callToolAsLowPriv('menus_siteitems_create', [
			'title'    => $title,
			'menutype' => 'mainmenu',
			'type'     => 'url',
			'link'     => 'https://www.example.com/' . $this->uniqueAlias('lowpriv'),
		]);
		$this->assertPermissionDenied($createResult);

		$typesResult = $this->callToolAsLowPriv('menus_siteitems_types');
		$this->assertPermissionDenied($typesResult);

		$search = $this->callToolOk('menus_siteitems_list', ['filterSearch' => $title]);
		$this->assertSame([], $search['items'], 'Nothing must have been created.');
	}
}
