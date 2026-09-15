<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Tool\Panopticon;

defined('_JEXEC') || die;

use Akeeba\Component\Mcp4j\Administrator\Bridge\Support\ItemNormalizer;
use Akeeba\Component\Mcp4j\Administrator\Bridge\Support\OutputShaper;
use Akeeba\Component\Mcp4j\Administrator\Bridge\Support\Pagination;
use Akeeba\Component\Mcp4j\Administrator\Mapper\Panopticon\CoreUpdateMapper;
use Akeeba\Component\Mcp4j\Administrator\Mapper\Panopticon\ExtensionMapper;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\McpTool;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\Schema;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\ToolRequirements;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Exception\ToolException;
use Akeeba\Component\Mcp4j\Administrator\Shared\Panopticon\AbstractPanopticonTool;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Installer\Installer;
use Joomla\Filesystem\File;
use Joomla\Http\HttpFactory;

/**
 * MCP tools for the Akeeba Panopticon Connector's extension list/read/install.
 *
 * Ported from the original `joomla-mcp-php` project's `src/Server/Panopticon/Extensions.php` — tool
 * names/descriptions, parameters and annotations are copied verbatim from there (see
 * `.plans/00-conventions.md` §2); every method body is reimplemented against the connector's own
 * `Extensions`/`Extension` Api models and, for `installExtension()`, a direct port of
 * `ExtensionsController::install()`'s `POST` (URL) branch — see `.plans/14-panopticon-core.md`.
 *
 * @since  0.0.1
 */
#[ToolRequirements(components: ['com_panopticon'])]
class Extensions extends AbstractPanopticonTool
{
	private const INSTALLER_OPTION = 'com_installer';

