<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Tool\Panopticon;

defined('_JEXEC') || die;

use Akeeba\Component\Mcp4j\Administrator\Bridge\Support\ItemNormalizer;
use Akeeba\Component\Mcp4j\Administrator\Mapper\Panopticon\CoreUpdateMapper;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\McpTool;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\Schema;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\ToolRequirements;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Exception\ToolException;
use Akeeba\Component\Mcp4j\Administrator\Shared\Panopticon\AbstractPanopticonTool;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\Database\ParameterType;
use Joomla\Filesystem\Exception\FilesystemException;
use Joomla\Filesystem\Path;
use Joomla\Http\HttpFactory;
use Joomla\Registry\Registry;

/**
 * MCP tools driving the Akeeba Panopticon Connector's Joomla core update flow.
 *
 * Ported from the original `joomla-mcp-php` project's `src/Server/Panopticon/CoreUpdate.php` — tool
 * names/descriptions, parameters and annotations are copied verbatim from there (see
 * `.plans/00-conventions.md` §2); every method body is a from-scratch reimplementation against the
 * connector's own `Core` Api model and, where the connector's controller itself holds business logic
 * (`prepareChecksum()`/`stepChecksum()`, `postUpdate()`), a direct port of that controller code — see
 * `.plans/14-panopticon-core.md` for the full rationale and the final report for the list of deviations.
 *
 * **The core update flow cannot be *completed* through MCP alone.** `panopticon_coreupdate_activate`
 * writes `administrator/components/com_joomlaupdate/update.php` (or `restoration.php` on very old Joomla)
 * and returns the extraction password needed to drive
 * `administrator/components/com_joomlaupdate/extract.php`, which MCP4j does not expose — actually
 * extracting the downloaded package is a step only the Joomla backend UI (or a direct HTTP request to
 * `extract.php`) can perform. `panopticon_coreupdate_postupdate` only runs the *after*-extraction
 * finalisation tasks.
 *
 * @since  0.0.1
 */
#[ToolRequirements(components: ['com_panopticon'])]
class CoreUpdate extends AbstractPanopticonTool
{
	private const JOOMLAUPDATE_OPTION = 'com_joomlaupdate';

