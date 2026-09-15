<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Fixtures\Tool\Beta;

use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\McpTool;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\Schema;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\ToolRequirements;

/**
 * A write-tool class fixture for {@see \Akeeba\Mcp4j\Tests\Unit\Mcp\Support\ToolRegistryTest} and
 * {@see \Akeeba\Mcp4j\Tests\Unit\Mcp\Support\ToolFilterTest}. Carries both a class-level and a
 * method-level `#[ToolRequirements]` so the registry's requirements-merging behaviour (components
 * unioned, version bounds narrowed to whichever is more restrictive) has something concrete to assert
 * against.
 */
#[ToolRequirements(components: ['com_beta_base'], maxJoomla: '7.0')]
class Widgets
{
	#[McpTool(
		name: 'beta_widgets_create',
		description: 'Create a widget',
		readOnlyHint: false,
		destructiveHint: true,
		idempotentHint: false,
	)]
	#[ToolRequirements(components: ['com_beta'], minJoomla: '5.0')]
	public function createWidget(
		#[Schema(description: 'Widget title', minLength: 1)]
		string $title,
	): array {
		return ['id' => 1, 'title' => $title];
	}
}
