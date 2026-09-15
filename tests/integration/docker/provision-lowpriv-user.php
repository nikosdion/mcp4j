<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

/**
 * Provisions (idempotently) a low-privilege Joomla user for the E2E test harness, so every tool's ACL
 * enforcement can be proven against a user who genuinely lacks the rights the installer's Super User
 * has, rather than only ever being exercised by a Super User for whom every `requireAuthorised()` check
 * trivially passes.
 *
 * Run inside the `web` container's Joomla root:
 *   php /app/tests/integration/docker/provision-lowpriv-user.php <username>
 *
 * A brand-new "Registered" (group 2) user cannot authenticate against the Web Services API at all on a
 * stock Joomla install — two more things have to be true first, neither of which is granted to that
 * group out of the box:
 *
 * 1. {@see \Joomla\CMS\Application\ApiApplication::login()} (reached via `$app->login()` in
 *    `ServerController::getAuthenticatedUserId()`) is called with `'action' => 'core.login.api'` and
 *    refuses to authenticate anyone who lacks that action on the root asset. A fresh install only
 *    grants `core.login.api` to Super Users (group 8).
 * 2. `plugins/api-authentication/token`'s `onUserAuthenticate()` additionally requires the
 *    authenticating user to belong to one of the groups listed in **a different plugin's** parameter —
 *    `plugins/user/token`'s `allowedUserGroups` (default `[8]`, Super Users only) — see
 *    `Token::isInAllowedUserGroup()` / `Token::getAllowedUserGroups()` in
 *    `plugins/api-authentication/token/src/Extension/Token.php`, which reads it via
 *    `getPluginParameter('user', 'token', 'allowedUserGroups', [8])`. A user can have `core.login.api`
 *    and a perfectly valid token and still be refused here if their group isn't in that list.
 *
 * This script grants both to group 2, narrowly — it adds group 2 alongside whatever groups are already
 * present, it never removes an existing entry (so Super Users keep working exactly as before) — so the
 * resulting low-privilege user has exactly "can call the MCP endpoint as themselves, with a Registered
 * user's ordinary Joomla ACL and nothing more": the baseline every ACL-enforcement E2E test needs.
 *
 * Idempotent: safe to run against a site that already has this user/grants.
 */

// phpcs:disable PSR1.Files.SideEffects

const _JEXEC = 1;

define('JPATH_BASE', '/var/www/html');
require_once JPATH_BASE . '/includes/defines.php';
require_once JPATH_BASE . '/includes/framework.php';

use Joomla\CMS\Factory;
use Joomla\CMS\User\UserHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

function fail(string $message): never
{
	fwrite(STDERR, $message . "\n");
	exit(1);
}

$username = $argv[1] ?? null;

if (!$username)
{
	fail('Usage: provision-lowpriv-user.php <username>');
}

$container = Factory::getContainer();

// A Console application, matching cli/joomla.php's own bootstrap, purely so framework services that
// expect Factory::getApplication() to return something (e.g. Factory::getDate()) work. Mirrors
// provision-token.php's own bootstrap exactly.
$container->alias('session', 'session.cli')
	->alias('JSession', 'session.cli')
	->alias(\Joomla\CMS\Session\Session::class, 'session.cli')
	->alias(\Joomla\Session\Session::class, 'session.cli')
	->alias(\Joomla\Session\SessionInterface::class, 'session.cli');

$app                   = $container->get(\Joomla\Console\Application::class);
Factory::$application  = $app;

$db = $container->get(DatabaseInterface::class);

// ---------------------------------------------------------------------------------------------------
// 1. The user itself, mapped to group 2 (Registered). Idempotent: reuse an existing row/mapping.
// ---------------------------------------------------------------------------------------------------
$query = $db->getQuery(true)
	->select($db->quoteName('id'))
	->from($db->quoteName('#__users'))
	->where($db->quoteName('username') . ' = :username')
	->bind(':username', $username, ParameterType::STRING);

$userId = (int) $db->setQuery($query)->loadResult();

if ($userId <= 0)
{
	// #__users.lastvisitDate is nullable on 4.4+ — never write '0000-00-00' into it.
	$user = (object) [
		'name'          => 'MCP4j Low Privilege',
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
	->where($db->quoteName('group_id') . ' = 2')
	->bind(':userId', $userId, ParameterType::INTEGER);

$alreadyMapped = ((int) $db->setQuery($query)->loadResult()) > 0;

if (!$alreadyMapped)
{
	$map = (object) [
		'user_id'  => $userId,
		'group_id' => 2,
	];

	$db->insertObject('#__user_usergroup_map', $map);
}

// ---------------------------------------------------------------------------------------------------
// 2. Grant core.login.api to group 2 on the root asset (name 'root.1'), keeping every other group's
//    existing grants (e.g. group 8) untouched.
// ---------------------------------------------------------------------------------------------------
$query = $db->getQuery(true)
	->select([$db->quoteName('id'), $db->quoteName('rules')])
	->from($db->quoteName('#__assets'))
	->where($db->quoteName('name') . ' = ' . $db->quote('root.1'));

$asset = $db->setQuery($query)->loadObject();

if ($asset === null)
{
	fail("Could not find the root asset ('root.1') in #__assets.");
}

$rules = json_decode((string) $asset->rules, true);
$rules = is_array($rules) ? $rules : [];

if (!isset($rules['core.login.api']) || !is_array($rules['core.login.api']))
{
	$rules['core.login.api'] = [];
}

if (($rules['core.login.api'][2] ?? null) !== 1)
{
	$rules['core.login.api'][2] = 1;

	$rulesJson = json_encode($rules);
	$assetId   = (int) $asset->id;

	$query = $db->getQuery(true)
		->update($db->quoteName('#__assets'))
		->set($db->quoteName('rules') . ' = :rules')
		->where($db->quoteName('id') . ' = :id')
		->bind(':rules', $rulesJson, ParameterType::STRING)
		->bind(':id', $assetId, ParameterType::INTEGER);

	$db->setQuery($query)->execute();
}

// ---------------------------------------------------------------------------------------------------
// 3. Allow group 2 in plugins/user/token's allowedUserGroups (what
//    plugins/api-authentication/token actually checks — see the docblock above), keeping every other
//    already-allowed group (default: [8], Super Users) untouched.
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

if (!in_array(2, $allowed, true))
{
	$allowed[] = 2;
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
