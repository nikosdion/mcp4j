<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Mapper\Fields;

defined('_JEXEC') || die;

use Akeeba\Component\Mcp4j\Administrator\Bridge\Support\NullFilter;

/**
 * Pure input/output mapping for the `fields_*` tools: tool parameters -> `FieldsModel`/`FieldModel` state
 * and save data, and the output field whitelists.
 *
 * `ITEM_FIELDS`/`LIST_FIELDS` are copied from Joomla core's own Web Services API view for fields
 * (`api/components/com_fields/src/View/Fields/JsonapiView.php`, `$fieldsToRenderItem`/`$fieldsToRenderList`)
 * — `ITEM_FIELDS` drops `typeAlias` (not present on the array `FieldModel::getItem()` normalises to;
 * `AbstractTool::loadItem()` never carries it) and adds `only_use_in_subform`, which
 * `FieldModel::getItem()` does return but core's own view forgets to whitelist — see
 * `.plans/00-conventions.md` §5.9 and `.plans/12-fields-history-privacy.md`.
 *
 * Pure: no Joomla.
 *
 * @since  0.0.1
 */
final class FieldMapper
{
	public const ITEM_FIELDS = [
		'id',
		'asset_id',
		'context',
		'group_id',
		'title',
		'name',
		'label',
		'default_value',
		'type',
		'note',
		'description',
		'state',
		'required',
		'only_use_in_subform',
		'checked_out',
		'checked_out_time',
		'ordering',
		'params',
		'fieldparams',
		'language',
		'created_time',
		'created_user_id',
		'modified_time',
		'modified_by',
		'access',
		'assigned_cat_ids',
	];

	public const LIST_FIELDS = [
		'id',
		'title',
		'name',
		'checked_out',
		'checked_out_time',
		'note',
		'state',
		'access',
		'created_time',
		'created_user_id',
		'ordering',
		'language',
		'fieldparams',
		'params',
		'type',
		'default_value',
		'context',
		'group_id',
		'label',
		'description',
		'required',
		'language_title',
		'language_image',
		'editor',
		'access_level',
		'author_name',
		'group_title',
		'group_access',
		'group_state',
		'group_note',
	];

	public const INT_FIELDS = [
		'asset_id',
		'group_id',
		'state',
		'required',
		'only_use_in_subform',
		'checked_out',
		'ordering',
		'created_user_id',
		'modified_by',
		'access',
		'group_access',
		'group_state',
	];

	public const JSON_FIELDS = ['params', 'fieldparams'];

	/**
	 * `FieldsModel::getListQuery()` state. `filter.context` is always set — unlike core's own backend list
	 * view, `ignore_request => true` means `populateState()` never defaults it for us — see
	 * `.plans/00-conventions.md` §5.4.
	 *
	 * @return  array<string, mixed>
	 */
	public static function listState(string $context, ?string $search, ?int $state): array
	{
		$data = [
			'filter.context'  => $context,
			'list.ordering'   => 'a.ordering',
			'list.direction'  => 'ASC',
		];

		if ($search !== null)
		{
			$data['filter.search'] = $search;
		}

		$data['filter.state'] = $state !== null ? (string) $state : '';

		return $data;
	}

