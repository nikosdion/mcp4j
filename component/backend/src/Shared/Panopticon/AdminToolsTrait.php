<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Shared\Panopticon;

defined('_JEXEC') || die;

use Akeeba\Component\Mcp4j\Administrator\Mcp\Exception\ToolException;
use Joomla\CMS\Access\Access;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;

/**
 * Helpers shared by {@see \Akeeba\Component\Mcp4j\Administrator\Tool\Panopticon\AdminTools} for driving
 * Admin Tools for Joomla's own `com_admintools` models without going through its dispatcher/controllers.
 * Owned by `.plans/15-panopticon-admintools.md`.
 *
 * Only usable on a class extending
 * {@see \Akeeba\Component\Mcp4j\Administrator\Shared\Panopticon\AbstractPanopticonTool} (for
 * `loadLanguage()`/`createModel()`/`getDatabase()`/`requireAuthorised()`, all `protected` on
 * {@see \Akeeba\Component\Mcp4j\Administrator\Bridge\AbstractTool}).
 *
 * @since  0.0.1
 */
trait AdminToolsTrait
{
	/**
	 * Memoises {@see self::bootAdminTools()} for the lifetime of the tool instance (a fresh instance is
	 * created per {@see \Akeeba\Component\Mcp4j\Administrator\Mcp\ToolDispatcher::callTool()} call, so this
	 * never needs to survive across tool calls).
	 */
	private bool $adminToolsBooted = false;

	/**
	 * Port of Admin Tools' own `Dispatcher::loadVersion()`. Several of its models (most notably
	 * `ScansModel::startScan()`) reference `ADMINTOOLS_VERSION`/`ADMINTOOLS_DATE`/`ADMINTOOLS_PRO` as bare
	 * constants and fatal with "Undefined constant" if nothing has defined them — normally Admin Tools'
	 * own dispatcher does this on every one of its own requests, but our request never runs it.
	 */
	private function bootAdminTools(): void
	{
		if ($this->adminToolsBooted)
		{
			return;
		}

		$this->adminToolsBooted = true;

		$versionFile = JPATH_ADMINISTRATOR . '/components/com_admintools/version.php';

		if (@file_exists($versionFile) && is_file($versionFile))
		{
			include_once $versionFile;
		}

		if (!defined('ADMINTOOLS_VERSION'))
		{
			define('ADMINTOOLS_VERSION', 'dev');
		}

		if (!defined('ADMINTOOLS_DATE'))
		{
			define('ADMINTOOLS_DATE', gmdate('Y-m-d'));
		}

		if (!defined('ADMINTOOLS_PRO'))
		{
			$isPro = @file_exists(JPATH_ADMINISTRATOR . '/components/com_admintools/src/Controller/ScansController.php');

			define('ADMINTOOLS_PRO', $isPro ? '1' : '0');
		}

		$this->loadLanguage('com_admintools');
	}

	/**
	 * Several Admin Tools models (`UnblockipModel`, `ScansModel`, `ScanModel`, `ScanalertsModel`,
	 * `ScanalertModel`) only ship in Admin Tools Professional — the Core build's `build.xml` excludes
	 * those files outright, so on Core the class simply doesn't exist.
	 *
	 * @throws  ToolException  The model's class doesn't exist — Admin Tools Core is installed, not Pro.
	 */
	private function requireAdminToolsPro(string $modelClassShortName): void
	{
		$this->bootAdminTools();

		$class = 'Akeeba\\Component\\AdminTools\\Administrator\\Model\\' . $modelClassShortName . 'Model';

		if (!class_exists($class))
		{
			throw new ToolException('This feature requires Admin Tools Professional.');
		}
	}

	private function createAdminToolsModel(string $name): BaseDatabaseModel
	{
		$this->bootAdminTools();

		return $this->createModel('com_admintools', $name);
	}

	/**
	 * Port of `AdmintoolsController::getSuperUserGroups()`: every `#__usergroups` id that carries
	 * `core.admin` on the root asset.
	 *
	 * @return  list<int>
	 */
	private function superUserGroupIds(): array
	{
		$db    = $this->getDatabase();
		$query = $db->getQuery(true)
			->select($db->quoteName('id'))
			->from($db->quoteName('#__usergroups'));

		// Access::checkGroup() is tri-state (true/false/null — null meaning "implicit deny"), never
		// declared to return strictly bool, so the callback below must not declare a strict `: bool`
		// return type of its own — cast explicitly instead of letting PHP's return-type coercion reject a
		// genuine null.
		$ids = (array) $db->setQuery($query)->loadColumn();
		$ids = array_filter($ids, static fn ($id) => (bool) Access::checkGroup((int) $id, 'core.admin'));

		return array_values(array_map('intval', $ids));
	}

	private function requireCanManageAdminTools(): void
	{
		$this->requireAuthorised('core.manage', 'com_admintools');
	}

	/**
	 * `TempsuperuserModel::save()` (via `TempsuperuserModel::createNewUser()`) ultimately calls the classic
	 * `Joomla\CMS\User\User::save()`, which — unlike the models the rest of this framework drives — never
	 * consults `CurrentUserInterface`/{@see \Akeeba\Component\Mcp4j\Administrator\Bridge\AbstractTool::createModel()}'s
	 * `setCurrentUser()` fix-up at all. It resolves "am I a Super Admin creating another Super Admin"
	 * exclusively via the classic `Factory::getUser()`, which — same root cause as
	 * `AbstractTool::beginLegacyCurrentUserWorkaround()`'s docblock explains for `publish()`/`delete()` —
	 * reads the throwaway `api` application's session `user` key, never populated by our bearer-token
	 * identity, and resolves to a guest user. A guest is never `core.admin`, so creating a Super User this
	 * way always fatals with `RuntimeException('User not Super Administrator')` unless this is fixed up
	 * first.
	 *
	 * `AbstractTool::beginLegacyCurrentUserWorkaround()`/`endLegacyCurrentUserWorkaround()` already solve
	 * exactly this, but are `private` on that class (plan 03's file — out of bounds for this plan per
	 * `.plans/00-conventions.md` §8), so this is a narrowly-scoped, independent implementation of the same
	 * fix, used only around the single `TempsuperuserModel::save()` call that needs it. Deliberately
	 * temporary: the previous (or absent) session `user` value is always restored immediately afterwards,
	 * in a `finally` block, so a `User` object (`password` property included) is never left sitting in the
	 * session for longer than that single call — `SessionManager::persist()` snapshots the whole session
	 * into `#__mcp4j_sessions` at the end of the request.
	 */
	private function withLegacySessionUser(callable $callback): mixed
	{
		$session = $this->getApplication()->getSession();

		$hadPrevious = $session->has('user');
		$previous    = $hadPrevious ? $session->get('user') : null;

		$session->set('user', $this->getUser());

		try
		{
			return $callback();
		}
		finally
		{
			if ($hadPrevious)
			{
				$session->set('user', $previous);
			}
			else
			{
				$session->remove('user');
			}
		}
	}
}
