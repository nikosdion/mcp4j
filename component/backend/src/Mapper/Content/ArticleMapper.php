<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Mapper\Content;

defined('_JEXEC') || die;

use Akeeba\Component\Mcp4j\Administrator\Bridge\Support\NullFilter;
use Akeeba\Component\Mcp4j\Administrator\Bridge\Support\TagIds;

/**
 * Pure input/output mapping for the `content_articles_*` tools: tool parameters -> `ArticleModel`/
 * `ArticlesModel` state and save data, and the output field whitelist.
 *
 * `ITEM_FIELDS`/`LIST_FIELDS` are derived from core's own Web Services API view for articles
 * (`api/components/com_content/src/View/Articles/JsonapiView.php`), with deliberate changes: `introtext`
 * and `fulltext` separately instead of core's concatenated `text` (lossless, and it is what
 * `updateArticle()` takes as input); `catid`/`category_title` instead of the JSON:API `category`
 * relationship; `tags` as a plain id list; no `asset_id`, `typeAlias`, `schemaorg`, `attribs` (article
 * options are not settable through these tools) — see `.plans/04-content.md` Part D.
 *
 * Pure: no Joomla.
 *
 * @since  0.0.1
 */
final class ArticleMapper
{
	public const ITEM_FIELDS = [
		'id',
		'title',
		'alias',
		'catid',
		'state',
		'featured',
		'featured_up',
		'featured_down',
		'access',
		'language',
		'introtext',
		'fulltext',
		'images',
		'urls',
		'metakey',
		'metadesc',
		'metadata',
		'note',
		'publish_up',
		'publish_down',
		'created',
		'created_by',
		'created_by_alias',
		'modified',
		'modified_by',
		'checked_out',
		'checked_out_time',
		'hits',
		'version',
		'tags',
	];

	public const LIST_FIELDS = [
		'id',
		'title',
		'alias',
		'catid',
		'category_title',
		'state',
		'featured',
		'featured_up',
		'featured_down',
		'access',
		'access_level',
		'language',
		'language_title',
		'introtext',
		'fulltext',
		'images',
		'metakey',
		'metadesc',
		'metadata',
		'note',
		'publish_up',
		'publish_down',
		'created',
		'created_by',
		'created_by_alias',
		'author_name',
		'modified',
		'checked_out',
		'checked_out_time',
		'editor',
		'hits',
		'version',
	];

	public const INT_FIELDS = ['catid', 'state', 'featured', 'access', 'created_by', 'modified_by', 'checked_out', 'hits', 'version'];

	public const JSON_FIELDS = ['images', 'urls', 'metadata'];

	/**
	 * The marker Joomla's own frontend article view (and `Table\Content::bind()`, which splits on it)
	 * uses between an article's intro and full text.
	 */
	public const READMORE = '<hr id="system-readmore">';

	/**
	 * `ArticlesModel::getListQuery()` state — same keys core's own API controller maps filters onto
	 * (`author -> filter.author_id`, `category -> filter.category_id`, `state -> filter.published`,
	 * `featured -> filter.featured`, `tag -> filter.tag`). See `.plans/04-content.md` Part D.
	 *
	 * @return  array<string, mixed>
	 */
	public static function listState(
		?int $filterAuthor,
		?int $filterCategory,
		?int $filterState,
		?int $filterFeatured,
		?array $filterTag,
		?string $filterLanguage,
		?string $filterSearch
	): array {
		$data = [
			'list.ordering'  => 'a.id',
			'list.direction' => 'DESC',
		];

		if ($filterAuthor !== null)
		{
			$data['filter.author_id'] = $filterAuthor;
		}

		if ($filterCategory !== null)
		{
			$data['filter.category_id'] = $filterCategory;
		}

		$data['filter.published'] = $filterState !== null ? (string) $filterState : '';

		if ($filterFeatured !== null)
		{
			$data['filter.featured'] = $filterFeatured;
		}

		if ($filterTag !== null && $filterTag !== [])
		{
			$data['filter.tag'] = TagIds::fromMixed($filterTag);
		}

		if ($filterLanguage !== null)
		{
			$data['filter.language'] = $filterLanguage;
		}

		if ($filterSearch !== null && $filterSearch !== '')
		{
			$data['filter.search'] = $filterSearch;
		}

		return $data;
	}

	/**
	 * Joins an article's intro and full text the way Joomla's own frontend (and `Table\Content::bind()`)
	 * do — a whitespace-only full text is treated as "no full text" (intro only), exactly like the
	 * original `joomla-mcp-php` behaviour it replaces (introtext/fulltext were combined into one `text`
	 * value there too).
	 */
	public static function composeArticleText(string $introHtml, string $fullHtml): string
	{
		return trim($fullHtml) !== '' ? $introHtml . self::READMORE . $fullHtml : $introHtml;
	}

