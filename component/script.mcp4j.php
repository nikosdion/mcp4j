<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

// Protect from unauthorized access
defined('_JEXEC') or die();

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerScript;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

class Pkg_Mcp4jInstallerScript extends InstallerScript
{
	protected $minimumPhp = '8.1.0';

	protected $maximumPhp = '8.7';

	protected $minimumJoomla = '4.0.0';

	protected $maximumJoomla = '6.3';

	protected $allowDowngrades = true;

	public function preflight($type, $parent)
	{
		if (!parent::preflight($type, $parent))
		{
			return false;
		}

		// Check for the maximum PHP version before continuing
		$maxPhp = !empty($this->maximumPhp) ? trim($this->maximumPhp) : null;

		if (!empty($maxPhp) && version_compare(PHP_VERSION, $maxPhp, 'ge'))
		{
			Log::add(
				sprintf('This extension supports PHP versions lower than %s. Your server has a newer PHP version (%s) which has not been tested with it. The installation cannot proceed.', $maxPhp, PHP_VERSION),
				Log::WARNING,
				'jerror'
			);

			return false;
		}

		// Check for the maximum Joomla version before continuing
		$maxJoomla = !empty($this->maximumJoomla) ? trim($this->maximumJoomla) : null;

		if (!empty($maxJoomla) && version_compare(JVERSION, $maxJoomla, 'ge'))
		{
			Log::add(
				sprintf('This extension supports Joomla! versions lower than %s. Your site has a newer Joomla! version (%s) which has not been tested with it. The installation cannot proceed.', $maxJoomla, JVERSION),
				Log::WARNING,
				'jerror'
			);

			return false;
		}

		return true;
	}

	function postflight($type, $parent)
	{
		// Do not run on uninstall.
		if ($type === 'uninstall')
		{
			return true;
		}

		// Both plugins do nothing while disabled — plg_webservices_mcp4j not being enabled means the whole
		// MCP endpoint 404s, and there'd be no obvious reason why to someone who just installed the package.
		$this->enablePlugins(['webservices' => 'mcp4j', 'task' => 'mcp4j']);

		// Forcibly create the autoload_psr4.php file afresh.
		if (class_exists(JNamespacePsr4Map::class))
		{
			try
			{
				$nsMap = new JNamespacePsr4Map();

				@clearstatcache(JPATH_CACHE . '/autoload_psr4.php');

				if (function_exists('opcache_invalidate'))
				{
					@opcache_invalidate(JPATH_CACHE . '/autoload_psr4.php');
				}

				@clearstatcache(JPATH_CACHE . '/autoload_psr4.php');
				$nsMap->create();

				if (function_exists('opcache_invalidate'))
				{
					@opcache_invalidate(JPATH_CACHE . '/autoload_psr4.php');
				}

				$nsMap->load();
			}
			catch (\Throwable $e)
			{
				// In case of failure, just try to delete the old autoload_psr4.php file
				if (function_exists('opcache_invalidate'))
				{
					@opcache_invalidate(JPATH_CACHE . '/autoload_psr4.php');
				}

				@unlink(JPATH_CACHE . '/autoload_psr4.php');
				@clearstatcache(JPATH_CACHE . '/autoload_psr4.php');
			}
		}

		$this->invalidateFiles();

		return true;
	}

	/**
	 * Enables plugins by folder => element, e.g. ['webservices' => 'mcp4j'].
	 *
	 * Joomla installs plugins disabled by default. Without this, a fresh install of this package would
	 * leave plg_webservices_mcp4j disabled and the entire MCP endpoint 404ing, with no obvious reason
	 * why to someone who just installed the package.
	 */
	private function enablePlugins(array $foldersByElement): void
	{
		if (empty($foldersByElement))
		{
			return;
		}

		$db = Factory::getContainer()->get(DatabaseInterface::class);

		foreach ($foldersByElement as $folder => $element)
		{
			// security.md I4: $folder/$element are bound directly from the loop variable, which is
			// only safe because this query is built AND executed within the same iteration, before the
			// next iteration reassigns them (see CLAUDE.md's bind()-in-a-loop-aliases-the-same-variable
			// pitfall). If this loop is ever refactored to batch or defer execution (e.g. collecting
			// queries to run after the loop), switch to a per-key bind-variable array first.
			$query = $db->getQuery(true)
				->update($db->quoteName('#__extensions'))
				->set($db->quoteName('enabled') . ' = 1')
				->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
				->where($db->quoteName('folder') . ' = :folder')
				->where($db->quoteName('element') . ' = :element')
				->bind(':folder', $folder, ParameterType::STRING)
				->bind(':element', $element, ParameterType::STRING);

			try
			{
				$db->setQuery($query)->execute();
			}
			catch (\Throwable $e)
			{
				// Not fatal: the site owner can still enable the plugin by hand from the Plugins manager.
			}
		}
	}

