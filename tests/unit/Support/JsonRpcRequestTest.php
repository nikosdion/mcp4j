<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Support;

use Akeeba\Component\Mcp4j\Administrator\Support\JsonRpcRequest;
use Akeeba\Component\Mcp4j\Administrator\Support\JsonRpcRequestException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class JsonRpcRequestTest extends TestCase
{
	// -----------------------------------------------------------------------------------------------------------
	// Valid requests
	// -----------------------------------------------------------------------------------------------------------

	public function testParsesAWellFormedRequest(): void
	{
		$request = JsonRpcRequest::fromRawBody('{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{"cursor":"abc"}}');

		$this->assertSame(1, $request->id);
		$this->assertSame('tools/list', $request->method);
		$this->assertSame(['cursor' => 'abc'], $request->params);
		$this->assertFalse($request->isNotification);
	}

	public function testParamsDefaultsToAnEmptyArrayWhenAbsent(): void
	{
		$request = JsonRpcRequest::fromRawBody('{"jsonrpc":"2.0","id":1,"method":"ping"}');

		$this->assertSame([], $request->params);
	}

	public function testParamsDefaultsToAnEmptyArrayWhenNotAnObjectOrArray(): void
	{
		$request = JsonRpcRequest::fromRawBody('{"jsonrpc":"2.0","id":1,"method":"ping","params":"not an object"}');

		$this->assertSame([], $request->params);
	}

	public static function idProvider(): iterable
	{
		yield 'integer id' => [1, 1];
		yield 'string id' => ['"abc"', 'abc'];
		yield 'null id (spec allows it, distinct from an absent id)' => ['null', null];
	}

	#[DataProvider('idProvider')]
	public function testPreservesTheIdVerbatim(mixed $rawId, mixed $expected): void
	{
		$request = JsonRpcRequest::fromRawBody('{"jsonrpc":"2.0","id":' . $rawId . ',"method":"ping"}');

		$this->assertSame($expected, $request->id);
	}

	// -----------------------------------------------------------------------------------------------------------
	// Notifications (no "id" member at all)
	// -----------------------------------------------------------------------------------------------------------

	public function testARequestWithNoIdMemberIsANotification(): void
	{
		$request = JsonRpcRequest::fromRawBody('{"jsonrpc":"2.0","method":"notifications/initialized"}');

		$this->assertTrue($request->isNotification);
		$this->assertNull($request->id);
	}

	public function testARequestWithAnExplicitNullIdIsNotATreatedAsNotification(): void
	{
		// Per JSON-RPC 2.0, "id" being present-but-null is a (discouraged) valid request id, distinct
		// from the member being absent entirely.
		$request = JsonRpcRequest::fromRawBody('{"jsonrpc":"2.0","id":null,"method":"ping"}');

		$this->assertFalse($request->isNotification);
	}

	// -----------------------------------------------------------------------------------------------------------
	// Rejected input
	// -----------------------------------------------------------------------------------------------------------

	public function testRejectsMalformedJson(): void
	{
		try
		{
			JsonRpcRequest::fromRawBody('{not valid json');
			$this->fail('Expected a JsonRpcRequestException.');
		}
		catch (JsonRpcRequestException $e)
		{
			$this->assertSame(-32700, $e->getCode());
			$this->assertNull($e->getRequestId());
		}
	}

	public static function batchProvider(): iterable
	{
		// MCP dropped JSON-RPC batching in the 2025-06-18 revision, unlike base JSON-RPC 2.0.
		yield 'a batch of one request' => ['[{"jsonrpc":"2.0","id":1,"method":"ping"}]'];
		yield 'an empty array' => ['[]'];
	}

	#[DataProvider('batchProvider')]
	public function testRejectsJsonArraysAsUnsupportedBatches(string $rawBody): void
	{
		$this->expectException(JsonRpcRequestException::class);
		$this->expectExceptionCode(-32600);

		JsonRpcRequest::fromRawBody($rawBody);
	}

	public static function notARequestObjectProvider(): iterable
	{
		yield 'a JSON string' => ['"hello"'];
		yield 'a JSON number' => ['42'];
		yield 'a JSON boolean' => ['true'];
		yield 'JSON null' => ['null'];
	}

	#[DataProvider('notARequestObjectProvider')]
	public function testRejectsTopLevelJsonThatIsNotAnObject(string $rawBody): void
	{
		$this->expectException(JsonRpcRequestException::class);
		$this->expectExceptionCode(-32600);

		JsonRpcRequest::fromRawBody($rawBody);
	}

	public function testRejectsAMissingJsonrpcMember(): void
	{
		$this->expectException(JsonRpcRequestException::class);
		$this->expectExceptionCode(-32600);

		JsonRpcRequest::fromRawBody('{"id":1,"method":"ping"}');
	}

	public static function wrongJsonrpcVersionProvider(): iterable
	{
		yield 'version 1.0' => ['1.0'];
		yield 'version 3.0 (does not exist, but must still be rejected)' => ['3.0'];
		yield 'empty string' => [''];
	}

	#[DataProvider('wrongJsonrpcVersionProvider')]
	public function testRejectsAnyJsonrpcVersionOtherThanExactly2Point0(string $version): void
	{
		$this->expectException(JsonRpcRequestException::class);
		$this->expectExceptionCode(-32600);

		JsonRpcRequest::fromRawBody(json_encode(['jsonrpc' => $version, 'id' => 1, 'method' => 'ping']));
	}

	public function testRejectsAMissingMethod(): void
	{
		$this->expectException(JsonRpcRequestException::class);
		$this->expectExceptionCode(-32600);

		JsonRpcRequest::fromRawBody('{"jsonrpc":"2.0","id":1}');
	}

	public function testRejectsAnEmptyStringMethod(): void
	{
		$this->expectException(JsonRpcRequestException::class);

		JsonRpcRequest::fromRawBody('{"jsonrpc":"2.0","id":1,"method":""}');
	}

	public function testRejectsANonStringMethod(): void
	{
		$this->expectException(JsonRpcRequestException::class);

		JsonRpcRequest::fromRawBody('{"jsonrpc":"2.0","id":1,"method":42}');
	}

	/**
	 * The whole reason JsonRpcRequestException carries a requestId separately from throwing: a caller
	 * building a spec-shaped error response needs to echo back whatever id *was* present, even when the
	 * request is otherwise invalid (e.g. a bad "jsonrpc" value) — only a body that isn't a JSON object at
	 * all leaves no id to recover.
	 */
	public function testPreservesTheIdOnAnOtherwiseInvalidRequestForErrorReporting(): void
	{
		try
		{
			JsonRpcRequest::fromRawBody('{"jsonrpc":"1.0","id":"abc-123","method":"ping"}');
			$this->fail('Expected a JsonRpcRequestException.');
		}
		catch (JsonRpcRequestException $e)
		{
			$this->assertSame('abc-123', $e->getRequestId());
		}
	}
}
