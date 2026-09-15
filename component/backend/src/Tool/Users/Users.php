<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Tool\Users;

defined('_JEXEC') || die;

use Akeeba\Component\Mcp4j\Administrator\Bridge\AbstractTool;
use Akeeba\Component\Mcp4j\Administrator\Mapper\Users\UserMapper;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\McpTool;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\Schema;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Exception\ToolException;
use Joomla\CMS\Access\Access;
use Joomla\CMS\Component\ComponentHelper;

/**
 * MCP tools for Joomla! user management — the most security-sensitive category in this package.
 *
 * Ported from the original `joomla-mcp-php` project's `src/Server/Users/Users.php` — method names, tool
 * names/descriptions, parameters and annotations are copied verbatim from there (see
 * `.plans/00-conventions.md` §2); only the method *bodies* differ, plus the deviations documented in the
 * plan 05 final report (most notably: `filterState` filters `#__users`.`block`, not an "active" flag, and
 * `*_delete`'s return type is the framework-wide `['deleted' => true, 'id' => int]` shape rather than the
 * original's `bool`).
 *
 * Direct model access to `UserModel`/`UsersModel` returns *everything* the `#__users` table holds,
 * including the password hash, MFA secret, and OTEPs — {@see UserMapper::ITEM_FIELDS}/`LIST_FIELDS` are an
 * explicit whitelist, never a raw select-star passthrough (see `.plans/00-conventions.md` §5.9).
 *
 * `UserModel::save()`/`delete()` enforce most of Joomla's Super User protections themselves, but do so by
 * *silently pruning* the offending change (dropping a group from `$data['groups']`, or an id from `$pks`)
 * and still reporting success — exactly the kind of behaviour an LLM tool must never paper over. Every
 * write method here pre-checks with {@see self::assertMayActOnSuperUsers()} so a refusal is loud
 * (`ToolException`) rather than a quietly-no-op'd success.
 *
 * @since  0.0.1
 */
class Users extends AbstractTool
{
	private const OPTION = 'com_users';

