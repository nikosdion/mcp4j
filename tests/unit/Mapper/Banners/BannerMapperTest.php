<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Mapper\Banners;

use Akeeba\Component\Mcp4j\Administrator\Mapper\Banners\BannerMapper;
use PHPUnit\Framework\TestCase;

class BannerMapperTest extends TestCase
{
	// -----------------------------------------------------------------------------------------------------
	// listState()
	// -----------------------------------------------------------------------------------------------------

	public function testListStateWithEverythingNullOnlySetsOrderingAndEmptyPublishedFilter(): void
	{
		$result = BannerMapper::listState(null, null, null, null);

		$this->assertSame(
			[
				'list.ordering'    => 'a.name',
				'list.direction'   => 'ASC',
				'filter.published' => '',
			],
			$result
		);
	}

	public function testListStateSearchIsSetWhenGivenAndNonEmpty(): void
	{
		$this->assertSame('foo', BannerMapper::listState('foo', null, null, null)['filter.search']);
	}

	public function testListStateSearchIsAbsentWhenNullOrEmpty(): void
	{
		$this->assertArrayNotHasKey('filter.search', BannerMapper::listState(null, null, null, null));
		$this->assertArrayNotHasKey('filter.search', BannerMapper::listState('', null, null, null));
	}

	public function testListStatePublishedIsStringifiedWhenGiven(): void
	{
		$this->assertSame('0', BannerMapper::listState(null, 0, null, null)['filter.published']);
		$this->assertSame('1', BannerMapper::listState(null, 1, null, null)['filter.published']);
		$this->assertSame('2', BannerMapper::listState(null, 2, null, null)['filter.published']);
		$this->assertSame('-2', BannerMapper::listState(null, -2, null, null)['filter.published']);
	}

	public function testListStateCategoryIsSetWhenGiven(): void
	{
		$this->assertSame(5, BannerMapper::listState(null, null, 5, null)['filter.category_id']);
	}

	public function testListStateCategoryIsAbsentWhenNull(): void
	{
		$this->assertArrayNotHasKey('filter.category_id', BannerMapper::listState(null, null, null, null));
	}

	public function testListStateClientIsSetWhenGiven(): void
	{
		$this->assertSame(7, BannerMapper::listState(null, null, null, 7)['filter.client_id']);
	}

	public function testListStateClientIsAbsentWhenNull(): void
	{
		$this->assertArrayNotHasKey('filter.client_id', BannerMapper::listState(null, null, null, null));
	}

	public function testListStateAlwaysOrdersByNameAscending(): void
	{
		$result = BannerMapper::listState('x', 1, 2, 3);

		$this->assertSame('a.name', $result['list.ordering']);
		$this->assertSame('ASC', $result['list.direction']);
	}

	// -----------------------------------------------------------------------------------------------------
	// createData()
	// -----------------------------------------------------------------------------------------------------

	private function createMinimal(array $overrides = []): array
	{
		$args = array_replace(
			[
				'name'              => 'My Banner',
				'catId'             => 3,
				'alias'             => null,
				'state'             => 1,
				'cid'               => null,
				'type'              => null,
				'clickUrl'          => null,
				'customBannerCode'  => null,
				'purchaseType'      => null,
				'trackImpressions'  => null,
				'trackClicks'       => null,
				'description'       => null,
				'language'          => '*',
				'metakey'           => null,
			],
			$overrides
		);

		return BannerMapper::createData(
			$args['name'],
			$args['catId'],
			$args['alias'],
			$args['state'],
			$args['cid'],
			$args['type'],
			$args['clickUrl'],
			$args['customBannerCode'],
			$args['purchaseType'],
			$args['trackImpressions'],
			$args['trackClicks'],
			$args['description'],
			$args['language'],
			$args['metakey']
		);
	}