	/**
	 * @return  array<string, mixed>
	 *
	 * @throws  \InvalidArgumentException  `$params`/`$fieldparams` is a string that isn't valid JSON.
	 */
	public static function createData(
		string $context,
		string $title,
		string $type,
		?string $name,
		?string $label,
		?string $description,
		int $state,
		?int $access,
		?int $group_id,
		string $language,
		?string $default_value,
		array|string|null $params,
		array|string|null $fieldparams
	): array {
		$data = [
			'context'  => $context,
			'title'    => $title,
			'type'     => $type,
			'state'    => $state,
			'access'   => $access ?? 1,
			'group_id' => $group_id ?? 0,
			'language' => $language,
			// #__fields.description is `text NOT NULL` with no column default, and FieldTable::check()
			// never fills a missing value in for it (it only defaults `fieldparams`) — same trap as
			// #__tags.description (see Mapper/Tags/TagMapper.php's own createData()) and
			// #__fields_groups.description (see FieldGroupMapper::createData()). A real backend form
			// always posts this field (even empty), so it must always be present here too, or
			// Table::store() fails with a MySQL "Field 'description' doesn't have a default value" error
			// on INSERT.
			'description' => $description ?? '',
		];

		if ($name !== null)
		{
			$data['name'] = $name;
		}

		if ($label !== null)
		{
			$data['label'] = $label;
		}

		// #__fields.default_value is nullable at the DB level, but `Form::filter()` builds its output via
		// `Registry::exists($key)` (`libraries/src/Form/Form.php::filter()`), which is `isset()`-based and
		// therefore false for a genuine PHP `null` — so a `null` value here is filtered out of $valid
		// exactly as if the key had never been given at all, and FieldModel::checkDefaultValue() then
		// reads $data['default_value'] with plain array access (`$data['default_value'] !== '0'`, no
		// `??`/isset() guard), triggering an "Undefined array key" PHP warning that gets echoed into (and
		// corrupts) the JSON-RPC response body. An empty string survives `Form::filter()` and keeps
		// `checkDefaultValue()`'s own "empty is valid" behaviour, so use that instead of `null`.
		$data['default_value'] = $default_value ?? '';

		// #__fields.params is also `text NOT NULL` with no column default, and nothing defaults it for us
		// (unlike `fieldparams`, which FieldTable::check() defaults to '{}' when empty). Worse: `params`
		// is a *nested* form field group (`<fields name="params">` in field.xml) — `Form::filter()`
		// builds its output field-by-field (e.g. `params.hint`, `params.class`, ...), each gated on
		// `Registry::exists('params.<subfield>')`, so an empty `params` array has *no* subfields that
		// exist and the entire `params` key is absent from $valid, not merely empty — same failure mode
		// as `description`/`default_value` above ("Field 'params' doesn't have a default value"). `hint`
		// is one of that group's static fields, present in every field type in Joomla 4.4 through 6.1
		// alike (verified against all three local dev sites), so forcing it present (defaulting to '',
		// the field's own un-submitted value) guarantees `params` always survives filtering, whatever the
		// caller passed (including nothing at all).
		$decodedParams = self::decodeJsonParam($params, 'params') ?? [];

		if (!array_key_exists('hint', $decodedParams))
		{
			$decodedParams['hint'] = '';
		}

		$data['params'] = $decodedParams;

		$decodedFieldparams = self::decodeJsonParam($fieldparams, 'fieldparams');

		if ($decodedFieldparams !== null)
		{
			$data['fieldparams'] = $decodedFieldparams;
		}

		return $data;
	}

	/**
	 * Only non-null parameters are included (null = "don't change" — see `.plans/00-conventions.md` §6);
	 * `context` is deliberately never one of the keys here — a field's context is never changed on update,
	 * the calling tool adds the record's stored context back onto the data itself.
	 *
	 * @return  array<string, mixed>
	 *
	 * @throws  \InvalidArgumentException  `$params`/`$fieldparams` is a string that isn't valid JSON.
	 */
	public static function updateData(
		?string $title,
		?string $type,
		?string $name,
		?string $label,
		?string $description,
		?int $state,
		?int $access,
		?int $group_id,
		?string $language,
		?string $default_value,
		array|string|null $params,
		array|string|null $fieldparams
	): array {
		return NullFilter::withoutNulls([
			'title'         => $title,
			'type'          => $type,
			'name'          => $name,
			'label'         => $label,
			'description'   => $description,
			'state'         => $state,
			'access'        => $access,
			'group_id'      => $group_id,
			'language'      => $language,
			'default_value' => $default_value,
			'params'        => self::decodeJsonParam($params, 'params'),
			'fieldparams'   => self::decodeJsonParam($fieldparams, 'fieldparams'),
		]);
	}

	/**
	 * Throws the plain SPL `\InvalidArgumentException` rather than the framework's `ToolException` — this
	 * class lives in `Mapper/` and must stay framework-free (see `.plans/00-conventions.md` §3); the
	 * calling tool method is responsible for catching this and re-throwing as a `ToolException`.
	 *
	 * @throws  \InvalidArgumentException
	 */
	private static function decodeJsonParam(array|string|null $value, string $name): ?array
	{
		if ($value === null)
		{
			return null;
		}

		if (is_array($value))
		{
			return $value;
		}

		$decoded = json_decode($value, true);

		if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded))
		{
			throw new \InvalidArgumentException(sprintf('The %s value must be a valid JSON object.', $name));
		}

		return $decoded;
	}
}
