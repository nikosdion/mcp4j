<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Tool\Panopticon;

defined('_JEXEC') || die;

use Akeeba\Component\Mcp4j\Administrator\Bridge\Support\ItemNormalizer;
use Akeeba\Component\Mcp4j\Administrator\Mapper\Panopticon\UpdateSiteMapper;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\McpTool;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\Schema;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\ToolRequirements;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Exception\ToolException;
use Akeeba\Component\Mcp4j\Administrator\Shared\Panopticon\AbstractPanopticonTool;

/**
 * MCP tools for managing Joomla extension update sites, driven by `com_installer`'s own models (the
 * connector's `Updatesites`/`Updatesite` Api models are thin subclasses of them, adding an `id` alias).
 *
 * Ported from the original `joomla-mcp-php` project's `src/Server/Panopticon/UpdateSites.php` — tool
 * names/descriptions, parameters and annotations are copied verbatim from there (see
 * `.plans/00-conventions.md` §2); every method body is reimplemented against `com_installer`'s
 * `UpdatesitesModel`/`UpdatesiteModel` directly — see `.plans/14-panopticon-core.md`.
 *
 * @since  0.0.1
 */
#[ToolRequirements(components: ['com_panopticon'])]
class UpdateSites extends AbstractPanopticonTool
{
	private const INSTALLER_OPTION = 'com_installer';

