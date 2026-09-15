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
use Akeeba\Component\Mcp4j\Administrator\Mapper\Fields\FieldMapper;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\McpTool;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\Schema;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Exception\ToolException;
use Joomla\CMS\Component\ComponentHelper;

/**
 * MCP tools for Joomla! custom fields management.
 *
 * Ported from the original `joomla-mcp-php` project's `src/Server/Fields/Fields.php` — method names, tool
 * names/descriptions, parameters and annotations are copied verbatim from there (see
 * `.plans/00-conventions.md` §2; parameters `group_id`/`default_value` keep the original's snake_case
 * names), only the method *bodies* differ (Joomla model calls instead of HTTP/JSON:API calls), and
 * `fields_delete`'s return type changes from the original's `bool` to the framework-wide
 * `['deleted' => true, 'id' => int]` shape — see `.plans/12-fields-history-privacy.md` for the full
 * deviations list.
 *
 * Every method resolves and validates the `context` parameter first (see {@see self::resolveComponent()}),
 * because `com_fields`' own models derive both the ACL asset *and* the type alias used to load/save
 * records from it, and `ignore_request => true` means nothing populates it for us — see
 * `.plans/00-conventions.md` §5.4/§5.5.
 *
 * @since  0.0.1
 */
class Fields extends AbstractTool
{
	#[McpTool(
		name: 'fields_list',
		description: 'List custom fields for a given context',
		readOnlyHint: true,
	)]
	public function listFields(
		#[Schema(description: 'The field context, e.g. "com_content.article"')]
		string $context,
		#[Schema(description: 'Search filter for field titles')]
		?string $filterSearch = null,
		#[Schema(description: 'Filter by field state: 0=unpublished, 1=published')]
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

		/** @var \Joomla\Component\Fields\Administrator\Model\FieldsModel $model */
		$model = $this->createModel('com_fields', 'Fields');

		return $this->listItems(
			$model,
			FieldMapper::listState($context, $filterSearch, $filterState),
			$pageLimit,
			$pageOffset,
			FieldMapper::LIST_FIELDS,
			FieldMapper::INT_FIELDS,
			FieldMapper::JSON_FIELDS
		);
	}

	#[McpTool(
		name: 'fields_read',
		description: 'Retrieve a specific custom field',
		readOnlyHint: true,
	)]
	public function readField(
		#[Schema(description: 'The field context, e.g. "com_content.article"')]
		string $context,
		#[Schema(description: 'The ID of the field to retrieve')]
		int $id
	): array
	{
		$component = $this->resolveComponent($context);

		$this->requireAuthorised('core.manage', $component);

		$this->getApplication()->getInput()->set('context', $context);

		/** @var \Joomla\Component\Fields\Administrator\Model\FieldModel $model */
		$model = $this->createModel('com_fields', 'Field');

		$item = $this->loadItem($model, $id, 'field');

		$this->assertContextMatches($item, $context, $id);

		return $this->shape($item, FieldMapper::ITEM_FIELDS, FieldMapper::INT_FIELDS, FieldMapper::JSON_FIELDS);
	}

	#[McpTool(
		name: 'fields_create',
		description: 'Create a new custom field'
	)]
	public function createField(
		#[Schema(description: 'The field context, e.g. "com_content.article"')]
		string $context,
		#[Schema(description: 'Field title')]
		string $title,
		#[Schema(description: 'Field type: text, textarea, list, etc.')]
		string $type,
		#[Schema(description: 'Field name/alias')]
		?string $name = null,
		#[Schema(description: 'Field label')]
		?string $label = null,
		#[Schema(description: 'Field description')]
		?string $description = null,
		#[Schema(description: 'Field state: 0=unpublished, 1=published')]
		int $state = 1,
		#[Schema(description: 'Access level ID (default 1)')]
		?int $access = null,
		#[Schema(description: 'Field group ID')]
		?int $group_id = null,
		#[Schema(description: 'Language code or "*" for all languages')]
		string $language = '*',
		#[Schema(description: 'Default value for the field')]
		?string $default_value = null,
		#[Schema(description: 'Field parameters as a JSON string or object')]
		array|string|null $params = null,
		#[Schema(description: 'Field-specific parameters as a JSON string or object')]
		array|string|null $fieldparams = null
	): array
	{
		$component = $this->resolveComponent($context);

		$this->requireAuthorised('core.manage', $component);
		$this->requireAuthorised('core.create', $component);

		$this->getApplication()->getInput()->set('context', $context);

		/** @var \Joomla\Component\Fields\Administrator\Model\FieldModel $model */
		$model = $this->createModel('com_fields', 'Field');

		// Deliberately do NOT setState('field.context', ...) here: FieldModel::getForm() only derives
		// state('field.component')/state('field.section') (read by FieldModel::preprocessForm() via
		// bootComponent($component)) from its own "$data['context'] fallback" branch, which is skipped
		// entirely once state('field.context') is already non-empty. Pre-setting it (as an earlier draft
		// of this method did, following .plans/12-fields-history-privacy.md's pseudocode literally) left
		// field.component/field.section unset — since ignore_request => true means nothing else ever
		// would — and bootComponent(null) throws a PHP 8.1+ deprecation ("Passing null to parameter
		// #1 ($string) of type string is deprecated") that gets echoed into (and corrupts) the JSON-RPC
		// response body, exactly the class of bug documented in Mapper/Tags/TagMapper.php's own
		// listState() docblock. createData() below always includes 'context' in $data, which is all
		// FieldModel::getForm() needs to populate every one of those state keys itself.
		$data = $this->createFieldData(
			$context,
			$title,
			$type,
			$name,
			$label,
			$description,
			$state,
			$access,
			$group_id,
			$language,
			$default_value,
			$params,
			$fieldparams
		);

		$this->ensureSessionRegistryIsObject();

		$newId = $this->saveItem($model, $data, null, 'field');

		return $this->readFresh($context, $newId);
	}

	#[McpTool(
		name: 'fields_update',
		description: 'Update an existing custom field',
		idempotentHint: true,
	)]
	public function updateField(
		#[Schema(description: 'The field context, e.g. "com_content.article"')]
		string $context,
		#[Schema(description: 'The ID of the field to update')]
		int $id,
		#[Schema(description: 'Field title')]
		?string $title = null,
		#[Schema(description: 'Field type: text, textarea, list, etc.')]
		?string $type = null,
		#[Schema(description: 'Field name/alias')]
		?string $name = null,
		#[Schema(description: 'Field label')]
		?string $label = null,
		#[Schema(description: 'Field description')]
		?string $description = null,
		#[Schema(description: 'Field state: 0=unpublished, 1=published')]
		?int $state = null,
		#[Schema(description: 'Access level ID (default 1)')]
		?int $access = null,
		#[Schema(description: 'Field group ID')]
		?int $group_id = null,
		#[Schema(description: 'Language code or "*" for all languages')]
		?string $language = null,
		#[Schema(description: 'Default value for the field')]
		?string $default_value = null,
		#[Schema(description: 'Field parameters as a JSON string or object')]
		array|string|null $params = null,
		#[Schema(description: 'Field-specific parameters as a JSON string or object')]
		array|string|null $fieldparams = null
	): array
	{
		$component = $this->resolveComponent($context);

		$this->requireAuthorised('core.manage', $component);

		$this->getApplication()->getInput()->set('context', $context);

		/** @var \Joomla\Component\Fields\Administrator\Model\FieldModel $model */
		$model = $this->createModel('com_fields', 'Field');

		$current = $this->loadItem($model, $id, 'field');

		$this->assertContextMatches($current, $context, $id);

		$asset   = $component . '.field.' . $id;
		$ownerId = isset($current['created_user_id']) ? (int) $current['created_user_id'] : null;

		$this->requireEditAllowed($asset, $ownerId);

		if ($state !== null)
		{
			$this->requireAuthorised('core.edit.state', $asset);
		}

		try
		{
			$data = FieldMapper::updateData($title, $type, $name, $label, $description, $state, $access, $group_id, $language, $default_value, $params, $fieldparams);
		}
		catch (\InvalidArgumentException $e)
		{
			throw new ToolException($e->getMessage());
		}

		// A field's context is never changed on update — put the stored context back onto the data.
		$data['context'] = $context;

		$this->ensureSessionRegistryIsObject();

		$this->saveItem($model, $data, $id, 'field');

		return $this->readFresh($context, $id);
	}

	#[McpTool(
		name: 'fields_delete',
		description: 'Delete a custom field',
		destructiveHint: true,
	)]
	public function deleteField(
		#[Schema(description: 'The field context, e.g. "com_content.article"')]
		string $context,
		#[Schema(description: 'The ID of the field to delete')]
		int $id
	): array
	{
		$component = $this->resolveComponent($context);

		$this->requireAuthorised('core.manage', $component);
		$this->requireAuthorised('core.delete', $component);

		$this->getApplication()->getInput()->set('context', $context);

		/** @var \Joomla\Component\Fields\Administrator\Model\FieldModel $model */
		$model = $this->createModel('com_fields', 'Field');

		$current = $this->loadItem($model, $id, 'field');

		$this->assertContextMatches($current, $context, $id);

		// FieldModel::canDelete() requires state == -2, so trash first unless it already is.
		if ((int) ($current['state'] ?? 0) !== -2)
		{
			/** @var \Joomla\Component\Fields\Administrator\Model\FieldModel $trashModel */
			$trashModel = $this->createModel('com_fields', 'Field');

			$this->changeState($trashModel, $id, -2, 'field');
		}

		/** @var \Joomla\Component\Fields\Administrator\Model\FieldModel $deleteModel */
		$deleteModel = $this->createModel('com_fields', 'Field');

		$this->deleteItem($deleteModel, $id, 'field');

		return ['deleted' => true, 'id' => $id];
	}

	/**
	 * Parses/validates `$context` and checks the target component is installed and enabled — mirrors what
	 * core's own Web Services API view does (`displayItem()`/`displayList()` 404 for a context whose
	 * component isn't available) before any `com_fields` model touches it.
	 *
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
	 * Mirrors core's own Web Services API item view, which 404s when a loaded record's context differs
	 * from the one requested — prevents a user who can manage `com_content` from reading/editing a field
	 * that actually belongs to a `com_users` context, purely because both components happen to share the
	 * `core.manage` permission the caller holds.
	 *
	 * @param   array<string, mixed>  $item
	 *
	 * @throws  ToolException
	 */
	private function assertContextMatches(array $item, string $context, int $id): void
	{
		if (($item['context'] ?? null) !== $context)
		{
			throw new ToolException(sprintf('Not found: field #%d', $id));
		}
	}

	/**
	 * @throws  ToolException  `$params`/`$fieldparams` is a string that isn't valid JSON.
	 */
	private function createFieldData(
		string $context,
		string $title,
		string $type,
		?string $name,
		?string $label,
		?string $description,
		int $state,
		?int $access,
		?int $group_id,
		string $language,
		?string $default_value,
		array|string|null $params,
		array|string|null $fieldparams
	): array {
		try
		{
			return FieldMapper::createData($context, $title, $type, $name, $label, $description, $state, $access, $group_id, $language, $default_value, $params, $fieldparams);
		}
		catch (\InvalidArgumentException $e)
		{
			throw new ToolException($e->getMessage());
		}
	}

	private function readFresh(string $context, int $id): array
	{
		/** @var \Joomla\Component\Fields\Administrator\Model\FieldModel $model */
		$model = $this->createModel('com_fields', 'Field');

		$item = $this->loadItem($model, $id, 'field');

		$this->assertContextMatches($item, $context, $id);

		return $this->shape($item, FieldMapper::ITEM_FIELDS, FieldMapper::INT_FIELDS, FieldMapper::JSON_FIELDS);
	}

	/**
	 * `FieldModel::getForm()` hard-codes `'load_data' => true` in the array it passes to `loadForm()`,
	 * unlike `GroupModel::getForm()` (which honours the `$loadData` argument `AbstractTool::saveItem()`
	 * passes as `false`) — so every `com_fields.Field` model's `getForm()` call unconditionally reaches
	 * `FormBehaviorTrait::loadForm()` -> `FieldModel::loadFormData()` ->
	 * `Factory::getApplication()->getUserState(...)`.
	 *
	 * `getUserState()` (`libraries/src/Application/CMSApplication.php`) reads a `Registry` object out of
	 * the session under the `registry` key and immediately calls `->get()` on it. This MCP session's data
	 * is persisted across requests via
	 * {@see \Akeeba\Component\Mcp4j\Administrator\Support\SessionDataCodec}, which is deliberately
	 * JSON-only — so once a session has been persisted and restored at least once, its `registry` entry
	 * has decayed from a `Registry` object to a plain array, and the next `getUserState()` call anywhere
	 * in Joomla core is a PHP fatal ("Call to a member function get() on array"). This is the exact same
	 * pre-existing session-bridge trade-off already documented and worked around in
	 * `Tool/Config/Config.php::ensureSessionRegistryIsObject()` (plan 10) — copied here rather than
	 * factored out, since `component/backend/src/Support/`/`Bridge/` are out of scope for this plan to
	 * change (see `.plans/00-conventions.md` §8). Call this immediately before any `saveItem()` call that
	 * uses a `com_fields.Field` model.
	 */
	private function ensureSessionRegistryIsObject(): void
	{
		$session  = $this->getApplication()->getSession();
		$registry = $session->get('registry');

		if (is_array($registry))
		{
			$session->set('registry', new \Joomla\Registry\Registry($registry));
		}
	}
}
