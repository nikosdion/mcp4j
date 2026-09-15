<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

/**
 * Provisions (freshly, every call) an ATS Pro ticket carrying one post with four attachments — a plain
 * text file, a PNG, an oversized text file, and a ZIP archive — for the `AttachmentsTest`/`ManagerNotesTest`
 * E2E suites (`.plans/16b-tickets-pro.md`).
 *
 * Same rationale as `tests/integration/fixtures/ats.php` (read that file's own docblock first): PHPUnit
 * for this suite runs *inside* the `web` container as an HTTP client, so it cannot touch the site's
 * database or filesystem directly. This script is launched as a separate CLI process instead.
 *
 * Run inside the `web` container's Joomla root:
 *   php /app/tests/integration/fixtures/ats-attachments.php <categoryId>
 *
 * Prints exactly one JSON object on stdout, nothing else:
 *   {"pro": false}                                                                on ATS Core
 *   {"pro": true, "ticketId": …, "postId": …, "attachments": {"text": …, "png": …, "big": …, "zip": …}}
 */

// phpcs:disable PSR1.Files.SideEffects

const _JEXEC = 1;

define('JPATH_BASE', '/var/www/html');
require_once JPATH_BASE . '/includes/defines.php';
require_once JPATH_BASE . '/includes/framework.php';

use Akeeba\Component\ATS\Administrator\Helper\Attachment;
use Joomla\CMS\Factory;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/**
 * A minimal valid 1x1 transparent PNG — the same fixture bytes as
 * tests/unit/Mapper/Tickets/AttachmentInlinerTest.php, so both suites exercise the same image.
 */
const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

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

