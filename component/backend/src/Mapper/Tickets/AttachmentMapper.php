<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Mapper\Tickets;

defined('_JEXEC') || die;

/**
 * Pure output field whitelist for the Attachments tool category (ATS Pro ticket post attachments).
 *
 * `LIST_FIELDS`/`ITEM_FIELDS` are copied verbatim from ATS's own
 * `component/api/src/View/Attachments/JsonapiView.php` `$fieldsToRenderList`/`$fieldsToRenderItem`.
 * `mangled_filename` — the on-disk, hashed name a caller could otherwise use to guess/enumerate the
 * attachments directory layout — is never in either list; {@see self::SENSITIVE} exists purely so the
 * unit/E2E test suites have a single place to assert its absence from (see `.plans/00-conventions.md`
 * §5.9).
 *
 * Pure: no Joomla.
 *
 * @since  0.0.1
 */
final class AttachmentMapper
{
	public const LIST_FIELDS = [
		'id',
		'post_id',
		'original_filename',
		'mime_type',
		'origin',
		'created',
		'created_by',
		'enabled',
		'created_by_info',
	];

	public const ITEM_FIELDS = [
		'id',
		'post_id',
		'original_filename',
		'mime_type',
		'origin',
		'created',
		'created_by',
		'enabled',
		'file_size',
		'created_by_info',
	];

	public const INT_FIELDS = ['post_id', 'created_by', 'enabled', 'file_size'];

	/**
	 * Not used to shape output — the field simply never appears in {@see self::LIST_FIELDS}/
	 * {@see self::ITEM_FIELDS} in the first place — only to give the test suites one place to assert its
	 * absence from every tool result.
	 */
	public const SENSITIVE = ['mangled_filename'];
}
