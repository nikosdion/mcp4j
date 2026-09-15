<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Tool\Menus;

defined('_JEXEC') || die;

use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\McpTool;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\Schema;
use Akeeba\Component\Mcp4j\Administrator\Shared\Menus\AbstractMenusTool;

/**
 * MCP tools for Joomla! administrator menu management (`#__menu_types` where `client_id = 1`).
 *
 * Ported from the original `joomla-mcp-php` project's `src/Server/Menus/AdminMenus.php` — identical to
 * {@see \Akeeba\Component\Mcp4j\Administrator\Tool\Menus\Menus} except for tool names/descriptions and the
 * client id; see that class and `.plans/06-menus.md` for the shared implementation and deviations.
 *
 * @since  0.0.1
 */
class AdminMenus extends AbstractMenusTool
{
	protected function clientId(): int
	{
		return 1;
	}

	#[McpTool(
		name: 'menus_adminmenus_list',
		description: 'List existing administrator menus',
		readOnlyHint: true,
	)]
	public function listMenus(
		#[Schema(description: 'Search menus by title')]
		?string $filterSearch = null,
		#[Schema(description: 'Maximum number of items to return per page', minimum: 1)]
		?int $pageLimit = null,
		#[Schema(description: 'Starting record offset for pagination (0-based)', minimum: 0)]
		?int $pageOffset = null
	): array
	{
		return $this->doList($filterSearch, $pageLimit, $pageOffset);
	}

	#[McpTool(
		name: 'menus_adminmenus_read',
		description: 'Retrieve the information of the specified administrator menu',
		readOnlyHint: true,
	)]
	public function readMenu(
		#[Schema(description: 'The ID of the menu to retrieve')]
		int $id
	): array
	{
		return $this->doRead($id);
	}

	#[McpTool(
		name: 'menus_adminmenus_create',
		description: 'Create a new administrator menu'
	)]
	public function createMenu(
		#[Schema(description: 'Menu title', minLength: 1, maxLength: 255)]
		string $title,
		#[Schema(description: 'Unique menu type identifier', minLength: 1, maxLength: 255)]
		string $menutype,
		#[Schema(description: 'Menu description')]
		?string $description = null
	): array
	{
		return $this->doCreate($title, $menutype, $description);
	}

	#[McpTool(
		name: 'menus_adminmenus_update',
		description: 'Update an existing administrator menu',
		idempotentHint: true,
	)]
	public function updateMenu(
		#[Schema(description: 'The ID of the menu to modify')]
		int $id,
		#[Schema(description: 'Menu title', minLength: 1, maxLength: 255)]
		?string $title = null,
		#[Schema(description: 'Unique menu type identifier', minLength: 1, maxLength: 255)]
		?string $menutype = null,
		#[Schema(description: 'Menu description')]
		?string $description = null
	): array
	{
		return $this->doUpdate($id, $title, $menutype, $description);
	}

	#[McpTool(
		name: 'menus_adminmenus_delete',
		description: 'Permanently deletes an administrator menu.',
		destructiveHint: true,
	)]
	public function deleteMenu(
		#[Schema(description: 'The ID of the menu to delete')]
		int $id
	): array
	{
		return $this->doDelete($id);
	}
}
