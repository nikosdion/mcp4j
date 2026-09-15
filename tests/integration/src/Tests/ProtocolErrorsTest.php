<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Integration\Tests;

use Akeeba\Mcp4j\Tests\Integration\AbstractE2ETestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Covers request-envelope-level rejections (malformed JSON, batching, wrong "jsonrpc" version, …)
 * against a real Joomla + MCP4j install — the same rules {@see \Akeeba\Mcp4j\Tests\Unit\Support\JsonRpcRequestTest}
 * checks in isolation, but here proving the whole HTTP stack (routing, content negotiation, the
 * webservices plugin's Accept-header handling) actually delivers the request to that parsing code and
 * ships the resulting error back out correctly.
 */
class ProtocolErrorsTest extends AbstractE2ETestCase
{
	public function testMalformedJsonIsRejected(): void
	{
		$response = $this->rawRequest('POST', '{not valid json');

		$this->assertStatus(400, $response);
		$this->assertJsonRpcError(-32700, $response);
	}

	public static function batchProvider(): iterable
	{
		yield 'a batch of one request' => ['[{"jsonrpc":"2.0","id":1,"method":"ping"}]'];
		yield 'an empty array' => ['[]'];
	}

	#[DataProvider('batchProvider')]
	public function testBatchingIsRejected(string $rawBody): void
	{
		$response = $this->rawRequest('POST', $rawBody);

		$this->assertStatus(400, $response);
		$this->assertJsonRpcError(-32600, $response);
	}

	public function testAMissingJsonrpcMemberIsRejected(): void
	{
		$response = $this->rawRequest('POST', json_encode(['id' => 1, 'method' => 'ping']));

		$this->assertStatus(400, $response);
		$this->assertJsonRpcError(-32600, $response);
	}

	public function testTheIdIsEchoedBackEvenWhenTheRequestIsOtherwiseInvalid(): void
	{
		$response = $this->rawRequest(
			'POST',
			json_encode(['jsonrpc' => '1.0', 'id' => 'abc-123', 'method' => 'ping'])
		);

		$this->assertStatus(400, $response);
		$this->assertSame('abc-123', $response['json']['id'] ?? null);
	}

	public function testNoAcceptHeaderIsRequired(): void
	{
		// Regression guard: Joomla's content negotiation library throws a hard 406 on a genuinely
		// missing Accept header rather than treating it as "accept anything" (see
		// plg_webservices_mcp4j's ensureAcceptHeaderForOurRoute()). Not every MCP HTTP client bothers
		// sending one. curl always sends "Accept: */*" by default, so this specifically strips it.
		$response = $this->rawRequestWithoutAcceptHeader(
			json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'])
		);

		$this->assertStatus(200, $response);
	}

	/**
	 * @return array{status:int,headers:string,json:?array,raw:string}
	 */
	private function rawRequestWithoutAcceptHeader(string $rawBody): array
	{
		$url = self::$baseUrl . '/v1/mcp4j/server';

		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CUSTOMREQUEST  => 'POST',
			CURLOPT_TIMEOUT        => 30,
			CURLOPT_HEADER         => true,
			CURLOPT_HTTPHEADER     => [
				'Content-Type: application/json',
				'Authorization: Bearer ' . self::$token,
				// The one HTTP client curl ships that can send NO Accept header at all: explicitly
				// unsetting a header curl would otherwise add by default.
				'Accept:',
			],
			CURLOPT_POSTFIELDS     => $rawBody,
		]);

		$raw = curl_exec($ch);
		$this->assertNotFalse($raw, 'cURL request failed: ' . curl_error($ch));

		$status     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);

		// curl_close() has been a no-op since PHP 8.0 and is deprecated outright since PHP 8.5 — see
		// AbstractE2ETestCase::request()'s identical comment.

		$rawHeaders = substr((string) $raw, 0, $headerSize);
		$rawResponseBody = substr((string) $raw, $headerSize);

		return [
			'status'  => $status,
			'headers' => $rawHeaders,
			'json'    => json_decode($rawResponseBody, true),
			'raw'     => $rawResponseBody,
		];
	}
}
