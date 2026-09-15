<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Mapper\Tags;

use Akeeba\Component\Mcp4j\Administrator\Mapper\Tags\TagMapper;
use PHPUnit\Framework\TestCase;

class TagMapperTest extends TestCase
{
	// -----------------------------------------------------------------------------------------------------
	// listState()
	// -----------------------------------------------------------------------------------------------------

	public function testListStateWithEverythingNullOnlySetsOrderingAndEmptyPublishedFilter(): void
	{
		$result = TagMapper::listState(null, null, null);

		$this->assertSame(
			[
				'list.ordering'    => 'a.lft',
				'list.direction'   => 'ASC',
				'filter.extension' => '',
				'filter.published' => '',
			],
			$result
		);
	}

	public function testListStateAlwaysSetsFilterExtensionToEmptyString(): void
	{
		// Joomla 4.4's TagsModel::getItems() calls getState('filter.extension') with no default and
		// feeds the (otherwise-null) result into explode() — see listState()'s docblock.
		$this->assertSame('', TagMapper::listState('x', 1, 'en-GB')['filter.extension']);
	}

	public function testListStateSearchIsSetWhenGiven(): void
	{
		$result = TagMapper::listState('foo', null, null);

		$this->assertSame('foo', $result['filter.search']);
	}

	public function testListStateSearchIsAbsentWhenNull(): void
	{
		$result = TagMapper::listState(null, null, null);

		$this->assertArrayNotHasKey('filter.search', $result);
	}

	public function testListStatePublishedIsStringifiedWhenGiven(): void
	{
		$this->assertSame('0', TagMapper::listState(null, 0, null)['filter.published']);
		$this->assertSame('1', TagMapper::listState(null, 1, null)['filter.published']);
		$this->assertSame('-2', TagMapper::listState(null, -2, null)['filter.published']);
	}

	public function testListStateLanguageIsSetWhenGiven(): void
	{
		$result = TagMapper::listState(null, null, 'en-GB');

		$this->assertSame('en-GB', $result['filter.language']);
	}

	public function testListStateLanguageIsAbsentWhenNull(): void
	{
		$result = TagMapper::listState(null, null, null);

		$this->assertArrayNotHasKey('filter.language', $result);
	}

	public function testListStateAlwaysOrdersByLftAscending(): void
	{
		$result = TagMapper::listState('x', 1, 'en-GB');

		$this->assertSame('a.lft', $result['list.ordering']);
		$this->assertSame('ASC', $result['list.direction']);
	}

	// -----------------------------------------------------------------------------------------------------
	// createData()
	// -----------------------------------------------------------------------------------------------------

	public function testCreateDataWithMinimalArguments(): void
	{
		$result = TagMapper::createData('My Tag', null, 1, 0, null, '*', null, null, null, null);

		$this->assertSame('My Tag', $result['title']);
		$this->assertSame(1, $result['published']);
		$this->assertSame(1, $result['parent_id'], 'parent_id 0 must become 1 (the nested set root).');
		$this->assertSame(1, $result['access'], 'access must default to 1 when null.');
		$this->assertSame('*', $result['language']);
		$this->assertArrayNotHasKey('alias', $result, 'alias must be omitted when null.');
		$this->assertSame('', $result['description'], 'description has no DB column default and must always be present.');
		$this->assertArrayNotHasKey('metadesc', $result);
		$this->assertArrayNotHasKey('metakey', $result);
		$this->assertArrayNotHasKey('note', $result);
	}

	public function testCreateDataNullParentIdAlsoBecomesRoot(): void
	{
		$result = TagMapper::createData('My Tag', null, 1, null, null, '*', null, null, null, null);

		$this->assertSame(1, $result['parent_id']);
	}

	public function testCreateDataPositiveParentIdIsKept(): void
	{
		$result = TagMapper::createData('My Tag', null, 1, 42, null, '*', null, null, null, null);

		$this->assertSame(42, $result['parent_id']);
	}

	public function testCreateDataEmptyAliasIsOmitted(): void
	{
		$result = TagMapper::createData('My Tag', '', 1, 0, null, '*', null, null, null, null);

		$this->assertArrayNotHasKey('alias', $result);
	}

	public function testCreateDataNonEmptyAliasIsIncluded(): void
	{
		$result = TagMapper::createData('My Tag', 'my-tag', 1, 0, null, '*', null, null, null, null);

		$this->assertSame('my-tag', $result['alias']);
	}

