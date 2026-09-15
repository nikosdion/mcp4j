<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Support;

defined('_JEXEC') || die;

/**
 * Mints and validates the opaque `Mcp-Session-Id` values MCP4j hands out.
 *
 * 256 bits of randomness, base64url-encoded without padding — 43 characters, matching the
 * `session_id CHAR(43)` column. Pure and framework-free.
 *
 * @since  0.0.1
 */
final class SessionIdGenerator
{
	public const LENGTH = 43;

	public static function generate(): string
	{
		return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
	}

	/**
	 * Whether $value has the shape of a session ID this generator could have produced.
	 *
	 * This is a cheap format check, not proof the ID exists or is still valid — that always requires
	 * a database lookup (see SessionManager::resume()).
	 */
	public static function isWellFormed(string $value): bool
	{
		return strlen($value) === self::LENGTH && preg_match('/^[A-Za-z0-9_-]+$/', $value) === 1;
	}
}
