<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Bridge\Support;

defined('_JEXEC') || die;

/**
 * Normalises a `*_list` tool's `pageLimit`/`pageOffset` parameters to a `[limit, offset]` pair with sane
 * defaults and bounds, per `.plans/00-conventions.md` §6 ("Pagination defaults").
 *
 * Pure: no Joomla.
 *
 * @since  0.0.1
 */
final class Pagination
{
	public const DEFAULT_LIMIT = 20;

	public const MAX_LIMIT = 100;

	/**
	 * @return  array{0: int, 1: int}  `[limit, offset]`.
	 */
	public static function normalise(?int $limit, ?int $offset): array
	{
		$limit ??= self::DEFAULT_LIMIT;
		$limit = max(1, min(self::MAX_LIMIT, $limit));

		$offset ??= 0;
		$offset = max(0, $offset);

		return [$limit, $offset];
	}
}
