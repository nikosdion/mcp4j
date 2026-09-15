<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Tool\Tags;

defined('_JEXEC') || die;

use Akeeba\Component\Mcp4j\Administrator\Bridge\AbstractTool;
use Akeeba\Component\Mcp4j\Administrator\Mapper\Tags\TagMapper;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\McpTool;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\Schema;

/**
 * MCP tools for Joomla! tags management.
 *
 * The pilot port proving {@see AbstractTool} out end-to-end: simple CRUD, a nested set, publish state, no
 * categories, no custom fields. Ported from the original `joomla-mcp-php` project's
 * `src/Server/Tags/Tags.php` — method names, tool names/descriptions, parameters and annotations are
 * copied verbatim from there (see `.plans/00-conventions.md` §2); only the method *bodies* differ (Joomla
 * model calls instead of HTTP/JSON:API calls), and `*_delete`'s return type changes from the original's
 * `bool` to the framework-wide `['deleted' => true, 'id' => int]` shape (`.plans/00-conventions.md` §6) —
 * see the final report for the full list of deviations.
 *
 * @since  0.0.1
 */
class Tags extends AbstractTool
{
	private const OPTION = 'com_tags';

	#[McpTool(
		name: 'tags_list',
		description: 'List existing tags',
		readOnlyHint: true,
	)]
	public function listTags(
		#[Schema(description: 'Search filter for the tag title')]
		?string $filterSearch = null,
		#[Schema(
			description: 'Filter by publish state: 0=unpublished, 1=published, 2=archived, -2=trashed',
			enum: [null, 0, 1, 2, -2]
		)]
		?int $filterState = null,
		#[Schema(description: 'Filter by language code, or "*" for all languages', pattern: '^(\*|[a-z]{2}(-[A-Z]{2})?)$')]
		?string $filterLanguage = null,
		#[Schema(description: 'Maximum number of items to return per page', minimum: 1)]
		?int $pageLimit = null,
		#[Schema(description: 'Starting record offset for pagination (0-based)', minimum: 0)]
		?int $pageOffset = null
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);

		/** @var \Joomla\Component\Tags\Administrator\Model\TagsModel $model */
		$model = $this->createModel(self::OPTION, 'Tags');

		return $this->listItems(
			$model,
			TagMapper::listState($filterSearch, $filterState, $filterLanguage),
			$pageLimit,
			$pageOffset,
			TagMapper::LIST_FIELDS,
			TagMapper::INT_FIELDS,
			TagMapper::JSON_FIELDS
		);
	}

	#[McpTool(
		name: 'tags_read',
		description: 'Retrieve the information of the specified tag',
		readOnlyHint: true,
	)]
	public function readTag(
		#[Schema(description: 'The ID of the tag to retrieve')]
		int $id
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);

		/** @var \Joomla\Component\Tags\Administrator\Model\TagModel $model */
		$model = $this->createModel(self::OPTION, 'Tag');

		return $this->shape(
			$this->loadItem($model, $id, 'tag'),
			TagMapper::ITEM_FIELDS,
			TagMapper::INT_FIELDS,
			TagMapper::JSON_FIELDS
		);
	}

	#[McpTool(
		name: 'tags_create',
		description: 'Create a new tag'
	)]
	public function createTag(
		#[Schema(description: 'Tag title', minLength: 1, maxLength: 255)]
		string $title,
		#[Schema(description: 'URL slug for the tag', pattern: '^[a-z0-9-_]+$')]
		?string $alias = null,
		#[Schema(
			description: 'Publish state: 0=unpublished, 1=published, 2=archived, -2=trashed',
			enum: [0, 1, 2, -2]
		)]
		int $published = 1,
		#[Schema(description: 'Parent tag ID, 0 for root')]
		?int $parentId = 0,
		#[Schema(description: 'The Joomla! viewing access level for the tag')]
		?int $access = null,
		#[Schema(description: 'Language code for the tag, or "*" for all languages', pattern: '^(\*|[a-z]{2}(-[A-Z]{2})?)$')]
		string $language = '*',
		#[Schema(description: 'Tag description')]
		?string $description = null,
		#[Schema(description: 'Optional meta description')]
		?string $metadesc = null,
		#[Schema(description: 'Optional meta keywords, separated by commas')]
		?string $metakey = null,
		#[Schema(description: 'Optional note for the tag')]
		?string $note = null
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);
		$this->requireAuthorised('core.create', self::OPTION);

		/** @var \Joomla\Component\Tags\Administrator\Model\TagModel $model */
		$model = $this->createModel(self::OPTION, 'Tag');

		$data = TagMapper::createData(
			$title,
			$alias,
			$published,
			$parentId,
			$access,
			$language,
			$description,
			$metadesc,
			$metakey,
			$note
		);

		$newId = $this->saveItem($model, $data, null, 'tag');

		/** @var \Joomla\Component\Tags\Administrator\Model\TagModel $freshModel */
		$freshModel = $this->createModel(self::OPTION, 'Tag');

		return $this->shape(
			$this->loadItem($freshModel, $newId, 'tag'),
			TagMapper::ITEM_FIELDS,
			TagMapper::INT_FIELDS,
			TagMapper::JSON_FIELDS
		);
	}

	#[McpTool(
		name: 'tags_update',
		description: 'Update an existing tag',
		idempotentHint: true,
	)]
	public function updateTag(
		#[Schema(description: 'The ID of the tag to modify')]
		int $id,
		#[Schema(description: 'Tag title', minLength: 1, maxLength: 255)]
		?string $title = null,
		#[Schema(description: 'URL slug for the tag', pattern: '^[a-z0-9-_]+$')]
		?string $alias = null,
		#[Schema(
			description: 'Publish state: null=no change, 0=unpublished, 1=published, 2=archived, -2=trashed',
			enum: [null, 0, 1, 2, -2]
		)]
		?int $published = null,
		#[Schema(description: 'Parent tag ID, 0 for root')]
		?int $parentId = null,
		#[Schema(description: 'The Joomla! viewing access level for the tag')]
		?int $access = null,
		#[Schema(description: 'Language code for the tag, or "*" for all languages', pattern: '^(\*|[a-z]{2}(-[A-Z]{2})?)$')]
		?string $language = null,
		#[Schema(description: 'Tag description')]
		?string $description = null,
		#[Schema(description: 'Optional meta description')]
		?string $metadesc = null,
		#[Schema(description: 'Optional meta keywords, separated by commas')]
		?string $metakey = null,
		#[Schema(description: 'Optional note for the tag')]
		?string $note = null
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);

		/** @var \Joomla\Component\Tags\Administrator\Model\TagModel $model */
		$model = $this->createModel(self::OPTION, 'Tag');

		$current = $this->loadItem($model, $id, 'tag');

		$ownerId = isset($current['created_user_id']) ? (int) $current['created_user_id'] : null;

		$this->requireEditAllowed(self::OPTION, $ownerId);

		$data = TagMapper::updateData(
			$title,
			$alias,
			$published,
			$parentId,
			$access,
			$language,
			$description,
			$metadesc,
			$metakey,
			$note
		);

		$this->saveItem($model, $data, $id, 'tag');

		/** @var \Joomla\Component\Tags\Administrator\Model\TagModel $freshModel */
		$freshModel = $this->createModel(self::OPTION, 'Tag');

		return $this->shape(
			$this->loadItem($freshModel, $id, 'tag'),
			TagMapper::ITEM_FIELDS,
			TagMapper::INT_FIELDS,
			TagMapper::JSON_FIELDS
		);
	}

	#[McpTool(
		name: 'tags_trash',
		description: 'Moves a tag to the trash by setting its state to -2',
		destructiveHint: true,
	)]
	public function trashTag(
		#[Schema(description: 'The ID of the tag to trash')]
		int $id
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);
		$this->requireAuthorised('core.edit.state', self::OPTION);

		/** @var \Joomla\Component\Tags\Administrator\Model\TagModel $model */
		$model = $this->createModel(self::OPTION, 'Tag');

		$this->changeState($model, $id, -2, 'tag');

		/** @var \Joomla\Component\Tags\Administrator\Model\TagModel $freshModel */
		$freshModel = $this->createModel(self::OPTION, 'Tag');

		return $this->shape(
			$this->loadItem($freshModel, $id, 'tag'),
			TagMapper::ITEM_FIELDS,
			TagMapper::INT_FIELDS,
			TagMapper::JSON_FIELDS
		);
	}

	#[McpTool(
		name: 'tags_delete',
		description: 'Permanently deletes a tag. Automatically trashes it first if needed.',
		destructiveHint: true,
	)]
	public function deleteTag(
		#[Schema(description: 'The ID of the tag to delete')]
		int $id
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);
		$this->requireAuthorised('core.delete', self::OPTION);

		/** @var \Joomla\Component\Tags\Administrator\Model\TagModel $model */
		$model = $this->createModel(self::OPTION, 'Tag');

		$current = $this->loadItem($model, $id, 'tag');

		// AdminModel::publish() prunes (and no-ops on) a record that is already at the target state — see
		// AbstractTool::changeState()'s docblock — so only trash when it isn't already trashed, which is
		// exactly this tool's own contract ("automatically trashes it first if needed").
		if ((int) ($current['published'] ?? 0) !== -2)
		{
			/** @var \Joomla\Component\Tags\Administrator\Model\TagModel $trashModel */
			$trashModel = $this->createModel(self::OPTION, 'Tag');

			$this->changeState($trashModel, $id, -2, 'tag');
		}

		/** @var \Joomla\Component\Tags\Administrator\Model\TagModel $deleteModel */
		$deleteModel = $this->createModel(self::OPTION, 'Tag');

		$this->deleteItem($deleteModel, $id, 'tag');

		return ['deleted' => true, 'id' => $id];
	}
}
