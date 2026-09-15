<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Mapper\Tags;

defined('_JEXEC') || die;

use Akeeba\Component\Mcp4j\Administrator\Bridge\Support\NullFilter;

/**
 * Pure input/output mapping for the Tags tool category: tool parameters -> `TagsModel`/`TagModel` state
 * and save data, and the output field whitelists.
 *
 * `ITEM_FIELDS`/`LIST_FIELDS` are copied verbatim from Joomla core's own Web Services API view for tags
 * (`api/components/com_tags/src/View/Tags/JsonapiView.php`, `$fieldsToRenderItem`/`$fieldsToRenderList`)
 * — that is the authoritative "what's safe to expose for this resource" list; see
 * `.plans/00-conventions.md` §5.9.
 *
 * Pure: no Joomla.
 *
 * @since  0.0.1
 */
final class TagMapper
{
	/**
	 * Copied verbatim from `api/components/com_tags/src/View/Tags/JsonapiView.php::$fieldsToRenderItem`.
	 */
	public const ITEM_FIELDS = [
		'id',
		'parent_id',
		'level',
		'lft',
		'rgt',
		'alias',
		'typeAlias',
		'path',
		'title',
		'note',
		'description',
		'published',
		'checked_out',
		'checked_out_time',
		'access',
		'params',
		'metadesc',
		'metakey',
		'metadata',
		'created_user_id',
		'created_time',
		'created_by_alias',
		'modified_user_id',
		'modified_time',
		'images',
		'urls',
		'hits',
		'language',
		'version',
		'publish_up',
		'publish_down',
	];

	/**
	 * Copied verbatim from `api/components/com_tags/src/View/Tags/JsonapiView.php::$fieldsToRenderList`.
	 */
	public const LIST_FIELDS = [
		'id',
		'title',
		'alias',
		'note',
		'published',
		'access',
		'description',
		'checked_out',
		'checked_out_time',
		'created_user_id',
		'path',
		'parent_id',
		'level',
		'lft',
		'rgt',
		'language',
		'language_title',
		'language_image',
		'editor',
		'author_name',
		'access_title',
	];

	public const INT_FIELDS = [
		'parent_id',
		'level',
		'lft',
		'rgt',
		'published',
		'access',
		'checked_out',
		'created_user_id',
		'modified_user_id',
		'hits',
		'version',
	];

	public const JSON_FIELDS = ['params', 'metadata', 'images', 'urls'];

	/**
	 * `TagsModel::getListQuery()` state — see `.plans/03-joomla-bridge-and-tags-pilot.md` Part C.
	 * `filter.level`/`filter.access` are deliberately left unset (empty = no filter, per
	 * `getListQuery()`); `filter.published` is always set (to `''` when `$state` is null) because
	 * `TagsModel` treats an unset state the same as `''` (shows published + unpublished, like the backend
	 * list). `filter.extension` IS always set here (to `''`), unlike the other two — not because
	 * `getListQuery()` needs it (it doesn't filter on it at all), but because `TagsModel::getItems()`
	 * (Joomla 4.4 only) calls `$this->getState('filter.extension')` with **no default** and feeds the
	 * result straight into `explode('.', ...)` — since `ignore_request => true` means `populateState()`
	 * (which would otherwise default this to `'com_content'`) never runs, that call receives `null` and
	 * triggers a PHP 8.1+ deprecation notice, which gets echoed into (and corrupts) the JSON-RPC response
	 * body. Joomla 5.4/6.x's `TagsModel::getItems()` already passes `''` as its own default, so this key
	 * is a no-op there — set unconditionally rather than branching on `JVERSION`.
	 *
	 * @return  array<string, mixed>
	 */
	public static function listState(?string $search, ?int $state, ?string $language): array
	{
		$data = [
			'list.ordering'    => 'a.lft',
			'list.direction'   => 'ASC',
			'filter.extension' => '',
		];

		if ($search !== null)
		{
			$data['filter.search'] = $search;
		}

		$data['filter.published'] = $state !== null ? (string) $state : '';

		if ($language !== null)
		{
			$data['filter.language'] = $language;
		}

		return $data;
	}

	/**
	 * @return  array<string, mixed>
	 */
	public static function createData(
		string $title,
		?string $alias,
		int $published,
		?int $parentId,
		?int $access,
		string $language,
		?string $description,
		?string $metadesc,
		?string $metakey,
		?string $note
	): array {
		$data = [
			'title'     => $title,
			'published' => $published,
			// A tag's nested set root is id 1 ("JNONE" in the parent_id field's option list); 0 is not a
			// valid parent — see forms/tag.xml's `parent_id` field ("field=id parent=parent" nested-set
			// validation) and TagTable::setLocation(). Never pass 0 through.
			'parent_id' => ($parentId !== null && $parentId > 0) ? $parentId : 1,
			'access'    => $access ?? 1,
			'language'  => $language,
			// #__tags.description is `mediumtext NOT NULL` with no column default, and unlike
			// metadesc/metakey/params/images/urls/metadata, TagTable::check() never fills a missing value
			// in for it (it only handles those other columns) — a real backend/frontend HTML form always
			// posts this field (even empty), so it must always be present here too, or Table::store()
			// fails with a MySQL "Field 'description' doesn't have a default value" error on INSERT. This
			// was found by this plan's own E2E suite; .plans/03-joomla-bridge-and-tags-pilot.md's Part C
			// pseudocode had it as "only if non-null" like the other optional text fields — see the final
			// report's deviations list.
			'description' => $description ?? '',
		];

		if ($alias !== null && $alias !== '')
		{
			$data['alias'] = $alias;
		}

		if ($metadesc !== null)
		{
			$data['metadesc'] = $metadesc;
		}

		if ($metakey !== null)
		{
			$data['metakey'] = $metakey;
		}

		if ($note !== null)
		{
			$data['note'] = $note;
		}

		return $data;
	}

	/**
	 * Only non-null parameters are included (null = "don't change" — see `.plans/00-conventions.md` §6);
	 * an explicit `parentId` of `0` is normalised to `1` for the same nested-set reason as
	 * {@see self::createData()}.
	 *
	 * @return  array<string, mixed>
	 */
	public static function updateData(
		?string $title,
		?string $alias,
		?int $published,
		?int $parentId,
		?int $access,
		?string $language,
		?string $description,
		?string $metadesc,
		?string $metakey,
		?string $note
	): array {
		if ($parentId !== null && $parentId <= 0)
		{
			$parentId = 1;
		}

		return NullFilter::withoutNulls([
			'title'       => $title,
			'alias'       => $alias,
			'published'   => $published,
			'parent_id'   => $parentId,
			'access'      => $access,
			'language'    => $language,
			'description' => $description,
			'metadesc'    => $metadesc,
			'metakey'     => $metakey,
			'note'        => $note,
		]);
	}
}
