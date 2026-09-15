<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Mapper\Media;

defined('_JEXEC') || die;

/**
 * Output field whitelists for the Media tool category, taken from com_media's own Web Services API views
 * (`api/components/com_media/src/View/Adapters/JsonapiView.php`, `View/Media/JsonapiView.php`) — see
 * `.plans/00-conventions.md` §5.9 and `.plans/13-media-joomlaupdate.md`.
 *
 * {@see self::FILE_FIELDS} deliberately omits `content` (base64 file contents — the original tool never
 * requested it, and returning it would make every list/read call proportional to file size) and `tempUrl`
 * (never populated by the local filesystem adapter this package supports).
 *
 * Pure: no Joomla.
 *
 * @since  0.0.1
 */
final class MediaMapper
{
	public const ADAPTER_FIELDS = ['id', 'provider_id', 'name', 'path'];

	public const FILE_FIELDS = [
		'type',
		'name',
		'path',
		'extension',
		'size',
		'mime_type',
		'width',
		'height',
		'create_date',
		'create_date_formatted',
		'modified_date',
		'modified_date_formatted',
		'thumb_path',
		'adapter',
		'url',
	];

	public const INT_FIELDS = ['size', 'width', 'height'];

	/**
	 * Media adapter ids are strings (e.g. `"local-images"`), so adapters must never go through
	 * {@see \Akeeba\Component\Mcp4j\Administrator\Bridge\Support\OutputShaper::shape()} — that helper
	 * always coerces `id` to `int`. This is a plain key filter instead, keeping `id` (and every other
	 * field) untouched.
	 *
	 * @param   array<string, mixed>  $adapter
	 *
	 * @return  array<string, mixed>
	 */
	public static function shapeAdapter(array $adapter): array
	{
		$shaped = [];

		foreach (self::ADAPTER_FIELDS as $field)
		{
			if (array_key_exists($field, $adapter))
			{
				$shaped[$field] = $adapter[$field];
			}
		}

		return $shaped;
	}
}
