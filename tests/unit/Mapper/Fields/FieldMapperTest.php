<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Mapper\Fields;

use Akeeba\Component\Mcp4j\Administrator\Mapper\Fields\FieldMapper;
use PHPUnit\Framework\TestCase;

class FieldMapperTest extends TestCase
{
	// -----------------------------------------------------------------------------------------------------
	// listState()
	// -----------------------------------------------------------------------------------------------------

	public function testListStateAlwaysSetsContextAndOrdering(): void
	{
		$result = FieldMapper::listState('com_content.article', null, null);

		$this->assertSame('com_content.article', $result['filter.context']);
		$this->assertSame('a.ordering', $result['list.ordering']);
		$this->assertSame('ASC', $result['list.direction']);
		$this->assertSame('', $result['filter.state']);
	}

	public function testListStateSearchIsSetWhenGiven(): void
	{
		$result = FieldMapper::listState('com_content.article', 'foo', null);

		$this->assertSame('foo', $result['filter.search']);
	}

	public function testListStateSearchIsAbsentWhenNull(): void
	{
		$result = FieldMapper::listState('com_content.article', null, null);

		$this->assertArrayNotHasKey('filter.search', $result);
	}

	public function testListStateStateIsStringifiedWhenGiven(): void
	{
		$this->assertSame('0', FieldMapper::listState('com_content.article', null, 0)['filter.state']);
		$this->assertSame('1', FieldMapper::listState('com_content.article', null, 1)['filter.state']);
	}

	// -----------------------------------------------------------------------------------------------------
	// createData()
	// -----------------------------------------------------------------------------------------------------

	public function testCreateDataWithMinimalArguments(): void
	{
		$result = FieldMapper::createData('com_content.article', 'My Field', 'text', null, null, null, 1, null, null, '*', null, null, null);

		$this->assertSame('com_content.article', $result['context']);
		$this->assertSame('My Field', $result['title']);
		$this->assertSame('text', $result['type']);
		$this->assertSame(1, $result['state']);
		$this->assertSame(1, $result['access'], 'access must default to 1 when null.');
		$this->assertSame(0, $result['group_id'], 'group_id must default to 0 when null.');
		$this->assertSame('*', $result['language']);
		$this->assertArrayNotHasKey('name', $result);
		$this->assertArrayNotHasKey('label', $result);
		$this->assertSame('', $result['description'], 'description has no DB column default and must always be present.');
		$this->assertSame('', $result['default_value'], 'default_value must be an empty string, not null, or Form::filter() drops the key entirely.');
		$this->assertSame(['hint' => ''], $result['params'], 'params must always survive Form::filter() via its "hint" subfield, even with no caller-supplied params.');
		$this->assertArrayNotHasKey('fieldparams', $result);
	}

	public function testCreateDataExplicitAccessAndGroupIdOverrideDefaults(): void
	{
		$result = FieldMapper::createData('com_content.article', 'My Field', 'text', null, null, null, 1, 5, 7, '*', null, null, null);

		$this->assertSame(5, $result['access']);
		$this->assertSame(7, $result['group_id']);
	}

	public function testCreateDataOptionalTextFieldsAreIncludedWhenGiven(): void
	{
		$result = FieldMapper::createData(
			'com_content.article',
			'My Field',
			'text',
			'my_field',
			'My Label',
			'A description',
			1,
			null,
			null,
			'*',
			'default text',
			null,
			null
		);

		$this->assertSame('my_field', $result['name']);
		$this->assertSame('My Label', $result['label']);
		$this->assertSame('A description', $result['description']);
		$this->assertSame('default text', $result['default_value']);
	}

	public function testCreateDataAcceptsParamsAsArray(): void
	{
		$result = FieldMapper::createData('com_content.article', 'My Field', 'text', null, null, null, 1, null, null, '*', null, ['hint' => 'x'], null);

		$this->assertSame(['hint' => 'x'], $result['params']);
	}

	public function testCreateDataDecodesParamsFromJsonString(): void
	{
		$result = FieldMapper::createData('com_content.article', 'My Field', 'text', null, null, null, 1, null, null, '*', null, '{"hint":"x"}', '{"y":2}');

		$this->assertSame(['hint' => 'x'], $result['params']);
		$this->assertSame(['y' => 2], $result['fieldparams']);
	}

	public function testCreateDataAddsTheHintSentinelWhenParamsOmitsIt(): void
	{
		// A caller-supplied params array that doesn't set "hint" must still end up with it present (as
		// ''), so the whole `params` group survives Form::filter() — see createData()'s own docblock.
		$result = FieldMapper::createData('com_content.article', 'My Field', 'text', null, null, null, 1, null, null, '*', null, ['class' => 'my-class'], null);

		$this->assertSame(['class' => 'my-class', 'hint' => ''], $result['params']);
	}

