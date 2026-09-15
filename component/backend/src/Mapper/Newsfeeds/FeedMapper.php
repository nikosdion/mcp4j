<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Mapper\Newsfeeds;

defined('_JEXEC') || die;

use Akeeba\Component\Mcp4j\Administrator\Bridge\Support\NullFilter;
use Akeeba\Component\Mcp4j\Administrator\Bridge\Support\TagIds;

/**
 * Pure input/output mapping for the `newsfeeds_*` tools: tool parameters -> `NewsfeedModel`/
 * `NewsfeedsModel` state and save data, and the output field whitelist.
 *
 * `ITEM_FIELDS`/`LIST_FIELDS` are derived from core's own Web Services API view for newsfeeds
 * (`api/components/com_newsfeeds/src/View/Feeds/JsonapiView.php`), with `catid` instead of the JSON:API
 * `category` relationship. See `.plans/09-contact-newsfeeds.md`.
 *
 * @since  0.0.1
 */
final class FeedMapper
{
	public const ITEM_FIELDS = [
		'id',
		'name',
		'alias',
		'catid',
		'link',
		'published',
		'numarticles',
		'cache_time',
		'checked_out',
		'checked_out_time',
		'ordering',
		'rtl',
		'access',
		'language',
		'params',
		'created',
		'created_by',
		'created_by_alias',
		'modified',
		'modified_by',
		'metakey',
		'metadesc',
		'metadata',
		'publish_up',
		'publish_down',
		'description',
		'version',
		'hits',
		'images',
		'tags',
	];

	public const LIST_FIELDS = [
		'id',
		'name',
		'alias',
		'checked_out',
		'checked_out_time',
		'catid',
		'category_title',
		'numarticles',
		'cache_time',
		'created_by',
		'published',
		'access',
		'access_level',
		'ordering',
		'language',
		'language_title',
		'language_image',
		'publish_up',
		'publish_down',
		'editor',
		'modified_by',
	];

	public const INT_FIELDS = [
		'catid',
		'published',
		'numarticles',
		'cache_time',
		'checked_out',
		'ordering',
		'rtl',
		'access',
		'created_by',
		'modified_by',
		'version',
		'hits',
	];

	public const JSON_FIELDS = ['params', 'metadata', 'images'];

	/**
	 * `NewsfeedsModel::getListQuery()` state — same key shape as `ContactMapper::listState()`.
	 *
	 * @return  array<string, mixed>
	 */
	public static function listState(?string $filterSearch, ?int $filterState, ?int $filterCategory, ?string $filterLanguage): array
	{
		$data = [
			'list.ordering'  => 'a.name',
			'list.direction' => 'ASC',
		];

		if ($filterSearch !== null && $filterSearch !== '')
		{
			$data['filter.search'] = $filterSearch;
		}

		$data['filter.published'] = $filterState !== null ? (string) $filterState : '';

		if ($filterCategory !== null)
		{
			$data['filter.category_id'] = $filterCategory;
		}

		if ($filterLanguage !== null)
		{
			$data['filter.language'] = $filterLanguage;
		}

		return $data;
	}

