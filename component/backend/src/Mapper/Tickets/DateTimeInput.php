<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Mapper\Tickets;

defined('_JEXEC') || die;

/**
 * Parses a caller-supplied `created`/`modified` override into the `Y-m-d H:i:s` UTC string
 * `#__ats_tickets`/`#__ats_posts` store.
 *
 * Pure: no Joomla.
 *
 * @since  0.0.1
 */
final class DateTimeInput
{
	/**
	 * @throws  \InvalidArgumentException  `$value` is not a parseable date/time string.
	 */
	public static function toSql(?string $value, string $argumentName): ?string
	{
		if ($value === null || $value === '')
		{
			return null;
		}

		try
		{
			// A value with no explicit UTC offset/"Z" is taken as UTC; one that does carry an offset (or
			// "Z") is honoured and then converted — DateTimeImmutable's second constructor argument is
			// only a *default* timezone, used solely when the string itself carries none.
			$date = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
		}
		catch (\Exception $e)
		{
			throw new \InvalidArgumentException("Invalid date-time for $argumentName: $value", 0, $e);
		}

		return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
	}
}
