<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Bridge\Support;

use Akeeba\Component\Mcp4j\Administrator\Bridge\Support\TagIds;
use PHPUnit\Framework\TestCase;

class TagIdsTest extends TestCase
{
	public function testNullBecomesEmptyList(): void
	{
		$this->assertSame([], TagIds::fromMixed(null));
	}

	public function testEmptyStringBecomesEmptyList(): void
	{
		$this->assertSame([], TagIds::fromMixed(''));
	}

	public function testListOfIntsIsPreserved(): void
	{
		$this->assertSame([1, 2, 3], TagIds::fromMixed([1, 2, 3]));
	}

	public function testListOfNumericStringsIsCoercedToInt(): void
	{
		$this->assertSame([1, 2, 3], TagIds::fromMixed(['1', '2', '3']));
	}

	public function testCommaSeparatedStringIsSplit(): void
	{
		$this->assertSame([1, 2, 3], TagIds::fromMixed('1,2,3'));
	}

	public function testNormalisedTagsHelperShapeExtractsTheTagsKey(): void
	{
		$this->assertSame([1, 2], TagIds::fromMixed(['tags' => '1,2', 'typeAlias' => 'com_tags.tag']));
	}

	public function testZeroAndNegativeIdsAreDropped(): void
	{
		$this->assertSame([1, 2], TagIds::fromMixed([0, 1, -5, 2]));
	}

	public function testDuplicatesAreDedupedPreservingFirstOccurrenceOrder(): void
	{
		$this->assertSame([3, 1, 2], TagIds::fromMixed([3, 1, 3, 2, 1]));
	}

	public function testNonNumericValuesAreDropped(): void
	{
		$this->assertSame([1, 2], TagIds::fromMixed([1, 'not-a-number', 2]));
	}

	public function testSingleIntIsWrapped(): void
	{
		$this->assertSame([7], TagIds::fromMixed(7));
	}

	public function testWhitespaceAroundCommaSeparatedValuesIsTrimmed(): void
	{
		$this->assertSame([1, 2, 3], TagIds::fromMixed(' 1 , 2 ,3 '));
	}

	public function testEmptyArrayBecomesEmptyList(): void
	{
		$this->assertSame([], TagIds::fromMixed([]));
	}
}
