<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Bridge\Support;

defined('_JEXEC') || die;

/**
 * Recursively strips `null` values out of an "update data" array — the standard "null means don't change
 * this field" convention every `*_update` mapper uses (see `.plans/00-conventions.md` §6). Used together
 * with {@see DataMerger::merge()}: a mapper builds the full set of possible keys with `null` for
 * "untouched", this removes the untouched ones, and `DataMerger` then merges only what's left onto the
 * current record.
 *
 * A nested array that becomes empty after stripping its nulls is itself removed — but only when it was
 * associative to begin with (a nested "group" of fields, like `params`, with every one of its fields set
 * to null). An explicit list, e.g. `tags: []`, is a deliberate "clear this" value and is always kept, even
 * though it's empty.
 *
 * Pure: no Joomla.
 *
 * @since  0.0.1
 */
final class NullFilter
{
	/**
	 * @param   array<string, mixed>  $data
	 *
	 * @return  array<string, mixed>
	 */
	public static function withoutNulls(array $data): array
	{
		$result = [];

		foreach ($data as $key => $value)
		{
			if ($value === null)
			{
				continue;
			}

			if (is_array($value) && !array_is_list($value))
			{
				$value = self::withoutNulls($value);

				if ($value === [])
				{
					continue;
				}
			}

			$result[$key] = $value;
		}

		return $result;
	}
}
