<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Tool\Privacy;

defined('_JEXEC') || die;

use Akeeba\Component\Mcp4j\Administrator\Bridge\AbstractTool;
use Akeeba\Component\Mcp4j\Administrator\Mapper\Privacy\ConsentMapper;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\McpTool;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\Schema;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Exception\ToolException;

/**
 * MCP tools for Joomla! privacy consent record management.
 *
 * Ported from the original `joomla-mcp-php` project's `src/Server/Privacy/Consents.php` — method names,
 * tool names/descriptions, parameters and annotations are copied verbatim from there (see
 * `.plans/00-conventions.md` §2).
 *
 * `core.admin` on `com_privacy` is required for both tools, mirroring
 * `api/components/com_privacy/src/Controller/ConsentsController.php`. `com_privacy` has no singular
 * "consent" model (only `ConsentsModel`, a `ListModel`) — {@see self::readConsent()} reads a single row the
 * same way core's own item view does, by filtering the list down to one row.
 *
 * Neither tool ever returns `#__privacy_consents.token` — the value that authenticates a consent's own
 * public management link — even though core's own Web Services API view does render it; see
 * `.plans/12-fields-history-privacy.md` deviation 3 and "Sensitive output".
 *
 * @since  0.0.1
 */
class Consents extends AbstractTool
{
	private const OPTION = 'com_privacy';

	#[McpTool(
		name: 'privacy_consents_list',
		description: 'List privacy consents',
		readOnlyHint: true,
	)]
	public function listConsents(
		#[Schema(description: 'Filter by consent state')]
		?int $filterState = null,
		#[Schema(description: 'Maximum number of items to return per page', minimum: 1)]
		?int $pageLimit = null,
		#[Schema(description: 'Starting record offset for pagination (0-based)', minimum: 0)]
		?int $pageOffset = null
	): array
	{
		$this->requireAuthorised('core.admin', self::OPTION);

		/** @var \Joomla\Component\Privacy\Administrator\Model\ConsentsModel $model */
		$model = $this->createModel(self::OPTION, 'Consents');

		return $this->listItems(
			$model,
			ConsentMapper::listState($filterState),
			$pageLimit,
			$pageOffset,
			ConsentMapper::LIST_FIELDS,
			ConsentMapper::INT_FIELDS
		);
	}

	#[McpTool(
		name: 'privacy_consents_read',
		description: 'Retrieve a specific privacy consent',
		readOnlyHint: true,
	)]
	public function readConsent(
		#[Schema(description: 'The ID of the privacy consent to retrieve')]
		int $id
	): array
	{
		$this->requireAuthorised('core.admin', self::OPTION);

		/** @var \Joomla\Component\Privacy\Administrator\Model\ConsentsModel $model */
		$model = $this->createModel(self::OPTION, 'Consents');

		$result = $this->listItems($model, ConsentMapper::readState($id), 1, 0, ConsentMapper::ITEM_FIELDS, ConsentMapper::INT_FIELDS);

		if ($result['items'] === [])
		{
			throw new ToolException(sprintf('Not found: privacy consent #%d', $id));
		}

		return $result['items'][0];
	}
}
