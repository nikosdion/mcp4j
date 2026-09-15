<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Support;

use Akeeba\Component\Mcp4j\Administrator\Support\SessionClock;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * Regression suite for a real bug found while testing MCP4j live: SessionManager used to compare the
 * stored `expires` column with PHP's strtotime(), which interprets naive datetime strings in the
 * process's *default* timezone. On a server whose default timezone isn't UTC, a session created
 * moments ago read back as already expired. Every test here runs under a non-UTC default timezone to
 * make sure that class of bug can't come back silently.
 */
class SessionClockTest extends TestCase
{
	private string $originalTimezone;

	protected function setUp(): void
	{
		parent::setUp();

		$this->originalTimezone = date_default_timezone_get();

		// A timezone with a non-trivial, non-whole-hour-adjacent-to-UTC offset and DST, chosen
		// specifically because it's what the live bug this suite guards against was reproduced under.
		date_default_timezone_set('Europe/Athens');
	}

	protected function tearDown(): void
	{
		date_default_timezone_set($this->originalTimezone);

		parent::tearDown();
	}

	public function testNowIsInUtcRegardlessOfDefaultTimezone(): void
	{
		$this->assertSame('UTC', SessionClock::now()->getTimezone()->getName());
	}

	public function testToSqlAndFromSqlRoundTrip(): void
	{
		$now = new DateTimeImmutable('2026-09-15 09:51:19', new DateTimeZone('UTC'));

		$sql = SessionClock::toSql($now);

		$this->assertSame('2026-09-15 09:51:19', $sql);
		$this->assertEquals($now, SessionClock::fromSql($sql));
	}

	public function testFromSqlThrowsOnAMalformedString(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		SessionClock::fromSql('not a date');
	}

	public function testExpiryFromAddsTheTtlInSeconds(): void
	{
		$from = new DateTimeImmutable('2026-09-15 09:51:19', new DateTimeZone('UTC'));

		$expires = SessionClock::expiryFrom($from, 3600);

		$this->assertSame('2026-09-15 10:51:19', SessionClock::toSql($expires));
	}

	public function testExpiryFromDoesNotMutateTheInputInstant(): void
	{
		// DateTimeImmutable already guarantees this, but SessionManager relies on being able to reuse
		// $now for both "created"/"last_active" and as the base for "expires" — assert it explicitly.
		$from = new DateTimeImmutable('2026-09-15 09:51:19', new DateTimeZone('UTC'));

		SessionClock::expiryFrom($from, 3600);

		$this->assertSame('2026-09-15 09:51:19', SessionClock::toSql($from));
	}

	public function testExpiryFromRejectsANegativeTtl(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		SessionClock::expiryFrom(SessionClock::now(), -1);
	}

	public function testIsExpiredIsFalseForATimeStillInTheFuture(): void
	{
		$now         = new DateTimeImmutable('2026-09-15 09:51:19', new DateTimeZone('UTC'));
		$oneHourLater = SessionClock::toSql(SessionClock::expiryFrom($now, 3600));

		$this->assertFalse(SessionClock::isExpired($oneHourLater, $now));
	}

	public function testIsExpiredIsTrueForATimeInThePast(): void
	{
		$now      = new DateTimeImmutable('2026-09-15 09:51:19', new DateTimeZone('UTC'));
		$anHourAgo = SessionClock::toSql($now->modify('-1 hour'));

		$this->assertTrue(SessionClock::isExpired($anHourAgo, $now));
	}

	/**
	 * The exact scenario that broke in production: a session's TTL puts `expires` a little over three
	 * hours ahead of the local Athens wall clock (this suite's default timezone) at the moment it was
	 * created. strtotime() on a naive UTC string would have misread that as already expired. It must
	 * not be, because "now" — a real UTC instant — is still well before it.
	 */
	public function testIsExpiredIsFalseImmediatelyAfterCreationUnderANonUtcDefaultTimezone(): void
	{
		$now     = SessionClock::now();
		$expires = SessionClock::toSql(SessionClock::expiryFrom($now, 3600));

		$this->assertFalse(SessionClock::isExpired($expires, $now));
	}
}
