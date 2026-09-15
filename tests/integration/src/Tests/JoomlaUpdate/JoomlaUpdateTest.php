<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Integration\Tests\JoomlaUpdate;

use Akeeba\Mcp4j\Tests\Integration\AbstractToolTestCase;

/**
 * End-to-end coverage for the six `joomlaupdate_*` tools
 * ({@see \Akeeba\Component\Mcp4j\Administrator\Tool\JoomlaUpdate\JoomlaUpdate}).
 *
 * These tools can replace the entire CMS installation, so — per `.plans/13-media-joomlaupdate.md` — this
 * class **never** calls `joomlaupdate_prepare`/`joomlaupdate_finalize` in a way that could actually run a
 * core update: it only exercises the read-only tools, the version-gate (absent below 5.4, present at/above
 * it), and the refusal paths (ACL, "automated updates disabled"), the last gated behind a live check that
 * automated updates are actually off on the test site before it ever calls `prepare`.
 */
class JoomlaUpdateTest extends AbstractToolTestCase
{
	private static ?string $joomlaVersion = null;

	/**
	 * The installed Joomla core version, read via the (already-ported, version-independent)
	 * `installer_extensions_list` tool rather than any `joomlaupdate_*` tool — see the plan.
	 */
	private function joomlaVersion(): string
	{
		if (self::$joomlaVersion !== null)
		{
			return self::$joomlaVersion;
		}

		$result = $this->callToolOk('installer_extensions_list', [
			'filterType'   => 'file',
			'filterSearch' => 'joomla',
		]);

		$joomla = null;

		foreach ($result['items'] as $item)
		{
			if (($item['element'] ?? null) === 'joomla')
			{
				$joomla = $item;
				break;
			}
		}

		$this->assertNotNull($joomla, 'Could not find the "joomla" file extension via installer_extensions_list: ' . json_encode($result));

		self::$joomlaVersion = (string) $joomla['version'];

		return self::$joomlaVersion;
	}

	private function skipUnlessAtLeast54(): void
	{
		if (version_compare($this->joomlaVersion(), '5.4.0', '<'))
		{
			$this->markTestSkipped('joomlaupdate_* tools require Joomla >= 5.4.0 (installed: ' . $this->joomlaVersion() . ').');
		}
	}

	private const TOOL_NAMES = [
		'joomlaupdate_healthcheck',
		'joomlaupdate_getupdate',
		'joomlaupdate_prepare',
		'joomlaupdate_finalize',
		'joomlaupdate_notify_success',
		'joomlaupdate_notify_failed',
	];

	public function testVersionGate(): void
	{
		$available = $this->listToolNames();

		if (version_compare($this->joomlaVersion(), '5.4.0', '<'))
		{
			foreach (self::TOOL_NAMES as $name)
			{
				$this->assertNotContains($name, $available, "$name must not be listed on Joomla < 5.4.0.");
			}
		}
		else
		{
			foreach (self::TOOL_NAMES as $name)
			{
				$this->assertContains($name, $available, "$name must be listed on Joomla >= 5.4.0.");
			}
		}
	}

	public function testHealthcheck(): void
	{
		$this->skipUnlessAtLeast54();

		$result = $this->callToolOk('joomlaupdate_healthcheck');

		$this->assertKeysPresent($result, [
			'php_version',
			'db_type',
			'db_version',
			'cms_version',
			'server_os',
			'update_requirement_state',
			'automated_updates_enabled',
		]);

		$this->assertSame($this->joomlaVersion(), $result['cms_version']);
		$this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', $result['db_version']);
		$this->assertIsBool($result['update_requirement_state']);
		$this->assertIsBool($result['automated_updates_enabled']);
	}

	public function testGetUpdate(): void
	{
		$this->skipUnlessAtLeast54();

		$result = $this->callTool('joomlaupdate_getupdate');

		if ($result['isError'])
		{
			// The Joomla Update Service may be unreachable from inside the Docker test network.
			$this->assertStringStartsWith('Cannot check for Joomla updates', $result['text']);
			$this->addToAssertionCount(1);
			fwrite(STDERR, "\n[JoomlaUpdateTest] joomlaupdate_getupdate could not reach the update service: " . $result['text'] . "\n");

			return;
		}

		$data = $result['data'];
		$this->assertKeysPresent($data, ['currentVersion', 'latestVersion', 'availableUpdate', 'automatedUpdatesEnabled']);
		$this->assertSame($this->joomlaVersion(), $data['currentVersion']);

		if ($data['availableUpdate'] !== null)
		{
			$this->assertGreaterThan(0, version_compare($data['availableUpdate'], $data['currentVersion']));
		}
	}

	/**
	 * Safety gate: only exercises the "automated updates are disabled" refusal path, and only after
	 * confirming (live, against this test run's own site) that automated updates really are off — never
	 * risks reaching `UpdateModel::prepareAutoUpdate()`/`finaliseUpgrade()`.
	 */
	public function testPrepareAndFinalizeRefuseWhenAutomatedUpdatesAreDisabled(): void
	{
		$this->skipUnlessAtLeast54();

		$config = $this->callToolOk('config_component_read', ['componentName' => 'com_joomlaupdate']);

		if (!empty($config['autoupdate']))
		{
			$this->markTestSkipped('autoupdate is enabled on the test site; refusing to risk a real update.');
		}

		$prepare = $this->callToolError('joomlaupdate_prepare');
		$this->assertStringContainsString('Automated updates are disabled', $prepare);

		$finalize = $this->callToolError('joomlaupdate_finalize');
		$this->assertStringContainsString('Automated updates are disabled', $finalize);
	}

	public function testNotifyFailedAsSuperUser(): void
	{
		$this->skipUnlessAtLeast54();

		// Mail is not delivered inside the Docker test environment, so this only proves the tool completes
		// and returns the documented shape — success reflects whether sendNotification() itself threw, not
		// whether an email actually reached anyone.
		$result = $this->callToolOk('joomlaupdate_notify_failed');
		$this->assertArrayHasKey('success', $result);
		$this->assertIsBool($result['success']);
	}

	public function testLowPrivilegeUserIsRefusedEveryTool(): void
	{
		$this->skipUnlessAtLeast54();

		foreach (self::TOOL_NAMES as $name)
		{
			// Safe to call prepare/finalize here too: the ACL check is the very first thing every tool
			// method does, well before anything that touches the filesystem or network.
			$result = $this->callToolAsLowPriv($name);
			$this->assertPermissionDenied($result, "$name must refuse the low-privilege user.");
		}
	}
}
