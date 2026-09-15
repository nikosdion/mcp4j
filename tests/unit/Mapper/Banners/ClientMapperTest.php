<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Mapper\Banners;

use Akeeba\Component\Mcp4j\Administrator\Mapper\Banners\ClientMapper;
use PHPUnit\Framework\TestCase;

class ClientMapperTest extends TestCase
{
	// -----------------------------------------------------------------------------------------------------
	// listState()
	// -----------------------------------------------------------------------------------------------------

	public function testListStateWithEverythingNullOnlySetsOrderingAndEmptyStateFilter(): void
	{
		$result = ClientMapper::listState(null, null);

		$this->assertSame(
			[
				'list.ordering'  => 'a.name',
				'list.direction' => 'ASC',
				'filter.state'   => '',
			],
			$result
		);
	}

	public function testListStateUsesFilterStateKeyNotFilterPublished(): void
	{
		// ClientsModel reads filter.state, NOT filter.published (that key belongs to BannersModel) — the
		// single most important gotcha this mapper exists to get right, see .plans/08-banners.md.
		$result = ClientMapper::listState(null, 1);

		$this->assertArrayHasKey('filter.state', $result);
		$this->assertArrayNotHasKey('filter.published', $result);
	}

	public function testListStateSearchIsSetWhenGivenAndNonEmpty(): void
	{
		$this->assertSame('foo', ClientMapper::listState('foo', null)['filter.search']);
	}

	public function testListStateSearchIsAbsentWhenNullOrEmpty(): void
	{
		$this->assertArrayNotHasKey('filter.search', ClientMapper::listState(null, null));
		$this->assertArrayNotHasKey('filter.search', ClientMapper::listState('', null));
	}

	public function testListStateIsStringifiedWhenGiven(): void
	{
		$this->assertSame('0', ClientMapper::listState(null, 0)['filter.state']);
		$this->assertSame('1', ClientMapper::listState(null, 1)['filter.state']);
		$this->assertSame('-2', ClientMapper::listState(null, -2)['filter.state']);
	}

	public function testListStateAlwaysOrdersByNameAscending(): void
	{
		$result = ClientMapper::listState('x', 1);

		$this->assertSame('a.name', $result['list.ordering']);
		$this->assertSame('ASC', $result['list.direction']);
	}

	// -----------------------------------------------------------------------------------------------------
	// createData() — swapped name/contactName mapping
	// -----------------------------------------------------------------------------------------------------

	public function testCreateDataSwapsNameAndContactNameOntoContactAndNameFormKeys(): void
	{
		// The original tool's "name" parameter is the client's CONTACT name and maps to the form's
		// "contact" field; its "contactName" parameter is the company/display name and maps to the form's
		// "name" field. This is the original's own naming, preserved verbatim — see ClientMapper's
		// docblock and .plans/00-conventions.md §2.
		$result = ClientMapper::createData('Jane Doe', 'Acme Ltd', null, null, 1, null, null, null, null);

		$this->assertSame('Jane Doe', $result['contact'], '"name" (contact name) must map to the "contact" form key.');
		$this->assertSame('Acme Ltd', $result['name'], '"contactName" (company name) must map to the "name" form key.');
	}

	public function testCreateDataWithMinimalArguments(): void
	{
		$result = ClientMapper::createData('Jane Doe', 'Acme Ltd', null, null, 1, null, null, null, null);

		$this->assertSame(1, $result['state']);
		$this->assertArrayNotHasKey('email', $result);
		$this->assertArrayNotHasKey('purchase_type', $result);
		$this->assertArrayNotHasKey('track_impressions', $result);
		$this->assertArrayNotHasKey('track_clicks', $result);

		// extrainfo/metakey are `text NOT NULL` with no DB column default and must always be present (as
		// an empty string when not given) — see createData()'s docblock.
		$this->assertSame('', $result['extrainfo']);
		$this->assertSame('', $result['metakey']);
	}

	public function testCreateDataOptionalFieldsAreIncludedWhenGiven(): void
	{
		$result = ClientMapper::createData(
			'Jane Doe',
			'Acme Ltd',
			'jane@example.com',
			'Extra info',
			0,
			2,
			1,
			0,
			'a,b'
		);

		$this->assertSame('jane@example.com', $result['email']);
		$this->assertSame('Extra info', $result['extrainfo']);
		$this->assertSame(0, $result['state']);
		$this->assertSame(2, $result['purchase_type']);
		$this->assertSame(1, $result['track_impressions']);
		$this->assertSame(0, $result['track_clicks']);
		$this->assertSame('a,b', $result['metakey']);
	}

