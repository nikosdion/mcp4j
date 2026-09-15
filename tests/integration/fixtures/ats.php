<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

/**
 * Provisions (idempotently) the Akeeba Ticket System (ATS) fixtures the Tickets E2E suite needs.
 *
 * ATS has no CLI or Web Services surface of its own to create categories/custom fields/tickets through,
 * and PHPUnit for this suite runs *inside* the `web` container as an HTTP client talking to the MCP
 * endpoint — it cannot touch the site's database directly. This script is instead launched as a separate
 * CLI process (via `proc_open`/`exec()`, from {@see \Akeeba\Mcp4j\Tests\Integration\Tests\Tickets\AtsFixtures})
 * and talks to the database the same way `docker/provision-token.php` does.
 *
 * Run inside the `web` container's Joomla root:
 *   php /app/tests/integration/fixtures/ats.php ensure
 *   php /app/tests/integration/fixtures/ats.php fields
 *
 * Prints exactly one JSON object on stdout, nothing else.
 */

// phpcs:disable PSR1.Files.SideEffects

const _JEXEC = 1;

define('JPATH_BASE', '/var/www/html');
require_once JPATH_BASE . '/includes/defines.php';
require_once JPATH_BASE . '/includes/framework.php';

use Joomla\CMS\Factory;
use Joomla\CMS\Table\Category;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

function fail(string $message): never
{
	fwrite(STDERR, $message . "\n");
	exit(1);
}

/**
 * @return  \Joomla\Database\DatabaseDriver
 */
function db()
{
	return Factory::getContainer()->get(DatabaseInterface::class);
}

function findUserId(string $username): int
{
	$db    = db();
	$query = $db->getQuery(true)
		->select($db->quoteName('id'))
		->from($db->quoteName('#__users'))
		->where($db->quoteName('username') . ' = :username')
		->bind(':username', $username, ParameterType::STRING);

	return (int) $db->setQuery($query)->loadResult();
}

/**
 * Finds (by alias) or creates a `com_ats` category. Mirrors the pattern used by ATS's own
 * `tests/integration/assets/e2e-provision.php`.
 */
function findOrCreateCategory(string $title, string $alias, int $adminId): int
{
	$db    = db();
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

	// setLocation() places the node in the nested set; store() then creates the #__assets row and wires
	// it to the com_ats component asset as its parent.
	$table->setLocation(1, 'last-child');

	if (!$table->store())
	{
		fail(sprintf('Could not store ATS category "%s": %s', $title, $table->getError()));
	}

	return (int) $table->id;
}

function ensureSendEmailsDisabled(): void
{
	$db    = db();
	$query = $db->getQuery(true)
		->select([$db->quoteName('extension_id'), $db->quoteName('params')])
		->from($db->quoteName('#__extensions'))
		->where($db->quoteName('type') . ' = ' . $db->quote('component'))
		->where($db->quoteName('element') . ' = ' . $db->quote('com_ats'));

	$extension = $db->setQuery($query)->loadObject();

	if ($extension === null)
	{
		fail('com_ats is not a registered component.');
	}

	$params = json_decode((string) $extension->params, true);
	$params = is_array($params) ? $params : [];

	if ((int) ($params['sendEmails'] ?? 1) === 0)
	{
		return;
	}

	$params['sendEmails'] = 0;

	$paramsJson = json_encode($params);
	$extId      = (int) $extension->extension_id;

	$query = $db->getQuery(true)
		->update($db->quoteName('#__extensions'))
		->set($db->quoteName('params') . ' = :params')
		->where($db->quoteName('extension_id') . ' = :id')
		->bind(':params', $paramsJson, ParameterType::STRING)
		->bind(':id', $extId, ParameterType::INTEGER);

	$db->setQuery($query)->execute();
}

function findForeignCategoryId(): int
{
	$db    = db();
	$query = $db->getQuery(true)
		->select($db->quoteName('id'))
		->from($db->quoteName('#__categories'))
		->where($db->quoteName('extension') . ' = ' . $db->quote('com_content'))
		->order($db->quoteName('id') . ' ASC');

	return (int) $db->setQuery($query)->loadResult();
}

function commandEnsure(int $adminId): void
{
	ensureSendEmailsDisabled();

	$categoryId        = findOrCreateCategory('MCP4j ATS Tests', 'mcp4j-ats-tests', $adminId);
	$foreignCategoryId = findForeignCategoryId();
	$isPro             = @is_dir(JPATH_ADMINISTRATOR . '/components/com_ats/src/CliCommand');

	echo json_encode(
		[
			'installed'         => true,
			'pro'               => (bool) $isPro,
			'adminId'           => $adminId,
			'categoryId'        => $categoryId,
			'foreignCategoryId' => $foreignCategoryId,
		]
	), "\n";
}

