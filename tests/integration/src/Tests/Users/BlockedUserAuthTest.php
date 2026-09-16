<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Integration\Tests\Users;

use Akeeba\Mcp4j\Tests\Integration\AbstractToolTestCase;

/**
 * security.md L4: `Users::updateUser()` skips its `core.edit.state` check when a caller edits their OWN
 * account's `block` flag (`$isSelf` case) — the finding speculated this might let a caller unblock
 * themselves via `users_update` without holding `core.edit.state`.
 *
 * The operator's counter-hypothesis, settled here empirically against a real Joomla install rather than
 * assumed from framework knowledge: a blocked Joomla user's bearer token is authentication, standing in
 * for a username/password — Joomla's login chain (`ServerController::getAuthenticatedUserId()` ->
 * `$this->app->login(..., ['action' => 'core.login.api'])`) should refuse a blocked account the same way
 * it refuses a blocked account's password, regardless of credential type. If so, the scenario the finding
 * describes can never occur: a blocked user's token would already be refused by `initialize()` before
 * `users_update` (or any other tool) ever ran.
 */
class BlockedUserAuthTest extends AbstractToolTestCase
{
	public function testABlockedUsersBearerTokenIsRefusedAuthentication(): void
	{
		if (self::$lowPrivToken === '')
		{
			$this->markTestSkipped('MCP4J_LOWPRIV_TOKEN is not set — run via tests/integration/run-tests.sh.');
		}

		// Baseline: prove the low-privilege token authenticates successfully BEFORE blocking, so a later
		// failure can only be attributed to the block, never to some unrelated token/environment problem.
		// Calls raw rpc('initialize', ...) directly rather than initializeSession()/sessionFor(), which
		// would fail the test outright on anything but success and would cache the resulting session —
		// this test needs to observe both the success and the failure response itself.
		$before = $this->rpc(
			'initialize',
			['protocolVersion' => '2025-06-18', 'clientInfo' => ['name' => 'mcp4j-e2e', 'version' => '1.0']],
			1,
			null,
			self::$lowPrivToken
		);

		$this->assertStatus(
			200,
			$before,
			'Sanity check failed: the low-privilege token could not authenticate before blocking: ' . $before['raw']
		);

		// Find the "lowpriv" user's numeric id, as the Super User.
		$search = $this->callToolOk('users_list', ['filterSearch' => 'lowpriv']);
		$this->assertNotEmpty($search['items'], 'Could not find the "lowpriv" user to block it.');
		$lowPrivUserId = $search['items'][0]['id'];

		// Block them — and unconditionally unblock them again afterward, even if an assertion below
		// fails: self::$lowPrivToken is shared by every other test in the suite via callToolAsLowPriv(),
		// leaving it blocked would break the rest of the run.
		$this->callToolOk('users_update', ['id' => $lowPrivUserId, 'block' => 1]);
		$this->registerCleanup(fn () => $this->callTool('users_update', ['id' => $lowPrivUserId, 'block' => 0]));

		$blockedState = $this->callToolOk('users_read', ['id' => $lowPrivUserId]);
		$this->assertSame(1, $blockedState['block'], 'The lowpriv user was not actually blocked — cannot test the hypothesis.');

		// The actual test: does the now-blocked user's EXISTING bearer token still authenticate?
		$after = $this->rpc(
			'initialize',
			['protocolVersion' => '2025-06-18', 'clientInfo' => ['name' => 'mcp4j-e2e', 'version' => '1.0']],
			1,
			null,
			self::$lowPrivToken
		);

		$this->assertStatus(
			401,
			$after,
			"A blocked user's bearer token was still accepted by initialize() with HTTP " . $after['status']
			. " — Joomla's own login chain did NOT refuse a blocked account here. Response: " . $after['raw']
		);
		$this->assertJsonRpcError(-32001, $after);
	}
}
