<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Mapper\Banners;

defined('_JEXEC') || die;

use Akeeba\Component\Mcp4j\Administrator\Bridge\Support\NullFilter;

/**
 * Pure input/output mapping for `Tool/Banners/Banners.php`.
 *
 * `ITEM_FIELDS`/`LIST_FIELDS` are Joomla core's own Web Services API view for banners
 * (`api/components/com_banners/src/View/Banners/JsonapiView.php`) minus `typeAlias` and
 * `contenthistoryHelper` (internal, never meant for output) — see `.plans/08-banners.md`. Banners have no
 * `tags` field at all (unlike categories/articles) and no `metadesc` field — both are refused outright by
 * {@see self::unsupported()} rather than silently discarded the way Joomla's own form would.
 *
 * Pure: no Joomla.
 *
 * @since  0.0.1
 */
final class BannerMapper
{
	public const ITEM_FIELDS = [
		'id', 'name', 'alias', 'catid', 'state', 'cid', 'type', 'imptotal', 'impmade',
		'clicks', 'clickurl', 'description', 'custombannercode', 'sticky', 'ordering', 'metakey', 'params',
		'own_prefix', 'metakey_prefix', 'purchase_type', 'track_clicks', 'track_impressions', 'checked_out',
		'checked_out_time', 'publish_up', 'publish_down', 'reset', 'created', 'language', 'created_by',
		'created_by_alias', 'modified', 'modified_by', 'version',
	];

	public const LIST_FIELDS = [
		'id', 'name', 'alias', 'checked_out', 'checked_out_time', 'catid', 'category_title',
		'clicks', 'metakey', 'sticky', 'impmade', 'imptotal', 'state', 'ordering', 'purchase_type', 'language',
		'language_image', 'publish_up', 'publish_down', 'editor', 'client_name', 'client_purchase_type',
	];

	public const INT_FIELDS = [
		'catid', 'state', 'cid', 'type', 'imptotal', 'impmade', 'clicks', 'sticky', 'ordering',
		'own_prefix', 'purchase_type', 'track_clicks', 'track_impressions', 'checked_out', 'created_by',
		'modified_by', 'version', 'client_purchase_type',
	];

	public const JSON_FIELDS = ['params'];

	/**
	 * `BannersModel::getListQuery()` state — see `.plans/08-banners.md`. `filter.published` (not
	 * `filter.state` — that key belongs to `ClientsModel`, a different model entirely, see the Gotchas
	 * section) is always set, to `''` when `$state` is null, so the model shows published + unpublished
	 * (states 0 and 1) exactly like the backend list. `filter.category_id`/`filter.client_id` are only set
	 * when given — `getListQuery()` uses `is_numeric()` on the raw state value, and an unset state key
	 * resolves to `null`, which is never numeric.
	 *
	 * @return  array<string, mixed>
	 */
	public static function listState(?string $search, ?int $state, ?int $category, ?int $client): array
	{
		$data = [
			'list.ordering'  => 'a.name',
			'list.direction' => 'ASC',
		];

		if ($search !== null && $search !== '')
		{
			$data['filter.search'] = $search;
		}

		$data['filter.published'] = $state !== null ? (string) $state : '';

		if ($category !== null)
		{
			$data['filter.category_id'] = $category;
		}

		if ($client !== null)
		{
			$data['filter.client_id'] = $client;
		}

		return $data;
	}

