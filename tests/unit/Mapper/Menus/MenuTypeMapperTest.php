<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Mapper\Menus;

use Akeeba\Component\Mcp4j\Administrator\Mapper\Menus\MenuTypeMapper;
use PHPUnit\Framework\TestCase;

class MenuTypeMapperTest extends TestCase
{
	// -----------------------------------------------------------------------------------------------------
	// listState()
	// -----------------------------------------------------------------------------------------------------

	public function testListStateAlwaysSetsClientId(): void
	{
		$this->assertSame(0, MenuTypeMapper::listState(0, null)['client_id']);
		$this->assertSame(1, MenuTypeMapper::listState(1, null)['client_id']);
	}

	public function testListStateSearchIsAbsentWhenNull(): void
	{
		$result = MenuTypeMapper::listState(0, null);

		$this->assertArrayNotHasKey('filter.search', $result);
	}

	public function testListStateSearchIsSetWhenGiven(): void
	{
		$result = MenuTypeMapper::listState(0, 'foo');

		$this->assertSame('foo', $result['filter.search']);
	}

	public function testListStateAlwaysOrdersByIdAscending(): void
	{
		$result = MenuTypeMapper::listState(1, 'x');

		$this->assertSame('a.id', $result['list.ordering']);
		$this->assertSame('ASC', $result['list.direction']);
	}

	public function testListStateWithEverythingMinimal(): void
	{
		$this->assertSame(
			[
				'client_id'      => 0,
				'list.ordering'  => 'a.id',
				'list.direction' => 'ASC',
			],
			MenuTypeMapper::listState(0, null)
		);
	}

	// -----------------------------------------------------------------------------------------------------
	// createData()
	// -----------------------------------------------------------------------------------------------------

	public function testCreateDataWithMinimalArguments(): void
	{
		$result = MenuTypeMapper::createData(0, 'My Menu', 'my-menu', null);

		$this->assertSame(
			[
				'title'     => 'My Menu',
				'menutype'  => 'my-menu',
				'client_id' => 0,
			],
			$result
		);
	}

	public function testCreateDataIncludesDescriptionWhenGiven(): void
	{
		$result = MenuTypeMapper::createData(1, 'My Menu', 'my-menu', 'A description');

		$this->assertSame('A description', $result['description']);
	}

	public function testCreateDataClientIdIsCarriedThrough(): void
	{
		$this->assertSame(1, MenuTypeMapper::createData(1, 'T', 'm', null)['client_id']);
	}

	// -----------------------------------------------------------------------------------------------------
	// updateData()
	// -----------------------------------------------------------------------------------------------------

	public function testUpdateDataWithEverythingNullReturnsEmptyArray(): void
	{
		$this->assertSame([], MenuTypeMapper::updateData(null, null, null));
	}

	public function testUpdateDataKeepsOnlyExplicitlyGivenValues(): void
	{
		$this->assertSame(['title' => 'New title'], MenuTypeMapper::updateData('New title', null, null));
	}

	public function testUpdateDataEveryFieldTogether(): void
	{
		$this->assertSame(
			[
				'title'       => 'Title',
				'menutype'    => 'menutype',
				'description' => 'Description',
			],
			MenuTypeMapper::updateData('Title', 'menutype', 'Description')
		);
	}

	// -----------------------------------------------------------------------------------------------------
	// isReservedMenutype()
	// -----------------------------------------------------------------------------------------------------

	public function testIsReservedMenutypeMatchesExactly(): void
	{
		$this->assertTrue(MenuTypeMapper::isReservedMenutype('main'));
	}

	public function testIsReservedMenutypeIsCaseInsensitive(): void
	{
		$this->assertTrue(MenuTypeMapper::isReservedMenutype('MAIN'));
		$this->assertTrue(MenuTypeMapper::isReservedMenutype('Main'));
	}

	public function testIsReservedMenutypeTrimsWhitespace(): void
	{
		$this->assertTrue(MenuTypeMapper::isReservedMenutype('Main '));
		$this->assertTrue(MenuTypeMapper::isReservedMenutype(' main'));
	}

	public function testIsReservedMenutypeRejectsOtherValues(): void
	{
		$this->assertFalse(MenuTypeMapper::isReservedMenutype('mainmenu'));
		$this->assertFalse(MenuTypeMapper::isReservedMenutype('my-menu'));
		$this->assertFalse(MenuTypeMapper::isReservedMenutype(''));
	}

	// -----------------------------------------------------------------------------------------------------
	// Field whitelists — hard-coded so a future edit is deliberate.
	// -----------------------------------------------------------------------------------------------------

	public function testItemFieldsMatchesCoreJsonapiView(): void
	{
		$this->assertSame(['id', 'menutype', 'title', 'description', 'client_id'], MenuTypeMapper::ITEM_FIELDS);
	}

	public function testListFieldsMatchesCoreJsonapiViewPlusCounts(): void
	{
		$this->assertSame(
			['id', 'menutype', 'title', 'description', 'client_id', 'count_published', 'count_unpublished', 'count_trashed'],
			MenuTypeMapper::LIST_FIELDS
		);
	}

	public function testListFieldsExcludesInternalAssetId(): void
	{
		$this->assertNotContains('asset_id', MenuTypeMapper::LIST_FIELDS);
	}

	public function testIntFields(): void
	{
		$this->assertSame(['client_id', 'count_published', 'count_unpublished', 'count_trashed'], MenuTypeMapper::INT_FIELDS);
	}
}
