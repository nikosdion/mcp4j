<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Bridge\Support;

use Akeeba\Component\Mcp4j\Administrator\Bridge\Support\DataMerger;
use PHPUnit\Framework\TestCase;

class DataMergerTest extends TestCase
{
	public function testScalarChangeReplacesBaseValue(): void
	{
		$result = DataMerger::merge(['title' => 'Old'], ['title' => 'New']);

		$this->assertSame(['title' => 'New'], $result);
	}

	public function testKeysOnlyInBaseAreKept(): void
	{
		$result = DataMerger::merge(['title' => 'Old', 'alias' => 'old'], ['title' => 'New']);

		$this->assertSame(['title' => 'New', 'alias' => 'old'], $result);
	}

	public function testKeysOnlyInChangesAreAdded(): void
	{
		$result = DataMerger::merge(['title' => 'Old'], ['note' => 'A note']);

		$this->assertSame(['title' => 'Old', 'note' => 'A note'], $result);
	}

	public function testBothAssociativeArraysAreRecursivelyMerged(): void
	{
		$base    = ['params' => ['show_title' => 1, 'show_author' => 1]];
		$changes = ['params' => ['show_title' => 0]];

		$result = DataMerger::merge($base, $changes);

		$this->assertSame(['params' => ['show_title' => 0, 'show_author' => 1]], $result);
	}

	public function testListValueIsReplacedWholesaleNotMergedByIndex(): void
	{
		$base    = ['tags' => [1, 2, 3]];
		$changes = ['tags' => [5]];

		$result = DataMerger::merge($base, $changes);

		// The bug array_replace_recursive() would have: [5, 2, 3] (merged by index).
		$this->assertSame(['tags' => [5]], $result);
	}

	public function testEmptyListReplacesAndClearsTheBaseValue(): void
	{
		$base    = ['tags' => [1, 2, 3]];
		$changes = ['tags' => []];

		$result = DataMerger::merge($base, $changes);

		$this->assertSame(['tags' => []], $result);
	}

	public function testEmptyArrayChangeReplacesANonEmptyAssociativeBaseValue(): void
	{
		$base    = ['params' => ['show_title' => 1]];
		$changes = ['params' => []];

		$result = DataMerger::merge($base, $changes);

		$this->assertSame(['params' => []], $result);
	}

	public function testAssociativeChangeOntoNonArrayBaseValueReplaces(): void
	{
		$base    = ['params' => 'not-an-array'];
		$changes = ['params' => ['show_title' => 1]];

		$result = DataMerger::merge($base, $changes);

		$this->assertSame(['params' => ['show_title' => 1]], $result);
	}

	public function testNestedMergeIsRecursiveToArbitraryDepth(): void
	{
		$base    = ['a' => ['b' => ['c' => 1, 'd' => 2]]];
		$changes = ['a' => ['b' => ['c' => 99]]];

		$result = DataMerger::merge($base, $changes);

		$this->assertSame(['a' => ['b' => ['c' => 99, 'd' => 2]]], $result);
	}
}