	/**
	 * @return  array<string, mixed>
	 */
	public static function createData(
		string $name,
		int $catId,
		?string $alias,
		int $state,
		?int $cid,
		?string $type,
		?string $clickUrl,
		?string $customBannerCode,
		?int $purchaseType,
		?int $trackImpressions,
		?int $trackClicks,
		?string $description,
		string $language,
		?string $metakey
	): array {
		$data = [
			'name'     => $name,
			'catid'    => $catId,
			// Joomla 4.4's Table\BannerTable::check() calls trim($this->alias) with no null-guard (same
			// trap documented in CategoryMapper::createData()/ArticleMapper::createData()) — always sending
			// an explicit empty string avoids the deprecation without generating the alias ourselves.
			'alias'    => $alias ?? '',
			'state'    => $state,
			'language' => $language,
			// #__banners.description/custombannercode/metakey/params are all `text NOT NULL` with NO
			// column default (MySQL cannot give TEXT/BLOB columns a literal default) — and unlike `alias`,
			// nothing in BannerTable::check()/bind() ever fills a missing value in for them. Form::filter()
			// only ever emits a key for a field that was actually present in the submitted data (see
			// `.plans/00-conventions.md` §5.6), so a field we never send is simply absent from the saved
			// row's data, its Table property is left at Joomla's own MySQL-introspected "Default" (NULL —
			// see the note above), and DatabaseDriver::insertObject() silently *skips* any NULL property
			// when building the INSERT column list. The result is a raw MySQL "Field '...' doesn't have a
			// default value" error, not a Joomla-level one. A real backend HTML form always posts these
			// fields (even empty), so they must always be present here too — same class of bug as
			// TagMapper::createData()'s `description` handling, found by this plan's own E2E suite; the
			// original `.plans/08-banners.md` pseudocode had these as "only if non-null" like the other
			// optional text fields, see the final report's deviations list. `params` is never exposed as a
			// tool parameter at all (this port doesn't expose the image/width/height/alt group — see the
			// plan) — see the note on that key below for why it can't just be `''`.
			'description'      => $description ?? '',
			'custombannercode' => $customBannerCode ?? '',
			'metakey'          => $metakey ?? '',
			// A flat `'params' => ''` is NOT enough: `forms/banner.xml` has no `<field name="params">`
			// (only individual fields nested under `<fields name="params">`, i.e. `params.imageurl` etc.),
			// so Form::filter() would drop a flat "params" key exactly like any other key that isn't
			// itself a declared field (`.plans/00-conventions.md` §5.6) — it must be sent as the nested
			// group with every one of its own sub-fields present, so Form::filter() reassembles them into
			// `params.imageurl` etc. and BannerTable::bind() then JSON-serialises the (all-empty) Registry.
			'params'           => ['imageurl' => '', 'width' => '', 'height' => '', 'alt' => ''],
		];

		if ($cid !== null)
		{
			$data['cid'] = $cid;
		}

		if ($type !== null)
		{
			$data['type'] = (int) $type;
		}

		if ($clickUrl !== null)
		{
			$data['clickurl'] = $clickUrl;
		}

		if ($purchaseType !== null)
		{
			$data['purchase_type'] = $purchaseType;
		}

		if ($trackImpressions !== null)
		{
			$data['track_impressions'] = $trackImpressions;
		}

		if ($trackClicks !== null)
		{
			$data['track_clicks'] = $trackClicks;
		}

		return $data;
	}

	/**
	 * Only non-null parameters are included (null = "don't change" — see `.plans/00-conventions.md` §6).
	 *
	 * @return  array<string, mixed>
	 */
	public static function updateData(
		?string $name,
		?string $alias,
		?int $catId,
		?int $state,
		?int $cid,
		?string $type,
		?string $clickUrl,
		?string $customBannerCode,
		?int $purchaseType,
		?int $trackImpressions,
		?int $trackClicks,
		?string $description,
		?string $language,
		?string $metakey
	): array {
		return NullFilter::withoutNulls([
			'name'              => $name,
			'alias'             => $alias,
			'catid'             => $catId,
			'state'             => $state,
			'cid'               => $cid,
			'type'              => $type !== null ? (int) $type : null,
			'clickurl'          => $clickUrl,
			'custombannercode'  => $customBannerCode,
			'purchase_type'     => $purchaseType,
			'track_impressions' => $trackImpressions,
			'track_clicks'      => $trackClicks,
			'description'       => $description,
			'language'          => $language,
			'metakey'           => $metakey,
		]);
	}

	/**
	 * Banners support neither `tags` nor `metadesc` (both are simply absent from `forms/banner.xml`, so
	 * Joomla's own form would silently discard them) — returns the list of parameter names ({@see
	 * \Akeeba\Component\Mcp4j\Administrator\Tool\Banners\Banners} refuses the call outright when this is
	 * non-empty, rather than pretending the value was honoured).
	 *
	 * @return  list<string>
	 */
	public static function unsupported(?array $tags, ?string $metadesc): array
	{
		$unsupported = [];

		if ($tags !== null && $tags !== [])
		{
			$unsupported[] = 'tags';
		}

		if ($metadesc !== null && $metadesc !== '')
		{
			$unsupported[] = 'metadesc';
		}

		return $unsupported;
	}
}