	#[McpTool(
		name: 'panopticon_coreupdate_status',
		description: 'Get Joomla core update status',
		readOnlyHint: true,
	)]
	public function status(): array
	{
		$this->requireSuperUser();
		$this->requireAuthorised('core.manage', self::JOOMLAUPDATE_OPTION);

		return $this->loadStatus();
	}

	#[McpTool(
		name: 'panopticon_coreupdate_changesource',
		description: 'Change the Joomla core update source'
	)]
	public function changeSource(
		#[Schema(description: 'The update source URL')]
		string $source
	): array
	{
		$this->requireSuperUser();
		$this->requireAuthorised('core.manage', self::JOOMLAUPDATE_OPTION);

		try
		{
			[$src, $url] = CoreUpdateMapper::parseUpdateSource($source, JVERSION);
		}
		catch (\InvalidArgumentException $e)
		{
			throw new ToolException($e->getMessage());
		}

		if ($src === 'custom')
		{
			$this->requireAuthorised('core.admin', self::JOOMLAUPDATE_OPTION);
		}

		/** @var Registry $params */
		$params        = ComponentHelper::getParams(self::JOOMLAUPDATE_OPTION);
		$currentSource = (string) $params->get('updatesource', 'default');
		$currentUrl    = (string) $params->get('customurl', '');

		$needsSave = $currentSource !== $src || ($src === 'custom' && $currentUrl !== $url);

		if ($needsSave)
		{
			$params->set('updatesource', $src);

			if ($src === 'custom')
			{
				$params->set('customurl', $url);
			}

			$this->componentParamsSave(self::JOOMLAUPDATE_OPTION, $params);
		}

		/** @var \Joomla\Component\Joomlaupdate\Administrator\Model\UpdateModel $model */
		$model = $this->createPanopticonModel('Core');

		if (!$model->useAdminToolsResetJoomlaUpdate())
		{
			$model->affirmCoreUpdateRecord();

			if (version_compare(JVERSION, '5.0.0', 'ge'))
			{
				$model->applyUpdateSite($src, $url);
			}
			else
			{
				$model->applyUpdateSite();
			}
		}

		$errors = array_filter($this->takeMessages(), static fn (array $m): bool => $m['type'] === 'error');

		if ($errors !== [])
		{
			throw new ToolException(implode('; ', array_column($errors, 'message')));
		}

		$this->withDeprecationsSuppressed(static fn () => $model->getJoomlaUpdateInfo(true));

		return $this->loadStatus();
	}

	#[McpTool(
		name: 'panopticon_coreupdate_download',
		description: 'Download the Joomla core update package'
	)]
	public function download(): array
	{
		$this->requireSuperUser();
		$this->requireAuthorised('core.manage', self::JOOMLAUPDATE_OPTION);

		/** @var \Joomla\Component\Joomlaupdate\Administrator\Model\UpdateModel $model */
		$model  = $this->createPanopticonModel('Core');
		$result = $model->download();

		if (!is_array($result))
		{
			throw new ToolException('Download failed.');
		}

		$this->sessionSet('coredownload', ['basename' => $result['basename'] ?? null, 'done' => true]);

		$result['messages'] = $this->takeMessages();

		return $result;
	}

	#[McpTool(
		name: 'panopticon_coreupdate_download_chunked',
		description: 'Download the Joomla core update package in chunks'
	)]
	public function downloadChunked(
		#[Schema(description: 'Byte offset to start downloading from')]
		?int $offset = null,
		#[Schema(description: 'Number of bytes to download')]
		?int $length = null
	): array
	{
		// $length is accepted (to keep the original tool's signature — see .plans/00-conventions.md §2) but
		// ignored: the connector's chunked downloader adapts its own chunk size and never accepted a
		// caller-supplied length in the first place.
		unset($length);

		$this->requireSuperUser();
		$this->requireAuthorised('core.manage', self::JOOMLAUPDATE_OPTION);

		$previous = $this->sessionGet('coredownload');
		$previous = is_array($previous) ? $previous : null;

		if ($previous !== null && !empty($previous['done'] ?? false))
		{
			$previous = null;
		}

		/** @var \Joomla\Component\Joomlaupdate\Administrator\Model\UpdateModel $model */
		$model = $this->createPanopticonModel('Core');
		$model->setState('download.url', $previous['url'] ?? null);
		$model->setState('download.size', $previous['size'] ?? null);
		$model->setState('download.offset', $offset ?? ($previous['offset'] ?? null));
		$model->setState('download.chunk_index', $previous['chunk_index'] ?? null);
		$model->setState('download.max_time', null);

		$result = $model->downloadChunkedUpdateByPanopticon();
		$result = is_array($result) ? $result : [];

		$this->sessionSet(
			'coredownload',
			array_intersect_key($result, array_flip(['basename', 'url', 'size', 'offset', 'chunk_index', 'done', 'error']))
		);

		return $result;
	}

	#[McpTool(
		name: 'panopticon_coreupdate_activate',
		description: 'Activate the downloaded Joomla core update'
	)]
	public function activate(): array
	{
		$this->requireSuperUser();
		$this->requireAuthorised('core.manage', self::JOOMLAUPDATE_OPTION);

		/** @var \Joomla\Component\Joomlaupdate\Administrator\Model\UpdateModel $model */
		$model  = $this->createPanopticonModel('Core');
		$method = method_exists($model, 'createUpdateFile') ? 'createUpdateFile' : 'createRestorationFile';

		$coredownload = $this->sessionGet('coredownload');
		$basename     = is_array($coredownload) ? ($coredownload['basename'] ?? null) : null;

		try
		{
			$created = $model->{$method}($basename);
		}
		catch (\Throwable $e)
		{
			throw new ToolException($e->getMessage());
		}

		if (!$created)
		{
			throw new ToolException(sprintf(
				'Cannot create the administrator/components/com_joomlaupdate/%s file.',
				version_compare(JVERSION, '4.0.4', 'ge') ? 'update.php' : 'restoration.php'
			));
		}

		return [
			'password' => $model->getState('password'),
			'filesize' => $model->getState('filesize'),
			'file'     => $model->getState('file'),
		];
	}

	#[McpTool(
		name: 'panopticon_coreupdate_disable',
		description: 'Disable the Joomla core update'
	)]
	public function disable(): array
	{
		$this->requireSuperUser();
		$this->requireAuthorised('core.manage', self::JOOMLAUPDATE_OPTION);

		/** @var \Joomla\Component\Joomlaupdate\Administrator\Model\UpdateModel $model */
		$model = $this->createPanopticonModel('Core');
		$model->removeExtractPasswordFile();

		return ['success' => true];
	}

	#[McpTool(
		name: 'panopticon_coreupdate_postupdate',
		description: 'Run post-update tasks for Joomla core update'
	)]
	public function postUpdate(): array
	{
		$this->requireSuperUser();
		$this->requireAuthorised('core.manage', self::JOOMLAUPDATE_OPTION);

		Log::addLogger(
			['format' => "{DATE}\t{TIME}\t{LEVEL}\t{CODE}\t{MESSAGE}", 'text_file' => 'joomla_update.php'],
			Log::INFO,
			['Update', 'databasequery', 'jerror']
		);

		$this->loadLanguage(self::JOOMLAUPDATE_OPTION);

		try
		{
			Log::add(Text::_('COM_JOOMLAUPDATE_UPDATE_LOG_FINALISE'), Log::INFO, 'Update');
		}
		catch (\RuntimeException $e)
		{
			// Informational log only — a failure here must never abort the actual post-update work.
		}

		/** @var \Joomla\Component\Joomlaupdate\Administrator\Model\UpdateModel $model */
		$model = $this->createPanopticonModel('Core');
		$model->finaliseUpgrade();

		$coredownload = $this->sessionGet('coredownload');
		$basename     = is_array($coredownload) ? ($coredownload['basename'] ?? null) : null;

		$this->getApplication()->setUserState('com_joomlaupdate.file', $basename);

		$model->cleanUp();

		$this->sessionSet('coredownload', null);

		return ['success' => true, 'messages' => $this->takeMessages()];
	}

	#[McpTool(
		name: 'panopticon_coreupdate_checksum_prepare',
		description: 'Prepare file checksum verification for core update'
	)]
	public function checksumPrepare(): array
	{
		$this->requireSuperUser();
		$this->requireAuthorised('core.manage', self::JOOMLAUPDATE_OPTION);

		$url = 'https://getpanopticon.com/checksums/joomla/' . JVERSION . '/sha256_squash.json.gz';

		$httpOptions = new Registry();
		$httpOptions->set('transport.curl', [CURLOPT_FOLLOWLOCATION => true]);

		try
		{
			$response = (new HttpFactory())->getHttp($httpOptions)->get($url, [], 30);
		}
		catch (\Throwable $e)
		{
			throw new ToolException(sprintf('Could not download checksums from %s: %s', $url, $e->getMessage()));
		}

		$code = $this->responseStatusCode($response);

		if ($code !== 200)
		{
			throw new ToolException(sprintf('Could not download checksums from %s (HTTP %d)', $url, $code));
		}

		$decompressed = @gzdecode($this->responseBody($response));

		if ($decompressed === false)
		{
			throw new ToolException('Failed to decompress checksums file');
		}

		$checksums = json_decode($decompressed, true);

		if (json_last_error() !== JSON_ERROR_NONE || !is_array($checksums))
		{
			throw new ToolException('Failed to parse checksums JSON: ' . json_last_error_msg());
		}

		$db = $this->getDatabase();
		$db->truncateTable('#__panopticon_coresums');

		$batch = [];

		foreach ($checksums as $path => $sum)
		{
			// No outer parens here — QueryInterface::values() adds them itself per call; wrapping the
			// value list in an extra pair turns each row into a single row-constructor operand and MySQL
			// rejects the INSERT with "Operand should contain 1 column(s)".
			$batch[] = $db->quote((string) $path) . ', ' . $db->quote((string) $sum);

			if (count($batch) >= 100)
			{
				$this->insertChecksumBatch($batch);
				$batch = [];
			}
		}

		if ($batch !== [])
		{
			$this->insertChecksumBatch($batch);
		}

		$this->sessionSet('checksum_last_id', 0);

		return ['success' => true, 'files' => count($checksums)];
	}

	#[McpTool(
		name: 'panopticon_coreupdate_checksum_step',
		description: 'Execute a step of file checksum verification'
	)]
	public function checksumStep(): array
	{
		$this->requireSuperUser();
		$this->requireAuthorised('core.manage', self::JOOMLAUPDATE_OPTION);

		$lastIdState = $this->sessionGet('checksum_last_id');

		if ($lastIdState === null && !$this->hasAnyChecksums())
		{
			throw new ToolException('Run panopticon_coreupdate_checksum_prepare first.');
		}

		$lastId       = (int) ($lastIdState ?? 0);
		$db           = $this->getDatabase();
		$startTime    = microtime(true);
		$invalidFiles = [];
		$done         = false;

		while (true)
		{
			$idBind = $lastId;

			$query = $db->createQuery()
				->select('*')
				->from($db->quoteName('#__panopticon_coresums'))
				->where($db->quoteName('id') . ' > :lastId')
				->order($db->quoteName('id') . ' ASC')
				->bind(':lastId', $idBind, ParameterType::INTEGER);

			$db->setQuery($query, 0, 250);
			$rows = $db->loadObjectList();

			if (empty($rows))
			{
				$done = true;

				break;
			}

			foreach ($rows as $row)
			{
				$lastId = (int) $row->id;

				if (str_starts_with($row->path, 'installation/'))
				{
					continue;
				}

				$actualChecksum = '';

				// security.md L1: $row->path comes from a remote checksums feed (fixed getpanopticon.com
				// URL, not an MCP-caller parameter), but is still hardened defense-in-depth against a
				// compromised/MITM'd feed steering it outside JPATH_ROOT via a `../` path.
				try
				{
					$fullPath = Path::check(JPATH_ROOT . '/' . $row->path);
				}
				catch (FilesystemException $e)
				{
					$invalidFiles[] = $row->path;

					continue;
				}

				if (@is_file($fullPath))
				{
					$content = @file_get_contents($fullPath);

					if ($content !== false)
					{
						$actualChecksum = CoreUpdateMapper::checksumOf($content);
					}
				}

				if ($actualChecksum !== $row->checksum)
				{
					$invalidFiles[] = $row->path;
				}
			}

			if ((microtime(true) - $startTime) >= 2.0)
			{
				break;
			}
		}

		$this->sessionSet('checksum_last_id', $done ? null : $lastId);

		return ['done' => $done, 'last_id' => $lastId, 'invalidFiles' => $invalidFiles];
	}

	/**
	 * Fresh model, fresh state — mirrors the connector's own request-scoped `getItem()`.
	 */
	private function loadStatus(): array
	{
		/** @var \Joomla\Component\Joomlaupdate\Administrator\Model\UpdateModel $model */
		$model = $this->createPanopticonModel('Core');
		$model->setState('panopticon_mode', 'core.update');
		$model->setState('panopticon_force', false);

		// CoreModel::getJoomlaUpdateInfo() (called from getItem()) builds VersionStability values via the
		// connector's vendored z4kn4fein/php-semver library, whose constructor still declares implicitly
		// nullable parameters — an E_DEPRECATED notice on PHP 8.4+ that this suite runs with
		// display_errors=On, so left unsuppressed it gets echoed straight into (and corrupts) the JSON-RPC
		// response body. Not our code to fix (third-party extension) — suppressed narrowly, like
		// Tool/Contact/Contacts.php's own analogous workaround; a genuine warning/error still surfaces.
		$item = $this->withDeprecationsSuppressed(static fn () => $model->getItem());

		if ($item === null || $item === false)
		{
			throw new ToolException('Core update information is unavailable.');
		}

		$normalised = ItemNormalizer::toArray($item);
		$sanitised  = CoreUpdateMapper::sanitiseStatus($normalised);

		return $this->shape($sanitised, CoreUpdateMapper::STATUS_FIELDS);
	}

	private function hasAnyChecksums(): bool
	{
		$db    = $this->getDatabase();
		$query = $db->createQuery()
			->select('1')
			->from($db->quoteName('#__panopticon_coresums'));

		$db->setQuery($query, 0, 1);

		return $db->loadResult() !== null;
	}

	private function insertChecksumBatch(array $rows): void
	{
		$db    = $this->getDatabase();
		$query = $db->createQuery()
			->insert($db->quoteName('#__panopticon_coresums'))
			->columns([$db->quoteName('path'), $db->quoteName('checksum')]);

		foreach ($rows as $row)
		{
			$query->values($row);
		}

		$db->setQuery($query)->execute();
	}

	/**
	 * Port of the connector's `HttpResponseCompatibilityTrait::getResponseStatusCode()`.
	 */
	private function responseStatusCode(object $response): int
	{
		return version_compare(JVERSION, '5.4.0', 'lt') ? (int) $response->code : (int) $response->getStatusCode();
	}

	/**
	 * Port of the connector's `HttpResponseCompatibilityTrait::getResponseBody()`.
	 */
	private function responseBody(object $response): string
	{
		return version_compare(JVERSION, '5.4.0', 'lt') ? (string) $response->body : (string) $response->getBody();
	}

	/**
	 * Runs `$callback` with `E_DEPRECATED` notices intercepted (not silently discarded — the previous
	 * handler is always restored — and only `E_DEPRECATED`, so a genuine warning/error still surfaces
	 * normally). Same pattern as `Tool/Contact/Contacts.php`'s analogous workaround, applied here because
	 * `com_joomlaupdate`'s update-info path runs through the connector's vendored z4kn4fein/php-semver
	 * library, which still declares implicitly-nullable constructor parameters.
	 */
	private function withDeprecationsSuppressed(callable $callback): mixed
	{
		set_error_handler(static fn (int $errno, string $errstr): bool => true, E_DEPRECATED);

		try
		{
			return $callback();
		}
		finally
		{
			restore_error_handler();
		}
	}
}
