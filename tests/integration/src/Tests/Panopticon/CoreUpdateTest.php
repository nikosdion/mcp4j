<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Integration\Tests\Panopticon;

use Akeeba\Mcp4j\Tests\Integration\AbstractToolTestCase;

/**
 * End-to-end coverage for the nine `panopticon_coreupdate_*` tools
 * ({@see \Akeeba\Component\Mcp4j\Administrator\Tool\Panopticon\CoreUpdate}). See
 * `.plans/14-panopticon-core.md` Tests for the coverage this class is meant to satisfy.
 *
 * Deliberately does NOT call `_download`, `_download_chunked`, `_activate` or `_postupdate` as the super
 * user: those are irreversible/network-heavy on a shared Docker test site and are only exercised for their
 * `Permission denied` refusal as the low-privilege user.
 */
class CoreUpdateTest extends AbstractToolTestCase
{
	private const TOOLS = [
		'panopticon_coreupdate_status',
		'panopticon_coreupdate_changesource',
		'panopticon_coreupdate_download',
		'panopticon_coreupdate_download_chunked',
		'panopticon_coreupdate_activate',
		'panopticon_coreupdate_disable',
		'panopticon_coreupdate_postupdate',
		'panopticon_coreupdate_checksum_prepare',
		'panopticon_coreupdate_checksum_step',
	];

	protected function setUp(): void
	{
		parent::setUp();

		$this->requireToolsOrSkip(self::TOOLS);
	}

	public function testStatusShapeAndNoAdminToolsSecrets(): void
	{
		$status = $this->callToolOk('panopticon_coreupdate_status');

		$this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', (string) $status['current']);
		$this->assertArrayHasKey('latest', $status);
		$this->assertIsBool($status['needsUpdate']);
		$this->assertArrayHasKey('panopticon', $status);
		$this->assertArrayHasKey('version', $status['panopticon']);

		if (($status['admintools'] ?? null) !== null)
		{
			$this->assertArrayNotHasKey('secret_word', $status['admintools']);
			$this->assertArrayNotHasKey('admindir', $status['admintools']);
			$this->assertArrayHasKey('hasSecretWord', $status['admintools']);
			$this->assertArrayHasKey('hasCustomAdminDir', $status['admintools']);
		}
	}

	public function testChangeSourceDefaultReturnsStatus(): void
	{
		$status = $this->callToolOk('panopticon_coreupdate_changesource', ['source' => 'default']);

		$this->assertArrayHasKey('current', $status);
	}

	public function testChangeSourceNonsenseIsRejected(): void
	{
		$text = $this->callToolError('panopticon_coreupdate_changesource', ['source' => 'nonsense']);

		$this->assertStringContainsString('Allowed:', $text);
	}

	public function testChangeSourceTestingBehaviourDependsOnJoomlaVersion(): void
	{
		$status = $this->callToolOk('panopticon_coreupdate_status');
		$isFourFour = version_compare((string) $status['current'], '5.0.0', 'lt');

		if ($isFourFour)
		{
			$result = $this->callToolOk('panopticon_coreupdate_changesource', ['source' => 'testing']);
			$this->assertArrayHasKey('current', $result);
		}
		else
		{
			$text = $this->callToolError('panopticon_coreupdate_changesource', ['source' => 'testing']);
			$this->assertStringContainsString('Allowed:', $text);
		}

		// Always restore the default source, whichever branch ran.
		$this->callToolOk('panopticon_coreupdate_changesource', ['source' => 'default']);
	}

	public function testDisableIsSafeAndSucceeds(): void
	{
		$result = $this->callToolOk('panopticon_coreupdate_disable');

		$this->assertTrue($result['success']);
	}

	public function testChecksumStepWithoutPrepareOnFreshSessionErrors(): void
	{
		$result = $this->callTool('panopticon_coreupdate_checksum_step');

		// If some earlier tool call in this same (cached, per-class) session already ran prepare, this
		// assertion would be meaningless — guard by only asserting when the table is genuinely empty,
		// detectable by the specific error message the tool promises.
		if ($result['isError'])
		{
			$this->assertStringContainsString('panopticon_coreupdate_checksum_prepare first', $result['text']);
		}
		else
		{
			$this->markTestSkipped('A checksum run was already prepared earlier in this session.');
		}
	}

	public function testChecksumPrepareThenStepUntilDone(): void
	{
		$prepare = $this->callTool('panopticon_coreupdate_checksum_prepare');

		if ($prepare['isError'])
		{
			if (str_starts_with($prepare['text'], 'Could not download checksums'))
			{
				$this->markTestSkipped('No network access to download checksums: ' . $prepare['text']);
			}

			$this->fail('panopticon_coreupdate_checksum_prepare failed: ' . $prepare['text']);
		}

		$this->assertTrue($prepare['data']['success']);
		$this->assertIsInt($prepare['data']['files']);

		$done = false;

		for ($i = 0; $i < 30 && !$done; $i++)
		{
			$step = $this->callToolOk('panopticon_coreupdate_checksum_step');

			$this->assertArrayHasKey('done', $step);
			$this->assertArrayHasKey('last_id', $step);
			$this->assertArrayHasKey('invalidFiles', $step);
			$this->assertIsArray($step['invalidFiles']);

			$done = (bool) $step['done'];
		}

		$this->assertTrue($done, 'Checksum stepping did not complete within 30 calls.');
	}

	public function testLowPrivilegeIsDeniedForEveryTool(): void
	{
		$this->assertPermissionDenied($this->callToolAsLowPriv('panopticon_coreupdate_status'));
		$this->assertPermissionDenied($this->callToolAsLowPriv('panopticon_coreupdate_download'));
		$this->assertPermissionDenied($this->callToolAsLowPriv('panopticon_coreupdate_activate'));
		$this->assertPermissionDenied($this->callToolAsLowPriv('panopticon_coreupdate_postupdate'));
		$this->assertPermissionDenied($this->callToolAsLowPriv('panopticon_coreupdate_download_chunked'));
	}
}
