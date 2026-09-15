<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Integration\Tests\Users;

use Akeeba\Mcp4j\Tests\Integration\AbstractToolTestCase;

/**
 * End-to-end coverage for the five `users_*` tools
 * ({@see \Akeeba\Component\Mcp4j\Administrator\Tool\Users\Users}) — the most security-sensitive category in
 * this package. See `.plans/05-users.md`'s Tests section for the coverage this class satisfies.
 */
class UsersTest extends AbstractToolTestCase
{
	/**
	 * Satisfies com_users' default password rules (minimum length 12, mixed case, digit, symbol).
	 */
	private const PASSWORD = 'Mcp4j-Test-Passw0rd!2026';

	public function testListShapeAndSearchFilter(): void
	{
		$username = $this->uniqueAlias('mcp4juser');

		$created = $this->callToolOk('users_create', [
			'name'     => 'MCP4j Search User',
			'username' => $username,
			'email'    => $username . '@example.com',
			'password' => self::PASSWORD,
			'groups'   => [2],
		]);
		$this->registerCleanup(fn () => $this->callTool('users_delete', ['id' => $created['id']]));

		$all = $this->callToolOk('users_list');
		$this->assertListShape($all);

		$filtered = $this->callToolOk('users_list', ['filterSearch' => $username]);
		$this->assertListShape($filtered);

		$usernames = array_column($filtered['items'], 'username');
		$this->assertSame([$username], $usernames);
	}

	public function testListFilterGroupIncludesCreatedUserExcludesAdmin(): void
	{
		$username = $this->uniqueAlias('mcp4jgroup');

		$created = $this->callToolOk('users_create', [
			'name'     => 'MCP4j Group User',
			'username' => $username,
			'email'    => $username . '@example.com',
			'password' => self::PASSWORD,
			'groups'   => [2],
		]);
		$this->registerCleanup(fn () => $this->callTool('users_delete', ['id' => $created['id']]));

		$admin = $this->findAdmin();

		$result = $this->callToolOk('users_list', ['filterGroup' => 2, 'pageLimit' => 100]);
		$ids    = array_column($result['items'], 'id');

		$this->assertContains($created['id'], $ids);
		$this->assertNotContains($admin['id'], $ids);
	}

	public function testListFilterStateFindsBlockedNotUnblocked(): void
	{
		$token          = bin2hex(random_bytes(4));
		$blockedUser    = $this->uniqueAlias('mcp4jblocked' . $token);
		$unblockedUser  = $this->uniqueAlias('mcp4junblocked' . $token);

		$blocked = $this->callToolOk('users_create', [
			'name'     => 'MCP4j Blocked ' . $token,
			'username' => $blockedUser,
			'email'    => $blockedUser . '@example.com',
			'password' => self::PASSWORD,
			'groups'   => [2],
			'block'    => 1,
		]);
		$this->registerCleanup(fn () => $this->callTool('users_delete', ['id' => $blocked['id']]));

		$unblocked = $this->callToolOk('users_create', [
			'name'     => 'MCP4j Unblocked ' . $token,
			'username' => $unblockedUser,
			'email'    => $unblockedUser . '@example.com',
			'password' => self::PASSWORD,
			'groups'   => [2],
			'block'    => 0,
		]);
		$this->registerCleanup(fn () => $this->callTool('users_delete', ['id' => $unblocked['id']]));

		$result = $this->callToolOk('users_list', ['filterSearch' => $token, 'filterState' => 1]);
		$ids    = array_column($result['items'], 'id');

		$this->assertContains($blocked['id'], $ids);
		$this->assertNotContains($unblocked['id'], $ids);
	}

