<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Mapper\Tickets;

defined('_JEXEC') || die;

/**
 * Pure input/output mapping for the Tickets tool category: tool parameters -> `TicketsModel`/`TicketModel`
 * state and save data, and the output field whitelists.
 *
 * `ITEM_FIELDS`/`LIST_FIELDS` are copied from ATS's own `component/api/src/View/Tickets/JsonapiView.php`
 * (`$fieldsToRenderItem`/`$fieldsToRenderList`) — see `.plans/00-conventions.md` §5.9. Unlike ATS's view,
 * custom field values are surfaced under a `com_fields` key rather than as top-level keys (a field named
 * e.g. `title` would otherwise silently overwrite the ticket's own `title`) — see the plan's deviations.
 *
 * Pure: no Joomla.
 *
 * @since  0.0.1
 */
final class TicketMapper
{
	public const LIST_FIELDS = [
		'id',
		'catid',
		'status',
		'title',
		'alias',
		'public',
		'priority',
		'origin',
		'assigned_to',
		'timespent',
		'created',
		'created_by',
		'modified',
		'enabled',
		'language',
		'tags',
		'created_by_info',
		'assigned_to_info',
		'com_fields',
	];

	public const ITEM_FIELDS = [
		'id',
		'catid',
		'status',
		'title',
		'alias',
		'public',
		'priority',
		'origin',
		'assigned_to',
		'timespent',
		'created',
		'created_by',
		'modified',
		'modified_by',
		'enabled',
		'params',
		'language',
		'tags',
		'created_by_info',
		'modified_by_info',
		'assigned_to_info',
		'com_fields',
		'posts',
	];

	public const INT_FIELDS = ['catid', 'public', 'priority', 'assigned_to', 'created_by', 'modified_by', 'enabled'];

	public const JSON_FIELDS = ['params'];

	/**
	 * Maps a tool's `orderBy` parameter value to the real `TicketsModel` sort column. `created_on`/
	 * `modified_on` — the original's (ignored, by ATS's API) values — are intentionally mapped to the
	 * real `created`/`modified` columns; see the plan's deviations list.
	 */
	public const ORDER_BY = [
		'id'          => 'id',
		'title'       => 'title',
		'status'      => 'status',
		'priority'    => 'priority',
		'catid'       => 'catid',
		'created_by'  => 'created_by',
		'created_on'  => 'created',
		'modified_on' => 'modified',
	];

	/**
	 * `TicketsModel::getListQuery()` state.
	 *
	 * @return  array<string, mixed>
	 * @throws  \InvalidArgumentException  Unknown `$orderBy` or `$orderDirection`.
	 */
	public static function listState(
		?string $search,
		?int $category,
		?string $status,
		?int $user,
		?int $priority,
		?string $orderBy,
		?string $orderDirection
	): array {
		$data = [];

		if ($search !== null)
		{
			$data['filter.search'] = $search;
		}

		if ($category !== null)
		{
			$data['filter.catid'] = $category;
		}

		if ($status !== null)
		{
			$data['filter.status'] = $status;
		}

		if ($user !== null)
		{
			$data['filter.created_by'] = $user;
		}

		if ($priority !== null)
		{
			$data['filter.priority'] = $priority;
		}

		if ($orderBy !== null)
		{
			if (!array_key_exists($orderBy, self::ORDER_BY))
			{
				throw new \InvalidArgumentException("Invalid orderBy: $orderBy");
			}

			$data['list.ordering'] = self::ORDER_BY[$orderBy];
		}

		if ($orderDirection !== null)
		{
			$direction = strtoupper($orderDirection);

			if (!in_array($direction, ['ASC', 'DESC'], true))
			{
				throw new \InvalidArgumentException("Invalid orderDirection: $orderDirection");
			}

			$data['list.direction'] = $direction;
		}

		return $data;
	}

	/**
	 * @return  array<string, mixed>
	 */
	public static function createTicketData(int $catid, string $title, ?int $priority, ?int $public, ?int $createdBy): array
	{
		$data = [
			'catid'    => $catid,
			'title'    => strip_tags($title),
			// Without an explicit priority, TicketsController::preprocessSaveData() defaults to 5
			// ("Normal") — required when "Ticket priorities" is enabled, otherwise ignored by the table.
			'priority' => $priority ?? 5,
			'public'   => $public ?? 1,
			'origin'   => 'web',
		];

		if ($createdBy !== null)
		{
			$data['created_by'] = $createdBy;
		}

		return $data;
	}

	/**
	 * A new ticket's opening post — identical to a normal reply, see {@see PostMapper::createData()}.
	 *
	 * @return  array<string, mixed>
	 */
	public static function firstPostData(int $ticketId, string $contentHtml, ?int $createdBy): array
	{
		return PostMapper::createData($ticketId, $contentHtml, $createdBy);
	}

	/**
	 * Only non-null parameters are included (null = "don't change" — see `.plans/00-conventions.md` §6).
	 *
	 * @return  array<string, mixed>
	 */
	public static function updateTicketData(?string $title, ?string $status, ?int $createdBy): array
	{
		$data = [];

		if ($title !== null)
		{
			$data['title'] = strip_tags($title);
		}

		if ($status !== null)
		{
			$data['status'] = $status;
		}

		if ($createdBy !== null)
		{
			$data['created_by'] = $createdBy;
		}

		return $data;
	}

	/**
	 * Top-level replace (NOT recursive — a multi-value field's new list must wholesale replace the old
	 * one, never merge element-by-element), then drops `null` values (an explicit `null` in `$incoming`
	 * removes that key, but never resurrects an existing value the caller didn't mention).
	 *
	 * @param   array<string, mixed>   $existing
	 * @param   ?array<string, mixed>  $incoming
	 *
	 * @return  array<string, mixed>
	 */
	public static function mergeComFields(array $existing, ?array $incoming): array
	{
		$merged = array_replace($existing, $incoming ?? []);

		return array_filter($merged, static fn ($value) => $value !== null);
	}

	/**
	 * @return  array{created: ?string, modified: ?string, modified_by: ?int}
	 * @throws  \InvalidArgumentException  An unparseable `$created`/`$modified`.
	 */
	public static function timestampColumns(?string $created, ?string $modified, ?int $modifiedBy): array
	{
		return [
			'created'     => DateTimeInput::toSql($created, 'created'),
			'modified'    => DateTimeInput::toSql($modified, 'modified'),
			'modified_by' => $modifiedBy,
		];
	}
}
