<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Mapper\Installer;

use Akeeba\Component\Mcp4j\Administrator\Bridge\Support\OutputShaper;
use Akeeba\Component\Mcp4j\Administrator\Mapper\Installer\ExtensionMapper;
use PHPUnit\Framework\TestCase;

class ExtensionMapperTest extends TestCase
{
	// -----------------------------------------------------------------------------------------------------
	// listState()
	// -----------------------------------------------------------------------------------------------------

	public function testListStateWithEverythingNull(): void
	{
		$result = ExtensionMapper::listState(null, null, null);

		$this->assertSame(
			[
				'filter.search'     => '',
				'filter.type'       => '',
				'filter.status'     => '',
				'filter.client_id'  => '',
				'filter.folder'     => '',
				'filter.core'       => '',
				'filter.package_id' => '',
				'list.ordering'     => 'name',
				'list.direction'    => 'asc',
			],
			$result
		);
	}

	public function testListStateSearchIsSetWhenGiven(): void
	{
		$this->assertSame('mcp4j', ExtensionMapper::listState('mcp4j', null, null)['filter.search']);
	}

	public function testListStateSearchIsAlwaysPresentEvenWhenNull(): void
	{
		// Joomla 4.4's InstallerModel::_getList() calls str_replace() on this state key with no default —
		// it must always be set (to '' when not given), never absent. See listState()'s own docblock.
		$this->assertSame('', ExtensionMapper::listState(null, null, null)['filter.search']);
	}

	public function testListStateTypeIsSetWhenValid(): void
	{
		$this->assertSame('component', ExtensionMapper::listState(null, 'component', null)['filter.type']);
	}

	public function testListStateStatusIsStringified(): void
	{
		$this->assertSame('1', ExtensionMapper::listState(null, null, 1)['filter.status']);
		$this->assertSame('0', ExtensionMapper::listState(null, null, 0)['filter.status']);
	}

	public function testListStateInvalidTypeThrows(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid extension type: bogus');

		ExtensionMapper::listState(null, 'bogus', null);
	}

	public function testEveryValidTypeIsAccepted(): void
	{
		foreach (ExtensionMapper::VALID_TYPES as $type)
		{
			$result = ExtensionMapper::listState(null, $type, null);

			$this->assertSame($type, $result['filter.type']);
		}
	}

	// -----------------------------------------------------------------------------------------------------
	// renameId()
	// -----------------------------------------------------------------------------------------------------

	public function testRenameIdMovesExtensionIdToLeadingId(): void
	{
		$result = ExtensionMapper::renameId(['extension_id' => '700', 'name' => 'com_mcp4j']);

		$this->assertSame(['id' => 700, 'name' => 'com_mcp4j'], $result);
	}

	// -----------------------------------------------------------------------------------------------------
	// Output whitelist — a fixture row with dangerous manifest-merged keys must have them dropped.
	// -----------------------------------------------------------------------------------------------------

	public function testShapingDropsParamsManifestCacheAndCustomData(): void
	{
		$row = [
			'extension_id'  => 700,
			'name'          => 'com_mcp4j',
			'element'       => 'com_mcp4j',
			'type'          => 'component',
			'folder'        => '',
			'client_id'     => 1,
			'enabled'       => 1,
			'protected'     => 0,
			'locked'        => 0,
			'status'        => 1,
			'package_id'    => 0,
			'params'        => '{"licence_key":"SECRET-1234"}',
			'custom_data'   => 'anything',
			'system_data'   => 'anything',
			'manifest_cache' => '{"authorEmail":"dev@example.com"}',
			'authorEmail'   => 'dev@example.com',
			'authorUrl'     => 'https://example.com',
			'author_info'   => 'Jane <dev@example.com>',
		];

		$shaped = OutputShaper::shape($row, ExtensionMapper::RAW_LIST_FIELDS, ExtensionMapper::RAW_INT_FIELDS);
		$shaped = ExtensionMapper::renameId($shaped);

		foreach (['params', 'custom_data', 'system_data', 'manifest_cache', 'authorEmail', 'authorUrl', 'author_info'] as $key)
		{
			$this->assertArrayNotHasKey($key, $shaped, "\"$key\" must never be present in extension output.");
		}

		$this->assertSame(700, $shaped['id']);
	}

	// -----------------------------------------------------------------------------------------------------
	// Field whitelists — hard-coded so a future edit is deliberate.
	// -----------------------------------------------------------------------------------------------------

	public function testListFields(): void
	{
		$this->assertSame(
			['id', 'name', 'element', 'type', 'folder', 'client_id', 'client_translated', 'type_translated',
				'folder_translated', 'version', 'author', 'creationDate', 'enabled', 'protected', 'locked',
				'status', 'package_id'],
			ExtensionMapper::LIST_FIELDS
		);
	}

	public function testRawListFieldsStartWithExtensionId(): void
	{
		$this->assertSame('extension_id', ExtensionMapper::RAW_LIST_FIELDS[0]);
	}

	public function testValidTypesMatchesCoreExtensionTypes(): void
	{
		$this->assertSame(
			['component', 'module', 'plugin', 'template', 'library', 'package', 'file', 'language'],
			ExtensionMapper::VALID_TYPES
		);
	}
}
