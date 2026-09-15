<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Mapper\Newsfeeds;

use Akeeba\Component\Mcp4j\Administrator\Mapper\Newsfeeds\FeedMapper;
use PHPUnit\Framework\TestCase;

class FeedMapperTest extends TestCase
{
	// -----------------------------------------------------------------------------------------------------
	// listState()
	// -----------------------------------------------------------------------------------------------------

	public function testListStateWithEverythingNullOnlySetsOrderingAndEmptyPublishedFilter(): void
	{
		$result = FeedMapper::listState(null, null, null, null);

		$this->assertSame(
			[
				'list.ordering'    => 'a.name',
				'list.direction'   => 'ASC',
				'filter.published' => '',
			],
			$result
		);
	}

	public function testListStateSearchIsSetOnlyWhenNonEmpty(): void
	{
		$this->assertSame('foo', FeedMapper::listState('foo', null, null, null)['filter.search']);
		$this->assertArrayNotHasKey('filter.search', FeedMapper::listState('', null, null, null));
		$this->assertArrayNotHasKey('filter.search', FeedMapper::listState(null, null, null, null));
	}

	public function testListStatePublishedIsStringifiedWhenGiven(): void
	{
		$this->assertSame('0', FeedMapper::listState(null, 0, null, null)['filter.published']);
		$this->assertSame('1', FeedMapper::listState(null, 1, null, null)['filter.published']);
		$this->assertSame('-2', FeedMapper::listState(null, -2, null, null)['filter.published']);
	}

	public function testListStateCategoryIsSetWhenGiven(): void
	{
		$this->assertSame(9, FeedMapper::listState(null, null, 9, null)['filter.category_id']);
	}

	public function testListStateCategoryIsAbsentWhenNull(): void
	{
		$this->assertArrayNotHasKey('filter.category_id', FeedMapper::listState(null, null, null, null));
	}

	public function testListStateLanguageIsSetWhenGiven(): void
	{
		$this->assertSame('en-GB', FeedMapper::listState(null, null, null, 'en-GB')['filter.language']);
	}

	public function testListStateLanguageIsAbsentWhenNull(): void
	{
		$this->assertArrayNotHasKey('filter.language', FeedMapper::listState(null, null, null, null));
	}

	// -----------------------------------------------------------------------------------------------------
	// createData()
	// -----------------------------------------------------------------------------------------------------

	private function createMinimal(array $overrides = []): array
	{
		$defaults = [
			'name'        => 'Joomla Announcements',
			'link'        => 'https://www.joomla.org/announcements.feed?type=rss',
			'catId'       => 5,
			'alias'       => null,
			'published'   => 1,
			'access'      => null,
			'language'    => '*',
			'description' => null,
			'numArticles' => null,
			'cacheTime'   => null,
			'ordering'    => null,
			'tags'        => null,
			'metadesc'    => null,
			'metakey'     => null,
		];

		$args = array_replace($defaults, $overrides);

		return FeedMapper::createData(...array_values($args));
	}

	public function testCreateDataMinimalOnlySetsNameLinkCatidPublishedAccessLanguageTags(): void
	{
		$result = $this->createMinimal();

		$this->assertSame('Joomla Announcements', $result['name']);
		$this->assertSame('https://www.joomla.org/announcements.feed?type=rss', $result['link']);
		$this->assertSame(5, $result['catid']);
		$this->assertSame(1, $result['published']);
		$this->assertSame(1, $result['access'], 'access must default to 1 when null.');
		$this->assertSame('*', $result['language']);
		$this->assertSame([], $result['tags']);
		$this->assertArrayNotHasKey('numarticles', $result);
		$this->assertArrayNotHasKey('cache_time', $result);
		$this->assertArrayNotHasKey('ordering', $result);
		$this->assertArrayNotHasKey('metakey', $result);

		// #__newsfeeds' alias/params/metadata/images/description/metadesc columns are all `NOT NULL` with
		// no usable column default (see createData()'s docblock — alias's is a driver-introspection quirk,
		// the rest a genuine NewsfeedTable::check()/prepareTable() gap vs. ContactTable's), so every one of
		// these must always be present, even when nothing was given.
		$this->assertSame('', $result['alias']);
		$this->assertSame('', $result['description']);
		$this->assertSame('', $result['metadesc']);
		$this->assertIsArray($result['params']);
		$this->assertIsArray($result['metadata']);
		$this->assertIsArray($result['images']);
	}

	public function testCreateDataAliasDefaultsToEmptyStringWhenNullOrEmpty(): void
	{
		$this->assertSame('', $this->createMinimal(['alias' => null])['alias']);
		$this->assertSame('', $this->createMinimal(['alias' => ''])['alias']);
		$this->assertSame('joomla-announcements', $this->createMinimal(['alias' => 'joomla-announcements'])['alias']);
	}

	public function testCreateDataAccessDefaultsToOneWhenNull(): void
	{
		$this->assertSame(1, $this->createMinimal(['access' => null])['access']);
		$this->assertSame(3, $this->createMinimal(['access' => 3])['access']);
	}

	public function testCreateDataNumArticlesMapsToNumarticles(): void
	{
		$this->assertArrayNotHasKey('numarticles', $this->createMinimal());
		$this->assertSame(5, $this->createMinimal(['numArticles' => 5])['numarticles']);
	}

	public function testCreateDataCacheTimeMapsToCacheTimeColumn(): void
	{
		$this->assertArrayNotHasKey('cache_time', $this->createMinimal());
		$this->assertSame(3600, $this->createMinimal(['cacheTime' => 3600])['cache_time']);
	}

