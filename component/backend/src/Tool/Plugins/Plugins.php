<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Tool\Plugins;

defined('_JEXEC') || die;

use Akeeba\Component\Mcp4j\Administrator\Bridge\AbstractTool;
use Akeeba\Component\Mcp4j\Administrator\Mapper\Config\SecretRedactor;
use Akeeba\Component\Mcp4j\Administrator\Mapper\Plugins\PluginMapper;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\McpTool;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\Schema;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Exception\ToolException;

/**
 * MCP tools for Joomla! plugin management.
 *
 * Ported from the original `joomla-mcp-php` project's `src/Server/Plugins/Plugins.php` — method names,
 * tool names/descriptions, parameters and annotations are copied verbatim from there (see
 * `.plans/00-conventions.md` §2); only the method *bodies* differ. See the final report for deviations
 * (filters that now actually work, `params` now actually applied on update, and the plugin lockout guard).
 *
 * @since  0.0.1
 */
class Plugins extends AbstractTool
{
	private const OPTION = 'com_plugins';

	#[McpTool(
		name: 'plugins_list',
		description: 'List existing plugins',
		readOnlyHint: true,
	)]
	public function listPlugins(
		#[Schema(description: 'Search plugins by name')]
		?string $filterSearch = null,
		#[Schema(description: 'Filter by enabled state: 0=disabled, 1=enabled', enum: [0, 1])]
		?int $filterState = null,
		#[Schema(description: 'Filter by plugin group (folder)')]
		?string $filterFolder = null,
		#[Schema(description: 'Filter by plugin element name')]
		?string $filterElement = null,
		#[Schema(description: 'Maximum number of items to return per page', minimum: 1)]
		?int $pageLimit = null,
		#[Schema(description: 'Starting record offset for pagination (0-based)', minimum: 0)]
		?int $pageOffset = null
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);

		/** @var \Joomla\Component\Plugins\Administrator\Model\PluginsModel $model */
		$model = $this->createModel(self::OPTION, 'Plugins');

		$result = $this->listItems(
			$model,
			PluginMapper::listState($filterSearch, $filterState, $filterFolder, $filterElement),
			$pageLimit,
			$pageOffset,
			PluginMapper::RAW_LIST_FIELDS,
			PluginMapper::RAW_INT_FIELDS
		);

		$result['items'] = array_map([PluginMapper::class, 'renameId'], $result['items']);

		return $result;
	}

	#[McpTool(
		name: 'plugins_read',
		description: 'Retrieve the information of the specified plugin',
		readOnlyHint: true,
	)]
	public function readPlugin(
		#[Schema(description: 'The ID of the plugin to retrieve')]
		int $id
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);

		/** @var \Joomla\Component\Plugins\Administrator\Model\PluginModel $model */
		$model = $this->createModel(self::OPTION, 'Plugin');

		return $this->shapedItem($model, $id);
	}

	#[McpTool(
		name: 'plugins_update',
		description: 'Update an existing plugin',
		idempotentHint: true,
	)]
	public function updatePlugin(
		#[Schema(description: 'The ID of the plugin to update')]
		int $id,
		#[Schema(description: 'Enable or disable the plugin: 0=disabled, 1=enabled', enum: [0, 1])]
		?int $enabled = null,
		#[Schema(description: 'The viewing access level for the plugin')]
		?int $access = null,
		#[Schema(description: 'The ordering of the plugin within its group')]
		?int $ordering = null,
		#[Schema(description: 'Plugin parameters as a JSON string or object')]
		array|string|null $params = null
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);

		/** @var \Joomla\Component\Plugins\Administrator\Model\PluginModel $model */
		$model = $this->createModel(self::OPTION, 'Plugin');

		$current = $this->loadItem($model, $id, 'plugin');

		$this->requireAuthorised('core.edit', self::OPTION);

		if ($enabled !== null)
		{
			$this->requireAuthorised('core.edit.state', self::OPTION);
		}

		try
		{
			$data = PluginMapper::updateData($enabled, $access, $ordering, $params);
		}
		catch (\InvalidArgumentException $e)
		{
			throw new ToolException($e->getMessage());
		}

		$folder  = (string) ($current['folder'] ?? '');
		$element = (string) ($current['element'] ?? '');

		if (PluginMapper::isLockoutDisable($folder, $element, $data))
		{
			throw new ToolException(sprintf(
				'Refusing to disable %s/%s: it would disconnect this MCP session.',
				$folder,
				$element
			));
		}

		// PluginModel::getForm() needs folder/element in the data it's given to locate the plugin's own
		// params XML — see `.plans/10-config-plugins-installer-languages.md`'s reading list.
		$data['folder']  = $folder;
		$data['element'] = $element;

		$this->saveItem($model, $data, $id, 'plugin');

		/** @var \Joomla\Component\Plugins\Administrator\Model\PluginModel $freshModel */
		$freshModel = $this->createModel(self::OPTION, 'Plugin');

		return $this->shapedItem($freshModel, $id);
	}

	/**
	 * @param   \Joomla\CMS\MVC\Model\AdminModel  $model
	 */
	private function shapedItem($model, int $id): array
	{
		$item = $this->loadItem($model, $id, 'plugin');

		$item['params'] = SecretRedactor::redactByPattern(is_array($item['params'] ?? null) ? $item['params'] : []);

		// PluginModel::getItem() attaches the plugin's own XML manifest as a SimpleXMLElement, which
		// ItemNormalizer already turns into null — unset it explicitly regardless so it never lingers in
		// the shaped item.
		unset($item['xml']);

		return $this->shape(
			PluginMapper::renameId($item),
			PluginMapper::ITEM_FIELDS,
			PluginMapper::INT_FIELDS,
			PluginMapper::JSON_FIELDS
		);
	}
}
