<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Mcp\Support;

use Akeeba\Component\Mcp4j\Administrator\Mcp\Support\ToolDefinition;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Support\ToolFilter;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Support\ToolFilterCriteria;
use PHPUnit\Framework\TestCase;

class ToolFilterTest extends TestCase
{
	private function tool(
		string $name,
		string $category,
		bool $readOnly = false,
		array $requiredComponents = [],
		?string $minJoomla = null,
		?string $maxJoomla = null
	): ToolDefinition {
		return new ToolDefinition(
			name: $name,
			description: 'desc',
			category: $category,
			className: 'C',
			methodName: 'm',
			inputSchema: ['type' => 'object', 'properties' => [], 'required' => []],
			annotations: $readOnly ? ['readOnlyHint' => true] : [],
			requiredComponents: $requiredComponents,
			minJoomla: $minJoomla,
			maxJoomla: $maxJoomla,
		);
	}

	private function criteria(
		string $categoryMode = ToolFilterCriteria::MODE_EXCLUDE,
		array $categories = [],
		string $toolMode = ToolFilterCriteria::MODE_EXCLUDE,
		array $tools = [],
		bool $readOnlyOnly = false,
		string $joomlaVersion = '5.4.0',
		?\Closure $isComponentEnabled = null
	): ToolFilterCriteria {
		return new ToolFilterCriteria(
			categoryMode: $categoryMode,
			categories: $categories,
			toolMode: $toolMode,
			tools: $tools,
			readOnlyOnly: $readOnlyOnly,
			joomlaVersion: $joomlaVersion,
			isComponentEnabled: $isComponentEnabled ?? static fn (string $option): bool => true,
		);
	}

	// -----------------------------------------------------------------------------------------------------------
	// Requirements
	// -----------------------------------------------------------------------------------------------------------

	public function testDisabledComponentIsFilteredOut(): void
	{
		$definitions = ['t' => $this->tool('t', 'Cat', requiredComponents: ['com_foo'])];

		$result = ToolFilter::apply($definitions, $this->criteria(isComponentEnabled: static fn (string $o): bool => false));

		$this->assertSame([], $result);
	}

	public function testEnabledComponentPasses(): void
	{
		$definitions = ['t' => $this->tool('t', 'Cat', requiredComponents: ['com_foo'])];

		$result = ToolFilter::apply($definitions, $this->criteria(isComponentEnabled: static fn (string $o): bool => true));

		$this->assertArrayHasKey('t', $result);
	}

	public function testVersionBelowMinimumIsFilteredOut(): void
	{
		$definitions = ['t' => $this->tool('t', 'Cat', minJoomla: '6.0')];

		$result = ToolFilter::apply($definitions, $this->criteria(joomlaVersion: '5.4.0'));

		$this->assertSame([], $result);
	}

	public function testVersionAtMinimumPasses(): void
	{
		$definitions = ['t' => $this->tool('t', 'Cat', minJoomla: '6.0')];

		$result = ToolFilter::apply($definitions, $this->criteria(joomlaVersion: '6.0.0'));

		$this->assertArrayHasKey('t', $result);
	}

	public function testVersionAtOrAboveMaximumIsFilteredOut(): void
	{
		$definitions = ['t' => $this->tool('t', 'Cat', maxJoomla: '6.0')];

		$this->assertSame([], ToolFilter::apply($definitions, $this->criteria(joomlaVersion: '6.0.0')));
		$this->assertSame([], ToolFilter::apply($definitions, $this->criteria(joomlaVersion: '7.0.0')));
	}

	public function testVersionBelowMaximumPasses(): void
	{
		$definitions = ['t' => $this->tool('t', 'Cat', maxJoomla: '6.0')];

		$result = ToolFilter::apply($definitions, $this->criteria(joomlaVersion: '5.9.9'));

		$this->assertArrayHasKey('t', $result);
	}

	// -----------------------------------------------------------------------------------------------------------
	// Category mode / categories
	// -----------------------------------------------------------------------------------------------------------

	public function testEmptyCategoriesKeepsEverythingRegardlessOfMode(): void
	{
		$definitions = [
			'a' => $this->tool('a', 'Content'),
			'b' => $this->tool('b', 'Users'),
		];

		$this->assertSame(['a', 'b'], array_keys(ToolFilter::apply($definitions, $this->criteria(categoryMode: ToolFilterCriteria::MODE_INCLUDE))));
		$this->assertSame(['a', 'b'], array_keys(ToolFilter::apply($definitions, $this->criteria(categoryMode: ToolFilterCriteria::MODE_EXCLUDE))));
	}

