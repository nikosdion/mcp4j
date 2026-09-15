<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Fixtures\Tool\Alpha;

use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\McpTool;

/**
 * An abstract tool class fixture for {@see \Akeeba\Mcp4j\Tests\Unit\Mcp\Support\ToolRegistryTest}. It
 * declares an `#[McpTool]` method purely to prove the registry skips the class (and therefore this
 * method) purely because the class itself is abstract, not because the method is missing the attribute.
 */
abstract class AbstractBase
{
	#[McpTool(name: 'alpha_abstractbase_never', description: 'Must never be discovered — abstract class.')]
	public function neverDiscovered(): array
	{
		return [];
	}
}
