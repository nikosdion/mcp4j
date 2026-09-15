<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Mapper\Redirects;

defined('_JEXEC') || die;

use Akeeba\Component\Mcp4j\Administrator\Bridge\Support\NullFilter;

/**
 * Pure input/output mapping for the Redirects tool category: tool parameters -> `LinksModel`/`LinkModel`
 * state and save data, and the output field whitelist.
 *
 * `ITEM_FIELDS`/`LIST_FIELDS` are copied from Joomla core's own Web Services API view for redirects
 * (`api/components/com_redirect/src/View/Redirect/JsonapiView.php`) — see `.plans/00-conventions.md`
 * §5.9. `#__redirect_links` has nothing sensitive in it, so both lists are the same, full set of columns.
 *
 * Deliberate deviation from the original `joomla-mcp-php` project's `Redirects` tool (see
 * `.plans/11-overrides-redirects-messages.md` Part B): the original wrote the HTTP status code into the
 * `referer` column ("referring page" — a different field entirely). The status code is stored in the
 * `header` column here, never `referer`.
 *
 * Pure: no Joomla.
 *
 * @since  0.0.1
 */
final class RedirectMapper
{
	public const ITEM_FIELDS = [
		'id',
		'old_url',
		'new_url',
		'referer',
		'comment',
		'hits',
		'published',
		'created_date',
		'modified_date',
		'header',
	];

	public const LIST_FIELDS = self::ITEM_FIELDS;

	public const INT_FIELDS = ['hits', 'published', 'header'];

	/**
	 * `LinksModel::getListQuery()` state: `filter.state` (not `filter.published`; cast to string —
	 * numeric means an equality filter, `''` means published+unpublished only), `filter.search`, and the
	 * default ordering.
	 *
	 * @return  array<string, mixed>
	 */
	public static function listState(?string $search, ?int $state): array
	{
		$data = [
			'list.ordering'  => 'a.old_url',
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
	 */
	public static function createData(string $oldUrl, string $newUrl, ?int $statusCode, int $published, ?string $comment): array
	{
		$data = [
			'old_url'   => $oldUrl,
			'new_url'   => $newUrl,
			'header'    => $statusCode ?? 301,
			'published' => $published,
		];

		if ($comment !== null)
		{
			$data['comment'] = $comment;
		}

		return $data;
	}

	/**
	 * Only non-null parameters are included (null = "don't change" — see `.plans/00-conventions.md` §6).
	 *
	 * @return  array<string, mixed>
	 */
	public static function updateData(?string $oldUrl, ?string $newUrl, ?int $statusCode, ?int $published, ?string $comment): array
	{
		return NullFilter::withoutNulls([
			'old_url'   => $oldUrl,
			'new_url'   => $newUrl,
			'header'    => $statusCode,
			'published' => $published,
			'comment'   => $comment,
		]);
	}
}
