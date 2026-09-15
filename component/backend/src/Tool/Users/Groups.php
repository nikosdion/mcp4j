<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Tool\Users;

defined('_JEXEC') || die;

use Akeeba\Component\Mcp4j\Administrator\Bridge\AbstractTool;
use Akeeba\Component\Mcp4j\Administrator\Mapper\Users\GroupMapper;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\McpTool;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\Schema;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Exception\ToolException;
use Joomla\CMS\Access\Access;

/**
 * MCP tools for Joomla! user group management.
 *
 * Ported from the original `joomla-mcp-php` project's `src/Server/Users/Groups.php` — method names, tool
 * names/descriptions, parameters and annotations are copied verbatim from there (see
 * `.plans/00-conventions.md` §2); only the method *bodies* differ, plus `*_delete`'s return type (the
 * framework-wide `['deleted' => true, 'id' => int]` shape rather than the original's `bool`) — see the
 * final report's deviations list.
 *
 * `com_users`' own backend controller (`GroupController::allowSave()`/`allowEdit()`) requires `core.admin`
 * on `com_users` for every write, not `core.create`/`core.edit` — every write method here mirrors that.
 * `GroupModel::delete()` silently refuses (and prunes) a group the current user belongs to, or a Super User
 * group edited/deleted by a non-Super-User; this class pre-checks both so the refusal is a clean
 * `ToolException` rather than a silently-no-op'd success. The root group (id 1, `parent_id` 0) is never
 * editable/deletable through this API — see `.plans/05-users.md`.
 *
 * @since  0.0.1
 */
class Groups extends AbstractTool
{
	private const OPTION = 'com_users';

	#[McpTool(
		name: 'users_groups_list',
		description: 'List existing user groups',
		readOnlyHint: true,
	)]
	public function listGroups(
		#[Schema(description: 'Search user groups by title')]
		?string $filterSearch = null,
		#[Schema(description: 'Maximum number of items to return per page', minimum: 1)]
		?int $pageLimit = null,
		#[Schema(description: 'Starting record offset for pagination (0-based)', minimum: 0)]
		?int $pageOffset = null
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);

		/** @var \Joomla\Component\Users\Administrator\Model\GroupsModel $model */
		$model = $this->createModel(self::OPTION, 'Groups');

		return $this->listItems(
			$model,
			GroupMapper::listState($filterSearch),
			$pageLimit,
			$pageOffset,
			GroupMapper::LIST_FIELDS,
			GroupMapper::INT_FIELDS
		);
	}

	#[McpTool(
		name: 'users_groups_read',
		description: 'Retrieve the information of the specified user group',
		readOnlyHint: true,
	)]
	public function readGroup(
		#[Schema(description: 'The ID of the user group to retrieve')]
		int $id
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);

		/** @var \Joomla\Component\Users\Administrator\Model\GroupModel $model */
		$model = $this->createModel(self::OPTION, 'Group');

		return $this->shape($this->loadItem($model, $id, 'user group'), GroupMapper::ITEM_FIELDS, GroupMapper::INT_FIELDS);
	}

	#[McpTool(
		name: 'users_groups_create',
		description: 'Create a new user group'
	)]
	public function createGroup(
		#[Schema(description: 'Title of the user group', minLength: 1, maxLength: 255)]
		string $title,
		#[Schema(description: 'The ID of the parent user group', minimum: 1)]
		?int $parentId = 1
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);
		$this->requireAuthorised('core.admin', self::OPTION);

		$effectiveParentId = ($parentId !== null && $parentId > 0) ? $parentId : 1;

		/** @var \Joomla\Component\Users\Administrator\Model\GroupModel $parentCheckModel */
		$parentCheckModel = $this->createModel(self::OPTION, 'Group');

		$this->loadItem($parentCheckModel, $effectiveParentId, 'user group');

		/** @var \Joomla\Component\Users\Administrator\Model\GroupModel $model */
		$model = $this->createModel(self::OPTION, 'Group');

		$data = GroupMapper::createData($title, $parentId);

		$newId = $this->saveItem($model, $data, null, 'user group');

		/** @var \Joomla\Component\Users\Administrator\Model\GroupModel $freshModel */
		$freshModel = $this->createModel(self::OPTION, 'Group');

		return $this->shape($this->loadItem($freshModel, $newId, 'user group'), GroupMapper::ITEM_FIELDS, GroupMapper::INT_FIELDS);
	}

	#[McpTool(
		name: 'users_groups_update',
		description: 'Update an existing user group',
		idempotentHint: true,
	)]
	public function updateGroup(
		#[Schema(description: 'The ID of the user group to modify')]
		int $id,
		#[Schema(description: 'Title of the user group', minLength: 1, maxLength: 255)]
		?string $title = null,
		#[Schema(description: 'The ID of the parent user group', minimum: 1)]
		?int $parentId = null
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);
		$this->requireAuthorised('core.admin', self::OPTION);

		/** @var \Joomla\Component\Users\Administrator\Model\GroupModel $model */
		$model = $this->createModel(self::OPTION, 'Group');

		$current = $this->loadItem($model, $id, 'user group');

		$this->requireNotRootGroup($current);

		if (Access::checkGroup($id, 'core.admin') === true && !$this->isAuthorised('core.admin', 'root.1'))
		{
			throw new ToolException('Permission denied: core.admin on root.1');
		}

		$data = GroupMapper::updateData($title, $parentId);

		$this->saveItem($model, $data, $id, 'user group');

		/** @var \Joomla\Component\Users\Administrator\Model\GroupModel $freshModel */
		$freshModel = $this->createModel(self::OPTION, 'Group');

		return $this->shape($this->loadItem($freshModel, $id, 'user group'), GroupMapper::ITEM_FIELDS, GroupMapper::INT_FIELDS);
	}

	#[McpTool(
		name: 'users_groups_delete',
		description: 'Permanently delete a user group',
		destructiveHint: true,
	)]
	public function deleteGroup(
		#[Schema(description: 'The ID of the user group to delete')]
		int $id
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);
		$this->requireAuthorised('core.admin', self::OPTION);

		/** @var \Joomla\Component\Users\Administrator\Model\GroupModel $model */
		$model = $this->createModel(self::OPTION, 'Group');

		$current = $this->loadItem($model, $id, 'user group');

		$this->requireNotRootGroup($current);

		if (in_array($id, Access::getGroupsByUser((int) $this->getUser()->id, false), true))
		{
			throw new ToolException('You cannot delete a user group you belong to.');
		}

		if (Access::checkGroup($id, 'core.admin') === true && !$this->isAuthorised('core.admin', 'root.1'))
		{
			throw new ToolException('Permission denied: core.admin on root.1');
		}

		/** @var \Joomla\Component\Users\Administrator\Model\GroupModel $deleteModel */
		$deleteModel = $this->createModel(self::OPTION, 'Group');

		$this->deleteItem($deleteModel, $id, 'user group');

		return ['deleted' => true, 'id' => $id];
	}

	/**
	 * @param   array<string, mixed>  $item  A normalised (not yet output-shaped) group record.
	 *
	 * @throws  ToolException  `$item` is the root user group (`parent_id` `0`, normally id `1`).
	 */
	private function requireNotRootGroup(array $item): void
	{
		if ((int) ($item['parent_id'] ?? -1) === 0)
		{
			throw new ToolException('The root user group cannot be modified.');
		}
	}
}
