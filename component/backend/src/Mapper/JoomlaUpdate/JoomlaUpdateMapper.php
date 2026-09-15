<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Mapper\JoomlaUpdate;

defined('_JEXEC') || die;

/**
 * Pure helpers for the JoomlaUpdate tool category: the health-check output whitelist, the MariaDB
 * version-string normalisation core's own `Api\View\Healthcheck\JsonapiView::getStatsData()` applies, the
 * "are automated updates actually enabled" truth table core's `BaseController::validateUpdateToken()`
 * checks (minus the token itself — see `.plans/13-media-joomlaupdate.md`'s deviations list), and the
 * "is there an update worth reporting" decision core's `Api\View\Updates\JsonapiView::getUpdate()` makes.
 *
 * Pure: no Joomla.
 *
 * @since  0.0.1
 */
final class JoomlaUpdateMapper
{
	public const HEALTHCHECK_FIELDS = [
		'php_version',
		'db_type',
		'db_version',
		'cms_version',
		'server_os',
		'update_requirement_state',
		'automated_updates_enabled',
	];

	/**
	 * Mirrors `Api\View\Healthcheck\JsonapiView::getStatsData()`'s MariaDB detection regex exactly: a
	 * `5.5.5-` MySQL-compatibility prefix (some MariaDB builds report themselves this way) and/or a
	 * `mariadb-` marker, both optional, followed by the real `major.minor.patch` triple. Anything that
	 * doesn't match (a plain MySQL version string such as `8.0.36`) is returned unchanged.
	 */
	public static function normaliseDbVersion(string $version): string
	{
		if (preg_match('/^(?:5\.5\.5-)?(mariadb-)?(?P<major>\d+)\.(?P<minor>\d+)\.(?P<patch>\d+)/i', $version, $matches) === 1)
		{
			return $matches['major'] . '.' . $matches['minor'] . '.' . $matches['patch'];
		}

		return $version;
	}

	/**
	 * The three `com_joomlaupdate` options `BaseController::validateUpdateToken()` requires before it will
	 * even look at the `X-JUpdate-Token` header: `updatesource` must be `"default"` (not a beta/"next"
	 * channel), `minimum_stability` must be `4` (Stable), and `autoupdate` must be truthy. This package
	 * never checks the token itself — see the class docblock and the plan's deviations list — the calling
	 * user's own `core.admin` right stands in for it instead.
	 *
	 * @param   array<string, mixed>  $params  `ComponentHelper::getParams('com_joomlaupdate')->toArray()`.
	 */
	public static function automatedUpdatesEnabled(array $params): bool
	{
		return (($params['updatesource'] ?? 'default') === 'default')
			&& ((int) ($params['minimum_stability'] ?? 4) === 4)
			&& ((bool) ($params['autoupdate'] ?? false));
	}

	/**
	 * `null` unless there genuinely is a newer, installable version to report: a non-empty `$latest`,
	 * strictly newer than `$current`, and the site's own pre-update requirement checks (PHP version,
	 * extensions, ...) currently pass.
	 */
	public static function availableUpdate(?string $latest, string $current, bool $requirementsOk): ?string
	{
		if ($latest === null || $latest === '' || !$requirementsOk)
		{
			return null;
		}

		return version_compare($current, $latest) < 0 ? $latest : null;
	}
}
