<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Tool\Panopticon;

defined('_JEXEC') || die;

use Akeeba\Component\Mcp4j\Administrator\Mapper\Panopticon\TemplateOverrideMapper;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\McpTool;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\Schema;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\ToolRequirements;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Exception\ToolException;
use Akeeba\Component\Mcp4j\Administrator\Shared\Panopticon\AbstractPanopticonTool;

/**
 * MCP tools for listing/reading changed template overrides (files under a template's `html/` override
 * directory that no longer match the core file they override), driven by the connector's
 * `Templatechanged` Api model.
 *
 * Ported from the original `joomla-mcp-php` project's `src/Server/Panopticon/TemplateOverrides.php` — tool
 * names/descriptions, parameters and annotations are copied verbatim from there (see
 * `.plans/00-conventions.md` §2). The original has no client parameter; the connector's own default is
 * `client_id = 0` (site templates only) — kept as-is, see `.plans/14-panopticon-core.md`.
 *
 * Deliberately WITHOUT the absolute server paths (`overridePath`/`corePath`) and `diff` the connector's
 * model never actually computes — see {@see TemplateOverrideMapper}.
 *
 * @since  0.0.1
 */
#[ToolRequirements(components: ['com_panopticon'])]
class TemplateOverrides extends AbstractPanopticonTool
{
	private const TEMPLATES_OPTION = 'com_templates';

	#[McpTool(
		name: 'panopticon_templateoverrides_list',
		description: 'List changed template overrides',
		readOnlyHint: true,
	)]
	public function listOverrides(
		#[Schema(description: 'Maximum number of items to return per page', minimum: 1)]
		?int $pageLimit = null,
		#[Schema(description: 'Starting record offset for pagination (0-based)', minimum: 0)]
		?int $pageOffset = null
	): array
	{
		$this->requireSuperUser();
		$this->requireAuthorised('core.manage', self::TEMPLATES_OPTION);

		/** @var \Akeeba\Component\Panopticon\Api\Model\TemplatechangedModel $model */
		$model = $this->createPanopticonModel('Templatechanged');

		return $this->listItems(
			$model,
			['filter.client_id' => 0],
			$pageLimit,
			$pageOffset,
			TemplateOverrideMapper::LIST_FIELDS,
			TemplateOverrideMapper::INT_FIELDS
		);
	}

	#[McpTool(
		name: 'panopticon_templateoverrides_read',
		description: 'Read details of a changed template override',
		readOnlyHint: true,
	)]
	public function readOverride(
		#[Schema(description: 'The ID of the changed template override to retrieve')]
		int $id
	): array
	{
		$this->requireSuperUser();
		$this->requireAuthorised('core.manage', self::TEMPLATES_OPTION);

		/** @var \Akeeba\Component\Panopticon\Api\Model\TemplatechangedModel $model */
		$model = $this->createPanopticonModel('Templatechanged');
		$model->setState('templatechanged.id', $id);

		try
		{
			$item = $model->getItem();
		}
		catch (\RuntimeException $e)
		{
			throw new ToolException($e->getCode() === 404 ? sprintf('Not found: template override #%d', $id) : $e->getMessage());
		}

		return $this->shape($item, TemplateOverrideMapper::ITEM_FIELDS, TemplateOverrideMapper::INT_FIELDS);
	}
}
