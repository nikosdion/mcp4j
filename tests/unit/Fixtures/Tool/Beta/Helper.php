<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Fixtures\Tool\Beta;

/**
 * A concrete class fixture that sits in a tool category folder but declares no `#[McpTool]` methods at
 * all — {@see \Akeeba\Mcp4j\Tests\Unit\Mcp\Support\ToolRegistryTest} asserts the registry tolerates this
 * (no tools discovered from it, no error).
 */
class Helper
{
	public function helperMethod(): void
	{
	}
}
