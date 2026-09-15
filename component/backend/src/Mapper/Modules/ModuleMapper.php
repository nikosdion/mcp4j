<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Mapper\Modules;

defined('_JEXEC') || die;

use Akeeba\Component\Mcp4j\Administrator\Bridge\Support\NullFilter;

/**
 * Pure input/output mapping for the Modules tool category (`SiteModules`/`AdminModules`, both client ids):
 * tool parameters -> `ModulesModel`/`ModuleModel`/`SelectModel` state and save data, and the output field
 * whitelists.
 *
 * `ITEM_FIELDS`/`LIST_FIELDS` are copied verbatim from Joomla core's own Web Services API view for modules
 * (`api/components/com_modules/src/View/Modules/JsonapiView.php`, `$fieldsToRenderItem`/
 * `$fieldsToRenderList`) minus `xml` (a `SimpleXMLElement`, huge, and dropped by the bridge's normaliser
 * anyway) and `typeAlias`/`asset_id` (internal) — see `.plans/07-modules-templates.md` Part A.
 *
 * Pure: no Joomla.
 *
 * @since  0.0.1
 */
final class ModuleMapper
{
	/**
	 * Copied verbatim from `api/components/com_modules/src/View/Modules/JsonapiView.php::$fieldsToRenderItem`,
	 * minus `xml`/`typeAlias`/`asset_id`.
	 */
	public const ITEM_FIELDS = [
		'id',
		'title',
		'note',
		'content',
		'ordering',
		'position',
		'checked_out',
		'checked_out_time',
		'publish_up',
		'publish_down',
		'published',
		'module',
		'access',
		'showtitle',
		'params',
		'client_id',
		'language',
		'assigned',
		'assignment',
		'associations',
	];

	/**
	 * Copied verbatim from `api/components/com_modules/src/View/Modules/JsonapiView.php::$fieldsToRenderList`,
	 * minus `language_image`.
	 */
	public const LIST_FIELDS = [
		'id',
		'title',
		'note',
		'position',
		'module',
		'language',
		'checked_out',
		'checked_out_time',
		'published',
		'enabled',
		'access',
		'ordering',
		'publish_up',
		'publish_down',
		'language_title',
		'editor',
		'access_level',
		'pages',
		'name',
	];

	public const INT_FIELDS = ['ordering', 'checked_out', 'published', 'access', 'showtitle', 'client_id', 'enabled'];

	public const JSON_FIELDS = ['params'];

	/**
	 * The only values `ModuleModel::save()` treats meaningfully for `$data['assignment']` — see
	 * `.plans/07-modules-templates.md` Part A "Key facts" §5. `'-'` is deliberately a non-numeric string
	 * ("no pages"), never cast to int.
	 */
	public const ASSIGNMENTS = ['0', '-', '1', '-1'];

	/**
	 * `ModulesModel::getListQuery()` state — `filter.state` (not `filter.published`) defaults to `''`
	 * (published + unpublished) when the caller gave no explicit value, matching the backend list's own
	 * default on Joomla 5.4/6.x; see `.plans/07-modules-templates.md` Part A "Key facts" §2. `client_id`
	 * is always set explicitly since `populateState()` never runs for us (`ignore_request => true`).
	 *
	 * @return  array<string, mixed>
	 */
	public static function listState(
		int $clientId,
		?string $search,
		?int $state,
		?string $position,
		?string $module,
		?int $access
	): array {
		$data = [
			'client_id'      => $clientId,
			'list.ordering'  => 'a.position',
			'list.direction' => 'asc',
		];

		if ($search !== null)
		{
			$data['filter.search'] = $search;
		}

		$data['filter.state'] = $state !== null ? (string) $state : '';

		if ($position !== null)
		{
			$data['filter.position'] = $position;
		}

		if ($module !== null)
		{
			$data['filter.module'] = $module;
		}

		if ($access !== null)
		{
			$data['filter.access'] = $access;
		}

		return $data;
	}

	/**
	 * @return  array<string, mixed>
	 */
	public static function createData(
		int $clientId,
		string $title,
		string $module,
		int $published,
		?int $access,
		?string $position,
		?string $note,
		?int $ordering,
		string $language,
		?string $assignment,
		?array $params
	): array {
		$data = [
			'client_id'  => $clientId,
			'title'      => $title,
			'module'     => $module,
			'published'  => $published,
			'access'     => $access ?? 1,
			'position'   => $position ?? '',
			'language'   => $language,
			'showtitle'  => 1,
			'assignment' => self::normaliseAssignment($assignment) ?? '0',
			// #__modules.params is `text NOT NULL` with no column default, and Form::filter() only ever
			// produces a top-level "params" array when the incoming data already has one to recurse into —
			// an entirely missing key here leaves ModuleTable::bind() with nothing to set, and
			// Table::store() then fails with a MySQL "Field 'params' doesn't have a default value" error on
			// INSERT. This was found by this plan's own E2E suite; always present, even when empty, exactly
			// like TagMapper::createData()'s "description" field for the same reason.
			'params'     => $params ?? [],
			// "content" is a real (non-"params"-group) form field on both module.xml/moduleadmin.xml, not
			// exposed by this tool (see .plans/07-modules-templates.md Part A deviations), but the same
			// missing-key-means-absent-from-filtered-output rule applies to it too. Left entirely unset, it
			// reaches Joomla 4.4's (only — 5.4/6.x already guard with `!empty($this->content) &&`, see
			// libraries/src/Table/Module.php) `strlen($this->content) > 65535` check as `strlen(null)`, a PHP
			// 8.1+ deprecation notice that gets echoed into (and corrupts) the JSON-RPC response body — the
			// same class of bug as TagMapper::listState()'s "filter.extension" fix. Found by this plan's own
			// E2E suite; always present, even empty, on every supported version rather than branching on
			// JVERSION.
			'content'    => '',
		];

		if ($note !== null)
		{
			$data['note'] = $note;
		}

		if ($ordering !== null)
		{
			$data['ordering'] = $ordering;
		}

		return $data;
	}

	/**
	 * Only non-null parameters are included (null = "don't change" — see `.plans/00-conventions.md` §6).
	 * `module` is deliberately not a parameter here — the tool layer rejects an attempt to change it before
	 * this is ever called (see `.plans/07-modules-templates.md` Part A deviations §2).
	 *
	 * @return  array<string, mixed>
	 */
	public static function updateData(
		?string $title,
		?int $published,
		?int $access,
		?string $position,
		?string $note,
		?int $ordering,
		?string $language,
		?string $assignment,
		?array $params
	): array {
		return NullFilter::withoutNulls([
			'title'      => $title,
			'published'  => $published,
			'access'     => $access,
			'position'   => $position,
			'note'       => $note,
			'ordering'   => $ordering,
			'language'   => $language,
			'assignment' => self::normaliseAssignment($assignment),
			'params'     => $params,
		]);
	}

	/**
	 * @throws  \InvalidArgumentException  `$assignment` is non-null and not one of {@see self::ASSIGNMENTS}.
	 */
	public static function normaliseAssignment(?string $assignment): ?string
	{
		if ($assignment === null)
		{
			return null;
		}

		$trimmed = trim($assignment);

		if (!in_array($trimmed, self::ASSIGNMENTS, true))
		{
			throw new \InvalidArgumentException(sprintf(
				'Invalid assignment "%s"; expected one of: %s.',
				$assignment,
				implode(', ', self::ASSIGNMENTS)
			));
		}

		return $trimmed;
	}
}
