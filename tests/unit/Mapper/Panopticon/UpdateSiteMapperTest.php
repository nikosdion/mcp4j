<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Mapper\Panopticon;

use Akeeba\Component\Mcp4j\Administrator\Mapper\Panopticon\UpdateSiteMapper;
use PHPUnit\Framework\TestCase;

class UpdateSiteMapperTest extends TestCase
{
	// -----------------------------------------------------------------------------------------------------
	// listState()
	// -----------------------------------------------------------------------------------------------------

	public function testListStateDefaults(): void
	{
		$this->assertSame(
			[
				'filter.search'    => '',
				'filter.enabled'   => '',
				'filter.supported' => '',
				'filter.type'      => '',
				'filter.client_id' => '',
				'filter.folder'    => '',
				'filter.eid'       => [],
				'list.ordering'    => 'name',
				'list.direction'   => 'asc',
			],
			UpdateSiteMapper::listState(null)
		);
	}

	public function testListStateSearchIsSetWhenGiven(): void
	{
		$this->assertSame('joomla', UpdateSiteMapper::listState('joomla')['filter.search']);
	}

	// -----------------------------------------------------------------------------------------------------
	// stripAffixes()
	// -----------------------------------------------------------------------------------------------------

	public function testStripAffixesStripsPrefixOnly(): void
	{
		$this->assertSame('KEY', UpdateSiteMapper::stripAffixes('dlid=KEY', 'dlid=', ''));
	}

	public function testStripAffixesStripsSuffixOnly(): void
	{
		$this->assertSame('KEY', UpdateSiteMapper::stripAffixes('KEY&extra=1', '', '&extra=1'));
	}

	public function testStripAffixesStripsBoth(): void
	{
		$this->assertSame('KEY', UpdateSiteMapper::stripAffixes('dlid=KEY&extra=1', 'dlid=', '&extra=1'));
	}

	public function testStripAffixesStripsNeither(): void
	{
		$this->assertSame('KEY', UpdateSiteMapper::stripAffixes('KEY', '', ''));
	}

	public function testStripAffixesLeavesValueUnchangedWhenAffixNotPresent(): void
	{
		$this->assertSame('somethingelse=KEY', UpdateSiteMapper::stripAffixes('somethingelse=KEY', 'dlid=', '&extra=1'));
	}

	// -----------------------------------------------------------------------------------------------------
	// sanitise()
	// -----------------------------------------------------------------------------------------------------

	public function testSanitiseMasksExtraQuery(): void
	{
		$result = UpdateSiteMapper::sanitise(['extra_query' => 'dlid=abcdef123456']);

		$this->assertStringStartsWith('****', $result['extra_query']);
	}

	public function testSanitiseLeavesEmptyExtraQueryUnchanged(): void
	{
		$result = UpdateSiteMapper::sanitise(['extra_query' => '']);

		$this->assertSame('', $result['extra_query']);
	}

	public function testSanitiseMasksDownloadKeyValue(): void
	{
		$result = UpdateSiteMapper::sanitise(['downloadKey' => ['value' => 'abcdef0123456789']]);

		$this->assertStringStartsWith('****', $result['downloadKey']['value']);
	}

	public function testSanitiseDropsDownloadIdPrefixAndSuffix(): void
	{
		$result = UpdateSiteMapper::sanitise([
			'update_site_id'   => 1,
			'downloadIdPrefix' => 'dlid=',
			'downloadIdSuffix' => '',
		]);

		$this->assertArrayNotHasKey('downloadIdPrefix', $result);
		$this->assertArrayNotHasKey('downloadIdSuffix', $result);
	}

	// -----------------------------------------------------------------------------------------------------
	// unsupportedChanges()
	// -----------------------------------------------------------------------------------------------------

	public function testUnsupportedChangesEmptyWhenBothNull(): void
	{
		$this->assertSame([], UpdateSiteMapper::unsupportedChanges(null, null));
	}

	public function testUnsupportedChangesNameOnly(): void
	{
		$this->assertSame(['name'], UpdateSiteMapper::unsupportedChanges('New name', null));
	}

	public function testUnsupportedChangesLocationOnly(): void
	{
		$this->assertSame(['location'], UpdateSiteMapper::unsupportedChanges(null, 'https://example.com'));
	}

	public function testUnsupportedChangesBoth(): void
	{
		$this->assertSame(['name', 'location'], UpdateSiteMapper::unsupportedChanges('New name', 'https://example.com'));
	}
}