	/**
	 * @return  array<string, mixed>
	 */
	public static function createData(
		string $title,
		int $catId,
		string $introText,
		string $fullText,
		int $state = 1,
		?string $publishStartTime = null,
		?string $publishEndTime = null,
		?int $access = null,
		?string $alias = null,
		string $language = '*',
		?string $createdTime = null,
		?int $createdBy = null,
		?string $createdByAlias = null,
		?string $imageIntro = null,
		?string $imageIntroAlt = null,
		?string $imageIntroFloat = null,
		?string $imageIntroCaption = null,
		?string $imageFulltext = null,
		?string $imageFulltextAlt = null,
		?string $imageFulltextFloat = null,
		?string $imageFulltextCaption = null,
		?string $urlA = null,
		?string $urlAText = null,
		?string $urlATarget = null,
		?string $urlB = null,
		?string $urlBText = null,
		?string $urlBTarget = null,
		?string $urlC = null,
		?string $urlCText = null,
		?string $urlCTarget = null,
		?string $metaKeywords = null,
		?string $metaDescription = null,
		?string $metadataRobots = null,
		?string $metadataAuthor = null,
		?string $metadataRights = null,
		bool $featured = false,
		?string $featuredStartTime = null,
		?string $featuredEndTime = null,
		?string $note = null,
		?array $tags = null,
		?array $images = null,
		?array $urls = null,
		?array $metadata = null
	): array {
		$introtext = MarkdownConverter::toHtml($introText);
		$fulltext  = MarkdownConverter::toHtml($fullText);

		$data = [
			'title'      => $title,
			'catid'      => $catId,
			'introtext'  => $introtext,
			'fulltext'   => $fulltext,
			'articletext' => self::composeArticleText($introtext, $fulltext),
			'state'      => $state,
			'access'     => $access ?? 1,
			'language'   => $language,
			'featured'   => (int) $featured,
			'tags'       => $tags !== null ? TagIds::fromMixed($tags) : [],
		];

		// Unlike Table\Category::check() (`$this->alias = trim($this->alias ?? '')`), Table\Content::check()
		// calls `trim($this->alias)` directly with no null-guard — a genuine inconsistency in Joomla core.
		// Leaving `alias` unset here (as the "never generate an alias in PHP" convention would otherwise
		// suggest — see AbstractTool's docblock) makes Form::validate() return `alias => null` for a create,
		// which Table::bind() carries straight through, and check()'s trim(null) call then emits a PHP
		// deprecation notice that gets echoed into (and corrupts) the JSON-RPC response body — exactly the
		// TagsModel::getItems() class of bug documented in TagMapper::listState(). Always sending an
		// explicit empty string (Joomla's own alias-from-title fallback still runs identically for '' as it
		// would have for null) avoids the deprecation without generating the alias ourselves.
		$data['alias'] = $alias ?? '';

		if ($publishStartTime !== null)
		{
			$data['publish_up'] = $publishStartTime;
		}

		if ($publishEndTime !== null)
		{
			$data['publish_down'] = $publishEndTime;
		}

		if ($createdTime !== null)
		{
			$data['created'] = $createdTime;
		}

		if ($createdBy !== null)
		{
			$data['created_by'] = $createdBy;
		}

		if ($createdByAlias !== null)
		{
			$data['created_by_alias'] = $createdByAlias;
		}

		if ($metaKeywords !== null)
		{
			$data['metakey'] = $metaKeywords;
		}

		if ($metaDescription !== null)
		{
			$data['metadesc'] = $metaDescription;
		}

		if ($featuredStartTime !== null)
		{
			$data['featured_up'] = $featuredStartTime;
		}

		if ($featuredEndTime !== null)
		{
			$data['featured_down'] = $featuredEndTime;
		}

		if ($note !== null)
		{
			$data['note'] = $note;
		}

		$imagesGroup = self::createGroup($images, [
			'image_intro'            => $imageIntro,
			'image_intro_alt'        => $imageIntroAlt,
			'float_intro'            => $imageIntroFloat,
			'image_intro_caption'    => $imageIntroCaption,
			'image_fulltext'         => $imageFulltext,
			'image_fulltext_alt'     => $imageFulltextAlt,
			'float_fulltext'         => $imageFulltextFloat,
			'image_fulltext_caption' => $imageFulltextCaption,
		]);

		if ($imagesGroup !== [])
		{
			$data['images'] = $imagesGroup;
		}

		$urlsGroup = self::createGroup($urls, [
			'urla'     => $urlA,
			'urlatext' => $urlAText,
			'targeta'  => $urlATarget,
			'urlb'     => $urlB,
			'urlbtext' => $urlBText,
			'targetb'  => $urlBTarget,
			'urlc'     => $urlC,
			'urlctext' => $urlCText,
			'targetc'  => $urlCTarget,
		]);

		if ($urlsGroup !== [])
		{
			$data['urls'] = $urlsGroup;
		}

		$metadataGroup = self::createGroup($metadata, [
			'robots' => $metadataRobots,
			'author' => $metadataAuthor,
			'rights' => $metadataRights,
		]);

		if ($metadataGroup !== [])
		{
			$data['metadata'] = $metadataGroup;
		}

		return $data;
	}

