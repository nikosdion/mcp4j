<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Mapper\Panopticon;

use Akeeba\Component\Mcp4j\Administrator\Mapper\Panopticon\ExtensionMapper;
use PHPUnit\Framework\TestCase;

class ExtensionMapperTest extends TestCase
{
	// -----------------------------------------------------------------------------------------------------
	// Field list constants
	// -----------------------------------------------------------------------------------------------------

	public function testListFieldsMatchConnectorView(): void
	{
		$this->assertSame(
			[
				'extension_id', 'package_id', 'type', 'folder', 'element', 'client_id', 'client_translated',
				'type_translated', 'folder_translated', 'state', 'enabled', 'access', 'protected', 'locked',
				'name', 'description', 'author', 'authorUrl', 'authorEmail', 'version', 'new_version',
				'detailsurl', 'infourl', 'changelogurl', 'updatesites', 'downloadkey', 'naughtyUpdates',
			],
			ExtensionMapper::LIST_FIELDS
		);
	}

	public function testItemFieldsAreListFieldsMinusNaughtyUpdates(): void
	{
		$this->assertSame(
			array_values(array_diff(ExtensionMapper::LIST_FIELDS, ['naughtyUpdates'])),
			ExtensionMapper::ITEM_FIELDS
		);
		$this->assertNotContains('naughtyUpdates', ExtensionMapper::ITEM_FIELDS);
	}

	// -----------------------------------------------------------------------------------------------------
	// sanitise()
	// -----------------------------------------------------------------------------------------------------

	public function testSanitiseMasksUpdateSiteExtraQuery(): void
	{
		$item = [
			'updatesites' => [
				['update_site_id' => 1, 'name' => 'Site', 'extra_query' => 'dlid=abcdef123456'],
			],
		];

		$result = ExtensionMapper::sanitise($item);

		$this->assertStringStartsWith('****', $result['updatesites'][0]['extra_query']);
		$this->assertSame('3456', substr($result['updatesites'][0]['extra_query'], -4));
	}

	public function testSanitiseCoercesUpdateSiteIntFields(): void
	{
		$item = [
			'updatesites' => [
				['update_site_id' => '5', 'enabled' => '1', 'last_check_timestamp' => '1700000000'],
			],
		];

		$result = ExtensionMapper::sanitise($item);

		$this->assertSame(5, $result['updatesites'][0]['update_site_id']);
		$this->assertSame(1, $result['updatesites'][0]['enabled']);
		$this->assertSame(1700000000, $result['updatesites'][0]['last_check_timestamp']);
	}

	public function testSanitiseDropsUnknownUpdateSiteKeys(): void
	{
		$item = [
			'updatesites' => [
				['update_site_id' => 1, 'downloadIdPrefix' => 'x', 'downloadIdSuffix' => 'y', 'secret' => 'z'],
			],
		];

		$result = ExtensionMapper::sanitise($item);

		$this->assertArrayNotHasKey('downloadIdPrefix', $result['updatesites'][0]);
		$this->assertArrayNotHasKey('downloadIdSuffix', $result['updatesites'][0]);
		$this->assertArrayNotHasKey('secret', $result['updatesites'][0]);
	}

	public function testSanitiseMasksDownloadKeyValue(): void
	{
		$item = [
			'downloadkey' => [
				'supported' => true,
				'valid'     => true,
				'prefix'    => 'dlid=',
				'suffix'    => '',
				'value'     => 'abcdef0123456789',
			],
		];

		$result = ExtensionMapper::sanitise($item);

		$this->assertSame(true, $result['downloadkey']['supported']);
		$this->assertSame(true, $result['downloadkey']['valid']);
		$this->assertSame('dlid=', $result['downloadkey']['prefix']);
		$this->assertStringStartsWith('****', $result['downloadkey']['value']);
	}

