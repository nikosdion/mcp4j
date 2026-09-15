<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Mapper\Menus;

defined('_JEXEC') || die;

use Akeeba\Component\Mcp4j\Administrator\Bridge\Support\NullFilter;

/**
 * Pure input/output mapping for the site/administrator *menus* (`#__menu_types`) tool category: tool
 * parameters -> `MenusModel`/`MenuModel` state and save data, and the output field whitelists.
 *
 * `ITEM_FIELDS`/`LIST_FIELDS` are copied verbatim from Joomla core's own Web Services API view
 * (`api/components/com_menus/src/View/Menus/JsonapiView.php::$fieldsToRenderItem`/`$fieldsToRenderList`),
 * minus the internal `asset_id` key from the list fields — see `.plans/00-conventions.md` §5.9 and
 * `.plans/06-menus.md`.
 *
 * Pure: no Joomla.
 *
 * @since  0.0.1
 */
final class MenuTypeMapper
{
	public const ITEM_FIELDS = ['id', 'menutype', 'title', 'description', 'client_id'];

	/**
	 * Core list fields plus the `count_*` metrics `MenusModel::getItems()` injects; `asset_id` (internal)
	 * dropped — see `.plans/06-menus.md`.
	 */
	public const LIST_FIELDS = ['id', 'menutype', 'title', 'description', 'client_id', 'count_published', 'count_unpublished', 'count_trashed'];

	public const INT_FIELDS = ['client_id', 'count_published', 'count_unpublished', 'count_trashed'];

	/**
	 * `MenusModel::getListQuery()` state — filters on the model state key `client_id` (not
	 * `filter.client_id`, a bug in core's own API controller that we deliberately don't copy — see
	 * `.plans/06-menus.md` "Key facts" §1).
	 *
	 * @return  array<string, mixed>
	 */
	public static function listState(int $clientId, ?string $search): array
	{
		$data = [
			'client_id' => $clientId,
		];

		if ($search !== null)
		{
			$data['filter.search'] = $search;
		}

		$data['list.ordering']  = 'a.id';
		$data['list.direction'] = 'ASC';

		return $data;
	}

	/**
	 * @return  array<string, mixed>
	 */
	public static function createData(int $clientId, string $title, string $menutype, ?string $description): array
	{
		$data = [
			'title'     => $title,
			'menutype'  => $menutype,
			'client_id' => $clientId,
		];

		if ($description !== null)
		{
			$data['description'] = $description;
		}

		return $data;
	}

	/**
	 * Only non-null parameters are included (null = "don't change" — see `.plans/00-conventions.md` §6).
	 *
	 * @return  array<string, mixed>
	 */
	public static function updateData(?string $title, ?string $menutype, ?string $description): array
	{
		return NullFilter::withoutNulls([
			'title'       => $title,
			'menutype'    => $menutype,
			'description' => $description,
		]);
	}

	/**
	 * `main` is the reserved menutype identifier for the administrator's built-in component-items menu —
	 * see `MenuController::save()` in Joomla core, which refuses it the same way (case-insensitively,
	 * trimmed).
	 */
	public static function isReservedMenutype(string $menutype): bool
	{
		return strtolower(trim($menutype)) === 'main';
	}
}
