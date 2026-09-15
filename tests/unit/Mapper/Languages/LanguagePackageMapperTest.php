<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Mapper\Languages;

use Akeeba\Component\Mcp4j\Administrator\Mapper\Languages\LanguagePackageMapper;
use PHPUnit\Framework\TestCase;

class LanguagePackageMapperTest extends TestCase
{
	private const ITEMS = [
		['name' => 'French (France)', 'element' => 'pkg_fr-FR', 'version' => '6.1.0.1', 'type' => 'package', 'detailsurl' => 'https://example.com/fr-FR.xml'],
		['name' => 'German (Germany)', 'element' => 'pkg_de-DE', 'version' => '6.1.0.1', 'type' => 'package', 'detailsurl' => 'https://example.com/de-DE.xml'],
	];

	public function testFindPackageMatchesBarePackageIdentifier(): void
	{
		$found = LanguagePackageMapper::findPackage(self::ITEMS, 'fr-FR');

		$this->assertNotNull($found);
		$this->assertSame('pkg_fr-FR', $found['element']);
	}

	public function testFindPackageMatchesFullElementName(): void
	{
		$found = LanguagePackageMapper::findPackage(self::ITEMS, 'pkg_de-DE');

		$this->assertNotNull($found);
		$this->assertSame('pkg_de-DE', $found['element']);
	}

	public function testFindPackageIsCaseInsensitive(): void
	{
		$found = LanguagePackageMapper::findPackage(self::ITEMS, 'FR-fr');

		$this->assertNotNull($found);
		$this->assertSame('pkg_fr-FR', $found['element']);
	}

	public function testFindPackageReturnsNullWhenNotFound(): void
	{
		$this->assertNull(LanguagePackageMapper::findPackage(self::ITEMS, 'xx-XX'));
	}

	public function testFindPackageOnEmptyItemsReturnsNull(): void
	{
		$this->assertNull(LanguagePackageMapper::findPackage([], 'fr-FR'));
	}

	public function testListFieldsMatchesCoreJsonapiView(): void
	{
		$this->assertSame(['name', 'element', 'version', 'type', 'detailsurl'], LanguagePackageMapper::LIST_FIELDS);
	}
}