	public function testCategoryIncludeModeKeepsOnlyListedCategoriesCaseInsensitively(): void
	{
		$definitions = [
			'a' => $this->tool('a', 'Content'),
			'b' => $this->tool('b', 'Users'),
		];

		$result = ToolFilter::apply(
			$definitions,
			$this->criteria(categoryMode: ToolFilterCriteria::MODE_INCLUDE, categories: ['content'])
		);

		$this->assertSame(['a'], array_keys($result));
	}

	public function testCategoryExcludeModeRemovesOnlyListedCategories(): void
	{
		$definitions = [
			'a' => $this->tool('a', 'Content'),
			'b' => $this->tool('b', 'Users'),
		];

		$result = ToolFilter::apply(
			$definitions,
			$this->criteria(categoryMode: ToolFilterCriteria::MODE_EXCLUDE, categories: ['Content'])
		);

		$this->assertSame(['b'], array_keys($result));
	}

	// -----------------------------------------------------------------------------------------------------------
	// Tool mode / tools
	// -----------------------------------------------------------------------------------------------------------

	public function testEmptyToolsKeepsEverythingRegardlessOfMode(): void
	{
		$definitions = [
			'a' => $this->tool('a', 'Content'),
			'b' => $this->tool('b', 'Users'),
		];

		$this->assertSame(['a', 'b'], array_keys(ToolFilter::apply($definitions, $this->criteria(toolMode: ToolFilterCriteria::MODE_INCLUDE))));
		$this->assertSame(['a', 'b'], array_keys(ToolFilter::apply($definitions, $this->criteria(toolMode: ToolFilterCriteria::MODE_EXCLUDE))));
	}

	public function testToolIncludeModeKeepsOnlyListedTools(): void
	{
		$definitions = [
			'a' => $this->tool('a', 'Content'),
			'b' => $this->tool('b', 'Users'),
		];

		$result = ToolFilter::apply(
			$definitions,
			$this->criteria(toolMode: ToolFilterCriteria::MODE_INCLUDE, tools: ['a'])
		);

		$this->assertSame(['a'], array_keys($result));
	}

	public function testToolExcludeModeRemovesOnlyListedTools(): void
	{
		$definitions = [
			'a' => $this->tool('a', 'Content'),
			'b' => $this->tool('b', 'Users'),
		];

		$result = ToolFilter::apply(
			$definitions,
			$this->criteria(toolMode: ToolFilterCriteria::MODE_EXCLUDE, tools: ['a'])
		);

		$this->assertSame(['b'], array_keys($result));
	}

	public function testCategoryAndToolFiltersCombineWithAnd(): void
	{
		$definitions = [
			'a' => $this->tool('a', 'Content'),
			'b' => $this->tool('b', 'Content'),
			'c' => $this->tool('c', 'Users'),
		];

		// Include only the "Content" category, then additionally exclude tool "a" from it.
		$result = ToolFilter::apply(
			$definitions,
			$this->criteria(
				categoryMode: ToolFilterCriteria::MODE_INCLUDE,
				categories: ['Content'],
				toolMode: ToolFilterCriteria::MODE_EXCLUDE,
				tools: ['a'],
			)
		);

		$this->assertSame(['b'], array_keys($result));
	}

	// -----------------------------------------------------------------------------------------------------------
	// Read-only, applied last
	// -----------------------------------------------------------------------------------------------------------

	public function testReadOnlyOnlyKeepsOnlyReadOnlyTools(): void
	{
		$definitions = [
			'r' => $this->tool('r', 'Content', readOnly: true),
			'w' => $this->tool('w', 'Content', readOnly: false),
		];

		$result = ToolFilter::apply($definitions, $this->criteria(readOnlyOnly: true));

		$this->assertSame(['r'], array_keys($result));
	}

	public function testReadOnlyAppliedAfterToolFilter(): void
	{
		$definitions = [
			'r' => $this->tool('r', 'Content', readOnly: true),
			'w' => $this->tool('w', 'Content', readOnly: false),
		];

		// Both tools are explicitly included, but read-only filtering, applied last, still removes 'w'.
		$result = ToolFilter::apply(
			$definitions,
			$this->criteria(toolMode: ToolFilterCriteria::MODE_INCLUDE, tools: ['r', 'w'], readOnlyOnly: true)
		);

		$this->assertSame(['r'], array_keys($result));
	}

	public function testReadOnlyFalseKeepsEverythingThatSurvivedEarlierStages(): void
	{
		$definitions = [
			'r' => $this->tool('r', 'Content', readOnly: true),
			'w' => $this->tool('w', 'Content', readOnly: false),
		];

		$result = ToolFilter::apply($definitions, $this->criteria(readOnlyOnly: false));

		$this->assertSame(['r', 'w'], array_keys($result));
	}
}
