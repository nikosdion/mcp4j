<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Mapper\Panopticon;

use Akeeba\Component\Mcp4j\Administrator\Mapper\Panopticon\CoreUpdateMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CoreUpdateMapperTest extends TestCase
{
	// -----------------------------------------------------------------------------------------------------
	// sanitiseStatus()
	// -----------------------------------------------------------------------------------------------------

	public function testSanitiseStatusLeavesNullAdmintoolsAsNull(): void
	{
		$result = CoreUpdateMapper::sanitiseStatus(['admintools' => null]);

		$this->assertNull($result['admintools']);
	}

	public function testSanitiseStatusLeavesMissingAdmintoolsKeyAbsent(): void
	{
		$result = CoreUpdateMapper::sanitiseStatus(['current' => '5.4.0']);

		$this->assertArrayNotHasKey('admintools', $result);
	}

	public function testSanitiseStatusNeverLeaksSecretWordOrAdminDir(): void
	{
		$result = CoreUpdateMapper::sanitiseStatus([
			'admintools' => [
				'enabled'      => true,
				'renamed'      => true,
				'secret_word'  => 'super-secret-value',
				'admindir'     => 'my-custom-admin',
				'awayschedule' => ['from' => '00:00', 'to' => '06:00'],
			],
		]);

		$json = json_encode($result);

		$this->assertStringNotContainsString('super-secret-value', $json);
		$this->assertStringNotContainsString('my-custom-admin', $json);
		$this->assertTrue($result['admintools']['enabled']);
		$this->assertTrue($result['admintools']['renamed']);
		$this->assertTrue($result['admintools']['hasSecretWord']);
		$this->assertTrue($result['admintools']['hasCustomAdminDir']);
		$this->assertSame(['from' => '00:00', 'to' => '06:00'], $result['admintools']['awayschedule']);
	}

	public function testSanitiseStatusDefaultsToFalseBooleans(): void
	{
		$result = CoreUpdateMapper::sanitiseStatus([
			'admintools' => ['enabled' => false, 'renamed' => false],
		]);

		$this->assertFalse($result['admintools']['hasSecretWord']);
		$this->assertFalse($result['admintools']['hasCustomAdminDir']);
		$this->assertNull($result['admintools']['awayschedule']);
	}

	public function testSanitiseStatusDefaultAdminDirIsNotFlaggedCustom(): void
	{
		$result = CoreUpdateMapper::sanitiseStatus([
			'admintools' => ['admindir' => 'administrator'],
		]);

		$this->assertFalse($result['admintools']['hasCustomAdminDir']);
	}

	// -----------------------------------------------------------------------------------------------------
	// parseUpdateSource()
	// -----------------------------------------------------------------------------------------------------

	public static function updateSourceProvider(): array
	{
		return [
			'4.4 default'          => ['4.4.14', 'default', ['default', null]],
			'4.4 next'             => ['4.4.14', 'next', ['next', null]],
			'4.4 testing allowed'  => ['4.4.14', 'testing', ['testing', null]],
			'4.4 uppercase'        => ['4.4.14', 'DEFAULT', ['default', null]],
			'5.4 default'          => ['5.4.8', 'default', ['default', null]],
			'5.4 next'             => ['5.4.8', 'next', ['next', null]],
			'6.1 default'          => ['6.1.3', 'default', ['default', null]],
		];
	}

	#[DataProvider('updateSourceProvider')]
	public function testParseUpdateSourceAllowedKeywords(string $joomlaVersion, string $source, array $expected): void
	{
		$this->assertSame($expected, CoreUpdateMapper::parseUpdateSource($source, $joomlaVersion));
	}

	public function testParseUpdateSourceTestingRejectedOnFivePointFour(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/Allowed:/');

		CoreUpdateMapper::parseUpdateSource('testing', '5.4.8');
	}

	public function testParseUpdateSourceTestingRejectedOnSix(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		CoreUpdateMapper::parseUpdateSource('testing', '6.1.3');
	}

	public function testParseUpdateSourceHttpUrlBecomesCustom(): void
	{
		$this->assertSame(
			['custom', 'https://example.com/update.xml'],
			CoreUpdateMapper::parseUpdateSource('https://example.com/update.xml', '6.1.3')
		);
	}

	public function testParseUpdateSourceHttpsUrlWithWhitespaceIsTrimmed(): void
	{
		$this->assertSame(
			['custom', 'http://example.com/update.xml'],
			CoreUpdateMapper::parseUpdateSource('  http://example.com/update.xml  ', '6.1.3')
		);
	}

	public function testParseUpdateSourceCustomWithoutUrlThrows(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('A custom update source needs a URL');

		CoreUpdateMapper::parseUpdateSource('custom', '6.1.3');
	}

	public function testParseUpdateSourceGarbageThrowsWithAllowedListInMessage(): void
	{
		try
		{
			CoreUpdateMapper::parseUpdateSource('nonsense', '6.1.3');
			$this->fail('Expected an InvalidArgumentException');
		}
		catch (\InvalidArgumentException $e)
		{
			$this->assertStringContainsString('Allowed:', $e->getMessage());
			$this->assertStringContainsString('default', $e->getMessage());
			$this->assertStringContainsString('next', $e->getMessage());
			$this->assertStringContainsString('custom', $e->getMessage());
			$this->assertStringNotContainsString('testing', $e->getMessage());
		}
	}

	public function testParseUpdateSourceGarbageOnFourFourMentionsTesting(): void
	{
		try
		{
			CoreUpdateMapper::parseUpdateSource('nonsense', '4.4.14');
			$this->fail('Expected an InvalidArgumentException');
		}
		catch (\InvalidArgumentException $e)
		{
			$this->assertStringContainsString('testing', $e->getMessage());
		}
	}

	// -----------------------------------------------------------------------------------------------------
	// parseExtensionIds()
	// -----------------------------------------------------------------------------------------------------

	public function testParseExtensionIdsNullReturnsEmpty(): void
	{
		$this->assertSame([], CoreUpdateMapper::parseExtensionIds(null));
	}

	public function testParseExtensionIdsEmptyStringReturnsEmpty(): void
	{
		$this->assertSame([], CoreUpdateMapper::parseExtensionIds(''));
	}

	public function testParseExtensionIdsSingle(): void
	{
		$this->assertSame([12], CoreUpdateMapper::parseExtensionIds('12'));
	}

	public function testParseExtensionIdsCommaSeparated(): void
	{
		$this->assertSame([12, 34], CoreUpdateMapper::parseExtensionIds('12,34'));
	}

	public function testParseExtensionIdsWithWhitespace(): void
	{
		$this->assertSame([12, 34], CoreUpdateMapper::parseExtensionIds(' 12 , 34 '));
	}

	public function testParseExtensionIdsBracketedList(): void
	{
		$this->assertSame([12, 34], CoreUpdateMapper::parseExtensionIds('[12,34]'));
	}

	public function testParseExtensionIdsDedupesAndPreservesOrder(): void
	{
		$this->assertSame([12, 34], CoreUpdateMapper::parseExtensionIds('12,34,12'));
	}

	public function testParseExtensionIdsDropsNonPositiveAndNonNumeric(): void
	{
		$this->assertSame([12], CoreUpdateMapper::parseExtensionIds('12,0,-5,abc'));
	}

	// -----------------------------------------------------------------------------------------------------
	// isHttpUrl()
	// -----------------------------------------------------------------------------------------------------

	public function testIsHttpUrlAcceptsHttp(): void
	{
		$this->assertTrue(CoreUpdateMapper::isHttpUrl('http://example.com/x.xml'));
	}

	public function testIsHttpUrlAcceptsHttps(): void
	{
		$this->assertTrue(CoreUpdateMapper::isHttpUrl('https://example.com/x.xml'));
	}

	public function testIsHttpUrlRejectsFtp(): void
	{
		$this->assertFalse(CoreUpdateMapper::isHttpUrl('ftp://example.com/x.xml'));
	}

	public function testIsHttpUrlRejectsFile(): void
	{
		$this->assertFalse(CoreUpdateMapper::isHttpUrl('file:///etc/passwd'));
	}

	public function testIsHttpUrlRejectsJavascript(): void
	{
		$this->assertFalse(CoreUpdateMapper::isHttpUrl('javascript:alert(1)'));
	}

	public function testIsHttpUrlRejectsPlainString(): void
	{
		$this->assertFalse(CoreUpdateMapper::isHttpUrl('not a url'));
	}

	// -----------------------------------------------------------------------------------------------------
	// checksumOf()
	// -----------------------------------------------------------------------------------------------------

	public function testChecksumOfWhitespaceNormalisationProducesEqualHashes(): void
	{
		$a = "foo\r\nbar\tbaz";
		$b = 'foo   bar    baz';

		$this->assertSame(CoreUpdateMapper::checksumOf($a), CoreUpdateMapper::checksumOf($b));
	}

	public function testChecksumOfDifferentContentProducesDifferentHashes(): void
	{
		$this->assertNotSame(CoreUpdateMapper::checksumOf('foo'), CoreUpdateMapper::checksumOf('bar'));
	}
}