	public function testFullLifecycle(): void
	{
		$username = $this->uniqueAlias('mcp4jlife');
		$email    = $username . '@example.com';

		$createResult = $this->callTool('users_create', [
			'name'         => 'MCP4j Lifecycle',
			'username'     => $username,
			'email'        => $email,
			'password'     => self::PASSWORD,
			'groups'       => [2],
			'requireReset' => false,
		]);
		$this->assertFalse($createResult['isError'], 'users_create unexpectedly failed: ' . $createResult['text']);

		$created = $createResult['data'];
		$id      = $created['id'];
		$this->registerCleanup(fn () => $this->callTool('users_delete', ['id' => $id]));

		$this->assertKeysPresent($created, ['id', 'name', 'username', 'email', 'block', 'groups']);
		$this->assertSame([2], $created['groups']);
		$this->assertSame(0, $created['block']);
		$this->assertStringNotContainsString(self::PASSWORD, $createResult['text']);

		$read = $this->callToolOk('users_read', ['id' => $id]);
		$this->assertSame($username, $read['username']);
		$this->assertSame($email, $read['email']);
		$this->assertSame([2], $read['groups']);

		// Partial update: only "name" changes — "email"/"groups" must be unaffected.
		$updated = $this->callToolOk('users_update', ['id' => $id, 'name' => 'MCP4j Lifecycle Renamed']);
		$this->assertSame('MCP4j Lifecycle Renamed', $updated['name']);
		$this->assertSame($email, $updated['email'], 'A partial update must not touch fields it was not given.');
		$this->assertSame([2], $updated['groups'], 'A partial update must not touch groups it was not given.');

		// Change the password.
		$passwordUpdateResult = $this->callTool('users_update', ['id' => $id, 'password' => self::PASSWORD . 'New1!']);
		$this->assertFalse($passwordUpdateResult['isError'], 'Password update unexpectedly failed: ' . $passwordUpdateResult['text']);
		$this->assertStringNotContainsString(self::PASSWORD . 'New1!', $passwordUpdateResult['text']);

		// Replace groups.
		$groupsUpdated = $this->callToolOk('users_update', ['id' => $id, 'groups' => [2, 3]]);
		$this->assertSame([2, 3], $groupsUpdated['groups']);

		$readAfterGroups = $this->callToolOk('users_read', ['id' => $id]);
		$this->assertSame([2, 3], $readAfterGroups['groups']);

		// Block the user.
		$blocked = $this->callToolOk('users_update', ['id' => $id, 'block' => 1]);
		$this->assertSame(1, $blocked['block']);

		$readAfterBlock = $this->callToolOk('users_read', ['id' => $id]);
		$this->assertSame(1, $readAfterBlock['block']);

		// Delete.
		$deleted = $this->callToolOk('users_delete', ['id' => $id]);
		$this->assertSame(['deleted' => true, 'id' => $id], $deleted);

		$this->assertNotFound($this->callTool('users_read', ['id' => $id]));
	}

	public function testCreateWithoutGroupsUsesDefaultRegisteredGroup(): void
	{
		$username = $this->uniqueAlias('mcp4jdefaultgroup');

		$created = $this->callToolOk('users_create', [
			'name'     => 'MCP4j Default Group',
			'username' => $username,
			'email'    => $username . '@example.com',
			'password' => self::PASSWORD,
		]);
		$this->registerCleanup(fn () => $this->callTool('users_delete', ['id' => $created['id']]));

		$this->assertSame([2], $created['groups']);
	}

	public function testCreateWithWeakPasswordFailsValidationAndCreatesNothing(): void
	{
		$username = $this->uniqueAlias('mcp4jweakpw');

		$result = $this->callTool('users_create', [
			'name'     => 'MCP4j Weak Password',
			'username' => $username,
			'email'    => $username . '@example.com',
			'password' => 'abc',
			'groups'   => [2],
		]);

		$this->assertTrue($result['isError'], 'A weak password must be refused by validation.');

		$search = $this->callToolOk('users_list', ['filterSearch' => $username]);
		$this->assertSame([], $search['items'], 'Nothing must be created when password validation fails.');
	}

