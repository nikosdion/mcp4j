<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Tool\Redirects;

defined('_JEXEC') || die;

use Akeeba\Component\Mcp4j\Administrator\Bridge\AbstractTool;
use Akeeba\Component\Mcp4j\Administrator\Mapper\Redirects\RedirectMapper;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\McpTool;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\Schema;

/**
 * MCP tools for Joomla! redirect management.
 *
 * Ported from the original `joomla-mcp-php` project's `src/Server/Redirects/Redirects.php` — method
 * names, tool names/descriptions, parameters and annotations are copied verbatim from there (see
 * `.plans/00-conventions.md` §2); only the method *bodies* differ, and `*_delete`'s return type changes
 * from the original's `bool` to the framework-wide `['deleted' => true, 'id' => int]` shape.
 *
 * Standard CRUD over `#__redirect_links` via `LinksModel`/`LinkModel` — the bridge's generic helpers work
 * as-is (unlike Part A/C of this plan). One deliberate deviation from the original (see
 * `.plans/11-overrides-redirects-messages.md` Part B): the original tool wrote the HTTP status code
 * parameter into the `referer` column, which is actually the "referring page" field, a bug — this port
 * writes it to `header`, the real status-code column, via {@see RedirectMapper}.
 *
 * @since  0.0.1
 */
class Redirects extends AbstractTool
{
	private const OPTION = 'com_redirect';

