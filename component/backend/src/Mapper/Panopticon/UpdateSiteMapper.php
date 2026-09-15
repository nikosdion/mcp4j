<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Mapper\Panopticon;

defined('_JEXEC') || die;

/**
 * Pure input/output mapping for the Panopticon Connector's `updatesites`/`updatesite` tools.
 *
 * `FIELDS` is copied verbatim from the connector's `component/api/src/View/Updatesites/JsonapiView.php`
 * (list and item field lists are identical there). See `.plans/14-panopticon-core.md`.
 *
 * Pure: no Joomla.
 *
 * @since  0.0.1
 */
final class UpdateSiteMapper
{
	public const FIELDS = [
		'update_site_id',
		'update_site_name',
		'update_site_type',
		'location',
		'enabled',
		'checked_out',
		'checked_out_time',
		'extra_query',
		'extension_id',
		'name',
		'type',
		'element',
		'folder',
		'client_id',
		'state',
		'manifest_cache',
		'editor',
		'downloadKey',
	];

	public const INT_FIELDS = ['update_site_id', 'enabled', 'checked_out', 'extension_id', 'client_id', 'state'];

	public const JSON_FIELDS = ['manifest_cache'];

	private const DOWNLOAD_KEY_FIELDS = ['supported', 'valid', 'prefix', 'suffix'];

	/**
	 * `UpdatesitesModel::getListQuery()` state — see `.plans/14-panopticon-core.md`.
	 *
	 * @return  array<string, mixed>
	 */
	public static function listState(?string $search): array
	{
		return [
			'filter.search'    => $search ?? '',
			'filter.enabled'   => '',
			'filter.supported' => '',
			'filter.type'      => '',
			'filter.client_id' => '',
			'filter.folder'    => '',
			'filter.eid'       => [],
			'list.ordering'    => 'name',
			'list.direction'   => 'asc',
		];
	}

	/**
	 * @param   array<string, mixed>  $item
	 *
	 * @return  array<string, mixed>
	 */
	public static function sanitise(array $item): array
	{
		if (array_key_exists('extra_query', $item) && is_string($item['extra_query']))
		{
			$item['extra_query'] = SecretMasker::mask($item['extra_query']);
		}

		if (isset($item['downloadKey']) && is_array($item['downloadKey']))
		{
			$downloadKey = $item['downloadKey'];
			$shaped      = [];

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

			$item['downloadKey'] = $shaped;
		}

		// Never part of FIELDS, but defensively dropped in case a caller passes the raw (unshaped) item
		// through sanitise() before shape() — see the plan's "Sensitive output" section.
		unset($item['downloadIdPrefix'], $item['downloadIdSuffix']);

		return $item;
	}

	/**
	 * Port of `UpdatesitesController::save()`'s prefix/suffix stripping — Joomla's own base model
	 * re-appends the download-key prefix/suffix to `extra_query` on the next read, so a caller-supplied
	 * `extraQuery` must have them stripped before being persisted.
	 */
	public static function stripAffixes(string $extraQuery, string $prefix, string $suffix): string
	{
		if ($prefix !== '' && str_starts_with($extraQuery, $prefix))
		{
			$extraQuery = substr($extraQuery, strlen($prefix));
		}

		if ($suffix !== '' && str_ends_with($extraQuery, $suffix))
		{
			$extraQuery = substr($extraQuery, 0, -strlen($suffix));
		}

		return $extraQuery;
	}

	/**
	 * @return  list<string>  The subset of `['name', 'location']` that is non-null (i.e. the caller asked
	 *                        to change it) — both are refused; see the plan's `updateUpdateSite()`.
	 */
	public static function unsupportedChanges(?string $name, ?string $location): array
	{
		$changes = [];

		if ($name !== null)
		{
			$changes[] = 'name';
		}

		if ($location !== null)
		{
			$changes[] = 'location';
		}

		return $changes;
	}
}
