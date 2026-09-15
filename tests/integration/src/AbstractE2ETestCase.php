<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Base class for HTTP end-to-end tests against a real, Docker-provisioned Joomla + MCP4j install (see
 * tests/integration/run-tests.sh).
 *
 * Pure HTTP client: no Joomla application is booted in this process. Every helper here talks to
 * `/v1/mcp4j/server` exactly the way a real MCP client would.
 */
abstract class AbstractE2ETestCase extends TestCase
{
	/**
	 * Sentinel default for the $token parameter of the request helpers below: means "use the valid
	 * token read from the environment". Pass null (or an empty string) explicitly to send NO
	 * Authorization header (the unauthenticated case), or any other string to send it verbatim (e.g. a
	 * garbage token, to exercise authorization failures).
	 */
	protected const SELF_TOKEN = "\0__mcp4j_self_token__\0";

	protected static string $baseUrl = '';

	protected static string $token = '';

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		$baseUrl = getenv('MCP4J_BASE_URL') ?: '';
		$token   = getenv('MCP4J_API_TOKEN') ?: '';

		if ($baseUrl === '' || $token === '')
		{
			self::markTestSkipped(
				'MCP4J_BASE_URL and/or MCP4J_API_TOKEN are not set. ' .
				'Run the integration suite via tests/integration/run-tests.sh.'
			);
		}

		self::$baseUrl = rtrim($baseUrl, '/');
		self::$token   = $token;
	}

	/**
	 * Sends a JSON-RPC *request* (has an "id" — expects a response body) to the MCP endpoint.
	 *
	 * @return array{status:int,headers:string,json:?array,raw:string,sessionId:?string}
	 */
	protected function rpc(
		string $method,
		array $params = [],
		mixed $id = 1,
		?string $sessionId = null,
		?string $token = self::SELF_TOKEN
	): array {
		$body = ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method];

		if ($params !== [])
		{
			$body['params'] = $params;
		}

		return $this->rawRequest('POST', json_encode($body), $sessionId, $token);
	}

	/**
	 * Sends a JSON-RPC *notification* (no "id" member at all — must get a 202 with no body) to the MCP
	 * endpoint.
	 *
	 * @return array{status:int,headers:string,json:?array,raw:string,sessionId:?string}
	 */
	protected function notify(
		string $method,
		array $params = [],
		?string $sessionId = null,
		?string $token = self::SELF_TOKEN
	): array {
		$body = ['jsonrpc' => '2.0', 'method' => $method];

		if ($params !== [])
		{
			$body['params'] = $params;
		}

		return $this->rawRequest('POST', json_encode($body), $sessionId, $token);
	}

	/**
	 * Terminates a session via DELETE /v1/mcp4j/server.
	 *
	 * @return array{status:int,headers:string,json:?array,raw:string,sessionId:?string}
	 */
	protected function terminate(?string $sessionId, ?string $token = self::SELF_TOKEN): array
	{
		return $this->rawRequest('DELETE', null, $sessionId, $token);
	}

	/**
	 * Convenience: initializes a fresh session and returns [sessionId, decoded result]. Fails the test
	 * outright (rather than returning a null session) if initialize didn't succeed — nearly every other
	 * test needs a working session to get anywhere, so a clear failure here beats a confusing one three
	 * calls later.
	 *
	 * @return array{0: string, 1: array}
	 */
	protected function initializeSession(?string $token = self::SELF_TOKEN): array
	{
		$response = $this->rpc('initialize', [
			'protocolVersion' => '2025-06-18',
			'clientInfo'      => ['name' => 'mcp4j-e2e', 'version' => '1.0'],
		], 1, null, $token);

		$this->assertStatus(200, $response, 'initialize did not succeed: ' . $response['raw']);
		$this->assertNotNull($response['sessionId'], 'initialize did not return an Mcp-Session-Id header.');

		return [$response['sessionId'], $response['json']['result'] ?? []];
	}

	/**
	 * Sends a raw HTTP request to the MCP endpoint. Exposed directly (rather than only through rpc()/
	 * notify()) for tests that need to send bodies rpc()/notify() can't produce: malformed JSON, a
	 * batch (array), a body missing "jsonrpc", etc.
	 *
	 * @return array{status:int,headers:string,json:?array,raw:string,sessionId:?string}
	 */
	protected function rawRequest(
		string $httpMethod,
		?string $rawBody,
		?string $sessionId = null,
		?string $token = self::SELF_TOKEN
	): array {
		if ($token === self::SELF_TOKEN)
		{
			$token = self::$token;
		}

		$url = self::$baseUrl . '/v1/mcp4j/server';

		$headers = ['User-Agent: mcp4j-e2e-test'];

		if ($rawBody !== null)
		{
			$headers[] = 'Content-Type: application/json';
		}

		if (!empty($token))
		{
			$headers[] = 'Authorization: Bearer ' . $token;
		}

		if ($sessionId !== null)
		{
			$headers[] = 'Mcp-Session-Id: ' . $sessionId;
		}

		$ch = curl_init($url);

		$options = [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CUSTOMREQUEST  => strtoupper($httpMethod),
			CURLOPT_TIMEOUT        => 30,
			CURLOPT_HEADER         => true,
			CURLOPT_HTTPHEADER     => $headers,
		];

		if ($rawBody !== null)
		{
			$options[CURLOPT_POSTFIELDS] = $rawBody;
		}

		curl_setopt_array($ch, $options);

		$raw = curl_exec($ch);

		if ($raw === false)
		{
			$error = curl_error($ch);
			curl_close($ch);

			$this->fail(sprintf('cURL request %s %s failed: %s', $httpMethod, $url, $error));
		}

		$status     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
		curl_close($ch);

		$rawHeaders = substr((string) $raw, 0, $headerSize);
		$rawResponseBody = substr((string) $raw, $headerSize);

		return [
			'status'    => $status,
			'headers'   => $rawHeaders,
			'json'      => json_decode($rawResponseBody, true),
			'raw'       => $rawResponseBody,
			'sessionId' => $this->extractHeader($rawHeaders, 'Mcp-Session-Id'),
		];
	}

	protected function extractHeader(string $rawHeaders, string $name): ?string
	{
		if (preg_match('/^' . preg_quote($name, '/') . ':\s*(.+?)\s*$/im', $rawHeaders, $matches) === 1)
		{
			return $matches[1];
		}

		return null;
	}

	/**
	 * Assert that a response has the expected HTTP status code.
	 *
	 * @param   array{status:int,headers:string,json:?array,raw:string,sessionId:?string}  $response
	 */
	protected function assertStatus(int $expected, array $response, string $message = ''): void
	{
		$this->assertSame(
			$expected,
			$response['status'],
			$message !== '' ? $message : sprintf(
				'Expected HTTP status %d, got %d. Body: %s',
				$expected,
				$response['status'],
				$response['raw']
			)
		);
	}

	/**
	 * Assert that a response is a JSON-RPC error with the given error code.
	 *
	 * @param   array{status:int,headers:string,json:?array,raw:string,sessionId:?string}  $response
	 */
	protected function assertJsonRpcError(int $expectedCode, array $response, string $message = ''): void
	{
		$this->assertIsArray($response['json'], 'Response body is not valid JSON: ' . $response['raw']);
		$this->assertArrayHasKey('error', $response['json'], 'Response has no "error" member: ' . $response['raw']);
		$this->assertSame(
			$expectedCode,
			$response['json']['error']['code'] ?? null,
			$message !== '' ? $message : 'Unexpected JSON-RPC error code. Body: ' . $response['raw']
		);
	}
}
