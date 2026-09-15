<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Tool\Templates;

defined('_JEXEC') || die;

use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\McpTool;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\Schema;
use Akeeba\Component\Mcp4j\Administrator\Shared\Templates\AbstractStylesTool;

/**
 * MCP tools for Joomla! site template style management (`#__template_styles` where `client_id = 0`).
 *
 * Ported from the original `joomla-mcp-php` project's `src/Server/Templates/SiteStyles.php` — method
 * names, tool names/descriptions, parameters and annotations are copied verbatim from there (see
 * `.plans/00-conventions.md` §2); only the method bodies differ, and `*_delete`'s return type changes from
 * the original's `bool` to the framework-wide `['deleted' => true, 'id' => int]` shape. There is no
 * `*_trash` tool — the original never had one either (template styles have no publish state). See
 * `.plans/07-modules-templates.md` Part B deviations for the full list.
 *
 * @since  0.0.1
 */
class SiteStyles extends AbstractStylesTool
{
	protected function clientId(): int
	{
		return 0;
	}

	#[McpTool(
		name: 'templates_sitestyles_list',
		description: 'List existing site template styles',
		readOnlyHint: true,
	)]
	public function listStyles(
		#[Schema(description: 'Search site template styles by name')]
		?string $filterSearch = null,
		#[Schema(description: 'Filter by template name')]
		?string $filterTemplate = null,
		#[Schema(description: 'Maximum number of items to return per page', minimum: 1)]
		?int $pageLimit = null,
		#[Schema(description: 'Starting record offset for pagination (0-based)', minimum: 0)]
		?int $pageOffset = null
	): array
	{
		return $this->doList($filterSearch, $filterTemplate, $pageLimit, $pageOffset);
	}

	#[McpTool(
		name: 'templates_sitestyles_read',
		description: 'Retrieve the information of the specified site template style',
		readOnlyHint: true,
	)]
	public function readStyle(
		#[Schema(description: 'The ID of the site template style to retrieve')]
		int $id
	): array
	{
		return $this->doRead($id);
	}

	#[McpTool(
		name: 'templates_sitestyles_create',
		description: 'Create a new site template style'
	)]
	public function createStyle(
		#[Schema(description: 'The title of the template style')]
		string $title,
		#[Schema(description: 'The template name')]
		string $template,
		#[Schema(description: 'Whether this is the default site template style')]
		?bool $home = false,
		#[Schema(description: 'Template style parameters as a JSON string or object')]
		array|string|null $params = null
	): array
	{
		return $this->doCreate($title, $template, $home, $params);
	}

	#[McpTool(
		name: 'templates_sitestyles_update',
		description: 'Update an existing site template style',
		idempotentHint: true,
	)]
	public function updateStyle(
		#[Schema(description: 'The ID of the site template style to update')]
		int $id,
		#[Schema(description: 'The title of the template style')]
		?string $title = null,
		#[Schema(description: 'The template name')]
		?string $template = null,
		#[Schema(description: 'Whether this is the default site template style')]
		?bool $home = null,
		#[Schema(description: 'Template style parameters as a JSON string or object')]
		array|string|null $params = null
	): array
	{
		return $this->doUpdate($id, $title, $template, $home, $params);
	}

	#[McpTool(
		name: 'templates_sitestyles_delete',
		description: 'Delete a site template style',
		destructiveHint: true,
	)]
	public function deleteStyle(
		#[Schema(description: 'The ID of the site template style to delete')]
		int $id
	): array
	{
		return $this->doDelete($id);
	}
}
