<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Tool\Messages;

defined('_JEXEC') || die;

use Akeeba\Component\Mcp4j\Administrator\Bridge\AbstractTool;
use Akeeba\Component\Mcp4j\Administrator\Bridge\Support\ItemNormalizer;
use Akeeba\Component\Mcp4j\Administrator\Mapper\Messages\MessageMapper;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\McpTool;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\Schema;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Exception\ToolException;

/**
 * MCP tools for Joomla! private message management.
 *
 * Ported from the original `joomla-mcp-php` project's `src/Server/Messages/Messages.php` — method names,
 * tool names/descriptions, parameters and annotations are copied verbatim from there (see
 * `.plans/00-conventions.md` §2); only the method *bodies* differ.
 *
 * `#__messages`'s primary key is `message_id`, not `id`, so `AbstractTool::loadItem()`/`changeState()`/
 * `deleteItem()` (which all assume/validate an `id` key) cannot be used here — see
 * `.plans/11-overrides-redirects-messages.md` Part C fact 1; {@see self::loadOwnRow()} and
 * {@see self::setMessageState()} are this category's own equivalents, scoped to "a message addressed to
 * the current user" throughout (never another user's inbox — see this plan's "Sensitive output" section).
 *
 * Deliberate deviations from the original (see the plan's Part C "Deviations"):
 *
 * 1. `messages_update` only ever changes `state` — the backend has no "edit a sent message" UI, and
 *    `MessageModel::save()` would re-send the new-message notification e-mail if reused for an edit.
 * 2. A message not addressed to the current user reads as `Not found`, not "forbidden" — this tool never
 *    reveals that a given id belongs to someone else's inbox.
 * 3. `messages_read` marks the message read (`state = 1`), matching `MessageModel::getItem()`'s own
 *    backend behaviour (opening a message in the UI does the same).
 *
 * @since  0.0.1
 */
class Messages extends AbstractTool
{
	private const OPTION = 'com_messages';

