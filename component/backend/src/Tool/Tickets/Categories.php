<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Tool\Tickets;

defined('_JEXEC') || die;

use Akeeba\Component\ATS\Administrator\Helper\Permissions;
use Akeeba\Component\Mcp4j\Administrator\Mapper\Tickets\TicketCategoryMapper;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\McpTool;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\Schema;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\ToolRequirements;
use Akeeba\Component\Mcp4j\Administrator\Shared\AbstractAtsTool;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\Component\Categories\Administrator\Model\CategoriesModel;
use Joomla\Component\Categories\Administrator\Model\CategoryModel;

/**
 * MCP tools for Akeeba Ticket System (ATS) ticket categories — `com_ats` categories are ordinary
 * `com_categories` records filtered to `extension = 'com_ats'`.
 *
 * Ported from the original `joomla-mcp-php` project's `src/Server/Tickets/Categories.php` — method names,
 * tool names/descriptions, parameters and annotations copied verbatim (see `.plans/00-conventions.md`
 * §2); only the bodies differ.
 *
 * @since  0.0.1
 */
#[ToolRequirements(components: ['com_ats'])]
class Categories extends AbstractAtsTool
{
	#[McpTool(
		name: 'tickets_categories_list',
		description: 'List ATS (Akeeba Ticket System) ticket categories',
		readOnlyHint: true,
	)]
	public function listCategories(
		#[Schema(description: 'Search filter for categories')]
		?string $filterSearch = null,
		#[Schema(description: 'Maximum number of items to return per page', minimum: 1)]
		?int $pageLimit = null,
		#[Schema(description: 'Starting record offset for pagination (0-based)', minimum: 0)]
		?int $pageOffset = null
	): array
	{
		$this->bootAts();
		$this->requireAtsManager();

		// security.md M5: CategoriesModel's own filtering is a plain Joomla content view-access level,
		// not the per-category manage-ACL check Permissions::isManager()/getManagerCategories() apply —
		// the same gap M4 fixes for the ticket/post/attachment list tools, closed here the same way.
		$allowed = array_values(array_unique(array_map(
			'intval',
			Permissions::getManagerCategories((int) $this->getUser()->id)
		)));

		/** @var CategoriesModel $model */
		$model = $this->createModel('com_categories', 'Categories');

		return $this->listPageVisible(
			$model,
			TicketCategoryMapper::listState($filterSearch),
			$pageLimit,
			$pageOffset,
			fn (object $item): bool => in_array((int) ($item->id ?? 0), $allowed, true),
			fn (object $item): array => $this->shape(
				$item,
				TicketCategoryMapper::LIST_FIELDS,
				TicketCategoryMapper::INT_FIELDS,
				TicketCategoryMapper::JSON_FIELDS
			)
		);
	}

	#[McpTool(
		name: 'tickets_categories_read',
		description: 'Read details of a specific ATS (Akeeba Ticket System) ticket category',
		readOnlyHint: true,
	)]
	public function readCategory(
		#[Schema(description: 'The ID of the category to retrieve')]
		int $id
	): array
	{
		$this->bootAts();
		$this->requireAtsManager();

		// Asserts $id is really a com_ats category — CategoryModel/loadItem() alone would happily load
		// (and let us read) a category belonging to any other extension.
		$this->requireAtsCategory($id);

		/** @var CategoryModel $model */
		$model = $this->createModel('com_categories', 'Category');
		$model->setState('category.extension', 'com_ats');
		$model->setState('category.component', 'com_ats');
		$model->setState('params', ComponentHelper::getParams('com_categories'));

		return $this->shape(
			$this->loadItem($model, $id, 'ticket category'),
			TicketCategoryMapper::ITEM_FIELDS,
			TicketCategoryMapper::INT_FIELDS,
			TicketCategoryMapper::JSON_FIELDS
		);
	}
}
