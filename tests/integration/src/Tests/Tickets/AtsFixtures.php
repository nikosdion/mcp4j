<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Integration\Tests\Tickets;

use PHPUnit\Framework\Assert;

/**
 * Runs `tests/integration/fixtures/ats.php` as a separate PHP CLI process (PHPUnit for this suite runs
 * *inside* the `web` container as an HTTP client, so it cannot touch the site's database directly — see
 * that script's own docblock) and decodes its one-line JSON result.
 *
 * Not a `*Test.php` class — a plain helper, called from `setUp()` of every test class in this namespace.
 */
final class AtsFixtures
{
	/**
	 * @var  ?array<string, mixed>
	 */
	private static ?array $ensureResult = null;

	/**
	 * Idempotent ATS setup (category, `sendEmails` disabled, ...). Cached for the lifetime of the current
	 * PHPUnit process — every test class's `setUp()` calls this, and there is no need to re-run it once
	 * per test method.
	 *
	 * @return  array{installed: bool, pro?: bool, adminId?: int, categoryId?: int, foreignCategoryId?: int}
	 */
	public static function ensure(): array
	{
		if (self::$ensureResult === null)
		{
			self::$ensureResult = self::run('ensure');
		}

		return self::$ensureResult;
	}

	/**
	 * The custom-field fixtures (a second category, a required text field, a ticket carrying a value for
	 * it). NOT cached — every call creates a fresh ticket.
	 *
	 * @return  array{categoryId: int, fieldName: string, ticketId: int}
	 */
	public static function fields(): array
	{
		return self::run('fields');
	}

	/**
	 * @return  array<string, mixed>
	 */
	private static function run(string $command): array
	{
		$script = dirname(__DIR__, 3) . '/fixtures/ats.php';

		$cmd = 'php ' . escapeshellarg($script) . ' ' . escapeshellarg($command) . ' 2>&1';

		exec($cmd, $outputLines, $exitCode);

		$output = implode("\n", $outputLines);

		Assert::assertSame(0, $exitCode, "ats.php $command failed (exit $exitCode): $output");

		$decoded = json_decode($output, true);

		Assert::assertIsArray($decoded, "ats.php $command did not print valid JSON: $output");

		return $decoded;
	}
}