	/**
	 * Only non-null parameters are included (null = "don't change" — see `.plans/00-conventions.md` §6).
	 * `introtext`/`fulltext`/`articletext` are always set — `getItem()` returns a stale `articletext`
	 * which {@see \Akeeba\Component\Mcp4j\Administrator\Bridge\Support\DataMerger}'s merge would otherwise
	 * carry forward into the form unchanged (see `.plans/04-content.md` Part D and Gotchas). `images`/
	 * `urls`/`metadata` are always fully merged onto the current record's own groups — not just the
	 * changed subset — because {@see self::finalUrls()}'s `urls` result is also used independently of
	 * this method's return value (the `$input->post` workaround in `Tool/Content/Articles.php`).
	 *
	 * @return  array<string, mixed>
	 */
	public static function updateData(
		?string $title,
		?int $catId,
		?string $introText,
		?string $fullText,
		?int $state,
		?string $publishStartTime,
		?string $publishEndTime,
		?int $access,
		?string $alias,
		?string $language,
		?string $createdTime,
		?int $createdBy,
		?string $createdByAlias,
		?string $imageIntro,
		?string $imageIntroAlt,
		?string $imageIntroFloat,
		?string $imageIntroCaption,
		?string $imageFulltext,
		?string $imageFulltextAlt,
		?string $imageFulltextFloat,
		?string $imageFulltextCaption,
		?string $urlA,
		?string $urlAText,
		?string $urlATarget,
		?string $urlB,
		?string $urlBText,
		?string $urlBTarget,
		?string $urlC,
		?string $urlCText,
		?string $urlCTarget,
		?string $metaKeywords,
		?string $metaDescription,
		?string $metadataRobots,
		?string $metadataAuthor,
		?string $metadataRights,
		?bool $featured,
		?string $featuredStartTime,
		?string $featuredEndTime,
		?string $note,
		?array $tags,
		?array $images,
		?array $urls,
		?array $metadata,
		array $currentItem
	): array {
		$data = NullFilter::withoutNulls([
			'title'            => $title,
			'catid'            => $catId,
			'state'            => $state,
			'publish_up'       => $publishStartTime,
			'publish_down'     => $publishEndTime,
			'access'           => $access,
			'alias'            => $alias,
			'language'         => $language,
			'created'          => $createdTime,
			'created_by'       => $createdBy,
			'created_by_alias' => $createdByAlias,
			'metakey'          => $metaKeywords,
			'metadesc'         => $metaDescription,
			'featured_up'      => $featuredStartTime,
			'featured_down'    => $featuredEndTime,
			'note'             => $note,
		]);

		$currentIntro = (string) ($currentItem['introtext'] ?? '');
		$currentFull  = (string) ($currentItem['fulltext'] ?? '');

		$introtext = $introText !== null ? MarkdownConverter::toHtml($introText) : $currentIntro;
		$fulltext  = $fullText !== null ? MarkdownConverter::toHtml($fullText) : $currentFull;

		$data['introtext']   = $introtext;
		$data['fulltext']    = $fulltext;
		$data['articletext'] = self::composeArticleText($introtext, $fulltext);

		if ($featured !== null)
		{
			$data['featured'] = (int) $featured;
		}

		$data['tags'] = $tags !== null ? TagIds::fromMixed($tags) : TagIds::fromMixed($currentItem['tags'] ?? null);

		$data['images'] = self::mergedGroup($currentItem, 'images', $images, [
			'image_intro'            => $imageIntro,
			'image_intro_alt'        => $imageIntroAlt,
			'float_intro'            => $imageIntroFloat,
			'image_intro_caption'    => $imageIntroCaption,
			'image_fulltext'         => $imageFulltext,
			'image_fulltext_alt'     => $imageFulltextAlt,
			'float_fulltext'         => $imageFulltextFloat,
			'image_fulltext_caption' => $imageFulltextCaption,
		]);

		$data['urls'] = self::finalUrls($currentItem, $urls, [
			'urla'     => $urlA,
			'urlatext' => $urlAText,
			'targeta'  => $urlATarget,
			'urlb'     => $urlB,
			'urlbtext' => $urlBText,
			'targetb'  => $urlBTarget,
			'urlc'     => $urlC,
			'urlctext' => $urlCText,
			'targetc'  => $urlCTarget,
		]);

		$data['metadata'] = self::mergedGroup($currentItem, 'metadata', $metadata, [
			'robots' => $metadataRobots,
			'author' => $metadataAuthor,
			'rights' => $metadataRights,
		]);

		return $data;
	}

