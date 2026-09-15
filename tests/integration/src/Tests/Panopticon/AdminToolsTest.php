<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Integration\Tests\Panopticon;

use Akeeba\Mcp4j\Tests\Integration\AbstractToolTestCase;

/**
 * End-to-end coverage for the eleven `panopticon_admintools_*` tools
 * ({@see \Akeeba\Component\Mcp4j\Administrator\Tool\Panopticon\AdminTools}). See
 * `.plans/15-panopticon-admintools.md` Tests for the coverage this class is meant to satisfy.
 *
 * Requires both the Panopticon Connector (plan 14) and Admin Tools for Joomla to be installed on the
 * Docker test site — `requireToolsOrSkip()` in {@see self::setUp()} makes the whole class self-skip when
 * either is missing, rather than failing.
 *
 * `htaccess_disable`/`htaccess_enable` are only ever exercised on their safe, deterministic "nothing to
 * do" branch (the Docker site ships `htaccess.txt`, never an active `.htaccess`) — actually renaming
 * `.htaccess` would change how Apache serves the whole site for every other test class in this run.
 * `plugin_disable`/`plugin_enable` DO rename a real file, so {@see self::testPluginDisableThenEnable()}
 * registers a cleanup that always re-enables the plugin, even if an assertion fails midway.
 */
class AdminToolsTest extends AbstractToolTestCase
{
	private const TOOLS = [
		'panopticon_admintools_unblock',
		'panopticon_admintools_plugin_disable',
		'panopticon_admintools_plugin_enable',
		'panopticon_admintools_htaccess_disable',
		'panopticon_admintools_htaccess_enable',
		'panopticon_admintools_tempsuperuser',
		'panopticon_admintools_scanner_start',
		'panopticon_admintools_scanner_step',
		'panopticon_admintools_scans_list',
		'panopticon_admintools_scan_read',
		'panopticon_admintools_scanalert_read',
	];

	protected function setUp(): void
	{
		parent::setUp();

		$this->requireToolsOrSkip(self::TOOLS);
	}

	// -----------------------------------------------------------------------------------------------------
	// htaccess_disable / htaccess_enable — safe "nothing to do" branch only
	// -----------------------------------------------------------------------------------------------------

	public function testHtaccessDisableWhenNoHtaccessExists(): void
	{
		$result = $this->callToolOk('panopticon_admintools_htaccess_disable');

		$this->assertFalse($result['exists']);
		$this->assertFalse($result['renamed']);
	}

	public function testHtaccessEnableWhenNoBackupExists(): void
	{
		$result = $this->callToolOk('panopticon_admintools_htaccess_enable');

		$this->assertFalse($result['exists']);
		$this->assertFalse($result['restored']);
	}

	// -----------------------------------------------------------------------------------------------------
	// plugin_disable / plugin_enable
	// -----------------------------------------------------------------------------------------------------

	public function testPluginDisableThenEnable(): void
	{
		// Always re-enable, whatever happens below, so a failing assertion here can't leave the WAF
		// plugin disabled for the rest of the E2E run.
		$this->registerCleanup(fn () => $this->callTool('panopticon_admintools_plugin_enable'));

		$first = $this->callToolOk('panopticon_admintools_plugin_disable');
		$this->assertTrue($first['renamed']);
		$this->assertSame('provider-disable.php', $first['name']);

		// Idempotent: calling it again while already disabled reports the renamed file, not a failure.
		$second = $this->callToolOk('panopticon_admintools_plugin_disable');
		$this->assertTrue($second['renamed']);
		$this->assertSame('provider-disable.php', $second['name']);

		$enabled = $this->callToolOk('panopticon_admintools_plugin_enable');
		$this->assertFalse($enabled['renamed']);
		$this->assertNull($enabled['name']);
	}

	// -----------------------------------------------------------------------------------------------------
	// unblock
	// -----------------------------------------------------------------------------------------------------

	public function testUnblockTestNetAddressNotFound(): void
	{
		// TEST-NET-3 (RFC 5737): guaranteed never to appear in this site's block lists.
		$result = $this->callToolOk('panopticon_admintools_unblock', ['ip' => '203.0.113.7']);

		$this->assertTrue($result['status']);
		$this->assertFalse($result['found']);
	}

	public function testUnblockMissingIpIsRejected(): void
	{
		$text = $this->callToolError('panopticon_admintools_unblock');

		$this->assertSame('You must provide an IP address.', $text);
	}

	// -----------------------------------------------------------------------------------------------------
	// scans_list / scanner_start / scanner_step / scan_read / scanalert_read
	// -----------------------------------------------------------------------------------------------------

	public function testScansListShape(): void
	{
		$this->assertListShape($this->callToolOk('panopticon_admintools_scans_list'));
	}

