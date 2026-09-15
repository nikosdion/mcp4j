<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Mapper\Users;

use Akeeba\Component\Mcp4j\Administrator\Mapper\Users\UserMapper;
use PHPUnit\Framework\TestCase;

class UserMapperTest extends TestCase
{
	// -----------------------------------------------------------------------------------------------------
	// listState()
	// -----------------------------------------------------------------------------------------------------

	public function testListStateWithEverythingNullOnlySetsOrdering(): void
	{
		$this->assertSame(
			['list.ordering' => 'a.name', 'list.direction' => 'ASC'],
			UserMapper::listState(null, null, null, null)
		);
	}

	public function testListStateSearchIsSetWhenNonEmpty(): void
	{
		$this->assertSame('foo', UserMapper::listState('foo', null, null, null)['filter.search']);
	}

	public function testListStateSearchIsOmittedWhenNullOrEmpty(): void
	{
		$this->assertArrayNotHasKey('filter.search', UserMapper::listState(null, null, null, null));
		$this->assertArrayNotHasKey('filter.search', UserMapper::listState('', null, null, null));
	}

	public function testListStateStateIsSetWhenGivenIncludingZero(): void
	{
		$this->assertSame(0, UserMapper::listState(null, 0, null, null)['filter.state']);
		$this->assertSame(1, UserMapper::listState(null, 1, null, null)['filter.state']);
	}

	public function testListStateStateIsOmittedWhenNull(): void
	{
		$this->assertArrayNotHasKey('filter.state', UserMapper::listState(null, null, null, null));
	}

	public function testListStateGroupIsSetOnlyWhenPositive(): void
	{
		$this->assertSame(2, UserMapper::listState(null, null, 2, null)['filter.group_id']);
		$this->assertArrayNotHasKey('filter.group_id', UserMapper::listState(null, null, 0, null));
		$this->assertArrayNotHasKey('filter.group_id', UserMapper::listState(null, null, -1, null));
		$this->assertArrayNotHasKey('filter.group_id', UserMapper::listState(null, null, null, null));
	}

	public function testListStateRangeIsSetWhenAllowed(): void
	{
		foreach (['today', 'past_week', 'past_1month', 'past_3month', 'past_6month', 'past_year', 'post_year'] as $range)
		{
			$this->assertSame($range, UserMapper::listState(null, null, null, $range)['filter.range']);
		}
	}

	public function testListStateRangeIsOmittedWhenNull(): void
	{
		$this->assertArrayNotHasKey('filter.range', UserMapper::listState(null, null, null, null));
	}

	public function testListStateInvalidRangeThrows(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		UserMapper::listState(null, null, null, 'not-a-real-range');
	}

	public function testListStateAlwaysOrdersByNameAscending(): void
	{
		$result = UserMapper::listState('x', 1, 2, 'today');

		$this->assertSame('a.name', $result['list.ordering']);
		$this->assertSame('ASC', $result['list.direction']);
	}

	// -----------------------------------------------------------------------------------------------------
	// createData()
	// -----------------------------------------------------------------------------------------------------

	public function testCreateDataCopiesPasswordToPassword2(): void
	{
		$result = UserMapper::createData('Name', 'user', 'a@b.com', 'S3cret!', 0, null, null, null, 2);

		$this->assertSame('S3cret!', $result['password']);
		$this->assertSame('S3cret!', $result['password2']);
	}

	public function testCreateDataBooleansCoerceToIntWithDefaultZero(): void
	{
		$result = UserMapper::createData('Name', 'user', 'a@b.com', 'x', 0, null, null, null, 2);

		$this->assertSame(0, $result['sendEmail']);
		$this->assertSame(0, $result['requireReset']);

		$result = UserMapper::createData('Name', 'user', 'a@b.com', 'x', 0, true, false, null, 2);

		$this->assertSame(1, $result['sendEmail']);
		$this->assertSame(0, $result['requireReset']);
	}

	public function testCreateDataUsesDefaultGroupWhenGroupsNullOrEmpty(): void
	{
		$this->assertSame([2], UserMapper::createData('Name', 'user', 'a@b.com', 'x', 0, null, null, null, 2)['groups']);
		$this->assertSame([2], UserMapper::createData('Name', 'user', 'a@b.com', 'x', 0, null, null, [], 2)['groups']);
	}

	public function testCreateDataKeepsGivenGroupsAsIntList(): void
	{
		$result = UserMapper::createData('Name', 'user', 'a@b.com', 'x', 0, null, null, ['3', 5], 2);

		$this->assertSame([3, 5], $result['groups']);
	}

	public function testCreateDataKeepsBlockAndOtherScalars(): void
	{
		$result = UserMapper::createData('Jane Doe', 'jane', 'jane@example.com', 'x', 1, null, null, null, 2);

		$this->assertSame('Jane Doe', $result['name']);
		$this->assertSame('jane', $result['username']);
		$this->assertSame('jane@example.com', $result['email']);
		$this->assertSame(1, $result['block']);
	}

	// -----------------------------------------------------------------------------------------------------
	// updateData()
	// -----------------------------------------------------------------------------------------------------

	public function testUpdateDataPasswordFieldsAreAlwaysPresent(): void
	{
		$result = UserMapper::updateData(null, null, null, null, null, null, null, null, ['groups' => []]);

		$this->assertSame('', $result['password']);
		$this->assertSame('', $result['password2']);

		$result = UserMapper::updateData(null, null, null, 'newpass', null, null, null, null, ['groups' => []]);

		$this->assertSame('newpass', $result['password']);
		$this->assertSame('newpass', $result['password2']);
	}

