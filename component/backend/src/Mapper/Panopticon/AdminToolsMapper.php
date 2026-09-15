<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Mapper\Panopticon;

defined('_JEXEC') || die;

/**
 * Pure input/output mapping for the Panopticon Connector's Admin Tools tools
 * ({@see \Akeeba\Component\Mcp4j\Administrator\Tool\Panopticon\AdminTools}).
 *
 * The `*_FIELDS` constants are copied verbatim from the connector's
 * `component/api/src/View/Admintools/JsonapiView.php` (`$fieldsToRenderList`, `$scanAlertsFieldsToRenderList`,
 * `$fieldsToRenderItem`). See `.plans/15-panopticon-admintools.md`.
 *
 * Pure: no Joomla.
 *
 * @since  0.0.1
 */
final class AdminToolsMapper
{
	public const SCAN_FIELDS = [
		'id',
		'comment',
		'scanstart',
		'scanend',
		'status',
		'origin',
		'totalfiles',
		'files_new',
		'files_modified',
		'files_suspicious',
	];

	public const SCAN_INT_FIELDS = ['id', 'totalfiles', 'files_new', 'files_modified', 'files_suspicious'];

	// Deliberately does NOT include "diff" — a scan alert's diff can contain file contents and must never
	// appear in a *list* of alerts, only when a single alert is read (see ALERT_ITEM_FIELDS below and the
	// plan's "Sensitive output" section).
	public const ALERT_LIST_FIELDS = [
		'id',
		'newfile',
		'suspicious',
		'filestatus',
		'threatindex',
		'path',
		'threat_score',
		'acknowledged',
		'scan_id',
		'scandate',
	];

	public const ALERT_ITEM_FIELDS = ['id', 'path', 'scan_id', 'diff', 'threat_score', 'acknowledged'];

	public const ALERT_INT_FIELDS = ['id', 'newfile', 'suspicious', 'threat_score', 'acknowledged', 'scan_id'];

