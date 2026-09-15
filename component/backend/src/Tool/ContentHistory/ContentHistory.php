<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Tool\ContentHistory;

defined('_JEXEC') || die;

use Akeeba\Component\Mcp4j\Administrator\Bridge\AbstractTool;
use Akeeba\Component\Mcp4j\Administrator\Mapper\ContentHistory\HistoryResourceMapper;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\McpTool;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\Schema;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Exception\ToolException;
use Joomla\CMS\Access\Exception\NotAllowed;

/**
 * MCP tools for Joomla! content history (`#__history`) management.
 *
 * Ported from the original `joomla-mcp-php` project's `src/Server/ContentHistory/ContentHistory.php` —
 * method names, tool names/descriptions, parameters and annotations are copied verbatim from there (see
 * `.plans/00-conventions.md` §2), only the method *bodies* differ, and `contenthistory_delete`'s return
 * type changes from the original's `bool` to the framework-wide `['deleted' => true, 'id' => int]` shape.
 *
 * The original's `resource`/`itemId`/`versionId` parameters mirror a Web Services API URL
 * (`v1/<resource>/<itemId>/contenthistory[/<versionId>[/keep]]`) that only exists for three resources in
 * core (articles, contacts, banners); calling `com_contenthistory`'s `HistoryModel` directly instead means
 * `resource` also accepts a bare type alias (e.g. `com_content.category`, never exposed by core's own
 * routes at all) — see `.plans/12-fields-history-privacy.md` deviation 2.
 *
 * @since  0.0.1
 */
class ContentHistory extends AbstractTool
{
	#[McpTool(
		name: 'contenthistory_list',
		description: 'List content history versions for a resource item',
		readOnlyHint: true,
	)]
	public function listHistory(
		#[Schema(description: 'The resource type, e.g. "content/articles"')]
		string $resource,
		#[Schema(description: 'The ID of the item to retrieve history for')]
		int $itemId,
		#[Schema(description: 'Maximum number of items to return per page', minimum: 1)]
		?int $pageLimit = null,
		#[Schema(description: 'Starting record offset for pagination (0-based)', minimum: 0)]
		?int $pageOffset = null
	): array
	{
		[$component, $itemKey] = $this->resolveItem($resource, $itemId);

		$this->requireAuthorised('core.manage', $component);

		/** @var \Joomla\Component\Contenthistory\Administrator\Model\HistoryModel $model */
		$model = $this->createModel('com_contenthistory', 'History');

		try
		{
			$result = $this->listItems(
				$model,
				[
					// HistoryModel::getListQuery() concatenates quoteName($ordering) . $direction with NO
					// space — the leading space in ' DESC' is deliberate, not a typo.
					'item_id'        => $itemKey,
					'list.ordering'  => 'h.save_date',
					'list.direction' => ' DESC',
				],
				$pageLimit,
				$pageOffset,
				$this->rawListFields(),
				HistoryResourceMapper::INT_FIELDS,
				HistoryResourceMapper::JSON_FIELDS
			);
		}
		catch (NotAllowed $e)
		{
			// HistoryModel::getItems() throws (rather than returning false) when the current user cannot
			// core.edit the item's asset.
			throw new ToolException(sprintf('Permission denied: core.edit on %s', $itemKey));
		}

		$result['items'] = array_map([HistoryResourceMapper::class, 'renameId'], $result['items']);

		return $result;
	}

	#[McpTool(
		name: 'contenthistory_keep',
		description: 'Toggle the keep flag on a content history version',
		idempotentHint: true,
	)]
	public function keepHistory(
		#[Schema(description: 'The resource type, e.g. "content/articles"')]
		string $resource,
		#[Schema(description: 'The ID of the item')]
		int $itemId,
		#[Schema(description: 'The ID of the content history version')]
		int $versionId
	): array
	{
		[$component, $itemKey] = $this->resolveItem($resource, $itemId);

		$this->requireAuthorised('core.manage', $component);

		/** @var \Joomla\Component\Contenthistory\Administrator\Model\HistoryModel $model */
		$model = $this->createModel('com_contenthistory', 'History');

		$table = $model->getTable();

		if (!$table->load($versionId) || $table->item_id !== $itemKey)
		{
			throw new ToolException(sprintf('Not found: history version #%d', $versionId));
		}

		$pks = [$versionId];

		$ok = $model->keep($pks);

		if (!$ok || $pks === [])
		{
			throw new ToolException($this->modelError($model, sprintf('Permission denied: core.edit on %s', $itemKey)));
		}

		$table = $model->getTable();
		$table->load($versionId);

		return HistoryResourceMapper::renameId(
			$this->shape($table, $this->rawListFields(), HistoryResourceMapper::INT_FIELDS, HistoryResourceMapper::JSON_FIELDS)
		);
	}

	#[McpTool(
		name: 'contenthistory_delete',
		description: 'Delete a content history version',
		destructiveHint: true,
	)]
	public function deleteHistory(
		#[Schema(description: 'The resource type, e.g. "content/articles"')]
		string $resource,
		#[Schema(description: 'The ID of the item')]
		int $itemId,
		#[Schema(description: 'The ID of the content history version to delete')]
		int $versionId
	): array
	{
		[$component, $itemKey] = $this->resolveItem($resource, $itemId);

		$this->requireAuthorised('core.manage', $component);

		/** @var \Joomla\Component\Contenthistory\Administrator\Model\HistoryModel $model */
		$model = $this->createModel('com_contenthistory', 'History');

		$table = $model->getTable();

		if (!$table->load($versionId) || $table->item_id !== $itemKey)
		{
			throw new ToolException(sprintf('Not found: history version #%d', $versionId));
		}

		$keepForever = (int) $table->keep_forever === 1;

		$pks = [$versionId];

		$ok = $model->delete($pks);

		if ($ok && $pks !== [])
		{
			return ['deleted' => true, 'id' => $versionId];
		}

		// HistoryModel::delete() silently prunes (and still returns true for) keep-forever rows without
		// deleting them — $pks ends up empty even though nothing went wrong permission-wise.
		if ($pks === [] && $keepForever)
		{
			throw new ToolException(sprintf(
				'Cannot delete history version #%d: it is marked to be kept forever. Toggle it with contenthistory_keep first.',
				$versionId
			));
		}

		throw new ToolException($this->modelError($model, sprintf('Permission denied: core.delete on %s', $itemKey)));
	}

	/**
	 * @return  array{0: string, 1: string}  `[$component, $itemKey]`.
	 *
	 * @throws  ToolException  `$resource` is neither a known resource shortcut nor a valid type alias.
	 */
	private function resolveItem(string $resource, int $itemId): array
	{
		try
		{
			$alias = HistoryResourceMapper::toTypeAlias($resource);
		}
		catch (\InvalidArgumentException $e)
		{
			throw new ToolException($e->getMessage());
		}

		[$component] = explode('.', $alias);

		return [$component, HistoryResourceMapper::itemId($alias, $itemId)];
	}

	/**
	 * {@see HistoryResourceMapper::LIST_FIELDS} with its leading `'id'` substituted for the raw row's
	 * actual primary key column name, `'version_id'` — see that constant's docblock.
	 *
	 * @return  list<string>
	 */
	private function rawListFields(): array
	{
		$fields    = HistoryResourceMapper::LIST_FIELDS;
		$fields[0] = 'version_id';

		return $fields;
	}
}