	public function testCreateDataOrderingOnlyWhenGiven(): void
	{
		$this->assertArrayNotHasKey('ordering', $this->createMinimal());
		$this->assertSame(2, $this->createMinimal(['ordering' => 2])['ordering']);
	}

	public function testCreateDataDescriptionDefaultsToEmptyStringWhenNull(): void
	{
		$this->assertSame('', $this->createMinimal()['description']);
		$this->assertSame('A feed', $this->createMinimal(['description' => 'A feed'])['description']);
	}

	public function testCreateDataMetadescDefaultsToEmptyStringWhenNull(): void
	{
		$this->assertSame('', $this->createMinimal()['metadesc']);
		$this->assertSame('desc', $this->createMinimal(['metadesc' => 'desc'])['metadesc']);
	}

	public function testCreateDataMetakeyOnlyWhenGiven(): void
	{
		$this->assertArrayNotHasKey('metakey', $this->createMinimal());
		$this->assertSame('a,b', $this->createMinimal(['metakey' => 'a,b'])['metakey']);
	}

	public function testCreateDataTagsNormalisedOrEmpty(): void
	{
		$this->assertSame([], $this->createMinimal(['tags' => null])['tags']);
		$this->assertSame([3, 4], $this->createMinimal(['tags' => ['3', '4']])['tags']);
	}

	// -----------------------------------------------------------------------------------------------------
	// updateData()
	// -----------------------------------------------------------------------------------------------------

	private function updateFields(array $overrides, array $currentItem = []): array
	{
		$defaults = [
			'name'        => null,
			'link'        => null,
			'catId'       => null,
			'alias'       => null,
			'published'   => null,
			'access'      => null,
			'language'    => null,
			'description' => null,
			'numArticles' => null,
			'cacheTime'   => null,
			'ordering'    => null,
			'tags'        => null,
			'metadesc'    => null,
			'metakey'     => null,
		];

		$args                 = array_replace($defaults, $overrides);
		$args['currentItem']  = $currentItem;

		return FeedMapper::updateData(...array_values($args));
	}

	public function testUpdateDataAllNullProducesOnlyTags(): void
	{
		$result = $this->updateFields([], ['tags' => [1, 2]]);

		$this->assertSame(['tags' => [1, 2]], $result);
	}

	public function testUpdateDataOnlyDescriptionChangesOnlyThatKey(): void
	{
		$result = $this->updateFields(['description' => 'Updated'], ['tags' => []]);

		$this->assertSame(['description' => 'Updated', 'tags' => []], $result);
	}

	public function testUpdateDataNumArticlesMapsToNumarticles(): void
	{
		$result = $this->updateFields(['numArticles' => 10], []);

		$this->assertSame(10, $result['numarticles']);
	}

	public function testUpdateDataCacheTimeMapsToCacheTimeColumn(): void
	{
		$result = $this->updateFields(['cacheTime' => 7200], []);

		$this->assertSame(7200, $result['cache_time']);
	}

	public function testUpdateDataTagsFallBackToCurrentItemWhenNull(): void
	{
		$result = $this->updateFields([], ['tags' => [5, 6]]);

		$this->assertSame([5, 6], $result['tags']);
	}

	public function testUpdateDataTagsUseGivenValueOverCurrent(): void
	{
		$result = $this->updateFields(['tags' => [9]], ['tags' => [5, 6]]);

		$this->assertSame([9], $result['tags']);
	}

	public function testUpdateDataExplicitEmptyTagsClearsThem(): void
	{
		$result = $this->updateFields(['tags' => []], ['tags' => [5, 6]]);

		$this->assertSame([], $result['tags']);
	}

	// -----------------------------------------------------------------------------------------------------
	// normaliseItem()
	// -----------------------------------------------------------------------------------------------------

	public function testNormaliseItemConvertsTagsHelperShapedValueToIdList(): void
	{
		$result = FeedMapper::normaliseItem(['id' => 1, 'tags' => ['tags' => '3,4', 'typeAlias' => 'com_newsfeeds.newsfeed']]);

		$this->assertSame([3, 4], $result['tags']);
	}

	public function testNormaliseItemRemovesMetadataTagsKey(): void
	{
		$result = FeedMapper::normaliseItem([
			'id'       => 1,
			'tags'     => [3],
			'metadata' => ['robots' => '', 'rights' => '', 'tags' => ['tags' => '3', 'typeAlias' => 'com_newsfeeds.newsfeed']],
		]);

		$this->assertArrayNotHasKey('tags', $result['metadata']);
		$this->assertSame('', $result['metadata']['robots']);
	}

	public function testNormaliseItemToleratesMissingOrNonArrayMetadata(): void
	{
		$result = FeedMapper::normaliseItem(['id' => 1, 'tags' => []]);
		$this->assertArrayNotHasKey('metadata', $result);

		$result = FeedMapper::normaliseItem(['id' => 1, 'tags' => [], 'metadata' => '']);
		$this->assertSame('', $result['metadata']);
	}

	// -----------------------------------------------------------------------------------------------------
	// output whitelist
	// -----------------------------------------------------------------------------------------------------

	public function testItemFieldsWhitelistExcludesAssetIdAndAssociations(): void
	{
		$this->assertNotContains('asset_id', FeedMapper::ITEM_FIELDS);
		$this->assertNotContains('associations', FeedMapper::ITEM_FIELDS);
	}

	public function testItemFieldsWhitelistIncludesCatidAndPublishing(): void
	{
		$this->assertContains('id', FeedMapper::ITEM_FIELDS);
		$this->assertContains('catid', FeedMapper::ITEM_FIELDS);
		$this->assertContains('published', FeedMapper::ITEM_FIELDS);
		$this->assertContains('link', FeedMapper::ITEM_FIELDS);
	}
}
