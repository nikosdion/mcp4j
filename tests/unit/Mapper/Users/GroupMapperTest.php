<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Mapper\Users;

use Akeeba\Component\Mcp4j\Administrator\Mapper\Users\GroupMapper;
use PHPUnit\Framework\TestCase;

class GroupMapperTest extends TestCase
{
	// -----------------------------------------------------------------------------------------------------
	// listState()
	// -----------------------------------------------------------------------------------------------------

	public function testListStateWithNullSearchOnlySetsOrdering(): void
	{
		$this->assertSame(
			['list.ordering' => 'a.lft', 'list.direction' => 'ASC'],
			GroupMapper::listState(null)
		);
	}

	public function testListStateSearchIsSetWhenNonEmpty(): void
	{
		$this->assertSame('foo', GroupMapper::listState('foo')['filter.search']);
	}

	public function testListStateSearchIsOmittedWhenEmptyString(): void
	{
		$this->assertArrayNotHasKey('filter.search', GroupMapper::listState(''));
	}

	// -----------------------------------------------------------------------------------------------------
	// createData()
	// -----------------------------------------------------------------------------------------------------

	public function testCreateDataKeepsPositiveParentId(): void
	{
		$this->assertSame(['title' => 'Editors', 'parent_id' => 2], GroupMapper::createData('Editors', 2));
	}

	public function testCreateDataNullOrNonPositiveParentIdBecomesRoot(): void
	{
		$this->assertSame(1, GroupMapper::createData('Editors', null)['parent_id']);
		$this->assertSame(1, GroupMapper::createData('Editors', 0)['parent_id']);
		$this->assertSame(1, GroupMapper::createData('Editors', -5)['parent_id']);
	}

	// -----------------------------------------------------------------------------------------------------
	// updateData()
	// -----------------------------------------------------------------------------------------------------

	public function testUpdateDataWithEverythingNullReturnsEmptyArray(): void
	{
		$this->assertSame([], GroupMapper::updateData(null, null));
	}

	public function testUpdateDataKeepsOnlyGivenValues(): void
	{
		$this->assertSame(['title' => 'New Title'], GroupMapper::updateData('New Title', null));
		$this->assertSame(['parent_id' => 5], GroupMapper::updateData(null, 5));
		$this->assertSame(['title' => 'X', 'parent_id' => 5], GroupMapper::updateData('X', 5));
	}

	// -----------------------------------------------------------------------------------------------------
	// Field whitelist
	// -----------------------------------------------------------------------------------------------------

	public function testItemFieldsAndListFieldsMatchSpec(): void
	{
		$this->assertSame(['id', 'parent_id', 'lft', 'rgt', 'title'], GroupMapper::ITEM_FIELDS);
		$this->assertSame(
			['id', 'parent_id', 'lft', 'rgt', 'title', 'count_enabled', 'count_disabled', 'user_count'],
			GroupMapper::LIST_FIELDS
		);
	}

	public function testSensitiveKeysAreAbsentFromOutputFields(): void
	{
		foreach (['password', 'otpKey', 'otep', 'activation', 'params'] as $key)
		{
			$this->assertNotContains($key, GroupMapper::ITEM_FIELDS);
			$this->assertNotContains($key, GroupMapper::LIST_FIELDS);
		}
	}
}
