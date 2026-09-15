<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Mapper\Media;

use Akeeba\Component\Mcp4j\Administrator\Mapper\Media\MediaPathMapper;
use PHPUnit\Framework\TestCase;

class MediaPathMapperTest extends TestCase
{
	// -----------------------------------------------------------------------------------------------------
	// combine()
	// -----------------------------------------------------------------------------------------------------

	public function testCombineWithNeitherPathNorAdapterReturnsEmptyString(): void
	{
		$this->assertSame('', MediaPathMapper::combine(null, null));
		$this->assertSame('', MediaPathMapper::combine('', null));
	}

	public function testCombineWithOnlyPathReturnsPathUnchanged(): void
	{
		$this->assertSame('foo/bar.png', MediaPathMapper::combine('foo/bar.png', null));
	}

	public function testCombineWithAdapterAndNoPathReturnsAdapterRoot(): void
	{
		$this->assertSame('local-images:/', MediaPathMapper::combine(null, 'local-images'));
		$this->assertSame('local-images:/', MediaPathMapper::combine('', 'local-images'));
	}

	public function testCombineWithAdapterAndPathJoinsThem(): void
	{
		$this->assertSame('local-images:/foo/bar.png', MediaPathMapper::combine('foo/bar.png', 'local-images'));
	}

	public function testCombineWithAdapterAndLeadingSlashPathDoesNotDoubleTheSlash(): void
	{
		$this->assertSame('local-images:/foo/bar.png', MediaPathMapper::combine('/foo/bar.png', 'local-images'));
	}

	public function testCombineWithPathAlreadyContainingAdapterIgnoresTheAdapterArgument(): void
	{
		$this->assertSame('local-files:/foo.png', MediaPathMapper::combine('local-files:/foo.png', 'local-images'));
	}

	public function testCombineWithPathAlreadyContainingAdapterAndNoAdapterArgument(): void
	{
		$this->assertSame('local-files:/foo.png', MediaPathMapper::combine('local-files:/foo.png', null));
	}

	// -----------------------------------------------------------------------------------------------------
	// decodeContent()
	// -----------------------------------------------------------------------------------------------------

	public function testDecodeContentEmptyStringReturnsEmptyString(): void
	{
		$this->assertSame('', MediaPathMapper::decodeContent(''));
	}

	public function testDecodeContentValidBase64Decodes(): void
	{
		$this->assertSame('hello', MediaPathMapper::decodeContent(base64_encode('hello')));
	}

	public function testDecodeContentInvalidCharactersThrows(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('The content must be valid base64.');

		MediaPathMapper::decodeContent('not-valid-base64!!!');
	}

	public function testDecodeContentBadPaddingThrows(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		// A single dangling base64 character can never be valid (strict decoding).
		MediaPathMapper::decodeContent('A');
	}

	// -----------------------------------------------------------------------------------------------------
	// isFolderPath()
	// -----------------------------------------------------------------------------------------------------

	public function testIsFolderPathWithAdapterAndNoDotIsFolder(): void
	{
		$this->assertTrue(MediaPathMapper::isFolderPath('images:/a/b'));
	}

	public function testIsFolderPathWithAdapterAndDotIsNotFolder(): void
	{
		$this->assertFalse(MediaPathMapper::isFolderPath('images:/a/b.png'));
	}

	public function testIsFolderPathWithDotInAnEarlierSegmentButNotBasenameIsFolder(): void
	{
		$this->assertTrue(MediaPathMapper::isFolderPath('a.b/c'));
	}

	public function testIsFolderPathWithNoSlashOrColon(): void
	{
		$this->assertTrue(MediaPathMapper::isFolderPath('folder'));
		$this->assertFalse(MediaPathMapper::isFolderPath('file.txt'));
	}

	public function testIsFolderPathRootOfAdapter(): void
	{
		$this->assertTrue(MediaPathMapper::isFolderPath('images:/'));
	}

	// -----------------------------------------------------------------------------------------------------
	// exceedsSizeLimit()
	// -----------------------------------------------------------------------------------------------------

	public function testExceedsSizeLimitAllZeroMeansUnlimited(): void
	{
		$this->assertFalse(MediaPathMapper::exceedsSizeLimit(1_000_000_000, 0, 0, 0));
	}

	public function testExceedsSizeLimitComponentLimitTriggers(): void
	{
		$oneMb = 1048576;

		$this->assertFalse(MediaPathMapper::exceedsSizeLimit($oneMb, 1, 0, 0));
		$this->assertTrue(MediaPathMapper::exceedsSizeLimit($oneMb + 1, 1, 0, 0));
	}

	public function testExceedsSizeLimitUploadIniLimitTriggers(): void
	{
		$this->assertFalse(MediaPathMapper::exceedsSizeLimit(100, 0, 100, 0));
		$this->assertTrue(MediaPathMapper::exceedsSizeLimit(101, 0, 100, 0));
	}

	public function testExceedsSizeLimitPostIniLimitTriggers(): void
	{
		$this->assertFalse(MediaPathMapper::exceedsSizeLimit(100, 0, 0, 100));
		$this->assertTrue(MediaPathMapper::exceedsSizeLimit(101, 0, 0, 100));
	}

	public function testExceedsSizeLimitAnyLimitBeingExceededIsEnough(): void
	{
		// Component limit passes, but the (smaller) post_max_size ini limit is exceeded.
		$this->assertTrue(MediaPathMapper::exceedsSizeLimit(200, 10, 0, 100));
	}

	// -----------------------------------------------------------------------------------------------------
	// paginate()
	// -----------------------------------------------------------------------------------------------------

	public function testPaginateDefaultsToTwentyItemsFromZero(): void
	{
		$items  = array_map(static fn (int $i): array => ['n' => $i], range(1, 25));
		$result = MediaPathMapper::paginate($items, null, null);

		$this->assertSame(25, $result['pagination']['total']);
		$this->assertSame(20, $result['pagination']['limit']);
		$this->assertSame(0, $result['pagination']['offset']);
		$this->assertCount(20, $result['items']);
		$this->assertSame(['n' => 1], $result['items'][0]);
	}

	public function testPaginateOffsetPastTheEndReturnsEmptyItemsButRealTotal(): void
	{
		$items  = array_map(static fn (int $i): array => ['n' => $i], range(1, 5));
		$result = MediaPathMapper::paginate($items, 10, 100);

		$this->assertSame(5, $result['pagination']['total']);
		$this->assertSame(10, $result['pagination']['limit']);
		$this->assertSame(100, $result['pagination']['offset']);
		$this->assertSame([], $result['items']);
	}

	public function testPaginateExplicitLimitAndOffsetSlicesCorrectly(): void
	{
		$items  = array_map(static fn (int $i): array => ['n' => $i], range(1, 5));
		$result = MediaPathMapper::paginate($items, 2, 2);

		$this->assertSame([['n' => 3], ['n' => 4]], array_values($result['items']));
	}
}
