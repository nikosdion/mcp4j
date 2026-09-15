<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Integration\Tests;

use Akeeba\Mcp4j\Tests\Integration\AbstractToolTestCase;

/**
 * Self-test for the E2E harness pieces plan 02 adds (docker/provision-lowpriv-user.php, the
 * MCP4J_LOWPRIV_TOKEN wiring in run-tests.sh, and AbstractToolTestCase's helpers), rather than for any
 * MCP4j tool — there are no real tools to call against yet (see .plans/02-e2e-harness.md).
 */
class HarnessSelfTest extends AbstractToolTestCase
{
	public function testTheLowPrivilegeTokenCanInitialize(): void
	{
		$this->skipUnlessLowPrivTokenAvailable();

		[$sessionId, $result] = $this->initializeSession(self::$lowPrivToken);

		$this->assertNotSame('', $sessionId, 'initialize did not return a session id for the low-privilege token.');
		$this->assertSame('2025-06-18', $result['protocolVersion'] ?? null);
	}

	/**
	 * Regression guard for SessionManager::resume()'s "session belongs to this user" check: a session
	 * minted for the low-privilege user must be refused (404, unknown/expired) when replayed with the
	 * Super User's token, not silently resumed as if it were that user's own session.
	 */
	public function testASessionMintedByTheLowPrivilegeUserIsRefusedWithTheSuperUserToken(): void
	{
		$this->skipUnlessLowPrivTokenAvailable();

		[$lowPrivSessionId] = $this->initializeSession(self::$lowPrivToken);

		$response = $this->rpc('tools/list', [], 2, $lowPrivSessionId, self::$token);

		$this->assertStatus(404, $response, 'A session minted for one user must not resume for a different user.');
		$this->assertJsonRpcError(-32001, $response);
	}

	/**
	 * The mirror image of the test above: a Super User's session must likewise be refused when replayed
	 * with the low-privilege token.
	 */
	public function testASessionMintedByTheSuperUserIsRefusedWithTheLowPrivilegeToken(): void
	{
		$this->skipUnlessLowPrivTokenAvailable();

		[$superUserSessionId] = $this->initializeSession();

		$response = $this->rpc('tools/list', [], 2, $superUserSessionId, self::$lowPrivToken);

		$this->assertStatus(404, $response, 'A session minted for one user must not resume for a different user.');
		$this->assertJsonRpcError(-32001, $response);
	}

	public function testUniqueNameProducesDistinctValues(): void
	{
		$first  = $this->uniqueName('Widget');
		$second = $this->uniqueName('Widget');

		$this->assertNotSame($first, $second);
		$this->assertStringStartsWith('Widget ', $first);
		$this->assertStringStartsWith('Widget ', $second);
	}

	public function testUniqueAliasMatchesTheAllowedCharacterSet(): void
	{
		$alias = $this->uniqueAlias('My Widget! 123');

		$this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $alias);
	}

	public function testUniqueAliasProducesDistinctValues(): void
	{
		$first  = $this->uniqueAlias('widget');
		$second = $this->uniqueAlias('widget');

		$this->assertNotSame($first, $second);
	}

	private function skipUnlessLowPrivTokenAvailable(): void
	{
		if (self::$lowPrivToken === '')
		{
			$this->markTestSkipped('MCP4J_LOWPRIV_TOKEN is not set — run via tests/integration/run-tests.sh.');
		}
	}
}
