<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Mcp\Support;

use Akeeba\Component\Mcp4j\Administrator\Mcp\Support\SecretLeakGuard;
use PHPUnit\Framework\TestCase;

class SecretLeakGuardTest extends TestCase
{
	// -----------------------------------------------------------------------------------------------------------
	// containsSecret()
	// -----------------------------------------------------------------------------------------------------------

	public function testFindsSecretInTopLevelString(): void
	{
		$this->assertTrue(SecretLeakGuard::containsSecret('a secret-value here', ['secret-value']));
	}

	public function testFindsSecretNestedInArrays(): void
	{
		$value = ['a' => ['b' => ['c' => 'contains secret-value inside']]];

		$this->assertTrue(SecretLeakGuard::containsSecret($value, ['secret-value']));
	}

	public function testFindsSecretInAListArray(): void
	{
		$this->assertTrue(SecretLeakGuard::containsSecret(['harmless', 'has secret-value'], ['secret-value']));
	}

	public function testNoMatchReturnsFalse(): void
	{
		$this->assertFalse(SecretLeakGuard::containsSecret(['a' => 'harmless value'], ['secret-value']));
	}

	public function testNonStringNonArrayValuesAreIgnored(): void
	{
		$this->assertFalse(SecretLeakGuard::containsSecret(['a' => 42, 'b' => true, 'c' => null, 'd' => 3.14], ['secret']));
	}

	public function testEmptySecretsListNeverMatches(): void
	{
		$this->assertFalse(SecretLeakGuard::containsSecret('anything at all', []));
	}

	public function testEmptySecretStringIsNeverConsideredAMatch(): void
	{
		$this->assertFalse(SecretLeakGuard::containsSecret('anything at all', ['']));
	}

	// -----------------------------------------------------------------------------------------------------------
	// redact()
	// -----------------------------------------------------------------------------------------------------------

	public function testRedactsTopLevelString(): void
	{
		$this->assertSame('a *** here', SecretLeakGuard::redact('a secret here', ['secret']));
	}

	public function testRedactsNestedArraysPreservingKeys(): void
	{
		$value = ['token' => 'Bearer secret', 'other' => 'unaffected'];

		$this->assertSame(
			['token' => 'Bearer ***', 'other' => 'unaffected'],
			SecretLeakGuard::redact($value, ['secret'])
		);
	}

	public function testRedactsEveryOccurrence(): void
	{
		$this->assertSame('*** and *** again', SecretLeakGuard::redact('secret and secret again', ['secret']));
	}

	public function testNonStringNonArrayValuesPassThroughUnchanged(): void
	{
		$this->assertSame(42, SecretLeakGuard::redact(42, ['secret']));
		$this->assertNull(SecretLeakGuard::redact(null, ['secret']));
	}

	// -----------------------------------------------------------------------------------------------------------
	// normaliseSecrets()
	// -----------------------------------------------------------------------------------------------------------

	public function testTrimsWhitespace(): void
	{
		$this->assertSame(['abcdef'], SecretLeakGuard::normaliseSecrets(['  abcdef  '], 1));
	}

	public function testDropsNonStrings(): void
	{
		$this->assertSame(['abcdef'], SecretLeakGuard::normaliseSecrets([123, null, true, ['x'], 'abcdef'], 1));
	}

	public function testDropsEmptyStrings(): void
	{
		$this->assertSame([], SecretLeakGuard::normaliseSecrets(['', '   '], 1));
	}

	public function testDropsStringsShorterThanMinLength(): void
	{
		$this->assertSame(['abcdefgh'], SecretLeakGuard::normaliseSecrets(['short', 'abcdefgh'], 8));
	}

	public function testDeduplicates(): void
	{
		$this->assertSame(['abcdef'], SecretLeakGuard::normaliseSecrets(['abcdef', 'abcdef', ' abcdef '], 1));
	}
}
