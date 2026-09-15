<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Tool\Privacy;

defined('_JEXEC') || die;

use Akeeba\Component\Mcp4j\Administrator\Bridge\AbstractTool;
use Akeeba\Component\Mcp4j\Administrator\Mapper\Privacy\ExportMapper;
use Akeeba\Component\Mcp4j\Administrator\Mapper\Privacy\RequestMapper;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\McpTool;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\Schema;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Exception\ToolException;

/**
 * MCP tools for Joomla! GDPR privacy request management.
 *
 * Ported from the original `joomla-mcp-php` project's `src/Server/Privacy/Requests.php` — method names,
 * tool names/descriptions, parameters and annotations are copied verbatim from there (see
 * `.plans/00-conventions.md` §2), only the method *bodies* differ.
 *
 * Every method requires `core.admin` on `com_privacy` — exactly what core's own
 * `api/components/com_privacy/src/Controller/RequestsController.php` checks on every action, and
 * deliberately *not* the weaker `core.manage` the shared pattern uses elsewhere: privacy request data is
 * personal data (an email address, and — via `privacy_requests_export` — a full export of everything the
 * site holds about that person), so this stays a Super-User-only surface regardless of who could otherwise
 * `core.manage` a component.
 *
 * @since  0.0.1
 */
class Requests extends AbstractTool
{
	private const OPTION = 'com_privacy';

	#[McpTool(
		name: 'privacy_requests_list',
		description: 'List privacy requests',
		readOnlyHint: true,
	)]
	public function listRequests(
		#[Schema(description: 'Filter by request status')]
		?int $filterState = null,
		#[Schema(description: 'Maximum number of items to return per page', minimum: 1)]
		?int $pageLimit = null,
		#[Schema(description: 'Starting record offset for pagination (0-based)', minimum: 0)]
		?int $pageOffset = null
	): array
	{
		$this->requireAuthorised('core.admin', self::OPTION);

		/** @var \Joomla\Component\Privacy\Administrator\Model\RequestsModel $model */
		$model = $this->createModel(self::OPTION, 'Requests');

		return $this->listItems(
			$model,
			RequestMapper::listState($filterState),
			$pageLimit,
			$pageOffset,
			RequestMapper::LIST_FIELDS,
			RequestMapper::INT_FIELDS
		);
	}

	#[McpTool(
		name: 'privacy_requests_read',
		description: 'Retrieve a specific privacy request',
		readOnlyHint: true,
	)]
	public function readRequest(
		#[Schema(description: 'The ID of the privacy request to retrieve')]
		int $id
	): array
	{
		$this->requireAuthorised('core.admin', self::OPTION);

		/** @var \Joomla\Component\Privacy\Administrator\Model\RequestModel $model */
		$model = $this->createModel(self::OPTION, 'Request');

		return $this->shape($this->loadItem($model, $id, 'privacy request'), RequestMapper::ITEM_FIELDS, RequestMapper::INT_FIELDS);
	}

	#[McpTool(
		name: 'privacy_requests_export',
		description: 'Export privacy request data',
		readOnlyHint: true,
	)]
	public function exportRequest(
		#[Schema(description: 'The ID of the privacy request to export')]
		int $id
	): array
	{
		$this->requireAuthorised('core.admin', self::OPTION);

		/** @var \Joomla\Component\Privacy\Administrator\Model\RequestModel $requestModel */
		$requestModel = $this->createModel(self::OPTION, 'Request');

		// 404 before attempting the export, matching every other *_read-shaped tool.
		$this->loadItem($requestModel, $id, 'privacy request');

		/** @var \Joomla\Component\Privacy\Administrator\Model\ExportModel $model */
		$model = $this->createModel(self::OPTION, 'Export');

		$domains = $model->collectDataForExportRequest($id);

		if ($domains === false)
		{
			throw new ToolException(sprintf('Cannot export privacy request #%d: %s', $id, $this->modelError($model, 'Unknown error')));
		}

		return [
			'request_id' => $id,
			'domains'    => ExportMapper::domainsToArray($domains),
		];
	}

	#[McpTool(
		name: 'privacy_requests_create',
		description: 'Create a new privacy request'
	)]
	public function createRequest(
		#[Schema(description: 'Email address for the privacy request')]
		string $email,
		#[Schema(description: 'Request type: 1=export, 2=removal')]
		int $requestType
	): array
	{
		$this->requireAuthorised('core.admin', self::OPTION);

		try
		{
			$type = RequestMapper::requestTypeFromInt($requestType);
		}
		catch (\InvalidArgumentException $e)
		{
			throw new ToolException($e->getMessage());
		}

		/** @var \Joomla\Component\Privacy\Administrator\Model\RequestModel $model */
		$model = $this->createModel(self::OPTION, 'Request');

		$id = $this->saveItem($model, ['email' => $email, 'request_type' => $type, 'status' => 0], null, 'privacy request');

		// Mirrors RequestController::postSaveHook() — logRequestCreated()/notifyUserAdminCreatedRequest()
		// are only ever called by the backend controller after a successful save, never by the model
		// itself. Both catch their own mail/DB errors and return false rather than throwing; Docker test
		// environments have no MTA, so notifyUserAdminCreatedRequest() returning false is expected, not a
		// tool failure — see `.plans/12-fields-history-privacy.md`.
		/** @var \Joomla\Component\Privacy\Administrator\Model\RequestModel $freshModel */
		$freshModel = $this->createModel(self::OPTION, 'Request');

		try
		{
			$freshModel->logRequestCreated($id);
		}
		catch (\Throwable $e)
		{
			// Deliberately ignored — action-log failures never fail request creation itself.
		}

		$notified = false;

		try
		{
			$notified = (bool) $freshModel->notifyUserAdminCreatedRequest($id);
		}
		catch (\Throwable $e)
		{
			$notified = false;
		}

		/** @var \Joomla\Component\Privacy\Administrator\Model\RequestModel $readModel */
		$readModel = $this->createModel(self::OPTION, 'Request');

		$result                       = $this->shape($this->loadItem($readModel, $id, 'privacy request'), RequestMapper::ITEM_FIELDS, RequestMapper::INT_FIELDS);
		$result['notification_sent'] = $notified;

		return $result;
	}
}
