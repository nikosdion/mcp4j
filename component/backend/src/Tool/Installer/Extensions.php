<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Tool\Installer;

defined('_JEXEC') || die;

use Akeeba\Component\Mcp4j\Administrator\Bridge\AbstractTool;
use Akeeba\Component\Mcp4j\Administrator\Mapper\Installer\ExtensionMapper;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\McpTool;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\Schema;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Exception\ToolException;

/**
 * MCP tools for Joomla! extension management.
 *
 * Ported from the original `joomla-mcp-php` project's `src/Server/Installer/Extensions.php` — method
 * name, tool name/description, parameters and annotations are copied verbatim from there (see
 * `.plans/00-conventions.md` §2); only the method *body* differs. `filterType`/`filterState` now actually
 * filter (core's own Web Services API ignored them — see the final report's deviations list), and the
 * output whitelist never includes `params`/`manifest_cache`/`custom_data`/author contact fields, any of
 * which can hold a third-party extension's licence key or personal data.
 *
 * @since  0.0.1
 */
class Extensions extends AbstractTool
{
	private const OPTION = 'com_installer';

	#[McpTool(
		name: 'installer_extensions_list',
		description: 'List installed extensions',
		readOnlyHint: true,
	)]
	public function listExtensions(
		#[Schema(description: 'Search extensions by name')]
		?string $filterSearch = null,
		#[Schema(description: 'Filter by extension type: component, module, plugin, template, library, package')]
		?string $filterType = null,
		#[Schema(description: 'Filter by enabled state: 0=disabled, 1=enabled', enum: [0, 1])]
		?int $filterState = null,
		#[Schema(description: 'Maximum number of items to return per page', minimum: 1)]
		?int $pageLimit = null,
		#[Schema(description: 'Starting record offset for pagination (0-based)', minimum: 0)]
		?int $pageOffset = null
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);

		/** @var \Joomla\Component\Installer\Administrator\Model\ManageModel $model */
		$model = $this->createModel(self::OPTION, 'Manage');

		try
		{
			$state = ExtensionMapper::listState($filterSearch, $filterType, $filterState);
		}
		catch (\InvalidArgumentException $e)
		{
			throw new ToolException($e->getMessage());
		}

		$result = $this->listItems(
			$model,
			$state,
			$pageLimit,
			$pageOffset,
			ExtensionMapper::RAW_LIST_FIELDS,
			ExtensionMapper::RAW_INT_FIELDS
		);

		$result['items'] = array_map([ExtensionMapper::class, 'renameId'], $result['items']);

		return $result;
	}
}
