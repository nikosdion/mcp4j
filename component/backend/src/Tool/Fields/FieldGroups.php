<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Tool\Fields;

defined('_JEXEC') || die;

use Akeeba\Component\Mcp4j\Administrator\Bridge\AbstractTool;
use Akeeba\Component\Mcp4j\Administrator\Mapper\Fields\FieldContext;
use Akeeba\Component\Mcp4j\Administrator\Mapper\Fields\FieldGroupMapper;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\McpTool;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\Schema;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Exception\ToolException;
use Joomla\CMS\Component\ComponentHelper;

/**
 * MCP tools for Joomla! custom field groups management.
 *
 * Ported from the original `joomla-mcp-php` project's `src/Server/Fields/FieldGroups.php` — the tool
 * *names* keep the original's `fields_groups_*` prefix (not `fieldgroups_*`) even though the underlying
 * resource/asset name is `fieldgroup` — see `.plans/00-conventions.md` §2. Structurally identical to
 * {@see \Akeeba\Component\Mcp4j\Administrator\Tool\Fields\Fields} (same context-guard/ACL shape); see
 * `.plans/12-fields-history-privacy.md` for the full deviations list.
 *
 * @since  0.0.1
 */
class FieldGroups extends AbstractTool
{
	#[McpTool(
		name: 'fields_groups_list',
		description: 'List custom field groups for a given context',
		readOnlyHint: true,
	)]
	public function listFieldGroups(
		#[Schema(description: 'The field group context, e.g. "com_content.article"')]
		string $context,
		#[Schema(description: 'Search filter for field group titles')]
		?string $filterSearch = null,
		#[Schema(description: 'Filter by field group state: 0=unpublished, 1=published')]
		?int $filterState = null,
		#[Schema(description: 'Maximum number of items to return per page', minimum: 1)]
		?int $pageLimit = null,
		#[Schema(description: 'Starting record offset for pagination (0-based)', minimum: 0)]
		?int $pageOffset = null
	): array
	{
		$component = $this->resolveComponent($context);

		$this->requireAuthorised('core.manage', $component);

		$this->getApplication()->getInput()->set('context', $context);

		/** @var \Joomla\Component\Fields\Administrator\Model\GroupsModel $model */
		$model = $this->createModel('com_fields', 'Groups');

		return $this->listItems(
			$model,
			FieldGroupMapper::listState($context, $filterSearch, $filterState),
			$pageLimit,
			$pageOffset,
			FieldGroupMapper::LIST_FIELDS,
			FieldGroupMapper::INT_FIELDS,
			FieldGroupMapper::JSON_FIELDS
		);
	}

	#[McpTool(
		name: 'fields_groups_read',
		description: 'Retrieve a specific custom field group',
		readOnlyHint: true,
	)]
	public function readFieldGroup(
		#[Schema(description: 'The field group context, e.g. "com_content.article"')]
		string $context,
		#[Schema(description: 'The ID of the field group to retrieve')]
		int $id
	): array
	{
		$component = $this->resolveComponent($context);

		$this->requireAuthorised('core.manage', $component);

		$this->getApplication()->getInput()->set('context', $context);

		/** @var \Joomla\Component\Fields\Administrator\Model\GroupModel $model */
		$model = $this->createModel('com_fields', 'Group');

		$item = $this->loadItem($model, $id, 'field group');

		$this->assertContextMatches($item, $context, $id);

		return $this->shape($item, FieldGroupMapper::ITEM_FIELDS, FieldGroupMapper::INT_FIELDS, FieldGroupMapper::JSON_FIELDS);
	}

	#[McpTool(
		name: 'fields_groups_create',
		description: 'Create a new custom field group'
	)]
	public function createFieldGroup(
		#[Schema(description: 'The field group context, e.g. "com_content.article"')]
		string $context,
		#[Schema(description: 'Field group title')]
		string $title,
		#[Schema(description: 'Field group description')]
		?string $description = null,
		#[Schema(description: 'Field group state: 0=unpublished, 1=published')]
		int $state = 1,
		#[Schema(description: 'Access level ID (default 1)')]
		?int $access = null,
		#[Schema(description: 'Language code or "*" for all languages')]
		string $language = '*',
		#[Schema(description: 'Optional note for the field group')]
		?string $note = null,
		#[Schema(description: 'Field group parameters as a JSON string or object')]
		array|string|null $params = null
	): array
	{
		$component = $this->resolveComponent($context);

		$this->requireAuthorised('core.manage', $component);
		$this->requireAuthorised('core.create', $component);

		$this->getApplication()->getInput()->set('context', $context);

		/** @var \Joomla\Component\Fields\Administrator\Model\GroupModel $model */
		$model = $this->createModel('com_fields', 'Group');
		$model->setState('filter.context', $context);

		try
		{
			$data = FieldGroupMapper::createData($context, $title, $description, $state, $access, $language, $note, $params);
		}
		catch (\InvalidArgumentException $e)
		{
			throw new ToolException($e->getMessage());
		}

		$newId = $this->saveItem($model, $data, null, 'field group');

		return $this->readFresh($context, $newId);
	}

	#[McpTool(
		name: 'fields_groups_update',
		description: 'Update an existing custom field group',
		idempotentHint: true,
	)]
	public function updateFieldGroup(
		#[Schema(description: 'The field group context, e.g. "com_content.article"')]
		string $context,
		#[Schema(description: 'The ID of the field group to update')]
		int $id,
		#[Schema(description: 'Field group title')]
		?string $title = null,
		#[Schema(description: 'Field group description')]
		?string $description = null,
		#[Schema(description: 'Field group state: 0=unpublished, 1=published')]
		?int $state = null,
		#[Schema(description: 'Access level ID (default 1)')]
		?int $access = null,
		#[Schema(description: 'Language code or "*" for all languages')]
		?string $language = null,
		#[Schema(description: 'Optional note for the field group')]
		?string $note = null,
		#[Schema(description: 'Field group parameters as a JSON string or object')]
		array|string|null $params = null
	): array
	{
		$component = $this->resolveComponent($context);

		$this->requireAuthorised('core.manage', $component);

		$this->getApplication()->getInput()->set('context', $context);

		/** @var \Joomla\Component\Fields\Administrator\Model\GroupModel $model */
		$model = $this->createModel('com_fields', 'Group');

		$current = $this->loadItem($model, $id, 'field group');

		$this->assertContextMatches($current, $context, $id);

		$asset   = $component . '.fieldgroup.' . $id;
		$ownerId = isset($current['created_by']) ? (int) $current['created_by'] : null;

		$this->requireEditAllowed($asset, $ownerId);

		if ($state !== null)
		{
			$this->requireAuthorised('core.edit.state', $asset);
		}

		try
		{
			$data = FieldGroupMapper::updateData($title, $description, $state, $access, $language, $note, $params);
		}
		catch (\InvalidArgumentException $e)
		{
			throw new ToolException($e->getMessage());
		}

		// A field group's context is never changed on update — put the stored context back onto the data.
		$data['context'] = $context;

		$this->saveItem($model, $data, $id, 'field group');

		return $this->readFresh($context, $id);
	}

	#[McpTool(
		name: 'fields_groups_delete',
		description: 'Delete a custom field group',
		destructiveHint: true,
	)]
	public function deleteFieldGroup(
		#[Schema(description: 'The field group context, e.g. "com_content.article"')]
		string $context,
		#[Schema(description: 'The ID of the field group to delete')]
		int $id
	): array
	{
		$component = $this->resolveComponent($context);

		$this->requireAuthorised('core.manage', $component);
		$this->requireAuthorised('core.delete', $component);

		$this->getApplication()->getInput()->set('context', $context);

		/** @var \Joomla\Component\Fields\Administrator\Model\GroupModel $model */
		$model = $this->createModel('com_fields', 'Group');

		$current = $this->loadItem($model, $id, 'field group');

		$this->assertContextMatches($current, $context, $id);

		// GroupModel::canDelete() requires state == -2, so trash first unless it already is.
		if ((int) ($current['state'] ?? 0) !== -2)
		{
			/** @var \Joomla\Component\Fields\Administrator\Model\GroupModel $trashModel */
			$trashModel = $this->createModel('com_fields', 'Group');

			$this->changeState($trashModel, $id, -2, 'field group');
		}

		/** @var \Joomla\Component\Fields\Administrator\Model\GroupModel $deleteModel */
		$deleteModel = $this->createModel('com_fields', 'Group');

		$this->deleteItem($deleteModel, $id, 'field group');

		return ['deleted' => true, 'id' => $id];
	}

	/**
	 * @throws  ToolException  `$context` is malformed, or its component isn't installed/enabled.
	 */
	private function resolveComponent(string $context): string
	{
		try
		{
			$parsed = FieldContext::parse($context);
		}
		catch (\InvalidArgumentException $e)
		{
			throw new ToolException($e->getMessage());
		}

		if (!ComponentHelper::isEnabled($parsed['component']))
		{
			throw new ToolException(sprintf('Not found: component %s', $parsed['component']));
		}

		return $parsed['component'];
	}

	/**
	 * @param   array<string, mixed>  $item
	 *
	 * @throws  ToolException
	 */
	private function assertContextMatches(array $item, string $context, int $id): void
	{
		if (($item['context'] ?? null) !== $context)
		{
			throw new ToolException(sprintf('Not found: field group #%d', $id));
		}
	}

	private function readFresh(string $context, int $id): array
	{
		/** @var \Joomla\Component\Fields\Administrator\Model\GroupModel $model */
		$model = $this->createModel('com_fields', 'Group');

		$item = $this->loadItem($model, $id, 'field group');

		$this->assertContextMatches($item, $context, $id);

		return $this->shape($item, FieldGroupMapper::ITEM_FIELDS, FieldGroupMapper::INT_FIELDS, FieldGroupMapper::JSON_FIELDS);
	}
}