	public function testSensitiveKeysAreAbsentFromEveryResponse(): void
	{
		$username = $this->uniqueAlias('mcp4jsensitive');
		$sensitive = ['password', 'password_clear', 'otpKey', 'otep', 'activation', 'params', 'authProvider'];

		$createResult = $this->callTool('users_create', [
			'name'     => 'MCP4j Sensitive',
			'username' => $username,
			'email'    => $username . '@example.com',
			'password' => self::PASSWORD,
			'groups'   => [2],
		]);
		$this->assertFalse($createResult['isError']);
		$id = $createResult['data']['id'];
		$this->registerCleanup(fn () => $this->callTool('users_delete', ['id' => $id]));

		$this->assertKeysAbsent($createResult['data'], $sensitive, 'users_create');
		$this->assertStringNotContainsString(self::PASSWORD, $createResult['text']);

		$readResult = $this->callTool('users_read', ['id' => $id]);
		$this->assertKeysAbsent($readResult['data'], $sensitive, 'users_read');
		$this->assertStringNotContainsString(self::PASSWORD, $readResult['text']);

		$listResult = $this->callTool('users_list', ['filterSearch' => $username]);
		foreach ($listResult['data']['items'] as $item)
		{
			$this->assertKeysAbsent($item, $sensitive, 'users_list item');
		}
		$this->assertStringNotContainsString(self::PASSWORD, $listResult['text']);

		$updateResult = $this->callTool('users_update', ['id' => $id, 'name' => 'MCP4j Sensitive Updated']);
		$this->assertKeysAbsent($updateResult['data'], $sensitive, 'users_update');
		$this->assertStringNotContainsString(self::PASSWORD, $updateResult['text']);
	}

	public function testCannotDeleteOrBlockOwnAccount(): void
	{
		$admin = $this->findAdmin();

		$deleteResult = $this->callTool('users_delete', ['id' => $admin['id']]);
		$this->assertTrue($deleteResult['isError'], 'Self-delete must be refused.');

		$stillThere = $this->callToolOk('users_read', ['id' => $admin['id']]);
		$this->assertSame('admin', $stillThere['username']);

		$blockResult = $this->callTool('users_update', ['id' => $admin['id'], 'block' => 1]);
		$this->assertTrue($blockResult['isError'], 'Self-block must be refused.');

		$stillUnblocked = $this->callToolOk('users_read', ['id' => $admin['id']]);
		$this->assertSame(0, $stillUnblocked['block'], 'The token\'s own account must remain unblocked.');
	}

	public function testReadNonexistentIdIsNotFound(): void
	{
		$this->assertNotFound($this->callTool('users_read', ['id' => 999999999]));
	}

	public function testLowPrivilegeUserIsRefusedReadAndWrite(): void
	{
		$this->assertPermissionDenied($this->callToolAsLowPriv('users_list'));
		$this->assertPermissionDenied($this->callToolAsLowPriv('users_read', ['id' => 1]));

		$username    = $this->uniqueAlias('mcp4jlowpriv');
		$writeResult = $this->callToolAsLowPriv('users_create', [
			'name'     => 'MCP4j LowPriv',
			'username' => $username,
			'email'    => $username . '@example.com',
			'password' => self::PASSWORD,
			'groups'   => [2],
		]);
		$this->assertPermissionDenied($writeResult);

		$this->assertPermissionDenied($this->callToolAsLowPriv('users_delete', ['id' => 1]));

		// Prove nothing was created, as the super user.
		$search = $this->callToolOk('users_list', ['filterSearch' => $username]);
		$this->assertSame([], $search['items']);
	}

	/**
	 * @return  array<string, mixed>  The `admin` user's shaped record (via `users_read`).
	 */
	private function findAdmin(): array
	{
		$result = $this->callToolOk('users_list', ['filterSearch' => 'username:admin']);

		$this->assertNotSame([], $result['items'], 'Could not find the "admin" user via users_list.');

		return $result['items'][0];
	}
}
