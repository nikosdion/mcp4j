<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Integration\Tests\Menus;

/**
 * End-to-end coverage for the five `menus_adminmenus_*` tools
 * ({@see \Akeeba\Component\Mcp4j\Administrator\Tool\Menus\AdminMenus}).
 */
class AdminMenusTest extends AbstractMenusToolCase
{
	protected function prefix(): string
	{
		return 'menus_adminmenus';
	}

	protected function otherPrefix(): string
	{
		return 'menus_sitemenus';
	}

	protected function expectedClientId(): int
	{
		return 1;
	}
}
