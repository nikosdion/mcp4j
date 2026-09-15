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
		array $enabledCategories = [],
		array $allowlist = [],
		bool $readOnlyOnly = false,
		string $joomlaVersion = '5.4.0',
		?\Closure $isComponentEnabled = null
	): ToolFilterCriteria {
		return new ToolFilterCriteria(
			enabledCategories: $enabledCategories,
			allowlist: $allowlist,
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
	// Categories / allow-list
	// -----------------------------------------------------------------------------------------------------------

	public function testCategoryFilterIsCaseInsensitive(): void
	{
		$definitions = ['t' => $this->tool('t', 'Content')];

		$result = ToolFilter::apply($definitions, $this->criteria(enabledCategories: ['content']));

		$this->assertArrayHasKey('t', $result);
	}

	public function testCategoryFilterExcludesOtherCategories(): void
	{
		$definitions = [
			'a' => $this->tool('a', 'Content'),
			'b' => $this->tool('b', 'Users'),
		];

		$result = ToolFilter::apply($definitions, $this->criteria(enabledCategories: ['Content']));

		$this->assertSame(['a'], array_keys($result));
	}

	public function testEmptyCategoriesKeepsEverything(): void
	{
		$definitions = [
			'a' => $this->tool('a', 'Content'),
			'b' => $this->tool('b', 'Users'),
		];

		$result = ToolFilter::apply($definitions, $this->criteria());

		$this->assertSame(['a', 'b'], array_keys($result));
	}

	public function testAllowlistOverridesCategories(): void
	{
		$definitions = [
			'a' => $this->tool('a', 'Content'),
			'b' => $this->tool('b', 'Users'),
		];

		$result = ToolFilter::apply($definitions, $this->criteria(enabledCategories: ['Users'], allowlist: ['a']));

		$this->assertSame(['a'], array_keys($result));
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

	public function testReadOnlyAppliedAfterAllowlist(): void
	{
		$definitions = [
			'r' => $this->tool('r', 'Content', readOnly: true),
			'w' => $this->tool('w', 'Content', readOnly: false),
		];

		// The write tool is explicitly allow-listed, but read-only filtering, applied last, still removes it.
		$result = ToolFilter::apply($definitions, $this->criteria(allowlist: ['r', 'w'], readOnlyOnly: true));

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
