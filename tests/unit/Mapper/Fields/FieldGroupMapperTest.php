<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Mapper\Fields;

use Akeeba\Component\Mcp4j\Administrator\Mapper\Fields\FieldGroupMapper;
use PHPUnit\Framework\TestCase;

class FieldGroupMapperTest extends TestCase
{
	// -----------------------------------------------------------------------------------------------------
	// listState()
	// -----------------------------------------------------------------------------------------------------

	public function testListStateAlwaysSetsContextAndOrdering(): void
	{
		$result = FieldGroupMapper::listState('com_content.article', null, null);

		$this->assertSame('com_content.article', $result['filter.context']);
		$this->assertSame('a.ordering', $result['list.ordering']);
		$this->assertSame('ASC', $result['list.direction']);
		$this->assertSame('', $result['filter.state']);
	}

	public function testListStateSearchIsSetWhenGiven(): void
	{
		$result = FieldGroupMapper::listState('com_content.article', 'foo', null);

		$this->assertSame('foo', $result['filter.search']);
	}

	public function testListStateStateIsStringifiedWhenGiven(): void
	{
		$this->assertSame('1', FieldGroupMapper::listState('com_content.article', null, 1)['filter.state']);
	}

	// -----------------------------------------------------------------------------------------------------
	// createData()
	// -----------------------------------------------------------------------------------------------------

	public function testCreateDataWithMinimalArguments(): void
	{
		$result = FieldGroupMapper::createData('com_content.article', 'My Group', null, 1, null, '*', null, null);

		$this->assertSame('com_content.article', $result['context']);
		$this->assertSame('My Group', $result['title']);
		$this->assertSame(1, $result['state']);
		$this->assertSame(1, $result['access'], 'access must default to 1 when null.');
		$this->assertSame('*', $result['language']);
		$this->assertSame('', $result['description'], 'description has no DB column default and must always be present.');
		$this->assertArrayNotHasKey('note', $result);
		$this->assertSame(['display_readonly' => 1], $result['params'], 'params must always survive Form::filter() via its "display_readonly" subfield, even with no caller-supplied params.');
	}

	public function testCreateDataExplicitAccessOverridesDefault(): void
	{
		$result = FieldGroupMapper::createData('com_content.article', 'My Group', null, 1, 5, '*', null, null);

		$this->assertSame(5, $result['access']);
	}

	public function testCreateDataOptionalFieldsAreIncludedWhenGiven(): void
	{
		$result = FieldGroupMapper::createData('com_content.article', 'My Group', 'A description', 1, null, '*', 'A note', ['x' => 1]);

		$this->assertSame('A description', $result['description']);
		$this->assertSame('A note', $result['note']);
		$this->assertSame(['x' => 1, 'display_readonly' => 1], $result['params']);
	}

	public function testCreateDataDecodesJsonStringParams(): void
	{
		$result = FieldGroupMapper::createData('com_content.article', 'My Group', null, 1, null, '*', null, '{"x":1}');

		$this->assertSame(['x' => 1, 'display_readonly' => 1], $result['params']);
	}

	public function testCreateDataKeepsCallerSuppliedDisplayReadonly(): void
	{
		$result = FieldGroupMapper::createData('com_content.article', 'My Group', null, 1, null, '*', null, ['display_readonly' => 0]);

		$this->assertSame(['display_readonly' => 0], $result['params']);
	}

	public function testCreateDataThrowsOnInvalidJson(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		FieldGroupMapper::createData('com_content.article', 'My Group', null, 1, null, '*', null, 'not json');
	}

	// -----------------------------------------------------------------------------------------------------
	// updateData()
	// -----------------------------------------------------------------------------------------------------

	public function testUpdateDataWithEverythingNullReturnsEmptyArray(): void
	{
		$result = FieldGroupMapper::updateData(null, null, null, null, null, null, null);

		$this->assertSame([], $result);
	}

	public function testUpdateDataNeverContainsContext(): void
	{
		$result = FieldGroupMapper::updateData('New title', null, null, null, null, null, null);

		$this->assertArrayNotHasKey('context', $result);
	}

	public function testUpdateDataStateZeroIsKeptNotTreatedAsNull(): void
	{
		$result = FieldGroupMapper::updateData(null, null, 0, null, null, null, null);

		$this->assertSame(['state' => 0], $result);
	}

	public function testUpdateDataEveryFieldTogether(): void
	{
		$result = FieldGroupMapper::updateData('Title', 'Description', 1, 2, 'en-GB', 'Note', ['a' => 1]);

		$this->assertSame(
			[
				'title'       => 'Title',
				'description' => 'Description',
				'state'       => 1,
				'access'      => 2,
				'language'    => 'en-GB',
				'note'        => 'Note',
				'params'      => ['a' => 1],
			],
			$result
		);
	}

	// -----------------------------------------------------------------------------------------------------
	// Field whitelists
	// -----------------------------------------------------------------------------------------------------

	public function testItemFieldsMatchesCoreJsonapiViewMinusTypeAliasAndType(): void
	{
		$this->assertSame(
			[
				'id',
				'asset_id',
				'context',
				'title',
				'note',
				'description',
				'state',
				'checked_out',
				'checked_out_time',
				'ordering',
				'params',
				'language',
				'created',
				'created_by',
				'modified',
				'modified_by',
				'access',
			],
			FieldGroupMapper::ITEM_FIELDS
		);
	}

	public function testListFieldsAreTheRealGroupsModelColumnsNotCoresCopyPastedFieldsList(): void
	{
		// Core's own JsonapiView::$fieldsToRenderList for groups is a copy-paste of the *fields* list and
		// contains columns #__fields_groups doesn't have at all (name, fieldparams, type, default_value,
		// group_id, label, required) — see FieldGroupMapper's docblock. None of those must appear here.
		$bogus = ['name', 'fieldparams', 'type', 'default_value', 'group_id', 'label', 'required'];

		foreach ($bogus as $key)
		{
			$this->assertNotContains($key, FieldGroupMapper::LIST_FIELDS);
		}

		$this->assertSame(
			[
				'id',
				'asset_id',
				'context',
				'title',
				'note',
				'description',
				'state',
				'access',
				'ordering',
				'language',
				'params',
				'created',
				'created_by',
				'checked_out',
				'checked_out_time',
				'language_title',
				'language_image',
				'editor',
				'access_level',
				'author_name',
			],
			FieldGroupMapper::LIST_FIELDS
		);
	}

	public function testIntAndJsonFields(): void
	{
		$this->assertSame(['asset_id', 'state', 'access', 'ordering', 'created_by', 'modified_by', 'checked_out'], FieldGroupMapper::INT_FIELDS);
		$this->assertSame(['params'], FieldGroupMapper::JSON_FIELDS);
	}
}
