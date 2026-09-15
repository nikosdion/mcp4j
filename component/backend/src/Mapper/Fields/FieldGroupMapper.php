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
 * Pure input/output mapping for the `fields_groups_*` tools: tool parameters -> `GroupsModel`/`GroupModel`
 * state and save data, and the output field whitelists.
 *
 * `ITEM_FIELDS` is copied from Joomla core's own Web Services API view for field groups
 * (`api/components/com_fields/src/View/Groups/JsonapiView.php::$fieldsToRenderItem`), minus `typeAlias`
 * and `type` (neither is present on the array `GroupModel::getItem()` normalises to). `LIST_FIELDS`
 * deliberately does NOT come from that view's `$fieldsToRenderList` — it is a verbatim copy-paste of the
 * *fields* list (`name`, `fieldparams`, `type`, `default_value`, `group_id`, ... — none of which are
 * columns on `#__fields_groups`), a bug in Joomla core itself. The real column set comes from
 * `GroupsModel::getListQuery()`'s own `SELECT`/joins instead — see `.plans/12-fields-history-privacy.md`.
 *
 * Pure: no Joomla.
 *
 * @since  0.0.1
 */
final class FieldGroupMapper
{
	public const ITEM_FIELDS = [
		'id',
		'asset_id',
		'context',
		'title',
		'note',
		'description',
		'state',
		'checked_out',
		'checked_out_time',
		'ordering',
		'params',
		'language',
		'created',
		'created_by',
		'modified',
		'modified_by',
		'access',
	];

	public const LIST_FIELDS = [
		'id',
		'asset_id',
		'context',
		'title',
		'note',
		'description',
		'state',
		'access',
		'ordering',
		'language',
		'params',
		'created',
		'created_by',
		'checked_out',
		'checked_out_time',
		'language_title',
		'language_image',
		'editor',
		'access_level',
		'author_name',
	];

	public const INT_FIELDS = ['asset_id', 'state', 'access', 'ordering', 'created_by', 'modified_by', 'checked_out'];

	public const JSON_FIELDS = ['params'];

	/**
	 * `GroupsModel::getListQuery()` state. `filter.context` is always set — `GroupsModel` defaults it to
	 * `com_fields` when unset, which is wrong for every real context we're asked for; `ignore_request =>
	 * true` means we must always provide it ourselves — see `.plans/00-conventions.md` §5.4.
	 *
	 * @return  array<string, mixed>
	 */
	public static function listState(string $context, ?string $search, ?int $state): array
	{
		$data = [
			'filter.context' => $context,
			'list.ordering'  => 'a.ordering',
			'list.direction' => 'ASC',
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
	 * @throws  \InvalidArgumentException  `$params` is a string that isn't valid JSON.
	 */
	public static function createData(
		string $context,
		string $title,
		?string $description,
		int $state,
		?int $access,
		string $language,
		?string $note,
		array|string|null $params
	): array {
		$data = [
			'context'  => $context,
			'title'    => $title,
			'state'    => $state,
			'access'   => $access ?? 1,
			'language' => $language,
			// #__fields_groups.description is `text NOT NULL` with no column default, and
			// GroupTable::check() never fills a missing value in for it (it only defaults `params`) —
			// same trap as #__tags.description (see Mapper/Tags/TagMapper.php's own createData()). A
			// real backend form always posts this field (even empty), so it must always be present here
			// too, or Table::store() fails with a MySQL "Field 'description' doesn't have a default
			// value" error on INSERT. Found by this plan's own E2E suite.
			'description' => $description ?? '',
		];

		if ($note !== null)
		{
			$data['note'] = $note;
		}

		// #__fields_groups.params is also `text NOT NULL` with no column default, and `params` is a
		// *nested* form field group (`<fields name="params">` in group.xml) — `Form::filter()` builds its
		// output field-by-field, each gated on `Registry::exists('params.<subfield>')` (`isset()`-based),
		// so an empty `params` array has no subfields that "exist" and the entire `params` key is absent
		// from $valid afterwards, not merely empty — same failure mode as `description` above ("Field
		// 'params' doesn't have a default value"). `display_readonly` is that group's only field, present
		// in every one of Joomla 4.4/5.4/6.1's `group.xml` alike (verified against all three local dev
		// sites); its own `default="1"` is what an un-submitted form would send, so default to that
		// (matching what GroupTable::check() would otherwise have defaulted `params` itself to, '{}',
		// only when the property is untouched) rather than to `''`/`0`.
		$decoded = self::decodeJsonParam($params, 'params') ?? [];

		if (!array_key_exists('display_readonly', $decoded))
		{
			$decoded['display_readonly'] = 1;
		}

		$data['params'] = $decoded;

		return $data;
	}

	/**
	 * Only non-null parameters are included (null = "don't change" — see `.plans/00-conventions.md` §6);
	 * `context` is deliberately never one of the keys here — the calling tool adds the record's stored
	 * context back onto the data itself.
	 *
	 * @return  array<string, mixed>
	 *
	 * @throws  \InvalidArgumentException  `$params` is a string that isn't valid JSON.
	 */
	public static function updateData(
		?string $title,
		?string $description,
		?int $state,
		?int $access,
		?string $language,
		?string $note,
		array|string|null $params
	): array {
		return NullFilter::withoutNulls([
			'title'       => $title,
			'description' => $description,
			'state'       => $state,
			'access'      => $access,
			'language'    => $language,
			'note'        => $note,
			'params'      => self::decodeJsonParam($params, 'params'),
		]);
	}

	/**
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
