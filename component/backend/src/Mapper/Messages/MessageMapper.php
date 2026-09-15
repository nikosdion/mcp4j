<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Mapper\Messages;

defined('_JEXEC') || die;

/**
 * Pure input/output mapping for the Messages tool category: `MessagesModel`/`MessageModel` state, save
 * data, and the output field whitelist.
 *
 * `#__messages`'s primary key is `message_id`, not `id` — {@see self::withId()} is the rename every list
 * row and read result goes through, copied from
 * `api/components/com_messages/src/View/Messages/JsonapiView.php`'s own `message_id` -> `id` rename (see
 * `.plans/00-conventions.md` §5.9). No user e-mail addresses are ever included in `ITEM_FIELDS`/
 * `LIST_FIELDS` — `from_user_name`/`user_from` are display names only (see this plan's "Sensitive
 * output" section).
 *
 * Pure: no Joomla.
 *
 * @since  0.0.1
 */
final class MessageMapper
{
	public const ITEM_FIELDS = [
		'id',
		'user_id_from',
		'user_id_to',
		'date_time',
		'priority',
		'subject',
		'message',
		'state',
		'from_user_name',
	];

	public const LIST_FIELDS = [
		'id',
		'user_id_from',
		'user_id_to',
		'date_time',
		'priority',
		'subject',
		'message',
		'state',
		'user_from',
	];

	public const INT_FIELDS = ['user_id_from', 'user_id_to', 'priority', 'state'];

	/**
	 * Renames the raw `message_id` column to `id` (see class docblock).
	 *
	 * @param   array<string, mixed>  $row
	 *
	 * @return  array<string, mixed>
	 */
	public static function withId(array $row): array
	{
		$row['id'] = (int) ($row['message_id'] ?? 0);

		unset($row['message_id']);

		return $row;
	}

	/**
	 * `MessagesModel::getListQuery()` state: `filter.state` (numeric = equality; `''` = states 0 and 1,
	 * same as an unset/null state — there is no dedicated "no filter" sentinel other than the literal
	 * string `'*'`, which this tool category never sends), and the default ordering (newest first).
	 *
	 * @return  array<string, mixed>
	 */
	public static function listState(?int $state): array
	{
		return [
			'filter.state'    => $state !== null ? (string) $state : '',
			'list.ordering'   => 'a.date_time',
			'list.direction'  => 'DESC',
		];
	}

	/**
	 * @return  array<string, mixed>
	 */
	public static function createData(int $userIdTo, string $subject, string $message): array
	{
		return [
			'user_id_to' => $userIdTo,
			'subject'    => $subject,
			'message'    => $message,
		];
	}

	/**
	 * The backend has no "edit a sent message" UI, and `MessageModel::save()` would re-send the
	 * new-message notification e-mail if it were reused for an edit — `messages_update` only ever changes
	 * `state` (see `.plans/11-overrides-redirects-messages.md` Part C deviation 1). All-null (only `state`
	 * given, or nothing at all) is fine; any of these three being non-null is rejected.
	 *
	 * @throws  \InvalidArgumentException
	 */
	public static function assertStateOnlyUpdate(?int $userIdTo, ?string $subject, ?string $message): void
	{
		if ($userIdTo !== null || $subject !== null || $message !== null)
		{
			throw new \InvalidArgumentException(
				'Private messages cannot be edited after they are sent; only the state can be changed.'
			);
		}
	}
}
