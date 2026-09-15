<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Mapper\Languages;

defined('_JEXEC') || die;

use Akeeba\Component\Mcp4j\Administrator\Bridge\Support\NullFilter;

/**
 * Pure input/output mapping for the Languages ContentLanguages tool category: tool parameters ->
 * `LanguagesModel`/`LanguageModel` state and save data, and the output field whitelist.
 *
 * `ITEM_FIELDS`/`LIST_FIELDS` are the same list — core's own Web Services API view for content languages
 * (`api/components/com_languages/src/View/Languages/JsonapiView.php`) whitelists an identical set for both
 * shapes.
 *
 * Pure: no Joomla.
 *
 * @since  0.0.1
 */
final class ContentLanguageMapper
{
	public const ITEM_FIELDS = [
		'id',
		'asset_id',
		'lang_code',
		'title',
		'title_native',
		'sef',
		'image',
		'description',
		'metakey',
		'metadesc',
		'sitename',
		'published',
		'access',
		'ordering',
		'access_level',
		'home',
	];

	public const LIST_FIELDS = self::ITEM_FIELDS;

	public const RAW_ITEM_FIELDS = [
		'lang_id',
		'asset_id',
		'lang_code',
		'title',
		'title_native',
		'sef',
		'image',
		'description',
		'metakey',
		'metadesc',
		'sitename',
		'published',
		'access',
		'ordering',
		'access_level',
		'home',
	];

	public const RAW_LIST_FIELDS = self::RAW_ITEM_FIELDS;

	public const INT_FIELDS = ['asset_id', 'published', 'access', 'ordering', 'home'];

	public const RAW_INT_FIELDS = ['lang_id', 'asset_id', 'published', 'access', 'ordering', 'home'];

	/**
	 * @param   array<string, mixed>  $item
	 *
	 * @return  array<string, mixed>
	 */
	public static function renameId(array $item): array
	{
		if (!array_key_exists('lang_id', $item))
		{
			return $item;
		}

		$id = (int) $item['lang_id'];
		unset($item['lang_id']);

		return ['id' => $id] + $item;
	}

	/**
	 * `LanguagesModel::getListQuery()` state. `filter.published` null maps to `''` (no filter — every
	 * state shown), same string-compare convention as every other `filter.published`/`filter.enabled`
	 * state in this plan.
	 *
	 * @return  array<string, mixed>
	 */
	public static function listState(?int $state): array
	{
		return [
			'filter.published' => $state !== null ? (string) $state : '',
			'list.ordering'    => 'a.ordering',
			'list.direction'   => 'ASC',
		];
	}

	/**
	 * Builds the save data for `languages_content_create`. `sef`/`image` are omitted when null (left to
	 * `Table::check()`/the column's own default rather than writing an explicit empty value); `metadesc`/
	 * `sitename` are likewise omitted when null — both are optional form fields with no NOT NULL-without-
	 * default trap (unlike `#__tags.description`, see `Mapper/Tags/TagMapper.php::createData()`'s docblock
	 * for that unrelated, table-specific issue). `metakey` is deliberately NOT part of this return value —
	 * `administrator/components/com_languages/forms/language.xml` has no `metakey` field at all (a known
	 * core limitation, in every supported Joomla version), so it would simply be dropped by
	 * `Form::filter()`; see {@see self::extractMetakey()} for how the tool persists it instead.
	 *
	 * @return  array<string, mixed>
	 */
	public static function createData(
		string $langCode,
		string $title,
		string $titleNative,
		?string $sef,
		?string $image,
		int $published,
		?int $access,
		?string $metadesc,
		?string $sitename
	): array {
		$data = [
			'lang_code'    => $langCode,
			'title'        => $title,
			'title_native' => $titleNative,
			'published'    => $published,
			'access'       => $access ?? 1,
			// #__languages.description is `varchar(512) NOT NULL` with no column default, and there is no
			// tool parameter for it at all (the original didn't have one either) — Table::bind() only ever
			// sets a property when the key is present in the data array, so it must always be present here,
			// or Table::store() fails with a MySQL "Field 'description' doesn't have a default value" error
			// on INSERT. The same class of bug as `Mapper/Tags/TagMapper.php::createData()`'s own
			// `description` field, found the same way (this plan's own E2E suite) — see the final report's
			// deviations list.
			'description'  => '',
		];

		if ($sef !== null)
		{
			$data['sef'] = $sef;
		}

		// #__languages.image is `varchar(50) NOT NULL` with no column default; the form field itself is
		// optional (no `required` attribute) and its own option list even offers an explicit "" ("JNONE")
		// choice — so unlike `sef` (a `required="true"` form field, where omission is properly reported as
		// a validation error instead), `image` must default to '' here rather than being omitted, for the
		// same NOT-NULL-without-default reason as `description` above.
		$data['image'] = $image ?? '';

		// #__languages.metadesc is `text NOT NULL` with no column default — same fix, same reason.
		$data['metadesc'] = $metadesc ?? '';

		if ($sitename !== null)
		{
			$data['sitename'] = $sitename;
		}

		return $data;
	}

	/**
	 * Only non-null parameters are included (null = "don't change" — `.plans/00-conventions.md` §6).
	 * `metakey` is, again, deliberately not part of the return value — see {@see self::createData()}'s
	 * docblock and {@see self::extractMetakey()}.
	 *
	 * @return  array<string, mixed>
	 */
	public static function updateData(
		?string $langCode,
		?string $title,
		?string $titleNative,
		?string $sef,
		?string $image,
		?int $published,
		?int $access,
		?string $metadesc,
		?string $sitename
	): array {
		return NullFilter::withoutNulls([
			'lang_code'    => $langCode,
			'title'        => $title,
			'title_native' => $titleNative,
			'sef'          => $sef,
			'image'        => $image,
			'published'    => $published,
			'access'       => $access,
			'metadesc'     => $metadesc,
			'sitename'     => $sitename,
		]);
	}

	/**
	 * `#__languages.metakey` has no corresponding field in `forms/language.xml` (in 4.4, 5.4 or 6.1), so it
	 * can never reach `Table::store()` through the normal `AdminModel::save()`/`Form::filter()` path — the
	 * tool persists it with a direct, narrowly-scoped `UPDATE` statement instead (see
	 * `Tool/Languages/ContentLanguages.php`). This helper only decides *whether* that extra step is needed;
	 * an empty string is a legitimate "clear the meta keywords" value and is kept, only `null` ("not given")
	 * is filtered out.
	 */
	public static function extractMetakey(?string $metakey): ?string
	{
		return $metakey;
	}
}
