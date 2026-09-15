<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Mapper\Tickets;

defined('_JEXEC') || die;

/**
 * Pure input/output mapping for the Posts tool category (ATS ticket replies): tool parameters ->
 * `PostsModel`/`PostModel` state and save data, and the output field whitelists.
 *
 * `ITEM_FIELDS`/`LIST_FIELDS` are copied from ATS's own `component/api/src/View/Posts/JsonapiView.php`.
 * `email_uid` is deliberately excluded — ATS's own view excludes it too (it can carry a customer's raw
 * inbound-email message ID) — see `.plans/00-conventions.md` §5.9.
 *
 * Pure: no Joomla.
 *
 * @since  0.0.1
 */
final class PostMapper
{
	public const LIST_FIELDS = [
		'id',
		'ticket_id',
		'content_html',
		'origin',
		'timespent',
		'created',
		'created_by',
		'modified',
		'enabled',
		'attachment_id',
		'created_by_info',
	];

	public const ITEM_FIELDS = [
		'id',
		'ticket_id',
		'content_html',
		'origin',
		'timespent',
		'created',
		'created_by',
		'modified',
		'modified_by',
		'enabled',
		'attachment_id',
		'created_by_info',
		'modified_by_info',
	];

	public const INT_FIELDS = ['ticket_id', 'created_by', 'modified_by', 'enabled'];

	/**
	 * Also used by {@see TicketMapper::firstPostData()} for a new ticket's opening post — the two are
	 * identical.
	 *
	 * @return  array<string, mixed>
	 */
	public static function createData(int $ticketId, string $contentHtml, ?int $createdBy): array
	{
		$data = [
			'ticket_id'    => $ticketId,
			'content_html' => $contentHtml,
			'origin'       => 'web',
			'enabled'      => 1,
		];

		if ($createdBy !== null)
		{
			$data['created_by'] = $createdBy;
		}

		return $data;
	}

	/**
	 * @return  array<string, mixed>
	 */
	public static function updateData(string $contentHtml, ?int $createdBy): array
	{
		$data = ['content_html' => $contentHtml];

		if ($createdBy !== null)
		{
			$data['created_by'] = $createdBy;
		}

		return $data;
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
