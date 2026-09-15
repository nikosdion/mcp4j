<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Integration\Tests;

use Akeeba\Mcp4j\Tests\Integration\AbstractE2ETestCase;

/**
 * Covers `initialize` against a real Joomla + MCP4j install.
 */
class InitializeTest extends AbstractE2ETestCase
{
	public function testInitializeReturnsTheExpectedResultShapeAndASessionId(): void
	{
		$response = $this->rpc('initialize', [
			'protocolVersion' => '2025-06-18',
			'clientInfo'      => ['name' => 'mcp4j-e2e', 'version' => '1.0'],
		]);

		$this->assertStatus(200, $response);
		$this->assertNotNull($response['sessionId'], 'Expected an Mcp-Session-Id response header.');

		$result = $response['json']['result'] ?? null;

		$this->assertIsArray($result, 'Missing "result" in response: ' . $response['raw']);
		$this->assertSame('2025-06-18', $result['protocolVersion'] ?? null);
		$this->assertIsArray($result['capabilities'] ?? null);
		$this->assertArrayHasKey('tools', $result['capabilities']);
		$this->assertSame('MCP4j', $result['serverInfo']['name'] ?? null);
	}

	public function testEachInitializeCallMintsADistinctSession(): void
	{
		[$first]  = $this->initializeSession();
		[$second] = $this->initializeSession();

		$this->assertNotSame($first, $second);
	}

	public function testInitializeWithoutAuthenticationIsRefused(): void
	{
		$response = $this->rpc('initialize', [
			'protocolVersion' => '2025-06-18',
			'clientInfo'      => ['name' => 'mcp4j-e2e', 'version' => '1.0'],
		], 1, null, null);

		$this->assertStatus(401, $response);
		$this->assertNull($response['sessionId'], 'A refused initialize must not mint a session.');
	}

	public function testInitializeWithAGarbageTokenIsRefused(): void
	{
		$response = $this->rpc('initialize', [], 1, null, 'not-a-real-token');

		$this->assertStatus(401, $response);
		$this->assertNull($response['sessionId']);
	}

	public function testInitializeIgnoresAnIncomingMcpSessionIdAndMintsAFreshOneAnyway(): void
	{
		// The spec doesn't expect a client to send Mcp-Session-Id on initialize at all; if one shows up
		// anyway, the server must not try to "resume" it — initialize always starts a new session.
		[$existingSessionId] = $this->initializeSession();

		$response = $this->rpc('initialize', [
			'protocolVersion' => '2025-06-18',
			'clientInfo'      => ['name' => 'mcp4j-e2e', 'version' => '1.0'],
		], 1, $existingSessionId);

		$this->assertStatus(200, $response);
		$this->assertNotSame($existingSessionId, $response['sessionId']);
	}
}
