<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Bridge\Support;

defined('_JEXEC') || die;

/**
 * Deep-merges a partial-update payload into a full "current record" array, for tools whose update
 * parameters are all nullable ("null means don't change this field").
 *
 * Pure: no Joomla.
 *
 * @since  0.0.1
 */
final class DataMerger
{
	/**
	 * Recursively merges `$changes` on top of `$base`.
	 *
	 * `array_replace_recursive()` is deliberately NOT used here: it merges list arrays element-by-index
	 * rather than replacing them wholesale, so updating a `tags` field of `[1, 2, 3]` with `[5]` would
	 * produce `[5, 2, 3]` instead of `[5]` — silently resurrecting values the caller meant to remove. It
	 * also can't distinguish "replace this list" from "merge into this list", and has no way to represent
	 * "clear this list" (an explicit `[]`) at all, since it simply has nothing to iterate and leaves the
	 * base value untouched.
	 *
	 * The rule implemented here instead: a key in `$changes` recurses into `$base` only when *both* sides
	 * are non-empty, associative arrays (`!array_is_list()`) — i.e. both sides are genuinely "nested
	 * groups of fields", like `params` or `images`. Everything else (scalars, lists, an empty array on
	 * either side) is a wholesale replacement, which is what makes clearing a list with `[]` work, and
	 * what stops list elements from bleeding through from the old value.
	 *
	 * @param   array<string, mixed>  $base     The current record.
	 * @param   array<string, mixed>  $changes  The partial update.
	 *
	 * @return  array<string, mixed>
	 */
	public static function merge(array $base, array $changes): array
	{
		$result = $base;

		foreach ($changes as $key => $value)
		{
			$baseValue = $result[$key] ?? null;

			if (self::isNonEmptyAssociativeArray($baseValue) && self::isNonEmptyAssociativeArray($value))
			{
				$result[$key] = self::merge($baseValue, $value);

				continue;
			}

			$result[$key] = $value;
		}

		return $result;
	}

	private static function isNonEmptyAssociativeArray(mixed $value): bool
	{
		return is_array($value) && $value !== [] && !array_is_list($value);
	}
}