function insertTicket(int $categoryId, int $adminId): int
{
	$db  = db();
	$now = Factory::getDate('now', 'UTC')->toSql();
	$tag = bin2hex(random_bytes(4));

	$ticket = (object) [
		'catid'       => $categoryId,
		'status'      => 'O',
		'title'       => 'MCP4j ATS Attachments ticket ' . $tag,
		'alias'       => 'mcp4j-ats-attachments-ticket-' . $tag,
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

function insertPost(int $ticketId, int $adminId): int
{
	$db  = db();
	$now = Factory::getDate('now', 'UTC')->toSql();

	$post = (object) [
		'ticket_id'     => $ticketId,
		'content_html'  => '<p>Fixture</p>',
		'origin'        => 'web',
		'timespent'     => 0,
		'created'       => $now,
		'created_by'    => $adminId,
		'modified_by'   => 0,
		'enabled'       => 1,
		'attachment_id' => '0',
	];

	// insertObject()'s second argument is by-reference: $post MUST be a variable.
	$db->insertObject('#__ats_posts', $post, 'id');

	return (int) $post->id;
}

/**
 * Creates (if missing) the two-level subdirectory an attachment's mangled name maps to, best-effort
 * chown-ing the newly created levels to www-data — the delete E2E test runs as an HTTP request handled
 * by the web server user, not by this CLI script's (typically root, inside the container) user, and
 * AttachmentTable::onAfterDelete() needs to be able to remove the file it's about to test against.
 */
function ensureAttachmentDir(string $mangled): string
{
	$relative = Attachment::relativePathFor($mangled); // e.g. "ab/cd/ab...cd" (mangled name)
	$subDir   = dirname($relative);                     // e.g. "ab/cd"
	$base     = Attachment::getDirectory();

	if (!is_dir($base))
	{
		mkdir($base, 0755, true);
	}

	$level1 = $base . '/' . dirname($subDir); // e.g. .../attachments/ab
	$level2 = $base . '/' . $subDir;          // e.g. .../attachments/ab/cd

	foreach ([$level1, $level2] as $dir)
	{
		if (is_dir($dir))
		{
			continue;
		}

		mkdir($dir, 0755, true);

		@chown($dir, 'www-data');
		@chgrp($dir, 'www-data');
	}

	return $level2;
}

function writeAttachmentFile(string $mangled, string $content): void
{
	$dir  = ensureAttachmentDir($mangled);
	$path = $dir . '/' . $mangled;

	file_put_contents($path, $content);
	chmod($path, 0644);

	@chown($path, 'www-data');
	@chgrp($path, 'www-data');
}

function insertAttachment(int $postId, string $originalFilename, string $mangled, string $mimeType, int $adminId): int
{
	$db  = db();
	$now = Factory::getDate('now', 'UTC')->toSql();

	$attachment = (object) [
		'post_id'           => $postId,
		'original_filename' => $originalFilename,
		'mangled_filename'  => $mangled,
		'mime_type'         => $mimeType,
		'origin'            => 'web',
		'created'           => $now,
		'created_by'        => $adminId,
		'enabled'           => 1,
	];

	// insertObject()'s second argument is by-reference: $attachment MUST be a variable.
	$db->insertObject('#__ats_attachments', $attachment, 'id');

	return (int) $attachment->id;
}

function updatePostAttachmentIds(int $postId, string $csv): void
{
	$db      = db();
	$boundId = $postId;
	$query   = $db->getQuery(true)
		->update($db->quoteName('#__ats_posts'))
		->set($db->quoteName('attachment_id') . ' = :attachmentId')
		->where($db->quoteName('id') . ' = :id')
		->bind(':attachmentId', $csv, ParameterType::STRING)
		->bind(':id', $boundId, ParameterType::INTEGER);

	$db->setQuery($query)->execute();
}

function buildZip(string $smallContent, string $bigContent): string
{
	$tmp = tempnam(sys_get_temp_dir(), 'ats_fixture_zip_');
	$zip = new \ZipArchive();

	if ($zip->open($tmp, \ZipArchive::OVERWRITE) !== true)
	{
		fail('Could not create the fixture ZIP archive.');
	}

	$zip->addFromString('small.txt', $smallContent);
	$zip->addFromString('big.log', $bigContent);
	$zip->close();

	$content = (string) file_get_contents($tmp);

	unlink($tmp);

	return $content;
}

// ---------------------------------------------------------------------------------------------------
// Entry point
// ---------------------------------------------------------------------------------------------------

$categoryId = (int) ($argv[1] ?? 0);

if ($categoryId <= 0)
{
	fail('Usage: ats-attachments.php <categoryId>');
}

$container = Factory::getContainer();

// A Console application, matching cli/joomla.php's own bootstrap — mirrors ats.php's/
// docker/provision-token.php's own bootstrap exactly.
$container->alias('session', 'session.cli')
	->alias('JSession', 'session.cli')
	->alias(\Joomla\CMS\Session\Session::class, 'session.cli')
	->alias(\Joomla\Session\Session::class, 'session.cli')
	->alias(\Joomla\Session\SessionInterface::class, 'session.cli');

$app                  = $container->get(\Joomla\Console\Application::class);
Factory::$application = $app;

if (!@is_dir(JPATH_ADMINISTRATOR . '/components/com_ats'))
{
	fail('com_ats is not installed.');
}

$adminId = findUserId('admin');

if ($adminId <= 0)
{
	fail('Could not find the admin user.');
}

$adminUser = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById($adminId);
$app->loadIdentity($adminUser);

// A Console application only registers every installed extension's PSR-4 namespace (from
// administrator/cache/autoload_psr4.php) as a side effect of its own execute() — which this script
// never calls, unlike a normal `cli/joomla.php` invocation. Without this, Akeeba\Component\ATS\...
// classes (e.g. the Attachment helper used below) simply couldn't be autoloaded at all.
//
// Deliberately NOT calling $app->bootComponent('com_ats') here (unlike AbstractAtsTool::bootAts(),
// which does, and can, because it always runs inside a real ApiApplication request): under a bare
// Console application, ATS's services/provider.php drags in a chain (MVCFactory -> Router service
// provider -> `new SiteApplication()`) that tries to parse the current HTTP request's URI to build a
// router — something that doesn't exist in a CLI process, and fails with "Could not parse the
// requested URI". None of the ATS classes this fixture actually needs (Attachment::getDirectory() /
// ::relativePathFor()) require the component to be "booted" in that sense — they're stateless helpers
// that only need their own class autoloadable, which createExtensionNamespaceMap() above already
// provides.
$app->createExtensionNamespaceMap();

$versionFile = JPATH_ADMINISTRATOR . '/components/com_ats/version.php';

if (@is_file($versionFile))
{
	include_once $versionFile;
}

if (!defined('ATS_PRO'))
{
	define('ATS_PRO', @is_dir(JPATH_ADMINISTRATOR . '/components/com_ats/src/CliCommand') ? '1' : '0');
}

if (!ATS_PRO)
{
	echo json_encode(['pro' => false]), "\n";
	exit(0);
}

$ticketId = insertTicket($categoryId, $adminId);
$postId   = insertPost($ticketId, $adminId);

$attachmentIds = [];

$mangledText = sha1(random_bytes(16));
writeAttachmentFile($mangledText, "Hello attachment\n");
$attachmentIds['text'] = insertAttachment($postId, 'hello.txt', $mangledText, 'text/plain', $adminId);

$mangledPng = sha1(random_bytes(16));
writeAttachmentFile($mangledPng, (string) base64_decode(PNG_BASE64, true));
$attachmentIds['png'] = insertAttachment($postId, 'pixel.png', $mangledPng, 'image/png', $adminId);

$mangledBig = sha1(random_bytes(16));
writeAttachmentFile($mangledBig, str_repeat('x', 300 * 1024));
$attachmentIds['big'] = insertAttachment($postId, 'big.log', $mangledBig, 'text/plain', $adminId);

$zipContent  = buildZip(str_repeat('a', 1024), str_repeat('x', 300 * 1024));
$mangledZip  = sha1(random_bytes(16));
writeAttachmentFile($mangledZip, $zipContent);
$attachmentIds['zip'] = insertAttachment($postId, 'bundle.zip', $mangledZip, 'application/zip', $adminId);

updatePostAttachmentIds($postId, implode(',', $attachmentIds));

echo json_encode(
	[
		'pro'         => true,
		'ticketId'    => $ticketId,
		'postId'      => $postId,
		'attachments' => $attachmentIds,
	]
), "\n";
