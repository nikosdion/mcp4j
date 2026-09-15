<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Fixtures\Tool\Alpha;

use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\McpTool;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\Schema;

/**
 * A read-only tool class fixture for {@see \Akeeba\Mcp4j\Tests\Unit\Mcp\Support\ToolRegistryTest} and
 * {@see \Akeeba\Mcp4j\Tests\Unit\Mcp\Support\SchemaGeneratorTest}. Deliberately not a real tool — it
 * exercises every `#[Schema(...)]` constructor argument somewhere, plus a union-typed parameter, a
 * nullable parameter with no default, and a parameter with a non-null default.
 */
class Things
{
	#[McpTool(
		name: 'alpha_things_list',
		description: 'List things',
		readOnlyHint: true,
		destructiveHint: false,
		idempotentHint: true,
		openWorldHint: false,
	)]
	public function listThings(
		#[Schema(description: 'Free-text search term', minLength: 1, maxLength: 100, pattern: '^[a-z]+$')]
		?string $search,
		#[Schema(description: 'Result page size', minimum: 1, maximum: 100, exclusiveMinimum: true, exclusiveMaximum: false)]
		int $limit = 20,
		#[Schema(description: 'Which state to filter to', enum: [0, 1, -2], format: 'int32', multipleOf: 1)]
		int $state = 1,
		#[Schema(description: 'Extra tag filters', items: ['type' => 'string'], minItems: 0, maxItems: 5, uniqueItems: true)]
		array $tags = [],
		#[Schema(properties: ['from' => ['type' => 'string'], 'to' => ['type' => 'string']], required: ['from'], additionalProperties: false)]
		array $range = [],
		#[Schema(definition: ['type' => 'string', 'const' => 'ignored'])]
		string $raw = 'x',
		array|string|null $flexible = null,
	): array {
		return [];
	}

	#[McpTool(name: 'alpha_things_noargs', description: 'A tool with no arguments at all.')]
	public function noArgs(): array
	{
		return [];
	}

	/**
	 * Not annotated with #[McpTool] — must never be discovered.
	 */
	public function notATool(): void
	{
	}

	/**
	 * Static methods, even if annotated, must never be discovered.
	 */
	#[McpTool(name: 'alpha_things_static', description: 'Must never be discovered — static method.')]
	public static function staticTool(): void
	{
	}
}
