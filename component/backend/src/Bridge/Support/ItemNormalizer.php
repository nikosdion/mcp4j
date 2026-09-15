<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Bridge\Support;

defined('_JEXEC') || die;

/**
 * Turns whatever a Joomla model hands back (`\stdClass`, `\Joomla\CMS\Object\CMSObject`, a `Table`,
 * `\Joomla\Registry\Registry`, a `TagsHelper`-normalised value, plain arrays, scalars, ...) into plain,
 * JSON-encodable arrays, recursively.
 *
 * Deliberately duck-typed — it must never reference a Joomla class by name, so it can be unit tested
 * without booting Joomla, and so it doesn't care whether `AdminModel::getItem()` returns a `CMSObject`
 * (Joomla 4) or a bare `\stdClass` (Joomla 5/6, where `CMSObject` is deprecated).
 *
 * Pure: no Joomla.
 *
 * @since  0.0.1
 */
final class ItemNormalizer
{
	private const MAX_DEPTH = 10;

	/**
	 * @return  array<string, mixed>|array<int, mixed>
	 */
	public static function toArray(mixed $item): array
	{
		if ($item === null || $item === false)
		{
			return [];
		}

		$normalised = self::normalise($item, 0);

		// Top-level non-array scalar: wrap it rather than throw, per the plan's contract — this keeps the
		// return type a predictable array in every case.
		if (!is_array($normalised))
		{
			return ['value' => $normalised];
		}

		return $normalised;
	}

	private static function normalise(mixed $value, int $depth): mixed
	{
		if ($depth > self::MAX_DEPTH)
		{
			return null;
		}

		if (is_array($value))
		{
			$result = [];

			foreach ($value as $key => $item)
			{
				$result[$key] = self::normalise($item, $depth + 1);
			}

			return $result;
		}

		if (is_resource($value))
		{
			return null;
		}

		if (!is_object($value))
		{
			// Scalars (including null/bool/int/float/string) are kept as-is.
			return $value;
		}

		if ($value instanceof \SimpleXMLElement)
		{
			return null;
		}

		if ($value instanceof \Closure)
		{
			return null;
		}

		if (self::isResourceLike($value))
		{
			return null;
		}

		if (method_exists($value, 'toArray'))
		{
			return self::normaliseObjectProperties($value->toArray(), $depth);
		}

		if ($value instanceof \DateTimeInterface)
		{
			$utc = (clone $value)->setTimezone(new \DateTimeZone('UTC'));

			return $utc->format('Y-m-d H:i:s');
		}

		if (method_exists($value, 'getProperties'))
		{
			return self::normaliseObjectProperties($value->getProperties(true), $depth);
		}

		if ($value instanceof \JsonSerializable)
		{
			$serialized = $value->jsonSerialize();

			return is_array($serialized)
				? self::normaliseObjectProperties($serialized, $depth)
				: self::normalise($serialized, $depth + 1);
		}

		return self::normaliseObjectProperties(get_object_vars($value), $depth);
	}

	/**
	 * Recurses into a property bag obtained from an object (`toArray()`, `getProperties()`,
	 * `get_object_vars()`, ...), dropping any key beginning with `_` — CMSObject-style internals such as
	 * `_errors` that were never meant to be part of the public record.
	 *
	 * @param   array<int|string, mixed>  $properties
	 */
	private static function normaliseObjectProperties(array $properties, int $depth): array
	{
		$result = [];

		foreach ($properties as $key => $property)
		{
			if (is_string($key) && str_starts_with($key, '_'))
			{
				continue;
			}

			$result[$key] = self::normalise($property, $depth + 1);
		}

		return $result;
	}

	/**
	 * `is_resource()` is false for closed/PHP 8 "resource (closed)" objects and for the newer
	 * object-based resource replacements (e.g. `\CurlHandle`); a small, deliberately narrow deny-list
	 * catches those without over-matching ordinary value objects.
	 */
	private static function isResourceLike(object $value): bool
	{
		if (str_contains($value::class, '\\'))
		{
			return false;
		}

		return in_array($value::class, ['CurlHandle', 'GdImage', 'PgSql\Result'], true);
	}
}
