<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Mapper\Privacy;

defined('_JEXEC') || die;

/**
 * Pure input/output mapping for the `privacy_requests_*` tools.
 *
 * `#__privacy_requests` also carries `confirm_token`/`confirm_token_created_at` columns, which
 * `RequestsModel`/`RequestModel` both select via `a.*`/plain `getItem()` — `ITEM_FIELDS`/`LIST_FIELDS`
 * deliberately exclude both (the confirmation token authenticates the requester's own confirm-by-email
 * link) — see `.plans/12-fields-history-privacy.md`.
 *
 * Pure: no Joomla.
 *
 * @since  0.0.1
 */
final class RequestMapper
{
	public const ITEM_FIELDS = ['id', 'email', 'requested_at', 'status', 'request_type'];

	public const LIST_FIELDS = self::ITEM_FIELDS;

	public const INT_FIELDS = ['status'];

	/**
	 * @throws  \InvalidArgumentException  `$type` is neither `1` (export) nor `2` (removal).
	 */
	public static function requestTypeFromInt(int $type): string
	{
		return match ($type)
		{
			1       => 'export',
			2       => 'remove',
			default => throw new \InvalidArgumentException('Invalid request type: use 1 (export) or 2 (removal)'),
		};
	}

	/**
	 * `RequestsModel::getListQuery()` state.
	 *
	 * @return  array<string, mixed>
	 */
	public static function listState(?int $status): array
	{
		return [
			'filter.status'       => $status !== null ? $status : '',
			'filter.request_type' => '',
			'filter.search'       => '',
			'list.ordering'       => 'a.requested_at',
			'list.direction'      => 'DESC',
		];
	}
}
