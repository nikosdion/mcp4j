<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

/**
 * Mints a Joomla API token for a user, for the E2E test harness. Prints the bearer token (and nothing
 * else) to stdout on success.
 *
 * Run inside the `web` container's Joomla root:
 *   php /app/tests/integration/docker/provision-token.php <username>
 *
 * Joomla only computes an API token lazily, the first time a site owner ticks "Enable Token Based
 * Authentication" on a user's profile and saves it (plugins/user/token) — there's no CLI command for
 * it. Rather than drive that through the backend UI (slow, browser-dependent) or Joomla's UserModel
 * (whose save() validation/side effects aren't worth depending on for a throwaway test fixture), this
 * writes the two `#__user_profiles` rows the plugin itself would have written, then computes the
 * token the same way plugins/api-authentication/token's onUserAuthenticate() verifies it:
 *   base64_encode("sha256:{$userId}:" . hash_hmac('sha256', base64_decode($seed), $siteSecret))
 * (see plugins/user/token/src/Extension/Token.php::getDefaultProfileFieldValues() and
 * plugins/api-authentication/token/src/Extension/Token.php::onUserAuthenticate() in Joomla core).
 */

// phpcs:disable PSR1.Files.SideEffects

const _JEXEC = 1;

define('JPATH_BASE', '/var/www/html');
require_once JPATH_BASE . '/includes/defines.php';
require_once JPATH_BASE . '/includes/framework.php';

use Joomla\CMS\Factory;
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
	fail('Usage: provision-token.php <username>');
}

$container = Factory::getContainer();

// A Console application, matching cli/joomla.php's own bootstrap, purely so framework services that
// expect Factory::getApplication() to return something (e.g. the site's configured "secret") work.
$container->alias('session', 'session.cli')
	->alias('JSession', 'session.cli')
	->alias(\Joomla\CMS\Session\Session::class, 'session.cli')
	->alias(\Joomla\Session\Session::class, 'session.cli')
	->alias(\Joomla\Session\SessionInterface::class, 'session.cli');

$app                   = $container->get(\Joomla\Console\Application::class);
Factory::$application  = $app;

$db = $container->get(DatabaseInterface::class);

$query = $db->getQuery(true)
	->select($db->quoteName('id'))
	->from($db->quoteName('#__users'))
	->where($db->quoteName('username') . ' = :username')
	->bind(':username', $username, ParameterType::STRING);

$userId = (int) $db->setQuery($query)->loadResult();

if ($userId <= 0)
{
	fail("No such user: {$username}");
}

// Remove any pre-existing Joomla Token profile rows for this user (mirrors what the plugin itself does
// before writing fresh ones), then write a fresh, enabled token seed.
$likeKey = 'joomlatoken.%';
$query   = $db->getQuery(true)
	->delete($db->quoteName('#__user_profiles'))
	->where($db->quoteName('user_id') . ' = :userId')
	->where($db->quoteName('profile_key') . ' LIKE :profileKey')
	->bind(':userId', $userId, ParameterType::INTEGER)
	->bind(':profileKey', $likeKey, ParameterType::STRING);
$db->setQuery($query)->execute();

$seed = base64_encode(random_bytes(32));

$rows = [
	['joomlatoken.token', $seed, 1],
	['joomlatoken.enabled', '1', 2],
];

foreach ($rows as [$key, $value, $ordering])
{
	$object = (object) [
		'user_id'       => $userId,
		'profile_key'   => $key,
		'profile_value' => $value,
		'ordering'      => $ordering,
	];

	$db->insertObject('#__user_profiles', $object);
}

$siteSecret = $app->get('secret');

if (empty($siteSecret))
{
	fail('The site has no configured secret; cannot compute a token.');
}

$hmac  = hash_hmac('sha256', base64_decode($seed), $siteSecret);
$token = base64_encode("sha256:{$userId}:{$hmac}");

echo $token, "\n";
