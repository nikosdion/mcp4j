<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Integration\Tests\Menus;

/**
 * End-to-end coverage for the five `menus_sitemenus_*` tools
 * ({@see \Akeeba\Component\Mcp4j\Administrator\Tool\Menus\Menus}).
 */
class MenusTest extends AbstractMenusToolCase
{
	protected function prefix(): string
	{
		return 'menus_sitemenus';
	}

	protected function otherPrefix(): string
	{
		return 'menus_adminmenus';
	}

	protected function expectedClientId(): int
	{
		return 0;
	}

	/**
	 * A fresh Joomla install ships a "mainmenu" site menu holding the default home item — see
	 * `.plans/06-menus.md` "Key facts" §11. `MenuType::delete()` refuses to delete a menu that still holds
	 * the default home item (or a checked-out item) — see the same file's Table\MenuType notes — so this
	 * must fail, and the menu must still be there afterwards.
	 */
	public function testDeletingTheDefaultMainmenuIsRefused(): void
	{
		$list = $this->callToolOk('menus_sitemenus_list', ['filterSearch' => 'mainmenu']);
		$this->assertListShape($list);

		$mainmenu = null;

		foreach ($list['items'] as $item)
		{
			if ($item['menutype'] === 'mainmenu')
			{
				$mainmenu = $item;

				break;
			}
		}

		$this->assertNotNull($mainmenu, 'Could not find the "mainmenu" menu type by search.');

		$result = $this->callTool('menus_sitemenus_delete', ['id' => $mainmenu['id']]);
		$this->assertTrue($result['isError'], 'Expected deleting "mainmenu" to be refused: ' . $result['text']);

		$read = $this->callToolOk('menus_sitemenus_read', ['id' => $mainmenu['id']]);
		$this->assertSame('mainmenu', $read['menutype']);
	}
}
