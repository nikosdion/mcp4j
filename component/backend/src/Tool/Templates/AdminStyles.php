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
 * MCP tools for Joomla! administrator template style management (`#__template_styles` where
 * `client_id = 1`).
 *
 * Ported from the original `joomla-mcp-php` project's `src/Server/Templates/AdminStyles.php` — identical
 * to {@see \Akeeba\Component\Mcp4j\Administrator\Tool\Templates\SiteStyles} except for tool
 * names/descriptions and the client id; see that class and `.plans/07-modules-templates.md` for the shared
 * implementation and deviations.
 *
 * @since  0.0.1
 */
class AdminStyles extends AbstractStylesTool
{
	protected function clientId(): int
	{
		return 1;
	}

	#[McpTool(
		name: 'templates_adminstyles_list',
		description: 'List existing administrator template styles',
		readOnlyHint: true,
	)]
	public function listStyles(
		#[Schema(description: 'Search administrator template styles by name')]
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
		name: 'templates_adminstyles_read',
		description: 'Retrieve the information of the specified administrator template style',
		readOnlyHint: true,
	)]
	public function readStyle(
		#[Schema(description: 'The ID of the administrator template style to retrieve')]
		int $id
	): array
	{
		return $this->doRead($id);
	}

	#[McpTool(
		name: 'templates_adminstyles_create',
		description: 'Create a new administrator template style'
	)]
	public function createStyle(
		#[Schema(description: 'The title of the template style')]
		string $title,
		#[Schema(description: 'The template name')]
		string $template,
		#[Schema(description: 'Whether this is the default administrator template style')]
		?bool $home = false,
		#[Schema(description: 'Template style parameters as a JSON string or object')]
		array|string|null $params = null
	): array
	{
		return $this->doCreate($title, $template, $home, $params);
	}

	#[McpTool(
		name: 'templates_adminstyles_update',
		description: 'Update an existing administrator template style',
		idempotentHint: true,
	)]
	public function updateStyle(
		#[Schema(description: 'The ID of the administrator template style to update')]
		int $id,
		#[Schema(description: 'The title of the template style')]
		?string $title = null,
		#[Schema(description: 'The template name')]
		?string $template = null,
		#[Schema(description: 'Whether this is the default administrator template style')]
		?bool $home = null,
		#[Schema(description: 'Template style parameters as a JSON string or object')]
		array|string|null $params = null
	): array
	{
		return $this->doUpdate($id, $title, $template, $home, $params);
	}

	#[McpTool(
		name: 'templates_adminstyles_delete',
		description: 'Delete an administrator template style',
		destructiveHint: true,
	)]
	public function deleteStyle(
		#[Schema(description: 'The ID of the administrator template style to delete')]
		int $id
	): array
	{
		return $this->doDelete($id);
	}
}
