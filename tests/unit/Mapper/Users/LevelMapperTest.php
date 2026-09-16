<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Mapper\Users;

use Akeeba\Component\Mcp4j\Administrator\Mapper\Users\LevelMapper;
use PHPUnit\Framework\TestCase;

class LevelMapperTest extends TestCase
{
	// -----------------------------------------------------------------------------------------------------
	// listState()
	// -----------------------------------------------------------------------------------------------------

	public function testListStateWithNullSearchOnlySetsOrdering(): void
	{
		$this->assertSame(
			['list.ordering' => 'a.ordering', 'list.direction' => 'ASC'],
			LevelMapper::listState(null)
		);
	}

	public function testListStateSearchIsSetWhenNonEmpty(): void
	{
		$this->assertSame('foo', LevelMapper::listState('foo')['filter.search']);
	}

	public function testListStateSearchIsOmittedWhenEmptyString(): void
	{
		$this->assertArrayNotHasKey('filter.search', LevelMapper::listState(''));
	}

	// -----------------------------------------------------------------------------------------------------
	// createData()
	// -----------------------------------------------------------------------------------------------------

	public function testCreateDataProducesUniqueSortedIntList(): void
	{
		$result = LevelMapper::createData('Special', [3, '3', 1, 2.0]);

		$this->assertSame('Special', $result['title']);
		$this->assertSame([1, 2, 3], $result['rules']);
	}

	// -----------------------------------------------------------------------------------------------------
	// updateData()
	// -----------------------------------------------------------------------------------------------------

	public function testUpdateDataWithEverythingNullReturnsEmptyArray(): void
	{
		$this->assertSame([], LevelMapper::updateData(null, null));
	}

	public function testUpdateDataTitleOnly(): void
	{
		$this->assertSame(['title' => 'New Title'], LevelMapper::updateData('New Title', null));
	}

	public function testUpdateDataRulesOnlyIsNormalised(): void
	{
		$this->assertSame(['rules' => [2, 3]], LevelMapper::updateData(null, [3, 2, 3]));
	}

	public function testUpdateDataBothTogether(): void
	{
		$this->assertSame(
			['title' => 'X', 'rules' => [1, 2]],
			LevelMapper::updateData('X', [2, 1])
		);
	}

	public function testUpdateDataEmptyRulesArrayIsKept(): void
	{
		// An explicit [] is "clear the rules", not "don't change" — the parameter itself being non-null is
		// what matters, not whether the resulting list happens to be empty.
		$this->assertSame(['rules' => []], LevelMapper::updateData(null, []));
	}

	// -----------------------------------------------------------------------------------------------------
	// normaliseItem()
	// -----------------------------------------------------------------------------------------------------

	public function testNormaliseItemDecodesJsonStringRules(): void
	{
		$result = LevelMapper::normaliseItem(['id' => 1, 'rules' => '[3,1,2]']);

		$this->assertSame([1, 2, 3], $result['rules']);
	}

	public function testNormaliseItemEmptyJsonStringBecomesEmptyList(): void
	{
		$result = LevelMapper::normaliseItem(['id' => 1, 'rules' => '']);

		$this->assertSame([], $result['rules']);
	}

	public function testNormaliseItemArrayOfStringsIsCoercedToInts(): void
	{
		$result = LevelMapper::normaliseItem(['id' => 1, 'rules' => ['3', '1', '3']]);

		$this->assertSame([1, 3], $result['rules']);
	}

	public function testNormaliseItemPlainIntArrayIsSortedAndDeduplicated(): void
	{
		$result = LevelMapper::normaliseItem(['id' => 1, 'rules' => [5, 2, 5]]);

		$this->assertSame([2, 5], $result['rules']);
	}

	public function testNormaliseItemNonArrayNonStringRulesBecomesEmptyList(): void
	{
		$result = LevelMapper::normaliseItem(['id' => 1, 'rules' => null]);

		$this->assertSame([], $result['rules']);
	}

	public function testNormaliseItemLeavesOtherKeysUntouched(): void
	{
		$result = LevelMapper::normaliseItem(['id' => 1, 'title' => 'Special', 'rules' => []]);

		$this->assertSame(1, $result['id']);
		$this->assertSame('Special', $result['title']);
	}

	public function testNormaliseItemNoOpsWhenRulesKeyIsAbsent(): void
	{
		$result = LevelMapper::normaliseItem(['id' => 1]);

		$this->assertArrayNotHasKey('rules', $result);
	}

	// -----------------------------------------------------------------------------------------------------
	// security.md L2: addedGroups()
	// -----------------------------------------------------------------------------------------------------

	public function testAddedGroupsIsEmptyWhenProposedRulesIsNull(): void
	{
		// $rules === null means the caller isn't touching `rules` at all (e.g. a title-only update) —
		// nothing is "added", regardless of what the current rules already contain.
		$this->assertSame([], LevelMapper::addedGroups([2, 8], null));
	}

	public function testAddedGroupsIsEmptyWhenProposedRulesIsUnchanged(): void
	{
		$this->assertSame([], LevelMapper::addedGroups([2, 8], [2, 8]));
	}

	public function testAddedGroupsIsEmptyWhenAGroupIsOnlyRemoved(): void
	{
		$this->assertSame([], LevelMapper::addedGroups([2, 8], [2]));
	}

	public function testAddedGroupsReturnsOnlyTheNewGroupIds(): void
	{
		$this->assertSame([9], LevelMapper::addedGroups([2, 8], [2, 8, 9]));
	}

	public function testAddedGroupsHandlesASimultaneousAddAndRemove(): void
	{
		// Removing 8 while adding 9: only 9 is "added" — matches Joomla's own LevelModel::validate(),
		// which force-preserves 8 regardless (see the method's docblock), so the removal side is moot.
		$this->assertSame([9], LevelMapper::addedGroups([2, 8], [2, 9]));
	}

	public function testAddedGroupsTreatsEveryRuleAsAddedWhenThereWereNoCurrentRules(): void
	{
		$this->assertSame([2], LevelMapper::addedGroups([], [2]));
	}

	// -----------------------------------------------------------------------------------------------------
	// Field whitelist
	// -----------------------------------------------------------------------------------------------------

	public function testItemFieldsAndListFieldsMatchSpec(): void
	{
		$this->assertSame(['id', 'title', 'ordering', 'rules'], LevelMapper::ITEM_FIELDS);
		$this->assertSame(['id', 'title', 'ordering', 'rules'], LevelMapper::LIST_FIELDS);
		$this->assertSame(['ordering'], LevelMapper::INT_FIELDS);
		$this->assertSame(['rules'], LevelMapper::JSON_FIELDS);
	}
}
