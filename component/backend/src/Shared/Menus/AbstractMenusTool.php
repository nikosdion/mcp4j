<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Shared\Menus;

defined('_JEXEC') || die;

use Akeeba\Component\Mcp4j\Administrator\Bridge\AbstractTool;
use Akeeba\Component\Mcp4j\Administrator\Bridge\Support\DataMerger;
use Akeeba\Component\Mcp4j\Administrator\Bridge\Support\ItemNormalizer;
use Akeeba\Component\Mcp4j\Administrator\Mapper\Menus\MenuTypeMapper;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Exception\ToolException;

/**
 * Shared implementation for the site/administrator *menus* (`#__menu_types`) tool pairs
 * ({@see \Akeeba\Component\Mcp4j\Administrator\Tool\Menus\Menus} and
 * {@see \Akeeba\Component\Mcp4j\Administrator\Tool\Menus\AdminMenus}) — identical behaviour except for the
 * client id (0 = site, 1 = administrator), which the concrete subclass supplies via {@see self::clientId()}.
 *
 * Ported from the original `joomla-mcp-php` project's `src/Server/Menus/Menus.php` and
 * `AdminMenus.php` (which differ from each other only in tool names/descriptions/URLs) — see
 * `.plans/06-menus.md`.
 *
 * **Does not reuse `AbstractTool::loadItem()`/`saveItem()`/`deleteItem()`/`changeState()`** for the `Menu`
 * model, unlike every other domain plan: those are strictly typed against
 * `\Joomla\CMS\MVC\Model\AdminModel`, and on Joomla 4.4 (only) `MenuModel extends FormModel`, not
 * `AdminModel` — a fatal `TypeError` results if they are called with it there, even though the model has
 * the identical shape (`getItem()`/`getForm()`/`validate()`/`save()`/`delete()`) on every supported version.
 * {@see self::loadMenuTypeItem()}/{@see self::saveMenuTypeItem()}/{@see self::deleteMenuTypeItem()} below
 * re-implement the same contract as those bridge methods, loosely typed, for this one model only — found by
 * this plan's own E2E suite running against the full Joomla version matrix; see the final report's
 * Joomla-version-differences list. `#__menu_types` has no `checked_out` column (menu types, unlike menu
 * items, are never individually checked out — see `forms/menu.xml`, which has no such field), so the
 * checkout/checkin handling `AbstractTool::saveItem()` does for other resources is correctly omitted here.
 *
 * @since  0.0.1
 */
abstract class AbstractMenusTool extends AbstractTool
{
	private const OPTION = 'com_menus';

	/**
	 * 0 = site menus, 1 = administrator menus.
	 */
	abstract protected function clientId(): int;

	protected function resourceLabel(): string
	{
		return $this->clientId() === 0 ? 'site menu' : 'administrator menu';
	}

