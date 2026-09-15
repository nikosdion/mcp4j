<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

/**
 * Provisions (idempotently) the fixtures for security.md findings M4/M5's regression tests: a Joomla user
 * group that is a genuine Akeeba Ticket System (ATS) "manager" at the `com_ats` component root
 * (`core.manage` granted there) but is explicitly DENIED `core.manage`/`core.admin` at one specific ATS
 * category ("department") — the exact shape M4/M5 describe ("a manager explicitly denied a specific
 * department ... can still bulk-list that department's ticket titles ... via the four list tools, even
 * though reading any one of those records individually is correctly refused").
 *
 * Creates, in order:
 *
 * 1. Two `com_ats` categories (idempotent, by alias): "MCP4j Scoped Allowed" and "MCP4j Scoped Denied".
 *    Fixed aliases so `tests/integration/fixtures/ats-scoped.php` (run later, from inside a PHPUnit test's
 *    setUp()) resolves the SAME two rows rather than creating a second pair.
 * 2. A fresh Joomla user group, "MCP4j ATS Scoped Manager", parented directly under Public (id 1) — kept
 *    isolated from every stock group so this never changes any other group's behaviour.
 * 3. `core.manage` = Allow on the `com_ats` COMPONENT asset for that group (the "is an ATS manager"
 *    baseline every tool in `Tool/Tickets/*.php` requires via `AbstractAtsTool::requireAtsManager()`).
 * 4. `core.manage` = Deny AND `core.admin` = Deny on the DENIED category's own asset
 *    (`com_ats.category.<id>`) for that group — Joomla ACL inheritance rules mean this explicit Deny wins
 *    over the inherited component-level Allow from step 3 for that one category specifically, which is
 *    exactly what `Permissions::isManager($deniedCatId, $userId)` (and therefore
 *    `Permissions::getManagerCategories()`) checks.
 * 5. The scoped-manager user itself, mapped to that group.
 * 6. The same two login-gating grants `provision-lowpriv-user.php`/`provision-author-user.php` need (see
 *    either script's own docblock for the full rationale): `core.login.api` on the root asset, and the
 *    group added to `plugins/user/token`'s `allowedUserGroups`.
 *
 * Run inside the `web` container's Joomla root:
 *   php /app/tests/integration/docker/provision-ats-scoped-manager.php <username>
 *
 * Every grant here is added narrowly — alongside whatever groups/actions are already present, never
 * replacing them — so this never weakens any other group's existing grants.
 *
 * Idempotent: safe to run against a site that already has this user/group/categories/grants.
 */

// phpcs:disable PSR1.Files.SideEffects

const _JEXEC = 1;

define('JPATH_BASE', '/var/www/html');
require_once JPATH_BASE . '/includes/defines.php';
require_once JPATH_BASE . '/includes/framework.php';

use Joomla\CMS\Factory;
use Joomla\CMS\Table\Category;
use Joomla\CMS\Table\Usergroup;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\CMS\User\UserHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

function fail(string $message): never
{
	fwrite(STDERR, $message . "\n");
	exit(1);
}

const GROUP_TITLE       = 'MCP4j ATS Scoped Manager';
const ALLOWED_CAT_TITLE = 'MCP4j Scoped Allowed';
const ALLOWED_CAT_ALIAS = 'mcp4j-ats-scoped-allowed';
const DENIED_CAT_TITLE  = 'MCP4j Scoped Denied';
const DENIED_CAT_ALIAS  = 'mcp4j-ats-scoped-denied';

$username = $argv[1] ?? null;

if (!$username)
{
	fail('Usage: provision-ats-scoped-manager.php <username>');
}

$container = Factory::getContainer();

// A Console application, matching cli/joomla.php's own bootstrap — mirrors provision-author-user.php's/
// ats.php's own bootstrap exactly.
$container->alias('session', 'session.cli')
	->alias('JSession', 'session.cli')
	->alias(\Joomla\CMS\Session\Session::class, 'session.cli')
	->alias(\Joomla\Session\Session::class, 'session.cli')
	->alias(\Joomla\Session\SessionInterface::class, 'session.cli');

$app                   = $container->get(\Joomla\Console\Application::class);
Factory::$application  = $app;

$db = $container->get(DatabaseInterface::class);

if (!@is_dir(JPATH_ADMINISTRATOR . '/components/com_ats'))
{
	// Nothing to do — TicketsTest/PostsTest/etc. all self-skip cleanly when com_ats isn't installed, and
	// so will the M4/M5 regression tests built on top of this fixture.
	exit(0);
}

function findUserId(string $username): int
{
	$db    = Factory::getContainer()->get(DatabaseInterface::class);
	$query = $db->getQuery(true)
		->select($db->quoteName('id'))
		->from($db->quoteName('#__users'))
		->where($db->quoteName('username') . ' = :username')
		->bind(':username', $username, ParameterType::STRING);

	return (int) $db->setQuery($query)->loadResult();
}

$adminId = findUserId('admin');

