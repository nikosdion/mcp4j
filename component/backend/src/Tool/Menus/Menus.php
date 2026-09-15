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
 * MCP tools for Joomla! site menu management (`#__menu_types` where `client_id = 0`).
 *
 * Ported from the original `joomla-mcp-php` project's `src/Server/Menus/Menus.php` — method names, tool
 * names/descriptions, parameters and annotations are copied verbatim from there (see
 * `.plans/00-conventions.md` §2); only the method bodies differ, and `*_delete`'s return type changes from
 * the original's `bool` to the framework-wide `['deleted' => true, 'id' => int]` shape
 * (`.plans/00-conventions.md` §6) — see the final report for the full list of deviations.
 *
 * @since  0.0.1
 */
class Menus extends AbstractMenusTool
{
	protected function clientId(): int
	{
		return 0;
	}

	#[McpTool(
		name: 'menus_sitemenus_list',
		description: 'List existing site menus',
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
		name: 'menus_sitemenus_read',
		description: 'Retrieve the information of the specified site menu',
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
		name: 'menus_sitemenus_create',
		description: 'Create a new site menu'
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
		name: 'menus_sitemenus_update',
		description: 'Update an existing site menu',
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
		name: 'menus_sitemenus_delete',
		description: 'Permanently deletes a site menu.',
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
