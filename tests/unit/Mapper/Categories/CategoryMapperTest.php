<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Mapper\Categories;

use Akeeba\Component\Mcp4j\Administrator\Mapper\Categories\CategoryMapper;
use PHPUnit\Framework\TestCase;

class CategoryMapperTest extends TestCase
{
	// -----------------------------------------------------------------------------------------------------
	// listState()
	// -----------------------------------------------------------------------------------------------------

	public function testListStateAlwaysSetsFilterExtension(): void
	{
		$result = CategoryMapper::listState('com_content', null, null, null, null);

		$this->assertSame('com_content', $result['filter.extension']);
	}

	public function testListStateWithEverythingNullOnlySetsExtensionOrderingAndEmptyPublishedFilter(): void
	{
		$result = CategoryMapper::listState('com_content', null, null, null, null);

		$this->assertSame(
			[
				'filter.extension' => 'com_content',
				'list.ordering'    => 'a.lft',
				'list.direction'   => 'ASC',
				'filter.published' => '',
			],
			$result
		);
	}

	public function testListStateSearchIsSetWhenGivenAndNonEmpty(): void
	{
		$result = CategoryMapper::listState('com_content', 'foo', null, null, null);

		$this->assertSame('foo', $result['filter.search']);
	}

	public function testListStateSearchIsAbsentWhenNullOrEmpty(): void
	{
		$this->assertArrayNotHasKey('filter.search', CategoryMapper::listState('com_content', null, null, null, null));
		$this->assertArrayNotHasKey('filter.search', CategoryMapper::listState('com_content', '', null, null, null));
	}

	public function testListStatePublishedIsStringifiedWhenGiven(): void
	{
		$this->assertSame('0', CategoryMapper::listState('com_content', null, 0, null, null)['filter.published']);
		$this->assertSame('1', CategoryMapper::listState('com_content', null, 1, null, null)['filter.published']);
		$this->assertSame('-2', CategoryMapper::listState('com_content', null, -2, null, null)['filter.published']);
	}

	public function testListStateLanguageIsSetWhenGiven(): void
	{
		$this->assertSame('en-GB', CategoryMapper::listState('com_content', null, null, 'en-GB', null)['filter.language']);
	}

	public function testListStateLanguageIsAbsentWhenNull(): void
	{
		$this->assertArrayNotHasKey('filter.language', CategoryMapper::listState('com_content', null, null, null, null));
	}

	public function testListStateLevelZeroIsOmitted(): void
	{
		$this->assertArrayNotHasKey('filter.level', CategoryMapper::listState('com_content', null, null, null, 0));
	}

	public function testListStateNegativeLevelIsOmitted(): void
	{
		$this->assertArrayNotHasKey('filter.level', CategoryMapper::listState('com_content', null, null, null, -1));
	}

	public function testListStatePositiveLevelIsSet(): void
	{
		$this->assertSame(2, CategoryMapper::listState('com_content', null, null, null, 2)['filter.level']);
	}

	public function testListStateAlwaysOrdersByLftAscending(): void
	{
		$result = CategoryMapper::listState('com_content', 'x', 1, 'en-GB', 3);

		$this->assertSame('a.lft', $result['list.ordering']);
		$this->assertSame('ASC', $result['list.direction']);
	}

	// -----------------------------------------------------------------------------------------------------
	// createData()
	// -----------------------------------------------------------------------------------------------------

	public function testCreateDataWithMinimalArguments(): void
	{
		$result = CategoryMapper::createData('com_content', 'My Category', null, 0, 1, null, '*', null, null, null, null, null);

		$this->assertSame('com_content', $result['extension']);
		$this->assertSame('My Category', $result['title']);
		$this->assertSame(1, $result['parent_id'], 'parent_id 0 must become 1 (the ROOT category).');
		$this->assertSame(1, $result['published']);
		$this->assertSame(1, $result['access'], 'access must default to 1 when null.');
		$this->assertSame('*', $result['language']);
		$this->assertSame([], $result['tags'], 'tags must default to an empty list when null.');
		$this->assertSame('', $result['alias']);
		$this->assertArrayNotHasKey('description', $result);
		$this->assertArrayNotHasKey('metadesc', $result);
		$this->assertArrayNotHasKey('metakey', $result);
		$this->assertArrayNotHasKey('note', $result);
	}

	public function testCreateDataNullParentIdAlsoBecomesRoot(): void
	{
		$result = CategoryMapper::createData('com_content', 'My Category', null, null, 1, null, '*', null, null, null, null, null);

		$this->assertSame(1, $result['parent_id']);
	}

	public function testCreateDataPositiveParentIdIsKept(): void
	{
		$result = CategoryMapper::createData('com_content', 'My Category', null, 42, 1, null, '*', null, null, null, null, null);

		$this->assertSame(42, $result['parent_id']);
	}

