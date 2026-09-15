<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Tool\Languages;

defined('_JEXEC') || die;

use Akeeba\Component\Mcp4j\Administrator\Bridge\AbstractTool;
use Akeeba\Component\Mcp4j\Administrator\Bridge\Support\OutputShaper;
use Akeeba\Component\Mcp4j\Administrator\Mapper\Languages\LanguagePackageMapper;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\McpTool;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\Schema;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Exception\ToolException;

/**
 * MCP tools for Joomla! language package management.
 *
 * Ported from the original `joomla-mcp-php` project's `src/Server/Languages/Packages.php` — method names,
 * tool names/descriptions, parameters and annotations are copied verbatim from there (see
 * `.plans/00-conventions.md` §2); only the method *bodies* differ.
 *
 * `languages_packages_install` is a genuine deviation: core's own Web Services API controller for language
 * packages (`api/components/com_installer/src/Controller/LanguagesController.php`) has no `install()` task
 * at all, so the original tool's equivalent HTTP call never worked. This port implements it for real,
 * against `InstallModel::install()` directly — and only ever installs a package the site's own configured
 * language update site actually lists (`detailsurl` comes from that feed, never from the caller), never an
 * arbitrary URL.
 *
 * @since  0.0.1
 */
class Packages extends AbstractTool
{
	private const OPTION = 'com_installer';

	#[McpTool(
		name: 'languages_packages_list',
		description: 'List available language packages',
		readOnlyHint: true,
	)]
	public function listPackages(
		#[Schema(description: 'Maximum number of items to return per page', minimum: 1)]
		?int $pageLimit = null,
		#[Schema(description: 'Starting record offset for pagination (0-based)', minimum: 0)]
		?int $pageOffset = null
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);

		/** @var \Joomla\Component\Installer\Administrator\Model\LanguagesModel $model */
		$model = $this->createModel(self::OPTION, 'Languages');

		// LanguagesModel::_getListCount() is only correct AFTER getItems() has run once (it counts the
		// un-paginated result it cached internally) — listItems() already calls them in that order, so this
		// must not be reordered.
		$result = $this->listItems(
			$model,
			[
				'filter.search'  => '',
				'list.ordering'  => 'name',
				'list.direction' => 'asc',
			],
			$pageLimit,
			$pageOffset,
			LanguagePackageMapper::LIST_FIELDS
		);

		if ($result['items'] === [])
		{
			// LanguagesModel fetches this feed over HTTP at request time (see the plan's reading list) — an
			// empty result is ambiguous between "no packages" and "couldn't reach the update server"; surface
			// whatever the model itself enqueued (e.g. "cannot connect to update server") so the LLM knows why,
			// rather than silently returning an empty list either way.
			$result['messages'] = $this->drainMessages();
		}

		return $result;
	}

	#[McpTool(
		name: 'languages_packages_install',
		description: 'Install a language package'
	)]
	public function installPackage(
		#[Schema(description: 'The language package identifier, e.g. "en-GB"')]
		string $package
	): array
	{
		// Mirrors core's own InstallController::install(): a bare, global core.admin check.
		$this->requireAuthorised('core.admin', 'root.1');

		/** @var \Joomla\Component\Installer\Administrator\Model\LanguagesModel $model */
		$model = $this->createModel(self::OPTION, 'Languages');
		$model->setState('filter.search', '');
		$model->setState('list.ordering', 'name');
		$model->setState('list.direction', 'asc');
		$model->setState('list.start', 0);
		$model->setState('list.limit', 0);

		$items = $model->getItems();

		if ($items === false)
		{
			throw new ToolException($this->modelError($model, 'Cannot list language packages'));
		}

		$shaped = OutputShaper::shapeList($items, LanguagePackageMapper::LIST_FIELDS);
		$found  = LanguagePackageMapper::findPackage($shaped, $package);

		if ($found === null || (string) ($found['detailsurl'] ?? '') === '')
		{
			throw new ToolException(sprintf('Not found: language package %s', $package));
		}

		// The URL comes ONLY from the site's own configured language update site's feed — never from the
		// caller — see this class's docblock.
		$input = $this->getApplication()->getInput();
		$input->set('installtype', 'url');
		$input->set('install_url', (string) $found['detailsurl']);
		$input->set('package', 'language');

		/** @var \Joomla\Component\Installer\Administrator\Model\InstallModel $installModel */
		$installModel = $this->createModel(self::OPTION, 'Install');

		$installed = (bool) $installModel->install();
		$messages  = $this->drainMessages();

		if (!$installed)
		{
			throw new ToolException(sprintf(
				'Language package install failed for %s: %s',
				(string) ($found['element'] ?? $package),
				$messages !== [] ? implode('; ', $messages) : 'Unknown error'
			));
		}

		return [
			'success'  => true,
			'package'  => (string) ($found['element'] ?? ''),
			'name'     => (string) ($found['name'] ?? ''),
			'messages' => $messages,
		];
	}

	/**
	 * @return  list<string>
	 */
	private function drainMessages(): array
	{
		$messages = [];

		foreach ($this->getApplication()->getMessageQueue(true) as $message)
		{
			if (!is_array($message))
			{
				continue;
			}

			$text = (string) ($message['message'] ?? '');

			if ($text !== '')
			{
				$messages[] = $text;
			}
		}

		return $messages;
	}
}