	#[McpTool(
		name: 'panopticon_extensions_list',
		description: 'List installed extensions',
		readOnlyHint: true,
	)]
	public function listExtensions(
		#[Schema(description: 'Search filter for extensions')]
		?string $filterSearch = null,
		#[Schema(description: 'Filter by extension type')]
		?string $filterType = null,
		#[Schema(description: 'Maximum number of items to return per page', minimum: 1)]
		?int $pageLimit = null,
		#[Schema(description: 'Starting record offset for pagination (0-based)', minimum: 0)]
		?int $pageOffset = null
	): array
	{
		$this->requireSuperUser();
		$this->requireAuthorised('core.manage', self::INSTALLER_OPTION);

		[$limit, $offset] = Pagination::normalise($pageLimit, $pageOffset);

		/** @var \Akeeba\Component\Panopticon\Api\Model\ExtensionsModel $model */
		$model = $this->createPanopticonModel('Extensions');
		$model->setState('filter.protected', '0');
		$model->setState('filter.updatable', '');
		$model->setState('filter.core', '');
		$model->setState('filter.id', '');
		$model->setState('filter.force', false);
		$model->setState('filter.timeout', null);
		$model->setState('list.start', 0);
		$model->setState('list.limit', 0);

		// Deliberately NOT calling getTotal(): the connector's own ExtensionsModel::_getListCount() simply
		// re-runs _getList(), which refreshes update information over the network — a second, redundant,
		// slow round trip. Fetch once, filter/paginate here instead (see ExtensionMapper::filterAndPaginate()).
		$items = $model->getItems();
		$all   = array_map([ItemNormalizer::class, 'toArray'], is_array($items) ? $items : []);

		$page = ExtensionMapper::filterAndPaginate($all, $filterSearch, $filterType, $limit, $offset);

		return [
			'items'      => OutputShaper::shapeList(
				array_map([ExtensionMapper::class, 'sanitise'], $page['items']),
				ExtensionMapper::LIST_FIELDS,
				ExtensionMapper::INT_FIELDS
			),
			'pagination' => ['total' => $page['total'], 'limit' => $limit, 'offset' => $offset],
		];
	}

	#[McpTool(
		name: 'panopticon_extensions_read',
		description: 'Read details of a specific extension',
		readOnlyHint: true,
	)]
	public function readExtension(
		#[Schema(description: 'The ID of the extension to retrieve')]
		int $id
	): array
	{
		$this->requireSuperUser();
		$this->requireAuthorised('core.manage', self::INSTALLER_OPTION);

		// ExtensionModel::getItem() internally creates its own ExtensionsModel without ignore_request, so
		// its populateState() genuinely runs — see AbstractPanopticonTool::ensureSessionRegistryIsObject().
		$this->ensureSessionRegistryIsObject();

		/** @var \Akeeba\Component\Panopticon\Api\Model\ExtensionModel $model */
		$model = $this->createPanopticonModel('Extension');
		$item  = $model->getItem($id);

		if (empty($item))
		{
			throw new ToolException(sprintf('Not found: extension #%d', $id));
		}

		return $this->shape(
			ExtensionMapper::sanitise(ItemNormalizer::toArray($item)),
			ExtensionMapper::ITEM_FIELDS,
			ExtensionMapper::INT_FIELDS
		);
	}

	#[McpTool(
		name: 'panopticon_extensions_install',
		description: 'Install an extension from a URL'
	)]
	public function installExtension(
		#[Schema(description: 'Package URL to install')]
		string $url,
		#[Schema(description: 'Install type')]
		?string $type = null
	): array
	{
		// $type is accepted (to keep the original tool's signature) but ignored: the connector's own
		// install() never used it either — Joomla's installer detects the extension type from the package.
		unset($type);

		$this->requireSuperUser();
		$this->requireAuthorised('core.manage', self::INSTALLER_OPTION);

		if (!ComponentHelper::getParams('com_panopticon')->get('allow_remote_install', 1))
		{
			throw new ToolException('Remote extension installation is disabled on this site.');
		}

		$url = trim($url);

		if (!CoreUpdateMapper::isHttpUrl($url))
		{
			throw new ToolException('You must provide an http(s) URL to download the package.');
		}

		$tmpPath = (string) $this->getApplication()->get('tmp_path');

		if ($tmpPath === '' || !is_dir($tmpPath) || !is_writable($tmpPath))
		{
			throw new ToolException('The Joomla temporary directory is not writable.');
		}

		$packageFile = null;
		$extractDir  = null;

		try
		{
			$packageFile = $this->downloadPackage($url, $tmpPath);

			$extractDir = rtrim($tmpPath, '/\\') . '/install_' . bin2hex(random_bytes(8));

			if (!@mkdir($extractDir, 0755, true) && !is_dir($extractDir))
			{
				throw new ToolException('Failed to create extraction directory.');
			}

			$zip = new \ZipArchive();

			if ($zip->open($packageFile) !== true)
			{
				throw new ToolException('Failed to open the extension package.');
			}

			if (!$zip->extractTo($extractDir))
			{
				$zip->close();

				throw new ToolException('Failed to extract the extension package.');
			}

			$zip->close();

			$installed = Installer::getInstance()->install($extractDir);

			return ['status' => (bool) $installed, 'messages' => $this->takeMessages()];
		}
		finally
		{
			if ($extractDir !== null && is_dir($extractDir))
			{
				$this->deleteDirectoryRecursive($extractDir);
			}

			if ($packageFile !== null && is_file($packageFile))
			{
				@unlink($packageFile);
			}
		}
	}

	private function downloadPackage(string $url, string $tmpPath): string
	{
		try
		{
			$response = (new HttpFactory())->getHttp()->get($url, [], 120);
		}
		catch (\Throwable $e)
		{
			throw new ToolException('Unable to download the package file.');
		}

		$code = version_compare(JVERSION, '5.4.0', 'lt') ? (int) $response->code : (int) $response->getStatusCode();

		if ($code < 200 || $code >= 300)
		{
			throw new ToolException('Unable to download the package file.');
		}

		$pathPart = (string) (parse_url($url, PHP_URL_PATH) ?: '');
		$filename = File::makeSafe(basename($pathPart));
		$filename = $filename !== '' ? $filename : 'package.zip';

		$path = rtrim($tmpPath, '/\\') . '/' . $filename;

		if (file_exists($path))
		{
			$path .= '-' . bin2hex(random_bytes(4));
		}

		$body = version_compare(JVERSION, '5.4.0', 'lt') ? (string) $response->body : (string) $response->getBody();

		if (!File::write($path, $body))
		{
			throw new ToolException('Unable to write the downloaded package file.');
		}

		return $path;
	}

	private function deleteDirectoryRecursive(string $dir): void
	{
		if (!is_dir($dir))
		{
			return;
		}

		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ($items as $item)
		{
			$item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
		}

		@rmdir($dir);
	}
}
