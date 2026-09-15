<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Mapper\Panopticon;

use Akeeba\Component\Mcp4j\Administrator\Mapper\Panopticon\AdminToolsMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AdminToolsMapperTest extends TestCase
{
	// -----------------------------------------------------------------------------------------------------
	// Field constants
	// -----------------------------------------------------------------------------------------------------

	public function testScanFields(): void
	{
		$this->assertSame(
			['id', 'comment', 'scanstart', 'scanend', 'status', 'origin', 'totalfiles', 'files_new', 'files_modified', 'files_suspicious'],
			AdminToolsMapper::SCAN_FIELDS
		);
	}

	public function testScanIntFields(): void
	{
		$this->assertSame(
			['id', 'totalfiles', 'files_new', 'files_modified', 'files_suspicious'],
			AdminToolsMapper::SCAN_INT_FIELDS
		);
	}

	public function testAlertListFields(): void
	{
		$this->assertSame(
			['id', 'newfile', 'suspicious', 'filestatus', 'threatindex', 'path', 'threat_score', 'acknowledged', 'scan_id', 'scandate'],
			AdminToolsMapper::ALERT_LIST_FIELDS
		);
	}

	public function testAlertListFieldsHasNoDiff(): void
	{
		$this->assertNotContains('diff', AdminToolsMapper::ALERT_LIST_FIELDS);
	}

	public function testAlertItemFields(): void
	{
		$this->assertSame(
			['id', 'path', 'scan_id', 'diff', 'threat_score', 'acknowledged'],
			AdminToolsMapper::ALERT_ITEM_FIELDS
		);
	}

	public function testAlertIntFields(): void
	{
		$this->assertSame(
			['id', 'newfile', 'suspicious', 'threat_score', 'acknowledged', 'scan_id'],
			AdminToolsMapper::ALERT_INT_FIELDS
		);
	}

	// -----------------------------------------------------------------------------------------------------
	// scansListState() / scanByIdState() / alertsState()
	// -----------------------------------------------------------------------------------------------------

	public function testScansListState(): void
	{
		$this->assertSame(
			[
				'filter.search'  => '',
				'filter.since'   => '',
				'filter.until'   => '',
				'filter.status'  => '',
				'list.ordering'  => 'id',
				'list.direction' => 'desc',
			],
			AdminToolsMapper::scansListState()
		);
	}

	public function testScanByIdState(): void
	{
		$state = AdminToolsMapper::scanByIdState(7);

		$this->assertSame('id:7', $state['filter.search']);
		$this->assertSame('id', $state['list.ordering']);
		$this->assertSame('desc', $state['list.direction']);
	}

	public function testAlertsState(): void
	{
		$this->assertSame(
			[
				'filter.search'       => '',
				'filter.status'       => '',
				'filter.scan_id'      => 42,
				'filter.acknowledged' => '',
				'filter.flag'         => '',
				'list.ordering'       => 'path',
				'list.direction'      => 'asc',
			],
			AdminToolsMapper::alertsState(42)
		);
	}

	// -----------------------------------------------------------------------------------------------------
	// scannerResult()
	// -----------------------------------------------------------------------------------------------------

	public function testScannerResultDropsUnknownKeysIncludingSession(): void
	{
		$result = AdminToolsMapper::scannerResult(
			[
				'status'   => true,
				'done'     => false,
				'error'    => '',
				'warnings' => ['w1'],
				'session'  => ['com_admintools.filescanner.fileQueue' => ['/a', '/b']],
			],
			5
		);

		$this->assertSame(['id', 'status', 'done', 'error', 'warnings'], array_keys($result));
		$this->assertArrayNotHasKey('session', $result);
	}

	public function testScannerResultFillsId(): void
	{
		$result = AdminToolsMapper::scannerResult(['status' => true, 'done' => true, 'error' => '', 'warnings' => []], 99);

		$this->assertSame(99, $result['id']);
	}

	public function testScannerResultNullScanIdBecomesZero(): void
	{
		$result = AdminToolsMapper::scannerResult(['status' => true, 'done' => true, 'error' => '', 'warnings' => []], null);

		$this->assertSame(0, $result['id']);
	}

	public function testScannerResultCoercesTypes(): void
	{
		$result = AdminToolsMapper::scannerResult([], 1);

		$this->assertIsBool($result['status']);
		$this->assertIsBool($result['done']);
		$this->assertIsString($result['error']);
		$this->assertIsArray($result['warnings']);
		$this->assertFalse($result['status']);
		$this->assertFalse($result['done']);
		$this->assertSame('', $result['error']);
		$this->assertSame([], $result['warnings']);
	}

	// -----------------------------------------------------------------------------------------------------
	// shouldRenameHtaccess()
	// -----------------------------------------------------------------------------------------------------

	public function testShouldRenameHtaccessTrueWhenSourceExists(): void
	{
		$this->assertTrue(AdminToolsMapper::shouldRenameHtaccess(true));
	}

	public function testShouldRenameHtaccessFalseWhenSourceMissing(): void
	{
		$this->assertFalse(AdminToolsMapper::shouldRenameHtaccess(false));
	}

	// -----------------------------------------------------------------------------------------------------
	// htaccessDisableResult()
	// -----------------------------------------------------------------------------------------------------

	public static function htaccessDisableProvider(): array
	{
		return [
			'no htaccess, backup exists (already disabled)' => [false, true, null, ['exists' => false, 'renamed' => true]],
			'no htaccess, no backup (nothing to do)'         => [false, false, null, ['exists' => false, 'renamed' => false]],
			'htaccess exists, rename succeeds'               => [true, false, true, ['exists' => true, 'renamed' => true]],
			'htaccess exists, rename fails'                  => [true, false, false, ['exists' => true, 'renamed' => false]],
		];
	}

	#[DataProvider('htaccessDisableProvider')]
	public function testHtaccessDisableResult(bool $htaccessExists, bool $backupExists, ?bool $renameSucceeded, array $expected): void
	{
		$this->assertSame($expected, AdminToolsMapper::htaccessDisableResult($htaccessExists, $backupExists, $renameSucceeded));
	}

	// -----------------------------------------------------------------------------------------------------
	// htaccessEnableResult()
	// -----------------------------------------------------------------------------------------------------

	public static function htaccessEnableProvider(): array
	{
		return [
			'no backup, htaccess exists (already enabled)' => [false, true, null, ['exists' => false, 'restored' => true]],
			'no backup, no htaccess (nothing to do)'        => [false, false, null, ['exists' => false, 'restored' => false]],
			'backup exists, rename succeeds'                => [true, false, true, ['exists' => true, 'restored' => true]],
			'backup exists, rename fails'                   => [true, false, false, ['exists' => true, 'restored' => false]],
		];
	}

	#[DataProvider('htaccessEnableProvider')]
	public function testHtaccessEnableResult(bool $backupExists, bool $htaccessExists, ?bool $renameSucceeded, array $expected): void
	{
		$this->assertSame($expected, AdminToolsMapper::htaccessEnableResult($backupExists, $htaccessExists, $renameSucceeded));
	}

	// -----------------------------------------------------------------------------------------------------
	// tempSuperUserExpiration()
	// -----------------------------------------------------------------------------------------------------

	private function nowUtc(): \DateTimeImmutable
	{
		return new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC'));
	}

	public function testTempSuperUserExpirationNullDefaultsToSevenDays(): void
	{
		$result = AdminToolsMapper::tempSuperUserExpiration(null, $this->nowUtc(), 'UTC');

		$this->assertSame('2026-01-08 00:00:00', $result);
	}

	public function testTempSuperUserExpirationClampsBelowMinimumToOneHour(): void
	{
		$result = AdminToolsMapper::tempSuperUserExpiration(60, $this->nowUtc(), 'UTC');

		$this->assertSame('2026-01-01 01:00:00', $result);
	}

	public function testTempSuperUserExpirationClampsAboveMaximumToSevenDays(): void
	{
		$result = AdminToolsMapper::tempSuperUserExpiration(1_000_000_000, $this->nowUtc(), 'UTC');

		$this->assertSame('2026-01-08 00:00:00', $result);
	}

	public function testTempSuperUserExpirationTwoHours(): void
	{
		$result = AdminToolsMapper::tempSuperUserExpiration(7200, $this->nowUtc(), 'UTC');

		$this->assertSame('2026-01-01 02:00:00', $result);
	}

	public function testTempSuperUserExpirationTimezoneConversion(): void
	{
		$utc     = AdminToolsMapper::tempSuperUserExpiration(7200, $this->nowUtc(), 'UTC');
		$athens  = AdminToolsMapper::tempSuperUserExpiration(7200, $this->nowUtc(), 'Europe/Athens');

		$this->assertNotSame($utc, $athens);
		$this->assertSame('2026-01-01 02:00:00', $utc);
		$this->assertSame('2026-01-01 04:00:00', $athens);
	}

	public function testTempSuperUserExpirationInvalidTimezoneFallsBackToUtc(): void
	{
		$result = AdminToolsMapper::tempSuperUserExpiration(7200, $this->nowUtc(), 'Not/ARealTimezone');

		$this->assertSame('2026-01-01 02:00:00', $result);
	}

	// -----------------------------------------------------------------------------------------------------
	// randomString()
	// -----------------------------------------------------------------------------------------------------

	public function testRandomStringLength(): void
	{
		$result = AdminToolsMapper::randomString(32, 'ab', static fn (int $min, int $max): int => 0);

		$this->assertSame(32, strlen($result));
	}

	public function testRandomStringOnlyAlphabetCharacters(): void
	{
		$alphabet = 'xyz';
		$result   = AdminToolsMapper::randomString(50, $alphabet, static fn (int $min, int $max): int => random_int($min, $max));

		for ($i = 0; $i < strlen($result); $i++)
		{
			$this->assertStringContainsString($result[$i], $alphabet);
		}
	}

	public function testRandomStringDeterministicWithStubRng(): void
	{
		// Always picks index 1 ('b').
		$result = AdminToolsMapper::randomString(5, 'abc', static fn (int $min, int $max): int => 1);

		$this->assertSame('bbbbb', $result);
	}

	public function testRandomStringPassesBoundsToRng(): void
	{
		$calls = [];

		AdminToolsMapper::randomString(3, 'abcd', function (int $min, int $max) use (&$calls): int {
			$calls[] = [$min, $max];

			return $max;
		});

		foreach ($calls as $call)
		{
			$this->assertSame([0, 3], $call);
		}

		$this->assertCount(3, $calls);
	}
}
