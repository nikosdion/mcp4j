<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Bridge\Support;

use Akeeba\Component\Mcp4j\Administrator\Bridge\Support\Pagination;
use PHPUnit\Framework\TestCase;

class PaginationTest extends TestCase
{
	public function testDefaultsWhenBothNull(): void
	{
		$this->assertSame([20, 0], Pagination::normalise(null, null));
	}

	public function testExplicitValuesArePassedThrough(): void
	{
		$this->assertSame([50, 10], Pagination::normalise(50, 10));
	}

	public function testLimitIsClampedToMaximum(): void
	{
		$this->assertSame([100, 0], Pagination::normalise(500, null));
	}

	public function testLimitIsClampedToMinimum(): void
	{
		$this->assertSame([1, 0], Pagination::normalise(0, null));
		$this->assertSame([1, 0], Pagination::normalise(-5, null));
	}

	public function testNegativeOffsetBecomesZero(): void
	{
		$this->assertSame([20, 0], Pagination::normalise(null, -10));
	}

	public function testConstants(): void
	{
		$this->assertSame(20, Pagination::DEFAULT_LIMIT);
		$this->assertSame(100, Pagination::MAX_LIMIT);
	}
}
