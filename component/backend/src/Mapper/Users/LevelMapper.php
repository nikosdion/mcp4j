<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Mapper\Users;

defined('_JEXEC') || die;

/**
 * Pure input/output mapping for the `users_levels_*` tools: tool parameters -> `LevelsModel`/`LevelModel`
 * state and save data, and the output field whitelist.
 *
 * `ITEM_FIELDS`/`LIST_FIELDS` are copied verbatim from
 * `api/components/com_users/src/View/Levels/JsonapiView.php`'s `$fieldsToRenderItem`/`$fieldsToRenderList`
 * — see `.plans/00-conventions.md` §5.9 and `.plans/05-users.md`.
 *
 * Pure: no Joomla.
 *
 * @since  0.0.1
 */
final class LevelMapper
{
	public const ITEM_FIELDS = ['id', 'title', 'ordering', 'rules'];

	public const LIST_FIELDS = ['id', 'title', 'ordering', 'rules'];

	public const INT_FIELDS = ['ordering'];

	public const JSON_FIELDS = ['rules'];

	/**
	 * `LevelsModel::getListQuery()`/`getStoreId()` state.
	 *
	 * @return  array<string, mixed>
	 */
	public static function listState(?string $search): array
	{
		$data = [
			'list.ordering'  => 'a.ordering',
			'list.direction' => 'ASC',
		];

		if ($search !== null && $search !== '')
		{
			$data['filter.search'] = $search;
		}

		return $data;
	}

	/**
	 * @param   list<int>  $rules
	 *
	 * @return  array<string, mixed>
	 */
	public static function createData(string $title, array $rules): array
	{
		return [
			'title' => $title,
			'rules' => self::uniqueIntList($rules),
		];
	}

	/**
	 * Only non-null parameters are included (null = "don't change" — see `.plans/00-conventions.md` §6).
	 *
	 * @return  array<string, mixed>
	 */
	public static function updateData(?string $title, ?array $rules): array
	{
		$data = [];

		if ($title !== null)
		{
			$data['title'] = $title;
		}

		if ($rules !== null)
		{
			$data['rules'] = self::uniqueIntList($rules);
		}

		return $data;
	}

	/**
	 * `LevelModel::getItem()` already `json_decode()`s `rules` into a plain array; a list tool's items go
	 * through {@see \Akeeba\Component\Mcp4j\Administrator\Bridge\Support\OutputShaper}'s `JSON_FIELDS`
	 * decoding instead (raw `a.*` select). Either way, `rules` always ends up a clean, deduplicated,
	 * ascending `list<int>` after this — matching the shape {@see self::createData()}/{@see self::updateData()}
	 * accept back.
	 *
	 * @return  array<string, mixed>
	 */
	public static function normaliseItem(array $item): array
	{
		if (array_key_exists('rules', $item))
		{
			$item['rules'] = self::rulesToIntList($item['rules']);
		}

		return $item;
	}

	/**
	 * @return  list<int>
	 */
	private static function rulesToIntList(mixed $rules): array
	{
		if (is_string($rules))
		{
			if ($rules === '')
			{
				return [];
			}

			$decoded = json_decode($rules, true);
			$rules   = is_array($decoded) ? $decoded : [];
		}

		if (!is_array($rules))
		{
			return [];
		}

		return self::uniqueIntList($rules);
	}

	/**
	 * @return  list<int>
	 */
	private static function uniqueIntList(array $values): array
	{
		$ids = array_values(array_unique(array_map('intval', $values)));

		sort($ids);

		return $ids;
	}
}
