<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Bridge\Support;

use Akeeba\Component\Mcp4j\Administrator\Bridge\Support\NullFilter;
use PHPUnit\Framework\TestCase;

class NullFilterTest extends TestCase
{
	public function testNullValuesAreRemoved(): void
	{
		$result = NullFilter::withoutNulls(['title' => 'Hello', 'alias' => null]);

		$this->assertSame(['title' => 'Hello'], $result);
	}

	public function testNonNullValuesAreKeptAsIs(): void
	{
		$result = NullFilter::withoutNulls(['title' => 'Hello', 'published' => 0]);

		$this->assertSame(['title' => 'Hello', 'published' => 0], $result);
	}

	public function testExplicitEmptyListIsKept(): void
	{
		$result = NullFilter::withoutNulls(['tags' => []]);

		$this->assertSame(['tags' => []], $result);
	}

	public function testExplicitNonEmptyListIsKept(): void
	{
		$result = NullFilter::withoutNulls(['tags' => [1, 2, 3]]);

		$this->assertSame(['tags' => [1, 2, 3]], $result);
	}

	public function testNestedAssociativeArrayHasItsOwnNullsStripped(): void
	{
		$result = NullFilter::withoutNulls(['params' => ['show_title' => 1, 'show_author' => null]]);

		$this->assertSame(['params' => ['show_title' => 1]], $result);
	}

	public function testNestedAssociativeArrayThatBecomesEmptyIsRemoved(): void
	{
		$result = NullFilter::withoutNulls(['params' => ['show_title' => null, 'show_author' => null]]);

		$this->assertSame([], $result);
	}

	public function testAllNullInputBecomesEmptyArray(): void
	{
		$this->assertSame([], NullFilter::withoutNulls(['a' => null, 'b' => null]));
	}
}