	public function testSanitiseDropsUnsupportedDownloadKeyKeys(): void
	{
		$item = [
			'downloadkey' => [
				'value'      => 'abcdef0123456789',
				'raw_secret' => 'do-not-leak',
			],
		];

		$result = ExtensionMapper::sanitise($item);

		$this->assertArrayNotHasKey('raw_secret', $result['downloadkey']);
	}

	public function testSanitiseMasksTopLevelExtraQueryDefensively(): void
	{
		$item = ['extra_query' => 'dlid=abcdef123456'];

		$result = ExtensionMapper::sanitise($item);

		$this->assertStringStartsWith('****', $result['extra_query']);
	}

	public function testSanitiseLeavesItemWithoutSecretsUnchanged(): void
	{
		$item = ['extension_id' => 1, 'name' => 'com_content'];

		$this->assertSame($item, ExtensionMapper::sanitise($item));
	}

	// -----------------------------------------------------------------------------------------------------
	// filterAndPaginate()
	// -----------------------------------------------------------------------------------------------------

	private function sampleItems(): array
	{
		return [
			['extension_id' => 1, 'name' => 'MCP4j', 'element' => 'com_mcp4j', 'type' => 'component'],
			['extension_id' => 2, 'name' => 'System - Cache', 'element' => 'system', 'type' => 'plugin'],
			['extension_id' => 3, 'name' => 'Cassiopeia', 'element' => 'cassiopeia', 'type' => 'template'],
			['extension_id' => 4, 'name' => 'Another Plugin', 'element' => 'anotherplugin', 'type' => 'plugin'],
		];
	}

	public function testFilterAndPaginateWithNoFiltersReturnsEverything(): void
	{
		$result = ExtensionMapper::filterAndPaginate($this->sampleItems(), null, null, 20, 0);

		$this->assertCount(4, $result['items']);
		$this->assertSame(4, $result['total']);
	}

	public function testFilterAndPaginateSearchMatchesNameCaseInsensitively(): void
	{
		$result = ExtensionMapper::filterAndPaginate($this->sampleItems(), 'mcp4j', null, 20, 0);

		$this->assertSame(1, $result['total']);
		$this->assertSame('MCP4j', $result['items'][0]['name']);
	}

	public function testFilterAndPaginateSearchMatchesElement(): void
	{
		$result = ExtensionMapper::filterAndPaginate($this->sampleItems(), 'cassiopeia', null, 20, 0);

		$this->assertSame(1, $result['total']);
		$this->assertSame('cassiopeia', $result['items'][0]['element']);
	}

	public function testFilterAndPaginateTypeFilterIsExactCaseInsensitive(): void
	{
		$result = ExtensionMapper::filterAndPaginate($this->sampleItems(), null, 'Plugin', 20, 0);

		$this->assertSame(2, $result['total']);

		foreach ($result['items'] as $item)
		{
			$this->assertSame('plugin', $item['type']);
		}
	}

	public function testFilterAndPaginateCombinesSearchAndType(): void
	{
		$result = ExtensionMapper::filterAndPaginate($this->sampleItems(), 'plugin', 'plugin', 20, 0);

		$this->assertSame(1, $result['total']);
		$this->assertSame('Another Plugin', $result['items'][0]['name']);
	}

	public function testFilterAndPaginateOffsetBeyondTotalReturnsEmptyItemsWithCorrectTotal(): void
	{
		$result = ExtensionMapper::filterAndPaginate($this->sampleItems(), null, null, 20, 100);

		$this->assertSame([], $result['items']);
		$this->assertSame(4, $result['total']);
	}

	public function testFilterAndPaginateLimitsAndReindexes(): void
	{
		$result = ExtensionMapper::filterAndPaginate($this->sampleItems(), null, null, 2, 1);

		$this->assertSame(4, $result['total']);
		$this->assertCount(2, $result['items']);
		$this->assertSame([0, 1], array_keys($result['items']));
		$this->assertSame(2, $result['items'][0]['extension_id']);
	}
}
