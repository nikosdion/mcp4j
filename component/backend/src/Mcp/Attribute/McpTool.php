<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute;

defined('_JEXEC') || die;

/**
 * Marks a public method of a `src/Tool/<Category>/<Class>.php` class as an MCP tool.
 *
 * This mirrors the original `joomla-mcp-php` project's `PhpMcp\Server\Attributes\McpTool(name:,
 * description:, annotations: new ToolAnnotations(...))`, except the `ToolAnnotations` object is
 * flattened into named constructor arguments here — there is no separate annotations class in this
 * framework, only the four MCP tool annotation hints as nullable booleans (null = "not stated",
 * distinct from `false`).
 *
 * @since  0.0.1
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class McpTool
{
	public function __construct(
		public readonly string $name,
		public readonly string $description = '',
		public readonly ?bool $readOnlyHint = null,
		public readonly ?bool $destructiveHint = null,
		public readonly ?bool $idempotentHint = null,
		public readonly ?bool $openWorldHint = null,
	) {
	}
}
