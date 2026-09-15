<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Shared\Menus;

defined('_JEXEC') || die;

use Akeeba\Component\Mcp4j\Administrator\Bridge\AbstractTool;
use Akeeba\Component\Mcp4j\Administrator\Mapper\Menus\MenuItemMapper;
use Akeeba\Component\Mcp4j\Administrator\Mapper\Menus\MenuTypeMapper;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Exception\ToolException;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;

/**
 * Shared implementation for the site/administrator *menu items* (`#__menu`) tool pairs
 * ({@see \Akeeba\Component\Mcp4j\Administrator\Tool\Menus\MenuItems} and
 * {@see \Akeeba\Component\Mcp4j\Administrator\Tool\Menus\AdminMenuItems}) — identical behaviour except for
 * the client id (0 = site, 1 = administrator), which the concrete subclass supplies via
 * {@see self::clientId()}.
 *
 * Ported from the original `joomla-mcp-php` project's `src/Server/Menus/MenuItems.php` and
 * `AdminMenuItems.php` (which differ from each other only in tool names/descriptions/URLs) — see
 * `.plans/06-menus.md`.
 *
 * @since  0.0.1
 */
abstract class AbstractMenuItemsTool extends AbstractTool
{
	private const OPTION = 'com_menus';

	/**
	 * 0 = site menu items, 1 = administrator menu items.
	 */
	abstract protected function clientId(): int;

	protected function resourceLabel(): string
	{
		return $this->clientId() === 0 ? 'site menu item' : 'administrator menu item';
	}

	/**
	 * @return  array{items: list<array>, pagination: array{total: int, limit: int, offset: int}}
	 */
	protected function doList(
		?string $filterSearch,
		?int $filterState,
		?string $filterLanguage,
		?string $filterMenutype,
		?int $filterLevel,
		?int $pageLimit,
		?int $pageOffset
	): array {
		$this->requireAuthorised('core.manage', self::OPTION);

		/** @var \Joomla\Component\Menus\Administrator\Model\ItemsModel $model */
		$model = $this->createModel(self::OPTION, 'Items');

		// A site tool must not be able to list administrator menus (or vice versa) by explicitly naming
		// one — ItemsModel::getListQuery() ignores client_id for an explicit filter.menutype, only for the
		// "all menus of this client" (empty string) case. "main" (the reserved administrator component
		// items pseudo-menu — see .plans/06-menus.md "Key facts" §11) has no #__menu_types row at all and
		// is only ever meaningful for the administrator class.
		if ($filterMenutype !== null && !($filterMenutype === 'main' && $this->clientId() === 1))
		{
			$this->resolveMenutype($model, $filterMenutype);
		}

		return $this->listItems(
			$model,
			MenuItemMapper::listState($this->clientId(), $filterSearch, $filterState, $filterLanguage, $filterMenutype, $filterLevel),
			$pageLimit,
			$pageOffset,
			MenuItemMapper::LIST_FIELDS,
			MenuItemMapper::INT_FIELDS,
			MenuItemMapper::JSON_FIELDS
		);
	}

