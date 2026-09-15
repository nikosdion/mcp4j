<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Bridge\Support;

defined('_JEXEC') || die;

/**
 * Normalises the many shapes a Joomla "tags" value can arrive in — a plain list of ids, a comma-separated
 * string, or the shape a normalised `\Joomla\CMS\Helper\TagsHelper` value takes once
 * {@see ItemNormalizer::toArray()} has flattened it (`['tags' => '1,2', 'typeAlias' => ...]`) — into a
 * clean `list<int>`, suitable to feed straight back into a save payload's `tags` key.
 *
 * `AdminModel::getItem()` returns a `tags` property populated by `TagsHelper`, never a plain id list; a
 * tool's {@see \Akeeba\Component\Mcp4j\Administrator\Bridge\AbstractTool::saveItem()} step re-reads the
 * current record before merging a partial update on top of it, so that helper-shaped value has to be
 * normalised back to ids before it can be fed to `$model->save()` again — see
 * `.plans/03-joomla-bridge-and-tags-pilot.md` Part B, `saveItem()` step 2.
 *
 * Pure: no Joomla.
 *
 * @since  0.0.1
 */
final class TagIds
{
	/**
	 * @return  list<int>
	 */
	public static function fromMixed(mixed $value): array
	{
		if ($value === null || $value === '')
		{
			return [];
		}

		// A TagsHelper-normalised value (e.g. ['tags' => '1,2', 'typeAlias' => ...]) is associative, so a
		// 'tags' key can only exist here on that shape — a plain id list's keys are always 0..n-1.
		if (is_array($value) && array_key_exists('tags', $value))
		{
			return self::fromMixed($value['tags']);
		}

		if (is_string($value))
		{
			$parts = explode(',', $value);
		}
		elseif (is_array($value))
		{
			$parts = $value;
		}
		elseif (is_int($value) || is_float($value))
		{
			$parts = [$value];
		}
		else
		{
			return [];
		}

		$ids = [];

		foreach ($parts as $part)
		{
			if (is_int($part))
			{
				$id = $part;
			}
			elseif (is_float($part))
			{
				$id = (int) $part;
			}
			elseif (is_string($part) && is_numeric(trim($part)))
			{
				$id = (int) trim($part);
			}
			else
			{
				continue;
			}

			if ($id <= 0)
			{
				continue;
			}

			$ids[$id] = $id;
		}

		return array_values($ids);
	}
}
