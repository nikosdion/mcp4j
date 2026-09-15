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
use Akeeba\Component\Mcp4j\Administrator\Shared\Menus\AbstractMenuItemsTool;

/**
 * MCP tools for Joomla! administrator menu item management (`#__menu` where `client_id = 1`).
 *
 * Ported from the original `joomla-mcp-php` project's `src/Server/Menus/AdminMenuItems.php` — identical to
 * {@see \Akeeba\Component\Mcp4j\Administrator\Tool\Menus\MenuItems} except for tool names/descriptions and
 * the client id (including the same required-before-optional parameter reordering); see that class and
 * `.plans/06-menus.md` for the shared implementation and deviations.
 *
 * @since  0.0.1
 */
class AdminMenuItems extends AbstractMenuItemsTool
{
	protected function clientId(): int
	{
		return 1;
	}

	#[McpTool(
		name: 'menus_adminitems_list',
		description: 'List existing administrator menu items',
		readOnlyHint: true,
	)]
	public function listMenuItems(
		#[Schema(description: 'Search menu items by title')]
		?string $filterSearch = null,
		#[Schema(
			description: 'Filter by publish state: 0=unpublished, 1=published, 2=archived, -2=trashed',
			enum: [null, 0, 1, 2, -2]
		)]
		?int $filterState = null,
		#[Schema(description: 'Filter by language code, or "*" for all languages')]
		?string $filterLanguage = null,
		#[Schema(description: 'Filter by menu type identifier')]
		?string $filterMenutype = null,
		#[Schema(description: 'Filter by menu item nesting level')]
		?int $filterLevel = null,
		#[Schema(description: 'Maximum number of items to return per page', minimum: 1)]
		?int $pageLimit = null,
		#[Schema(description: 'Starting record offset for pagination (0-based)', minimum: 0)]
		?int $pageOffset = null
	): array
	{
		return $this->doList($filterSearch, $filterState, $filterLanguage, $filterMenutype, $filterLevel, $pageLimit, $pageOffset);
	}

	#[McpTool(
		name: 'menus_adminitems_read',
		description: 'Retrieve the information of the specified administrator menu item',
		readOnlyHint: true,
	)]
	public function readMenuItem(
		#[Schema(description: 'The ID of the menu item to retrieve')]
		int $id
	): array
	{
		return $this->doRead($id);
	}

	#[McpTool(
		name: 'menus_adminitems_create',
		description: 'Create a new administrator menu item'
	)]
	public function createMenuItem(
		#[Schema(description: 'Menu item title', minLength: 1, maxLength: 255)]
		string $title,
		#[Schema(description: 'Menu type identifier this item belongs to', minLength: 1)]
		string $menutype,
		#[Schema(description: 'Menu item type, e.g. "component"')]
		string $type,
		#[Schema(description: 'The link URL for the menu item')]
		string $link,
		#[Schema(description: 'URL slug for the menu item', pattern: '^[a-z0-9-_]+$')]
		?string $alias = null,
		#[Schema(description: 'Parent menu item ID, 1 for root')]
		?int $parentId = 1,
		#[Schema(
			description: 'Publish state: 0=unpublished, 1=published, 2=archived, -2=trashed',
			enum: [0, 1, 2, -2]
		)]
		int $published = 1,
		#[Schema(description: 'The Joomla! viewing access level for the menu item')]
		?int $access = null,
		#[Schema(description: 'Language code for the menu item, or "*" for all languages')]
		string $language = '*',
		#[Schema(description: 'The component ID associated with this menu item')]
		?int $componentId = null,
		#[Schema(description: 'Optional note for the menu item')]
		?string $note = null,
		#[Schema(description: 'An array of tag IDs to associate with the menu item', items: ['type' => 'integer'], minItems: 0, uniqueItems: true)]
		?array $tags = null,
		#[Schema(description: 'Browser navigation: 0=same window, 1=new window', enum: ['0', '1'])]
		?string $browserNav = null,
		#[Schema(description: 'Whether this menu item is the home page')]
		?bool $home = null,
		#[Schema(description: 'Request parameters for component menu items, e.g. {"id": 5} to target a Single Article or Single Category. Corresponds to the request[] fields Joomla requires when type=component.', additionalProperties: true)]
		?array $request = null
	): array
	{
		return $this->doCreate($title, $alias, $menutype, $type, $link, $parentId, $published, $access, $language, $note, $tags, $browserNav, $home, $request);
	}

	#[McpTool(
		name: 'menus_adminitems_update',
		description: 'Update an existing administrator menu item',
		idempotentHint: true,
	)]
	public function updateMenuItem(
		#[Schema(description: 'The ID of the menu item to modify')]
		int $id,
		#[Schema(description: 'Menu item title', minLength: 1, maxLength: 255)]
		?string $title = null,
		#[Schema(description: 'URL slug for the menu item', pattern: '^[a-z0-9-_]+$')]
		?string $alias = null,
		#[Schema(description: 'Menu type identifier this item belongs to')]
		?string $menutype = null,
		#[Schema(description: 'Menu item type, e.g. "component"')]
		?string $type = null,
		#[Schema(description: 'The link URL for the menu item')]
		?string $link = null,
		#[Schema(description: 'Parent menu item ID, 1 for root')]
		?int $parentId = null,
		#[Schema(
			description: 'Publish state: 0=unpublished, 1=published, 2=archived, -2=trashed',
			enum: [null, 0, 1, 2, -2]
		)]
		?int $published = null,
		#[Schema(description: 'The Joomla! viewing access level for the menu item')]
		?int $access = null,
		#[Schema(description: 'Language code for the menu item, or "*" for all languages')]
		?string $language = null,
		#[Schema(description: 'The component ID associated with this menu item')]
		?int $componentId = null,
		#[Schema(description: 'Optional note for the menu item')]
		?string $note = null,
		#[Schema(description: 'An array of tag IDs to associate with the menu item', items: ['type' => 'integer'], minItems: 0, uniqueItems: true)]
		?array $tags = null,
		#[Schema(description: 'Browser navigation: 0=same window, 1=new window', enum: ['0', '1'])]
		?string $browserNav = null,
		#[Schema(description: 'Whether this menu item is the home page')]
		?bool $home = null,
		#[Schema(description: 'Request parameters for component menu items, e.g. {"id": 5} to target a Single Article or Single Category. Corresponds to the request[] fields Joomla requires when type=component.', additionalProperties: true)]
		?array $request = null
	): array
	{
		return $this->doUpdate($id, $title, $alias, $menutype, $type, $link, $parentId, $published, $access, $language, $note, $tags, $browserNav, $home, $request);
	}

	#[McpTool(
		name: 'menus_adminitems_delete',
		description: 'Permanently deletes an administrator menu item.',
		destructiveHint: true,
	)]
	public function deleteMenuItem(
		#[Schema(description: 'The ID of the menu item to delete')]
		int $id
	): array
	{
		return $this->doDelete($id);
	}

	#[McpTool(
		name: 'menus_adminitems_types',
		description: 'List available menu item types for administrator menus',
		readOnlyHint: true,
	)]
	public function listMenuItemTypes(): array
	{
		return $this->doTypes();
	}
}