	public function testCreateDataWithMinimalArguments(): void
	{
		$result = $this->createMinimal();

		$this->assertSame('My Banner', $result['name']);
		$this->assertSame(3, $result['catid']);
		$this->assertSame('', $result['alias'], 'alias must always be present (empty string) to avoid the trim(null) deprecation.');
		$this->assertSame(1, $result['state']);
		$this->assertSame('*', $result['language']);
		$this->assertArrayNotHasKey('cid', $result);
		$this->assertArrayNotHasKey('type', $result);
		$this->assertArrayNotHasKey('clickurl', $result);
		$this->assertArrayNotHasKey('purchase_type', $result);
		$this->assertArrayNotHasKey('track_impressions', $result);
		$this->assertArrayNotHasKey('track_clicks', $result);

		// description/custombannercode/metakey/params are `text NOT NULL` with no DB column default and
		// must always be present (as an empty string when not given) — see createData()'s docblock.
		$this->assertSame('', $result['description']);
		$this->assertSame('', $result['custombannercode']);
		$this->assertSame('', $result['metakey']);

		// params must be sent as the nested field group (not a flat '') — see createData()'s docblock on
		// why a flat string would be silently dropped by Form::filter().
		$this->assertSame(['imageurl' => '', 'width' => '', 'height' => '', 'alt' => ''], $result['params']);
	}

	public function testCreateDataNonEmptyAliasIsIncluded(): void
	{
		$result = $this->createMinimal(['alias' => 'my-banner']);

		$this->assertSame('my-banner', $result['alias']);
	}

	public function testCreateDataTypeStringIsCoercedToInt(): void
	{
		$this->assertSame(0, $this->createMinimal(['type' => '0'])['type']);
		$this->assertSame(1, $this->createMinimal(['type' => '1'])['type']);
		$this->assertIsInt($this->createMinimal(['type' => '1'])['type']);
	}

	public function testCreateDataOptionalFieldsAreIncludedWhenGiven(): void
	{
		$result = $this->createMinimal([
			'cid'              => 4,
			'type'             => '1',
			'clickUrl'         => 'https://example.com/',
			'customBannerCode' => '<p>Hello</p>',
			'purchaseType'     => 2,
			'trackImpressions' => 1,
			'trackClicks'      => 0,
			'description'      => 'A banner',
			'metakey'          => 'a,b',
		]);

		$this->assertSame(4, $result['cid']);
		$this->assertSame(1, $result['type']);
		$this->assertSame('https://example.com/', $result['clickurl']);
		$this->assertSame('<p>Hello</p>', $result['custombannercode']);
		$this->assertSame(2, $result['purchase_type']);
		$this->assertSame(1, $result['track_impressions']);
		$this->assertSame(0, $result['track_clicks']);
		$this->assertSame('A banner', $result['description']);
		$this->assertSame('a,b', $result['metakey']);
	}

	public function testCreateDataTrackClicksZeroIsIncludedNotTreatedAsNull(): void
	{
		$result = $this->createMinimal(['trackClicks' => 0]);

		$this->assertArrayHasKey('track_clicks', $result);
		$this->assertSame(0, $result['track_clicks']);
	}

	// -----------------------------------------------------------------------------------------------------
	// updateData()
	// -----------------------------------------------------------------------------------------------------

	public function testUpdateDataWithEverythingNullReturnsEmptyArray(): void
	{
		$result = BannerMapper::updateData(null, null, null, null, null, null, null, null, null, null, null, null, null, null);

		$this->assertSame([], $result);
	}

	public function testUpdateDataKeepsOnlyExplicitlyGivenValues(): void
	{
		$result = BannerMapper::updateData('New name', null, null, null, null, null, null, null, null, null, null, null, null, null);

		$this->assertSame(['name' => 'New name'], $result);
	}

	public function testUpdateDataTypeStringIsCoercedToIntWhenGiven(): void
	{
		$result = BannerMapper::updateData(null, null, null, null, null, '1', null, null, null, null, null, null, null, null);

		$this->assertSame(['type' => 1], $result);
	}

	public function testUpdateDataStateZeroIsKeptNotTreatedAsNull(): void
	{
		$result = BannerMapper::updateData(null, null, null, 0, null, null, null, null, null, null, null, null, null, null);

		$this->assertSame(['state' => 0], $result);
	}

