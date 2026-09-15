<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Integration\Tests\Menus;

use Akeeba\Mcp4j\Tests\Integration\AbstractToolTestCase;

/**
 * End-to-end coverage for the six `menus_adminitems_*` tools
 * ({@see \Akeeba\Component\Mcp4j\Administrator\Tool\Menus\AdminMenuItems}).
 */
class AdminMenuItemsTest extends AbstractToolTestCase
{
	/**
	 * @return  array<string, mixed>
	 */
	private function createTestMenu(): array
	{
		$menu = $this->callToolOk('menus_adminmenus_create', [
			'title'    => $this->uniqueName('MCP4j Admin Item Menu'),
			'menutype' => $this->uniqueAlias('mcpa'),
		]);
		$this->registerCleanup(fn () => $this->callTool('menus_adminmenus_delete', ['id' => $menu['id']]));

		return $menu;
	}

	public function testCreateReadUpdateDeleteUrlAndComponentItems(): void
	{
		$menu = $this->createTestMenu();

		$urlTitle = $this->uniqueName('MCP4j Admin Item Url');
		$urlItem  = $this->callToolOk('menus_adminitems_create', [
			'title'    => $urlTitle,
			'menutype' => $menu['menutype'],
			'type'     => 'url',
			'link'     => 'https://www.example.com/' . $this->uniqueAlias('admin-url'),
		]);
		$urlId = $urlItem['id'];
		$this->registerCleanup(fn () => $this->callTool('menus_adminitems_delete', ['id' => $urlId]));

		$componentTitle = $this->uniqueName('MCP4j Admin Item Component');
		$componentItem  = $this->callToolOk('menus_adminitems_create', [
			'title'    => $componentTitle,
			'menutype' => $menu['menutype'],
			'type'     => 'component',
			'link'     => 'index.php?option=com_content&view=articles',
		]);
		$componentItemId = $componentItem['id'];
		$this->registerCleanup(fn () => $this->callTool('menus_adminitems_delete', ['id' => $componentItemId]));

		$readUrl = $this->callToolOk('menus_adminitems_read', ['id' => $urlId]);
		$this->assertSame(1, $readUrl['client_id']);
		$this->assertSame('url', $readUrl['type']);

		$readComponent = $this->callToolOk('menus_adminitems_read', ['id' => $componentItemId]);
		$this->assertSame(1, $readComponent['client_id']);
		$this->assertSame('component', $readComponent['type']);
		$this->assertStringContainsString('option=com_content', $readComponent['link']);

		$newTitle = $this->uniqueName('MCP4j Admin Item Url Updated');
		$updated  = $this->callToolOk('menus_adminitems_update', ['id' => $urlId, 'title' => $newTitle]);
		$this->assertSame($newTitle, $updated['title']);

		$deletedUrl = $this->callToolOk('menus_adminitems_delete', ['id' => $urlId]);
		$this->assertSame(['deleted' => true, 'id' => $urlId], $deletedUrl);

		$deletedComponent = $this->callToolOk('menus_adminitems_delete', ['id' => $componentItemId]);
		$this->assertSame(['deleted' => true, 'id' => $componentItemId], $deletedComponent);
	}

	/**
	 * "main" is the reserved administrator component-items pseudo-menu: it has no `#__menu_types` row at
	 * all, but a fresh install's entire backend menu structure IS built from `#__menu` rows with
	 * `menutype = 'main'` — see `.plans/06-menus.md` "Key facts" §11. Editing one is hard-refused
	 * regardless of ACL (mirrors `ItemController::allowEdit()`).
	 */
	public function testMainMenutypeFilterListsCoreItemsAndUpdateIsPermissionDenied(): void
	{
		$result = $this->callToolOk('menus_adminitems_list', ['filterMenutype' => 'main']);
		$this->assertListShape($result);
		$this->assertNotSame([], $result['items'], 'Expected core "main" administrator menu items to exist.');

		$mainItem = $result['items'][0];

		$updateResult = $this->callTool('menus_adminitems_update', ['id' => $mainItem['id'], 'note' => 'x']);
		$this->assertPermissionDenied($updateResult);
	}

	public function testTypesReturnsNonEmptyListShape(): void
	{
		$result = $this->callToolOk('menus_adminitems_types');
		$this->assertListShape($result);
		$this->assertNotSame([], $result['items']);
	}

	public function testLowPrivilegeUserIsRefusedList(): void
	{
		$result = $this->callToolAsLowPriv('menus_adminitems_list');
		$this->assertPermissionDenied($result);
	}
}
