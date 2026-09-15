<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Mapper\JoomlaUpdate;

use Akeeba\Component\Mcp4j\Administrator\Mapper\JoomlaUpdate\JoomlaUpdateMapper;
use PHPUnit\Framework\TestCase;

class JoomlaUpdateMapperTest extends TestCase
{
	// -----------------------------------------------------------------------------------------------------
	// normaliseDbVersion()
	// -----------------------------------------------------------------------------------------------------

	public function testNormaliseDbVersionMariaDbWithMysqlCompatibilityPrefix(): void
	{
		$this->assertSame('10.11.6', JoomlaUpdateMapper::normaliseDbVersion('5.5.5-10.11.6-MariaDB'));
	}

	public function testNormaliseDbVersionMariaDbWithMariaDbMarker(): void
	{
		$this->assertSame('11.4.2', JoomlaUpdateMapper::normaliseDbVersion('11.4.2-MariaDB-ubu2404'));
	}

	public function testNormaliseDbVersionPlainMysqlIsUnchanged(): void
	{
		$this->assertSame('8.0.36', JoomlaUpdateMapper::normaliseDbVersion('8.0.36'));
	}

	public function testNormaliseDbVersionMysqlWithSuffixStillExtractsTriple(): void
	{
		$this->assertSame('8.0.36', JoomlaUpdateMapper::normaliseDbVersion('8.0.36-log'));
	}

	public function testNormaliseDbVersionUnparsableStringIsReturnedUnchanged(): void
	{
		$this->assertSame('unknown', JoomlaUpdateMapper::normaliseDbVersion('unknown'));
	}

	// -----------------------------------------------------------------------------------------------------
	// automatedUpdatesEnabled()
	// -----------------------------------------------------------------------------------------------------

	public function testAutomatedUpdatesEnabledTrueWhenEverythingMatches(): void
	{
		$this->assertTrue(JoomlaUpdateMapper::automatedUpdatesEnabled([
			'updatesource'      => 'default',
			'minimum_stability' => 4,
			'autoupdate'        => 1,
		]));
	}

	public function testAutomatedUpdatesEnabledFalseWhenUpdateSourceIsNotDefault(): void
	{
		$this->assertFalse(JoomlaUpdateMapper::automatedUpdatesEnabled([
			'updatesource'      => 'next',
			'minimum_stability' => 4,
			'autoupdate'        => 1,
		]));
	}

	public function testAutomatedUpdatesEnabledFalseWhenMinimumStabilityIsNotStable(): void
	{
		$this->assertFalse(JoomlaUpdateMapper::automatedUpdatesEnabled([
			'updatesource'      => 'default',
			'minimum_stability' => 2,
			'autoupdate'        => 1,
		]));
	}

	public function testAutomatedUpdatesEnabledFalseWhenAutoupdateIsOff(): void
	{
		$this->assertFalse(JoomlaUpdateMapper::automatedUpdatesEnabled([
			'updatesource'      => 'default',
			'minimum_stability' => 4,
			'autoupdate'        => 0,
		]));
	}

	public function testAutomatedUpdatesEnabledDefaultsWhenParamsAreMissing(): void
	{
		// Defaults mirror config.xml: updatesource=default, minimum_stability=4, autoupdate=0.
		$this->assertFalse(JoomlaUpdateMapper::automatedUpdatesEnabled([]));
	}

	public function testAutomatedUpdatesEnabledCoercesStringStabilityAndAutoupdate(): void
	{
		$this->assertTrue(JoomlaUpdateMapper::automatedUpdatesEnabled([
			'updatesource'      => 'default',
			'minimum_stability' => '4',
			'autoupdate'        => '1',
		]));
	}

	// -----------------------------------------------------------------------------------------------------
	// availableUpdate()
	// -----------------------------------------------------------------------------------------------------

	public function testAvailableUpdateNullWhenLatestIsNull(): void
	{
		$this->assertNull(JoomlaUpdateMapper::availableUpdate(null, '5.4.0', true));
	}

	public function testAvailableUpdateNullWhenLatestIsEmptyString(): void
	{
		$this->assertNull(JoomlaUpdateMapper::availableUpdate('', '5.4.0', true));
	}

	public function testAvailableUpdateNullWhenCurrentEqualsLatest(): void
	{
		$this->assertNull(JoomlaUpdateMapper::availableUpdate('5.4.0', '5.4.0', true));
	}

	public function testAvailableUpdateNullWhenCurrentIsNewerThanLatest(): void
	{
		$this->assertNull(JoomlaUpdateMapper::availableUpdate('5.3.0', '5.4.0', true));
	}

	public function testAvailableUpdateNullWhenRequirementsAreNotMet(): void
	{
		$this->assertNull(JoomlaUpdateMapper::availableUpdate('5.5.0', '5.4.0', false));
	}

	public function testAvailableUpdateReturnsLatestWhenNewerAndRequirementsOk(): void
	{
		$this->assertSame('5.5.0', JoomlaUpdateMapper::availableUpdate('5.5.0', '5.4.0', true));
	}

	// -----------------------------------------------------------------------------------------------------
	// HEALTHCHECK_FIELDS
	// -----------------------------------------------------------------------------------------------------

	public function testHealthcheckFieldsMatchesCoreView(): void
	{
		$this->assertSame(
			[
				'php_version',
				'db_type',
				'db_version',
				'cms_version',
				'server_os',
				'update_requirement_state',
				'automated_updates_enabled',
			],
			JoomlaUpdateMapper::HEALTHCHECK_FIELDS
		);
	}
}
