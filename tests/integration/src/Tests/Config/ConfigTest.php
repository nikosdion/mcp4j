<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Integration\Tests\Config;

use Akeeba\Mcp4j\Tests\Integration\AbstractToolTestCase;

/**
 * End-to-end coverage for `config_application_read`/`config_application_update`/`config_component_read`/
 * `config_component_update` ({@see \Akeeba\Component\Mcp4j\Administrator\Tool\Config\Config}). See
 * `.plans/10-config-plugins-installer-languages.md` for the coverage this class is meant to satisfy.
 */
class ConfigTest extends AbstractToolTestCase
{
	public function testApplicationReadHasExpectedKeysAndRedactsSecrets(): void
	{
		$data = $this->callToolOk('config_application_read');

		$this->assertKeysPresent($data, ['sitename', 'list_limit']);

		// The Docker test site is always configured with a non-empty DB password and site secret, so both
		// must be redacted rather than empty.
		$this->assertSame('********', $data['password']);
		$this->assertSame('********', $data['secret']);
	}

	public function testApplicationUpdateChangesSitenameAndSurvivesAPasswordPlaceholderRoundTrip(): void
	{
		$original = $this->callToolOk('config_application_read')['sitename'];
		$newName  = $this->uniqueName('MCP4j Site');

		$this->registerCleanup(fn () => $this->callTool('config_application_update', ['configData' => ['sitename' => $original]]));

		$updated = $this->callToolOk('config_application_update', [
			'configData' => ['sitename' => $newName, 'password' => '********'],
		]);

		$this->assertSame($newName, $updated['sitename']);

		// Tolerate opcache not immediately seeing the freshly-written configuration.php.
		$read = $this->pollUntilSitenameIs($newName);
		$this->assertSame($newName, $read['sitename']);

		// The DB password round-tripped through the placeholder must have been left untouched: prove the
		// DB connection built from configuration.php still works at all by making another successful call.
		$this->callToolOk('config_application_read');
	}

	public function testApplicationUpdateRefusesToChangeTheSiteSecret(): void
	{
		$result = $this->callTool('config_application_update', ['configData' => ['secret' => 'x']]);

		$this->assertTrue($result['isError']);
		$this->assertStringContainsString('site secret', $result['text']);
	}

	public function testApplicationUpdateRejectsAJsonList(): void
	{
		$result = $this->callTool('config_application_update', ['configData' => '[1,2]']);

		$this->assertTrue($result['isError']);
	}

	public function testComponentReadContentHasSaveHistory(): void
	{
		$data = $this->callToolOk('config_component_read', ['componentName' => 'com_content']);

		$this->assertArrayHasKey('save_history', $data);
	}

	public function testComponentReadJoomlaUpdateRedactsUpdateTokenWhenPresent(): void
	{
		$data = $this->callToolOk('config_component_read', ['componentName' => 'com_joomlaupdate']);

		if (array_key_exists('update_token', $data) && $data['update_token'] !== '')
		{
			$this->assertSame('********', $data['update_token']);
		}
		else
		{
			$this->assertTrue(true, 'com_joomlaupdate has no update_token configured on this install; nothing to redact.');
		}
	}

	public function testComponentUpdateRoundTripsHistoryLimit(): void
	{
		// "history_limit" (an int field of com_content's own options, not the global list_limit) round-trips
		// through config_component_update/config_component_read.
		$original = $this->callToolOk('config_component_read', ['componentName' => 'com_content'])['history_limit'];
		$newValue = ((int) $original === 15) ? 20 : 15;

		$this->registerCleanup(fn () => $this->callTool('config_component_update', [
			'componentName' => 'com_content',
			'configData'    => ['history_limit' => $original],
		]));

		$updated = $this->callToolOk('config_component_update', [
			'componentName' => 'com_content',
			'configData'    => ['history_limit' => $newValue],
		]);

		$this->assertSame($newValue, (int) $updated['history_limit']);

		$read = $this->callToolOk('config_component_read', ['componentName' => 'com_content']);
		$this->assertSame($newValue, (int) $read['history_limit']);
	}

	public function testComponentReadNonexistentComponentIsNotFound(): void
	{
		$this->assertNotFound($this->callTool('config_component_read', ['componentName' => 'com_doesnotexist']));
	}

	public function testComponentReadInvalidComponentNameIsRejected(): void
	{
		$result = $this->callTool('config_component_read', ['componentName' => '../etc']);

		$this->assertTrue($result['isError']);
		$this->assertStringContainsString('Invalid component name', $result['text']);
	}

	public function testLowPrivilegeUserIsRefusedReadAndWrite(): void
	{
		$this->assertPermissionDenied($this->callToolAsLowPriv('config_application_read'));

		$before = $this->callToolOk('config_component_read', ['componentName' => 'com_content'])['history_limit'];

		$writeResult = $this->callToolAsLowPriv('config_component_update', [
			'componentName' => 'com_content',
			'configData'    => ['history_limit' => 99],
		]);
		$this->assertPermissionDenied($writeResult);

		$after = $this->callToolOk('config_component_read', ['componentName' => 'com_content'])['history_limit'];
		$this->assertSame($before, $after, 'A low-privilege write must not have changed anything.');
	}

	/**
	 * Retries `config_application_read` up to 5 times, 1 second apart, until `sitename` matches
	 * `$expected` — tolerates opcache not immediately seeing a freshly-written `configuration.php`.
	 */
	private function pollUntilSitenameIs(string $expected): array
	{
		$data = [];

		for ($attempt = 0; $attempt < 5; $attempt++)
		{
			$data = $this->callToolOk('config_application_read');

			if (($data['sitename'] ?? null) === $expected)
			{
				break;
			}

			sleep(1);
		}

		return $data;
	}
}