	public function testCreateDataEmptyOrNullAliasIsSentAsEmptyString(): void
	{
		// See createData()'s docblock comment: Joomla 4.4's Table\Category::check() calls
		// trim($this->alias) with no null-guard, so an omitted alias must be sent as '' rather than left
		// out of the payload entirely, to avoid a PHP deprecation notice corrupting the response.
		$this->assertSame('', CategoryMapper::createData('com_content', 'My Category', '', 0, 1, null, '*', null, null, null, null, null)['alias']);
		$this->assertSame('', CategoryMapper::createData('com_content', 'My Category', null, 0, 1, null, '*', null, null, null, null, null)['alias']);
	}

	public function testCreateDataNonEmptyAliasIsIncluded(): void
	{
		$result = CategoryMapper::createData('com_content', 'My Category', 'my-category', 0, 1, null, '*', null, null, null, null, null);

		$this->assertSame('my-category', $result['alias']);
	}

	public function testCreateDataExplicitAccessOverridesDefault(): void
	{
		$result = CategoryMapper::createData('com_content', 'My Category', null, 0, 1, 5, '*', null, null, null, null, null);

		$this->assertSame(5, $result['access']);
	}

	public function testCreateDataTagsAreNormalised(): void
	{
		$result = CategoryMapper::createData('com_content', 'My Category', null, 0, 1, null, '*', null, ['3', '4', '4'], null, null, null);

		$this->assertSame([3, 4], $result['tags']);
	}

	public function testCreateDataOptionalTextFieldsAreIncludedWhenGiven(): void
	{
		$result = CategoryMapper::createData(
			'com_content',
			'My Category',
			null,
			0,
			1,
			null,
			'*',
			'A description',
			null,
			'A meta description',
			'key1,key2',
			'A note'
		);

		$this->assertSame('A description', $result['description']);
		$this->assertSame('A meta description', $result['metadesc']);
		$this->assertSame('key1,key2', $result['metakey']);
		$this->assertSame('A note', $result['note']);
	}

	// -----------------------------------------------------------------------------------------------------
	// updateData()
	// -----------------------------------------------------------------------------------------------------

	private function currentItem(array $overrides = []): array
	{
		return array_replace(['id' => 5, 'tags' => [1, 2]], $overrides);
	}

	public function testUpdateDataWithEverythingNullOmitsEveryNullableFieldButAlwaysSendsTags(): void
	{
		$result = CategoryMapper::updateData(null, null, null, null, null, null, null, null, null, null, null, $this->currentItem());

		$this->assertSame(['tags' => [1, 2]], $result);
	}

	public function testUpdateDataKeepsOnlyExplicitlyGivenValues(): void
	{
		$result = CategoryMapper::updateData('New title', null, null, null, null, null, null, null, null, null, null, $this->currentItem());

		$this->assertSame('New title', $result['title']);
		$this->assertArrayNotHasKey('alias', $result);
	}

	public function testUpdateDataParentIdZeroBecomesOne(): void
	{
		$result = CategoryMapper::updateData(null, null, 0, null, null, null, null, null, null, null, null, $this->currentItem());

		$this->assertSame(1, $result['parent_id']);
	}

	public function testUpdateDataPositiveParentIdIsKept(): void
	{
		$result = CategoryMapper::updateData(null, null, 9, null, null, null, null, null, null, null, null, $this->currentItem());

		$this->assertSame(9, $result['parent_id']);
	}

	public function testUpdateDataPublishedZeroIsKeptNotTreatedAsNull(): void
	{
		$result = CategoryMapper::updateData(null, null, null, 0, null, null, null, null, null, null, null, $this->currentItem());

		$this->assertSame(0, $result['published']);
	}

	public function testUpdateDataTagsFallsBackToCurrentWhenNull(): void
	{
		$result = CategoryMapper::updateData(null, null, null, null, null, null, null, null, null, null, null, $this->currentItem(['tags' => [7, 8]]));

		$this->assertSame([7, 8], $result['tags']);
	}

	public function testUpdateDataTagsFallsBackToCurrentTagsHelperShape(): void
	{
		$result = CategoryMapper::updateData(
			null, null, null, null, null, null, null, null, null, null, null,
			$this->currentItem(['tags' => ['tags' => '3,4', 'typeAlias' => 'com_content.category']])
		);

		$this->assertSame([3, 4], $result['tags']);
	}

	public function testUpdateDataExplicitEmptyTagsClearsThem(): void
	{
		$result = CategoryMapper::updateData(null, null, null, null, null, null, null, [], null, null, null, $this->currentItem(['tags' => [1, 2]]));

		$this->assertSame([], $result['tags']);
	}

