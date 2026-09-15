<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Mapper\Installer;

defined('_JEXEC') || die;

/**
 * Pure input/output mapping for the Installer Extensions tool category: tool parameters -> `ManageModel`
 * state, and the output field whitelist.
 *
 * `ManageModel::getListQuery()` is `SELECT * FROM #__extensions` (no field whitelist of its own) with the
 * manifest cache's own keys merged straight onto each item by `InstallerModel::translate()` — that
 * includes `params`, `custom_data`, `system_data`, `manifest_cache` (the raw JSON blob itself) and author
 * contact fields, any of which can hold a third-party extension's licence key or other secret, or personal
 * data. {@see self::LIST_FIELDS} is therefore a deliberately narrow whitelist built from core's own
 * `ManageModel` constructor `filter_fields` (identification/status columns) plus the handful of
 * manifest-derived display fields (`version`, `author`, `creationDate`) that carry no secret risk — see
 * `.plans/00-conventions.md` §5.9.
 *
 * Pure: no Joomla.
 *
 * @since  0.0.1
 */
final class ExtensionMapper
{
	public const LIST_FIELDS = [
		'id',
		'name',
		'element',
		'type',
		'folder',
		'client_id',
		'client_translated',
		'type_translated',
		'folder_translated',
		'version',
		'author',
		'creationDate',
		'enabled',
		'protected',
		'locked',
		'status',
		'package_id',
	];

	/**
	 * {@see self::LIST_FIELDS} with the leading `id` replaced by the real primary key column
	 * (`extension_id`) — see {@see \Akeeba\Component\Mcp4j\Administrator\Mapper\Plugins\PluginMapper::renameId()}
	 * for why this two-step (shape with the raw name, then rename) is used.
	 */
	public const RAW_LIST_FIELDS = [
		'extension_id',
		'name',
		'element',
		'type',
		'folder',
		'client_id',
		'client_translated',
		'type_translated',
		'folder_translated',
		'version',
		'author',
		'creationDate',
		'enabled',
		'protected',
		'locked',
		'status',
		'package_id',
	];

	public const INT_FIELDS = ['client_id', 'enabled', 'protected', 'locked', 'status', 'package_id'];

	public const RAW_INT_FIELDS = ['extension_id', 'client_id', 'enabled', 'protected', 'locked', 'status', 'package_id'];

	/**
	 * `#__extensions.type` accepted values — Joomla core's own extension types.
	 */
	public const VALID_TYPES = ['component', 'module', 'plugin', 'template', 'library', 'package', 'file', 'language'];

	/**
	 * @param   array<string, mixed>  $item
	 *
	 * @return  array<string, mixed>
	 */
	public static function renameId(array $item): array
	{
		if (!array_key_exists('extension_id', $item))
		{
			return $item;
		}

		$id = (int) $item['extension_id'];
		unset($item['extension_id']);

		return ['id' => $id] + $item;
	}

	/**
	 * `ManageModel::getListQuery()` state. `filter.status` is compared with `=== '2'`/`=== '3'`
	 * (protected/unprotected) inside the model, so it must always be a string — `''` means "no filter".
	 * `status` `'1'` means *enabled and not protected*; protected core extensions report `status` `2`
	 * regardless of their `enabled` column (see `getListQuery()`'s `2*protected+(1-protected)*enabled AS
	 * status` computed column).
	 *
	 * `filter.search` is always set (to `''` when `$search` is null), unlike most other optional filters in
	 * this plan: Joomla 4.4's `InstallerModel::_getList()` (the shared base `ManageModel` inherits) calls
	 * `str_replace('/', ' ', $this->getState('filter.search'))` with **no default** on `getState()` — since
	 * `ignore_request => true` means `populateState()` (which would otherwise default this to `''`) never
	 * runs, an unset state here is `null`, and `str_replace()` with a `null` subject is a PHP 8.1+
	 * deprecation notice that gets echoed into (and corrupts) the JSON-RPC response body. Joomla 5.4/6.x's
	 * `InstallerModel::_getList()` already passes `''` as its own default, so this key is a no-op there —
	 * set unconditionally rather than branching on `JVERSION`, the same fix `TagMapper::listState()`
	 * applies for the analogous `filter.extension` issue (see that class's own docblock). Found by this
	 * plan's own E2E suite on the Joomla 4.4 matrix entry — see the final report's deviations list.
	 *
	 * @throws  \InvalidArgumentException  `$type` is not one of {@see self::VALID_TYPES}.
	 *
	 * @return  array<string, mixed>
	 */
	public static function listState(?string $search, ?string $type, ?int $state): array
	{
		if ($type !== null && !in_array($type, self::VALID_TYPES, true))
		{
			throw new \InvalidArgumentException(sprintf('Invalid extension type: %s', $type));
		}

		$data = [
			'filter.search'     => $search ?? '',
			'filter.type'       => $type ?? '',
			'filter.status'     => $state !== null ? (string) $state : '',
			'filter.client_id'  => '',
			'filter.folder'     => '',
			'filter.core'       => '',
			'filter.package_id' => '',
			'list.ordering'     => 'name',
			'list.direction'    => 'asc',
		];

		return $data;
	}
}