	#[McpTool(
		name: 'panopticon_updatesites_list',
		description: 'List update sites',
		readOnlyHint: true,
	)]
	public function listUpdateSites(
		#[Schema(description: 'Search filter for update sites')]
		?string $filterSearch = null,
		#[Schema(description: 'Maximum number of items to return per page', minimum: 1)]
		?int $pageLimit = null,
		#[Schema(description: 'Starting record offset for pagination (0-based)', minimum: 0)]
		?int $pageOffset = null
	): array
	{
		$this->requireSuperUser();
		$this->requireAuthorised('core.manage', self::INSTALLER_OPTION);

		/** @var \Akeeba\Component\Panopticon\Api\Model\UpdatesitesModel $model */
		$model = $this->createPanopticonModel('Updatesites');

		// Note: com_installer's InstallerModel::_getList() resets list.start back to 0 (page 1) whenever
		// the requested offset is beyond the total row count, rather than returning an empty page — this is
		// core Joomla behaviour, not something this tool works around.
		$result = $this->listItems(
			$model,
			UpdateSiteMapper::listState($filterSearch),
			$pageLimit,
			$pageOffset,
			UpdateSiteMapper::FIELDS,
			UpdateSiteMapper::INT_FIELDS,
			UpdateSiteMapper::JSON_FIELDS
		);

		$result['items'] = array_map([UpdateSiteMapper::class, 'sanitise'], $result['items']);

		return $result;
	}

	#[McpTool(
		name: 'panopticon_updatesites_read',
		description: 'Read details of a specific update site',
		readOnlyHint: true,
	)]
	public function readUpdateSite(
		#[Schema(description: 'The ID of the update site to retrieve')]
		int $id
	): array
	{
		$this->requireSuperUser();
		$this->requireAuthorised('core.manage', self::INSTALLER_OPTION);

		$item = $this->readUpdateSiteRaw($id);

		return $this->shape(
			UpdateSiteMapper::sanitise($item),
			UpdateSiteMapper::FIELDS,
			UpdateSiteMapper::INT_FIELDS,
			UpdateSiteMapper::JSON_FIELDS
		);
	}

	#[McpTool(
		name: 'panopticon_updatesites_update',
		description: 'Update an existing update site',
		idempotentHint: true,
	)]
	public function updateUpdateSite(
		#[Schema(description: 'The ID of the update site to update')]
		int $id,
		#[Schema(description: 'Enable or disable the update site', enum: [0, 1])]
		?int $enabled = null,
		#[Schema(description: 'The name of the update site')]
		?string $name = null,
		#[Schema(description: 'The URL location of the update site')]
		?string $location = null,
		#[Schema(description: 'Extra query string to append to the update site URL')]
		?string $extraQuery = null
	): array
	{
		$this->requireSuperUser();
		$this->requireAuthorised('core.edit', self::INSTALLER_OPTION);

		if ($enabled !== null)
		{
			$this->requireAuthorised('core.edit.state', self::INSTALLER_OPTION);
		}

		// Read first, both to 404 early and to have the current values getItem()/save() need below.
		$this->readUpdateSiteRaw($id);

		// A repointed update site is a supply-chain attack vector — a prompt-injected LLM could redirect
		// extension updates to a hostile server. Joomla's own update-site form marks both fields read-only
		// for exactly this reason; the connector's save() *intended* to allow only enabled/extra_query but
		// has a bug (`unset($data['key'])` instead of unsetting the disallowed fields) that lets anything
		// through — we do not inherit that bug.
		$unsupported = UpdateSiteMapper::unsupportedChanges($name, $location);

		if ($unsupported !== [])
		{
			throw new ToolException('Changing the name or location of an update site is not supported.');
		}

		if ($enabled !== null)
		{
			/** @var \Akeeba\Component\Panopticon\Api\Model\UpdatesitesModel $model */
			$model = $this->createPanopticonModel('Updatesites');
			$pks   = [$id];

			try
			{
				$model->publish($pks, $enabled ? 1 : 0);
			}
			catch (\Throwable $e)
			{
				throw new ToolException($e->getMessage());
			}
		}

		if ($extraQuery !== null)
		{
			/** @var \Akeeba\Component\Panopticon\Api\Model\UpdatesiteModel $model */
			$model = $this->createPanopticonModel('Updatesite');
			$item  = $model->getItem($id);

			$value = UpdateSiteMapper::stripAffixes(
				trim($extraQuery),
				(string) ($item->downloadIdPrefix ?? ''),
				(string) ($item->downloadIdSuffix ?? '')
			);

			if (str_contains($value, "\r") || str_contains($value, "\n"))
			{
				throw new ToolException('The extra query value must not contain line breaks.');
			}

			$data = array_merge(ItemNormalizer::toArray($item), ['extra_query' => $value]);

			$model->checkout($id);

			try
			{
				if (!$model->save($data))
				{
					throw new ToolException('Save failed for update site: ' . $this->modelError($model, 'Unknown error'));
				}
			}
			finally
			{
				$model->checkin($id);
			}
		}

		return $this->shape(
			UpdateSiteMapper::sanitise($this->readUpdateSiteRaw($id)),
			UpdateSiteMapper::FIELDS,
			UpdateSiteMapper::INT_FIELDS,
			UpdateSiteMapper::JSON_FIELDS
		);
	}

	#[McpTool(
		name: 'panopticon_updatesites_delete',
		description: 'Delete an update site',
		destructiveHint: true,
	)]
	public function deleteUpdateSite(
		#[Schema(description: 'The ID of the update site to delete')]
		int $id
	): array
	{
		$this->requireSuperUser();
		$this->requireAuthorised('core.delete', self::INSTALLER_OPTION);

		$this->readUpdateSiteRaw($id);

		/** @var \Akeeba\Component\Panopticon\Api\Model\UpdatesitesModel $model */
		$model = $this->createPanopticonModel('Updatesites');
		$ids   = [$id];

		try
		{
			$model->delete($ids);
		}
		catch (\Exception $e)
		{
			if ((int) $e->getCode() === 403)
			{
				throw new ToolException('Permission denied: core.delete on ' . self::INSTALLER_OPTION);
			}

			throw new ToolException($e->getMessage());
		}

		// Joomla's own model silently refuses to delete a core Joomla update site (it enqueues an error
		// message and moves on rather than throwing) — detect that by re-reading.
		try
		{
			$this->readUpdateSiteRaw($id);
		}
		catch (ToolException $e)
		{
			return ['deleted' => true, 'id' => $id];
		}

		$errors = array_filter($this->takeMessages(), static fn (array $m): bool => $m['type'] === 'error');

		if ($errors !== [])
		{
			throw new ToolException(implode('; ', array_column($errors, 'message')));
		}

		throw new ToolException('Could not delete update site #' . $id);
	}

	#[McpTool(
		name: 'panopticon_updatesites_rebuild',
		description: 'Rebuild the update sites table'
	)]
	public function rebuildUpdateSites(): array
	{
		$this->requireSuperUser();
		$this->requireAuthorised('core.admin', self::INSTALLER_OPTION);

		/** @var \Akeeba\Component\Panopticon\Api\Model\UpdatesitesModel $model */
		$model = $this->createPanopticonModel('Updatesites');

		// Discard whatever is already queued before rebuild() so takeMessages() below reflects only this
		// call's own outcome.
		$this->takeMessages();

		try
		{
			$model->rebuild();
		}
		catch (\Throwable $e)
		{
			throw new ToolException($e->getMessage());
		}

		$messages = $this->takeMessages();
		$errors   = array_filter($messages, static fn (array $m): bool => $m['type'] === 'error');

		if ($errors !== [])
		{
			throw new ToolException(implode('; ', array_column($errors, 'message')));
		}

		return ['success' => true, 'messages' => $messages];
	}

	/**
	 * @return  array<string, mixed>
	 *
	 * @throws  ToolException  No such update site.
	 */
	private function readUpdateSiteRaw(int $id): array
	{
		/** @var \Akeeba\Component\Panopticon\Api\Model\UpdatesiteModel $model */
		$model = $this->createPanopticonModel('Updatesite');

		// com_installer's own UpdatesiteModel::getItem() (which the connector's Api model wraps in a
		// try/catch \Throwable, but only for actual exceptions) does
		// `(int) $item->update_site_id ?? 0` straight after `$item = parent::getItem($pk)` with no
		// falsy-check in between — for a nonexistent id AdminModel::getItem() returns bool false, so that
		// line is "Attempt to read property on bool", a PHP E_WARNING that display_errors would otherwise
		// echo into (and corrupt) the JSON-RPC response body. Not our code to fix (Joomla core) —
		// suppressed narrowly, like Tool/Contact/Contacts.php's analogous E_DEPRECATED workaround; a
		// genuine warning/error outside this exact call still surfaces normally.
		set_error_handler(static fn (int $errno, string $errstr): bool => true, E_WARNING);

		try
		{
			$item = ItemNormalizer::toArray($model->getItem($id));
		}
		finally
		{
			restore_error_handler();
		}

		if ((int) ($item['update_site_id'] ?? 0) !== $id)
		{
			throw new ToolException(sprintf('Not found: update site #%d', $id));
		}

		return $item;
	}
}