	#[McpTool(
		name: 'users_list',
		description: 'List existing users',
		readOnlyHint: true,
	)]
	public function listUsers(
		#[Schema(description: 'Search users by name, username, or email')]
		?string $filterSearch = null,
		#[Schema(description: 'Filter by user state: 0=disabled, 1=enabled', enum: [0, 1])]
		?int $filterState = null,
		#[Schema(description: 'Filter by user group ID')]
		?int $filterGroup = null,
		#[Schema(description: 'Filter by registration date range')]
		?string $filterRange = null,
		#[Schema(description: 'Maximum number of items to return per page', minimum: 1)]
		?int $pageLimit = null,
		#[Schema(description: 'Starting record offset for pagination (0-based)', minimum: 0)]
		?int $pageOffset = null
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);

		/** @var \Joomla\Component\Users\Administrator\Model\UsersModel $model */
		$model = $this->createModel(self::OPTION, 'Users');

		try
		{
			$state = UserMapper::listState($filterSearch, $filterState, $filterGroup, $filterRange);
		}
		catch (\InvalidArgumentException $e)
		{
			throw new ToolException($e->getMessage(), 0, $e);
		}

		return $this->listItems(
			$model,
			$state,
			$pageLimit,
			$pageOffset,
			UserMapper::LIST_FIELDS,
			UserMapper::INT_FIELDS,
			UserMapper::JSON_FIELDS
		);
	}

	#[McpTool(
		name: 'users_read',
		description: 'Retrieve the information of the specified user',
		readOnlyHint: true,
	)]
	public function readUser(
		#[Schema(description: 'The ID of the user to retrieve')]
		int $id
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);

		/** @var \Joomla\Component\Users\Administrator\Model\UserModel $model */
		$model = $this->createModel(self::OPTION, 'User');

		return $this->shape(
			UserMapper::normaliseItem($this->loadItem($model, $id, 'user')),
			UserMapper::ITEM_FIELDS,
			UserMapper::INT_FIELDS,
			UserMapper::JSON_FIELDS
		);
	}

	#[McpTool(
		name: 'users_create',
		description: 'Create a new user'
	)]
	public function createUser(
		#[Schema(description: 'Full name of the user', minLength: 1, maxLength: 255)]
		string $name,
		#[Schema(description: 'Login username', minLength: 1, maxLength: 150)]
		string $username,
		#[Schema(description: 'Email address of the user', format: 'email')]
		string $email,
		#[Schema(description: 'Password for the user', minLength: 1)]
		string $password,
		#[Schema(description: 'Block status: 0=not blocked, 1=blocked', enum: [0, 1])]
		int $block = 0,
		#[Schema(description: 'Send an email notification to the user')]
		?bool $sendEmail = null,
		#[Schema(description: 'Require the user to reset their password on next login')]
		?bool $requireReset = null,
		#[Schema(description: 'An array of user group IDs to assign the user to', items: ['type' => 'integer'], minItems: 1)]
		?array $groups = null
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);
		$this->requireAuthorised('core.create', self::OPTION);

		$this->assertMayActOnSuperUsers(0, $groups ?? []);

		/** @var \Joomla\Component\Users\Administrator\Model\UserModel $model */
		$model = $this->createModel(self::OPTION, 'User');

		// Matches the backend UI's own default: the "New User Registration Group" component option, not a
		// hard failure, when no groups were given at all — see the final report's deviations list.
		$default = (int) ComponentHelper::getParams(self::OPTION)->get('new_usertype', 2);

		$data = UserMapper::createData($name, $username, $email, $password, $block, $sendEmail, $requireReset, $groups, $default);

		$newId = $this->saveItem($model, $data, null, 'user');

		/** @var \Joomla\Component\Users\Administrator\Model\UserModel $freshModel */
		$freshModel = $this->createModel(self::OPTION, 'User');

		return $this->shape(
			UserMapper::normaliseItem($this->loadItem($freshModel, $newId, 'user')),
			UserMapper::ITEM_FIELDS,
			UserMapper::INT_FIELDS,
			UserMapper::JSON_FIELDS
		);
	}

	#[McpTool(
		name: 'users_update',
		description: 'Update an existing user',
		idempotentHint: true,
	)]
	public function updateUser(
		#[Schema(description: 'The ID of the user to modify')]
		int $id,
		#[Schema(description: 'Full name of the user', minLength: 1, maxLength: 255)]
		?string $name = null,
		#[Schema(description: 'Login username', minLength: 1, maxLength: 150)]
		?string $username = null,
		#[Schema(description: 'Email address of the user', format: 'email')]
		?string $email = null,
		#[Schema(description: 'Password for the user', minLength: 1)]
		?string $password = null,
		#[Schema(description: 'Block status: 0=not blocked, 1=blocked', enum: [0, 1])]
		?int $block = null,
		#[Schema(description: 'Send an email notification to the user')]
		?bool $sendEmail = null,
		#[Schema(description: 'Require the user to reset their password on next login')]
		?bool $requireReset = null,
		#[Schema(description: 'An array of user group IDs to assign the user to', items: ['type' => 'integer'], minItems: 1)]
		?array $groups = null
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);

		/** @var \Joomla\Component\Users\Administrator\Model\UserModel $model */
		$model = $this->createModel(self::OPTION, 'User');

		$current = UserMapper::normaliseItem($this->loadItem($model, $id, 'user'));

		$isSelf = $id === (int) $this->getUser()->id;

		if (!$isSelf)
		{
			$this->requireAuthorised('core.edit', self::OPTION);
		}

		$this->assertMayActOnSuperUsers($id, $groups ?? []);

		if ($block !== null && !$isSelf)
		{
			$this->requireAuthorised('core.edit.state', self::OPTION);
		}

		$data = UserMapper::updateData($name, $username, $email, $password, $block, $sendEmail, $requireReset, $groups, $current);

		$this->saveItem($model, $data, $id, 'user');

		/** @var \Joomla\Component\Users\Administrator\Model\UserModel $freshModel */
		$freshModel = $this->createModel(self::OPTION, 'User');

		return $this->shape(
			UserMapper::normaliseItem($this->loadItem($freshModel, $id, 'user')),
			UserMapper::ITEM_FIELDS,
			UserMapper::INT_FIELDS,
			UserMapper::JSON_FIELDS
		);
	}

	#[McpTool(
		name: 'users_delete',
		description: 'Permanently delete a user',
		destructiveHint: true,
	)]
	public function deleteUser(
		#[Schema(description: 'The ID of the user to delete')]
		int $id
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);
		$this->requireAuthorised('core.delete', self::OPTION);

		/** @var \Joomla\Component\Users\Administrator\Model\UserModel $model */
		$model = $this->createModel(self::OPTION, 'User');

		$this->loadItem($model, $id, 'user');

		// UserModel::delete() itself refuses to delete the current user, but only via setError()+return
		// false, which the bridge would surface as a generic "Permission denied" — a dedicated message is
		// clearer for the calling LLM (and for the E2E test asserting on it).
		if ($id === (int) $this->getUser()->id)
		{
			throw new ToolException('You cannot delete your own user account.');
		}

		$this->assertMayActOnSuperUsers($id, []);

		/** @var \Joomla\Component\Users\Administrator\Model\UserModel $deleteModel */
		$deleteModel = $this->createModel(self::OPTION, 'User');

		$this->deleteItem($deleteModel, $id, 'user');

		return ['deleted' => true, 'id' => $id];
	}

	/**
	 * `UserModel::save()` (via `libraries/src/User/User.php::save()`) throws a bare `\RuntimeException('User
	 * not Super Administrator')` — not a `setError()`/`false` — when a non-Super-User edits a Super User's
	 * account, or assigns a group that itself grants `core.admin`. Left uncaught, that would surface as an
	 * opaque "Internal error" to the calling LLM; this pre-check turns it into a clean, expected
	 * `ToolException` before the model is ever touched.
	 *
	 * @param   int        $targetUserId  The user being created (`0`) or edited.
	 * @param   list<int>  $newGroupIds   The group ids the caller is trying to assign (empty when the call
	 *                                    doesn't touch groups, e.g. `users_delete`).
	 *
	 * @throws  ToolException
	 */
	private function assertMayActOnSuperUsers(int $targetUserId, array $newGroupIds): void
	{
		if ($targetUserId > 0
			&& Access::check($targetUserId, 'core.admin') === true
			&& !$this->isAuthorised('core.admin', 'root.1'))
		{
			throw new ToolException('Permission denied: core.admin on root.1');
		}

		if (UserMapper::grantsSuperUser($newGroupIds, static fn (int $g): bool => Access::checkGroup($g, 'core.admin') === true)
			&& !$this->isAuthorised('core.admin', 'root.1'))
		{
			throw new ToolException('Permission denied: core.admin on root.1');
		}
	}
}
