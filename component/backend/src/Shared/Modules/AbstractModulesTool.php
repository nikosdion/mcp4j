<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Shared\Modules;

defined('_JEXEC') || die;

use Akeeba\Component\Mcp4j\Administrator\Bridge\AbstractTool;
use Akeeba\Component\Mcp4j\Administrator\Bridge\Support\JsonObjectInput;
use Akeeba\Component\Mcp4j\Administrator\Mapper\Modules\ModuleMapper;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Exception\ToolException;
use Joomla\CMS\MVC\Model\AdminModel;
use Joomla\CMS\MVC\Model\ListModel;
use Joomla\Database\ParameterType;

/**
 * Shared implementation for the site/administrator *modules* tool pairs
 * ({@see \Akeeba\Component\Mcp4j\Administrator\Tool\Modules\SiteModules} and
 * {@see \Akeeba\Component\Mcp4j\Administrator\Tool\Modules\AdminModules}) — identical behaviour except for
 * the client id (0 = site, 1 = administrator), which the concrete subclass supplies via
 * {@see self::clientId()}.
 *
 * Ported from the original `joomla-mcp-php` project's `src/Server/Modules/SiteModules.php` and
 * `AdminModules.php` (which differ from each other only in tool names/descriptions/URLs) — see
 * `.plans/07-modules-templates.md` Part A.
 *
 * Unlike {@see \Akeeba\Component\Mcp4j\Administrator\Shared\Menus\AbstractMenusTool}, `ModuleModel` and
 * `ModulesModel`/`SelectModel` extend `AdminModel`/`ListModel` on every supported Joomla version (verified
 * in boot4/boot5/boot6), so this class reuses {@see AbstractTool::loadItem()}/`saveItem()`/`changeState()`/
 * `deleteItem()}` directly rather than re-implementing them.
 *
 * @since  0.0.1
 */
abstract class AbstractModulesTool extends AbstractTool
{
	private const OPTION = 'com_modules';

	/**
	 * 0 = site modules, 1 = administrator modules.
	 */
	abstract protected function clientId(): int;

	protected function resourceLabel(): string
	{
		return $this->clientId() === 0 ? 'site module' : 'administrator module';
	}

	/**
	 * @return  array{items: list<array>, pagination: array{total: int, limit: int, offset: int}}
	 */
	protected function doList(
		?string $filterSearch,
		?int $filterState,
		?string $filterPosition,
		?string $filterModule,
		?int $filterAccess,
		?int $pageLimit,
		?int $pageOffset
	): array {
		$this->requireAuthorised('core.manage', self::OPTION);

		/** @var ListModel $model */
		$model = $this->createModel(self::OPTION, 'Modules');

		return $this->listItems(
			$model,
			ModuleMapper::listState($this->clientId(), $filterSearch, $filterState, $filterPosition, $filterModule, $filterAccess),
			$pageLimit,
			$pageOffset,
			ModuleMapper::LIST_FIELDS,
			ModuleMapper::INT_FIELDS,
			ModuleMapper::JSON_FIELDS
		);
	}

