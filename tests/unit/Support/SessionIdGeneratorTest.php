<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Support;

use Akeeba\Component\Mcp4j\Administrator\Support\SessionIdGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SessionIdGeneratorTest extends TestCase
{
	public function testGenerateProducesAWellFormedId(): void
	{
		$id = SessionIdGenerator::generate();

		$this->assertSame(43, strlen($id));
		$this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $id);
		$this->assertTrue(SessionIdGenerator::isWellFormed($id));
	}

	public function testGenerateDoesNotRepeatAcrossManyCalls(): void
	{
		$seen = [];

		for ($i = 0; $i < 1000; $i++)
		{
			$seen[SessionIdGenerator::generate()] = true;
		}

		$this->assertCount(1000, $seen, 'Expected 1000 distinct session IDs out of 1000 generated.');
	}

	public static function malformedIdProvider(): iterable
	{
		yield 'empty string' => [''];
		yield 'one character short' => [str_repeat('a', 42)];
		yield 'one character long' => [str_repeat('a', 44)];
		yield 'contains a slash (not base64url)' => [str_repeat('a', 42) . '/'];
		yield 'contains a plus (not base64url)' => [str_repeat('a', 42) . '+'];
		yield 'contains padding' => [str_repeat('a', 42) . '='];
		yield 'contains whitespace' => [str_repeat('a', 42) . ' '];
	}

	#[DataProvider('malformedIdProvider')]
	public function testIsWellFormedRejectsMalformedInput(string $value): void
	{
		$this->assertFalse(SessionIdGenerator::isWellFormed($value));
	}

	public function testIsWellFormedAcceptsAllValidBase64UrlCharacters(): void
	{
		// 43 characters covering every character base64url can produce (- and _ included).
		$value = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmno-_';

		$this->assertSame(43, strlen($value));
		$this->assertTrue(SessionIdGenerator::isWellFormed($value));
	}
}
