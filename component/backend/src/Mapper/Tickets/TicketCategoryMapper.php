<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Mapper\Tickets;

defined('_JEXEC') || die;

/**
 * Pure input/output mapping for `com_categories` records filtered to the `com_ats` extension (ATS ticket
 * categories) — tool parameters -> `CategoriesModel`/`CategoryModel` state, and the output field
 * whitelists.
 *
 * `ITEM_FIELDS`/`LIST_FIELDS` are copied verbatim from Joomla core's own Web Services API view for
 * categories (`api/components/com_categories/src/View/Categories/JsonapiView.php`,
 * `$fieldsToRenderItem`/`$fieldsToRenderList`) — see `.plans/00-conventions.md` §5.9.
 *
 * Pure: no Joomla.
 *
 * @since  0.0.1
 */
final class TicketCategoryMapper
{
	/**
	 * Copied verbatim from `api/components/com_categories/src/View/Categories/JsonapiView.php::$fieldsToRenderItem`.
	 */
	public const ITEM_FIELDS = [
		'id',
		'title',
		'alias',
		'note',
		'published',
		'access',
		'checked_out',
		'checked_out_time',
		'created_user_id',
		'parent_id',
		'level',
		'extension',
		'lft',
		'rgt',
		'language',
		'language_title',
		'language_image',
		'editor',
		'access_level',
		'author_name',
		'count_trashed',
		'count_unpublished',
		'count_published',
		'count_archived',
		'params',
		'description',
	];

	/**
	 * Copied verbatim from `$fieldsToRenderList` — the same list, minus `extension`.
	 */
	public const LIST_FIELDS = [
		'id',
		'title',
		'alias',
		'note',
		'published',
		'access',
		'checked_out',
		'checked_out_time',
		'created_user_id',
		'parent_id',
		'level',
		'lft',
		'rgt',
		'language',
		'language_title',
		'language_image',
		'editor',
		'access_level',
		'author_name',
		'count_trashed',
		'count_unpublished',
		'count_published',
		'count_archived',
		'params',
		'description',
	];

	public const INT_FIELDS = [
		'published',
		'access',
		'checked_out',
		'created_user_id',
		'parent_id',
		'level',
		'lft',
		'rgt',
		'count_trashed',
		'count_unpublished',
		'count_published',
		'count_archived',
	];

	public const JSON_FIELDS = ['params'];

	/**
	 * `CategoriesModel::getListQuery()` state, scoped to `com_ats` ticket categories.
	 *
	 * @return  array<string, mixed>
	 */
	public static function listState(?string $search): array
	{
		$data = [
			'filter.extension' => 'com_ats',
			'filter.component' => 'com_ats',
			'filter.section'   => null,
			'filter.published' => '',
			'list.ordering'    => 'a.lft',
			'list.direction'   => 'ASC',
		];

		if ($search !== null)
		{
			$data['filter.search'] = $search;
		}

		return $data;
	}
}
