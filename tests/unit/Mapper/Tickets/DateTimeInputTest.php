<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Mapper\Tickets;

use Akeeba\Component\Mcp4j\Administrator\Mapper\Tickets\DateTimeInput;
use PHPUnit\Framework\TestCase;

/**
 * Every test here runs under a non-UTC default timezone (like SessionClockTest) — DateTimeInput::toSql()
 * must never let PHP's *default* timezone leak into a naive-string interpretation; a naive string must
 * always be read as UTC.
 */
class DateTimeInputTest extends TestCase
{
	private string $originalTimezone;

	protected function setUp(): void
	{
		parent::setUp();

		$this->originalTimezone = date_default_timezone_get();

		date_default_timezone_set('Asia/Nicosia');
	}

	protected function tearDown(): void
	{
		date_default_timezone_set($this->originalTimezone);

		parent::tearDown();
	}

	public function testNullReturnsNull(): void
	{
		$this->assertNull(DateTimeInput::toSql(null, 'created'));
	}

	public function testEmptyStringReturnsNull(): void
	{
		$this->assertNull(DateTimeInput::toSql('', 'created'));
	}

	public function testZuluSuffixIsHonouredAndConvertedToUtc(): void
	{
		$this->assertSame('2020-01-02 03:04:05', DateTimeInput::toSql('2020-01-02T03:04:05Z', 'created'));
	}

	public function testExplicitOffsetIsHonouredAndConvertedToUtc(): void
	{
		// +03:00 -> UTC is 3 hours earlier.
		$this->assertSame('2020-01-02 00:04:05', DateTimeInput::toSql('2020-01-02T03:04:05+03:00', 'created'));
	}

	public function testNaiveStringIsTakenAsUtcRegardlessOfDefaultTimezone(): void
	{
		// No offset in the string at all: must be read as UTC, NOT as the process default timezone
		// (Asia/Nicosia, which is UTC+2 or +3 depending on DST) — if it leaked through, this would not
		// equal the input value.
		$this->assertSame('2020-06-15 12:00:00', DateTimeInput::toSql('2020-06-15 12:00:00', 'created'));
	}

	public function testDateOnlyStringIsMidnightUtc(): void
	{
		$this->assertSame('2020-06-15 00:00:00', DateTimeInput::toSql('2020-06-15', 'created'));
	}

	public function testInvalidStringThrowsWithArgumentName(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid date-time for modified: not-a-date');

		DateTimeInput::toSql('not-a-date', 'modified');
	}
}
