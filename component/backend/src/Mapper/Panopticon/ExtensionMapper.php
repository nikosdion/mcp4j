<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Mapper\Panopticon;

defined('_JEXEC') || die;

/**
 * Pure input/output mapping for the Panopticon Connector's `extensions`/`extension` tools.
 *
 * `LIST_FIELDS`/`ITEM_FIELDS` are copied verbatim from the connector's own
 * `component/api/src/View/Extensions/JsonapiView.php` `$fieldsToRenderList`/`$fieldsToRenderItem` — see
 * `.plans/14-panopticon-core.md`. {@see self::sanitise()} additionally masks the secrets nested inside
 * those fields (`updatesites[*].extra_query`, `downloadkey.value`) that the connector's own view does
 * *not* redact — see the plan's "Sensitive output" section.
 *
 * Pure: no Joomla.
 *
 * @since  0.0.1
 */
final class ExtensionMapper
{
	public const LIST_FIELDS = [
		'extension_id',
		'package_id',
		'type',
		'folder',
		'element',
		'client_id',
		'client_translated',
		'type_translated',
		'folder_translated',
		'state',
		'enabled',
		'access',
		'protected',
		'locked',
		'name',
		'description',
		'author',
		'authorUrl',
		'authorEmail',
		'version',
		'new_version',
		'detailsurl',
		'infourl',
		'changelogurl',
		'updatesites',
		'downloadkey',
		'naughtyUpdates',
	];

	public const ITEM_FIELDS = [
		'extension_id',
		'package_id',
		'type',
		'folder',
		'element',
		'client_id',
		'client_translated',
		'type_translated',
		'folder_translated',
		'state',
		'enabled',
		'access',
		'protected',
		'locked',
		'name',
		'description',
		'author',
		'authorUrl',
		'authorEmail',
		'version',
		'new_version',
		'detailsurl',
		'infourl',
		'changelogurl',
		'updatesites',
		'downloadkey',
	];

	public const INT_FIELDS = ['extension_id', 'package_id', 'client_id', 'state', 'enabled', 'access', 'protected', 'locked'];

	public const UPDATE_SITE_FIELDS = ['update_site_id', 'name', 'type', 'location', 'enabled', 'last_check_timestamp', 'extra_query'];

	private const UPDATE_SITE_INT_FIELDS = ['update_site_id', 'enabled', 'last_check_timestamp'];

	private const DOWNLOAD_KEY_FIELDS = ['supported', 'valid', 'prefix', 'suffix'];

	/**
	 * @param   array<string, mixed>  $item  A normalised extension item (see
	 *                                       {@see \Akeeba\Component\Mcp4j\Administrator\Bridge\Support\ItemNormalizer::toArray()}).
	 *
	 * @return  array<string, mixed>
	 */
	public static function sanitise(array $item): array
	{
		if (isset($item['updatesites']) && is_array($item['updatesites']))
		{
			$item['updatesites'] = array_map([self::class, 'sanitiseUpdateSite'], array_values($item['updatesites']));
		}

		if (isset($item['downloadkey']) && is_array($item['downloadkey']))
		{
			$item['downloadkey'] = self::sanitiseDownloadKey($item['downloadkey']);
		}

		if (array_key_exists('extra_query', $item) && is_string($item['extra_query']))
		{
			$item['extra_query'] = SecretMasker::mask($item['extra_query']);
		}

		return $item;
	}

	/**
	 * Post-filters and paginates an already-fetched (and normalised) extension list — the connector's own
	 * `ExtensionsModel` has no search/type filtering and refreshes remote update information on every
	 * `getTotal()` call, so the tool fetches everything once and this method does the rest in memory; see
	 * the plan's `listExtensions()` pseudocode.
	 *
	 * @param   list<array<string, mixed>>  $items
	 *
	 * @return  array{items: list<array<string, mixed>>, total: int}
	 */
	public static function filterAndPaginate(array $items, ?string $search, ?string $type, int $limit, int $offset): array
	{
		$filtered = array_values(array_filter(
			$items,
			static function (array $item) use ($search, $type): bool {
				if ($search !== null && $search !== '')
				{
					$name    = (string) ($item['name'] ?? '');
					$element = (string) ($item['element'] ?? '');

					if (!self::containsCaseInsensitive($name, $search) && !self::containsCaseInsensitive($element, $search))
					{
						return false;
					}
				}

				if ($type !== null && $type !== '' && strcasecmp((string) ($item['type'] ?? ''), $type) !== 0)
				{
					return false;
				}

				return true;
			}
		));

		return [
			'items' => array_values(array_slice($filtered, $offset, $limit)),
			'total' => count($filtered),
		];
	}

	/**
	 * @param   array<string, mixed>  $site
	 *
	 * @return  array<string, mixed>
	 */
	private static function sanitiseUpdateSite(mixed $site): array
	{
		$site = is_array($site) ? $site : [];

		$shaped = [];

		foreach (self::UPDATE_SITE_FIELDS as $field)
		{
			if (array_key_exists($field, $site))
			{
				$shaped[$field] = $site[$field];
			}
		}

		foreach (self::UPDATE_SITE_INT_FIELDS as $field)
		{
			if (array_key_exists($field, $shaped) && $shaped[$field] !== null && is_numeric($shaped[$field]))
			{
				$shaped[$field] = (int) $shaped[$field];
			}
		}

		if (array_key_exists('extra_query', $shaped) && is_string($shaped['extra_query']))
		{
			$shaped['extra_query'] = SecretMasker::mask($shaped['extra_query']);
		}

		return $shaped;
	}

	/**
	 * @param   array<string, mixed>  $downloadKey
	 *
	 * @return  array<string, mixed>
	 */
	private static function sanitiseDownloadKey(array $downloadKey): array
	{
		$shaped = [];

		foreach (self::DOWNLOAD_KEY_FIELDS as $field)
		{
			if (array_key_exists($field, $downloadKey))
			{
				$shaped[$field] = $downloadKey[$field];
			}
		}

		if (array_key_exists('value', $downloadKey))
		{
			$shaped['value'] = SecretMasker::mask(is_string($downloadKey['value']) ? $downloadKey['value'] : null);
		}

		return $shaped;
	}

	private static function containsCaseInsensitive(string $haystack, string $needle): bool
	{
		if ($needle === '')
		{
			return true;
		}

		return function_exists('mb_stripos')
			? mb_stripos($haystack, $needle) !== false
			: stripos($haystack, $needle) !== false;
	}
}
