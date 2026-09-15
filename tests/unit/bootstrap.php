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
 * that live in several `Support/` folders under component/backend/src (component/backend/src/Support,
 * component/backend/src/Mcp/Support, and — from later plans onward — component/backend/src/Bridge/Support
 * and component/backend/src/Mapper/<Category>). See .claude/mcp-session-handling.md and the individual
 * class docblocks for why this logic is deliberately kept Joomla-free. Joomla-coupled classes (anything
 * that touches Factory::getApplication(), the database, etc.) must never be loaded by this suite — only
 * the vendored Composer autoloader is needed to load the classes under test.
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
// namespace under test, and the unit test namespace itself, by hand. The whole `Administrator\` root is
// registered (not just `Administrator\Support\`) so that `Administrator\Mcp\...` (attributes, exceptions,
// Mcp\Support) resolves too — nothing under `Administrator\` outside of a `Support/` folder is meant to
// be loaded by this suite, but PSR-4 has no way to express "except this subtree", so that boundary is
// enforced by review/convention (see .plans/00-conventions.md), not by the autoloader.
$classLoader->addPsr4('Akeeba\\Component\\Mcp4j\\Administrator\\', __DIR__ . '/../../component/backend/src');
$classLoader->addPsr4('Akeeba\\Mcp4j\\Tests\\Unit\\', __DIR__);
