<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Mapper\Modules;

use Akeeba\Component\Mcp4j\Administrator\Mapper\Modules\ModuleMapper;
use PHPUnit\Framework\TestCase;

class ModuleMapperTest extends TestCase
{
	// -----------------------------------------------------------------------------------------------------
	// listState()
	// -----------------------------------------------------------------------------------------------------

	public function testListStateWithEverythingNullOnlySetsClientOrderingAndEmptyStateFilter(): void
	{
		$result = ModuleMapper::listState(0, null, null, null, null, null);

		$this->assertSame(
			[
				'client_id'      => 0,
				'list.ordering'  => 'a.position',
				'list.direction' => 'asc',
				'filter.state'   => '',
			],
			$result
		);
	}

	public function testListStateClientIdIsAlwaysAnInt(): void
	{
		$this->assertSame(1, ModuleMapper::listState(1, null, null, null, null, null)['client_id']);
	}

	public function testListStateSearchIsSetWhenGiven(): void
	{
		$this->assertSame('foo', ModuleMapper::listState(0, 'foo', null, null, null, null)['filter.search']);
	}

	public function testListStateSearchIsAbsentWhenNull(): void
	{
		$this->assertArrayNotHasKey('filter.search', ModuleMapper::listState(0, null, null, null, null, null));
	}

	public function testListStateFilterStateIsStringifiedWhenGiven(): void
	{
		$this->assertSame('0', ModuleMapper::listState(0, null, 0, null, null, null)['filter.state']);
		$this->assertSame('1', ModuleMapper::listState(0, null, 1, null, null, null)['filter.state']);
		$this->assertSame('-2', ModuleMapper::listState(0, null, -2, null, null, null)['filter.state']);
	}

	public function testListStateFilterStateDefaultsToEmptyStringWhenNull(): void
	{
		$this->assertSame('', ModuleMapper::listState(0, null, null, null, null, null)['filter.state']);
	}

	public function testListStatePositionIsSetWhenGiven(): void
	{
		$this->assertSame('sidebar-right', ModuleMapper::listState(0, null, null, 'sidebar-right', null, null)['filter.position']);
	}

	public function testListStatePositionIsAbsentWhenNull(): void
	{
		$this->assertArrayNotHasKey('filter.position', ModuleMapper::listState(0, null, null, null, null, null));
	}

	public function testListStateModuleIsSetWhenGiven(): void
	{
		$this->assertSame('mod_custom', ModuleMapper::listState(0, null, null, null, 'mod_custom', null)['filter.module']);
	}

	public function testListStateModuleIsAbsentWhenNull(): void
	{
		$this->assertArrayNotHasKey('filter.module', ModuleMapper::listState(0, null, null, null, null, null));
	}

	public function testListStateAccessIsSetWhenGiven(): void
	{
		$this->assertSame(3, ModuleMapper::listState(0, null, null, null, null, 3)['filter.access']);
	}

	public function testListStateAccessIsAbsentWhenNull(): void
	{
		$this->assertArrayNotHasKey('filter.access', ModuleMapper::listState(0, null, null, null, null, null));
	}

	public function testListStateAlwaysOrdersByPositionAscending(): void
	{
		$result = ModuleMapper::listState(1, 'x', 1, 'p', 'm', 2);

		$this->assertSame('a.position', $result['list.ordering']);
		$this->assertSame('asc', $result['list.direction']);
	}

	// -----------------------------------------------------------------------------------------------------
	// createData()
	// -----------------------------------------------------------------------------------------------------

	public function testCreateDataWithMinimalArguments(): void
	{
		$result = ModuleMapper::createData(0, 'My Module', 'mod_custom', 1, null, null, null, null, '*', null, null);

		$this->assertSame(0, $result['client_id']);
		$this->assertSame('My Module', $result['title']);
		$this->assertSame('mod_custom', $result['module']);
		$this->assertSame(1, $result['published']);
		$this->assertSame(1, $result['access'], 'access must default to 1 when null.');
		$this->assertSame('', $result['position'], 'position must default to "" when null.');
		$this->assertSame('*', $result['language']);
		$this->assertSame(1, $result['showtitle'], 'showtitle must always default to 1.');
		$this->assertSame('0', $result['assignment'], 'assignment must default to "0" (all pages) when null.');
		$this->assertSame([], $result['params'], 'params has no DB column default and must always be present, even empty.');
		$this->assertSame('', $result['content'], 'content must always be present, even empty (see the mapper\'s docblock).');
		$this->assertArrayNotHasKey('note', $result);
		$this->assertArrayNotHasKey('ordering', $result);
	}

	public function testCreateDataExplicitAccessOverridesDefault(): void
	{
		$result = ModuleMapper::createData(0, 'My Module', 'mod_custom', 1, 5, null, null, null, '*', null, null);

		$this->assertSame(5, $result['access']);
	}

	public function testCreateDataExplicitPositionOverridesDefault(): void
	{
		$result = ModuleMapper::createData(0, 'My Module', 'mod_custom', 1, null, 'sidebar-right', null, null, '*', null, null);

		$this->assertSame('sidebar-right', $result['position']);
	}

