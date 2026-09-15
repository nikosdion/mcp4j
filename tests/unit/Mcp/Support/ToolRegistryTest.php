<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Mcp\Support;

use Akeeba\Component\Mcp4j\Administrator\Mcp\Support\ToolDefinition;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Support\ToolRegistry;
use PHPUnit\Framework\TestCase;

class ToolRegistryTest extends TestCase
{
	private const FIXTURE_NAMESPACE = 'Akeeba\\Mcp4j\\Tests\\Unit\\Fixtures\\Tool';

	private function discoverFixtures(): array
	{
		return ToolRegistry::discover(__DIR__ . '/../../Fixtures/Tool', self::FIXTURE_NAMESPACE);
	}

	public function testDiscoversExactlyTheExpectedTools(): void
	{
		$definitions = $this->discoverFixtures();

		$this->assertSame(
			['alpha_things_list', 'alpha_things_noargs', 'beta_widgets_create'],
			array_keys($definitions)
		);
	}

	public function testEveryDefinitionIsAToolDefinition(): void
	{
		foreach ($this->discoverFixtures() as $definition)
		{
			$this->assertInstanceOf(ToolDefinition::class, $definition);
		}
	}

	public function testAbstractClassIsSkippedEntirely(): void
	{
		$definitions = $this->discoverFixtures();

		foreach ($definitions as $definition)
		{
			$this->assertNotSame('AbstractBase', (new \ReflectionClass($definition->className))->getShortName());
		}

		$this->assertArrayNotHasKey('alpha_abstractbase_never', $definitions);
	}

	public function testStaticMethodsAreNeverDiscovered(): void
	{
		$this->assertArrayNotHasKey('alpha_things_static', $this->discoverFixtures());
	}

	public function testNonAnnotatedMethodsAreNeverDiscovered(): void
	{
		$definitions = $this->discoverFixtures();

		foreach ($definitions as $definition)
		{
			$this->assertNotSame('notATool', $definition->methodName);
		}
	}

	public function testCategoryIsTheFolderName(): void
	{
		$definitions = $this->discoverFixtures();

		$this->assertSame('Alpha', $definitions['alpha_things_list']->category);
		$this->assertSame('Beta', $definitions['beta_widgets_create']->category);
	}

	public function testHelperClassWithNoToolsYieldsNothingAndDoesNotError(): void
	{
		$definitions = $this->discoverFixtures();

		foreach ($definitions as $definition)
		{
			$this->assertNotSame('Helper', (new \ReflectionClass($definition->className))->getShortName());
		}
	}

	public function testSortOrderIsCategoryThenName(): void
	{
		$definitions = $this->discoverFixtures();

		$pairs = [];

		foreach ($definitions as $definition)
		{
			$pairs[] = [$definition->category, $definition->name];
		}

		$sorted = $pairs;
		usort($sorted, static fn (array $a, array $b): int => $a <=> $b);

		$this->assertSame($sorted, $pairs);
	}

	public function testRequirementsAreMergedFromClassAndMethod(): void
	{
		$definition = $this->discoverFixtures()['beta_widgets_create'];

		// Class-level: components=[com_beta_base], maxJoomla=7.0. Method-level: components=[com_beta], minJoomla=5.0.
		$this->assertSame(['com_beta_base', 'com_beta'], $definition->requiredComponents);
		$this->assertSame('5.0', $definition->minJoomla);
		$this->assertSame('7.0', $definition->maxJoomla);
	}

	public function testAnnotationsAreCarriedThrough(): void
	{
		$readTool  = $this->discoverFixtures()['alpha_things_list'];
		$writeTool = $this->discoverFixtures()['beta_widgets_create'];

		$this->assertTrue($readTool->isReadOnly());
		$this->assertSame(
			['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
			$readTool->annotations
		);

		$this->assertFalse($writeTool->isReadOnly());
		$this->assertSame(
			['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false],
			$writeTool->annotations
		);
	}

	public function testDuplicateToolNameThrowsNamingBothClasses(): void
	{
		$this->expectException(\LogicException::class);
		$this->expectExceptionMessageMatches('/duplicate_tool_name/i');

		ToolRegistry::discover(
			__DIR__ . '/../../Fixtures/DuplicateTool',
			'Akeeba\\Mcp4j\\Tests\\Unit\\Fixtures\\DuplicateTool'
		);
	}

	public function testInvalidToolNameThrows(): void
	{
		$this->expectException(\LogicException::class);

		ToolRegistry::discover(
			__DIR__ . '/../../Fixtures/InvalidToolName',
			'Akeeba\\Mcp4j\\Tests\\Unit\\Fixtures\\InvalidToolName'
		);
	}

	public function testMissingDirectoryYieldsEmptyArray(): void
	{
		$this->assertSame([], ToolRegistry::discover(__DIR__ . '/../../Fixtures/DoesNotExist', 'Whatever'));
	}
}
