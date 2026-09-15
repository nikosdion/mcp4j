<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Tool\Banners;

defined('_JEXEC') || die;

use Akeeba\Component\Mcp4j\Administrator\Bridge\AbstractTool;
use Akeeba\Component\Mcp4j\Administrator\Mapper\Banners\ClientMapper;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\McpTool;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\Schema;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Exception\ToolException;

/**
 * MCP tools for Joomla! banner clients management.
 *
 * Ported from the original `joomla-mcp-php` project's `src/Server/Banners/Clients.php` — method names,
 * tool names/descriptions, parameters and annotations are copied verbatim from there (see
 * `.plans/00-conventions.md` §2). Unlike banners, clients are not category-scoped —
 * `ClientController`/`ClientModel` fall back to plain component-level ACL (`core.create`/`core.edit`/
 * `core.edit.state`/`core.delete` on `com_banners` itself) rather than a per-category asset.
 *
 * The original's parameter naming is swapped for this resource: `name` ("Client contact name") maps to the
 * `contact` form field, and `contactName` ("Client company/display name") maps to the `name` form field —
 * see {@see ClientMapper}'s docblock. Clients have no `metadesc` field; a non-empty value is refused
 * outright (see {@see ClientMapper::unsupported()}) rather than silently discarded.
 *
 * @since  0.0.1
 */
class Clients extends AbstractTool
{
	private const OPTION = 'com_banners';