	public function testCreateDataOptionalFieldsAreIncludedWhenGiven(): void
	{
		$result = ModuleMapper::createData(1, 'My Module', 'mod_custom', 1, null, null, 'A note', 5, '*', '1', ['a' => 'b']);

		$this->assertSame('A note', $result['note']);
		$this->assertSame(5, $result['ordering']);
		$this->assertSame('1', $result['assignment']);
		$this->assertSame(['a' => 'b'], $result['params']);
	}

	public function testCreateDataAssignmentIsTrimmed(): void
	{
		$result = ModuleMapper::createData(0, 'My Module', 'mod_custom', 1, null, null, null, null, '*', ' 1 ', null);

		$this->assertSame('1', $result['assignment']);
	}

	public function testCreateDataInvalidAssignmentThrows(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		ModuleMapper::createData(0, 'My Module', 'mod_custom', 1, null, null, null, null, '*', '2', null);
	}

	// -----------------------------------------------------------------------------------------------------
	// updateData()
	// -----------------------------------------------------------------------------------------------------

	public function testUpdateDataWithEverythingNullReturnsEmptyArray(): void
	{
		$result = ModuleMapper::updateData(null, null, null, null, null, null, null, null, null);

		$this->assertSame([], $result);
	}

	public function testUpdateDataKeepsOnlyExplicitlyGivenValues(): void
	{
		$result = ModuleMapper::updateData('New title', null, null, null, null, null, null, null, null);

		$this->assertSame(['title' => 'New title'], $result);
	}

	public function testUpdateDataPublishedZeroIsKeptNotTreatedAsNull(): void
	{
		$result = ModuleMapper::updateData(null, 0, null, null, null, null, null, null, null);

		$this->assertSame(['published' => 0], $result);
	}

	public function testUpdateDataEveryFieldTogether(): void
	{
		$result = ModuleMapper::updateData(
			'Title',
			1,
			2,
			'sidebar-left',
			'Note',
			9,
			'en-GB',
			'-1',
			['x' => 1]
		);

		$this->assertSame(
			[
				'title'      => 'Title',
				'published'  => 1,
				'access'     => 2,
				'position'   => 'sidebar-left',
				'note'       => 'Note',
				'ordering'   => 9,
				'language'   => 'en-GB',
				'assignment' => '-1',
				'params'     => ['x' => 1],
			],
			$result
		);
	}

	public function testUpdateDataInvalidAssignmentThrows(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		ModuleMapper::updateData(null, null, null, null, null, null, null, 'all', null);
	}

	// -----------------------------------------------------------------------------------------------------
	// normaliseAssignment()
	// -----------------------------------------------------------------------------------------------------

	public function testNormaliseAssignmentNullStaysNull(): void
	{
		$this->assertNull(ModuleMapper::normaliseAssignment(null));
	}

	public function testNormaliseAssignmentAcceptsEveryValidValue(): void
	{
		foreach (['0', '-', '1', '-1'] as $value)
		{
			$this->assertSame($value, ModuleMapper::normaliseAssignment($value));
		}
	}

	public function testNormaliseAssignmentTrimsWhitespace(): void
	{
		$this->assertSame('1', ModuleMapper::normaliseAssignment(' 1 '));
	}

	public function testNormaliseAssignmentRejectsInvalidValue(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		ModuleMapper::normaliseAssignment('2');
	}

	public function testNormaliseAssignmentRejectsWordValue(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		ModuleMapper::normaliseAssignment('all');
	}

	// -----------------------------------------------------------------------------------------------------
	// Field whitelists — hard-coded so a future edit is deliberate.
	// -----------------------------------------------------------------------------------------------------

	public function testItemFieldsMatchesPlan(): void
	{
		$this->assertSame(
			[
				'id', 'title', 'note', 'content', 'ordering', 'position', 'checked_out', 'checked_out_time',
				'publish_up', 'publish_down', 'published', 'module', 'access', 'showtitle', 'params', 'client_id',
				'language', 'assigned', 'assignment', 'associations',
			],
			ModuleMapper::ITEM_FIELDS
		);
	}

	public function testListFieldsMatchesPlan(): void
	{
		$this->assertSame(
			[
				'id', 'title', 'note', 'position', 'module', 'language', 'checked_out', 'checked_out_time',
				'published', 'enabled', 'access', 'ordering', 'publish_up', 'publish_down', 'language_title',
				'editor', 'access_level', 'pages', 'name',
			],
			ModuleMapper::LIST_FIELDS
		);
	}

	public function testItemFieldsExcludesXmlAndSensitiveKeys(): void
	{
		$excluded = ['xml', 'typeAlias', 'asset_id', 'password', 'otpKey', 'otep', 'activation'];

		foreach ($excluded as $key)
		{
			$this->assertNotContains($key, ModuleMapper::ITEM_FIELDS);
			$this->assertNotContains($key, ModuleMapper::LIST_FIELDS);
		}
	}

	public function testIntFieldsAndJsonFields(): void
	{
		$this->assertSame(
			['ordering', 'checked_out', 'published', 'access', 'showtitle', 'client_id', 'enabled'],
			ModuleMapper::INT_FIELDS
		);

		$this->assertSame(['params'], ModuleMapper::JSON_FIELDS);
	}

	public function testAssignmentsConstant(): void
	{
		$this->assertSame(['0', '-', '1', '-1'], ModuleMapper::ASSIGNMENTS);
	}
}
