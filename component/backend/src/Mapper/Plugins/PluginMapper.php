<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Mapper\Plugins;

defined('_JEXEC') || die;

use Akeeba\Component\Mcp4j\Administrator\Bridge\Support\NullFilter;
use Akeeba\Component\Mcp4j\Administrator\Mapper\Config\ConfigDataMapper;
use Akeeba\Component\Mcp4j\Administrator\Mapper\Config\SecretRedactor;

/**
 * Pure input/output mapping for the Plugins tool category: tool parameters -> `PluginsModel`/`PluginModel`
 * state and save data, and the output field whitelists.
 *
 * `ITEM_FIELDS`/`LIST_FIELDS` start from core's own Web Services API view for plugins
 * (`api/components/com_plugins/src/View/Plugins/JsonapiView.php`, `$fieldsToRenderItem`/
 * `$fieldsToRenderList`) plus `note` (present on both core lists already surfaced through the backend UI)
 * and, for the item shape only, `params` — a deliberate addition: `plugins_update` accepts `params`, so an
 * LLM must be able to read them back (see `.plans/00-conventions.md` §5.9); `params` is always passed
 * through {@see SecretRedactor::redactByPattern()} before being returned.
 *
 * Pure: no Joomla.
 *
 * @since  0.0.1
 */
final class PluginMapper
{
	public const ITEM_FIELDS = [
		'id',
		'name',
		'type',
		'element',
		'changelogurl',
		'folder',
		'client_id',
		'enabled',
		'access',
		'protected',
		'checked_out',
		'checked_out_time',
		'ordering',
		'state',
		'note',
		'params',
	];

	public const LIST_FIELDS = [
		'id',
		'name',
		'element',
		'folder',
		'checked_out',
		'checked_out_time',
		'enabled',
		'access',
		'ordering',
		'editor',
		'access_level',
		'note',
	];

	public const INT_FIELDS = ['client_id', 'enabled', 'access', 'protected', 'checked_out', 'ordering', 'state'];

	public const JSON_FIELDS = ['params'];

	/**
	 * {@see self::ITEM_FIELDS} with the leading `id` replaced by the real primary key column
	 * (`extension_id`) — the field list to shape the RAW model item with, BEFORE {@see self::renameId()}
	 * moves that column to a leading `id` (see that method's docblock for why the two steps are separate).
	 */
	public const RAW_ITEM_FIELDS = [
		'extension_id',
		'name',
		'type',
		'element',
		'changelogurl',
		'folder',
		'client_id',
		'enabled',
		'access',
		'protected',
		'checked_out',
		'checked_out_time',
		'ordering',
		'state',
		'note',
		'params',
	];

	public const RAW_LIST_FIELDS = [
		'extension_id',
		'name',
		'element',
		'folder',
		'checked_out',
		'checked_out_time',
		'enabled',
		'access',
		'ordering',
		'editor',
		'access_level',
		'note',
	];

	public const RAW_INT_FIELDS = ['extension_id', 'client_id', 'enabled', 'access', 'protected', 'checked_out', 'ordering', 'state'];

	/**
	 * `[folder, element]` pairs of plugins this MCP session's own connection depends on staying enabled —
	 * disabling any of them mid-conversation would cut the connection off with no way back through MCP
	 * itself. See {@see self::isLockoutDisable()}.
	 */
	public const LOCKOUT_PLUGINS = [
		['webservices', 'mcp4j'],
		['api-authentication', 'token'],
		['user', 'token'],
	];

	/**
	 * Moves the raw `extension_id` primary key column to a leading `id` (cast to int) — kept as a separate
	 * step from shaping (rather than just adding `id` to the field whitelists directly) because
	 * `PluginsModel`/`PluginModel` never return an `id` column at all, only `extension_id`; shaping with
	 * {@see self::RAW_ITEM_FIELDS}/{@see self::RAW_LIST_FIELDS} first keeps that column under its real name
	 * through {@see \Akeeba\Component\Mcp4j\Administrator\Bridge\Support\OutputShaper}'s int coercion, and
	 * this then renames it for the tool's public output shape.
	 *
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
	 * `PluginsModel::getListQuery()` state.
	 *
	 * The original `joomla-mcp-php` project sent `filter[enabled]`/`filter[folder]` to core's Web Services
	 * API, which silently ignored both (it only reads `status`/`type`) — ported here as working filters
	 * instead, an intentional fix (see the plan's deviations list).
	 *
	 * `filter.enabled` null maps to `''`, which `getListQuery()` treats as "0 or 1" (i.e. no filter) rather
	 * than "not set" — `PluginsModel` compares it with `is_numeric()`, so it must always be a string.
	 * `list.direction` is always a literal `'asc'` here: `PluginsModel::_getList()` concatenates
	 * `list.direction` straight into the SQL `ORDER BY` clause with no binding — it must never come from
	 * caller input (see `.plans/00-conventions.md` §5 and the plan's gotchas).
	 *
	 * @return  array<string, mixed>
	 */
	public static function listState(?string $search, ?int $state, ?string $folder, ?string $element): array
	{
		$data = [
			'list.ordering'  => 'name',
			'list.direction' => 'asc',
		];

		if ($search !== null)
		{
			$data['filter.search'] = $search;
		}

		$data['filter.enabled'] = $state !== null ? (string) $state : '';

		if ($folder !== null)
		{
			$data['filter.folder'] = $folder;
		}

		if ($element !== null)
		{
			$data['filter.element'] = $element;
		}

		return $data;
	}

	/**
	 * Only non-null parameters are included (null = "don't change" — `.plans/00-conventions.md` §6).
	 * `params` is normalised from its `array|string|null` tool-parameter shape via
	 * {@see ConfigDataMapper::normaliseConfigData()} and has any `SecretRedactor::PLACEHOLDER` leaves the
	 * caller round-tripped back stripped out via {@see SecretRedactor::stripPlaceholders()} — this is a
	 * deliberate fix over the original, whose equivalent HTTP call core's own Web Services API silently
	 * rejected `params` on (see the plan's deviations list); here it is actually applied.
	 *
	 * @throws  \InvalidArgumentException  `$params` is a string that isn't valid JSON, or isn't a JSON object.
	 *
	 * @return  array<string, mixed>
	 */
	public static function updateData(?int $enabled, ?int $access, ?int $ordering, array|string|null $params): array
	{
		$data = NullFilter::withoutNulls([
			'enabled'  => $enabled,
			'access'   => $access,
			'ordering' => $ordering,
		]);

		if ($params !== null)
		{
			$data['params'] = SecretRedactor::stripPlaceholders(ConfigDataMapper::normaliseConfigData($params));
		}

		return $data;
	}

	/**
	 * True when `$data` would disable one of the three plugins this MCP session's own connection depends
	 * on: the `webservices/mcp4j` plugin routing this very request, and the two authentication plugins
	 * (`api-authentication/token`, `user/token`) needed to issue/validate the bearer token used to reach it.
	 * Only matches an explicit `enabled === 0` — enabling, or leaving `enabled` unset, is always allowed.
	 *
	 * @param   array<string, mixed>  $data  The (already-mapped) save data, as built by {@see self::updateData()}.
	 */
	public static function isLockoutDisable(string $folder, string $element, array $data): bool
	{
		if (!array_key_exists('enabled', $data) || (int) $data['enabled'] !== 0)
		{
			return false;
		}

		foreach (self::LOCKOUT_PLUGINS as [$lockedFolder, $lockedElement])
		{
			if ($folder === $lockedFolder && $element === $lockedElement)
			{
				return true;
			}
		}

		return false;
	}
}