	/**
	 * @return  array<string, mixed>
	 */
	public static function createData(
		string $name,
		string $link,
		int $catId,
		?string $alias,
		int $published,
		?int $access,
		string $language,
		?string $description,
		?int $numArticles,
		?int $cacheTime,
		?int $ordering,
		?array $tags,
		?string $metadesc,
		?string $metakey
	): array {
		$data = [
			'name'      => $name,
			'link'      => $link,
			'catid'     => $catId,
			'published' => $published,
			'access'    => $access ?? 1,
			'language'  => $language,
			'tags'      => $tags !== null ? TagIds::fromMixed($tags) : [],
			// `#__newsfeeds`.`params`/`metadata`/`images`/`description`/`metadesc` are all `text NOT NULL`
			// with no column default — and, unlike `ContactTable::check()` (which defaults every one of its
			// equivalent empty text columns to `'{}'`/`''`), `NewsfeedTable::check()` has none of those
			// fallbacks. A real backend form submission always posts every one of these fields (even a
			// `useglobal="true"` <select> left at "Use Global" still posts its current, empty value), so
			// this gap is never hit through the UI. `Form::filter()` only ever emits an output key for a
			// field — grouped ('params.*', 'metadata.*', 'images.*') or not ('description', 'metadesc') —
			// when the corresponding path already exists in the given data (see `Form::filter()`'s
			// `$input->exists($key)` guard); leaving any of them out of $data entirely (as "never send
			// fields the caller didn't ask about" would otherwise suggest) leaves the fresh Table's property
			// at its `reset()` value (`null`), and the INSERT fails outright. Every one of these is
			// therefore always sent, explicitly, at its safe "nothing set" value — every `params.*` field at
			// "use global" (`''`), the two nested groups otherwise empty, matching what a real submission
			// with nothing filled in would send.
			'params'    => [
				'show_feed_image'       => '',
				'show_feed_description' => '',
				'show_item_description' => '',
				'feed_character_count'  => '',
				'newsfeed_layout'       => '',
				'feed_display_order'    => '',
			],
			'metadata'  => [
				'robots' => '',
				'rights' => '',
			],
			'images'    => [
				'image_first'  => '',
				'image_second' => '',
			],
			'description' => $description ?? '',
			'metadesc'    => $metadesc ?? '',
			// "Never generate an alias in PHP" (see AbstractTool's docblock) — but, unlike
			// `Table\Category::check()`/`ContactTable::generateAlias()` (both null-guard with `empty()`
			// before deriving one from the title/name), `NewsfeedModel::prepareTable()` calls
			// `ApplicationHelper::stringURLSafe($table->alias, ...)` directly, with NO such guard, and runs
			// BEFORE `NewsfeedTable::check()` (which does have the guard) ever gets a chance to run. Leaving
			// `alias` unset here means `Table::bind()` never touches the fresh Table's `alias` property,
			// which is `null` after `reset()` (`getTableColumns()` reports a `DEFAULT ''` text column's
			// default as PHP `null`, not `''` — a genuine driver-introspection quirk, not a Joomla bug) —
			// `stringURLSafe(null, ...)` is a PHP 8.1+ "passing null to non-nullable parameter" deprecation
			// that corrupts the JSON-RPC response body. Sending an explicit empty string sidesteps it
			// exactly like `ArticleMapper`/`CategoryMapper` already do for their own, differently-caused,
			// version of the same class of bug — `NewsfeedTable::check()`'s own `empty($this->alias)` guard
			// still runs afterwards and derives the alias from the name identically for `''` as it would
			// have for an omitted value.
			'alias' => $alias ?? '',
		];

		if ($numArticles !== null)
		{
			$data['numarticles'] = $numArticles;
		}

		if ($cacheTime !== null)
		{
			$data['cache_time'] = $cacheTime;
		}

		if ($ordering !== null)
		{
			$data['ordering'] = $ordering;
		}

		if ($metakey !== null)
		{
			$data['metakey'] = $metakey;
		}

		return $data;
	}

	/**
	 * Only non-null parameters are included (null = "don't change" — see `.plans/00-conventions.md` §6).
	 * `tags` is always sent explicitly, same rule as {@see ContactMapper::updateData()}.
	 *
	 * @return  array<string, mixed>
	 */
	public static function updateData(
		?string $name,
		?string $link,
		?int $catId,
		?string $alias,
		?int $published,
		?int $access,
		?string $language,
		?string $description,
		?int $numArticles,
		?int $cacheTime,
		?int $ordering,
		?array $tags,
		?string $metadesc,
		?string $metakey,
		array $currentItem
	): array {
		$data = NullFilter::withoutNulls([
			'name'        => $name,
			'link'        => $link,
			'catid'       => $catId,
			'alias'       => $alias,
			'published'   => $published,
			'access'      => $access,
			'language'    => $language,
			'description' => $description,
			'numarticles' => $numArticles,
			'cache_time'  => $cacheTime,
			'ordering'    => $ordering,
			'metadesc'    => $metadesc,
			'metakey'     => $metakey,
		]);

		$data['tags'] = $tags !== null ? TagIds::fromMixed($tags) : TagIds::fromMixed($currentItem['tags'] ?? null);

		return $data;
	}

	/**
	 * `tags` -> a clean `list<int>`. `NewsfeedModel::getItem()` copies its `TagsHelper` into
	 * `metadata['tags']` for every client EXCEPT the `api` one it detects via
	 * `Factory::getApplication()->isClient('api')` — since our own request context is not guaranteed to
	 * report as that client in every supported Joomla version (and doing so is an internal implementation
	 * detail this tool must not depend on), `metadata['tags']` is unconditionally stripped here: it must
	 * never be part of the output (a `TagsHelper` object is not JSON-encodable data a caller can use), and
	 * it must never be carried forward into a save payload (the real `tags` key already carries the same
	 * information, correctly normalised).
	 *
	 * @return  array<string, mixed>
	 */
	public static function normaliseItem(array $item): array
	{
		if (array_key_exists('tags', $item))
		{
			$item['tags'] = TagIds::fromMixed($item['tags']);
		}

		if (isset($item['metadata']) && is_array($item['metadata']))
		{
			unset($item['metadata']['tags']);
		}

		return $item;
	}
}