	public function testCreateDataThrowsOnInvalidJsonParams(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		FieldMapper::createData('com_content.article', 'My Field', 'text', null, null, null, 1, null, null, '*', null, '{not json', null);
	}

	public function testCreateDataThrowsOnInvalidJsonFieldparams(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		FieldMapper::createData('com_content.article', 'My Field', 'text', null, null, null, 1, null, null, '*', null, null, '{not json');
	}

	// -----------------------------------------------------------------------------------------------------
	// updateData()
	// -----------------------------------------------------------------------------------------------------

	public function testUpdateDataWithEverythingNullReturnsEmptyArray(): void
	{
		$result = FieldMapper::updateData(null, null, null, null, null, null, null, null, null, null, null, null);

		$this->assertSame([], $result);
	}

	public function testUpdateDataNeverContainsContext(): void
	{
		$result = FieldMapper::updateData('New title', null, null, null, null, null, null, null, null, null, null, null);

		$this->assertArrayNotHasKey('context', $result);
	}

	public function testUpdateDataKeepsOnlyExplicitlyGivenValues(): void
	{
		$result = FieldMapper::updateData('New title', null, null, null, null, null, null, null, null, null, null, null);

		$this->assertSame(['title' => 'New title'], $result);
	}

	public function testUpdateDataStateZeroIsKeptNotTreatedAsNull(): void
	{
		$result = FieldMapper::updateData(null, null, null, null, null, 0, null, null, null, null, null, null);

		$this->assertSame(['state' => 0], $result);
	}

	public function testUpdateDataDecodesJsonStringParams(): void
	{
		$result = FieldMapper::updateData(null, null, null, null, null, null, null, null, null, null, '{"hint":"y"}', null);

		$this->assertSame(['params' => ['hint' => 'y']], $result);
	}

	public function testUpdateDataThrowsOnInvalidJson(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		FieldMapper::updateData(null, null, null, null, null, null, null, null, null, null, 'not json', null);
	}

	public function testUpdateDataEveryFieldTogether(): void
	{
		$result = FieldMapper::updateData(
			'Title',
			'textarea',
			'name',
			'Label',
			'Description',
			1,
			2,
			3,
			'en-GB',
			'default',
			['a' => 1],
			['b' => 2]
		);

		$this->assertSame(
			[
				'title'         => 'Title',
				'type'          => 'textarea',
				'name'          => 'name',
				'label'         => 'Label',
				'description'   => 'Description',
				'state'         => 1,
				'access'        => 2,
				'group_id'      => 3,
				'language'      => 'en-GB',
				'default_value' => 'default',
				'params'        => ['a' => 1],
				'fieldparams'   => ['b' => 2],
			],
			$result
		);
	}

	// -----------------------------------------------------------------------------------------------------
	// Field whitelists — hard-coded so a future edit is deliberate.
	// -----------------------------------------------------------------------------------------------------

	public function testItemFieldsMatchesCoreJsonapiViewMinusTypeAliasPlusOnlyUseInSubform(): void
	{
		$this->assertSame(
			[
				'id',
				'asset_id',
				'context',
				'group_id',
				'title',
				'name',
				'label',
				'default_value',
				'type',
				'note',
				'description',
				'state',
				'required',
				'only_use_in_subform',
				'checked_out',
				'checked_out_time',
				'ordering',
				'params',
				'fieldparams',
				'language',
				'created_time',
				'created_user_id',
				'modified_time',
				'modified_by',
				'access',
				'assigned_cat_ids',
			],
			FieldMapper::ITEM_FIELDS
		);
	}

	public function testItemFieldsExcludesSensitiveKeys(): void
	{
		$sensitive = ['password', 'otpKey', 'otep', 'activation', 'typeAlias'];

		foreach ($sensitive as $key)
		{
			$this->assertNotContains($key, FieldMapper::ITEM_FIELDS);
			$this->assertNotContains($key, FieldMapper::LIST_FIELDS);
		}
	}

	public function testIntAndJsonFields(): void
	{
		$this->assertSame(
			['asset_id', 'group_id', 'state', 'required', 'only_use_in_subform', 'checked_out', 'ordering', 'created_user_id', 'modified_by', 'access', 'group_access', 'group_state'],
			FieldMapper::INT_FIELDS
		);

		$this->assertSame(['params', 'fieldparams'], FieldMapper::JSON_FIELDS);
	}
}