	#[McpTool(
		name: 'messages_list',
		description: 'List existing private messages',
		readOnlyHint: true,
	)]
	public function listMessages(
		#[Schema(
			description: 'Filter by message state: 0=unread, 1=read, -2=trashed',
			enum: [null, 0, 1, -2]
		)]
		?int $filterState = null,
		#[Schema(description: 'Maximum number of items to return per page', minimum: 1)]
		?int $pageLimit = null,
		#[Schema(description: 'Starting record offset for pagination (0-based)', minimum: 0)]
		?int $pageOffset = null
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);

		/** @var \Joomla\Component\Messages\Administrator\Model\MessagesModel $model */
		$model = $this->createModel(self::OPTION, 'Messages');

		// MessagesModel::getListQuery() always restricts to the current user's own inbox (a.user_id_to)
		// — see class docblock. The raw list rows carry "message_id" (the table's real primary key), not
		// "id" — kept alongside the whitelist for the first shaping pass so it survives to be renamed.
		$result = $this->listItems(
			$model,
			MessageMapper::listState($filterState),
			$pageLimit,
			$pageOffset,
			array_merge(['message_id'], MessageMapper::LIST_FIELDS),
			MessageMapper::INT_FIELDS
		);

		$result['items'] = array_map(
			fn (array $item): array => $this->shape(MessageMapper::withId($item), MessageMapper::LIST_FIELDS, MessageMapper::INT_FIELDS),
			$result['items']
		);

		return $result;
	}

	#[McpTool(
		name: 'messages_read',
		description: 'Retrieve the information of the specified private message',
		readOnlyHint: true,
	)]
	public function readMessage(
		#[Schema(description: 'The ID of the message to retrieve')]
		int $id
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);

		// Confirms the message exists and is addressed to the current user before ever calling
		// MessageModel::getItem() on it, which reads a property off `false` (a PHP warning) for a
		// nonexistent id — see .plans/11-overrides-redirects-messages.md Part C fact 3.
		$this->loadOwnRow($id);

		/** @var \Joomla\Component\Messages\Administrator\Model\MessageModel $model */
		$model = $this->createModel(self::OPTION, 'Message');
		$item  = $model->getItem($id);

		if ($item === false)
		{
			throw new ToolException(sprintf('Not found: message #%d', $id));
		}

		$normalised = MessageMapper::withId(ItemNormalizer::toArray($item));

		// MessageModel::getItem() marks the message read with a direct UPDATE query as a side effect
		// (see class docblock deviation 3), but never updates the in-memory object it returns — the
		// "state" it hands back is whatever was loaded a moment earlier, i.e. stale. loadOwnRow() above
		// already confirmed this row exists and is addressed to the current user, which is exactly the
		// condition under which MessageModel::getItem() always takes that "mark read" branch, so it is
		// safe to reflect the side effect it just performed here rather than return a stale value.
		$normalised['state'] = 1;

		return $this->shape(
			$normalised,
			MessageMapper::ITEM_FIELDS,
			MessageMapper::INT_FIELDS
		);
	}

	#[McpTool(
		name: 'messages_create',
		description: 'Create a new private message'
	)]
	public function createMessage(
		#[Schema(description: 'The ID of the Joomla user to send the message to', minimum: 1)]
		int $userIdTo,
		#[Schema(description: 'Message subject', minLength: 1, maxLength: 255)]
		string $subject,
		#[Schema(description: 'Message body text', minLength: 1)]
		string $message
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);
		$this->requireAuthorised('core.create', self::OPTION);

		/** @var \Joomla\Component\Messages\Administrator\Model\MessageModel $model */
		$model = $this->createModel(self::OPTION, 'Message');

		$data = MessageMapper::createData($userIdTo, $subject, $message);

		// Validation failures AND recipient-related refusals (recipient lacks core.login.admin/
		// core.manage on com_messages, or has locked their inbox — MessageModel::save()) both surface as
		// a "Save failed for message: ..." ToolException via AbstractTool::saveItem().
		$newId = $this->saveItem($model, $data, null, 'message');

		// Reload from the sender's perspective (no ownership check — the caller IS the sender here, and
		// loadOwnRow()/getItem() both assume "recipient", which would wrongly refuse this reload).
		/** @var \Joomla\Component\Messages\Administrator\Model\MessageModel $freshModel */
		$freshModel = $this->createModel(self::OPTION, 'Message');
		$table      = $freshModel->getTable();

		if (!$table->load($newId))
		{
			throw new ToolException(sprintf('Not found: message #%d', $newId));
		}

		// from_user_name is only ever populated by MessageModel::getItem() (the recipient's read path);
		// omit it here rather than return an always-empty field.
		$fields = array_values(array_diff(MessageMapper::ITEM_FIELDS, ['from_user_name']));

		return $this->shape(MessageMapper::withId(ItemNormalizer::toArray($table)), $fields, MessageMapper::INT_FIELDS);
	}

	#[McpTool(
		name: 'messages_update',
		description: 'Update an existing private message',
		idempotentHint: true,
	)]
	public function updateMessage(
		#[Schema(description: 'The ID of the message to modify')]
		int $id,
		#[Schema(description: 'The ID of the Joomla user to send the message to', minimum: 1)]
		?int $userIdTo = null,
		#[Schema(description: 'Message subject', minLength: 1, maxLength: 255)]
		?string $subject = null,
		#[Schema(description: 'Message body text', minLength: 1)]
		?string $message = null,
		#[Schema(
			description: 'Message state: null=no change, 0=unread, 1=read, -2=trashed',
			enum: [null, 0, 1, -2]
		)]
		?int $state = null
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);
		$this->requireAuthorised('core.edit.state', self::OPTION);

		try
		{
			MessageMapper::assertStateOnlyUpdate($userIdTo, $subject, $message);
		}
		catch (\InvalidArgumentException $e)
		{
			throw new ToolException($e->getMessage());
		}

		if ($state === null)
		{
			throw new ToolException('Nothing to update: pass state.');
		}

		$this->setMessageState($id, $state);

		return $this->shape($this->loadOwnRow($id), MessageMapper::ITEM_FIELDS, MessageMapper::INT_FIELDS);
	}

	#[McpTool(
		name: 'messages_trash',
		description: 'Moves a private message to the trash by setting its state to -2',
		destructiveHint: true,
	)]
	public function trashMessage(
		#[Schema(description: 'The ID of the message to trash')]
		int $id
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);
		$this->requireAuthorised('core.edit.state', self::OPTION);

		$this->setMessageState($id, -2);

		return $this->shape($this->loadOwnRow($id), MessageMapper::ITEM_FIELDS, MessageMapper::INT_FIELDS);
	}

	#[McpTool(
		name: 'messages_delete',
		description: 'Permanently deletes a private message. Automatically trashes it first if needed.',
		destructiveHint: true,
	)]
	public function deleteMessage(
		#[Schema(description: 'The ID of the message to delete')]
		int $id
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);
		$this->requireAuthorised('core.delete', self::OPTION);

		$current = $this->loadOwnRow($id);

		if ((int) ($current['state'] ?? 0) !== -2)
		{
			$this->setMessageState($id, -2);
		}

		/** @var \Joomla\Component\Messages\Administrator\Model\MessageModel $model */
		$model = $this->createModel(self::OPTION, 'Message');

		// MessageModel::delete(&$pks) — by reference, a variable only (never an inline array literal —
		// see .plans/00-conventions.md §5.2); it also prunes $pks (refusing) if the current user isn't
		// the recipient, which loadOwnRow() above already guarantees isn't the case here.
		$pks = [$id];

		if (!$model->delete($pks) || $pks === [])
		{
			throw new ToolException($this->modelError($model, sprintf('Permission denied: core.delete on message #%d', $id)));
		}

		return ['deleted' => true, 'id' => $id];
	}

	/**
	 * Loads a message row directly off the table (never `MessageModel::getItem()`, which reads a
	 * property off `false` for a nonexistent id — see class docblock fact 3) and confirms it belongs to
	 * the current user's inbox, reporting "Not found" either way rather than ever revealing that a given
	 * id belongs to someone else (see `.plans/11-overrides-redirects-messages.md` Part C deviation 2).
	 *
	 * @throws  ToolException
	 */
	private function loadOwnRow(int $id): array
	{
		if ($id <= 0)
		{
			throw new ToolException(sprintf('Not found: message #%d', $id));
		}

		/** @var \Joomla\Component\Messages\Administrator\Model\MessageModel $model */
		$model = $this->createModel(self::OPTION, 'Message');
		$table = $model->getTable();

		if (!$table->load($id) || (int) $table->user_id_to !== (int) $this->getUser()->id)
		{
			throw new ToolException(sprintf('Not found: message #%d', $id));
		}

		return MessageMapper::withId(ItemNormalizer::toArray($table));
	}

	/**
	 * De-HTTP'd equivalent of the state-change path, via `MessageModel::publish()` — which additionally
	 * refuses (prunes `$pks`) when the current user isn't the message's recipient, on top of the usual
	 * `core.edit.state` check `AdminModel::publish()` performs.
	 *
	 * @throws  ToolException
	 */
	private function setMessageState(int $id, int $state): void
	{
		$current = $this->loadOwnRow($id);

		if ((int) ($current['state'] ?? null) === $state)
		{
			// AdminModel::publish() silently prunes (unset()s) a pk that is already at the target state,
			// and returns true with an emptied $pks array for it — indistinguishable, from the caller's
			// side, from "refused" without checking first (see AbstractTool::changeState()'s docblock for
			// the same pitfall). Treat "already there" as a successful no-op rather than misreport it as
			// a permission failure; this also makes messages_update's state change idempotent, matching
			// its own idempotentHint.
			return;
		}

		/** @var \Joomla\Component\Messages\Administrator\Model\MessageModel $model */
		$model = $this->createModel(self::OPTION, 'Message');

		// publish(&$pks) — by reference, a variable only (never an inline array literal).
		$pks = [$id];

		if (!$model->publish($pks, $state) || $pks === [])
		{
			throw new ToolException($this->modelError($model, sprintf('Permission denied: core.edit.state on message #%d', $id)));
		}
	}
}
