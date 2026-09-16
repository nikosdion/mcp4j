<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Tool\Users;

defined('_JEXEC') || die;

use Akeeba\Component\Mcp4j\Administrator\Bridge\AbstractTool;
use Akeeba\Component\Mcp4j\Administrator\Mapper\Users\LevelMapper;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\McpTool;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\Schema;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Exception\ToolException;
use Joomla\CMS\Access\Access;
use Joomla\Database\ParameterType;

/**
 * MCP tools for Joomla! viewing access level management.
 *
 * Ported from the original `joomla-mcp-php` project's `src/Server/Users/Levels.php` — method names, tool
 * names/descriptions, parameters and annotations are copied verbatim from there (see
 * `.plans/00-conventions.md` §2); only the method *bodies* differ, plus `*_delete`'s return type (the
 * framework-wide `['deleted' => true, 'id' => int]` shape rather than the original's `bool`) — see the
 * final report's deviations list.
 *
 * `LevelModel::delete()` silently prunes (and still returns `true` for) an access level that is currently
 * in use by any `access`-column table on the site (`COM_USERS_ERROR_VIEW_LEVEL_IN_USE`) — this class does
 * NOT use {@see AbstractTool::deleteItem()} (which would report a misleading success) and instead inspects
 * `$pks` after the call itself, exactly as `.plans/05-users.md` specifies.
 *
 * Two Joomla-version-specific quirks are worked around locally (this file only — `Bridge/` is out of scope
 * for this plan, see `.plans/00-conventions.md` §8), both discovered by this plan's own E2E suite:
 *
 * 1. **Joomla 4.4**: `LevelModel::getItem()` does not guard against a nonexistent record the way 5.4/6.x
 *    do (`if ($result) { $result->rules = ...; }` — 4.4 dereferences unconditionally), so reading a
 *    nonexistent id there throws a PHP `Error` ("Attempt to assign property \"rules\" on bool") instead of
 *    cleanly returning `false`. {@see self::assertLevelExists()} is a version-independent existence check
 *    run before every `loadItem()` call on a caller-supplied id, sidestepping the bug entirely.
 * 2. **Joomla 4.4**: unlike `AbstractTool::deleteItem()`/`changeState()`, this class calls
 *    `LevelModel::delete()` directly (see above) — which on 4.4 falls through to the *default*
 *    `AdminModel::canDelete()` for a level that isn't SU-restricted or already known to be in use, and that
 *    default implementation calls the classic `Factory::getUser()->authorise(...)` (session-based, not our
 *    bearer-token identity) rather than `$this->getCurrentUser()` — the exact same pitfall
 *    `AbstractTool::beginLegacyCurrentUserWorkaround()`'s docblock documents for `publish()`/`delete()`,
 *    just reached via a different call path here. {@see self::deleteLevel()} replicates that same
 *    workaround locally (`getApplication()`/`getUser()` are protected on `AbstractTool`, so no Bridge
 *    change is needed) around the one `$model->delete($pks)` call that needs it.
 *
 * @since  0.0.1
 */
class Levels extends AbstractTool
{
	private const OPTION = 'com_users';

