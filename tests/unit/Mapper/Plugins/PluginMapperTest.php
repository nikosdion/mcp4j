<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Mapper\Plugins;

use Akeeba\Component\Mcp4j\Administrator\Mapper\Config\SecretRedactor;
use Akeeba\Component\Mcp4j\Administrator\Mapper\Plugins\PluginMapper;
use PHPUnit\Framework\TestCase;

class PluginMapperTest extends TestCase
{
	// -----------------------------------------------------------------------------------------------------
	// renameId()
	// -----------------------------------------------------------------------------------------------------

	public function testRenameIdMovesExtensionIdToLeadingId(): void
	{
		$result = PluginMapper::renameId(['extension_id' => '5', 'name' => 'Email Cloak']);

		$this->assertSame(['id' => 5, 'name' => 'Email Cloak'], $result);
		$this->assertIsInt($result['id']);
	}

	public function testRenameIdIsFirstKey(): void
	{
		$result = PluginMapper::renameId(['name' => 'Email Cloak', 'extension_id' => 5]);

		$this->assertSame('id', array_key_first($result));
	}

	public function testRenameIdWithoutExtensionIdIsUnchanged(): void
	{
		$item = ['name' => 'Email Cloak'];

		$this->assertSame($item, PluginMapper::renameId($item));
	}

	// -----------------------------------------------------------------------------------------------------
	// listState()
	// -----------------------------------------------------------------------------------------------------

	public function testListStateWithEverythingNull(): void
	{
		$result = PluginMapper::listState(null, null, null, null);

		$this->assertSame(
			['list.ordering' => 'name', 'list.direction' => 'asc', 'filter.enabled' => ''],
			$result
		);
	}

	public function testListStateFilterEnabledIsStringified(): void
	{
		$this->assertSame('0', PluginMapper::listState(null, 0, null, null)['filter.enabled']);
		$this->assertSame('1', PluginMapper::listState(null, 1, null, null)['filter.enabled']);
	}

	public function testListStateSearchFolderElementSetWhenGiven(): void
	{
		$result = PluginMapper::listState('cloak', 1, 'content', 'emailcloak');

		$this->assertSame('cloak', $result['filter.search']);
		$this->assertSame('content', $result['filter.folder']);
		$this->assertSame('emailcloak', $result['filter.element']);
	}

	public function testListStateSearchFolderElementAbsentWhenNull(): void
	{
		$result = PluginMapper::listState(null, null, null, null);

		$this->assertArrayNotHasKey('filter.search', $result);
		$this->assertArrayNotHasKey('filter.folder', $result);
		$this->assertArrayNotHasKey('filter.element', $result);
	}

	public function testListStateDirectionIsAlwaysTheLiteralAsc(): void
	{
		// PluginsModel concatenates list.direction straight into SQL with no binding — it must never be
		// anything other than a fixed literal.
		$this->assertSame('asc', PluginMapper::listState('x', 1, 'y', 'z')['list.direction']);
	}

	// -----------------------------------------------------------------------------------------------------
	// updateData()
	// -----------------------------------------------------------------------------------------------------

	public function testUpdateDataWithEverythingNullReturnsEmptyArray(): void
	{
		$this->assertSame([], PluginMapper::updateData(null, null, null, null));
	}

	public function testUpdateDataKeepsOnlyExplicitlyGivenScalarValues(): void
	{
		$this->assertSame(['enabled' => 0], PluginMapper::updateData(0, null, null, null));
		$this->assertSame(['access' => 3], PluginMapper::updateData(null, 3, null, null));
		$this->assertSame(['ordering' => 7], PluginMapper::updateData(null, null, 7, null));
	}

	public function testUpdateDataParamsAsArrayIsIncludedAsIs(): void
	{
		$result = PluginMapper::updateData(null, null, null, ['mode' => 1]);

		$this->assertSame(['mode' => 1], $result['params']);
	}

