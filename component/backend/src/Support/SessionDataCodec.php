<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Support;

defined('_JEXEC') || die;

/**
 * Encodes/decodes the `session_data` column: a snapshot of a Joomla ephemeral session's data array.
 *
 * Deliberately JSON, not PHP serialize()/unserialize(): this blob round-trips through the database
 * under a client-supplied `Mcp-Session-Id`, so unserializing arbitrary objects back out of it would be
 * a PHP object-injection gadget waiting to happen. The Joomla session state MCP4j actually cares about
 * (list-model pagination/filter state, etc.) is plain scalars and arrays, so JSON loses nothing needed.
 *
 * Pure and framework-free by design — it operates on plain arrays, not on
 * {@see \Joomla\Session\SessionInterface} — so it's unit-testable without Joomla or a database.
 *
 * @since  0.0.1
 */
final class SessionDataCodec
{
	/**
	 * @param   array  $data  The session's data array, e.g. from `SessionInterface::all()`.
	 */
	public static function encode(array $data): string
	{
		return json_encode($data) ?: '[]';
	}

	/**
	 * @return  array  The decoded data, or an empty array if $encoded is empty or not a JSON array/object.
	 */
	public static function decode(string $encoded): array
	{
		if ($encoded === '')
		{
			return [];
		}

		$decoded = json_decode($encoded, true);

		return is_array($decoded) ? $decoded : [];
	}
}