	/**
	 * @return  array<string, mixed>
	 */
	protected function doRead(int $id): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);

		/** @var \Joomla\Component\Menus\Administrator\Model\ItemModel $model */
		$model = $this->createModel(self::OPTION, 'Item');
		$this->prepareItemModel($model, $id, null);

		$item = $this->loadItem($model, $id, $this->resourceLabel());

		$this->requireSameClient($item, $id);

		return $this->shape($item, MenuItemMapper::ITEM_FIELDS, MenuItemMapper::INT_FIELDS, MenuItemMapper::JSON_FIELDS);
	}

	/**
	 * @param   ?array<int, mixed>  $tags
	 * @param   ?array<string, mixed>  $request
	 *
	 * @return  array<string, mixed>
	 */
	protected function doCreate(
		string $title,
		?string $alias,
		string $menutype,
		string $type,
		string $link,
		?int $parentId,
		int $published,
		?int $access,
		string $language,
		?string $note,
		?array $tags,
		?string $browserNav,
		?bool $home,
		?array $request
	): array {
		$this->requireAuthorised('core.manage', self::OPTION);

		if ($tags !== null && $tags !== [])
		{
			throw new ToolException('Menu items do not support tags.');
		}

		if (MenuTypeMapper::isReservedMenutype($menutype))
		{
			throw new ToolException('The menutype "main" is reserved for the administrator menu.');
		}

		/** @var \Joomla\Component\Menus\Administrator\Model\ItemModel $model */
		$model = $this->createModel(self::OPTION, 'Item');

		$menutypeRow = $this->resolveMenutype($model, $menutype);

		// Mirrors ItemController::allowAdd().
		$this->requireAuthorised('core.create', self::OPTION . '.menu.' . $menutypeRow->id);

		$data = MenuItemMapper::createData(
			$this->clientId(),
			$title,
			$alias,
			$menutype,
			$type,
			$link,
			$parentId,
			$published,
			$access,
			$language,
			$note,
			$browserNav,
			$home,
			$request
		);

		if ($type === 'url' && !MenuItemMapper::isAllowedUrlLink($data['link']))
		{
			throw new ToolException('The link uses a URL scheme that is not allowed.');
		}

		if ($type === 'component')
		{
			$built            = MenuItemMapper::buildComponentLink($data['link'], $data['request'] ?? []);
			$data['link']     = $built['link'];
			$data['request']  = $built['request'];
		}

		$this->prepareItemModel($model, 0, $menutype);

		$newId = $this->saveItem($model, $data, null, $this->resourceLabel());

		/** @var \Joomla\Component\Menus\Administrator\Model\ItemModel $freshModel */
		$freshModel = $this->createModel(self::OPTION, 'Item');
		$this->prepareItemModel($freshModel, $newId, null);

		return $this->shape(
			$this->loadItem($freshModel, $newId, $this->resourceLabel()),
			MenuItemMapper::ITEM_FIELDS,
			MenuItemMapper::INT_FIELDS,
			MenuItemMapper::JSON_FIELDS
		);
	}

	/**
	 * @param   ?array<int, mixed>  $tags
	 * @param   ?array<string, mixed>  $request
	 *
	 * @return  array<string, mixed>
	 */
	protected function doUpdate(
		int $id,
		?string $title,
		?string $alias,
		?string $menutype,
		?string $type,
		?string $link,
		?int $parentId,
		?int $published,
		?int $access,
		?string $language,
		?string $note,
		?array $tags,
		?string $browserNav,
		?bool $home,
		?array $request
	): array {
		$this->requireAuthorised('core.manage', self::OPTION);

		if ($tags !== null && $tags !== [])
		{
			throw new ToolException('Menu items do not support tags.');
		}

		/** @var \Joomla\Component\Menus\Administrator\Model\ItemModel $model */
		$model = $this->createModel(self::OPTION, 'Item');
		$this->prepareItemModel($model, $id, null);

		$current = $this->loadItem($model, $id, $this->resourceLabel());

		$this->requireSameClient($current, $id);

		$currentMenutype = (string) ($current['menutype'] ?? '');

		if ($currentMenutype === 'main')
		{
			// Mirrors ItemController::allowEdit(), which hard-refuses editing "main" items regardless of
			// ACL — see .plans/06-menus.md "Key facts" and the per-tool notes.
			throw new ToolException('Permission denied: core.edit on com_menus.menu.0');
		}

		$currentMenutypeRow = $this->resolveMenutype($model, $currentMenutype);

		$this->requireAuthorised('core.edit', self::OPTION . '.menu.' . $currentMenutypeRow->id);

		if ($menutype !== null && $menutype !== $currentMenutype)
		{
			if (MenuTypeMapper::isReservedMenutype($menutype))
			{
				throw new ToolException('The menutype "main" is reserved for the administrator menu.');
			}

			$newMenutypeRow = $this->resolveMenutype($model, $menutype);

			$this->requireAuthorised('core.create', self::OPTION . '.menu.' . $newMenutypeRow->id);
		}

		$data = MenuItemMapper::updateData(
			$title,
			$alias,
			$menutype,
			$type,
			$link,
			$parentId,
			$published,
			$access,
			$language,
			$note,
			$browserNav,
			$home,
			$request
		);

		$effectiveType = $type ?? (string) ($current['type'] ?? '');

		if ($effectiveType === 'url' && $link !== null && !MenuItemMapper::isAllowedUrlLink($link))
		{
			throw new ToolException('The link uses a URL scheme that is not allowed.');
		}

		if ($effectiveType === 'component' && ($link !== null || $request !== null))
		{
			$effectiveLink    = $link ?? (string) ($current['link'] ?? '');
			$built            = MenuItemMapper::buildComponentLink($effectiveLink, $request ?? []);
			$data['link']     = $built['link'];
			$data['request']  = $built['request'];
		}

		$effectiveMenutype = $menutype ?? $currentMenutype;

		$this->prepareItemModel($model, $id, $effectiveMenutype);

		$this->saveItem($model, $data, $id, $this->resourceLabel());

		/** @var \Joomla\Component\Menus\Administrator\Model\ItemModel $freshModel */
		$freshModel = $this->createModel(self::OPTION, 'Item');
		$this->prepareItemModel($freshModel, $id, null);

		return $this->shape(
			$this->loadItem($freshModel, $id, $this->resourceLabel()),
			MenuItemMapper::ITEM_FIELDS,
			MenuItemMapper::INT_FIELDS,
			MenuItemMapper::JSON_FIELDS
		);
	}

	/**
	 * @return  array{deleted: true, id: int}
	 */
	protected function doDelete(int $id): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);

		/** @var \Joomla\Component\Menus\Administrator\Model\ItemModel $model */
		$model = $this->createModel(self::OPTION, 'Item');
		$this->prepareItemModel($model, $id, null);

		$current = $this->loadItem($model, $id, $this->resourceLabel());

		$this->requireSameClient($current, $id);

		if ((int) ($current['home'] ?? 0) === 1)
		{
			throw new ToolException('The default home menu item cannot be deleted. Set another item as home first.');
		}

		// ItemModel::canDelete() requires published == -2 — trash first if it isn't already, exactly like
		// the original "trashes first, then deletes" contract (.plans/00-conventions.md §6).
		if ((int) ($current['published'] ?? 0) !== -2)
		{
			/** @var \Joomla\Component\Menus\Administrator\Model\ItemModel $trashModel */
			$trashModel = $this->createModel(self::OPTION, 'Item');
			$this->prepareItemModel($trashModel, $id, null);

			$this->changeState($trashModel, $id, -2, $this->resourceLabel());
		}

		/** @var \Joomla\Component\Menus\Administrator\Model\ItemModel $deleteModel */
		$deleteModel = $this->createModel(self::OPTION, 'Item');
		$this->prepareItemModel($deleteModel, $id, null);

		$this->deleteItem($deleteModel, $id, $this->resourceLabel());

		return ['deleted' => true, 'id' => $id];
	}

	/**
	 * @return  array{items: list<array>, pagination: array{total: int, limit: int, offset: int}}
	 */
	protected function doTypes(): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);

		/** @var \Joomla\Component\Menus\Administrator\Model\MenutypesModel $model */
		$model = $this->createModel(self::OPTION, 'Menutypes');
		$model->setState('client_id', $this->clientId());

		$groups = $model->getTypeOptions();
		$items  = [];

		foreach ($groups as $groupName => $options)
		{
			foreach ((array) $options as $option)
			{
				$request = (array) $option->request;

				$items[] = [
					'id'          => implode('/', $request),
					'title'       => Text::_($option->title),
					'description' => Text::_($option->description ?? ''),
					'group'       => Text::_((string) $groupName),
					'request'     => $request,
					'link'        => 'index.php?' . http_build_query($request),
				];
			}
		}

		return [
			'items'      => $items,
			'pagination' => [
				'total'  => count($items),
				'limit'  => count($items),
				'offset' => 0,
			],
		];
	}

	/**
	 * Loads a `#__menu_types` row by its `menutype` identifier, scoped to this class's client id.
	 *
	 * @throws  ToolException  No such menutype, or it belongs to the other client.
	 */
	private function resolveMenutype(BaseDatabaseModel $model, string $menutype): \stdClass
	{
		// Explicit prefix: BaseDatabaseModel::getTable()'s own default ('') is deprecated since it falls
		// back to the *current* application name (here, 'api') to resolve the class, which would emit a
		// deprecation notice into (and corrupt) the JSON-RPC response body — see
		// .plans/03-joomla-bridge-and-tags-pilot.md's TagMapper::listState() docblock for the same class of
		// bug. 'Administrator' resolves to Joomla\Component\Menus\Administrator\Table\MenuTypeTable (a
		// trivial subclass of the core \Joomla\CMS\Table\MenuType) regardless of which model calls this.
		$table = $model->getTable('MenuType', 'Administrator');
		$table->load(['menutype' => $menutype]);

		if (empty($table->id) || (int) $table->client_id !== $this->clientId())
		{
			throw new ToolException(sprintf('Not found: menu %s', $menutype));
		}

		return (object) [
			'id'        => (int) $table->id,
			'menutype'  => (string) $table->menutype,
			'client_id' => (int) $table->client_id,
		];
	}

	/**
	 * Sets the model state `ItemModel::getForm()`/`getItem()`/`save()` read, mirroring what
	 * `ItemModel::populateState()` would have set for a real (non-`ignore_request`) request — see
	 * `.plans/06-menus.md` "Key facts" §5-6. Deliberately never sets `item.type`/`item.link`: `getForm()`
	 * sets those itself from the save data, and a pre-set `item.link` would make `getItem()` think the
	 * link type changed (see `ItemModel::getItem()`).
	 */
	private function prepareItemModel(BaseDatabaseModel $model, int $id, ?string $menutype): void
	{
		$model->setState('item.client_id', $this->clientId());
		$model->setState('item.id', $id);

		if ($menutype !== null)
		{
			$model->setState('item.menutype', $menutype);
			$model->setState('item.menutypeid', $this->resolveMenutype($model, $menutype)->id);
		}
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
}
