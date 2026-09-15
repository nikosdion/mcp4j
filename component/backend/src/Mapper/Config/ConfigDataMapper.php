<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Mapper\Config;

defined('_JEXEC') || die;

/**
 * Pure port of the original `joomla-mcp-php` project's
 * `JsonInputCompatibilityTrait::normaliseJsonObjectInput()`: accepts a tool's `configData` parameter as
 * either a real associative array or its JSON-encoded string form, and always returns a plain array.
 *
 * Stricter than the original in one respect: a JSON **list** (e.g. `"[1,2]"`) is also rejected, not just
 * non-objects/invalid JSON — a configuration payload is always a set of named keys, never a bare list, so
 * accepting one silently would just produce a confusing "nothing changed" (a list has no key that could
 * ever match a config key) instead of a clear error. An empty array (`[]`/`{}` — PHP's `json_decode(...,
 * true)` cannot tell those apart once decoded) is accepted: it legitimately means "no changes".
 *
 * Throws the plain SPL `\InvalidArgumentException` rather than the framework's `ToolException` — this class
 * lives in `Mapper/` and must stay framework-free (see `.plans/00-conventions.md` §3); the calling tool
 * method is responsible for catching this and re-throwing as a `ToolException` with the same message.
 *
 * Pure: no Joomla.
 *
 * @since  0.0.1
 */
final class ConfigDataMapper
{
	/**
	 * @throws  \InvalidArgumentException  `$configData` is not valid JSON, does not decode to an object, or
	 *                                     decodes to a non-empty JSON list.
	 *
	 * @return  array<string, mixed>
	 */
	public static function normaliseConfigData(array|string $configData): array
	{
		if (is_string($configData))
		{
			$decoded = json_decode($configData, true);

			if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded))
			{
				throw new \InvalidArgumentException('The configData value must be a valid JSON object.');
			}

			$configData = $decoded;
		}

		if ($configData !== [] && array_is_list($configData))
		{
			throw new \InvalidArgumentException('The configData value must be a valid JSON object.');
		}

		return $configData;
	}
}