	#[McpTool(
		name: 'banners_clients_list',
		description: 'List existing banner clients',
		readOnlyHint: true,
	)]
	public function listClients(
		#[Schema(description: 'Search filter for the client name')]
		?string $filterSearch = null,
		#[Schema(
			description: 'Filter by state: 0=unpublished, 1=published, 2=archived, -2=trashed',
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

		/** @var \Joomla\Component\Banners\Administrator\Model\ClientsModel $model */
		$model = $this->createModel(self::OPTION, 'Clients');

		return $this->listItems(
			$model,
			ClientMapper::listState($filterSearch, $filterState),
			$pageLimit,
			$pageOffset,
			ClientMapper::LIST_FIELDS,
			ClientMapper::INT_FIELDS
		);
	}

	#[McpTool(
		name: 'banners_clients_read',
		description: 'Retrieve the information of the specified banner client',
		readOnlyHint: true,
	)]
	public function readClient(
		#[Schema(description: 'The ID of the client to retrieve')]
		int $id
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);

		/** @var \Joomla\Component\Banners\Administrator\Model\ClientModel $model */
		$model = $this->createModel(self::OPTION, 'Client');

		return $this->shape(
			$this->loadItem($model, $id, 'banner client'),
			ClientMapper::ITEM_FIELDS,
			ClientMapper::INT_FIELDS
		);
	}

	#[McpTool(
		name: 'banners_clients_create',
		description: 'Create a new banner client'
	)]
	public function createClient(
		#[Schema(description: 'Client contact name', minLength: 1, maxLength: 255)]
		string $name,
		#[Schema(description: 'Client company/display name', minLength: 1, maxLength: 255)]
		string $contactName,
		#[Schema(description: 'Client email address', format: 'email')]
		?string $email = null,
		#[Schema(description: 'Extra information about the client')]
		?string $extraInfo = null,
		#[Schema(
			description: 'Publish state: 0=unpublished, 1=published',
			enum: [0, 1]
		)]
		int $state = 1,
		#[Schema(description: 'Purchase type')]
		?int $purchaseType = null,
		#[Schema(description: 'Track impressions')]
		?int $trackImpressions = null,
		#[Schema(description: 'Track clicks')]
		?int $trackClicks = null,
		#[Schema(description: 'Optional meta keywords, separated by commas')]
		?string $metakey = null,
		#[Schema(description: 'Optional meta description')]
		?string $metadesc = null
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);

		$this->refuseUnsupported($metadesc);

		$this->requireAuthorised('core.create', self::OPTION);

		if ($state !== 1)
		{
			$this->requireAuthorised('core.edit.state', self::OPTION);
		}

		/** @var \Joomla\Component\Banners\Administrator\Model\ClientModel $model */
		$model = $this->createModel(self::OPTION, 'Client');

		$data = ClientMapper::createData($name, $contactName, $email, $extraInfo, $state, $purchaseType, $trackImpressions, $trackClicks, $metakey);

		$newId = $this->saveItem($model, $data, null, 'banner client');

		return $this->readClient($newId);
	}

	#[McpTool(
		name: 'banners_clients_update',
		description: 'Update an existing banner client',
		idempotentHint: true,
	)]
	public function updateClient(
		#[Schema(description: 'The ID of the client to modify')]
		int $id,
		#[Schema(description: 'Client contact name', minLength: 1, maxLength: 255)]
		?string $name = null,
		#[Schema(description: 'Client company/display name', minLength: 1, maxLength: 255)]
		?string $contactName = null,
		#[Schema(description: 'Client email address', format: 'email')]
		?string $email = null,
		#[Schema(description: 'Extra information about the client')]
		?string $extraInfo = null,
		#[Schema(
			description: 'Publish state: null=no change, 0=unpublished, 1=published, -2=trashed',
			enum: [null, 0, 1, -2]
		)]
		?int $state = null,
		#[Schema(description: 'Purchase type')]
		?int $purchaseType = null,
		#[Schema(description: 'Track impressions')]
		?int $trackImpressions = null,
		#[Schema(description: 'Track clicks')]
		?int $trackClicks = null,
		#[Schema(description: 'Optional meta keywords, separated by commas')]
		?string $metakey = null,
		#[Schema(description: 'Optional meta description')]
		?string $metadesc = null
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);

		$this->refuseUnsupported($metadesc);

		/** @var \Joomla\Component\Banners\Administrator\Model\ClientModel $model */
		$model = $this->createModel(self::OPTION, 'Client');

		$this->loadItem($model, $id, 'banner client');

		$this->requireAuthorised('core.edit', self::OPTION);

		if ($state !== null)
		{
			$this->requireAuthorised('core.edit.state', self::OPTION);
		}

		$data = ClientMapper::updateData($name, $contactName, $email, $extraInfo, $state, $purchaseType, $trackImpressions, $trackClicks, $metakey);

		$this->saveItem($model, $data, $id, 'banner client');

		return $this->readClient($id);
	}

	#[McpTool(
		name: 'banners_clients_trash',
		description: 'Moves a banner client to the trash by setting its state to -2',
		destructiveHint: true,
	)]
	public function trashClient(
		#[Schema(description: 'The ID of the client to trash')]
		int $id
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);

		/** @var \Joomla\Component\Banners\Administrator\Model\ClientModel $model */
		$model = $this->createModel(self::OPTION, 'Client');

		$this->loadItem($model, $id, 'banner client');

		$this->requireAuthorised('core.edit.state', self::OPTION);

		$this->changeState($model, $id, -2, 'banner client');

		return $this->readClient($id);
	}

	#[McpTool(
		name: 'banners_clients_delete',
		description: 'Permanently deletes a banner client. Automatically trashes it first if needed.',
		destructiveHint: true,
	)]
	public function deleteClient(
		#[Schema(description: 'The ID of the client to delete')]
		int $id
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);

		/** @var \Joomla\Component\Banners\Administrator\Model\ClientModel $model */
		$model = $this->createModel(self::OPTION, 'Client');

		$current = $this->loadItem($model, $id, 'banner client');

		$this->requireAuthorised('core.delete', self::OPTION);

		if ((int) ($current['state'] ?? 0) !== -2)
		{
			/** @var \Joomla\Component\Banners\Administrator\Model\ClientModel $trashModel */
			$trashModel = $this->createModel(self::OPTION, 'Client');

			$this->changeState($trashModel, $id, -2, 'banner client');
		}

		/** @var \Joomla\Component\Banners\Administrator\Model\ClientModel $deleteModel */
		$deleteModel = $this->createModel(self::OPTION, 'Client');

		$this->deleteItem($deleteModel, $id, 'banner client');

		return ['deleted' => true, 'id' => $id];
	}

	/**
	 * @throws  ToolException
	 */
	private function refuseUnsupported(?string $metadesc): void
	{
		$unsupported = ClientMapper::unsupported($metadesc);

		if ($unsupported !== [])
		{
			throw new ToolException(sprintf('Banner clients do not support: %s. Omit these parameters.', implode(', ', $unsupported)));
		}
	}
}
