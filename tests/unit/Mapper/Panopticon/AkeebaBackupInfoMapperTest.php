<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Mapper\Panopticon;

use Akeeba\Component\Mcp4j\Administrator\Mapper\Panopticon\AkeebaBackupInfoMapper;
use PHPUnit\Framework\TestCase;

class AkeebaBackupInfoMapperTest extends TestCase
{
	// -----------------------------------------------------------------------------------------------------
	// componentElement()
	// -----------------------------------------------------------------------------------------------------

	public function testComponentElementPrefersAkeebaBackup(): void
	{
		$this->assertSame('com_akeebabackup', AkeebaBackupInfoMapper::componentElement(true, true));
	}

	public function testComponentElementFallsBackToLegacyAkeeba(): void
	{
		$this->assertSame('com_akeeba', AkeebaBackupInfoMapper::componentElement(false, true));
	}

	public function testComponentElementNullWhenNeitherEnabled(): void
	{
		$this->assertNull(AkeebaBackupInfoMapper::componentElement(false, false));
	}

	// -----------------------------------------------------------------------------------------------------
	// versionFromManifestXml()
	// -----------------------------------------------------------------------------------------------------

	public function testVersionFromManifestXmlExtractsVersion(): void
	{
		$xml = "<extension><version>9.5.2</version></extension>";

		$this->assertSame('9.5.2', AkeebaBackupInfoMapper::versionFromManifestXml($xml));
	}

	public function testVersionFromManifestXmlToleratesWhitespace(): void
	{
		$xml = "<extension><version>\n\t9.5.2\n\t</version></extension>";

		$this->assertSame('9.5.2', AkeebaBackupInfoMapper::versionFromManifestXml($xml));
	}

	public function testVersionFromManifestXmlNullWhenAbsent(): void
	{
		$this->assertNull(AkeebaBackupInfoMapper::versionFromManifestXml('<extension></extension>'));
	}

	// -----------------------------------------------------------------------------------------------------
	// versionFromManifestCache()
	// -----------------------------------------------------------------------------------------------------

	public function testVersionFromManifestCacheExtractsVersion(): void
	{
		$this->assertSame('9.5.2', AkeebaBackupInfoMapper::versionFromManifestCache('{"version":"9.5.2"}'));
	}

	public function testVersionFromManifestCacheNullWhenMissing(): void
	{
		$this->assertNull(AkeebaBackupInfoMapper::versionFromManifestCache('{"name":"foo"}'));
	}

	public function testVersionFromManifestCacheNullWhenInvalidJson(): void
	{
		$this->assertNull(AkeebaBackupInfoMapper::versionFromManifestCache('not json'));
	}

	// -----------------------------------------------------------------------------------------------------
	// apiLevel()
	// -----------------------------------------------------------------------------------------------------

	public function testApiLevelReturnsLevelThreeWhenFirstPathExists(): void
	{
		$files = [
			'api-controller'  => true,
			'site-controller' => true,
			'controller-api'  => false,
			'controller-json' => false,
			'controllers-json' => false,
		];

		$this->assertSame('3', AkeebaBackupInfoMapper::apiLevel($files));
	}

	public function testApiLevelReturnsLevelTwoForSecondOrThirdPath(): void
	{
		$files = [
			'api-controller'   => false,
			'site-controller'  => true,
			'controller-api'   => false,
			'controller-json'  => false,
			'controllers-json' => false,
		];

		$this->assertSame('2', AkeebaBackupInfoMapper::apiLevel($files));

		$files2 = [
			'api-controller'   => false,
			'site-controller'  => false,
			'controller-api'   => true,
			'controller-json'  => false,
			'controllers-json' => false,
		];

		$this->assertSame('2', AkeebaBackupInfoMapper::apiLevel($files2));
	}

	public function testApiLevelReturnsLevelOneForFourthOrFifthPath(): void
	{
		$files = [
			'api-controller'   => false,
			'site-controller'  => false,
			'controller-api'   => false,
			'controller-json'  => true,
			'controllers-json' => false,
		];

		$this->assertSame('1', AkeebaBackupInfoMapper::apiLevel($files));
	}

	public function testApiLevelNullWhenNoneExist(): void
	{
		$files = [
			'api-controller'   => false,
			'site-controller'  => false,
			'controller-api'   => false,
			'controller-json'  => false,
			'controllers-json' => false,
		];

		$this->assertNull(AkeebaBackupInfoMapper::apiLevel($files));
	}

	// -----------------------------------------------------------------------------------------------------
	// endpoints()
	// -----------------------------------------------------------------------------------------------------

	public function testEndpointsApiLevelThree(): void
	{
		$result = AkeebaBackupInfoMapper::endpoints('3', 'https://example.com/api/', 'com_akeebabackup');

		$this->assertArrayHasKey('v3', $result);
		$this->assertArrayHasKey('v2', $result);
		$this->assertArrayNotHasKey('v1', $result);
		$this->assertSame(['https://example.com/api/index.php/v3/akeebabackup'], $result['v3']);
	}

	public function testEndpointsApiLevelTwo(): void
	{
		$result = AkeebaBackupInfoMapper::endpoints('2', 'https://example.com/api/', 'com_akeebabackup');

		$this->assertArrayHasKey('v2', $result);
		$this->assertArrayHasKey('v1', $result);
		$this->assertArrayNotHasKey('v3', $result);
	}

	public function testEndpointsApiLevelOne(): void
	{
		$result = AkeebaBackupInfoMapper::endpoints('1', 'https://example.com/api/', 'com_akeebabackup');

		$this->assertArrayHasKey('v1', $result);
		$this->assertArrayNotHasKey('v2', $result);
		$this->assertArrayNotHasKey('v3', $result);
		$this->assertSame(
			['https://example.com/index.php?option=com_akeebabackup&view=json'],
			$result['v1']
		);
	}

	public function testEndpointsApiLevelNullFallsBackToV1(): void
	{
		$result = AkeebaBackupInfoMapper::endpoints(null, 'https://example.com/api/', 'com_akeebabackup');

		$this->assertArrayHasKey('v1', $result);
		$this->assertArrayNotHasKey('v2', $result);
		$this->assertArrayNotHasKey('v3', $result);
	}
}