	public function testUpdateDataParamsAsJsonStringIsDecoded(): void
	{
		$result = PluginMapper::updateData(null, null, null, '{"mode":0}');

		$this->assertSame(['mode' => 0], $result['params']);
	}

	public function testUpdateDataParamsPlaceholderIsStripped(): void
	{
		$result = PluginMapper::updateData(null, null, null, ['mode' => 1, 'api_key' => SecretRedactor::PLACEHOLDER]);

		$this->assertSame(['mode' => 1], $result['params']);
	}

	public function testUpdateDataInvalidParamsJsonThrows(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		PluginMapper::updateData(null, null, null, 'not valid json');
	}

	public function testUpdateDataEverythingTogether(): void
	{
		$result = PluginMapper::updateData(1, 2, 3, ['mode' => 1]);

		$this->assertSame(
			['enabled' => 1, 'access' => 2, 'ordering' => 3, 'params' => ['mode' => 1]],
			$result
		);
	}

	// -----------------------------------------------------------------------------------------------------
	// isLockoutDisable()
	// -----------------------------------------------------------------------------------------------------

	public function testLockoutDetectsMcp4jWebservicesPluginDisable(): void
	{
		$this->assertTrue(PluginMapper::isLockoutDisable('webservices', 'mcp4j', ['enabled' => 0]));
	}

	public function testLockoutDetectsTokenAuthenticationPluginDisable(): void
	{
		$this->assertTrue(PluginMapper::isLockoutDisable('api-authentication', 'token', ['enabled' => 0]));
	}

	public function testLockoutDetectsUserTokenPluginDisable(): void
	{
		$this->assertTrue(PluginMapper::isLockoutDisable('user', 'token', ['enabled' => 0]));
	}

	public function testLockoutDoesNotMatchUnrelatedPlugins(): void
	{
		$this->assertFalse(PluginMapper::isLockoutDisable('content', 'emailcloak', ['enabled' => 0]));
	}

	public function testLockoutDoesNotMatchWhenEnabling(): void
	{
		$this->assertFalse(PluginMapper::isLockoutDisable('webservices', 'mcp4j', ['enabled' => 1]));
	}

	public function testLockoutDoesNotMatchWhenEnabledIsNotSet(): void
	{
		$this->assertFalse(PluginMapper::isLockoutDisable('webservices', 'mcp4j', ['access' => 1]));
	}

	// -----------------------------------------------------------------------------------------------------
	// Field whitelists — hard-coded so a future edit is deliberate.
	// -----------------------------------------------------------------------------------------------------

	public function testItemFieldsAndListFields(): void
	{
		$this->assertSame(
			['id', 'name', 'type', 'element', 'changelogurl', 'folder', 'client_id', 'enabled', 'access',
				'protected', 'checked_out', 'checked_out_time', 'ordering', 'state', 'note', 'params'],
			PluginMapper::ITEM_FIELDS
		);

		$this->assertSame(
			['id', 'name', 'element', 'folder', 'checked_out', 'checked_out_time', 'enabled', 'access',
				'ordering', 'editor', 'access_level', 'note'],
			PluginMapper::LIST_FIELDS
		);
	}

	public function testItemFieldsExcludesSensitiveKeys(): void
	{
		foreach (['password', 'otpKey', 'otep', 'activation'] as $key)
		{
			$this->assertNotContains($key, PluginMapper::ITEM_FIELDS);
			$this->assertNotContains($key, PluginMapper::LIST_FIELDS);
		}
	}

	public function testRawFieldListsHaveExtensionIdInsteadOfId(): void
	{
		$this->assertSame('extension_id', PluginMapper::RAW_ITEM_FIELDS[0]);
		$this->assertSame('extension_id', PluginMapper::RAW_LIST_FIELDS[0]);
		$this->assertContains('extension_id', PluginMapper::RAW_INT_FIELDS);
	}
}
