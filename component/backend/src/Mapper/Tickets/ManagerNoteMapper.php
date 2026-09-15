<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Mapper\Tickets;

defined('_JEXEC') || die;

/**
 * Pure input/output mapping for the ManagerNotes tool category (ATS Pro internal ticket notes): tool
 * parameters -> `ManagernoteModel` save data, and the output field whitelists.
 *
 * `LIST_FIELDS`/`ITEM_FIELDS` are copied verbatim from ATS's own
 * `component/api/src/View/Managernotes/JsonapiView.php` `$fieldsToRenderList`/`$fieldsToRenderItem`.
 *
 * Pure: no Joomla.
 *
 * @since  0.0.1
 */
final class ManagerNoteMapper
{
	public const LIST_FIELDS = [
		'id',
		'ticket_id',
		'note_html',
		'created',
		'created_by',
		'modified',
		'enabled',
		'created_by_info',
	];

	public const ITEM_FIELDS = [
		'id',
		'ticket_id',
		'note_html',
		'created',
		'created_by',
		'modified',
		'modified_by',
		'enabled',
		'created_by_info',
		'modified_by_info',
	];

	public const INT_FIELDS = ['ticket_id', 'created_by', 'modified_by', 'enabled'];

	/**
	 * @return  array{ticket_id: int, note_html: string, enabled: int}
	 */
	public static function createData(int $ticketId, string $noteHtml): array
	{
		return [
			'ticket_id' => $ticketId,
			'note_html' => $noteHtml,
			'enabled'   => 1,
		];
	}
}
