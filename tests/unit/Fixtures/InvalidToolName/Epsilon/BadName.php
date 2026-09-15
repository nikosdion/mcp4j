<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Fixtures\InvalidToolName\Epsilon;

use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\McpTool;

/**
 * Declares an MCP tool name that does not match `/^[a-z0-9_]{1,64}$/` (upper-case letters) — used only by
 * {@see \Akeeba\Mcp4j\Tests\Unit\Mcp\Support\ToolRegistryTest} to assert
 * {@see \Akeeba\Component\Mcp4j\Administrator\Mcp\Support\ToolRegistry::discover()} throws
 * `\LogicException`. Lives in its own fixture tree so it never poisons the other registry tests.
 */
class BadName
{
	#[McpTool(name: 'Not-A-Valid-Tool-Name', description: 'Invalid characters in the name.')]
	public function badName(): array
	{
		return [];
	}
}
