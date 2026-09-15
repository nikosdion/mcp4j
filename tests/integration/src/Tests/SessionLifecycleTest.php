<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Integration\Tests;

use Akeeba\Mcp4j\Tests\Integration\AbstractE2ETestCase;

/**
 * Covers the `Mcp-Session-Id` lifecycle — resume, tools/list, tools/call, and DELETE termination —
 * against a real Joomla + MCP4j install.
 *
 * This suite exists because two of the bugs it now guards against only showed up against a live site,
 * never in the unit suite: ApiApplication::close() silently discarding every setHeader() call (so
 * Mcp-Session-Id never reached the client), and a timezone bug in session expiry comparison (see
 * .claude/mcp-session-handling.md and SessionClockTest for the unit-level regression guard — this
 * suite is the full-stack one, run under a non-UTC container timezone for the same reason).
 */
class SessionLifecycleTest extends AbstractE2ETestCase
{
	public function testToolsListSucceedsWithAValidSession(): void
	{
		[$sessionId] = $this->initializeSession();

		$response = $this->rpc('tools/list', [], 2, $sessionId);

		$this->assertStatus(200, $response);

		$tools = $response['json']['result']['tools'] ?? null;

		$this->assertIsArray($tools);
		$this->assertNotSame([], $tools, 'tools/list must advertise at least the Tags tools.');

		$tagsList = null;

		foreach ($tools as $tool)
		{
			$this->assertIsArray($tool);
			$this->assertArrayHasKey('name', $tool);
			$this->assertArrayHasKey('description', $tool);
			$this->assertArrayHasKey('inputSchema', $tool);
			$this->assertSame('object', $tool['inputSchema']['type'] ?? null);

			if ($tool['name'] === 'tags_list')
			{
				$tagsList = $tool;
			}
		}

		$this->assertNotNull($tagsList, 'tools/list must advertise tags_list.');
		$this->assertTrue($tagsList['annotations']['readOnlyHint'] ?? null, 'tags_list must be annotated readOnlyHint: true.');
	}

	public function testToolsCallWithAnUnknownToolNameIsAJsonRpcError(): void
	{
		[$sessionId] = $this->initializeSession();

		$response = $this->rpc('tools/call', ['name' => 'does-not-exist'], 2, $sessionId);

		// A JSON-RPC method-level error is still a 200 at the HTTP layer — only transport-level
		// failures (missing/invalid session, bad auth, malformed body) use HTTP error statuses.
		$this->assertStatus(200, $response);
		$this->assertJsonRpcError(-32602, $response);
	}

	public function testAnUnknownMethodIsAJsonRpcMethodNotFoundError(): void
	{
		[$sessionId] = $this->initializeSession();

		$response = $this->rpc('not/a/real/method', [], 2, $sessionId);

		$this->assertStatus(200, $response);
		$this->assertJsonRpcError(-32601, $response);
	}

	public function testPingSucceedsWithAValidSession(): void
	{
		[$sessionId] = $this->initializeSession();

		$response = $this->rpc('ping', [], 2, $sessionId);

		$this->assertStatus(200, $response);
		$this->assertSame([], $response['json']['result'] ?? null);
	}

	public function testANotificationGetsA202WithNoBody(): void
	{
		[$sessionId] = $this->initializeSession();

		$response = $this->notify('notifications/initialized', [], $sessionId);

		$this->assertStatus(202, $response);
		$this->assertSame('', trim($response['raw']));
	}

	public function testAMissingSessionIdIsRefused(): void
	{
		$response = $this->rpc('tools/list', [], 2, null);

		$this->assertStatus(400, $response);
		$this->assertJsonRpcError(-32000, $response);
	}

	public function testAGarbageSessionIdIsRefused(): void
	{
		$response = $this->rpc('tools/list', [], 2, 'this-is-not-a-real-session-id');

		$this->assertStatus(404, $response);
		$this->assertJsonRpcError(-32001, $response);
	}

	public function testDeleteTerminatesASession(): void
	{
		[$sessionId] = $this->initializeSession();

		$deleteResponse = $this->terminate($sessionId);
		$this->assertStatus(204, $deleteResponse);

		// The refusal's effect: the session must actually be gone, not just the DELETE call reporting
		// success. A follow-up request with the same (now terminated) session ID must be refused too.
		$reuseResponse = $this->rpc('tools/list', [], 2, $sessionId);
		$this->assertStatus(404, $reuseResponse);
		$this->assertJsonRpcError(-32001, $reuseResponse);
	}

	public function testDeletingAnAlreadyUnknownSessionStillReturns204(): void
	{
		// DELETE is idempotent by nature: deleting something that's already gone isn't an error.
		$response = $this->terminate('this-session-id-was-never-issued');

		$this->assertStatus(204, $response);
	}

	public function testDeleteWithoutAuthenticationIsRefused(): void
	{
		[$sessionId] = $this->initializeSession();

		$response = $this->terminate($sessionId, null);

		$this->assertStatus(401, $response);

		// And, critically, the refusal must mean the session survives — prove it by successfully using
		// it right afterward with the real token, not just by re-reading the DELETE's own status code.
		$stillWorks = $this->rpc('tools/list', [], 2, $sessionId);
		$this->assertStatus(200, $stillWorks, 'An unauthenticated DELETE must not have terminated the session.');
	}

	public function testSessionStateSurvivesAcrossCalls(): void
	{
		// A weak but real regression guard for the session bridging in SessionManager::resume()/
		// persist(): if it stopped round-tripping the ephemeral Joomla session correctly, the session
		// row itself would stop resuming at all (covered above) well before anything subtler broke —
		// so simply proving several calls in a row all succeed against the same session id is already
		// meaningful coverage here.
		[$sessionId] = $this->initializeSession();

		for ($i = 2; $i <= 4; $i++)
		{
			$response = $this->rpc('ping', [], $i, $sessionId);
			$this->assertStatus(200, $response, "Call #{$i} against the same session failed.");
		}
	}
}
