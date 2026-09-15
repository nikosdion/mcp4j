<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Mapper\Users;

defined('_JEXEC') || die;

use Akeeba\Component\Mcp4j\Administrator\Bridge\Support\NullFilter;

/**
 * Pure input/output mapping for the `users_groups_*` tools: tool parameters -> `GroupsModel`/`GroupModel`
 * state and save data, and the output field whitelist.
 *
 * `ITEM_FIELDS`/`LIST_FIELDS` are copied verbatim from
 * `api/components/com_users/src/View/Groups/JsonapiView.php`'s `$fieldsToRenderItem`/`$fieldsToRenderList`,
 * plus `count_enabled`/`count_disabled`/`user_count` (added by `GroupsModel::getItems()`, list-only) — see
 * `.plans/00-conventions.md` §5.9 and `.plans/05-users.md`.
 *
 * Pure: no Joomla.
 *
 * @since  0.0.1
 */
final class GroupMapper
{
	public const ITEM_FIELDS = ['id', 'parent_id', 'lft', 'rgt', 'title'];

	public const LIST_FIELDS = ['id', 'parent_id', 'lft', 'rgt', 'title', 'count_enabled', 'count_disabled', 'user_count'];

	public const INT_FIELDS = ['parent_id', 'lft', 'rgt', 'count_enabled', 'count_disabled', 'user_count'];

	/**
	 * `GroupsModel::getListQuery()`/`getStoreId()` state.
	 *
	 * @return  array<string, mixed>
	 */
	public static function listState(?string $search): array
	{
		$data = [
			'list.ordering'  => 'a.lft',
			'list.direction' => 'ASC',
		];

		if ($search !== null && $search !== '')
		{
			$data['filter.search'] = $search;
		}

		return $data;
	}

	/**
	 * `$parentId` `<= 0` (or null) is normalised to `1` — the root user group; `0` is never a valid
	 * `parent_id` for a non-root group (see `forms/group.xml`'s `parent_id` field, `option value="0"
	 * disabled="disabled"`).
	 *
	 * @return  array<string, mixed>
	 */
	public static function createData(string $title, ?int $parentId): array
	{
		return [
			'title'     => $title,
			'parent_id' => ($parentId !== null && $parentId > 0) ? $parentId : 1,
		];
	}

	/**
	 * Only non-null parameters are included (null = "don't change" — see `.plans/00-conventions.md` §6).
	 *
	 * @return  array<string, mixed>
	 */
	public static function updateData(?string $title, ?int $parentId): array
	{
		return NullFilter::withoutNulls([
			'title'     => $title,
			'parent_id' => $parentId,
		]);
	}
}
