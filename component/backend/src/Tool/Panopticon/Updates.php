<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Tool\Panopticon;

defined('_JEXEC') || die;

use Akeeba\Component\Mcp4j\Administrator\Mapper\Panopticon\CoreUpdateMapper;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\McpTool;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\Schema;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\ToolRequirements;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Exception\ToolException;
use Akeeba\Component\Mcp4j\Administrator\Shared\Panopticon\AbstractPanopticonTool;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Installer\Installer;
use Joomla\CMS\Updater\Updater;

/**
 * MCP tools for refreshing and applying Joomla extension updates, driven by `com_installer`'s own models
 * (the connector's `Updates` Api tasks are thin wrappers around them).
 *
 * Ported from the original `joomla-mcp-php` project's `src/Server/Panopticon/Updates.php` — tool
 * names/descriptions, parameters and annotations are copied verbatim from there (see
 * `.plans/00-conventions.md` §2); every method body is a from-scratch reimplementation of
 * `UpdatesController::refresh()`/`update()` — see `.plans/14-panopticon-core.md`.
 *
 * @since  0.0.1
 */
#[ToolRequirements(components: ['com_panopticon'])]
class Updates extends AbstractPanopticonTool
{
	private const INSTALLER_OPTION = 'com_installer';

	#[McpTool(
		name: 'panopticon_updates_refresh',
		description: 'Refresh available extension updates'
	)]
	public function refreshUpdates(): array
	{
		$this->requireSuperUser();
		$this->requireAuthorised('core.manage', self::INSTALLER_OPTION);

		$params       = ComponentHelper::getParams(self::INSTALLER_OPTION);
		$cacheTimeout = 3600 * (int) $params->get('cachetimeout', 6);
		$minStability = (int) $params->get('minimum_stability', Updater::STABILITY_STABLE);

		/** @var \Joomla\Component\Installer\Administrator\Model\UpdateModel $model */
		$model = $this->createInstallerModel('Update');
		$model->findUpdates(0, $cacheTimeout, $minStability);

		return ['success' => true, 'messages' => $this->takeMessages()];
	}

	#[McpTool(
		name: 'panopticon_updates_apply',
		description: 'Apply pending extension updates'
	)]
	public function applyUpdates(
		#[Schema(description: 'Extension ID to update')]
		?string $eid = null
	): array
	{
		$this->requireSuperUser();
		$this->requireAuthorised('core.manage', self::INSTALLER_OPTION);

		$ids = CoreUpdateMapper::parseExtensionIds($eid);

		if ($ids === [])
		{
			throw new ToolException('No extensions to update');
		}

		$params       = ComponentHelper::getParams(self::INSTALLER_OPTION);
		$minStability = (int) $params->get('minimum_stability', Updater::STABILITY_STABLE);

		$results = [];

		foreach ($ids as $id)
		{
			// The Installer class caches an installer adapter instance per (type, client, folder, element)
			// the first time one is created in a request — updating several extensions in the same tool
			// call would otherwise reuse a stale adapter for the second and later extensions. No
			// setAccessible() (deprecated/unnecessary since PHP 8.1) and the two-argument setValue() form
			// (the one-argument form is deprecated since PHP 8.3) — see .plans/14-panopticon-core.md.
			if (property_exists(Installer::class, 'instances'))
			{
				$instancesProperty = new \ReflectionProperty(Installer::class, 'instances');
				$instancesProperty->setValue(null, []);
			}

			/** @var \Joomla\Component\Installer\Administrator\Model\UpdateModel $model */
			$model = $this->createInstallerModel('Update');
			$model->setState('filter.extension_id', $id);
			$model->setState('filter.search', '');
			$model->setState('filter.client_id', '');
			$model->setState('filter.type', '');
			$model->setState('filter.folder', '');
			$model->setState('list.start', 0);
			$model->setState('list.limit', 0);

			$updates = $model->getItems();
			$updates = is_array($updates) ? $updates : [];

			if ($updates === [])
			{
				$this->getApplication()->enqueueMessage('No updates', 'warning');

				$results[] = ['id' => $id, 'status' => false, 'messages' => $this->takeMessages()];

				continue;
			}

			$update = array_pop($updates);
			$model->update([$update->update_id], $minStability);

			$results[] = [
				'id'       => $id,
				'status'   => (bool) $model->getState('result'),
				'messages' => $this->takeMessages(),
			];
		}

		return ['results' => $results];
	}
}
