<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Mapper\Panopticon;

defined('_JEXEC') || die;

/**
 * Pure helpers for a read-only reimplementation of the Panopticon Connector's `component/api/src/Model/
 * BackupModel.php::getVersion()` — deliberately NOT calling that method, because despite the original
 * `joomla-mcp-php` tool's `readOnlyHint: true`, it *writes*: it enables Akeeba Backup's frontend JSON API
 * and Web Services plugin and, if none exists yet, creates and returns a Secret Word — a credential
 * granting full backup control. See `.plans/14-panopticon-core.md`, `AkeebaBackup.php`.
 *
 * Pure: no Joomla.
 *
 * @since  0.0.1
 */
final class AkeebaBackupInfoMapper
{
	/**
	 * Port of `BackupModel::getComponentElement()`: Akeeba Backup 9+ (`com_akeebabackup`) takes precedence
	 * over the legacy `com_akeeba`; `null` when neither is enabled.
	 */
	public static function componentElement(bool $hasAkeebaBackup, bool $hasAkeeba): ?string
	{
		if ($hasAkeebaBackup)
		{
			return 'com_akeebabackup';
		}

		if ($hasAkeeba)
		{
			return 'com_akeeba';
		}

		return null;
	}

	/**
	 * Port of `BackupModel::getVersionFromManifest()`'s regular expression.
	 */
	public static function versionFromManifestXml(string $xml): ?string
	{
		if (preg_match('#<version>\s*([\d.]+)\s*</version>#is', $xml, $matches) !== 1)
		{
			return null;
		}

		return $matches[1] !== '' ? $matches[1] : null;
	}

	/**
	 * Port of `BackupModel::getVersionFromExtensionsTable()`'s JSON decode of `#__extensions.manifest_cache`.
	 */
	public static function versionFromManifestCache(string $json): ?string
	{
		$decoded = json_decode($json, true);

		if (!is_array($decoded) || empty($decoded['version']))
		{
			return null;
		}

		return (string) $decoded['version'];
	}

	/**
	 * Port of `BackupModel::getMaxApiVersion()`: the caller passes the same five candidate paths (in the
	 * same order) mapped to whether each one exists; this returns the level of the first one that does.
	 *
	 * @param   array<string, bool>  $existingFiles  Ordered `[path => exists]`, matching
	 *                                                `BackupModel::getMaxApiVersion()`'s own five paths
	 *                                                (api ApiController, site ApiController, site Api,
	 *                                                site Json, site json.php) in that order.
	 */
	public static function apiLevel(array $existingFiles): ?string
	{
		$levels = ['3', '2', '2', '1', '1'];
		$index  = 0;

		foreach ($existingFiles as $exists)
		{
			$level = $levels[$index] ?? null;
			$index++;

			if ($exists)
			{
				return $level;
			}
		}

		return null;
	}

	/**
	 * Port of `BackupModel::getEndpoints()`. `$apiBaseUri` must end in `api/` (the API application's own
	 * base URI); the "root" URI used for the legacy v1/v2 `index.php?option=...` endpoints is derived by
	 * stripping that trailing `api/` off, exactly like the connector does.
	 *
	 * @return  array<string, list<string>>
	 */
	public static function endpoints(?string $maxApi, string $apiBaseUri, string $component): array
	{
		$baseUri = rtrim($apiBaseUri, '/') . '/';
		$rootUri = substr($baseUri, 0, -4);
		$level   = $maxApi === null ? 0 : (int) $maxApi;

		if ($level >= 3)
		{
			return [
				'v3' => [$baseUri . 'index.php/v3/akeebabackup'],
				'v2' => [
					$baseUri . 'index.php/v2/akeebabackup/index.php',
					$rootUri . sprintf('index.php?option=%s&view=Api&format=raw', $component),
				],
			];
		}

		if ($level === 2)
		{
			return [
				'v2' => [$rootUri . sprintf('index.php?option=%s&view=Api&format=raw', $component)],
				'v1' => [$rootUri . sprintf('index.php?option=%s&view=json', $component)],
			];
		}

		// Matches the connector's own unconditional fallback — it always reports a v1 endpoint even when
		// the API level could not be determined at all (null) or is already '1'.
		return ['v1' => [$rootUri . sprintf('index.php?option=%s&view=json', $component)]];
	}
}