if ($adminId <= 0)
{
	fail('Could not find the admin user.');
}

$adminUser = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById($adminId);
$app->loadIdentity($adminUser);

/**
 * Finds (by alias) or creates a `com_ats` category — same pattern as
 * tests/integration/fixtures/ats.php::findOrCreateCategory(), duplicated here (a separate, host-invoked
 * CLI process) rather than shared, matching this project's existing convention of small, self-contained
 * provisioning scripts.
 */
function findOrCreateAtsCategory(string $title, string $alias, int $adminId): int
{
	$db    = Factory::getContainer()->get(DatabaseInterface::class);
	$query = $db->getQuery(true)
		->select($db->quoteName('id'))
		->from($db->quoteName('#__categories'))
		->where($db->quoteName('extension') . ' = ' . $db->quote('com_ats'))
		->where($db->quoteName('alias') . ' = :alias')
		->bind(':alias', $alias, ParameterType::STRING);

	$existing = (int) $db->setQuery($query)->loadResult();

	if ($existing > 0)
	{
		return $existing;
	}

	$table = new Category($db);

	$data = [
		'id'              => 0,
		'parent_id'       => 1,
		'extension'       => 'com_ats',
		'title'           => $title,
		'alias'           => $alias,
		'description'     => '',
		'published'       => 1,
		'access'          => 1,
		'params'          => '{}',
		'metadata'        => '{}',
		'language'        => '*',
		'created_user_id' => $adminId,
	];

	if (!$table->bind($data) || !$table->check())
	{
		fail(sprintf('Could not prepare ATS category "%s": %s', $title, $table->getError()));
	}

	$table->setLocation(1, 'last-child');

	if (!$table->store())
	{
		fail(sprintf('Could not store ATS category "%s": %s', $title, $table->getError()));
	}

	return (int) $table->id;
}

$allowedCategoryId = findOrCreateAtsCategory(ALLOWED_CAT_TITLE, ALLOWED_CAT_ALIAS, $adminId);
$deniedCategoryId  = findOrCreateAtsCategory(DENIED_CAT_TITLE, DENIED_CAT_ALIAS, $adminId);

// ---------------------------------------------------------------------------------------------------
// The scoped-manager user group: a fresh group parented directly under Public (id 1), isolated from
// every stock group.
// ---------------------------------------------------------------------------------------------------
$query = $db->getQuery(true)
	->select($db->quoteName('id'))
	->from($db->quoteName('#__usergroups'))
	->where($db->quoteName('title') . ' = ' . $db->quote(GROUP_TITLE));

$groupId = (int) $db->setQuery($query)->loadResult();

if ($groupId <= 0)
{
	$groupTable = new Usergroup($db);

	if (!$groupTable->bind(['parent_id' => 1, 'title' => GROUP_TITLE]) || !$groupTable->check())
	{
		fail('Could not prepare the scoped-manager user group: ' . $groupTable->getError());
	}

	if (!$groupTable->store())
	{
		fail('Could not store the scoped-manager user group: ' . $groupTable->getError());
	}

	$groupId = (int) $groupTable->id;
}

/**
 * Merges one action => allow/deny grant into an asset's rules JSON for the given group, leaving every
 * other action/group untouched. Returns true if the rules actually changed (caller then persists it).
 */
function mergeRule(array &$rules, string $action, int $groupId, int $value): bool
{
	if (!isset($rules[$action]) || !is_array($rules[$action]))
	{
		$rules[$action] = [];
	}

	if (($rules[$action][$groupId] ?? null) === $value)
	{
		return false;
	}

	$rules[$action][$groupId] = $value;

	return true;
}

function loadAssetRules(string $assetName): array
{
	$db    = Factory::getContainer()->get(DatabaseInterface::class);
	$query = $db->getQuery(true)
		->select([$db->quoteName('id'), $db->quoteName('rules')])
		->from($db->quoteName('#__assets'))
		->where($db->quoteName('name') . ' = :name')
		->bind(':name', $assetName, ParameterType::STRING);

	$asset = $db->setQuery($query)->loadObject();

	if ($asset === null)
	{
		fail("Could not find the '{$assetName}' asset in #__assets.");
	}

	$rules = json_decode((string) $asset->rules, true);

	return [(int) $asset->id, is_array($rules) ? $rules : []];
}

function storeAssetRules(int $assetId, array $rules): void
{
	$db        = Factory::getContainer()->get(DatabaseInterface::class);
	$rulesJson = json_encode($rules);
	$query     = $db->getQuery(true)
		->update($db->quoteName('#__assets'))
		->set($db->quoteName('rules') . ' = :rules')
		->where($db->quoteName('id') . ' = :id')
		->bind(':rules', $rulesJson, ParameterType::STRING)
		->bind(':id', $assetId, ParameterType::INTEGER);

	$db->setQuery($query)->execute();
}

