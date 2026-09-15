<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Tool\Content;

defined('_JEXEC') || die;

use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\McpTool;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\Schema;
use Akeeba\Component\Mcp4j\Administrator\Shared\AbstractCategoriesTool;

/**
 * MCP tools for Joomla! content (`com_content`) categories management.
 *
 * Ported from the original `joomla-mcp-php` project's `src/Server/Content/Categories.php` — method
 * names, tool names/descriptions, parameters and annotations are copied verbatim from there (see
 * `.plans/00-conventions.md` §2), except `listCategories()`'s `filterSearch` parameter, which the
 * original typed `?int` (a bug carried over from a copy/paste — every sibling categories class types it
 * `?string`, and a text search cannot be sent as an int); see `.plans/04-content.md` Deviations item 1.
 *
 * All behaviour lives in {@see AbstractCategoriesTool}, shared with the (future) Banners/Contact/
 * Newsfeeds categories classes — this class only supplies {@see self::getExtension()} and the public
 * tool surface.
 *
 * @since  0.0.1
 */
class Categories extends AbstractCategoriesTool
{
	protected function getExtension(): string
	{
		return 'com_content';
	}

	#[McpTool(
		name: 'content_categories_list',
		description: 'List existing content categories',
		readOnlyHint: true,
	)]
	public function listCategories(
		#[Schema(description: 'Search filter for the category title')]
		?string $filterSearch = null,
		#[Schema(
			description: 'Filter by publish state: 0=unpublished, 1=published, 2=archived, -2=trashed',
			enum: [null, 0, 1, 2, -2]
		)]
		?int $filterState = null,
		#[Schema(description: 'Filter by language code, or "*" for all languages', pattern: '^(\*|[a-z]{2}(-[A-Z]{2})?)$')]
		?string $filterLanguage = null,
		#[Schema(description: 'Filter by category nesting level', minimum: 1)]
		?int $filterLevel = null,
		#[Schema(description: 'Maximum number of items to return per page', minimum: 1)]
		?int $pageLimit = null,
		#[Schema(description: 'Starting record offset for pagination (0-based)', minimum: 0)]
		?int $pageOffset = null
	): array
	{
		return $this->doListCategories($filterSearch, $filterState, $filterLanguage, $filterLevel, $pageLimit, $pageOffset);
	}

	#[McpTool(
		name: 'content_categories_read',
		description: 'Retrieve the information of the specified content category',
		readOnlyHint: true,
	)]
	public function readCategory(
		#[Schema(description: 'The ID of the category to retrieve')]
		int $id
	): array
	{
		return $this->doReadCategory($id);
	}

	#[McpTool(
		name: 'content_categories_create',
		description: 'Create a new content category'
	)]
	public function createCategory(
		#[Schema(description: 'Category title', minLength: 1, maxLength: 255)]
		string $title,
		#[Schema(description: 'URL slug for the category', pattern: '^[a-z0-9-_]+$')]
		?string $alias = null,
		#[Schema(description: 'Parent category ID, 0 for root')]
		?int $parentId = 0,
		#[Schema(
			description: 'Publish state: 0=unpublished, 1=published, 2=archived, -2=trashed',
			enum: [0, 1, 2, -2]
		)]
		int $published = 1,
		#[Schema(description: 'The Joomla! viewing access level for the category')]
		?int $access = null,
		#[Schema(description: 'Language code for the category, or "*" for all languages', pattern: '^(\*|[a-z]{2}(-[A-Z]{2})?)$')]
		string $language = '*',
		#[Schema(description: 'Category description')]
		?string $description = null,
		#[Schema(description: 'An array of tag IDs to associate with the category', items: ['type' => 'integer'], minItems: 0, uniqueItems: true)]
		?array $tags = null,
		#[Schema(description: 'Optional meta description')]
		?string $metadesc = null,
		#[Schema(description: 'Optional meta keywords, separated by commas')]
		?string $metakey = null,
		#[Schema(description: 'Optional note for the category')]
		?string $note = null
	): array
	{
		return $this->doCreateCategory($title, $alias, $parentId, $published, $access, $language, $description, $tags, $metadesc, $metakey, $note);
	}

	#[McpTool(
		name: 'content_categories_update',
		description: 'Update an existing content category',
		idempotentHint: true,
	)]
	public function updateCategory(
		#[Schema(description: 'The ID of the category to modify')]
		int $id,
		#[Schema(description: 'Category title', minLength: 1, maxLength: 255)]
		?string $title = null,
		#[Schema(description: 'URL slug for the category', pattern: '^[a-z0-9-_]+$')]
		?string $alias = null,
		#[Schema(description: 'Parent category ID, 0 for root')]
		?int $parentId = null,
		#[Schema(
			description: 'Publish state: null=no change, 0=unpublished, 1=published, 2=archived, -2=trashed',
			enum: [null, 0, 1, 2, -2]
		)]
		?int $published = null,
		#[Schema(description: 'The Joomla! viewing access level for the category')]
		?int $access = null,
		#[Schema(description: 'Language code for the category, or "*" for all languages', pattern: '^(\*|[a-z]{2}(-[A-Z]{2})?)$')]
		?string $language = null,
		#[Schema(description: 'Category description')]
		?string $description = null,
		#[Schema(description: 'An array of tag IDs to associate with the category', items: ['type' => 'integer'], minItems: 0, uniqueItems: true)]
		?array $tags = null,
		#[Schema(description: 'Optional meta description')]
		?string $metadesc = null,
		#[Schema(description: 'Optional meta keywords, separated by commas')]
		?string $metakey = null,
		#[Schema(description: 'Optional note for the category')]
		?string $note = null
	): array
	{
		return $this->doUpdateCategory($id, $title, $alias, $parentId, $published, $access, $language, $description, $tags, $metadesc, $metakey, $note);
	}

	#[McpTool(
		name: 'content_categories_trash',
		description: 'Moves a content category to the trash by setting its state to -2',
		destructiveHint: true,
	)]
	public function trashCategory(
		#[Schema(description: 'The ID of the category to trash')]
		int $id
	): array
	{
		return $this->doTrashCategory($id);
	}

	#[McpTool(
		name: 'content_categories_delete',
		description: 'Permanently deletes a content category. Automatically trashes it first if needed.',
		destructiveHint: true,
	)]
	public function deleteCategory(
		#[Schema(description: 'The ID of the category to delete')]
		int $id
	): array
	{
		return $this->doDeleteCategory($id);
	}
}
