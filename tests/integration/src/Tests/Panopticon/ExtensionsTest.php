<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Integration\Tests\Panopticon;

use Akeeba\Mcp4j\Tests\Integration\AbstractToolTestCase;

/**
 * End-to-end coverage for the three `panopticon_extensions_*` tools
 * ({@see \Akeeba\Component\Mcp4j\Administrator\Tool\Panopticon\Extensions}). See
 * `.plans/14-panopticon-core.md` Tests for the coverage this class is meant to satisfy.
 */
class ExtensionsTest extends AbstractToolTestCase
{
	private const TOOLS = ['panopticon_extensions_list', 'panopticon_extensions_read', 'panopticon_extensions_install'];

	protected function setUp(): void
	{
		parent::setUp();

		$this->requireToolsOrSkip(self::TOOLS);
	}

	public function testListShape(): void
	{
		$list = $this->callToolOk('panopticon_extensions_list');

		$this->assertListShape($list);
	}

	public function testListSearchFiltersToMatchingItemsOnly(): void
	{
		$list = $this->callToolOk('panopticon_extensions_list', ['filterSearch' => 'mcp4j']);

		$this->assertGreaterThanOrEqual(1, count($list['items']));

		foreach ($list['items'] as $item)
		{
			$matches = str_contains(strtolower((string) $item['name']), 'mcp4j')
				|| str_contains(strtolower((string) $item['element']), 'mcp4j');

			$this->assertTrue($matches, 'Every returned item must match the search term in name or element.');
		}
	}

	public function testListTypeFilterReturnsOnlyThatType(): void
	{
		$list = $this->callToolOk('panopticon_extensions_list', ['filterType' => 'plugin']);

		foreach ($list['items'] as $item)
		{
			$this->assertSame('plugin', $item['type']);
		}
	}

	public function testListPageLimitOne(): void
	{
		$unfiltered = $this->callToolOk('panopticon_extensions_list');
		$limited    = $this->callToolOk('panopticon_extensions_list', ['pageLimit' => 1]);

		$this->assertCount(1, $limited['items']);
		$this->assertGreaterThanOrEqual($unfiltered['pagination']['total'], $limited['pagination']['total']);
	}

	private function findMcp4jExtensionId(): ?int
	{
		$list = $this->callToolOk('panopticon_extensions_list', ['filterSearch' => 'mcp4j']);

		foreach ($list['items'] as $item)
		{
			if (($item['element'] ?? null) === 'com_mcp4j')
			{
				return (int) $item['extension_id'];
			}
		}

		return null;
	}

	public function testReadRoundTripsMcp4jAndSensitiveKeysAreAbsent(): void
	{
		$id = $this->findMcp4jExtensionId();

		if ($id === null)
		{
			$this->markTestSkipped('com_mcp4j was not found via panopticon_extensions_list.');
		}

		$item = $this->callToolOk('panopticon_extensions_read', ['id' => $id]);

		$this->assertSame('com_mcp4j', $item['element']);
		$this->assertSensitiveKeysAbsent($item);
	}

	public function testReadNonexistentIsNotFound(): void
	{
		$this->assertNotFound($this->callTool('panopticon_extensions_read', ['id' => 999999]));
	}

	public function testListSensitiveKeysAbsent(): void
	{
		$list = $this->callToolOk('panopticon_extensions_list', ['pageLimit' => 100]);

		foreach ($list['items'] as $item)
		{
			$this->assertSensitiveKeysAbsent($item);
		}
	}

	private function assertSensitiveKeysAbsent(array $item): void
	{
		$this->assertKeysAbsent($item, ['manifest_cache', 'params', 'custom_data']);

		foreach ((array) ($item['updatesites'] ?? []) as $site)
		{
			$extraQuery = (string) ($site['extra_query'] ?? '');

			$this->assertTrue(
				$extraQuery === '' || str_starts_with($extraQuery, '****'),
				'updatesites[*].extra_query must be empty or masked, got: ' . $extraQuery
			);
		}
	}

	public function testInstallRejectsNonUrl(): void
	{
		$text = $this->callToolError('panopticon_extensions_install', ['url' => 'not a url']);

		$this->assertStringContainsString('http(s)', $text);
	}

	public function testInstallRejectsNonHttpScheme(): void
	{
		$text = $this->callToolError('panopticon_extensions_install', ['url' => 'ftp://example.com/x.zip']);

		$this->assertStringContainsString('http(s)', $text);
	}

	public function testInstallRefusedWhenRemoteInstallDisabled(): void
	{
		$this->requireToolsOrSkip(['config_component_update', 'config_component_read']);

		$original = $this->callToolOk('config_component_read', ['componentName' => 'com_panopticon'])['allow_remote_install'] ?? 1;

		$this->callToolOk('config_component_update', [
			'componentName' => 'com_panopticon',
			'configData'    => ['allow_remote_install' => 0],
		]);
		$this->registerCleanup(fn () => $this->callTool('config_component_update', [
			'componentName' => 'com_panopticon',
			'configData'    => ['allow_remote_install' => $original],
		]));

		$text = $this->callToolError('panopticon_extensions_install', ['url' => 'https://example.com/x.zip']);

		$this->assertStringContainsString('Remote extension installation is disabled on this site.', $text);
	}

	public function testLowPrivilegeIsDeniedForEveryTool(): void
	{
		$this->assertPermissionDenied($this->callToolAsLowPriv('panopticon_extensions_list'));
		$this->assertPermissionDenied($this->callToolAsLowPriv('panopticon_extensions_install', ['url' => 'https://example.com/x.zip']));
	}
}
