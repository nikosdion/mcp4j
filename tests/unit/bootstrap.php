<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

/**
 * Bootstrap for the unit test suite.
 *
 * No Joomla framework is loaded here on purpose: these tests exercise the pure, framework-free classes
 * under component/backend/src/Support (see .claude/mcp-session-handling.md and the class docblocks for
 * why that logic was pulled out of the Joomla-coupled SessionManager/ServerController in the first
 * place). Only the vendored Composer autoloader is needed to load them.
 */

// phpcs:disable PSR1.Files.SideEffects

error_reporting(E_ALL);
ini_set('display_errors', '1');

define('_JEXEC', 1);

$vendorAutoload = __DIR__ . '/../../component/backend/vendor/autoload.php';

if (!is_file($vendorAutoload))
{
	fwrite(
		STDERR,
		"Vendored Composer autoloader not found at $vendorAutoload. Run `composer install` in the repository root first.\n"
	);

	exit(1);
}

/** @var \Composer\Autoload\ClassLoader $classLoader */
$classLoader = require $vendorAutoload;

// The vendored autoloader only ships production dependencies (no dev autoload map), so register the
// namespace under test, and the unit test namespace itself, by hand.
$classLoader->addPsr4('Akeeba\\Component\\Mcp4j\\Administrator\\Support\\', __DIR__ . '/../../component/backend/src/Support');
$classLoader->addPsr4('Akeeba\\Mcp4j\\Tests\\Unit\\', __DIR__);
