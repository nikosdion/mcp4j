<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Mapper\Panopticon;

defined('_JEXEC') || die;

/**
 * Pure input/output mapping for the Panopticon Connector's core-update tools.
 *
 * `STATUS_FIELDS` is copied verbatim from the connector's
 * `component/api/src/View/Core/JsonapiView.php::displayItem()` (`core.update` mode field list). See
 * `.plans/14-panopticon-core.md`.
 *
 * Pure: no Joomla.
 *
 * @since  0.0.1
 */
final class CoreUpdateMapper
{
	public const STATUS_FIELDS = [
		'current',
		'currentStability',
		'latest',
		'latestStability',
		'needsUpdate',
		'details',
		'info',
		'changelog',
		'extensionAvailable',
		'updateSiteAvailable',
		'maxCacheHours',
		'minimumStability',
		'updateSiteUrl',
		'lastUpdateTimestamp',
		'phpVersion',
		'overridesChanged',
		'panopticon',
		'admintools',
		'serverInfo',
	];

	/**
	 * Replaces the connector's `admintools` sub-structure (which carries the Admin Tools Secret Word and
	 * custom admin directory verbatim) with booleans only — see the plan's "Sensitive output" section.
	 *
	 * @param   array<string, mixed>  $status  A normalised core-update status item.
	 *
	 * @return  array<string, mixed>
	 */
	public static function sanitiseStatus(array $status): array
	{
		$admintools = $status['admintools'] ?? null;

		if (is_array($admintools))
		{
			$secretWord = $admintools['secret_word'] ?? null;
			$adminDir   = $admintools['admindir'] ?? 'administrator';
			$adminDir   = ($adminDir === null || $adminDir === '') ? 'administrator' : $adminDir;

			$status['admintools'] = [
				'enabled'            => (bool) ($admintools['enabled'] ?? false),
				'renamed'            => (bool) ($admintools['renamed'] ?? false),
				'hasSecretWord'      => !empty($secretWord),
				'hasCustomAdminDir'  => $adminDir !== 'administrator',
				'awayschedule'       => $admintools['awayschedule'] ?? null,
			];
		}

		return $status;
	}

	/**
	 * Parses the `panopticon_coreupdate_changesource` `source` parameter into `[updateSource, customUrl]`.
	 * An http(s) URL always means "custom" (with that URL); otherwise the value must be one of `default`,
	 * `next`, `custom` (which then requires a URL to have been given separately — impossible here, so it
	 * always throws), or, only on Joomla below 5.0, `testing`.
	 *
	 * @return  array{0: string, 1: ?string}
	 *
	 * @throws  \InvalidArgumentException  The source is neither a recognised keyword nor an http(s) URL.
	 */
	public static function parseUpdateSource(string $source, string $joomlaVersion): array
	{
		$trimmed = trim($source);

		if (preg_match('#^https?://#i', $trimmed) === 1)
		{
			return ['custom', $trimmed];
		}

		$lower          = strtolower($trimmed);
		$testingAllowed = version_compare($joomlaVersion, '5.0.0', 'lt');
		$allowed        = $testingAllowed ? ['default', 'next', 'custom', 'testing'] : ['default', 'next', 'custom'];

		if (!in_array($lower, $allowed, true))
		{
			throw new \InvalidArgumentException(sprintf(
				'Invalid update source "%s". Allowed: %s, or an http(s) URL.',
				$source,
				implode(', ', $allowed)
			));
		}

		if ($lower === 'custom')
		{
			throw new \InvalidArgumentException('A custom update source needs a URL');
		}

		return [$lower, null];
	}

	/**
	 * Parses the `eid` parameter of `panopticon_updates_apply` — a single id, a comma-separated list
	 * (optionally wrapped in `[...]`), with arbitrary whitespace — into a deduplicated, order-preserving
	 * `list<int>` of positive extension ids.
	 *
	 * @return  list<int>
	 */
	public static function parseExtensionIds(?string $eid): array
	{
		if ($eid === null)
		{
			return [];
		}

		$trimmed = trim($eid);

		if ($trimmed === '')
		{
			return [];
		}

		$trimmed = trim($trimmed, '[]');

		$ids = [];

		foreach (explode(',', $trimmed) as $part)
		{
			$part = trim($part);

			if ($part === '' || !is_numeric($part))
			{
				continue;
			}

			$id = (int) $part;

			if ($id <= 0)
			{
				continue;
			}

			$ids[$id] = $id;
		}

		return array_values($ids);
	}

	public static function isHttpUrl(string $url): bool
	{
		if (filter_var($url, FILTER_VALIDATE_URL) === false)
		{
			return false;
		}

		$scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

		return in_array($scheme, ['http', 'https'], true);
	}

	/**
	 * Port of `CoreController::stepChecksum()`'s per-file checksum: whitespace (including newlines/tabs)
	 * is collapsed to a single space before hashing, matching how the checksums were generated upstream.
	 */
	public static function checksumOf(string $content): string
	{
		return hash('sha256', preg_replace('#[\n\r\t\s\v]+#ms', ' ', $content));
	}
}
