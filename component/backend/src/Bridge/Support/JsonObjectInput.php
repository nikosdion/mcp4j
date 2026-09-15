<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Bridge\Support;

defined('_JEXEC') || die;

use Akeeba\Component\Mcp4j\Administrator\Mcp\Exception\ToolException;

/**
 * Decodes an `array|string|null` tool parameter into a plain array, for tool parameters that accept
 * either a real JSON object/array (when the caller can construct one directly) or its JSON-encoded string
 * form (when the caller can only send strings) — the shape used throughout the original `joomla-mcp-php`
 * project's `JsonInputCompatibilityTrait::normaliseJsonObjectInput()`.
 *
 * The framework's {@see \Akeeba\Component\Mcp4j\Administrator\Mcp\Support\ArgumentCoercer} tries an exact
 * type match first, so a JSON string given for an `array|string|null` parameter is bound as a `string`
 * (not decoded into an array) — decoding is left to the tool, via this class, which is why every tool
 * taking such a parameter must call this instead of assuming it already received an array.
 *
 * Pure: no Joomla (throws the framework's own {@see ToolException}, which is itself Joomla-free).
 *
 * @since  0.0.1
 */
final class JsonObjectInput
{
	/**
	 * @throws  ToolException  The string isn't valid JSON, or it doesn't decode to an array.
	 */
	public static function decode(array|string|null $value, string $name): ?array
	{
		if ($value === null)
		{
			return null;
		}

		if (is_array($value))
		{
			return $value;
		}

		try
		{
			$decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
		}
		catch (\JsonException $e)
		{
			throw new ToolException(sprintf('The %s value must be a valid JSON object.', $name));
		}

		if (!is_array($decoded))
		{
			throw new ToolException(sprintf('The %s value must be a valid JSON object.', $name));
		}

		return $decoded;
	}
}
