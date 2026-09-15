<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

/**
 * Bootstrap for the integration test suite.
 *
 * Unlike the unit suite, no Joomla application is booted in this process: the integration tests are
 * plain HTTP clients driving a real Joomla + MCP4j install running in Docker (see
 * tests/integration/run-tests.sh / tests/integration/docker). This file only wires up autoloading and
 * reads the environment variables the test cases need.
 */

// phpcs:disable PSR1.Files.SideEffects

error_reporting(E_ALL);
ini_set('display_errors', '1');

// Minimal PSR-4 autoloader for the integration test source tree — no Composer ClassLoader is
// guaranteed to be present (a plain HTTP client suite has no need for the component's own vendored
// dependencies), so register a small loader by hand.
spl_autoload_register(function (string $class): void {
	$prefix = 'Akeeba\\Mcp4j\\Tests\\Integration\\';

	if (strncmp($class, $prefix, strlen($prefix)) !== 0)
	{
		return;
	}

	$relative = substr($class, strlen($prefix));
	$path     = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';

	if (is_file($path))
	{
		require $path;
	}
});

// Read the environment once; AbstractE2ETestCase::setUpBeforeClass() consumes these via getenv() as
// well, this is just an early sanity note for anyone running `phpunit` directly without provisioning.
$baseUrl = getenv('MCP4J_BASE_URL') ?: '';
$token   = getenv('MCP4J_API_TOKEN') ?: '';

if ($baseUrl === '' || $token === '')
{
	fwrite(
		STDERR,
		"NOTE: MCP4J_BASE_URL and/or MCP4J_API_TOKEN are not set. " .
		"Integration tests will be skipped. Run them via tests/integration/run-tests.sh.\n"
	);
}