	private function invalidateFiles()
	{
		$extensionsFromPackage = $this->invF_getExtensionsFromManifest($this->invF_getManifestXML(__CLASS__));

		foreach ($extensionsFromPackage as $element)
		{
			$paths = [];

			if (strpos($element, 'plg_') === 0)
			{
				[$dummy, $folder, $plugin] = explode('_', $element);

				$paths = [
					sprintf('%s/%s/%s/services', JPATH_PLUGINS, $folder, $plugin),
					sprintf('%s/%s/%s/src', JPATH_PLUGINS, $folder, $plugin),
				];
			}
			elseif (strpos($element, 'com_') === 0)
			{
				$paths = [
					sprintf('%s/components/%s/services', JPATH_ADMINISTRATOR, $element),
					sprintf('%s/components/%s/src', JPATH_ADMINISTRATOR, $element),
					sprintf('%s/components/%s/src', JPATH_SITE, $element),
					sprintf('%s/components/%s/src', JPATH_API, $element),
				];
			}
			else
			{
				continue;
			}

			foreach ($paths as $path)
			{
				$this->invF_recursiveClearCache($path);
			}
		}

		$this->invF_clearFileInOPCache(JPATH_CACHE . '/autoload_psr4.php');
	}

	private function invF_getManifestXML($class): ?SimpleXMLElement
	{
		// Get the package element name
		$myPackage = strtolower(str_replace('InstallerScript', '', $class));

		// Get the package's manifest file
		$filePath = JPATH_MANIFESTS . '/packages/' . $myPackage . '.xml';

		if (!@file_exists($filePath) || !@is_readable($filePath))
		{
			return null;
		}

		$xmlContent = @file_get_contents($filePath);

		if (empty($xmlContent))
		{
			return null;
		}

		return new SimpleXMLElement($xmlContent);
	}

	private function invF_xmlNodeToExtensionName(SimpleXMLElement $fileField): ?string
	{
		$type = (string) $fileField->attributes()->type;
		$id   = (string) $fileField->attributes()->id;

		switch ($type)
		{
			case 'component':
			case 'file':
			case 'library':
				$extension = $id;
				break;

			case 'plugin':
				$group     = (string) $fileField->attributes()->group ?? 'system';
				$extension = 'plg_' . $group . '_' . $id;
				break;

			case 'module':
				$client    = (string) $fileField->attributes()->client ?? 'site';
				$extension = (($client != 'site') ? 'a' : '') . $id;
				break;

			default:
				$extension = null;
				break;
		}

		return $extension;
	}

	private function invF_getExtensionsFromManifest(?SimpleXMLElement $xml): array
	{
		if (empty($xml))
		{
			return [];
		}

		$extensions = [];

		foreach ($xml->xpath('//files/file') as $fileField)
		{
			$extensions[] = $this->invF_xmlNodeToExtensionName($fileField);
		}

		return array_filter($extensions);
	}

	private function invF_clearFileInOPCache(string $file): bool
	{
		static $hasOpCache = null;

		if (is_null($hasOpCache))
		{
			$hasOpCache = ini_get('opcache.enable')
			              && function_exists('opcache_invalidate')
			              && (!ini_get('opcache.restrict_api')
			                  || stripos(
				                     realpath($_SERVER['SCRIPT_FILENAME']), ini_get('opcache.restrict_api')
			                     ) === 0);
		}

		if ($hasOpCache && (strtolower(substr($file, -4)) === '.php'))
		{
			$ret = opcache_invalidate($file, true);

			@clearstatcache($file);

			return $ret;
		}

		return false;
	}

	private function invF_recursiveClearCache(string $path): void
	{
		if (!@is_dir($path))
		{
			return;
		}

		/** @var DirectoryIterator $file */
		foreach (new DirectoryIterator($path) as $file)
		{
			if ($file->isDot() || $file->isLink())
			{
				continue;
			}

			if ($file->isDir())
			{
				$this->invF_recursiveClearCache($file->getPathname());

				continue;
			}

			if (!$file->isFile())
			{
				continue;
			}

			$this->invF_clearFileInOPCache($file->getPathname());
		}
	}
}