	public function testUpdateDataOmitsOtherNullFields(): void
	{
		$result = UserMapper::updateData(null, null, null, null, null, null, null, null, ['groups' => []]);

		$this->assertArrayNotHasKey('name', $result);
		$this->assertArrayNotHasKey('username', $result);
		$this->assertArrayNotHasKey('email', $result);
		$this->assertArrayNotHasKey('block', $result);
		$this->assertArrayNotHasKey('sendEmail', $result);
		$this->assertArrayNotHasKey('requireReset', $result);
	}

	public function testUpdateDataKeepsGivenScalarValues(): void
	{
		$result = UserMapper::updateData('New Name', 'newuser', 'new@example.com', null, 1, true, false, null, ['groups' => []]);

		$this->assertSame('New Name', $result['name']);
		$this->assertSame('newuser', $result['username']);
		$this->assertSame('new@example.com', $result['email']);
		$this->assertSame(1, $result['block']);
		$this->assertSame(1, $result['sendEmail']);
		$this->assertSame(0, $result['requireReset']);
	}

	public function testUpdateDataGroupsKeepsCurrentGroupsWhenNull(): void
	{
		$result = UserMapper::updateData(null, null, null, null, null, null, null, null, ['groups' => [8, 2]]);

		$this->assertSame([8, 2], $result['groups']);
	}

	public function testUpdateDataGroupsReplacesWhenGiven(): void
	{
		$result = UserMapper::updateData(null, null, null, null, null, null, null, [2, 3], ['groups' => [8]]);

		$this->assertSame([2, 3], $result['groups']);
	}

	public function testUpdateDataGroupsDefaultsToEmptyListWhenCurrentHasNone(): void
	{
		$result = UserMapper::updateData(null, null, null, null, null, null, null, null, []);

		$this->assertSame([], $result['groups']);
	}

	// -----------------------------------------------------------------------------------------------------
	// normaliseItem()
	// -----------------------------------------------------------------------------------------------------

	public function testNormaliseItemSortsStringKeyedGroupsIntoIntList(): void
	{
		$result = UserMapper::normaliseItem(['id' => 1, 'groups' => ['8' => '8', '2' => '2']]);

		$this->assertSame([2, 8], $result['groups']);
	}

	public function testNormaliseItemNonArrayGroupsBecomesEmptyList(): void
	{
		$result = UserMapper::normaliseItem(['id' => 1, 'groups' => null]);

		$this->assertSame([], $result['groups']);
	}

	public function testNormaliseItemLeavesOtherKeysUntouched(): void
	{
		$result = UserMapper::normaliseItem(['id' => 1, 'name' => 'Jane', 'groups' => []]);

		$this->assertSame(1, $result['id']);
		$this->assertSame('Jane', $result['name']);
	}

	public function testNormaliseItemNoOpsWhenGroupsKeyIsAbsent(): void
	{
		$result = UserMapper::normaliseItem(['id' => 1]);

		$this->assertArrayNotHasKey('groups', $result);
	}

	// -----------------------------------------------------------------------------------------------------
	// grantsSuperUser()
	// -----------------------------------------------------------------------------------------------------

	public function testGrantsSuperUserTrueWhenAnyGroupChecksTrue(): void
	{
		$checker = static fn (int $g): bool => $g === 8;

		$this->assertTrue(UserMapper::grantsSuperUser([2, 8], $checker));
		$this->assertTrue(UserMapper::grantsSuperUser([8], $checker));
	}

	public function testGrantsSuperUserFalseWhenNoneMatch(): void
	{
		$checker = static fn (int $g): bool => $g === 8;

		$this->assertFalse(UserMapper::grantsSuperUser([2, 3], $checker));
		$this->assertFalse(UserMapper::grantsSuperUser([], $checker));
	}

	// -----------------------------------------------------------------------------------------------------
	// Field whitelist / sensitive output
	// -----------------------------------------------------------------------------------------------------

	public function testItemFieldsAndListFieldsMatchSpec(): void
	{
		$this->assertSame(
			['id', 'name', 'username', 'email', 'block', 'sendEmail', 'registerDate', 'lastvisitDate', 'lastResetTime', 'resetCount', 'requireReset', 'groups'],
			UserMapper::ITEM_FIELDS
		);

		$this->assertSame(
			['id', 'name', 'username', 'email', 'block', 'sendEmail', 'registerDate', 'lastvisitDate', 'lastResetTime', 'resetCount', 'requireReset', 'group_count', 'group_names'],
			UserMapper::LIST_FIELDS
		);
	}

	public function testSensitiveKeysNeverIntersectTheOutputWhitelists(): void
	{
		$this->assertSame([], array_intersect(UserMapper::SENSITIVE, UserMapper::ITEM_FIELDS));
		$this->assertSame([], array_intersect(UserMapper::SENSITIVE, UserMapper::LIST_FIELDS));
	}

	public function testSensitiveConstantContainsEveryKeyThePlanMandates(): void
	{
		foreach (['password', 'password_clear', 'otpKey', 'otep', 'activation', 'params', 'authProvider'] as $key)
		{
			$this->assertContains($key, UserMapper::SENSITIVE);
		}
	}
}