	public function testUpdateDataEveryFieldTogether(): void
	{
		$result = BannerMapper::updateData(
			'Name',
			'alias',
			5,
			1,
			2,
			'0',
			'https://example.com/',
			'<b>x</b>',
			3,
			1,
			0,
			'Desc',
			'en-GB',
			'k1,k2'
		);

		$this->assertSame(
			[
				'name'              => 'Name',
				'alias'             => 'alias',
				'catid'             => 5,
				'state'             => 1,
				'cid'               => 2,
				'type'              => 0,
				'clickurl'          => 'https://example.com/',
				'custombannercode'  => '<b>x</b>',
				'purchase_type'     => 3,
				'track_impressions' => 1,
				'track_clicks'      => 0,
				'description'       => 'Desc',
				'language'          => 'en-GB',
				'metakey'           => 'k1,k2',
			],
			$result
		);
	}

	// -----------------------------------------------------------------------------------------------------
	// unsupported()
	// -----------------------------------------------------------------------------------------------------

	public function testUnsupportedIsEmptyWhenBothNull(): void
	{
		$this->assertSame([], BannerMapper::unsupported(null, null));
	}

	public function testUnsupportedIsEmptyWhenTagsIsEmptyArrayAndMetadescIsEmptyString(): void
	{
		$this->assertSame([], BannerMapper::unsupported([], ''));
	}

	public function testUnsupportedListsTagsWhenNonEmpty(): void
	{
		$this->assertSame(['tags'], BannerMapper::unsupported([1, 2], null));
	}

	public function testUnsupportedListsMetadescWhenNonEmpty(): void
	{
		$this->assertSame(['metadesc'], BannerMapper::unsupported(null, 'x'));
	}

	public function testUnsupportedListsBothWhenBothGiven(): void
	{
		$this->assertSame(['tags', 'metadesc'], BannerMapper::unsupported([1], 'x'));
	}

	// -----------------------------------------------------------------------------------------------------
	// Field whitelists — hard-coded so a future edit is deliberate.
	// -----------------------------------------------------------------------------------------------------

	public function testItemFieldsMatchesPlan(): void
	{
		$this->assertSame(
			[
				'id', 'name', 'alias', 'catid', 'state', 'cid', 'type', 'imptotal', 'impmade',
				'clicks', 'clickurl', 'description', 'custombannercode', 'sticky', 'ordering', 'metakey', 'params',
				'own_prefix', 'metakey_prefix', 'purchase_type', 'track_clicks', 'track_impressions', 'checked_out',
				'checked_out_time', 'publish_up', 'publish_down', 'reset', 'created', 'language', 'created_by',
				'created_by_alias', 'modified', 'modified_by', 'version',
			],
			BannerMapper::ITEM_FIELDS
		);
	}

	public function testListFieldsMatchesPlan(): void
	{
		$this->assertSame(
			[
				'id', 'name', 'alias', 'checked_out', 'checked_out_time', 'catid', 'category_title',
				'clicks', 'metakey', 'sticky', 'impmade', 'imptotal', 'state', 'ordering', 'purchase_type', 'language',
				'language_image', 'publish_up', 'publish_down', 'editor', 'client_name', 'client_purchase_type',
			],
			BannerMapper::LIST_FIELDS
		);
	}

	public function testFieldsExcludeInternalAndSensitiveKeys(): void
	{
		$excluded = ['typeAlias', 'contenthistoryHelper', 'asset_id', 'password', 'otpKey'];

		foreach ($excluded as $key)
		{
			$this->assertNotContains($key, BannerMapper::ITEM_FIELDS);
			$this->assertNotContains($key, BannerMapper::LIST_FIELDS);
		}
	}

	public function testItemFieldsNeverIncludeTags(): void
	{
		// Banners have no tags support at all (see unsupported()) — 'tags' must never appear here.
		$this->assertNotContains('tags', BannerMapper::ITEM_FIELDS);
		$this->assertNotContains('tags', BannerMapper::LIST_FIELDS);
	}

	public function testIntFieldsAndJsonFields(): void
	{
		$this->assertSame(
			[
				'catid', 'state', 'cid', 'type', 'imptotal', 'impmade', 'clicks', 'sticky', 'ordering',
				'own_prefix', 'purchase_type', 'track_clicks', 'track_impressions', 'checked_out', 'created_by',
				'modified_by', 'version', 'client_purchase_type',
			],
			BannerMapper::INT_FIELDS
		);

		$this->assertSame(['params'], BannerMapper::JSON_FIELDS);
	}
}