	public function testUpdateDataExplicitTagsOverrideCurrent(): void
	{
		$result = CategoryMapper::updateData(null, null, null, null, null, null, null, [9], null, null, null, $this->currentItem(['tags' => [1, 2]]));

		$this->assertSame([9], $result['tags']);
	}

	public function testUpdateDataEveryFieldTogether(): void
	{
		$result = CategoryMapper::updateData(
			'Title',
			'alias',
			3,
			1,
			2,
			'en-GB',
			'Description',
			[5],
			'Meta desc',
			'k1,k2',
			'Note',
			$this->currentItem()
		);

		$this->assertSame(
			[
				'title'       => 'Title',
				'alias'       => 'alias',
				'parent_id'   => 3,
				'published'   => 1,
				'access'      => 2,
				'language'    => 'en-GB',
				'description' => 'Description',
				'metadesc'    => 'Meta desc',
				'metakey'     => 'k1,k2',
				'note'        => 'Note',
				'tags'        => [5],
			],
			$result
		);
	}

	public function testUpdateDataNeverIncludesExtension(): void
	{
		$result = CategoryMapper::updateData(
			'Title', null, null, null, null, null, null, null, null, null, null, $this->currentItem()
		);

		$this->assertArrayNotHasKey('extension', $result);
	}

	// -----------------------------------------------------------------------------------------------------
	// normaliseItem()
	// -----------------------------------------------------------------------------------------------------

	public function testNormaliseItemConvertsTagsHelperShapedArray(): void
	{
		$result = CategoryMapper::normaliseItem(['id' => 1, 'tags' => ['tags' => '3,4', 'typeAlias' => 'com_content.category']]);

		$this->assertSame([3, 4], $result['tags']);
	}

	public function testNormaliseItemConvertsPlainIdList(): void
	{
		$result = CategoryMapper::normaliseItem(['id' => 1, 'tags' => [3, 4]]);

		$this->assertSame([3, 4], $result['tags']);
	}

	public function testNormaliseItemLeavesOtherFieldsUntouched(): void
	{
		$result = CategoryMapper::normaliseItem(['id' => 1, 'title' => 'Foo', 'tags' => []]);

		$this->assertSame(1, $result['id']);
		$this->assertSame('Foo', $result['title']);
	}

	public function testNormaliseItemToleratesMissingTags(): void
	{
		$result = CategoryMapper::normaliseItem(['id' => 1, 'title' => 'Foo']);

		$this->assertArrayNotHasKey('tags', $result);
	}

	// -----------------------------------------------------------------------------------------------------
	// Field whitelists — hard-coded so a future edit is deliberate.
	// -----------------------------------------------------------------------------------------------------

	public function testItemFieldsMatchesPlan(): void
	{
		$this->assertSame(
			[
				'id', 'title', 'alias', 'note', 'published', 'access', 'checked_out', 'checked_out_time',
				'created_user_id', 'created_time', 'modified_user_id', 'modified_time', 'parent_id', 'level',
				'path', 'extension', 'lft', 'rgt', 'language', 'params', 'description', 'metadesc', 'metakey',
				'metadata', 'hits', 'version', 'tags',
			],
			CategoryMapper::ITEM_FIELDS
		);
	}

	public function testListFieldsMatchesPlan(): void
	{
		$this->assertSame(
			[
				'id', 'title', 'alias', 'note', 'published', 'access', 'checked_out', 'checked_out_time',
				'created_user_id', 'parent_id', 'level', 'path', 'extension', 'lft', 'rgt', 'language',
				'language_title', 'language_image', 'editor', 'access_level', 'author_name', 'count_trashed',
				'count_unpublished', 'count_published', 'count_archived', 'params', 'description',
			],
			CategoryMapper::LIST_FIELDS
		);
	}

	public function testItemFieldsExcludesSensitiveOrInternalKeys(): void
	{
		$excluded = ['password', 'otpKey', 'otep', 'activation', 'asset_id', 'rules', 'associations'];

		foreach ($excluded as $key)
		{
			$this->assertNotContains($key, CategoryMapper::ITEM_FIELDS);
			$this->assertNotContains($key, CategoryMapper::LIST_FIELDS);
		}
	}

	public function testIntFieldsAndJsonFields(): void
	{
		$this->assertSame(
			[
				'published', 'access', 'checked_out', 'created_user_id', 'modified_user_id', 'parent_id',
				'level', 'lft', 'rgt', 'hits', 'version', 'count_trashed', 'count_unpublished',
				'count_published', 'count_archived',
			],
			CategoryMapper::INT_FIELDS
		);

		$this->assertSame(['params', 'metadata'], CategoryMapper::JSON_FIELDS);
	}
}
