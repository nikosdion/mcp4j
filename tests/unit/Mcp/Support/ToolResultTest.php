<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Mcp\Support;

use Akeeba\Component\Mcp4j\Administrator\Mcp\Support\ToolResult;
use PHPUnit\Framework\TestCase;

class ToolResultTest extends TestCase
{
	public function testStringValuePassesThroughUnencoded(): void
	{
		$result = ToolResult::success('a plain string');

		$this->assertFalse($result['isError']);
		$this->assertSame('a plain string', $result['content'][0]['text']);
		$this->assertSame('text', $result['content'][0]['type']);
	}

	public function testArrayValueIsJsonEncoded(): void
	{
		$result = ToolResult::success(['id' => 1, 'title' => 'Hello']);

		$this->assertSame('{"id":1,"title":"Hello"}', $result['content'][0]['text']);
	}

	public function testSlashesAreUnescaped(): void
	{
		$result = ToolResult::success(['url' => 'https://example.com/path']);

		$this->assertStringContainsString('https://example.com/path', $result['content'][0]['text']);
		$this->assertStringNotContainsString('\\/', $result['content'][0]['text']);
	}

	public function testUnicodeIsUnescaped(): void
	{
		$result = ToolResult::success(['name' => 'Ελληνικά']);

		$this->assertStringContainsString('Ελληνικά', $result['content'][0]['text']);
	}

	public function testInvalidUtf8IsSubstitutedNotFatal(): void
	{
		$invalid = "bad utf8: \xB1\x31";

		$result = ToolResult::success(['value' => $invalid]);

		$this->assertFalse($result['isError']);
		$this->assertIsString($result['content'][0]['text']);
	}

	public function testZeroFractionIsPreserved(): void
	{
		$result = ToolResult::success(['value' => 5.0]);

		$this->assertSame('{"value":5.0}', $result['content'][0]['text']);
	}

	public function testErrorShapeHasIsErrorTrue(): void
	{
		$result = ToolResult::error('Not found: article #42');

		$this->assertTrue($result['isError']);
		$this->assertSame('Not found: article #42', $result['content'][0]['text']);
		$this->assertSame('text', $result['content'][0]['type']);
	}
}
