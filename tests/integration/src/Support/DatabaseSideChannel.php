<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Integration\Support;

/**
 * Test-only direct database access, for E2E test setup/teardown that the MCP tool surface itself cannot
 * (or must not) perform.
 *
 * The motivating case is plan 01's component Options: turning `read_only` on via `config_component_update`
 * is, by design, a one-way door through MCP — every write tool (including `config_component_update`
 * itself) is refused the instant it takes effect, so there is no MCP path back to turn it off again. A test
 * that exercises `read_only` must therefore be able to restore the previous `#__extensions.params` value
 * some other way, in `tearDown()`, regardless of what the test itself did or didn't manage to undo through
 * MCP first.
 *
 * This class must NEVER be used to *prove* tool behaviour (a test asserting on a row this class read would
 * really be asserting on `SELECT`, not on the tool) — only for arranging state before a test, or restoring
 * it afterwards. Every test that uses it must call {@see self::isAvailable()} and `markTestSkipped()` when
 * it returns false, so the suite still runs (skipped rather than erroring) against an environment that
 * doesn't expose direct DB access (e.g. a bare `phpunit` run outside `run-tests.sh`, or a future non-Docker
 * target for this same suite).
 *
 * PHPUnit itself runs inside the `web` container (see `tests/integration/docker/docker-compose.yml`),
 * whose environment already carries `JTEST_DB_HOST`/`JTEST_DB_PORT`/`JTEST_DB_USER`/`JTEST_DB_PASSWORD`/
 * `JTEST_DB_NAME`/`JTEST_DB_PREFIX` for exactly this purpose — the same database Joomla itself is
 * configured against, reached over the `db` service's internal network rather than through Joomla or MCP4j
 * at all.
 */
final class DatabaseSideChannel
{
	private static ?\PDO $pdo = null;

	/**
	 * True when both the required environment variables and the `pdo_mysql` extension are present. Every
	 * public method below assumes this has already been checked (they do not re-check it themselves) —
	 * callers must `markTestSkipped()` rather than let a `\PDOException` fail the test outright.
	 */
	public static function isAvailable(): bool
	{
		if (!extension_loaded('pdo_mysql'))
		{
			return false;
		}

		foreach (['JTEST_DB_HOST', 'JTEST_DB_PORT', 'JTEST_DB_USER', 'JTEST_DB_NAME', 'JTEST_DB_PREFIX'] as $name)
		{
			if (getenv($name) === false || getenv($name) === '')
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * Lazily creates (and caches, for the lifetime of the PHP process running the test suite) a PDO
	 * connection to the site's own database, in exception mode, UTF-8 (utf8mb4).
	 */
	public static function pdo(): \PDO
	{
		if (self::$pdo instanceof \PDO)
		{
			return self::$pdo;
		}

		$host = getenv('JTEST_DB_HOST') ?: '';
		$port = getenv('JTEST_DB_PORT') ?: '3306';
		$user = getenv('JTEST_DB_USER') ?: '';
		$pass = getenv('JTEST_DB_PASSWORD') ?: '';
		$name = getenv('JTEST_DB_NAME') ?: '';

		$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);

		self::$pdo = new \PDO($dsn, $user, $pass, [
			\PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
			\PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
		]);

		return self::$pdo;
	}

	/**
	 * Expands the `#__` Joomla table-name prefix placeholder against `JTEST_DB_PREFIX`, e.g.
	 * `table('#__extensions')` => `jtest_extensions`.
	 */
	public static function table(string $name): string
	{
		$prefix = getenv('JTEST_DB_PREFIX') ?: '';

		return str_replace('#__', $prefix, $name);
	}

	/**
	 * Reads the raw (still JSON-encoded) `params` column of a component's `#__extensions` row.
	 */
	public static function getComponentParams(string $option): string
	{
		$row = self::fetchOne(
			'SELECT params FROM ' . self::table('#__extensions') . ' WHERE type = :type AND element = :element',
			['type' => 'component', 'element' => $option]
		);

		return $row !== null ? (string) ($row['params'] ?? '') : '';
	}

	/**
	 * Overwrites the raw `params` column of a component's `#__extensions` row. `$json` is written verbatim
	 * (no validation) — callers are expected to pass back exactly what {@see self::getComponentParams()}
	 * returned earlier, or a deliberately crafted test fixture.
	 */
	public static function setComponentParams(string $option, string $json): void
	{
		self::execute(
			'UPDATE ' . self::table('#__extensions') . ' SET params = :params WHERE type = :type AND element = :element',
			['params' => $json, 'type' => 'component', 'element' => $option]
		);
	}

	/**
	 * @param   array<string, mixed>  $params
	 *
	 * @return  ?array<string, mixed>
	 */
	public static function fetchOne(string $sql, array $params = []): ?array
	{
		$statement = self::pdo()->prepare($sql);
		$statement->execute($params);

		$row = $statement->fetch();

		return $row === false ? null : $row;
	}

	/**
	 * @param   array<string, mixed>  $params
	 *
	 * @return  int  The number of affected rows.
	 */
	public static function execute(string $sql, array $params = []): int
	{
		$statement = self::pdo()->prepare($sql);
		$statement->execute($params);

		return $statement->rowCount();
	}
}