	/**
	 * @return  array{items: list<array>, pagination: array{total: int, limit: int, offset: int}}
	 */
	protected function doList(?string $filterSearch, ?int $pageLimit, ?int $pageOffset): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);

		/** @var \Joomla\Component\Menus\Administrator\Model\MenusModel $model */
		$model = $this->createModel(self::OPTION, 'Menus');

		return $this->listItems(
			$model,
			MenuTypeMapper::listState($this->clientId(), $filterSearch),
			$pageLimit,
			$pageOffset,
			MenuTypeMapper::LIST_FIELDS,
			MenuTypeMapper::INT_FIELDS
		);
	}

	/**
	 * @return  array<string, mixed>
	 */
	protected function doRead(int $id): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);

		/** @var \Joomla\Component\Menus\Administrator\Model\MenuModel $model */
		$model = $this->createModel(self::OPTION, 'Menu');

		$item = $this->loadMenuTypeItem($model, $id);

		// MenuModel::getItem() does not itself check client_id — see .plans/06-menus.md "Key facts" §2.
		$this->requireSameClient($item, $id);

		return $this->shape($item, MenuTypeMapper::ITEM_FIELDS, MenuTypeMapper::INT_FIELDS);
	}

	/**
	 * @return  array<string, mixed>
	 */
	protected function doCreate(string $title, string $menutype, ?string $description): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);
		$this->requireAuthorised('core.create', self::OPTION);

		if (MenuTypeMapper::isReservedMenutype($menutype))
		{
			throw new ToolException('The menutype "main" is reserved for the administrator menu.');
		}

		/** @var \Joomla\Component\Menus\Administrator\Model\MenuModel $model */
		$model = $this->createModel(self::OPTION, 'Menu');
		$model->setState('client_id', $this->clientId());

		$data = MenuTypeMapper::createData($this->clientId(), $title, $menutype, $description);

		$newId = $this->saveMenuTypeItem($model, $data, null);

		/** @var \Joomla\Component\Menus\Administrator\Model\MenuModel $freshModel */
		$freshModel = $this->createModel(self::OPTION, 'Menu');

		return $this->shape(
			$this->loadMenuTypeItem($freshModel, $newId),
			MenuTypeMapper::ITEM_FIELDS,
			MenuTypeMapper::INT_FIELDS
		);
	}

	/**
	 * @return  array<string, mixed>
	 */
	protected function doUpdate(int $id, ?string $title, ?string $menutype, ?string $description): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);
		$this->requireAuthorised('core.edit', self::OPTION);

		/** @var \Joomla\Component\Menus\Administrator\Model\MenuModel $model */
		$model = $this->createModel(self::OPTION, 'Menu');
		$model->setState('client_id', $this->clientId());

		$current = $this->loadMenuTypeItem($model, $id);

		$this->requireSameClient($current, $id);

		if ($menutype !== null && MenuTypeMapper::isReservedMenutype($menutype))
		{
			throw new ToolException('The menutype "main" is reserved for the administrator menu.');
		}

		$data              = MenuTypeMapper::updateData($title, $menutype, $description);
		$data['client_id'] = (int) $current['client_id'];

		$this->saveMenuTypeItem($model, $data, $id);

		/** @var \Joomla\Component\Menus\Administrator\Model\MenuModel $freshModel */
		$freshModel = $this->createModel(self::OPTION, 'Menu');

		return $this->shape(
			$this->loadMenuTypeItem($freshModel, $id),
			MenuTypeMapper::ITEM_FIELDS,
			MenuTypeMapper::INT_FIELDS
		);
	}

	/**
	 * @return  array{deleted: true, id: int}
	 */
	protected function doDelete(int $id): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);

		/** @var \Joomla\Component\Menus\Administrator\Model\MenuModel $model */
		$model = $this->createModel(self::OPTION, 'Menu');

		$current = $this->loadMenuTypeItem($model, $id);

		$this->requireSameClient($current, $id);

		// MenuModel::delete() performs no ACL check at all — see .plans/06-menus.md "Key facts" §3 — so we
		// enforce it ourselves, exactly like MenusController::delete() does.
		$this->requireAuthorised('core.delete', self::OPTION . '.menu.' . $id);

		// No trash step: menu types have no publish state.
		$this->deleteMenuTypeItem($model, $id);

		return ['deleted' => true, 'id' => $id];
	}

	/**
	 * @param   array<string, mixed>  $item
	 *
	 * @throws  ToolException
	 */
	private function requireSameClient(array $item, int $id): void
	{
		if ((int) ($item['client_id'] ?? -1) !== $this->clientId())
		{
			throw new ToolException(sprintf('Not found: %s #%d', $this->resourceLabel(), $id));
		}
	}

	/**
	 * Loosely-typed equivalent of {@see AbstractTool::loadItem()} for `MenuModel` — see this class's own
	 * docblock for why it can't reuse that method directly.
	 *
	 * @return  array<string, mixed>
	 * @throws  ToolException
	 */
	private function loadMenuTypeItem(object $model, int $id): array
	{
		if ($id <= 0)
		{
			throw new ToolException(sprintf('Not found: %s #%d', $this->resourceLabel(), $id));
		}

		$item = $model->getItem($id);

		if ($item === false || $item === null)
		{
			throw new ToolException(sprintf('Not found: %s #%d', $this->resourceLabel(), $id));
		}

		$normalised = ItemNormalizer::toArray($item);
		$keyValue   = $normalised['id'] ?? null;

		if ($keyValue === null || $keyValue === '' || (int) $keyValue !== $id)
		{
			throw new ToolException(sprintf('Not found: %s #%d', $this->resourceLabel(), $id));
		}

		return $normalised;
	}

	/**
	 * Loosely-typed equivalent of {@see AbstractTool::saveItem()} for `MenuModel` — see this class's own
	 * docblock for why it can't reuse that method directly. `MenuModel` has no checkout support to mirror
	 * (see this class's own docblock).
	 *
	 * @param   array<string, mixed>  $data
	 *
	 * @throws  ToolException
	 */
	private function saveMenuTypeItem(object $model, array $data, ?int $id): int
	{
		if ($id)
		{
			$current = $this->loadMenuTypeItem($model, $id);
			$data    = DataMerger::merge($current, $data);
		}

		$data['id'] = $id ?: 0;

		$app   = $this->getApplication();
		$input = $app->getInput();
		$input->set('jform', $data);
		$input->post->set('jform', $data);
		$input->set('id', $data['id']);

		$form = $model->getForm($data, false);

		if (!$form)
		{
			throw new ToolException(sprintf('Cannot load the %s form: %s', $this->resourceLabel(), $this->modelError($model, 'Unknown error')));
		}

		$valid = $model->validate($form, $data);

		if ($valid === false)
		{
			$messages = [];

			foreach (array_slice($model->getErrors(), 0, 3) as $error)
			{
				$messages[] = $error instanceof \Throwable ? $error->getMessage() : (string) $error;
			}

			if ($messages === [])
			{
				$messages[] = $this->modelError($model, 'Unknown validation error');
			}

			throw new ToolException(sprintf('Validation failed for %s: %s', $this->resourceLabel(), implode('; ', $messages)));
		}

		$saved = $model->save($valid);

		if (!$saved)
		{
			throw new ToolException(sprintf('Save failed for %s: %s', $this->resourceLabel(), $this->modelError($model, 'Unknown error')));
		}

		// MenuModel::save() always sets this exact state key on every supported version — see
		// administrator/components/com_menus/src/Model/MenuModel.php::save().
		$newId = (int) $model->getState('menu.id');

		if ($newId <= 0)
		{
			throw new ToolException(sprintf('Save failed for %s: %s', $this->resourceLabel(), $this->modelError($model, 'No id was returned by the model')));
		}

		return $newId;
	}

	/**
	 * Loosely-typed equivalent of {@see AbstractTool::deleteItem()} for `MenuModel` — see this class's own
	 * docblock for why it can't reuse that method directly.
	 *
	 * @throws  ToolException
	 */
	private function deleteMenuTypeItem(object $model, int $id): void
	{
		$this->loadMenuTypeItem($model, $id);

		$pks    = [$id];
		$result = $model->delete($pks);

		if (!$result)
		{
			throw new ToolException($this->modelError($model, sprintf('Permission denied: core.delete on %s #%d', $this->resourceLabel(), $id)));
		}
	}
}
