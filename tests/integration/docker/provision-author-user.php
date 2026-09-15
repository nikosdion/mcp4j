<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

/**
 * Provisions (idempotently) an "Author" group (group id 3) Joomla user for the E2E test harness — see
 * security.md finding M3: `content_articles_create`'s `$state` parameter defaults to `1` (published),
 * and the tool's own pre-check (`ArticleMapper::touchesPublishingState()`) is skipped for that default
 * value specifically, so a live test needs a caller who genuinely holds `core.create` but NOT
 * `core.edit.state` on a `com_content` category to prove whether that's actually exploitable, or
 * whether Joomla's own `ArticleModel`/`AdminModel::save()` independently enforces the state.
 *
 * A stock Joomla install's default ACL already gives group 3 (Author) exactly `core.create` without
 * `core.edit.state` on `com_content` (confirmed against a live Joomla 5 install's `#__assets.rules`,
 * not assumed): `root.1` and the `com_content` component asset both explicitly grant `core.create` to
 * group 3, and grant `core.edit.state` only to groups 5 (Publisher) and 6 (Manager) — group 3's
 * ancestor chain (3 -> 2 Registered -> 1 Public) never sets `core.edit.state`, so it resolves to
 * Denied by inheritance.
 *
 * BUT `Articles::createArticle()` (`component/backend/src/Tool/Content/Articles.php:145`) gates the
 * *entire* tool behind `requireAuthorised('core.manage', 'com_content')` before it ever reaches the
 * per-category `core.create`/`core.edit.state` checks M3 is actually about — and a stock Author does
 * NOT have `core.manage` on `com_content` (only Manager/Administrator/Super User do by default; this
 * was also confirmed live — a first version of this script without the grant below hit "Permission
 * denied: core.manage on com_content" before ever reaching the code M3 is concerned with). A pure
 * stock "Author" therefore can never reach the code path this finding describes through this tool at
 * all.
 *
 * To actually exercise M3's code path, this script additionally grants `core.manage` on the
 * `com_content` *component* asset specifically (not root, and not touching any category) to group 3 —
 * modelling a realistic, deliberately-configured custom ACL shape some site owners do use in practice
 * ("can see/manage the Articles list and create drafts, but cannot publish"): `core.manage` +
 * `core.create` granted, `core.edit.state` still not granted anywhere in the chain. This is a
 * deliberate deviation from pure Joomla stock defaults, made narrowly (only `core.manage`, only on the
 * `com_content` asset, only for group 3) so it does not change any other group's behaviour or grant
 * `core.edit.state` anywhere.
 *
 * Run inside the `web` container's Joomla root:
 *   php /app/tests/integration/docker/provision-author-user.php <username>
 *
 * Same two API-login preconditions as provision-lowpriv-user.php (see that file's docblock for the
 * full rationale), granted here to group 3 instead of group 2:
 *
 * 1. `core.login.api` on the root asset, for group 3.
 * 2. Group 3 added to `plugins/user/token`'s `allowedUserGroups` parameter (what
 *    `plugins/api-authentication/token` actually checks).
 *
 * Every grant here is added narrowly — alongside whatever groups/actions are already present, never
 * replacing them — so this never weakens any other group's (e.g. Super Users') existing grants, and
 * never touches `core.edit.state` for group 3 anywhere.
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

const AUTHOR_GROUP_ID = 3;

$username = $argv[1] ?? null;

if (!$username)
{
	fail('Usage: provision-author-user.php <username>');
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
// 1. The user itself, mapped to group 3 (Author). Idempotent: reuse an existing row/mapping.
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
		'name'          => 'MCP4j Author',
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

$groupId = AUTHOR_GROUP_ID;

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
		'group_id' => AUTHOR_GROUP_ID,
	];

	$db->insertObject('#__user_usergroup_map', $map);
}

// ---------------------------------------------------------------------------------------------------
// 2. Grant core.login.api to group 3 on the root asset (name 'root.1'), keeping every other group's
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

if (($rules['core.login.api'][AUTHOR_GROUP_ID] ?? null) !== 1)
{
	$rules['core.login.api'][AUTHOR_GROUP_ID] = 1;

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
// 3. Grant core.manage on the com_content COMPONENT asset (not root, not any category) to group 3 —
//    see the docblock above: content_articles_create gates the whole tool behind core.manage on
//    com_content before it ever reaches the core.create/core.edit.state checks M3 is about, which a
//    stock Author lacks. This models a deliberately-configured "can manage/create but not publish"
//    custom ACL shape, without granting core.edit.state anywhere.
// ---------------------------------------------------------------------------------------------------
$query = $db->getQuery(true)
	->select([$db->quoteName('id'), $db->quoteName('rules')])
	->from($db->quoteName('#__assets'))
	->where($db->quoteName('name') . ' = ' . $db->quote('com_content'));

$contentAsset = $db->setQuery($query)->loadObject();

if ($contentAsset === null)
{
	fail("Could not find the 'com_content' asset in #__assets.");
}

$contentRules = json_decode((string) $contentAsset->rules, true);
$contentRules = is_array($contentRules) ? $contentRules : [];

if (!isset($contentRules['core.manage']) || !is_array($contentRules['core.manage']))
{
	$contentRules['core.manage'] = [];
}

if (($contentRules['core.manage'][AUTHOR_GROUP_ID] ?? null) !== 1)
{
	$contentRules['core.manage'][AUTHOR_GROUP_ID] = 1;

	$contentRulesJson = json_encode($contentRules);
	$contentAssetId   = (int) $contentAsset->id;

	$query = $db->getQuery(true)
		->update($db->quoteName('#__assets'))
		->set($db->quoteName('rules') . ' = :rules')
		->where($db->quoteName('id') . ' = :id')
		->bind(':rules', $contentRulesJson, ParameterType::STRING)
		->bind(':id', $contentAssetId, ParameterType::INTEGER);

	$db->setQuery($query)->execute();
}

// ---------------------------------------------------------------------------------------------------
// 4. Allow group 3 in plugins/user/token's allowedUserGroups (what
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

if (!in_array(AUTHOR_GROUP_ID, $allowed, true))
{
	$allowed[] = AUTHOR_GROUP_ID;
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
