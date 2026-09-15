<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Tool\Newsfeeds;

defined('_JEXEC') || die;

use Akeeba\Component\Mcp4j\Administrator\Bridge\AbstractTool;
use Akeeba\Component\Mcp4j\Administrator\Mapper\Newsfeeds\FeedMapper;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\McpTool;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\Schema;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Exception\ToolException;

/**
 * MCP tools for Joomla! newsfeeds (`com_newsfeeds`) management.
 *
 * Ported from the original `joomla-mcp-php` project's `src/Server/Newsfeeds/Feeds.php` — method names,
 * tool names/descriptions, parameters and annotations are copied verbatim from there (see
 * `.plans/00-conventions.md` §2); only the method *bodies* differ (Joomla model calls instead of
 * HTTP/JSON:API calls), and `newsfeeds_delete`'s return type changes from the original's `bool` to the
 * framework-wide `['deleted' => true, 'id' => int]` shape — see the final report for the full list of
 * deviations.
 *
 * @since  0.0.1
 */
class Feeds extends AbstractTool
{
	private const OPTION = 'com_newsfeeds';

	#[McpTool(
		name: 'newsfeeds_list',
		description: 'List existing newsfeeds',
		readOnlyHint: true,
	)]
	public function listFeeds(
		#[Schema(description: 'Search filter for the newsfeed name')]
		?string $filterSearch = null,
		#[Schema(
			description: 'Filter by publish state: 0=unpublished, 1=published, 2=archived, -2=trashed',
			enum: [null, 0, 1, 2, -2]
		)]
		?int $filterState = null,
		#[Schema(description: 'Filter by category ID')]
		?int $filterCategory = null,
		#[Schema(description: 'Filter by language code, or "*" for all languages', pattern: '^(\*|[a-z]{2}(-[A-Z]{2})?)$')]
		?string $filterLanguage = null,
		#[Schema(description: 'Maximum number of items to return per page', minimum: 1)]
		?int $pageLimit = null,
		#[Schema(description: 'Starting record offset for pagination (0-based)', minimum: 0)]
		?int $pageOffset = null
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);

		/** @var \Joomla\Component\Newsfeeds\Administrator\Model\NewsfeedsModel $model */
		$model = $this->createModel(self::OPTION, 'Newsfeeds');

		return $this->listItems(
			$model,
			FeedMapper::listState($filterSearch, $filterState, $filterCategory, $filterLanguage),
			$pageLimit,
			$pageOffset,
			FeedMapper::LIST_FIELDS,
			FeedMapper::INT_FIELDS,
			FeedMapper::JSON_FIELDS
		);
	}

	#[McpTool(
		name: 'newsfeeds_read',
		description: 'Retrieve the information of the specified newsfeed',
		readOnlyHint: true,
	)]
	public function readFeed(
		#[Schema(description: 'The ID of the newsfeed to retrieve')]
		int $id
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);

		return $this->readFresh($id);
	}

	#[McpTool(
		name: 'newsfeeds_create',
		description: 'Create a new newsfeed'
	)]
	public function createFeed(
		#[Schema(description: 'Newsfeed name', minLength: 1, maxLength: 255)]
		string $name,
		#[Schema(description: 'Feed URL', format: 'uri')]
		string $link,
		#[Schema(description: 'Newsfeed category ID', minimum: 0, exclusiveMinimum: true)]
		int $catId,
		#[Schema(description: 'URL slug for the newsfeed', pattern: '^[a-z0-9-_]+$')]
		?string $alias = null,
		#[Schema(
			description: 'Publish state: 0=unpublished, 1=published, 2=archived, -2=trashed',
			enum: [0, 1, 2, -2]
		)]
		int $published = 1,
		#[Schema(description: 'The Joomla! viewing access level for the newsfeed')]
		?int $access = null,
		#[Schema(description: 'Language code for the newsfeed, or "*" for all languages', pattern: '^(\*|[a-z]{2}(-[A-Z]{2})?)$')]
		string $language = '*',
		#[Schema(description: 'Newsfeed description')]
		?string $description = null,
		#[Schema(description: 'Number of articles to display from the feed', minimum: 0)]
		?int $numArticles = null,
		#[Schema(description: 'Cache time in seconds', minimum: 0)]
		?int $cacheTime = null,
		#[Schema(description: 'Newsfeed ordering')]
		?int $ordering = null,
		#[Schema(description: 'An array of tag IDs to associate with the newsfeed', items: ['type' => 'integer'], minItems: 0, uniqueItems: true)]
		?array $tags = null,
		#[Schema(description: 'Optional meta description')]
		?string $metadesc = null,
		#[Schema(description: 'Optional meta keywords, separated by commas')]
		?string $metakey = null
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);

		// A newsfeed can never auto-create a category on the fly through this tool (unlike
		// NewsfeedModel::save(), which would happily create one named after a nonexistent numeric catid) —
		// the category must already exist and belong to com_newsfeeds, or this is refused loudly instead.
		$this->requireNewsfeedsCategory($catId);

		$this->requireAuthorised('core.create', self::OPTION . '.category.' . $catId);

		// getForm() would silently unset() ordering/published/publish_up/publish_down for a user without
		// core.edit.state on the (new newsfeed's) category — refuse loudly instead of the LLM believing it
		// set them. $published's non-nullable default (1) only counts as "given" when it actually differs
		// from the no-op default, same convention as content_articles_create's $state/$featured.
		if ($published !== 1 || $ordering !== null)
		{
			$this->requireAuthorised('core.edit.state', self::OPTION . '.category.' . $catId);
		}

		$data = FeedMapper::createData(
			$name,
			$link,
			$catId,
			$alias,
			$published,
			$access,
			$language,
			$description,
			$numArticles,
			$cacheTime,
			$ordering,
			$tags,
			$metadesc,
			$metakey
		);

		/** @var \Joomla\Component\Newsfeeds\Administrator\Model\NewsfeedModel $model */
		$model = $this->createModel(self::OPTION, 'Newsfeed');

		$newId = $this->saveItem($model, $data, null, 'newsfeed');

		return $this->readFresh($newId);
	}

	#[McpTool(
		name: 'newsfeeds_update',
		description: 'Update an existing newsfeed',
		idempotentHint: true,
	)]
	public function updateFeed(
		#[Schema(description: 'The ID of the newsfeed to modify')]
		int $id,
		#[Schema(description: 'Newsfeed name', minLength: 1, maxLength: 255)]
		?string $name = null,
		#[Schema(description: 'Feed URL', format: 'uri')]
		?string $link = null,
		#[Schema(description: 'Newsfeed category ID', minimum: 0, exclusiveMinimum: true)]
		?int $catId = null,
		#[Schema(description: 'URL slug for the newsfeed', pattern: '^[a-z0-9-_]+$')]
		?string $alias = null,
		#[Schema(
			description: 'Publish state: null=no change, 0=unpublished, 1=published, 2=archived, -2=trashed',
			enum: [null, 0, 1, 2, -2]
		)]
		?int $published = null,
		#[Schema(description: 'The Joomla! viewing access level for the newsfeed')]
		?int $access = null,
		#[Schema(description: 'Language code for the newsfeed, or "*" for all languages', pattern: '^(\*|[a-z]{2}(-[A-Z]{2})?)$')]
		?string $language = null,
		#[Schema(description: 'Newsfeed description')]
		?string $description = null,
		#[Schema(description: 'Number of articles to display from the feed', minimum: 0)]
		?int $numArticles = null,
		#[Schema(description: 'Cache time in seconds', minimum: 0)]
		?int $cacheTime = null,
		#[Schema(description: 'Newsfeed ordering')]
		?int $ordering = null,
		#[Schema(description: 'An array of tag IDs to associate with the newsfeed', items: ['type' => 'integer'], minItems: 0, uniqueItems: true)]
		?array $tags = null,
		#[Schema(description: 'Optional meta description')]
		?string $metadesc = null,
		#[Schema(description: 'Optional meta keywords, separated by commas')]
		?string $metakey = null
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);

		/** @var \Joomla\Component\Newsfeeds\Administrator\Model\NewsfeedModel $model */
		$model = $this->createModel(self::OPTION, 'Newsfeed');

		$current   = FeedMapper::normaliseItem($this->loadItem($model, $id, 'newsfeed'));
		$currentCatId = (int) ($current['catid'] ?? 0);

		// The backend controller's allowEdit() checks core.edit (or core.edit.own + ownership) on the
		// CATEGORY asset, not on the newsfeed itself — requireEditAllowed() checks a single asset, so it is
		// called with that category asset, exactly matching the backend's own logic.
		$ownerId = isset($current['created_by']) ? (int) $current['created_by'] : null;
		$this->requireEditAllowed(self::OPTION . '.category.' . $currentCatId, $ownerId);

		if ($catId !== null && $catId !== $currentCatId)
		{
			// Moving a newsfeed into a different category is, ACL-wise, "creating" it there.
			$this->requireNewsfeedsCategory($catId);
			$this->requireAuthorised('core.create', self::OPTION . '.category.' . $catId);
		}

		if ($published !== null)
		{
			$this->requireAuthorised('core.edit.state', self::OPTION . '.category.' . $currentCatId);
		}

		$data = FeedMapper::updateData(
			$name,
			$link,
			$catId,
			$alias,
			$published,
			$access,
			$language,
			$description,
			$numArticles,
			$cacheTime,
			$ordering,
			$tags,
			$metadesc,
			$metakey,
			$current
		);

		$this->saveItem($model, $data, $id, 'newsfeed');

		return $this->readFresh($id);
	}

	#[McpTool(
		name: 'newsfeeds_trash',
		description: 'Moves a newsfeed to the trash by setting its state to -2',
		destructiveHint: true,
	)]
	public function trashFeed(
		#[Schema(description: 'The ID of the newsfeed to trash')]
		int $id
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);

		/** @var \Joomla\Component\Newsfeeds\Administrator\Model\NewsfeedModel $model */
		$model = $this->createModel(self::OPTION, 'Newsfeed');

		$current = $this->loadItem($model, $id, 'newsfeed');

		$this->requireAuthorised('core.edit.state', self::OPTION . '.category.' . (int) ($current['catid'] ?? 0));

		$this->changeState($model, $id, -2, 'newsfeed');

		return $this->readFresh($id);
	}

	#[McpTool(
		name: 'newsfeeds_delete',
		description: 'Permanently deletes a newsfeed. Automatically trashes it first if needed.',
		destructiveHint: true,
	)]
	public function deleteFeed(
		#[Schema(description: 'The ID of the newsfeed to delete')]
		int $id
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);

		/** @var \Joomla\Component\Newsfeeds\Administrator\Model\NewsfeedModel $model */
		$model = $this->createModel(self::OPTION, 'Newsfeed');

		$current = $this->loadItem($model, $id, 'newsfeed');

		$this->requireAuthorised('core.delete', self::OPTION . '.category.' . (int) ($current['catid'] ?? 0));

		// AdminModel::publish() prunes (and no-ops on) a record already at the target state — see
		// AbstractTool::changeState()'s docblock — so only trash when it isn't already trashed, exactly
		// this tool's own contract ("automatically trashes it first if needed").
		if ((int) ($current['published'] ?? 0) !== -2)
		{
			/** @var \Joomla\Component\Newsfeeds\Administrator\Model\NewsfeedModel $trashModel */
			$trashModel = $this->createModel(self::OPTION, 'Newsfeed');

			$this->changeState($trashModel, $id, -2, 'newsfeed');
		}

		/** @var \Joomla\Component\Newsfeeds\Administrator\Model\NewsfeedModel $deleteModel */
		$deleteModel = $this->createModel(self::OPTION, 'Newsfeed');

		$this->deleteItem($deleteModel, $id, 'newsfeed');

		return ['deleted' => true, 'id' => $id];
	}

	/**
	 * `CategoryModel` reads `extension` from the request **input**, not from model state — set it before
	 * every `createModel('com_categories', ...)` (see `.plans/00-conventions.md` §5.4-5.5). Throws
	 * `Not found: category #<id>` both when the category doesn't exist at all and when it belongs to a
	 * different extension — `NewsfeedModel::save()` would otherwise silently create a category named after
	 * the number for a nonexistent numeric catid; this tool refuses instead.
	 *
	 * @throws  ToolException
	 *
	 * @return  array<string, mixed>
	 */
	private function requireNewsfeedsCategory(int $catId): array
	{
		$this->getApplication()->getInput()->set('extension', self::OPTION);

		/** @var \Joomla\Component\Categories\Administrator\Model\CategoryModel $model */
		$model = $this->createModel('com_categories', 'Category');

		$item = $this->loadItem($model, $catId, 'category');

		if (($item['extension'] ?? null) !== self::OPTION)
		{
			throw new ToolException(sprintf('Not found: category #%d', $catId));
		}

		return $item;
	}

	/**
	 * Re-reads a newsfeed through a fresh model and shapes it — used by every tool method that returns a
	 * record, so a just-saved/state-changed newsfeed is always read back rather than assembled from
	 * in-memory data.
	 *
	 * @return  array<string, mixed>
	 */
	private function readFresh(int $id): array
	{
		/** @var \Joomla\Component\Newsfeeds\Administrator\Model\NewsfeedModel $model */
		$model = $this->createModel(self::OPTION, 'Newsfeed');

		return $this->shape(
			FeedMapper::normaliseItem($this->loadItem($model, $id, 'newsfeed')),
			FeedMapper::ITEM_FIELDS,
			FeedMapper::INT_FIELDS,
			FeedMapper::JSON_FIELDS
		);
	}
}