function findOrCreateRequiredField(int $adminId): int
{
	$db        = db();
	$fieldName = 'mcp4j-required';

	$query = $db->getQuery(true)
		->select($db->quoteName('id'))
		->from($db->quoteName('#__fields'))
		->where($db->quoteName('context') . ' = ' . $db->quote('com_ats.ticket'))
		->where($db->quoteName('name') . ' = :name')
		->bind(':name', $fieldName, ParameterType::STRING);

	$fieldId = (int) $db->setQuery($query)->loadResult();

	if ($fieldId > 0)
	{
		return $fieldId;
	}

	$now = Factory::getDate('now', 'UTC')->toSql();

	$field = (object) [
		'context'         => 'com_ats.ticket',
		'title'           => 'MCP4j required',
		'name'            => $fieldName,
		'label'           => 'MCP4j required',
		'default_value'   => '',
		'type'            => 'text',
		'note'            => '',
		'description'     => '',
		'state'           => 1,
		'required'        => 1,
		'language'        => '*',
		'params'          => '{}',
		'fieldparams'     => '{}',
		'created_time'    => $now,
		'created_user_id' => $adminId,
		'modified_time'   => $now,
		'modified_by'     => $adminId,
		'access'          => 1,
	];

	// insertObject()'s second argument is by-reference: $field MUST be a variable, never an inline cast.
	$db->insertObject('#__fields', $field, 'id');

	return (int) $field->id;
}

function assignFieldToCategory(int $fieldId, int $categoryId): void
{
	$db    = db();
	$query = $db->getQuery(true)
		->select('COUNT(*)')
		->from($db->quoteName('#__fields_categories'))
		->where($db->quoteName('field_id') . ' = :fieldId')
		->where($db->quoteName('category_id') . ' = :categoryId')
		->bind(':fieldId', $fieldId, ParameterType::INTEGER)
		->bind(':categoryId', $categoryId, ParameterType::INTEGER);

	if (((int) $db->setQuery($query)->loadResult()) > 0)
	{
		return;
	}

	$map = (object) [
		'field_id'    => $fieldId,
		'category_id' => $categoryId,
	];

	$db->insertObject('#__fields_categories', $map);
}

function insertTicket(int $categoryId, int $adminId): int
{
	$db  = db();
	$now = Factory::getDate('now', 'UTC')->toSql();
	$tag = bin2hex(random_bytes(4));

	$ticket = (object) [
		'catid'       => $categoryId,
		'status'      => 'O',
		'title'       => 'MCP4j ATS Fields ticket ' . $tag,
		'alias'       => 'mcp4j-ats-fields-ticket-' . $tag,
		'public'      => 1,
		'priority'    => 5,
		'origin'      => 'web',
		'created'     => $now,
		'created_by'  => $adminId,
		'modified'    => $now,
		'modified_by' => $adminId,
		'enabled'     => 1,
		'params'      => '{}',
	];

	// insertObject()'s second argument is by-reference: $ticket MUST be a variable.
	$db->insertObject('#__ats_tickets', $ticket, 'id');

	return (int) $ticket->id;
}

function insertFieldValue(int $fieldId, int $ticketId): void
{
	$db    = db();
	$value = (object) [
		'field_id' => $fieldId,
		'item_id'  => (string) $ticketId,
		'value'    => 'initial',
	];

	$db->insertObject('#__fields_values', $value);
}

function commandFields(int $adminId): void
{
	$categoryId = findOrCreateCategory('MCP4j ATS Fields', 'mcp4j-ats-fields', $adminId);
	$fieldId    = findOrCreateRequiredField($adminId);

	assignFieldToCategory($fieldId, $categoryId);

	$ticketId = insertTicket($categoryId, $adminId);

	insertFieldValue($fieldId, $ticketId);

	echo json_encode(
		[
			'categoryId' => $categoryId,
			'fieldName'  => 'mcp4j-required',
			'ticketId'   => $ticketId,
		]
	), "\n";
}

// ---------------------------------------------------------------------------------------------------
// Entry point
// ---------------------------------------------------------------------------------------------------

$command = $argv[1] ?? null;

if (!in_array($command, ['ensure', 'fields'], true))
{
	fail('Usage: ats.php <ensure|fields>');
}

$container = Factory::getContainer();

// A Console application, matching cli/joomla.php's own bootstrap, purely so framework services that
// expect Factory::getApplication() to return something work — mirrors docker/provision-token.php's own
// bootstrap exactly.
$container->alias('session', 'session.cli')
	->alias('JSession', 'session.cli')
	->alias(\Joomla\CMS\Session\Session::class, 'session.cli')
	->alias(\Joomla\Session\Session::class, 'session.cli')
	->alias(\Joomla\Session\SessionInterface::class, 'session.cli');

$app                   = $container->get(\Joomla\Console\Application::class);
Factory::$application  = $app;

if (!@is_dir(JPATH_ADMINISTRATOR . '/components/com_ats'))
{
	echo json_encode(['installed' => false]), "\n";
	exit(0);
}

$adminId = findUserId('admin');

if ($adminId <= 0)
{
	fail('Could not find the admin user.');
}

// Several Table classes (Category::check() among them) and ATS's own Permissions helper read the
// "current user" off the application identity — without this, created_user_id/ACL-adjacent checks fall
// back to a guest user even though this script is provisioning fixtures on the admin's behalf.
$adminUser = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById($adminId);
$app->loadIdentity($adminUser);

if ($command === 'ensure')
{
	commandEnsure($adminId);
}
else
{
	commandFields($adminId);
}
