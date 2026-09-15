<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Mapper\Languages;

use Akeeba\Component\Mcp4j\Administrator\Mapper\Languages\OverrideMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OverrideMapperTest extends TestCase
{
	// -----------------------------------------------------------------------------------------------------
	// isWellFormedLanguageTag()
	// -----------------------------------------------------------------------------------------------------

	public static function acceptedTagProvider(): array
	{
		return [
			'two-letter + two-letter region' => ['en-GB'],
			'two-letter + two-letter region 2' => ['de-DE'],
			'script + region'                => ['sr-Latn-RS'],
		];
	}

	#[DataProvider('acceptedTagProvider')]
	public function testWellFormedTagsAreAccepted(string $tag): void
	{
		$this->assertTrue(OverrideMapper::isWellFormedLanguageTag($tag));
	}

	public static function rejectedTagProvider(): array
	{
		return [
			'path traversal prefix'   => ['../en-GB'],
			'path traversal suffix'   => ['en-GB/..'],
			'upper-case primary tag'  => ['EN-gb'],
			'no region'               => ['en'],
			'empty string'            => [''],
			'trailing extension'      => ['en-GB.override'],
		];
	}

	#[DataProvider('rejectedTagProvider')]
	public function testMalformedTagsAreRejected(string $tag): void
	{
		$this->assertFalse(OverrideMapper::isWellFormedLanguageTag($tag));
	}

	// -----------------------------------------------------------------------------------------------------
	// normaliseKey()
	// -----------------------------------------------------------------------------------------------------

	public function testNormaliseKeyUppercasesAndTrims(): void
	{
		$this->assertSame('MCP4J_TEST', OverrideMapper::normaliseKey('  mcp4j_test  '));
	}

	public function testNormaliseKeyRejectsHyphens(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		OverrideMapper::normaliseKey('A-B');
	}

	public function testNormaliseKeyRejectsSpaces(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		OverrideMapper::normaliseKey('A B');
	}

	public function testNormaliseKeyRejectsQuotes(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		OverrideMapper::normaliseKey('"X"');
	}

	public function testNormaliseKeyRejectsEmptyString(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		OverrideMapper::normaliseKey('   ');
	}

	public static function reservedKeyProvider(): array
	{
		return [
			['YES'], ['NO'], ['NULL'], ['FALSE'], ['ON'], ['OFF'], ['NONE'], ['TRUE'],
			// Reserved-word check happens after upper-casing.
			['yes'], ['true'],
		];
	}

	#[DataProvider('reservedKeyProvider')]
	public function testNormaliseKeyRejectsReservedWords(string $key): void
	{
		$this->expectException(\InvalidArgumentException::class);

		OverrideMapper::normaliseKey($key);
	}

	public function testNormaliseKeyAcceptsUnderscoresAndDigits(): void
	{
		$this->assertSame('MCP4J_TEST_1', OverrideMapper::normaliseKey('mcp4j_test_1'));
	}

	// -----------------------------------------------------------------------------------------------------
	// normaliseSearchType()
	// -----------------------------------------------------------------------------------------------------

	public function testNormaliseSearchTypeDefaultsToValue(): void
	{
		$this->assertSame('value', OverrideMapper::normaliseSearchType(null));
	}

	public function testNormaliseSearchTypeAcceptsConstant(): void
	{
		$this->assertSame('constant', OverrideMapper::normaliseSearchType('constant'));
	}

	public function testNormaliseSearchTypeAcceptsValue(): void
	{
		$this->assertSame('value', OverrideMapper::normaliseSearchType('value'));
	}

	public function testNormaliseSearchTypeRejectsUnknownType(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		OverrideMapper::normaliseSearchType('bogus');
	}

	// -----------------------------------------------------------------------------------------------------
	// toRecord()
	// -----------------------------------------------------------------------------------------------------

	public function testToRecordShape(): void
	{
		$this->assertSame(
			['key' => 'MCP4J_TEST', 'value' => 'Hello', 'language' => 'en-GB', 'client' => 'site'],
			OverrideMapper::toRecord('MCP4J_TEST', 'Hello', 'en-GB', 'site')
		);
	}

	// -----------------------------------------------------------------------------------------------------
	// toListItems()
	// -----------------------------------------------------------------------------------------------------

	public function testToListItemsPreservesOrder(): void
	{
		$result = OverrideMapper::toListItems(['B_KEY' => 'b', 'A_KEY' => 'a']);

		$this->assertSame(
			[
				['key' => 'B_KEY', 'value' => 'b'],
				['key' => 'A_KEY', 'value' => 'a'],
			],
			$result
		);
	}

	public function testToListItemsEmpty(): void
	{
		$this->assertSame([], OverrideMapper::toListItems([]));
	}

	// -----------------------------------------------------------------------------------------------------
	// relativePath()
	// -----------------------------------------------------------------------------------------------------

	public function testRelativePathStripsRootWithoutTrailingSlash(): void
	{
		$this->assertSame(
			'language/overrides/en-GB.override.ini',
			OverrideMapper::relativePath('/var/www/html/language/overrides/en-GB.override.ini', '/var/www/html')
		);
	}

	public function testRelativePathStripsRootWithTrailingSlash(): void
	{
		$this->assertSame(
			'language/overrides/en-GB.override.ini',
			OverrideMapper::relativePath('/var/www/html/language/overrides/en-GB.override.ini', '/var/www/html/')
		);
	}

	public function testRelativePathLeavesForeignPathsUnchanged(): void
	{
		$this->assertSame(
			'/etc/passwd',
			OverrideMapper::relativePath('/etc/passwd', '/var/www/html')
		);
	}

	public function testRelativePathWithEmptyRootLeavesPathUnchanged(): void
	{
		$this->assertSame(
			'/var/www/html/x.ini',
			OverrideMapper::relativePath('/var/www/html/x.ini', '')
		);
	}

	// -----------------------------------------------------------------------------------------------------
	// clientInt()
	// -----------------------------------------------------------------------------------------------------

	public function testClientIntAdministrator(): void
	{
		$this->assertSame(1, OverrideMapper::clientInt('administrator'));
	}

	public function testClientIntSite(): void
	{
		$this->assertSame(0, OverrideMapper::clientInt('site'));
	}

	public function testClientIntUnknownDefaultsToSite(): void
	{
		$this->assertSame(0, OverrideMapper::clientInt('bogus'));
	}

	// -----------------------------------------------------------------------------------------------------
	// Constants
	// -----------------------------------------------------------------------------------------------------

	public function testConstants(): void
	{
		$this->assertSame(['site', 'administrator'], OverrideMapper::CLIENTS);
		$this->assertSame(['YES', 'NO', 'NULL', 'FALSE', 'ON', 'OFF', 'NONE', 'TRUE'], OverrideMapper::RESERVED_KEYS);
		$this->assertSame(['constant', 'value'], OverrideMapper::SEARCH_TYPES);
	}
}
