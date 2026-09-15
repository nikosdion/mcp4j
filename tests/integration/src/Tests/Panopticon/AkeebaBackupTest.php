<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Integration\Tests\Panopticon;

use Akeeba\Mcp4j\Tests\Integration\AbstractToolTestCase;

/**
 * End-to-end coverage for `panopticon_akeebabackup_info`
 * ({@see \Akeeba\Component\Mcp4j\Administrator\Tool\Panopticon\AkeebaBackup}). See
 * `.plans/14-panopticon-core.md` Tests for the coverage this class is meant to satisfy.
 */
class AkeebaBackupTest extends AbstractToolTestCase
{
	private const TOOLS = ['panopticon_akeebabackup_info'];

	protected function setUp(): void
	{
		parent::setUp();

		$this->requireToolsOrSkip(self::TOOLS);
	}

	public function testInfoShapeAndNoSecretLeak(): void
	{
		$result = $this->callTool('panopticon_akeebabackup_info');

		$this->assertFalse($result['isError']);
		$this->assertStringNotContainsString('"secret"', $result['text']);

		$data = $result['data'];

		$this->assertIsBool($data['installed']);
		$this->assertIsBool($data['hasSecretWord']);

		if ($data['installed'])
		{
			$this->assertMatchesRegularExpression('/^\d+\.\d+/', (string) $data['version']);
		}
	}

	public function testInfoDoesNotCreateASecretWord(): void
	{
		$info = $this->callToolOk('panopticon_akeebabackup_info');

		if (!$info['installed'] || $info['component'] === null)
		{
			$this->markTestSkipped('Akeeba Backup is not installed on this site.');
		}

		$this->requireToolsOrSkip(['config_component_read']);

		$before = $this->callToolOk('config_component_read', ['componentName' => $info['component']])['frontend_secret_word'] ?? null;

		$this->callToolOk('panopticon_akeebabackup_info');

		$after = $this->callToolOk('config_component_read', ['componentName' => $info['component']])['frontend_secret_word'] ?? null;

		$this->assertSame($before, $after, 'panopticon_akeebabackup_info must never create/change a Secret Word.');
	}

	public function testLowPrivilegeIsDenied(): void
	{
		$this->assertPermissionDenied($this->callToolAsLowPriv('panopticon_akeebabackup_info'));
	}
}
