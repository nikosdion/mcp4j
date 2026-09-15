<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Integration\Tests\Tickets;

use PHPUnit\Framework\Assert;

/**
 * Runs `tests/integration/fixtures/ats-attachments.php` as a separate PHP CLI process (see that script's
 * own docblock, and {@see AtsFixtures} for why) and decodes its one-line JSON result.
 *
 * Unlike {@see AtsFixtures::ensure()}, {@see self::create()} is deliberately NOT cached — every call
 * provisions a brand new ticket/post/attachment set, since `AttachmentsTest` mutates (deletes) one of the
 * attachments it creates.
 *
 * Not a `*Test.php` class — a plain helper, called from the test methods that need a fresh attachment
 * fixture.
 */
final class AtsAttachmentsFixtures
{
	/**
	 * @return  array{pro: bool, ticketId?: int, postId?: int, attachments?: array{text: int, png: int, big: int, zip: int}}
	 */
	public static function create(int $categoryId): array
	{
		$script = dirname(__DIR__, 3) . '/fixtures/ats-attachments.php';

		$cmd = 'php ' . escapeshellarg($script) . ' ' . escapeshellarg((string) $categoryId) . ' 2>&1';

		exec($cmd, $outputLines, $exitCode);

		$output = implode("\n", $outputLines);

		Assert::assertSame(0, $exitCode, "ats-attachments.php failed (exit $exitCode): $output");

		$decoded = json_decode($output, true);

		Assert::assertIsArray($decoded, "ats-attachments.php did not print valid JSON: $output");

		return $decoded;
	}
}