// ---------------------------------------------------------------------------------------------------
// core.manage = Allow on the com_ats COMPONENT asset — the "is an ATS manager" baseline.
// ---------------------------------------------------------------------------------------------------
[$comAtsAssetId, $comAtsRules] = loadAssetRules('com_ats');

if (mergeRule($comAtsRules, 'core.manage', $groupId, 1))
{
	storeAssetRules($comAtsAssetId, $comAtsRules);
}

// ---------------------------------------------------------------------------------------------------
// core.manage = Deny AND core.admin = Deny on the DENIED category's own asset — the explicit
// department-level denial M4/M5 are about. Joomla ACL inheritance: an explicit Deny here overrides the
// inherited Allow from the component asset above, for this one category only.
// ---------------------------------------------------------------------------------------------------
$deniedAssetName = 'com_ats.category.' . $deniedCategoryId;
[$deniedAssetId, $deniedRules] = loadAssetRules($deniedAssetName);

$deniedChanged = false;
$deniedChanged = mergeRule($deniedRules, 'core.manage', $groupId, 0) || $deniedChanged;
$deniedChanged = mergeRule($deniedRules, 'core.admin', $groupId, 0) || $deniedChanged;

if ($deniedChanged)
{
	storeAssetRules($deniedAssetId, $deniedRules);
}

// ---------------------------------------------------------------------------------------------------
// core.login.api = Allow on the root asset, so this group can authenticate against the Web Services API
// at all (see provision-lowpriv-user.php's docblock for the full rationale).
// ---------------------------------------------------------------------------------------------------
[$rootAssetId, $rootRules] = loadAssetRules('root.1');

if (mergeRule($rootRules, 'core.login.api', $groupId, 1))
{
	storeAssetRules($rootAssetId, $rootRules);
}

// ---------------------------------------------------------------------------------------------------
// The user itself, mapped to the scoped-manager group. Idempotent: reuse an existing row/mapping.
// ---------------------------------------------------------------------------------------------------
$userId = findUserId($username);

if ($userId <= 0)
{
	$user = (object) [
		'name'          => 'MCP4j ATS Scoped Manager',
		'username'      => $username,
		'email'         => $username . '@example.com',
		'password'      => UserHelper::hashPassword(bin2hex(random_bytes(16))),
		'block'         => 0,
		'sendEmail'     => 0,
		'registerDate'  => Factory::getDate()->toSql(),
		'lastvisitDate' => null,
		'activation'    => '',
		'params'        => '{}',
		'requireReset'  => 0,
	];

	// insertObject()'s second argument is by-reference: $user MUST be a variable, never an inline cast.
	$db->insertObject('#__users', $user, 'id');
	$userId = (int) $user->id;
}

$query = $db->getQuery(true)
	->select('COUNT(*)')
	->from($db->quoteName('#__user_usergroup_map'))
	->where($db->quoteName('user_id') . ' = :userId')
	->where($db->quoteName('group_id') . ' = :groupId')
	->bind(':userId', $userId, ParameterType::INTEGER)
	->bind(':groupId', $groupId, ParameterType::INTEGER);

$alreadyMapped = ((int) $db->setQuery($query)->loadResult()) > 0;

if (!$alreadyMapped)
{
	$map = (object) [
		'user_id'  => $userId,
		'group_id' => $groupId,
	];

	$db->insertObject('#__user_usergroup_map', $map);
}

// ---------------------------------------------------------------------------------------------------
// Allow the scoped-manager group in plugins/user/token's allowedUserGroups (what
// plugins/api-authentication/token actually checks), keeping every other already-allowed group intact.
// ---------------------------------------------------------------------------------------------------
$query = $db->getQuery(true)
	->select([$db->quoteName('extension_id'), $db->quoteName('params')])
	->from($db->quoteName('#__extensions'))
	->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
	->where($db->quoteName('folder') . ' = ' . $db->quote('user'))
	->where($db->quoteName('element') . ' = ' . $db->quote('token'));

$plugin = $db->setQuery($query)->loadObject();

if ($plugin === null)
{
	fail('Could not find the plg_user_token plugin.');
}

$params = json_decode((string) $plugin->params, true);
$params = is_array($params) ? $params : [];

$allowed = $params['allowedUserGroups'] ?? [8];
$allowed = is_array($allowed) ? $allowed : [$allowed];
$allowed = array_map('intval', $allowed);

if (!in_array($groupId, $allowed, true))
{
	$allowed[] = $groupId;
	sort($allowed);
	$allowed = array_values(array_unique($allowed));

	$params['allowedUserGroups'] = $allowed;

	$paramsJson  = json_encode($params);
	$extensionId = (int) $plugin->extension_id;

	$query = $db->getQuery(true)
		->update($db->quoteName('#__extensions'))
		->set($db->quoteName('params') . ' = :params')
		->where($db->quoteName('extension_id') . ' = :id')
		->bind(':params', $paramsJson, ParameterType::STRING)
		->bind(':id', $extensionId, ParameterType::INTEGER);

	$db->setQuery($query)->execute();
}

exit(0);
