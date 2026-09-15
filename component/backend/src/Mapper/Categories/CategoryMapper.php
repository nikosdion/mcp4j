<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Mapper\Categories;

defined('_JEXEC') || die;

use Akeeba\Component\Mcp4j\Administrator\Bridge\Support\NullFilter;
use Akeeba\Component\Mcp4j\Administrator\Bridge\Support\TagIds;

/**
 * Pure input/output mapping shared by every `com_categories`-backed category tool class
 * (`Tool/Content/Categories.php` here, and the Banners/Contact/Newsfeeds categories classes plans 08/09
 * add on top of {@see \Akeeba\Component\Mcp4j\Administrator\Shared\AbstractCategoriesTool}).
 *
 * `ITEM_FIELDS`/`LIST_FIELDS` are derived from Joomla core's own Web Services API view for categories
 * (`api/components/com_categories/src/View/Categories/JsonapiView.php`), plus the extra, non-sensitive
 * fields `CategoryModel::getItem()` actually returns (`path`, `extension`, `created_time`,
 * `modified_user_id`, `modified_time`, `metadesc`, `metakey`, `metadata`, `hits`, `version`, `tags`) —
 * see `.plans/04-content.md` Part B. Deliberately excluded: `asset_id`, `rules`, `associations`.
 *
 * Pure: no Joomla.
 *
 * @since  0.0.1
 */
final class CategoryMapper
{
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
		'created_time',
		'modified_user_id',
		'modified_time',
		'parent_id',
		'level',
		'path',
		'extension',
		'lft',
		'rgt',
		'language',
		'params',
		'description',
		'metadesc',
		'metakey',
		'metadata',
		'hits',
		'version',
		'tags',
	];

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
		'path',
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

	public const INT_FIELDS = [
		'published',
		'access',
		'checked_out',
		'created_user_id',
		'modified_user_id',
		'parent_id',
		'level',
		'lft',
		'rgt',
		'hits',
		'version',
		'count_trashed',
		'count_unpublished',
		'count_published',
		'count_archived',
	];

	public const JSON_FIELDS = ['params', 'metadata'];

	/**
	 * `CategoriesModel::getListQuery()` state — see `.plans/04-content.md` Part B.
	 *
	 * `filter.extension` is always set (never omitted — without it `CategoriesModel` lists categories of
	 * every component). `filter.published` is always set too, to `''` when `$state` is null, so the model
	 * shows published + unpublished (states 0 and 1) exactly like the backend list. `filter.level` is only
	 * set when positive — `getListQuery()` treats it as "level <= N", and 0/negative would wrongly exclude
	 * every category (the root has level 0, top-level categories level 1).
	 *
	 * @return  array<string, mixed>
	 */
	public static function listState(string $extension, ?string $search, ?int $state, ?string $language, ?int $level): array
	{
		$data = [
			'filter.extension' => $extension,
			'list.ordering'    => 'a.lft',
			'list.direction'   => 'ASC',
		];

		if ($search !== null && $search !== '')
		{
			$data['filter.search'] = $search;
		}

		$data['filter.published'] = $state !== null ? (string) $state : '';

		if ($language !== null)
		{
			$data['filter.language'] = $language;
		}

		if ($level !== null && $level > 0)
		{
			$data['filter.level'] = $level;
		}

		return $data;
	}

	/**
	 * @return  array<string, mixed>
	 */
	public static function createData(
		string $extension,
		string $title,
		?string $alias,
		?int $parentId,
		int $published,
		?int $access,
		string $language,
		?string $description,
		?array $tags,
		?string $metadesc,
		?string $metakey,
		?string $note
	): array {
		$data = [
			'extension' => $extension,
			'title'     => $title,
			// A category's nested set root is id 1 (the "ROOT" category) — 0 is not a valid parent for
			// Table::setLocation(). Never pass 0 through.
			'parent_id' => ($parentId !== null && $parentId > 0) ? $parentId : 1,
			'published' => $published,
			'access'    => $access ?? 1,
			'language'  => $language,
			'tags'      => $tags !== null ? TagIds::fromMixed($tags) : [],
			// Joomla 4.4's Table\Category::check() calls trim($this->alias) with no null-guard (5.4/6.x
			// fixed this to `trim($this->alias ?? '')`); leaving `alias` unset here (as the "never
			// generate an alias in PHP" convention would otherwise suggest — see AbstractTool's docblock)
			// makes Form::validate() return `alias => null` for a create, and Table::bind() carries that
			// straight through to check()'s trim(null) call, which emits a PHP deprecation notice that
			// gets echoed into (and corrupts) the JSON-RPC response body on 4.4 — the same class of bug
			// documented in ArticleMapper::createData() and TagMapper::listState(). Always sending an
			// explicit empty string (Joomla's own alias-from-title fallback runs identically for '' as it
			// would have for null, on every version) avoids the deprecation without generating the alias
			// ourselves.
			'alias'     => $alias ?? '',
		];

		if ($description !== null)
		{
			$data['description'] = $description;
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
	 * {@see self::createData()}. `tags` is always sent explicitly: the given ids when non-null, otherwise
	 * the current record's own tags (re-normalised) — `saveItem()`'s merge would otherwise be fine on its
	 * own, but `getItem()` returns `tags` as a `TagsHelper`-shaped value that must never reach `save()`
	 * unconverted (see `.plans/04-content.md` Gotchas). `extension` is never included — a category can
	 * never change which component it belongs to through these tools.
	 *
	 * @return  array<string, mixed>
	 */
	public static function updateData(
		?string $title,
		?string $alias,
		?int $parentId,
		?int $published,
		?int $access,
		?string $language,
		?string $description,
		?array $tags,
		?string $metadesc,
		?string $metakey,
		?string $note,
		array $currentItem
	): array {
		if ($parentId !== null && $parentId <= 0)
		{
			$parentId = 1;
		}

		$data = NullFilter::withoutNulls([
			'title'       => $title,
			'alias'       => $alias,
			'parent_id'   => $parentId,
			'published'   => $published,
			'access'      => $access,
			'language'    => $language,
			'description' => $description,
			'metadesc'    => $metadesc,
			'metakey'     => $metakey,
			'note'        => $note,
		]);

		$data['tags'] = $tags !== null ? TagIds::fromMixed($tags) : TagIds::fromMixed($currentItem['tags'] ?? null);

		return $data;
	}

	/**
	 * Replaces `tags` (a normalised `TagsHelper` value, an array of ids, a comma-separated string, or
	 * absent) with a clean `list<int>`, ready either to feed back into a save payload or to pass through
	 * {@see \Akeeba\Component\Mcp4j\Administrator\Bridge\Support\OutputShaper}.
	 *
	 * @return  array<string, mixed>
	 */
	public static function normaliseItem(array $item): array
	{
		if (array_key_exists('tags', $item))
		{
			$item['tags'] = TagIds::fromMixed($item['tags']);
		}

		return $item;
	}
}
