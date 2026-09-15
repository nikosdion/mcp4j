<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Bridge\Support;

use Akeeba\Component\Mcp4j\Administrator\Bridge\Support\JsonObjectInput;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Exception\ToolException;
use PHPUnit\Framework\TestCase;

class JsonObjectInputTest extends TestCase
{
	public function testNullReturnsNull(): void
	{
		$this->assertNull(JsonObjectInput::decode(null, 'params'));
	}

	public function testArrayIsReturnedAsIs(): void
	{
		$this->assertSame(['a' => 1], JsonObjectInput::decode(['a' => 1], 'params'));
	}

	public function testJsonObjectStringIsDecoded(): void
	{
		$this->assertSame(['a' => 1], JsonObjectInput::decode('{"a":1}', 'params'));
	}

	public function testJsonArrayStringIsAccepted(): void
	{
		$this->assertSame([1, 2, 3], JsonObjectInput::decode('[1,2,3]', 'params'));
	}

	public function testInvalidJsonThrows(): void
	{
		$this->expectException(ToolException::class);
		$this->expectExceptionMessage('The params value must be a valid JSON object.');

		JsonObjectInput::decode('{not json', 'params');
	}

	public function testJsonScalarThrows(): void
	{
		$this->expectException(ToolException::class);
		$this->expectExceptionMessage('The params value must be a valid JSON object.');

		JsonObjectInput::decode('"5"', 'params');
	}

	public function testJsonNumberScalarThrows(): void
	{
		$this->expectException(ToolException::class);

		JsonObjectInput::decode('5', 'params');
	}
}