	/**
	 * @return  array<string, mixed>
	 */
	protected function doRead(int $id): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);

		return $this->shape($this->loadOwn($id), ModuleMapper::ITEM_FIELDS, ModuleMapper::INT_FIELDS, ModuleMapper::JSON_FIELDS);
	}

	/**
	 * @return  array<string, mixed>
	 */
	protected function doCreate(
		string $title,
		string $module,
		int $published,
		?int $access,
		?string $position,
		?string $note,
		?int $ordering,
		string $language,
		?string $assignment,
		array|string|null $params
	): array {
		$this->requireAuthorised('core.manage', self::OPTION);
		$this->requireAuthorised('core.create', self::OPTION);

		$decodedParams = JsonObjectInput::decode($params, 'params');

		$this->requireModuleType($module);

		/** @var AdminModel $model */
		$model = $this->createModel(self::OPTION, 'Module');

		try
		{
			$data = ModuleMapper::createData(
				$this->clientId(),
				$title,
				$module,
				$published,
				$access,
				$position,
				$note,
				$ordering,
				$language,
				$assignment,
				$decodedParams
			);
		}
		catch (\InvalidArgumentException $e)
		{
			throw new ToolException($e->getMessage());
		}

		$data = $this->fillParamsDefaults($model, $data);

		$newId = $this->saveItem($model, $data, null, $this->resourceLabel());

		return $this->shape($this->loadOwn($newId), ModuleMapper::ITEM_FIELDS, ModuleMapper::INT_FIELDS, ModuleMapper::JSON_FIELDS);
	}

	/**
	 * @return  array<string, mixed>
	 */
	protected function doUpdate(
		int $id,
		?string $title,
		?string $module,
		?int $published,
		?int $access,
		?string $position,
		?string $note,
		?int $ordering,
		?string $language,
		?string $assignment,
		array|string|null $params
	): array {
		$this->requireAuthorised('core.manage', self::OPTION);
		$this->requireAuthorised('core.edit', self::OPTION . '.module.' . $id);

		$current = $this->loadOwn($id);

		if ($module !== null && $module !== ($current['module'] ?? null))
		{
			throw new ToolException('The module type of an existing module cannot be changed.');
		}

		$decodedParams = JsonObjectInput::decode($params, 'params');

		/** @var AdminModel $model */
		$model = $this->createModel(self::OPTION, 'Module');

		try
		{
			$data = ModuleMapper::updateData(
				$title,
				$published,
				$access,
				$position,
				$note,
				$ordering,
				$language,
				$assignment,
				$decodedParams
			);
		}
		catch (\InvalidArgumentException $e)
		{
			throw new ToolException($e->getMessage());
		}

		$this->saveItem($model, $data, $id, $this->resourceLabel());

		return $this->shape($this->loadOwn($id), ModuleMapper::ITEM_FIELDS, ModuleMapper::INT_FIELDS, ModuleMapper::JSON_FIELDS);
	}

	/**
	 * @return  array<string, mixed>
	 */
	protected function doTrash(int $id): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);
		$this->requireAuthorised('core.edit.state', self::OPTION . '.module.' . $id);

		$this->loadOwn($id);

		/** @var AdminModel $model */
		$model = $this->createModel(self::OPTION, 'Module');

		$this->changeState($model, $id, -2, $this->resourceLabel());

		return $this->shape($this->loadOwn($id), ModuleMapper::ITEM_FIELDS, ModuleMapper::INT_FIELDS, ModuleMapper::JSON_FIELDS);
	}

	/**
	 * @return  array{deleted: true, id: int}
	 */
	protected function doDelete(int $id): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);
		$this->requireAuthorised('core.delete', self::OPTION . '.module.' . $id);

		$current = $this->loadOwn($id);

		// AdminModel::publish() prunes (and no-ops on) a record already at the target state — see
		// AbstractTool::changeState()'s docblock — so only trash when it isn't already trashed, matching
		// this tool's own contract ("automatically trashes it first if needed").
		if ((int) ($current['published'] ?? 0) !== -2)
		{
			/** @var AdminModel $trashModel */
			$trashModel = $this->createModel(self::OPTION, 'Module');

			$this->changeState($trashModel, $id, -2, $this->resourceLabel());
		}

		/** @var AdminModel $deleteModel */
		$deleteModel = $this->createModel(self::OPTION, 'Module');

		$this->deleteItem($deleteModel, $id, $this->resourceLabel());

		return ['deleted' => true, 'id' => $id];
	}

	/**
	 * `SelectModel` reads `list.limit`/`list.start`/`list.ordering`/`list.direction` from `populateState()`,
	 * which never runs for us — set them explicitly, exactly as core's own `populateState()` would (see
	 * `.plans/07-modules-templates.md` Part A "Key facts" §7).
	 *
	 * @return  array{items: list<array>, pagination: array{total: int, limit: int, offset: int}}
	 */
	protected function doTypes(): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);

		/** @var ListModel $model */
		$model = $this->createModel(self::OPTION, 'Select');
		$model->setState('client_id', $this->clientId());
		$model->setState('list.limit', 0);
		$model->setState('list.start', 0);
		$model->setState('list.ordering', 'a.name');
		$model->setState('list.direction', 'ASC');

		$items = $model->getItems();

		if ($items === false)
		{
			throw new ToolException($this->modelError($model, 'Cannot list module types'));
		}

		$shaped = [];

		foreach ($items as $item)
		{
			$shaped[] = [
				'id'     => (int) $item->extension_id,
				'name'   => (string) $item->name,
				'module' => (string) $item->module,
				'desc'   => (string) $item->desc,
			];
		}

		return [
			'items'      => $shaped,
			'pagination' => [
				'total'  => count($shaped),
				'limit'  => 0,
				'offset' => 0,
			],
		];
	}

	/**
	 * Fills in every declared `params.*` field this create call didn't explicitly supply with that field's
	 * own XML `default` — exactly what a real backend "New Module" browser submission would send (every
	 * rendered `<input>`/`<select>` posts *something*, even left untouched).
	 *
	 * Without this, `$data['params']` — however it is built — never survives `Form::filter()` unless it
	 * already contains at least one key matching a declared subfield: `Form::filter()` (and
	 * `AbstractTool::saveItem()`, which calls it) only ever populates the *top-level* `params` key in its
	 * output for keys that satisfy `$input->exists('params.<name>')`, for each `<field>` name it finds by
	 * iterating the form XML — it never falls back to defaults for a key that's simply missing from the
	 * input. A module type with a `<config>` (or the always-loaded `forms/advanced.xml` "chrome" options —
	 * every module gets those, regardless of type) but no matching `params` keys in our data then ends up
	 * with `params` entirely absent from the validated/filtered save data, `ModuleTable::bind()` never
	 * touches `$this->params`, and `DatabaseDriver::insertObject()` — which silently skips any *null*-valued
	 * property — omits the column outright: a MySQL "Field 'params' doesn't have a default value" error
	 * (`#__modules.params` is `text NOT NULL`, no column default). Found by this plan's own E2E suite; see
	 * the final report.
	 *
	 * Deliberately fills gaps with each field's raw XML `default` string (via `Form::getFieldAttribute()`)
	 * rather than `FormField::$value` (the bound, display-oriented value `Form::getGroup()` would otherwise
	 * hand back) — a field's *display* value is not always the same representation `Form::filter()` would
	 * later save (e.g. a `type="radio"` switcher field's `value` can render as a genuine PHP `bool` rather
	 * than the string its `filter` attribute would actually produce). Every value this method adds, like
	 * every value the caller supplied, is left as a raw, unfiltered scalar precisely so the *real*
	 * `Form::filter()`/`validate()` pass inside `AbstractTool::saveItem()` — not this method — is what
	 * ultimately decides each field's saved type/representation, exactly as it would for a value a real
	 * browser submission had sent (found the hard way in the sibling `AbstractStylesTool` — see that class's
	 * identical fix and the final report).
	 *
	 * @param   array<string, mixed>  $data  Must already carry whatever `client_id`/`module` the target
	 *                                       form's `getForm()` needs to resolve the right XML (see
	 *                                       `ModuleModel::getForm()`).
	 *
	 * @return  array<string, mixed>
	 */
	private function fillParamsDefaults(AdminModel $model, array $data): array
	{
		$form = $model->getForm($data, false);

		if (!$form)
		{
			return $data;
		}

		$full = is_array($data['params'] ?? null) ? $data['params'] : [];

		foreach ($form->getGroup('params') as $field)
		{
			$name = $field->fieldname;

			if (!array_key_exists($name, $full))
			{
				$full[$name] = $form->getFieldAttribute($name, 'default', '', 'params');
			}
		}

		if ($full !== [])
		{
			$data['params'] = $full;
		}

		return $data;
	}

	/**
	 * @throws  ToolException  No such module type is registered for this client.
	 */
	private function requireModuleType(string $module): void
	{
		$db       = $this->getDatabase();
		$query    = $db->createQuery();
		$clientId = $this->clientId();

		$query->select($db->quoteName('extension_id'))
			->from($db->quoteName('#__extensions'))
			->where($db->quoteName('type') . ' = ' . $db->quote('module'))
			->where($db->quoteName('element') . ' = :module')
			->where($db->quoteName('client_id') . ' = :client')
			->bind(':module', $module)
			->bind(':client', $clientId, ParameterType::INTEGER);

		$db->setQuery($query);

		if ($db->loadResult() === null)
		{
			throw new ToolException(sprintf('Not found: module type %s', $module));
		}
	}

	/**
	 * Loads a module by id with a fresh {@see AbstractTool::loadItem()} call, then rejects it if it belongs
	 * to the other client — `ModuleModel::getItem()` does not itself check `client_id` (only `id`), so a
	 * `modules_admin_read` call with a site module's id would otherwise happily return it.
	 *
	 * @return  array<string, mixed>
	 * @throws  ToolException
	 */
	private function loadOwn(int $id): array
	{
		/** @var AdminModel $model */
		$model = $this->createModel(self::OPTION, 'Module');

		$item = $this->loadItem($model, $id, $this->resourceLabel());

		if ((int) ($item['client_id'] ?? -1) !== $this->clientId())
		{
			throw new ToolException(sprintf('Not found: %s #%d', $this->resourceLabel(), $id));
		}

		return $item;
	}
}
