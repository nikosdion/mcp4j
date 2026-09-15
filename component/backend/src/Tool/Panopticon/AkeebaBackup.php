<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Tool\Panopticon;

defined('_JEXEC') || die;

use Akeeba\Component\Mcp4j\Administrator\Mapper\Panopticon\AkeebaBackupInfoMapper;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\McpTool;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\ToolRequirements;
use Akeeba\Component\Mcp4j\Administrator\Shared\Panopticon\AbstractPanopticonTool;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\ParameterType;

/**
 * MCP tool reporting Akeeba Backup's installation/API status — a **genuinely read-only** reimplementation
 * of the connector's `BackupModel::getVersion()`.
 *
 * Ported from the original `joomla-mcp-php` project's `src/Server/Panopticon/AkeebaBackup.php` — tool
 * name/description/annotations are copied verbatim from there (see `.plans/00-conventions.md` §2). The
 * connector's own `BackupModel::getVersion()` is deliberately NOT called: despite the original tool's
 * `readOnlyHint: true`, it *writes* — it enables Akeeba Backup's frontend JSON API and Web Services plugin
 * and, if none exists yet, **creates and returns a Secret Word**, a credential granting full backup (and
 * therefore full site and database) download control. See {@see AkeebaBackupInfoMapper} and
 * `.plans/14-panopticon-core.md`.
 *
 * @since  0.0.1
 */
#[ToolRequirements(components: ['com_panopticon'])]
class AkeebaBackup extends AbstractPanopticonTool
{
	#[McpTool(
		name: 'panopticon_akeebabackup_info',
		description: 'Get Akeeba Backup information and status',
		readOnlyHint: true,
	)]
	public function info(): array
	{
		$this->requireSuperUser();
		$this->requireAuthorised('core.manage', 'com_installer');

		$hasAkeebaBackup = $this->isEnabledSafely('com_akeebabackup');
		$hasAkeeba       = $this->isEnabledSafely('com_akeeba');

		$component = AkeebaBackupInfoMapper::componentElement($hasAkeebaBackup, $hasAkeeba);

		if ($component === null)
		{
			return [
				'installed'     => false,
				'component'     => null,
				'version'       => '0.0.0',
				'api'           => null,
				'endpoints'     => [],
				'hasSecretWord' => false,
			];
		}

		$version = $this->resolveVersion($component);
		$api     = AkeebaBackupInfoMapper::apiLevel($this->apiCandidateFiles($component));

		$apiBaseUri = rtrim(Uri::base(), '/') . '/';
		$endpoints  = AkeebaBackupInfoMapper::endpoints($api, $apiBaseUri, $component);

		$hasSecretWord = (string) ComponentHelper::getParams($component)->get('frontend_secret_word', '') !== '';

		return [
			'installed'     => true,
			'component'     => $component,
			'version'       => $version,
			'api'           => $api,
			'endpoints'     => $endpoints,
			'hasSecretWord' => $hasSecretWord,
		];
	}

	private function isEnabledSafely(string $option): bool
	{
		try
		{
			return ComponentHelper::isEnabled($option);
		}
		catch (\Throwable $e)
		{
			return false;
		}
	}

	private function resolveVersion(string $component): string
	{
		$manifestPath = JPATH_ADMINISTRATOR . '/components/' . $component . '/' . substr($component, 4) . '.xml';

		if (is_file($manifestPath) && is_readable($manifestPath))
		{
			$xml = @file_get_contents($manifestPath);

			if ($xml !== false)
			{
				$version = AkeebaBackupInfoMapper::versionFromManifestXml($xml);

				if ($version !== null)
				{
					return $version;
				}
			}
		}

		$db          = $this->getDatabase();
		$elementName = $component;
		$query       = $db->createQuery()
			->select($db->quoteName('manifest_cache'))
			->from($db->quoteName('#__extensions'))
			->where($db->quoteName('element') . ' = :element')
			->where($db->quoteName('type') . ' = ' . $db->quote('component'))
			->bind(':element', $elementName, ParameterType::STRING);

		$manifestCache = (string) ($db->setQuery($query)->loadResult() ?? '');

		if ($manifestCache !== '')
		{
			$version = AkeebaBackupInfoMapper::versionFromManifestCache($manifestCache);

			if ($version !== null)
			{
				return $version;
			}
		}

		return '0.0.0';
	}

	/**
	 * The five candidate paths `BackupModel::getMaxApiVersion()` checks, in the same order, mapped to
	 * whether each one exists.
	 *
	 * @return  array<string, bool>
	 */
	private function apiCandidateFiles(string $component): array
	{
		$apiPrefix = rtrim(JPATH_API, '/\\') . '/components/' . $component;
		$sitePrefix = rtrim(JPATH_SITE, '/\\') . '/components/' . $component;

		$candidates = [
			$apiPrefix . '/src/Controller/ApiController.php',
			$sitePrefix . '/src/Controller/ApiController.php',
			$sitePrefix . '/Controller/Api.php',
			$sitePrefix . '/Controller/Json.php',
			$sitePrefix . '/controllers/json.php',
		];

		$existing = [];

		foreach ($candidates as $path)
		{
			$existing[$path] = @is_file($path) && is_readable($path);
		}

		return $existing;
	}
}
