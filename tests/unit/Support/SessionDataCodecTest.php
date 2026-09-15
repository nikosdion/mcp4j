<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Support;

use Akeeba\Component\Mcp4j\Administrator\Support\SessionDataCodec;
use PHPUnit\Framework\TestCase;

class SessionDataCodecTest extends TestCase
{
	public function testRoundTripsAnEmptyArray(): void
	{
		$this->assertSame([], SessionDataCodec::decode(SessionDataCodec::encode([])));
	}

	public function testRoundTripsScalarAndNestedArrayValues(): void
	{
		$data = [
			'session.counter' => 3,
			'com_content.articles.list.limitstart' => 20,
			'registry' => ['nested' => ['deeper' => true, 'value' => null]],
			'a_string' => "with \"quotes\" and a / slash",
		];

		$this->assertSame($data, SessionDataCodec::decode(SessionDataCodec::encode($data)));
	}

	public function testEncodeProducesValidJson(): void
	{
		$encoded = SessionDataCodec::encode(['key' => 'value']);

		$this->assertJson($encoded);
		$this->assertSame('{"key":"value"}', $encoded);
	}

	public function testDecodeOfEmptyStringReturnsEmptyArray(): void
	{
		$this->assertSame([], SessionDataCodec::decode(''));
	}

	public function testDecodeOfMalformedJsonReturnsEmptyArrayRatherThanThrowing(): void
	{
		$this->assertSame([], SessionDataCodec::decode('{not valid json'));
	}

	public function testDecodeOfAJsonScalarReturnsEmptyArray(): void
	{
		// A valid JSON document that isn't an array/object still shouldn't crash the caller.
		$this->assertSame([], SessionDataCodec::decode('"just a string"'));
		$this->assertSame([], SessionDataCodec::decode('42'));
		$this->assertSame([], SessionDataCodec::decode('null'));
	}

	public function testDecodeDoesNotUnserializePhpObjects(): void
	{
		// Regression guard: this class replaced a PHP serialize()/unserialize()-based implementation
		// specifically to avoid unserializing attacker-reachable data into objects (see class docblock
		// and .claude/mcp-session-handling.md). Feeding it a PHP serialized payload must not produce an
		// object — json_decode() simply can't parse PHP's serialization format, so it degrades to [].
		$phpSerializedPayload = serialize((object) ['evil' => true]);

		$this->assertSame([], SessionDataCodec::decode($phpSerializedPayload));
	}
}
