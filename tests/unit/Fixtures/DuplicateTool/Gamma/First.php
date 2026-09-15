<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Fixtures\DuplicateTool\Gamma;

use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\McpTool;

/**
 * Deliberately declares the same MCP tool name as {@see Second} — used only by
 * {@see \Akeeba\Mcp4j\Tests\Unit\Mcp\Support\ToolRegistryTest} to assert
 * {@see \Akeeba\Component\Mcp4j\Administrator\Mcp\Support\ToolRegistry::discover()} throws
 * `\LogicException` naming both classes on a duplicate tool name. Lives in its own fixture tree
 * (separate from `Fixtures/Tool`) so it never poisons the other registry tests.
 */
class First
{
	#[McpTool(name: 'duplicate_tool_name', description: 'First declaration.')]
	public function first(): array
	{
		return [];
	}
}