	public function testScannerLifecycleAndScanRead(): void
	{
		$start = $this->callToolOk('panopticon_admintools_scanner_start');

		$this->assertIsBool($start['status']);
		$this->assertGreaterThan(0, $start['id']);

		$scanId = (int) $start['id'];

		// Step (in the SAME cached MCP session) until done, or a small safety cap of attempts.
		$done = (bool) $start['done'];

		for ($i = 0; $i < 3 && !$done; $i++)
		{
			$step = $this->callToolOk('panopticon_admintools_scanner_step');

			$this->assertArrayHasKey('status', $step);
			$this->assertArrayHasKey('done', $step);
			$this->assertArrayHasKey('error', $step);
			$this->assertArrayHasKey('warnings', $step);
			$this->assertSame($scanId, $step['id'], 'The scan id must stay the same across scanner_step calls in the same MCP session.');

			$done = (bool) $step['done'];
		}

		$list = $this->callToolOk('panopticon_admintools_scans_list');
		$this->assertContains($scanId, array_column($list['items'], 'id'));

		$scanRead = $this->callToolOk('panopticon_admintools_scan_read', ['id' => $scanId]);

		$this->assertSame($scanId, $scanRead['scan']['id']);
		$this->assertIsArray($scanRead['alerts']);
		$this->assertIsInt($scanRead['alertsPagination']['total']);
		$this->assertIsInt($scanRead['alertsPagination']['limit']);
		$this->assertIsInt($scanRead['alertsPagination']['offset']);

		if ($scanRead['alerts'] === [])
		{
			$this->markTestSkipped('Scan found no alerts to read; scanalert_read coverage skipped for this run.');
		}

		$alertId = (int) $scanRead['alerts'][0]['id'];
		$alert   = $this->callToolOk('panopticon_admintools_scanalert_read', ['scanId' => $scanId, 'alertId' => $alertId]);

		$this->assertKeysPresent($alert, ['id', 'path', 'scan_id', 'diff', 'threat_score', 'acknowledged']);
		$this->assertSame($alertId, $alert['id']);
		$this->assertSame($scanId, $alert['scan_id']);

		$this->assertNotFound($this->callTool('panopticon_admintools_scanalert_read', ['scanId' => 999999, 'alertId' => $alertId]));
	}

	public function testScannerStepOnFreshSessionErrors(): void
	{
		[$freshSessionId] = $this->initializeSession();

		$response = $this->rpc(
			'tools/call',
			['name' => 'panopticon_admintools_scanner_step', 'arguments' => (object) []],
			1,
			$freshSessionId
		);

		$this->assertStatus(200, $response, 'tools/call did not return HTTP 200: ' . $response['raw']);

		$text = $response['json']['result']['content'][0]['text'] ?? null;
		$this->assertIsString($text);
		$this->assertTrue((bool) ($response['json']['result']['isError'] ?? false));
		$this->assertStringContainsString('No scan is in progress in this MCP session', $text);
	}

	public function testScanReadNonexistentIsNotFound(): void
	{
		$this->assertNotFound($this->callTool('panopticon_admintools_scan_read', ['id' => 999999]));
	}

	public function testScanAlertReadNonexistentIsNotFound(): void
	{
		$this->assertNotFound($this->callTool('panopticon_admintools_scanalert_read', ['scanId' => 1, 'alertId' => 999999]));
	}

	// -----------------------------------------------------------------------------------------------------
	// tempsuperuser
	// -----------------------------------------------------------------------------------------------------

	public function testTempSuperUserCreatesBoundedAccount(): void
	{
		$result = $this->callTool('panopticon_admintools_tempsuperuser', ['expiration' => 3600]);

		$this->assertFalse($result['isError'], 'tempsuperuser unexpectedly failed: ' . $result['text']);
		$this->assertStringNotContainsString('password2', $result['text']);

		$data = $result['data'];

		$this->assertStringStartsWith('tsu_', $data['username']);
		$this->assertSame(32, strlen($data['password']));
		$this->assertNotEmpty($data['groups']);
		$this->assertNotFalse(strtotime((string) $data['expiration']), 'expiration must be a parseable date/time string.');

		if (in_array('users_list', $this->listToolNames(), true) && in_array('users_delete', $this->listToolNames(), true))
		{
			$found = $this->callToolOk('users_list', ['filterSearch' => $data['username']]);
			$this->assertCount(1, $found['items'], 'Expected exactly one user matching the generated username.');

			$userId = (int) $found['items'][0]['id'];
			$this->callTool('users_delete', ['id' => $userId]);
		}
	}

	// -----------------------------------------------------------------------------------------------------
	// Low-privilege ACL
	// -----------------------------------------------------------------------------------------------------

	public function testLowPrivilegeIsDeniedForEveryTool(): void
	{
		$this->assertPermissionDenied($this->callToolAsLowPriv('panopticon_admintools_unblock', ['ip' => '203.0.113.7']));
		$this->assertPermissionDenied($this->callToolAsLowPriv('panopticon_admintools_plugin_disable'));
		$this->assertPermissionDenied($this->callToolAsLowPriv('panopticon_admintools_htaccess_disable'));
		$this->assertPermissionDenied($this->callToolAsLowPriv('panopticon_admintools_scans_list'));

		$before = null;

		if (in_array('users_list', $this->listToolNames(), true))
		{
			$before = count($this->callToolOk('users_list', ['filterSearch' => 'tsu_'])['items']);
		}

		$this->assertPermissionDenied($this->callToolAsLowPriv('panopticon_admintools_tempsuperuser'));

		if ($before !== null)
		{
			$after = count($this->callToolOk('users_list', ['filterSearch' => 'tsu_'])['items']);
			$this->assertSame($before, $after, 'A refused tempsuperuser call must not have created a user.');
		}
	}
}
