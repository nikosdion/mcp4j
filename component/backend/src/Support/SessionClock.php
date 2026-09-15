<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Support;

defined('_JEXEC') || die;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Pure, framework-free UTC time arithmetic for MCP session TTLs.
 *
 * Deliberately does not use Joomla\CMS\Date\Date, nor PHP's strtotime(): a naive "YYYY-MM-DD HH:MM:SS"
 * string written in UTC, read back through strtotime(), is interpreted in the PHP process's *default*
 * timezone — on a server whose default timezone isn't UTC, that silently shifts the comparison by the
 * timezone offset (a session created moments ago can read back as already expired). Every method here
 * is explicit about UTC, has no Joomla dependency, and is unit-testable without a framework or database.
 *
 * @since  0.0.1
 */
final class SessionClock
{
	private const SQL_FORMAT = 'Y-m-d H:i:s';

	/**
	 * The current instant, in UTC.
	 */
	public static function now(): DateTimeImmutable
	{
		return new DateTimeImmutable('now', new DateTimeZone('UTC'));
	}

	/**
	 * Formats a UTC instant the way it is stored in `#__mcp4j_sessions` DATETIME columns.
	 */
	public static function toSql(DateTimeImmutable $when): string
	{
		return $when->format(self::SQL_FORMAT);
	}

	/**
	 * Parses a stored "YYYY-MM-DD HH:MM:SS" column value back into a UTC instant.
	 *
	 * @throws \InvalidArgumentException  If $stored isn't a valid SQL datetime string.
	 */
	public static function fromSql(string $stored): DateTimeImmutable
	{
		$parsed = DateTimeImmutable::createFromFormat('!' . self::SQL_FORMAT, $stored, new DateTimeZone('UTC'));

		if ($parsed === false)
		{
			throw new \InvalidArgumentException(sprintf('"%s" is not a valid SQL datetime string.', $stored));
		}

		return $parsed;
	}

	/**
	 * Computes the expiry instant for a session created/refreshed at $from, given a TTL in seconds.
	 *
	 * @param   int  $ttlSeconds  Must be >= 0.
	 */
	public static function expiryFrom(DateTimeImmutable $from, int $ttlSeconds): DateTimeImmutable
	{
		if ($ttlSeconds < 0)
		{
			throw new \InvalidArgumentException('The session TTL cannot be negative.');
		}

		return $from->add(new DateInterval('PT' . $ttlSeconds . 'S'));
	}

	/**
	 * Whether a stored "expires" column value is in the past relative to $now.
	 */
	public static function isExpired(string $storedExpires, DateTimeImmutable $now): bool
	{
		return self::fromSql($storedExpires) < $now;
	}
}
