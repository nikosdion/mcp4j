<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Mapper\Config;

use Akeeba\Component\Mcp4j\Administrator\Mapper\Config\SecretRedactor;
use PHPUnit\Framework\TestCase;

class SecretRedactorTest extends TestCase
{
	// -----------------------------------------------------------------------------------------------------
	// redactKeys()
	// -----------------------------------------------------------------------------------------------------

	public function testRedactKeysReplacesNonEmptyValuesWithPlaceholder(): void
	{
		$result = SecretRedactor::redactKeys(['password' => 'hunter2', 'sitename' => 'My Site'], ['password']);

		$this->assertSame(SecretRedactor::PLACEHOLDER, $result['password']);
		$this->assertSame('My Site', $result['sitename'], 'Keys not in the redaction list must be untouched.');
	}

	public function testRedactKeysLeavesEmptyStringValuesEmpty(): void
	{
		$result = SecretRedactor::redactKeys(['smtppass' => ''], ['smtppass']);

		$this->assertSame('', $result['smtppass'], 'An empty secret must stay empty, not become the placeholder.');
	}

	public function testRedactKeysLeavesNullValuesNull(): void
	{
		$result = SecretRedactor::redactKeys(['ftp_pass' => null], ['ftp_pass']);

		$this->assertNull($result['ftp_pass']);
	}

	public function testRedactKeysIgnoresKeysAbsentFromData(): void
	{
		$result = SecretRedactor::redactKeys(['sitename' => 'My Site'], ['password']);

		$this->assertArrayNotHasKey('password', $result);
	}

	public function testEveryApplicationSecretKeyIsRedacted(): void
	{
		$data = array_fill_keys(SecretRedactor::APPLICATION_SECRET_KEYS, 'a-live-secret-value');

		$result = SecretRedactor::redactKeys($data, SecretRedactor::APPLICATION_SECRET_KEYS);

		foreach (SecretRedactor::APPLICATION_SECRET_KEYS as $key)
		{
			$this->assertSame(SecretRedactor::PLACEHOLDER, $result[$key], "Expected \"$key\" to be redacted.");
		}
	}

	public function testApplicationSecretKeysListMatchesTheDocumentedSet(): void
	{
		$this->assertSame(
			['password', 'secret', 'smtppass', 'ftp_pass', 'proxy_pass', 'redis_server_auth', 'session_redis_server_auth'],
			SecretRedactor::APPLICATION_SECRET_KEYS
		);
	}

	// -----------------------------------------------------------------------------------------------------
	// redactByPattern()
	// -----------------------------------------------------------------------------------------------------

	public function testRedactByPatternHitsCommonSecretKeyNames(): void
	{
		$result = SecretRedactor::redactByPattern([
			'update_token'      => 'abc123',
			'forbidden_secrets' => 'MCP4J-FORBIDDEN-deadbeef',
			'private_key'       => '-----BEGIN KEY-----',
			'downloadid'        => 'DL-1234',
		]);

		$this->assertSame(SecretRedactor::PLACEHOLDER, $result['update_token']);
		$this->assertSame(SecretRedactor::PLACEHOLDER, $result['forbidden_secrets']);
		$this->assertSame(SecretRedactor::PLACEHOLDER, $result['private_key']);
		$this->assertSame(SecretRedactor::PLACEHOLDER, $result['downloadid']);
	}

	public function testRedactByPatternRecursesIntoNestedArrays(): void
	{
		$result = SecretRedactor::redactByPattern(['params' => ['api_key' => 'sk-live-xxx', 'title' => 'Widget']]);

		$this->assertSame(SecretRedactor::PLACEHOLDER, $result['params']['api_key']);
		$this->assertSame('Widget', $result['params']['title']);
	}

	public function testRedactByPatternDoesNotMatchAuthOrUserOrAuthorKeys(): void
	{
		$result = SecretRedactor::redactByPattern(['smtpauth' => 1, 'smtpuser' => 'bob', 'author' => 'Jane Doe']);

		$this->assertSame(1, $result['smtpauth']);
		$this->assertSame('bob', $result['smtpuser']);
		$this->assertSame('Jane Doe', $result['author']);
	}

	public function testRedactByPatternLeavesEmptyMatchingValuesEmpty(): void
	{
		$result = SecretRedactor::redactByPattern(['api_key' => '']);

		$this->assertSame('', $result['api_key']);
	}

	public function testRedactByPatternIgnoresNonScalarLeaves(): void
	{
		$result = SecretRedactor::redactByPattern(['token' => ['nested' => 'value']]);

		// A "token" key holding an array is itself recursed into, not stamped with the placeholder wholesale
		// (redaction only ever replaces a scalar leaf, never collapses a structure).
		$this->assertSame(['nested' => 'value'], $result['token']);
	}

	// -----------------------------------------------------------------------------------------------------
	// stripPlaceholders()
	// -----------------------------------------------------------------------------------------------------

	public function testStripPlaceholdersRemovesTopLevelPlaceholder(): void
	{
		$result = SecretRedactor::stripPlaceholders(['password' => SecretRedactor::PLACEHOLDER, 'sitename' => 'My Site']);

		$this->assertArrayNotHasKey('password', $result);
		$this->assertSame('My Site', $result['sitename']);
	}

	public function testStripPlaceholdersRemovesNestedPlaceholder(): void
	{
		$result = SecretRedactor::stripPlaceholders(['params' => ['api_key' => SecretRedactor::PLACEHOLDER, 'title' => 'Widget']]);

		$this->assertArrayNotHasKey('api_key', $result['params']);
		$this->assertSame('Widget', $result['params']['title']);
	}

	public function testStripPlaceholdersKeepsEveryOtherValue(): void
	{
		$result = SecretRedactor::stripPlaceholders(['a' => 1, 'b' => 'text', 'c' => null, 'd' => false]);

		$this->assertSame(['a' => 1, 'b' => 'text', 'c' => null, 'd' => false], $result);
	}
}