	public function testCreateDataTrackClicksZeroIsIncludedNotTreatedAsNull(): void
	{
		$result = ClientMapper::createData('Jane Doe', 'Acme Ltd', null, null, 1, null, null, 0, null);

		$this->assertArrayHasKey('track_clicks', $result);
		$this->assertSame(0, $result['track_clicks']);
	}

	// -----------------------------------------------------------------------------------------------------
	// updateData()
	// -----------------------------------------------------------------------------------------------------

	public function testUpdateDataWithEverythingNullReturnsEmptyArray(): void
	{
		$result = ClientMapper::updateData(null, null, null, null, null, null, null, null, null);

		$this->assertSame([], $result);
	}

	public function testUpdateDataSwapsNameAndContactNameOntoContactAndNameFormKeys(): void
	{
		$result = ClientMapper::updateData('Jane Doe', 'Acme Ltd', null, null, null, null, null, null, null);

		$this->assertSame(['contact' => 'Jane Doe', 'name' => 'Acme Ltd'], $result);
	}

	public function testUpdateDataKeepsOnlyExplicitlyGivenValues(): void
	{
		$result = ClientMapper::updateData(null, null, null, 'New extra info', null, null, null, null, null);

		$this->assertSame(['extrainfo' => 'New extra info'], $result);
	}

	public function testUpdateDataStateZeroIsKeptNotTreatedAsNull(): void
	{
		$result = ClientMapper::updateData(null, null, null, null, 0, null, null, null, null);

		$this->assertSame(['state' => 0], $result);
	}

	public function testUpdateDataEveryFieldTogether(): void
	{
		$result = ClientMapper::updateData(
			'Jane Doe',
			'Acme Ltd',
			'jane@example.com',
			'Extra',
			1,
			2,
			1,
			0,
			'k1,k2'
		);

		$this->assertSame(
			[
				'contact'           => 'Jane Doe',
				'name'              => 'Acme Ltd',
				'email'             => 'jane@example.com',
				'extrainfo'         => 'Extra',
				'state'             => 1,
				'purchase_type'     => 2,
				'track_impressions' => 1,
				'track_clicks'      => 0,
				'metakey'           => 'k1,k2',
			],
			$result
		);
	}

	// -----------------------------------------------------------------------------------------------------
	// unsupported()
	// -----------------------------------------------------------------------------------------------------

	public function testUnsupportedIsEmptyWhenNull(): void
	{
		$this->assertSame([], ClientMapper::unsupported(null));
	}

	public function testUnsupportedIsEmptyWhenEmptyString(): void
	{
		$this->assertSame([], ClientMapper::unsupported(''));
	}

	public function testUnsupportedListsMetadescWhenNonEmpty(): void
	{
		$this->assertSame(['metadesc'], ClientMapper::unsupported('x'));
	}

	// -----------------------------------------------------------------------------------------------------
	// Field whitelists — hard-coded so a future edit is deliberate.
	// -----------------------------------------------------------------------------------------------------

	public function testItemFieldsMatchesPlan(): void
	{
		$this->assertSame(
			[
				'id', 'name', 'contact', 'email', 'extrainfo', 'state', 'metakey', 'own_prefix',
				'metakey_prefix', 'purchase_type', 'track_clicks', 'track_impressions', 'checked_out', 'checked_out_time',
			],
			ClientMapper::ITEM_FIELDS
		);
	}

	public function testListFieldsMatchesPlanAndExcludesEmail(): void
	{
		// email is deliberately excluded from LIST_FIELDS: ClientsModel::getListQuery()'s select list
		// never includes a.email, so it would never actually be present on a list row — see the class
		// docblock.
		$this->assertSame(
			[
				'id', 'name', 'contact', 'checked_out', 'checked_out_time', 'state', 'metakey',
				'purchase_type', 'nbanners', 'editor', 'count_published', 'count_unpublished', 'count_trashed', 'count_archived',
			],
			ClientMapper::LIST_FIELDS
		);

		$this->assertNotContains('email', ClientMapper::LIST_FIELDS);
	}

	public function testFieldsExcludeInternalAndSensitiveKeys(): void
	{
		$excluded = ['typeAlias', 'password', 'otpKey', 'checked_out_time_dup'];

		foreach ($excluded as $key)
		{
			$this->assertNotContains($key, ClientMapper::ITEM_FIELDS);
			$this->assertNotContains($key, ClientMapper::LIST_FIELDS);
		}
	}

	public function testIntFields(): void
	{
		$this->assertSame(
			[
				'state', 'own_prefix', 'purchase_type', 'track_clicks', 'track_impressions',
				'checked_out', 'nbanners', 'count_published', 'count_unpublished', 'count_trashed', 'count_archived',
			],
			ClientMapper::INT_FIELDS
		);
	}
}
