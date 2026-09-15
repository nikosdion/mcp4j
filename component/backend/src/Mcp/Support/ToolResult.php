<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Mcp\Support;

defined('_JEXEC') || die;

/**
 * Renders a tool method's PHP return value (or an error message) into the MCP `tools/call` result shape:
 * `{"content": [{"type": "text", "text": "..."}], "isError": bool}`. A tool method never builds this
 * shape itself — see `.plans/00-conventions.md` §6 for the return-value contract tool authors follow
 * instead.
 *
 * Pure: no Joomla, no I/O.
 *
 * @since  0.0.1
 */
final class ToolResult
{
	/**
	 * @throws  \JsonException  If `$value` cannot be represented as JSON (e.g. contains a resource,
	 *                          `NAN`/`INF`, or malformed UTF-8 outside what
	 *                          `JSON_INVALID_UTF8_SUBSTITUTE` can fix up).
	 */
	public static function success(mixed $value): array
	{
		return [
			'content' => [
				[
					'type' => 'text',
					'text' => self::render($value),
				],
			],
			'isError' => false,
		];
	}

	public static function error(string $message): array
	{
		return [
			'content' => [
				[
					'type' => 'text',
					'text' => $message,
				],
			],
			'isError' => true,
		];
	}

	/**
	 * @throws  \JsonException
	 */
	private static function render(mixed $value): string
	{
		if (is_string($value))
		{
			return $value;
		}

		return json_encode(
			$value,
			JSON_UNESCAPED_SLASHES
			| JSON_UNESCAPED_UNICODE
			| JSON_PRESERVE_ZERO_FRACTION
			| JSON_INVALID_UTF8_SUBSTITUTE
			| JSON_THROW_ON_ERROR
		);
	}
}
