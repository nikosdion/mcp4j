<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Mcp\Support;

use Akeeba\Component\Mcp4j\Administrator\Mcp\Support\ToolDefinition;
use PHPUnit\Framework\TestCase;

class ToolDefinitionTest extends TestCase
{
	private function definition(array $inputSchema, array $annotations = []): ToolDefinition
	{
		return new ToolDefinition(
			name: 'test_tool',
			description: 'A test tool.',
			category: 'Test',
			className: 'Some\\Class',
			methodName: 'method',
			inputSchema: $inputSchema,
			annotations: $annotations,
		);
	}

	public function testNoArgToolHasEmptyObjectPropertiesInJson(): void
	{
		$definition = $this->definition(['type' => 'object', 'properties' => [], 'required' => []]);

		$json = json_encode($definition->toMcpArray());

		$this->assertStringContainsString('"properties":{}', $json);
		$this->assertStringNotContainsString('"required"', $json);
	}

	public function testRequiredKeyOmittedWhenEmpty(): void
	{
		$definition = $this->definition(['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'required' => []]);

		$array = $definition->toMcpArray();

		$this->assertArrayNotHasKey('required', $array['inputSchema']);
	}

	public function testRequiredKeyPresentWhenNonEmpty(): void
	{
		$definition = $this->definition(['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'required' => ['a']]);

		$array = $definition->toMcpArray();

		$this->assertSame(['a'], $array['inputSchema']['required']);
	}

	public function testAnnotationsOmittedWhenEmpty(): void
	{
		$definition = $this->definition(['type' => 'object', 'properties' => [], 'required' => []]);

		$array = $definition->toMcpArray();

		$this->assertArrayNotHasKey('annotations', $array);
	}

	public function testAnnotationsPresentWhenNonEmpty(): void
	{
		$definition = $this->definition(['type' => 'object', 'properties' => [], 'required' => []], ['readOnlyHint' => true]);

		$array = $definition->toMcpArray();

		$this->assertSame(['readOnlyHint' => true], $array['annotations']);
	}

	public function testMinimalSchemaKeepsOnlyTypeAndDefault(): void
	{
		$definition = $this->definition([
			'type'       => 'object',
			'properties' => [
				'a' => ['type' => 'string', 'description' => 'A description', 'minLength' => 1],
				'b' => ['type' => 'integer', 'default' => 5, 'description' => 'B description'],
				'c' => ['description' => 'No type at all'],
			],
			'required'   => ['a'],
		]);

		$array = $definition->toMcpArray(minimalSchema: true);

		$this->assertSame(['type' => 'string'], $array['inputSchema']['properties']['a']);
		$this->assertSame(['type' => 'integer', 'default' => 5], $array['inputSchema']['properties']['b']);
		$this->assertSame([], $array['inputSchema']['properties']['c']);
	}

	public function testFullSchemaKeepsEverythingWhenNotMinimal(): void
	{
		$definition = $this->definition([
			'type'       => 'object',
			'properties' => [
				'a' => ['type' => 'string', 'description' => 'A description', 'minLength' => 1],
			],
			'required'   => ['a'],
		]);

		$array = $definition->toMcpArray(minimalSchema: false);

		$this->assertSame(
			['type' => 'string', 'description' => 'A description', 'minLength' => 1],
			$array['inputSchema']['properties']['a']
		);
	}

	public function testIsReadOnlyTrueOnlyWhenExplicitlyTrue(): void
	{
		$this->assertTrue($this->definition([], ['readOnlyHint' => true])->isReadOnly());
		$this->assertFalse($this->definition([], ['readOnlyHint' => false])->isReadOnly());
		$this->assertFalse($this->definition([])->isReadOnly());
	}

	public function testNameDescriptionAndInputSchemaArePresent(): void
	{
		$definition = $this->definition(['type' => 'object', 'properties' => [], 'required' => []]);

		$array = $definition->toMcpArray();

		$this->assertSame('test_tool', $array['name']);
		$this->assertSame('A test tool.', $array['description']);
		$this->assertSame('object', $array['inputSchema']['type']);
	}
}
