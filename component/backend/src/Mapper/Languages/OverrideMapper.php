<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Mapper\Languages;

defined('_JEXEC') || die;

/**
 * Pure input/output mapping for the language override tool category (`Tool/Languages/Overrides.php`):
 * language tag/key validation, search-type normalisation, and the small record/list shapes the tool
 * returns — overrides live in `.ini` files, not a database table, so there is no `ITEM_FIELDS`/output
 * whitelist here the way other categories have one; {@see self::toRecord()} builds the entire (already
 * safe) output shape directly.
 *
 * Pure: no Joomla.
 *
 * @since  0.0.1
 */
final class OverrideMapper
{
	public const CLIENTS = ['site', 'administrator'];

	/**
	 * `OverrideModel::save()` refuses these as a constant name (case-sensitive, upper-case) — see
	 * `administrator/components/com_languages/src/Model/OverrideModel.php::save()`.
	 */
	public const RESERVED_KEYS = ['YES', 'NO', 'NULL', 'FALSE', 'ON', 'OFF', 'NONE', 'TRUE'];

	public const SEARCH_TYPES = ['constant', 'value'];

	/**
	 * Shape-only check for a language tag ("well formed", not "installed on this site" — that further
	 * check needs `LanguageHelper::getKnownLanguages()`, which is Joomla-coupled and lives on the tool
	 * class instead). Deliberately strict (lower-case primary subtag, no `.`/`/` allowed anywhere) so a
	 * value that was never a plausible language tag to begin with can never reach the filesystem paths
	 * `Tool/Languages/Overrides.php` builds from it — see `.plans/11-overrides-redirects-messages.md`
	 * Part A fact 2.
	 */
	public static function isWellFormedLanguageTag(string $tag): bool
	{
		return (bool) preg_match('/^[a-z]{2,3}(-[A-Za-z0-9]{2,8}){1,2}$/', $tag);
	}

	/**
	 * `LanguagesHelper::filterKey()` (`strtoupper(cmd filter)`) mirrored here so a tool can validate
	 * *before* ever touching a model/form, and reject reserved words the core filter itself does not
	 * catch (that check lives in `OverrideModel::save()`, further downstream).
	 *
	 * @throws  \InvalidArgumentException  Not `[A-Z0-9_]+` after normalisation, or a reserved word.
	 */
	public static function normaliseKey(string $key): string
	{
		$normalised = strtoupper(trim($key));

		if ($normalised === '' || !preg_match('/^[A-Z0-9_]+$/', $normalised))
		{
			throw new \InvalidArgumentException(sprintf(
				'"%s" is not a valid language constant (only upper-case letters, digits and underscores are allowed).',
				$key
			));
		}

		if (in_array($normalised, self::RESERVED_KEYS, true))
		{
			throw new \InvalidArgumentException(sprintf('"%s" is a reserved word and cannot be used as a language constant.', $normalised));
		}

		return $normalised;
	}

	/**
	 * @throws  \InvalidArgumentException  `$type` is given but not one of {@see self::SEARCH_TYPES}.
	 */
	public static function normaliseSearchType(?string $type): string
	{
		if ($type === null)
		{
			return 'value';
		}

		if (!in_array($type, self::SEARCH_TYPES, true))
		{
			throw new \InvalidArgumentException(sprintf('"%s" is not a valid search type (expected "constant" or "value").', $type));
		}

		return $type;
	}

	/**
	 * @return  array{key: string, value: string, language: string, client: string}
	 */
	public static function toRecord(string $key, string $value, string $language, string $client): array
	{
		return [
			'key'      => $key,
			'value'    => $value,
			'language' => $language,
			'client'   => $client,
		];
	}

	/**
	 * @param   array<string, string>  $strings  `['KEY' => 'value', ...]`, as returned by
	 *                                           `LanguageHelper::parseIniFile()`/`OverridesModel::getOverrides()`.
	 *
	 * @return  list<array{key: string, value: string}>
	 */
	public static function toListItems(array $strings): array
	{
		$items = [];

		foreach ($strings as $key => $value)
		{
			$items[] = ['key' => (string) $key, 'value' => (string) $value];
		}

		return $items;
	}

	/**
	 * Strips `$root` (and a leading path separator) off the front of `$absolute`, so search results never
	 * leak the server's absolute filesystem layout (see `.plans/00-conventions.md` §5.9 and this plan's
	 * "Sensitive output" section). A path that isn't under `$root` (or `$root` itself is empty) is
	 * returned unchanged rather than guessed at.
	 */
	public static function relativePath(string $absolute, string $root): string
	{
		$root = rtrim($root, '/\\');

		if ($root === '' || !str_starts_with($absolute, $root))
		{
			return $absolute;
		}

		return ltrim(substr($absolute, strlen($root)), '/\\');
	}

	/**
	 * `OverrideModel::save()`'s own client encoding (a truthy/falsy int, not the string every other
	 * method on that model and on `OverridesModel` uses) — see
	 * `.plans/11-overrides-redirects-messages.md` Part A fact 3.
	 */
	public static function clientInt(string $client): int
	{
		return $client === 'administrator' ? 1 : 0;
	}
}