	#[McpTool(
		name: 'users_levels_list',
		description: 'List existing viewing access levels',
		readOnlyHint: true,
	)]
	public function listLevels(
		#[Schema(description: 'Search viewing access levels by title')]
		?string $filterSearch = null,
		#[Schema(description: 'Maximum number of items to return per page', minimum: 1)]
		?int $pageLimit = null,
		#[Schema(description: 'Starting record offset for pagination (0-based)', minimum: 0)]
		?int $pageOffset = null
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);

		/** @var \Joomla\Component\Users\Administrator\Model\LevelsModel $model */
		$model = $this->createModel(self::OPTION, 'Levels');

		$result = $this->listItems(
			$model,
			LevelMapper::listState($filterSearch),
			$pageLimit,
			$pageOffset,
			LevelMapper::LIST_FIELDS,
			LevelMapper::INT_FIELDS,
			LevelMapper::JSON_FIELDS
		);

		$result['items'] = array_map(
			static fn (array $item): array => LevelMapper::normaliseItem($item),
			$result['items']
		);

		return $result;
	}

	#[McpTool(
		name: 'users_levels_read',
		description: 'Retrieve the information of the specified viewing access level',
		readOnlyHint: true,
	)]
	public function readLevel(
		#[Schema(description: 'The ID of the viewing access level to retrieve')]
		int $id
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);

		$this->assertLevelExists($id);

		/** @var \Joomla\Component\Users\Administrator\Model\LevelModel $model */
		$model = $this->createModel(self::OPTION, 'Level');

		return $this->shape(
			LevelMapper::normaliseItem($this->loadItem($model, $id, 'viewing access level')),
			LevelMapper::ITEM_FIELDS,
			LevelMapper::INT_FIELDS,
			LevelMapper::JSON_FIELDS
		);
	}

	#[McpTool(
		name: 'users_levels_create',
		description: 'Create a new viewing access level'
	)]
	public function createLevel(
		#[Schema(description: 'Title of the viewing access level', minLength: 1, maxLength: 255)]
		string $title,
		#[Schema(description: 'An array of user group IDs that can access this level', items: ['type' => 'integer'], minItems: 1)]
		array $rules
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);
		$this->requireAuthorised('core.admin', self::OPTION);

		/** @var \Joomla\Component\Users\Administrator\Model\LevelModel $model */
		$model = $this->createModel(self::OPTION, 'Level');

		$data = LevelMapper::createData($title, $rules);

		$newId = $this->saveItem($model, $data, null, 'viewing access level');

		/** @var \Joomla\Component\Users\Administrator\Model\LevelModel $freshModel */
		$freshModel = $this->createModel(self::OPTION, 'Level');

		return $this->shape(
			LevelMapper::normaliseItem($this->loadItem($freshModel, $newId, 'viewing access level')),
			LevelMapper::ITEM_FIELDS,
			LevelMapper::INT_FIELDS,
			LevelMapper::JSON_FIELDS
		);
	}

	#[McpTool(
		name: 'users_levels_update',
		description: 'Update an existing viewing access level',
		idempotentHint: true,
	)]
	public function updateLevel(
		#[Schema(description: 'The ID of the viewing access level to modify')]
		int $id,
		#[Schema(description: 'Title of the viewing access level', minLength: 1, maxLength: 255)]
		?string $title = null,
		#[Schema(description: 'An array of user group IDs that can access this level', items: ['type' => 'integer'], minItems: 1)]
		?array $rules = null
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);
		$this->requireAuthorised('core.admin', self::OPTION);

		$this->assertLevelExists($id);

		/** @var \Joomla\Component\Users\Administrator\Model\LevelModel $model */
		$model = $this->createModel(self::OPTION, 'Level');

		$current = LevelMapper::normaliseItem($this->loadItem($model, $id, 'viewing access level'));

		// security.md L2: only the ADDED groups matter here (see LevelMapper::addedGroups()'s docblock)
		// — gating on "the current rules merely already include a Super-User group" blocked unrelated
		// edits (e.g. a title rename) to a level a legitimately-privileged com_users admin should be
		// able to touch.
		$addedGroups = LevelMapper::addedGroups($current['rules'] ?? [], $rules);

		$addsNewSuperUserGroup = false;

		foreach ($addedGroups as $groupId)
		{
			if (Access::checkGroup((int) $groupId, 'core.admin') === true)
			{
				$addsNewSuperUserGroup = true;

				break;
			}
		}

		if ($addsNewSuperUserGroup && !$this->isAuthorised('core.admin', 'root.1'))
		{
			throw new ToolException('Permission denied: core.admin on root.1');
		}

		$data = LevelMapper::updateData($title, $rules);

		$this->saveItem($model, $data, $id, 'viewing access level');

		/** @var \Joomla\Component\Users\Administrator\Model\LevelModel $freshModel */
		$freshModel = $this->createModel(self::OPTION, 'Level');

		return $this->shape(
			LevelMapper::normaliseItem($this->loadItem($freshModel, $id, 'viewing access level')),
			LevelMapper::ITEM_FIELDS,
			LevelMapper::INT_FIELDS,
			LevelMapper::JSON_FIELDS
		);
	}

	#[McpTool(
		name: 'users_levels_delete',
		description: 'Permanently delete a viewing access level',
		destructiveHint: true,
	)]
	public function deleteLevel(
		#[Schema(description: 'The ID of the viewing access level to delete')]
		int $id
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);
		$this->requireAuthorised('core.delete', self::OPTION);

		$this->assertLevelExists($id);

		/** @var \Joomla\Component\Users\Administrator\Model\LevelModel $model */
		$model = $this->createModel(self::OPTION, 'Level');

		$this->loadItem($model, $id, 'viewing access level');

		// Deliberately NOT AbstractTool::deleteItem(): LevelModel::delete() silently prunes an in-use level
		// from $pks and still returns true (see the class docblock) — $pks must be inspected here, not just
		// the boolean result, to tell "deleted" from "refused" apart.
		$pks = [$id];

		// See the class docblock, point 2: replicates AbstractTool::beginLegacyCurrentUserWorkaround()/
		// endLegacyCurrentUserWorkaround() locally, because this method deliberately doesn't go through
		// AbstractTool::deleteItem() (which already does this) and those two methods are private.
		$session         = $this->getApplication()->getSession();
		$hadPreviousUser = $session->has('user');
		$previousUser    = $hadPreviousUser ? $session->get('user') : null;
		$session->set('user', $this->getUser());

		try
		{
			$ok = $model->delete($pks);
		}
		finally
		{
			if ($hadPreviousUser)
			{
				$session->set('user', $previousUser);
			}
			else
			{
				$session->remove('user');
			}
		}

		if (!$ok)
		{
			throw new ToolException($this->modelError($model, sprintf('Cannot delete viewing access level #%d', $id)));
		}

		if ($pks === [])
		{
			throw new ToolException(sprintf(
				'Cannot delete viewing access level #%d: it is in use, or you are not allowed to delete it.',
				$id
			));
		}

		return ['deleted' => true, 'id' => $id];
	}

	/**
	 * Joomla 4.4's `LevelModel::getItem()` (unlike 5.4/6.x) does not guard against a nonexistent record
	 * before dereferencing it — see the class docblock, point 1. A lightweight existence check here, run
	 * before ever calling {@see AbstractTool::loadItem()} on a caller-supplied id, sidesteps the bug
	 * entirely and is a harmless no-op extra query on every supported version.
	 *
	 * @throws  ToolException  No such access level.
	 */
	private function assertLevelExists(int $id): void
	{
		if ($id > 0)
		{
			$db    = $this->getDatabase();
			$query = $db->createQuery()
				->select('1')
				->from($db->quoteName('#__viewlevels'))
				->where($db->quoteName('id') . ' = :id')
				->bind(':id', $id, ParameterType::INTEGER);

			if ((bool) $db->setQuery($query)->loadResult())
			{
				return;
			}
		}

		throw new ToolException(sprintf('Not found: viewing access level #%d', $id));
	}
}