	/**
	 * The final, fully-merged `urls` group for an update — current record's own urls, overlaid by the
	 * nested `urls` payload (if any), overlaid by the individual `urlA`/`urlAText`/... parameters (if
	 * any). Exposed separately (rather than only inline inside {@see self::updateData()}) because
	 * `Tool/Content/Articles.php::updateArticle()` needs this exact value independently, to work around
	 * `ArticleModel::save()` indexing `$input->post->get('jform')['urls'][$i]` directly (see
	 * `.plans/04-content.md` Part D "Why the `$input->post` workaround").
	 *
	 * @param   array<string, mixed>  $currentItem     The current record (as returned by `loadItem()`).
	 * @param   ?array                $nestedUrls      The tool's `urls` nested-payload parameter.
	 * @param   array<string, mixed>  $individualUrls  The tool's individual `urlA`/`urlAText`/... parameters.
	 *
	 * @return  array<string, mixed>
	 */
	public static function finalUrls(array $currentItem, ?array $nestedUrls, array $individualUrls): array
	{
		return self::mergedGroup($currentItem, 'urls', $nestedUrls, $individualUrls);
	}

	/**
	 * `tags` -> a clean `list<int>`; `articletext` (a derived, write-only convenience field — the tools
	 * always work with `introtext`/`fulltext` separately) is dropped from the output.
	 *
	 * @return  array<string, mixed>
	 */
	public static function normaliseItem(array $item): array
	{
		if (array_key_exists('tags', $item))
		{
			$item['tags'] = TagIds::fromMixed($item['tags']);
		}

		unset($item['articletext']);

		return $item;
	}

	/**
	 * Decides whether a create/update/trash call is trying to change an article's publishing state in a
	 * way that Joomla's Workflow integration for `com_content` (when enabled) drives instead — see
	 * `.plans/04-content.md`'s "Workflow caveat". Every parameter here uses the same "null = not given/no
	 * change" convention as the rest of this class — callers normalise their own defaults before calling
	 * (e.g. `content_articles_create`'s non-nullable `$state = 1`/`$featured = false` parameters are
	 * mapped to null when they still hold their default, non-state-changing value, since the framework
	 * gives no way to tell "explicitly given" apart from "left at its default" for a non-nullable
	 * parameter).
	 *
	 * Pure decision function — deliberately free of any Joomla/workflow-enabled check itself, so it can be
	 * unit tested without booting Joomla; the tool class combines its result with
	 * `ComponentHelper::getParams('com_content')->get('workflow_enabled')`.
	 */
	public static function touchesPublishingState(
		?int $state,
		?bool $featured,
		?string $publishStartTime,
		?string $publishEndTime,
		?string $featuredStartTime,
		?string $featuredEndTime
	): bool {
		return $state !== null
			|| $featured !== null
			|| $publishStartTime !== null
			|| $publishEndTime !== null
			|| $featuredStartTime !== null
			|| $featuredEndTime !== null;
	}

	/**
	 * Builds a nested "group" payload for a create call: the nested payload first, then the individual
	 * (non-null) parameters override it — an empty result (no nested payload and no individual parameter
	 * given) is returned as `[]`, which the caller omits from the save data entirely.
	 *
	 * @param   ?array                 $nested      The tool's nested-payload parameter (`images`/`urls`/`metadata`).
	 * @param   array<string, mixed>  $individual  The tool's individual parameters for this group, keyed by Joomla field name.
	 *
	 * @return  array<string, mixed>
	 */
	private static function createGroup(?array $nested, array $individual): array
	{
		$individual = array_filter($individual, static fn ($value) => $value !== null);

		return array_replace($nested ?? [], $individual);
	}

	/**
	 * Builds a nested "group" payload for an update call: the current record's own group, overlaid by the
	 * nested payload (if any), overlaid by the individual (non-null) parameters.
	 *
	 * @param   array<string, mixed>  $currentItem  The current record (as returned by `loadItem()`).
	 * @param   string                 $key          The group's key (`images`/`urls`/`metadata`).
	 * @param   ?array                 $nested       The tool's nested-payload parameter.
	 * @param   array<string, mixed>  $individual   The tool's individual parameters for this group.
	 *
	 * @return  array<string, mixed>
	 */
	private static function mergedGroup(array $currentItem, string $key, ?array $nested, array $individual): array
	{
		$current    = is_array($currentItem[$key] ?? null) ? $currentItem[$key] : [];
		$individual = array_filter($individual, static fn ($value) => $value !== null);

		return array_replace($current, $nested ?? [], $individual);
	}
}