	#[McpTool(
		name: 'redirects_list',
		description: 'List existing redirects',
		readOnlyHint: true,
	)]
	public function listRedirects(
		#[Schema(description: 'Search filter for the redirect URLs')]
		?string $filterSearch = null,
		#[Schema(
			description: 'Filter by publish state: 0=unpublished, 1=published, 2=archived, -2=trashed',
			enum: [null, 0, 1, 2, -2]
		)]
		?int $filterState = null,
		#[Schema(description: 'Maximum number of items to return per page', minimum: 1)]
		?int $pageLimit = null,
		#[Schema(description: 'Starting record offset for pagination (0-based)', minimum: 0)]
		?int $pageOffset = null
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);

		/** @var \Joomla\Component\Redirect\Administrator\Model\LinksModel $model */
		$model = $this->createModel(self::OPTION, 'Links');

		return $this->listItems(
			$model,
			RedirectMapper::listState($filterSearch, $filterState),
			$pageLimit,
			$pageOffset,
			RedirectMapper::LIST_FIELDS,
			RedirectMapper::INT_FIELDS
		);
	}

	#[McpTool(
		name: 'redirects_read',
		description: 'Retrieve the information of the specified redirect',
		readOnlyHint: true,
	)]
	public function readRedirect(
		#[Schema(description: 'The ID of the redirect to retrieve')]
		int $id
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);

		/** @var \Joomla\Component\Redirect\Administrator\Model\LinkModel $model */
		$model = $this->createModel(self::OPTION, 'Link');

		return $this->shape(
			$this->loadItem($model, $id, 'redirect'),
			RedirectMapper::ITEM_FIELDS,
			RedirectMapper::INT_FIELDS
		);
	}

	#[McpTool(
		name: 'redirects_create',
		description: 'Create a new redirect'
	)]
	public function createRedirect(
		#[Schema(description: 'The old URL to redirect from', minLength: 1)]
		string $oldUrl,
		#[Schema(description: 'The new URL to redirect to', minLength: 1)]
		string $newUrl,
		#[Schema(description: 'The HTTP status code for the redirect', enum: [301, 302, 303, 307, 308])]
		?int $statusCode = 301,
		#[Schema(
			description: 'Publish state: 0=unpublished, 1=published, 2=archived, -2=trashed',
			enum: [0, 1, 2, -2]
		)]
		int $published = 1,
		#[Schema(description: 'Optional comment for the redirect')]
		?string $comment = null
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);
		$this->requireAuthorised('core.create', self::OPTION);

		/** @var \Joomla\Component\Redirect\Administrator\Model\LinkModel $model */
		$model = $this->createModel(self::OPTION, 'Link');

		$data = RedirectMapper::createData($oldUrl, $newUrl, $statusCode, $published, $comment);

		$newId = $this->withLegacyCurrentUser(fn () => $this->saveItem($model, $data, null, 'redirect'));

		/** @var \Joomla\Component\Redirect\Administrator\Model\LinkModel $freshModel */
		$freshModel = $this->createModel(self::OPTION, 'Link');

		return $this->shape(
			$this->loadItem($freshModel, $newId, 'redirect'),
			RedirectMapper::ITEM_FIELDS,
			RedirectMapper::INT_FIELDS
		);
	}

	#[McpTool(
		name: 'redirects_update',
		description: 'Update an existing redirect',
		idempotentHint: true,
	)]
	public function updateRedirect(
		#[Schema(description: 'The ID of the redirect to modify')]
		int $id,
		#[Schema(description: 'The old URL to redirect from', minLength: 1)]
		?string $oldUrl = null,
		#[Schema(description: 'The new URL to redirect to', minLength: 1)]
		?string $newUrl = null,
		#[Schema(description: 'The HTTP status code for the redirect', enum: [null, 301, 302, 303, 307, 308])]
		?int $statusCode = null,
		#[Schema(
			description: 'Publish state: null=no change, 0=unpublished, 1=published, 2=archived, -2=trashed',
			enum: [null, 0, 1, 2, -2]
		)]
		?int $published = null,
		#[Schema(description: 'Optional comment for the redirect')]
		?string $comment = null
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);
		$this->requireAuthorised('core.edit', self::OPTION);

		/** @var \Joomla\Component\Redirect\Administrator\Model\LinkModel $model */
		$model = $this->createModel(self::OPTION, 'Link');

		$data = RedirectMapper::updateData($oldUrl, $newUrl, $statusCode, $published, $comment);

		$this->withLegacyCurrentUser(fn () => $this->saveItem($model, $data, $id, 'redirect'));

		/** @var \Joomla\Component\Redirect\Administrator\Model\LinkModel $freshModel */
		$freshModel = $this->createModel(self::OPTION, 'Link');

		return $this->shape(
			$this->loadItem($freshModel, $id, 'redirect'),
			RedirectMapper::ITEM_FIELDS,
			RedirectMapper::INT_FIELDS
		);
	}

	#[McpTool(
		name: 'redirects_trash',
		description: 'Moves a redirect to the trash by setting its state to -2',
		destructiveHint: true,
	)]
	public function trashRedirect(
		#[Schema(description: 'The ID of the redirect to trash')]
		int $id
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);
		$this->requireAuthorised('core.edit.state', self::OPTION);

		/** @var \Joomla\Component\Redirect\Administrator\Model\LinkModel $model */
		$model = $this->createModel(self::OPTION, 'Link');

		$this->changeState($model, $id, -2, 'redirect');

		/** @var \Joomla\Component\Redirect\Administrator\Model\LinkModel $freshModel */
		$freshModel = $this->createModel(self::OPTION, 'Link');

		return $this->shape(
			$this->loadItem($freshModel, $id, 'redirect'),
			RedirectMapper::ITEM_FIELDS,
			RedirectMapper::INT_FIELDS
		);
	}

	#[McpTool(
		name: 'redirects_delete',
		description: 'Permanently deletes a redirect. Automatically trashes it first if needed.',
		destructiveHint: true,
	)]
	public function deleteRedirect(
		#[Schema(description: 'The ID of the redirect to delete')]
		int $id
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);
		$this->requireAuthorised('core.delete', self::OPTION);

		/** @var \Joomla\Component\Redirect\Administrator\Model\LinkModel $model */
		$model = $this->createModel(self::OPTION, 'Link');

		$current = $this->loadItem($model, $id, 'redirect');

		// LinkModel::canDelete() refuses anything not already at published == -2 — trash first, exactly
		// like this tool's own "automatically trashes it first if needed" contract (see
		// AbstractTool::changeState()'s docblock on why this check, rather than always calling
		// changeState(), is needed).
		if ((int) ($current['published'] ?? 0) !== -2)
		{
			/** @var \Joomla\Component\Redirect\Administrator\Model\LinkModel $trashModel */
			$trashModel = $this->createModel(self::OPTION, 'Link');

			$this->changeState($trashModel, $id, -2, 'redirect');
		}

		/** @var \Joomla\Component\Redirect\Administrator\Model\LinkModel $deleteModel */
		$deleteModel = $this->createModel(self::OPTION, 'Link');

		$this->deleteItem($deleteModel, $id, 'redirect');

		return ['deleted' => true, 'id' => $id];
	}

	/**
	 * `LinkModel::getForm()` calls `AdminModel::canEditState()` to decide whether to disable (and
	 * `filter="unset"`) the `published` field — on Joomla 4.4 only, `canEditState()` resolves "the current
	 * user" via the classic, session-based `Factory::getUser()` rather than `$this->getCurrentUser()`
	 * (fixed upstream in 5.4/6.x; see `AbstractTool::createModel()`'s own docblock for the general shape
	 * of this issue, and its private `beginLegacyCurrentUserWorkaround()`/`endLegacyCurrentUserWorkaround()`
	 * pair, which does the same thing around `publish()`/`delete()` — not reusable here, as they are
	 * private to that class). Our MCP session never populates Joomla's classic session `user`, so on 4.4
	 * `canEditState()` sees a guest, `published` gets stripped out of `AbstractTool::saveItem()`'s
	 * form-filtered data entirely, and the `INSERT`/`UPDATE` then fails outright — `#__redirect_links
	 * .published` has no database default. Mirrors that same workaround, narrowly scoped around just the
	 * `saveItem()` call that reaches `getForm()`, for `redirects_create`/`redirects_update`.
	 *
	 * @template T
	 * @param   \Closure(): T  $callback
	 * @return  T
	 */
	private function withLegacyCurrentUser(\Closure $callback): mixed
	{
		$session      = $this->getApplication()->getSession();
		$hadPrevious  = $session->has('user');
		$previousUser = $hadPrevious ? $session->get('user') : null;

		$session->set('user', $this->getUser());

		try
		{
			return $callback();
		}
		finally
		{
			if ($hadPrevious)
			{
				$session->set('user', $previousUser);
			}
			else
			{
				$session->remove('user');
			}
		}
	}
}
