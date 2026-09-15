<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Integration\Tests\Installer;

use Akeeba\Mcp4j\Tests\Integration\AbstractToolTestCase;

/**
 * End-to-end coverage for `installer_extensions_list`
 * ({@see \Akeeba\Component\Mcp4j\Administrator\Tool\Installer\Extensions}). See
 * `.plans/10-config-plugins-installer-languages.md` for the coverage this class is meant to satisfy.
 */
class ExtensionsTest extends AbstractToolTestCase
{
	public function testFilterTypeComponentReturnsOnlyComponentsAndIncludesMcp4j(): void
	{
		$result = $this->callToolOk('installer_extensions_list', ['filterType' => 'component', 'pageLimit' => 100]);
		$this->assertListShape($result);
		$this->assertNotSame([], $result['items']);

		foreach ($result['items'] as $item)
		{
			$this->assertSame('component', $item['type']);
		}

		$search = $this->callToolOk('installer_extensions_list', ['filterSearch' => 'mcp4j']);
		$elements = array_column($search['items'], 'element');
		$this->assertContains('com_mcp4j', $elements);
	}

	public function testNoItemLeaksParamsManifestCacheOrCustomData(): void
	{
		$result = $this->callToolOk('installer_extensions_list', ['pageLimit' => 100]);

		foreach ($result['items'] as $item)
		{
			$this->assertKeysAbsent($item, ['params', 'manifest_cache', 'custom_data', 'system_data', 'authorEmail', 'authorUrl', 'author_info']);
		}
	}

	public function testFilterTypeBogusIsRejected(): void
	{
		$result = $this->callTool('installer_extensions_list', ['filterType' => 'bogus']);

		$this->assertTrue($result['isError']);
		$this->assertStringContainsString('Invalid extension type', $result['text']);
	}

	public function testLowPrivilegeUserIsRefused(): void
	{
		$this->assertPermissionDenied($this->callToolAsLowPriv('installer_extensions_list'));
	}
}