	public const PASSWORD_ALPHABET = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789`~!@#$%^&*()-_=+[{]};:\'"<,>.?/';

	public const USERNAME_ALPHABET = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

	/**
	 * @return  array<string, mixed>
	 */
	public static function scansListState(): array
	{
		return [
			'filter.search'  => '',
			'filter.since'   => '',
			'filter.until'   => '',
			'filter.status'  => '',
			'list.ordering'  => 'id',
			'list.direction' => 'desc',
		];
	}

	/**
	 * @return  array<string, mixed>
	 */
	public static function scanByIdState(int $id): array
	{
		return array_merge(self::scansListState(), ['filter.search' => 'id:' . $id]);
	}

	/**
	 * @return  array<string, mixed>
	 */
	public static function alertsState(int $scanId): array
	{
		return [
			'filter.search'       => '',
			'filter.status'       => '',
			'filter.scan_id'      => $scanId,
			'filter.acknowledged' => '',
			'filter.flag'         => '',
			'list.ordering'       => 'path',
			'list.direction'      => 'asc',
		];
	}

	/**
	 * Whitelists `ScansModel::startScan()`/`stepScan()`'s return array down to the fields an MCP client
	 * needs, dropping everything else — in particular the connector's own `session` member (the scanner
	 * engine's session-queue state, which in MCP4j lives server-side in the Joomla session and must never
	 * be echoed back to the caller; see the plan's "Sensitive output" section).
	 *
	 * @param   array<string, mixed>  $statusArray
	 *
	 * @return  array{id: int, status: bool, done: bool, error: string, warnings: list<mixed>}
	 */
	public static function scannerResult(array $statusArray, ?int $scanId): array
	{
		return [
			'id'       => $scanId ?? 0,
			'status'   => (bool) ($statusArray['status'] ?? false),
			'done'     => (bool) ($statusArray['done'] ?? false),
			'error'    => (string) ($statusArray['error'] ?? ''),
			'warnings' => array_values((array) ($statusArray['warnings'] ?? [])),
		];
	}

	/**
	 * Rename only if the source of the rename actually exists — mirrors the connector's own
	 * `htaccess_disable()`/`htaccess_enable()` guard (attempting `rename()` on a nonexistent source is
	 * always a no-op failure, so it's skipped rather than attempted).
	 */
	public static function shouldRenameHtaccess(bool $sourceExists): bool
	{
		return $sourceExists;
	}

	/**
	 * Port of `AdmintoolsController::htaccess_disable()`'s decision table.
	 *
	 * @param   bool   $htaccessExists   Whether `.htaccess` exists (the rename source).
	 * @param   bool   $backupExists     Whether `.htaccess.admintools` exists (the rename target).
	 * @param   ?bool  $renameSucceeded  The outcome of `rename()`, or null when no rename was attempted
	 *                                   (i.e. `$htaccessExists` was false).
	 *
	 * @return  array{exists: bool, renamed: bool}
	 */
	public static function htaccessDisableResult(bool $htaccessExists, bool $backupExists, ?bool $renameSucceeded): array
	{
		if (!$htaccessExists && $backupExists)
		{
			return ['exists' => false, 'renamed' => true];
		}

		if (!$htaccessExists)
		{
			return ['exists' => false, 'renamed' => false];
		}

		return ['exists' => true, 'renamed' => (bool) $renameSucceeded];
	}

	/**
	 * Port of `AdmintoolsController::htaccess_enable()`'s decision table (mirror of
	 * {@see self::htaccessDisableResult()}).
	 *
	 * @param   bool   $backupExists     Whether `.htaccess.admintools` exists (the rename source).
	 * @param   bool   $htaccessExists   Whether `.htaccess` exists (the rename target).
	 * @param   ?bool  $renameSucceeded  The outcome of `rename()`, or null when no rename was attempted
	 *                                   (i.e. `$backupExists` was false).
	 *
	 * @return  array{exists: bool, restored: bool}
	 */
	public static function htaccessEnableResult(bool $backupExists, bool $htaccessExists, ?bool $renameSucceeded): array
	{
		if (!$backupExists && $htaccessExists)
		{
			return ['exists' => false, 'restored' => true];
		}

		if (!$backupExists)
		{
			return ['exists' => false, 'restored' => false];
		}

		return ['exists' => true, 'restored' => (bool) $renameSucceeded];
	}

	/**
	 * Computes the `expiration` value `TempsuperuserModel::save()` expects: a `Y-m-d H:i:s` string that
	 * `TempsuperuserTable::onBeforeCheck()` will re-interpret in `$timezone` and convert to UTC for
	 * storage — so the string returned here must be the wall-clock time, in `$timezone`, of
	 * `$nowUtc + $seconds`.
	 *
	 * `$seconds` is the tool's own contract (its description has always said "seconds"), clamped to a
	 * sane 1 hour .. 7 day window regardless of what the caller asks for — see the plan's "Deviations"
	 * section for why (the connector's own date handling was broken and always produced one week).
	 */
	public static function tempSuperUserExpiration(?int $seconds, \DateTimeImmutable $nowUtc, string $timezone): string
	{
		$seconds = $seconds ?? 604800;
		$seconds = max(3600, min(604800, $seconds));

		$target = $nowUtc->add(new \DateInterval('PT' . $seconds . 'S'));

		try
		{
			$tz = new \DateTimeZone($timezone);
		}
		catch (\Exception $e)
		{
			$tz = new \DateTimeZone('UTC');
		}

		return $target->setTimezone($tz)->format('Y-m-d H:i:s');
	}

	/**
	 * Builds a random string of `$length` characters drawn from `$alphabet`, via an injected
	 * `$randomInt(int $min, int $max): int` — pass `'random_int'` in production, a deterministic stub in
	 * tests. Same shift-free character-at-a-time approach as the connector's own `genRandomPassword()`,
	 * simplified since we don't need its cryptographic-shift trick to get a unit-testable, evenly
	 * distributed result.
	 */
	public static function randomString(int $length, string $alphabet, callable $randomInt): string
	{
		$alphabetLength = strlen($alphabet);
		$result         = '';

		for ($i = 0; $i < $length; $i++)
		{
			$result .= $alphabet[$randomInt(0, $alphabetLength - 1)];
		}

		return $result;
	}
}
