<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Fixtures\DuplicateTool\Delta;

use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\McpTool;

/**
 * Deliberately declares the same MCP tool name as {@see \Akeeba\Mcp4j\Tests\Unit\Fixtures\DuplicateTool\Gamma\First}
 * — see that class's docblock.
 */
class Second
{
	#[McpTool(name: 'duplicate_tool_name', description: 'Second declaration.')]
	public function second(): array
	{
		return [];
	}
}
