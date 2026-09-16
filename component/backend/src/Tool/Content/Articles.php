<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Tool\Content;

defined('_JEXEC') || die;

use Akeeba\Component\Mcp4j\Administrator\Bridge\AbstractTool;
use Akeeba\Component\Mcp4j\Administrator\Mapper\Content\ArticleMapper;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\McpTool;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\Schema;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Exception\ToolException;
use Joomla\CMS\Component\ComponentHelper;

/**
 * MCP tools for Joomla! articles (`com_content`) management.
 *
 * Ported from the original `joomla-mcp-php` project's `src/Server/Content/Articles.php` — method names,
 * tool names/descriptions, parameters and annotations are copied verbatim from there (see
 * `.plans/00-conventions.md` §2); only the method *bodies* differ (Joomla model calls instead of
 * HTTP/JSON:API calls), and `content_articles_delete`'s return type changes from the original's `bool` to
 * the framework-wide `['deleted' => true, 'id' => int]` shape (`.plans/00-conventions.md` §6) — see the
 * final report for the full list of deviations.
 *
 * @since  0.0.1
 */
class Articles extends AbstractTool
{
	private const OPTION = 'com_content';

	#[McpTool(
		name: 'content_articles_create',
		description: 'Create a new article'
	)]
	public function createArticle(
		#[Schema(description: 'Article title', minLength: 1, maxLength: 255)]
		string $title,
		#[Schema(description: 'Article category ID', minimum: 0, exclusiveMinimum: true)]
		int $catId,
		#[Schema(description: 'Article introductory (intro) text', minLength: 1)]
		string $introText,
		#[Schema(description: 'Article full text, without the intro text', minLength: 0)]
		string $fullText,
		#[Schema(
			description: 'Article publish state: 0=unpublished, 1=published, 2=archived, -2=trashed',
			enum: [0, 1, 2, -2]
		)]
		int $state = 1,
		#[Schema(description: 'When the article will start being published', format: 'date-time')]
		?string $publishStartTime = null,
		#[Schema(description: 'When the article will stop being published', format: 'date-time')]
		?string $publishEndTime = null,
		#[Schema(description: 'The Joomla! viewing access level for the article')]
		?int $access = null,
		#[Schema(description: 'Number of public views')]
		?int $hits = null,
		#[Schema(description: 'URL slug for the article', pattern: '^[a-z0-9-_]+$')]
		?string $alias = null,
		#[Schema(description: 'Language code for the article, or "*" for all languages', pattern: '^(\*|[a-z]{2}(-[A-Z]{2})?)$')]
		string $language = '*',
		#[Schema(description: 'When the article was originally created', format: 'date-time')]
		?string $createdTime = null,
		#[Schema(description: 'The ID of the Joomla user who originally created the article')]
		?int $createdBy = null,
		#[Schema(description: 'The name of the person who wrote the article, if it is different than the full name of the Joomla user who created it')]
		?string $createdByAlias = null,
		#[Schema(description: 'The intro image of the article, as a Joomla! image URL')]
		?string $imageIntro = null,
		#[Schema(description: 'The alt text of the intro image of the article. Empty string if it is a decorative image.')]
		?string $imageIntroAlt = null,
		#[Schema(description: 'The CSS class of the intro image of the article.')]
		?string $imageIntroFloat = null,
		#[Schema(description: 'The caption of the intro image of the article.')]
		?string $imageIntroCaption = null,
		#[Schema(description: 'The full text image of the article, as a Joomla! image URL')]
		?string $imageFulltext = null,
		#[Schema(description: 'The alt text of the full text image of the article. Empty string if it is a decorative image.')]
		?string $imageFulltextAlt = null,
		#[Schema(description: 'The CSS class of the full text image of the article.')]
		?string $imageFulltextFloat = null,
		#[Schema(description: 'The caption of the full text image of the article.')]
		?string $imageFulltextCaption = null,
		#[Schema(description: 'The URL to an optional first additional link to display at the bottom of the article')]
		?string $urlA = null,
		#[Schema(description: 'The text to an optional first additional link to display at the bottom of the article')]
		?string $urlAText = null,
		#[Schema(
			description: 'The link target to an optional first additional link to display at the bottom of the article',
			enum: [null, '_blank', '_self', '_parent', '_top']
		)]
		?string $urlATarget = null,
		#[Schema(description: 'The URL to an optional second additional link to display at the bottom of the article')]
		?string $urlB = null,
		#[Schema(description: 'The text to an optional second additional link to display at the bottom of the article')]
		?string $urlBText = null,
		#[Schema(
			description: 'The link target to an optional second additional link to display at the bottom of the article',
			enum: [null, '_blank', '_self', '_parent', '_top']
		)]
		?string $urlBTarget = null,
		#[Schema(description: 'The URL to an optional third additional link to display at the bottom of the article')]
		?string $urlC = null,
		#[Schema(description: 'The text to an optional third additional link to display at the bottom of the article')]
		?string $urlCText = null,
		#[Schema(
			description: 'The link target to an optional third additional link to display at the bottom of the article',
			enum: [null, '_blank', '_self', '_parent', '_top']
		)]
		?string $urlCTarget = null,
		#[Schema(description: 'Optional meta keywords, separated by commas (e.g., "keyword1, keyword2, keyword3")')]
		?string $metaKeywords = '',
		#[Schema(description: 'Optional meta description')]
		?string $metaDescription = '',
		#[Schema(
			description: 'The robots instruction for links to this article',
			enum: [null, 'index, follow', 'noindex, follow', 'index, nofollow', 'noindex, nofollow']
		)]
		?string $metadataRobots = null,
		#[Schema(description: 'The full name of the author of the article for use in metadata, if different than the full name of the Joomla! user who created the article')]
		?string $metadataAuthor = null,
		#[Schema(description: 'The content rights for the article, published as article metadata')]
		?string $metadataRights = null,
		#[Schema(description: 'Is the article featured?', enum: ['true', 'false'])]
		bool $featured = false,
		#[Schema(description: 'When the article will start being featured', format: 'date-time')]
		?string $featuredStartTime = null,
		#[Schema(description: 'When the article will stop being featured', format: 'date-time')]
		?string $featuredEndTime = null,
		#[Schema(description: 'Optional note for the article')]
		?string $note = null,
		#[Schema(description: 'An array of tag IDs to associate with the article', items: ['type' => 'integer'], minItems: 0, uniqueItems: true)]
		?array $tags = null,
		#[Schema(description: 'Optional nested images payload (Joomla format)')]
		?array $images = null,
		#[Schema(description: 'Optional nested URLs payload (Joomla format)')]
		?array $urls = null,
		#[Schema(description: 'Optional nested metadata payload (Joomla format)')]
		?array $metadata = null
	): array
	{
		// security.md I17: no component-wide core.manage gate here — real Joomla's own
		// ArticleController::allowAdd() (the backend controller this tool's write path is modeled on)
		// checks core.create on the specific target category when one is given, exactly what
		// requireAuthorised('core.create', ...) below already does; requiring core.manage on top was
		// stricter than core and refused a stock Author-shaped user (core.create but not core.manage)
		// that real Joomla's own backend would let create an article.

		// A content article can never auto-create a category on the fly through this tool (unlike
		// ArticleModel::save(), which would happily create one named after a nonexistent numeric catid —
		// see .plans/04-content.md Deviations item 5): the category must already exist and belong to
		// com_content, or this is refused loudly instead.
		$this->requireContentCategory($catId);

		$this->requireAuthorised('core.create', self::OPTION . '.category.' . $catId);

		// state defaults to 1 (published) and featured defaults to false; the framework gives no way to
		// tell "the caller explicitly passed the default value" apart from "the caller omitted it", so
		// only a value that actually differs from that no-op default counts as "given" here — see
		// ArticleMapper::touchesPublishingState()'s docblock.
		$touchesState = ArticleMapper::touchesPublishingState(
			$state !== 1 ? $state : null,
			$featured === true ? true : null,
			$publishStartTime,
			$publishEndTime,
			$featuredStartTime,
			$featuredEndTime
		);

		if ($touchesState)
		{
			// getForm() would silently unset() these fields for a user without core.edit.state on the
			// (new article's) category — refuse loudly instead of the LLM believing it set them.
			$this->requireAuthorised('core.edit.state', self::OPTION . '.category.' . $catId);
		}

		if ($touchesState && $this->workflowEnabled())
		{
			throw new ToolException(
				'This site uses Joomla workflows for articles; changing the publishing state through MCP4j is not supported.'
			);
		}

		$data = ArticleMapper::createData(
			$title,
			$catId,
			$introText,
			$fullText,
			$state,
			$publishStartTime,
			$publishEndTime,
			$access,
			$alias,
			$language,
			$createdTime,
			$createdBy,
			$createdByAlias,
			$imageIntro,
			$imageIntroAlt,
			$imageIntroFloat,
			$imageIntroCaption,
			$imageFulltext,
			$imageFulltextAlt,
			$imageFulltextFloat,
			$imageFulltextCaption,
			$urlA,
			$urlAText,
			$urlATarget,
			$urlB,
			$urlBText,
			$urlBTarget,
			$urlC,
			$urlCText,
			$urlCTarget,
			$metaKeywords,
			$metaDescription,
			$metadataRobots,
			$metadataAuthor,
			$metadataRights,
			$featured,
			$featuredStartTime,
			$featuredEndTime,
			$note,
			$tags,
			$images,
			$urls,
			$metadata
		);

		/** @var \Joomla\Component\Content\Administrator\Model\ArticleModel $model */
		$model = $this->createModel(self::OPTION, 'Article');

		// ArticleModel::save() reads $input->post->get('jform', [], 'array')['urls'][$i] directly for
		// every non-empty urla/urlb/urlc — see .plans/04-content.md Part D "Why the $input->post
		// workaround". saveItem() only populates the generic input, not $input->post.
		$this->getApplication()->getInput()->post->set('jform', ['urls' => $data['urls'] ?? []]);

		$newId = $this->saveItem($model, $data, null, 'article');

		return $this->readFresh($newId);
	}

	#[McpTool(
		name: 'content_articles_update',
		description: 'Update an existing article',
		idempotentHint: true,
	)]
	public function updateArticle(
		#[Schema(description: 'The ID of the article to modify')]
		int $articleId,
		#[Schema(description: 'Article title', minLength: 1, maxLength: 255)]
		?string $title = null,
		#[Schema(description: 'Article category ID', minimum: 0, exclusiveMinimum: true)]
		?int $catId = null,
		#[Schema(description: 'Article introductory (intro) text', minLength: 1)]
		?string $introText = null,
		#[Schema(description: 'Article full text, without the intro text', minLength: 0)]
		?string $fullText = null,
		#[Schema(
			description: 'Article publish state: null=no change, 0=unpublished, 1=published, 2=archived, -2=trashed',
			enum: [null, 0, 1, 2, -2]
		)]
		?int $state = null,
		#[Schema(description: 'When the article will start being published', format: 'date-time')]
		?string $publishStartTime = null,
		#[Schema(description: 'When the article will stop being published', format: 'date-time')]
		?string $publishEndTime = null,
		#[Schema(description: 'The Joomla! viewing access level for the article')]
		?int $access = null,
		#[Schema(description: 'Number of public views')]
		?int $hits = null,
		#[Schema(description: 'URL slug for the article', pattern: '^[a-z0-9-_]+$')]
		?string $alias = null,
		#[Schema(description: 'Language code for the article, or "*" for all languages', pattern: '^(\*|[a-z]{2}(-[A-Z]{2})?)$')]
		?string $language = null,
		#[Schema(description: 'When the article was originally created', format: 'date-time')]
		?string $createdTime = null,
		#[Schema(description: 'The ID of the Joomla user who originally created the article')]
		?int $createdBy = null,
		#[Schema(description: 'The name of the person who wrote the article, if it is different than the full name of the Joomla user who created it')]
		?string $createdByAlias = null,
		#[Schema(description: 'The intro image of the article, as a Joomla! image URL')]
		?string $imageIntro = null,
		#[Schema(description: 'The alt text of the intro image of the article. Empty string if it is a decorative image.')]
		?string $imageIntroAlt = null,
		#[Schema(description: 'The CSS class of the intro image of the article.')]
		?string $imageIntroFloat = null,
		#[Schema(description: 'The caption of the intro image of the article.')]
		?string $imageIntroCaption = null,
		#[Schema(description: 'The full text image of the article, as a Joomla! image URL')]
		?string $imageFulltext = null,
		#[Schema(description: 'The alt text of the full text image of the article. Empty string if it is a decorative image.')]
		?string $imageFulltextAlt = null,
		#[Schema(description: 'The CSS class of the full text image of the article.')]
		?string $imageFulltextFloat = null,
		#[Schema(description: 'The caption of the full text image of the article.')]
		?string $imageFulltextCaption = null,
		#[Schema(description: 'The URL to an optional first additional link to display at the bottom of the article')]
		?string $urlA = null,
		#[Schema(description: 'The text to an optional first additional link to display at the bottom of the article')]
		?string $urlAText = null,
		#[Schema(
			description: 'The link target to an optional first additional link to display at the bottom of the article',
			enum: [null, '_blank', '_self', '_parent', '_top']
		)]
		?string $urlATarget = null,
		#[Schema(description: 'The URL to an optional second additional link to display at the bottom of the article')]
		?string $urlB = null,
		#[Schema(description: 'The text to an optional second additional link to display at the bottom of the article')]
		?string $urlBText = null,
		#[Schema(
			description: 'The link target to an optional second additional link to display at the bottom of the article',
			enum: [null, '_blank', '_self', '_parent', '_top']
		)]
		?string $urlBTarget = null,
		#[Schema(description: 'The URL to an optional third additional link to display at the bottom of the article')]
		?string $urlC = null,
		#[Schema(description: 'The text to an optional third additional link to display at the bottom of the article')]
		?string $urlCText = null,
		#[Schema(
			description: 'The link target to an optional third additional link to display at the bottom of the article',
			enum: [null, '_blank', '_self', '_parent', '_top']
		)]
		?string $urlCTarget = null,
		#[Schema(description: 'Optional meta keywords, separated by commas (e.g., "keyword1, keyword2, keyword3")')]
		?string $metaKeywords = null,
		#[Schema(description: 'Optional meta description')]
		?string $metaDescription = null,
		#[Schema(
			description: 'The robots instruction for links to this article',
			enum: [null, 'index, follow', 'noindex, follow', 'index, nofollow', 'noindex, nofollow']
		)]
		?string $metadataRobots = null,
		#[Schema(description: 'The full name of the author of the article for use in metadata, if different than the full name of the Joomla! user who created the article')]
		?string $metadataAuthor = null,
		#[Schema(description: 'The content rights for the article, published as article metadata')]
		?string $metadataRights = null,
		#[Schema(description: 'Is the article featured?', enum: ['true', 'false'])]
		?bool $featured = null,
		#[Schema(description: 'When the article will start being featured', format: 'date-time')]
		?string $featuredStartTime = null,
		#[Schema(description: 'When the article will stop being featured', format: 'date-time')]
		?string $featuredEndTime = null,
		#[Schema(description: 'Optional note for the article')]
		?string $note = null,
		#[Schema(description: 'An array of tag IDs to associate with the article', items: ['type' => 'integer'], minItems: 0, uniqueItems: true)]
		?array $tags = null,
		#[Schema(description: 'Optional nested images payload (Joomla field names)')]
		?array $images = null,
		#[Schema(description: 'Optional nested URLs payload (Joomla field names)')]
		?array $urls = null,
		#[Schema(description: 'Optional nested metadata payload (Joomla field names)')]
		?array $metadata = null
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);

		/** @var \Joomla\Component\Content\Administrator\Model\ArticleModel $model */
		$model = $this->createModel(self::OPTION, 'Article');

		$current = $this->loadItem($model, $articleId, 'article');

		$ownerId = isset($current['created_by']) ? (int) $current['created_by'] : null;
		$this->requireEditAllowed(self::OPTION . '.article.' . $articleId, $ownerId);

		if ($catId !== null && $catId !== (int) ($current['catid'] ?? 0))
		{
			// Moving an article into a different category is, ACL-wise, "creating" it there — mirrors the
			// backend's batch-move check.
			$this->requireContentCategory($catId);
			$this->requireAuthorised('core.create', self::OPTION . '.category.' . $catId);
		}

		$touchesState = ArticleMapper::touchesPublishingState(
			$state,
			$featured,
			$publishStartTime,
			$publishEndTime,
			$featuredStartTime,
			$featuredEndTime
		);

		if ($touchesState)
		{
			$this->requireAuthorised('core.edit.state', self::OPTION . '.article.' . $articleId);
		}

		if ($touchesState && $this->workflowEnabled())
		{
			throw new ToolException(
				'This site uses Joomla workflows for articles; changing the publishing state through MCP4j is not supported.'
			);
		}

		$data = ArticleMapper::updateData(
			$title,
			$catId,
			$introText,
			$fullText,
			$state,
			$publishStartTime,
			$publishEndTime,
			$access,
			$alias,
			$language,
			$createdTime,
			$createdBy,
			$createdByAlias,
			$imageIntro,
			$imageIntroAlt,
			$imageIntroFloat,
			$imageIntroCaption,
			$imageFulltext,
			$imageFulltextAlt,
			$imageFulltextFloat,
			$imageFulltextCaption,
			$urlA,
			$urlAText,
			$urlATarget,
			$urlB,
			$urlBText,
			$urlBTarget,
			$urlC,
			$urlCText,
			$urlCTarget,
			$metaKeywords,
			$metaDescription,
			$metadataRobots,
			$metadataAuthor,
			$metadataRights,
			$featured,
			$featuredStartTime,
			$featuredEndTime,
			$note,
			$tags,
			$images,
			$urls,
			$metadata,
			$current
		);

		// See createArticle()'s docblock comment for why this is necessary.
		$this->getApplication()->getInput()->post->set('jform', ['urls' => $data['urls'] ?? []]);

		$this->saveItem($model, $data, $articleId, 'article');

		return $this->readFresh($articleId);
	}

	#[McpTool(
		name: 'content_articles_list',
		description: 'List existing articles',
		readOnlyHint: true,
	)]
	public function listArticles(
		#[Schema(description: 'The ID of the Joomla user who has created the returned articles', minimum: 0, exclusiveMinimum: true)]
		?int $filterAuthor = null,
		#[Schema(description: 'The ID of the Joomla articles category the returned articles belong into', minimum: 0, exclusiveMinimum: true)]
		?int $filterCategory = null,
		#[Schema(
			description: 'The article state of the returned articles: 0=unpublished, 1=published, 2=archived, -2=trashed, null=any state',
			enum: [null, 0, 1, 2, -2]
		)]
		?int $filterState = null,
		#[Schema(description: 'The featured state of the returned articles: 0=not featured, 1=featured, null=both featured and not featured',
			enum: [null, 0, 1]
		)]
		?int $filterFeatured = null,
		#[Schema(description: 'An array of tag IDs the returned articles must be assigned. NULL to return articles regardless of their tags.', items: ['type' => 'integer'], minItems: 0, uniqueItems: true)]
		?array $filterTag = null,
		#[Schema(description: 'The language code of the returned articles, "*" for articles explicitly assigned to "all languages", or NULL for articles assigned to any language', pattern: '^(\*|[a-z]{2}(-[A-Z]{2})?)$')]
		?string $filterLanguage = null,
		#[Schema(description: 'The returned articles must have a title that matches this search string', pattern: '^.*$')]
		?string $filterSearch = null,
		#[Schema(description: 'Maximum number of items to return per page', minimum: 1)]
		?int $pageLimit = null,
		#[Schema(description: 'Starting record offset for pagination (0-based)', minimum: 0)]
		?int $pageOffset = null
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);

		/** @var \Joomla\Component\Content\Administrator\Model\ArticlesModel $model */
		$model = $this->createModel(self::OPTION, 'Articles');

		return $this->listItems(
			$model,
			ArticleMapper::listState($filterAuthor, $filterCategory, $filterState, $filterFeatured, $filterTag, $filterLanguage, $filterSearch),
			$pageLimit,
			$pageOffset,
			ArticleMapper::LIST_FIELDS,
			ArticleMapper::INT_FIELDS,
			ArticleMapper::JSON_FIELDS
		);
	}

	#[McpTool(
		name: 'content_articles_read',
		description: 'Retrieve the information of the specified article',
		readOnlyHint: true,
	)]
	public function readArticle(
		#[Schema(description: 'The ID of the article to retrieve')]
		int $id
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);

		return $this->readFresh($id);
	}

	#[McpTool(
		name: 'content_articles_trash',
		description: 'Moves an article to the trash by setting its state to -2',
		destructiveHint: true,
	)]
	public function trashArticle(
		#[Schema(description: 'The ID of the article to trash')]
		int $id
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);

		/** @var \Joomla\Component\Content\Administrator\Model\ArticleModel $model */
		$model = $this->createModel(self::OPTION, 'Article');

		$this->loadItem($model, $id, 'article');

		$this->requireAuthorised('core.edit.state', self::OPTION . '.article.' . $id);

		if ($this->workflowEnabled())
		{
			throw new ToolException(
				'This site uses Joomla workflows for articles; changing the publishing state through MCP4j is not supported.'
			);
		}

		$this->changeState($model, $id, -2, 'article');

		return $this->readFresh($id);
	}

	#[McpTool(
		name: 'content_articles_delete',
		description: 'Permanently deletes an article. Automatically trashes it first if needed.',
		destructiveHint: true,
	)]
	public function deleteArticle(int $id): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);

		/** @var \Joomla\Component\Content\Administrator\Model\ArticleModel $model */
		$model = $this->createModel(self::OPTION, 'Article');

		$current = $this->loadItem($model, $id, 'article');

		$this->requireAuthorised('core.delete', self::OPTION . '.article.' . $id);

		// AdminModel::publish() prunes (and no-ops on) a record already at the target state — see
		// AbstractTool::changeState()'s docblock — so only trash when it isn't already trashed, exactly
		// this tool's own contract ("automatically trashes it first if needed").
		if ((int) ($current['state'] ?? 0) !== -2)
		{
			if ($this->workflowEnabled())
			{
				throw new ToolException(
					'This site uses Joomla workflows for articles; changing the publishing state through MCP4j is not supported.'
				);
			}

			/** @var \Joomla\Component\Content\Administrator\Model\ArticleModel $trashModel */
			$trashModel = $this->createModel(self::OPTION, 'Article');

			$this->changeState($trashModel, $id, -2, 'article');
		}

		/** @var \Joomla\Component\Content\Administrator\Model\ArticleModel $deleteModel */
		$deleteModel = $this->createModel(self::OPTION, 'Article');

		$this->deleteItem($deleteModel, $id, 'article');

		return ['deleted' => true, 'id' => $id];
	}

	private function workflowEnabled(): bool
	{
		return (bool) ComponentHelper::getParams(self::OPTION)->get('workflow_enabled');
	}

	/**
	 * `CategoryModel` reads `extension` from the request **input**, not from model state — set it before
	 * every `createModel('com_categories', ...)` (see `.plans/00-conventions.md` §5.4-5.5). Throws
	 * `Not found: category #<id>` both when the category doesn't exist at all and when it belongs to a
	 * different extension — `ArticleModel::save()` would otherwise silently create a category named after
	 * the number for a nonexistent numeric catid (see `.plans/04-content.md` Deviations item 5); this tool
	 * refuses instead.
	 *
	 * @throws  ToolException
	 *
	 * @return  array<string, mixed>
	 */
	private function requireContentCategory(int $catId): array
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
	 * Re-reads an article through a fresh model and shapes it — used by every tool method that returns a
	 * record, so a just-saved/state-changed article is always read back rather than assembled from
	 * in-memory data (see `.plans/00-conventions.md` Part D, "Create a fresh model for each save / state
	 * change / reload").
	 *
	 * @return  array<string, mixed>
	 */
	private function readFresh(int $id): array
	{
		/** @var \Joomla\Component\Content\Administrator\Model\ArticleModel $model */
		$model = $this->createModel(self::OPTION, 'Article');

		return $this->shape(
			ArticleMapper::normaliseItem($this->loadItem($model, $id, 'article')),
			ArticleMapper::ITEM_FIELDS,
			ArticleMapper::INT_FIELDS,
			ArticleMapper::JSON_FIELDS
		);
	}
}
