<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Mapper\ContentHistory;

defined('_JEXEC') || die;

/**
 * Pure input/output mapping for the `contenthistory_*` tools: the `resource` parameter -> a content type
 * alias, `item_id`/`h.item_id` key building, and the output field whitelist for `#__history` rows.
 *
 * The original `joomla-mcp-php` project's `resource` parameter mirrors a Web Services API URL segment
 * (`content/articles`, `contacts`, `banners`) — the only three resources that actually register
 * `contenthistory` routes in core (see `plugins/webservices/{content,contact,banners}/src/Extension/*.php`).
 * We call `com_contenthistory`'s `HistoryModel` directly, so any valid type alias also works (e.g.
 * `com_content.category`, which core's own history routes never exposed at all) — see
 * `.plans/12-fields-history-privacy.md` deviation 2.
 *
 * `LIST_FIELDS` is built from `HistoryModel::getListQuery()`'s own `SELECT` list, not core's Web Services
 * API view for history (`api/components/com_contenthistory/src/View/History/JsonapiView.php`), whose
 * `$fieldsToRenderList` includes `ucm_item_id`/`ucm_type_id` — columns the model itself never selects, in
 * any of the three supported Joomla versions — so exposing them there would always be a silent no-op.
 * `is_current` exists as a table column, and is selected by `getListQuery()`, only from Joomla 6.0 onward;
 * on 4.4/5.4 the raw row simply never has that key, and `OutputShaper::shape()` already omits any field
 * that isn't present rather than erroring — no version branching is needed here.
 *
 * Pure: no Joomla.
 *
 * @since  0.0.1
 */
final class HistoryResourceMapper
{
	public const RESOURCE_ALIASES = [
		'content/articles' => 'com_content.article',
		'contacts'         => 'com_contact.contact',
		'banners'          => 'com_banners.banner',
	];

	/**
	 * The whitelist of a `#__history` row's fields, in the order `HistoryModel::getListQuery()` selects
	 * them, with `h.version_id` (the primary key) under its output name `id`. Tool code that shapes a raw
	 * row (whose primary key column really is named `version_id`) must substitute `'version_id'` for the
	 * leading `'id'` before shaping, then rename the resulting `version_id` output key back to `id` with
	 * {@see self::renameId()} — see `Tool/ContentHistory/ContentHistory.php`.
	 */
	public const LIST_FIELDS = [
		'id',
		'item_id',
		'version_note',
		'save_date',
		'editor_user_id',
		'editor',
		'character_count',
		'sha1_hash',
		'version_data',
		'keep_forever',
		'is_current',
	];

	public const INT_FIELDS = ['editor_user_id', 'character_count', 'keep_forever', 'is_current'];

	public const JSON_FIELDS = ['version_data'];

	/**
	 * @throws  \InvalidArgumentException  `$resource` is neither a known resource shortcut nor a valid type
	 *                                     alias.
	 */
	public static function toTypeAlias(string $resource): string
	{
		$trimmed = trim($resource, '/');

		if (str_starts_with($trimmed, 'v1/'))
		{
			$trimmed = substr($trimmed, 3);
		}

		if (isset(self::RESOURCE_ALIASES[$trimmed]))
		{
			return self::RESOURCE_ALIASES[$trimmed];
		}

		if (preg_match('/^com_[a-z0-9_]+\.[a-z0-9_]+$/', $trimmed))
		{
			return $trimmed;
		}

		throw new \InvalidArgumentException(sprintf(
			'Unsupported resource: %s. Use content/articles, contacts, banners or a type alias such as com_content.article.',
			$resource
		));
	}

	public static function itemId(string $typeAlias, int $itemId): string
	{
		return $typeAlias . '.' . $itemId;
	}

	/**
	 * Moves a shaped row's `version_id` key to a leading `id` key (cast to int) — the counterpart to the
	 * substitution described on {@see self::LIST_FIELDS}.
	 *
	 * @param   array<string, mixed>  $row
	 *
	 * @return  array<string, mixed>
	 */
	public static function renameId(array $row): array
	{
		if (!array_key_exists('version_id', $row))
		{
			return $row;
		}

		$id = (int) $row['version_id'];
		unset($row['version_id']);

		return ['id' => $id] + $row;
	}
}