	public function testCreateDataExplicitAccessOverridesDefault(): void
	{
		$result = TagMapper::createData('My Tag', null, 1, 0, 5, '*', null, null, null, null);

		$this->assertSame(5, $result['access']);
	}

	public function testCreateDataOptionalTextFieldsAreIncludedWhenGiven(): void
	{
		$result = TagMapper::createData(
			'My Tag',
			null,
			1,
			0,
			null,
			'*',
			'A description',
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

	public function testUpdateDataWithEverythingNullReturnsEmptyArray(): void
	{
		$result = TagMapper::updateData(null, null, null, null, null, null, null, null, null, null);

		$this->assertSame([], $result);
	}

	public function testUpdateDataKeepsOnlyExplicitlyGivenValues(): void
	{
		$result = TagMapper::updateData('New title', null, null, null, null, null, null, null, null, null);

		$this->assertSame(['title' => 'New title'], $result);
	}

	public function testUpdateDataParentIdZeroBecomesOne(): void
	{
		$result = TagMapper::updateData(null, null, null, 0, null, null, null, null, null, null);

		$this->assertSame(['parent_id' => 1], $result);
	}

	public function testUpdateDataPositiveParentIdIsKept(): void
	{
		$result = TagMapper::updateData(null, null, null, 9, null, null, null, null, null, null);

		$this->assertSame(['parent_id' => 9], $result);
	}

	public function testUpdateDataPublishedZeroIsKeptNotTreatedAsNull(): void
	{
		$result = TagMapper::updateData(null, null, 0, null, null, null, null, null, null, null);

		$this->assertSame(['published' => 0], $result);
	}

	public function testUpdateDataEveryFieldTogether(): void
	{
		$result = TagMapper::updateData(
			'Title',
			'alias',
			1,
			3,
			2,
			'en-GB',
			'Description',
			'Meta desc',
			'k1,k2',
			'Note'
		);

		$this->assertSame(
			[
				'title'       => 'Title',
				'alias'       => 'alias',
				'published'   => 1,
				'parent_id'   => 3,
				'access'      => 2,
				'language'    => 'en-GB',
				'description' => 'Description',
				'metadesc'    => 'Meta desc',
				'metakey'     => 'k1,k2',
				'note'        => 'Note',
			],
			$result
		);
	}

	// -----------------------------------------------------------------------------------------------------
	// Field whitelists — hard-coded so a future edit is deliberate.
	// -----------------------------------------------------------------------------------------------------

	public function testItemFieldsMatchesCoreJsonapiView(): void
	{
		$this->assertSame(
			[
				'id',
				'parent_id',
				'level',
				'lft',
				'rgt',
				'alias',
				'typeAlias',
				'path',
				'title',
				'note',
				'description',
				'published',
				'checked_out',
				'checked_out_time',
				'access',
				'params',
				'metadesc',
				'metakey',
				'metadata',
				'created_user_id',
				'created_time',
				'created_by_alias',
				'modified_user_id',
				'modified_time',
				'images',
				'urls',
				'hits',
				'language',
				'version',
				'publish_up',
				'publish_down',
			],
			TagMapper::ITEM_FIELDS
		);
	}

	public function testListFieldsMatchesCoreJsonapiView(): void
	{
		$this->assertSame(
			[
				'id',
				'title',
				'alias',
				'note',
				'published',
				'access',
				'description',
				'checked_out',
				'checked_out_time',
				'created_user_id',
				'path',
				'parent_id',
				'level',
				'lft',
				'rgt',
				'language',
				'language_title',
				'language_image',
				'editor',
				'author_name',
				'access_title',
			],
			TagMapper::LIST_FIELDS
		);
	}

	public function testItemFieldsExcludesSensitiveKeys(): void
	{
		$sensitive = ['password', 'otpKey', 'otep', 'activation', 'params_encrypted'];

		foreach ($sensitive as $key)
		{
			$this->assertNotContains($key, TagMapper::ITEM_FIELDS);
			$this->assertNotContains($key, TagMapper::LIST_FIELDS);
		}
	}

	public function testIntFieldsAndJsonFields(): void
	{
		$this->assertSame(
			['parent_id', 'level', 'lft', 'rgt', 'published', 'access', 'checked_out', 'created_user_id', 'modified_user_id', 'hits', 'version'],
			TagMapper::INT_FIELDS
		);

		$this->assertSame(['params', 'metadata', 'images', 'urls'], TagMapper::JSON_FIELDS);
	}
}
