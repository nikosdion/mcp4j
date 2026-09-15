<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Integration\Tests\Plugins;

use Akeeba\Mcp4j\Tests\Integration\AbstractToolTestCase;

/**
 * End-to-end coverage for the three `plugins_*` tools
 * ({@see \Akeeba\Component\Mcp4j\Administrator\Tool\Plugins\Plugins}). See
 * `.plans/10-config-plugins-installer-languages.md` for the coverage this class is meant to satisfy.
 */
class PluginsTest extends AbstractToolTestCase
{
	public function testListFilterFolderAndElementFindsExactlyOneItem(): void
	{
		$result = $this->callToolOk('plugins_list', ['filterFolder' => 'content', 'filterElement' => 'emailcloak']);

		$this->assertListShape($result);
		$this->assertCount(1, $result['items']);
		$this->assertSame('emailcloak', $result['items'][0]['element']);
		$this->assertSame('content', $result['items'][0]['folder']);
	}

	public function testListFilterStatePartitionsEnabledAndDisabled(): void
	{
		// A stock Joomla install always ships several plugins disabled by default (e.g. sampledata,
		// several third-party captcha plugins) — the disabled partition must be non-empty.
		$disabled = $this->callToolOk('plugins_list', ['filterState' => 0]);
		$this->assertListShape($disabled);
		$this->assertNotSame([], $disabled['items'], 'Expected at least one disabled plugin on a fresh install.');

		foreach ($disabled['items'] as $item)
		{
			$this->assertSame(0, $item['enabled']);
		}

		$enabled = $this->callToolOk('plugins_list', ['filterState' => 1]);
		$this->assertListShape($enabled);
		$this->assertNotSame([], $enabled['items']);

		foreach ($enabled['items'] as $item)
		{
			$this->assertSame(1, $item['enabled']);
		}
	}

	public function testReadEmailcloakHasParamsIdAndNoXmlKey(): void
	{
		$id   = $this->emailcloakId();
		$item = $this->callToolOk('plugins_read', ['id' => $id]);

		$this->assertIsInt($item['id']);
		$this->assertIsArray($item['params']);
		$this->assertArrayNotHasKey('xml', $item);
	}

	public function testUpdateEmailcloakParamsChangesModeButLeavesOrderingUntouched(): void
	{
		$id      = $this->emailcloakId();
		$before  = $this->callToolOk('plugins_read', ['id' => $id]);
		$flipped = ((int) ($before['params']['mode'] ?? 1)) === 1 ? 0 : 1;

		$this->registerCleanup(fn () => $this->callTool('plugins_update', [
			'id'     => $id,
			'params' => ['mode' => (int) ($before['params']['mode'] ?? 1)],
		]));

		$updated = $this->callToolOk('plugins_update', ['id' => $id, 'params' => ['mode' => $flipped]]);

		$this->assertSame($flipped, (int) $updated['params']['mode']);
		$this->assertSame($before['ordering'], $updated['ordering'], 'A params-only update must not touch ordering.');

		$read = $this->callToolOk('plugins_read', ['id' => $id]);
		$this->assertSame($flipped, (int) $read['params']['mode']);
	}

	public function testUpdateRefusesToDisableTheMcp4jWebservicesPlugin(): void
	{
		$list = $this->callToolOk('plugins_list', ['filterFolder' => 'webservices', 'filterElement' => 'mcp4j']);
		$this->assertCount(1, $list['items'], 'Expected the webservices/mcp4j plugin to exist on this install.');
		$id = $list['items'][0]['id'];

		$result = $this->callTool('plugins_update', ['id' => $id, 'enabled' => 0]);

		$this->assertTrue($result['isError']);
		$this->assertStringContainsString('disconnect this MCP session', $result['text']);

		$after = $this->callToolOk('plugins_list', ['filterFolder' => 'webservices', 'filterElement' => 'mcp4j']);
		$this->assertSame(1, $after['items'][0]['enabled'], 'The lockout guard must have prevented the plugin from being disabled.');
	}

	public function testReadNonexistentIdIsNotFound(): void
	{
		$this->assertNotFound($this->callTool('plugins_read', ['id' => 999999]));
	}

	public function testLowPrivilegeUserIsRefusedReadAndWrite(): void
	{
		$this->assertPermissionDenied($this->callToolAsLowPriv('plugins_list'));

		$id      = $this->emailcloakId();
		$result  = $this->callToolAsLowPriv('plugins_update', ['id' => $id, 'access' => 5]);
		$this->assertPermissionDenied($result);
	}

	private function emailcloakId(): int
	{
		$list = $this->callToolOk('plugins_list', ['filterFolder' => 'content', 'filterElement' => 'emailcloak']);

		return (int) $list['items'][0]['id'];
	}
}
