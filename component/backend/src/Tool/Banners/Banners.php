<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Tool\Banners;

defined('_JEXEC') || die;

use Akeeba\Component\Mcp4j\Administrator\Bridge\AbstractTool;
use Akeeba\Component\Mcp4j\Administrator\Mapper\Banners\BannerMapper;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\McpTool;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\Schema;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Exception\ToolException;

/**
 * MCP tools for Joomla! banners management.
 *
 * Ported from the original `joomla-mcp-php` project's `src/Server/Banners/Banners.php` — method names,
 * tool names/descriptions, parameters and annotations are copied verbatim from there (see
 * `.plans/00-conventions.md` §2), except `createBanner()`'s `alias` parameter, which the original declared
 * *before* the required `catId` parameter (a PHP 8 "optional before required" deprecation — see
 * `.plans/08-banners.md` Deviations item 1) and is moved to directly after it here.
 *
 * `custombannercode` is stored with `filter="raw"` in `forms/banner.xml` — exactly as the Joomla backend
 * allows for any user with banner edit rights (`core.create`/`core.edit` on the banner's category); this
 * class does not add any extra filtering on top of what the form itself already applies.
 *
 * Banners support neither `tags` nor `metadesc` — both are simply absent from `forms/banner.xml`, so
 * Joomla's own form would silently discard them. This port refuses a non-empty value for either outright
 * (see {@see BannerMapper::unsupported()}) rather than pretending the value was honoured.
 *
 * @since  0.0.1
 */
class Banners extends AbstractTool
{
	private const OPTION = 'com_banners';

	#[McpTool(
		name: 'banners_list',
		description: 'List existing banners',
		readOnlyHint: true,
	)]
	public function listBanners(
		#[Schema(description: 'Search filter for the banner name')]
		?string $filterSearch = null,
		#[Schema(
			description: 'Filter by publish state: 0=unpublished, 1=published, 2=archived, -2=trashed',
			enum: [null, 0, 1, 2, -2]
		)]
		?int $filterState = null,
		#[Schema(description: 'Filter by category ID')]
		?int $filterCategory = null,
		#[Schema(description: 'Filter by client ID')]
		?int $filterClient = null,
		#[Schema(description: 'Maximum number of items to return per page', minimum: 1)]
		?int $pageLimit = null,
		#[Schema(description: 'Starting record offset for pagination (0-based)', minimum: 0)]
		?int $pageOffset = null
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);

		/** @var \Joomla\Component\Banners\Administrator\Model\BannersModel $model */
		$model = $this->createModel(self::OPTION, 'Banners');

		return $this->listItems(
			$model,
			BannerMapper::listState($filterSearch, $filterState, $filterCategory, $filterClient),
			$pageLimit,
			$pageOffset,
			BannerMapper::LIST_FIELDS,
			BannerMapper::INT_FIELDS,
			BannerMapper::JSON_FIELDS
		);
	}

	#[McpTool(
		name: 'banners_read',
		description: 'Retrieve the information of the specified banner',
		readOnlyHint: true,
	)]
	public function readBanner(
		#[Schema(description: 'The ID of the banner to retrieve')]
		int $id
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);

		/** @var \Joomla\Component\Banners\Administrator\Model\BannerModel $model */
		$model = $this->createModel(self::OPTION, 'Banner');

		return $this->shape(
			$this->loadItem($model, $id, 'banner'),
			BannerMapper::ITEM_FIELDS,
			BannerMapper::INT_FIELDS,
			BannerMapper::JSON_FIELDS
		);
	}

	#[McpTool(
		name: 'banners_create',
		description: 'Create a new banner'
	)]
	public function createBanner(
		#[Schema(description: 'Banner name', minLength: 1, maxLength: 255)]
		string $name,
		#[Schema(description: 'Banner category ID', minimum: 0, exclusiveMinimum: true)]
		int $catId,
		#[Schema(description: 'URL slug for the banner', pattern: '^[a-z0-9-_]+$')]
		?string $alias = null,
		#[Schema(
			description: 'Publish state: 0=unpublished, 1=published, 2=archived, -2=trashed',
			enum: [0, 1, 2, -2]
		)]
		int $state = 1,
		#[Schema(description: 'Banner client ID')]
		?int $cid = null,
		#[Schema(description: 'Banner type: 0=image, 1=custom', enum: ['0', '1'])]
		?string $type = null,
		#[Schema(description: 'Click URL for the banner')]
		?string $clickUrl = null,
		#[Schema(description: 'Custom banner HTML code')]
		?string $customBannerCode = null,
		#[Schema(description: 'Purchase type')]
		?int $purchaseType = null,
		#[Schema(description: 'Track impressions')]
		?int $trackImpressions = null,
		#[Schema(description: 'Track clicks')]
		?int $trackClicks = null,
		#[Schema(description: 'An array of tag IDs to associate with the banner', items: ['type' => 'integer'], minItems: 0, uniqueItems: true)]
		?array $tags = null,
		#[Schema(description: 'Banner description')]
		?string $description = null,
		#[Schema(description: 'Language code for the banner, or "*" for all languages', pattern: '^(\*|[a-z]{2}(-[A-Z]{2})?)$')]
		string $language = '*',
		#[Schema(description: 'Optional meta keywords, separated by commas')]
		?string $metakey = null,
		#[Schema(description: 'Optional meta description')]
		?string $metadesc = null
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);

		$this->refuseUnsupported($tags, $metadesc);

		$this->requireBannerCategory($catId);
		$this->requireAuthorised('core.create', self::OPTION . '.category.' . $catId);

		if ($state !== 1)
		{
			$this->requireAuthorised('core.edit.state', self::OPTION . '.category.' . $catId);
		}

		/** @var \Joomla\Component\Banners\Administrator\Model\BannerModel $model */
		$model = $this->createModel(self::OPTION, 'Banner');

		$data = BannerMapper::createData(
			$name,
			$catId,
			$alias,
			$state,
			$cid,
			$type,
			$clickUrl,
			$customBannerCode,
			$purchaseType,
			$trackImpressions,
			$trackClicks,
			$description,
			$language,
			$metakey
		);

		$newId = $this->saveItem($model, $data, null, 'banner');

		return $this->readBanner($newId);
	}

	#[McpTool(
		name: 'banners_update',
		description: 'Update an existing banner',
		idempotentHint: true,
	)]
	public function updateBanner(
		#[Schema(description: 'The ID of the banner to modify')]
		int $id,
		#[Schema(description: 'Banner name', minLength: 1, maxLength: 255)]
		?string $name = null,
		#[Schema(description: 'URL slug for the banner', pattern: '^[a-z0-9-_]+$')]
		?string $alias = null,
		#[Schema(description: 'Banner category ID', minimum: 0, exclusiveMinimum: true)]
		?int $catId = null,
		#[Schema(
			description: 'Publish state: null=no change, 0=unpublished, 1=published, 2=archived, -2=trashed',
			enum: [null, 0, 1, 2, -2]
		)]
		?int $state = null,
		#[Schema(description: 'Banner client ID')]
		?int $cid = null,
		#[Schema(description: 'Banner type: 0=image, 1=custom', enum: ['0', '1'])]
		?string $type = null,
		#[Schema(description: 'Click URL for the banner')]
		?string $clickUrl = null,
		#[Schema(description: 'Custom banner HTML code')]
		?string $customBannerCode = null,
		#[Schema(description: 'Purchase type')]
		?int $purchaseType = null,
		#[Schema(description: 'Track impressions')]
		?int $trackImpressions = null,
		#[Schema(description: 'Track clicks')]
		?int $trackClicks = null,
		#[Schema(description: 'An array of tag IDs to associate with the banner', items: ['type' => 'integer'], minItems: 0, uniqueItems: true)]
		?array $tags = null,
		#[Schema(description: 'Banner description')]
		?string $description = null,
		#[Schema(description: 'Language code for the banner, or "*" for all languages', pattern: '^(\*|[a-z]{2}(-[A-Z]{2})?)$')]
		?string $language = null,
		#[Schema(description: 'Optional meta keywords, separated by commas')]
		?string $metakey = null,
		#[Schema(description: 'Optional meta description')]
		?string $metadesc = null
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);

		$this->refuseUnsupported($tags, $metadesc);

		/** @var \Joomla\Component\Banners\Administrator\Model\BannerModel $model */
		$model = $this->createModel(self::OPTION, 'Banner');

		$current = $this->loadItem($model, $id, 'banner');
		$catid   = (int) ($current['catid'] ?? 0);

		$this->requireAuthorised('core.edit', self::OPTION . '.category.' . $catid);

		if ($catId !== null && $catId !== $catid)
		{
			$this->requireBannerCategory($catId);
			$this->requireAuthorised('core.create', self::OPTION . '.category.' . $catId);
		}

		if ($state !== null)
		{
			$this->requireAuthorised('core.edit.state', self::OPTION . '.category.' . $catid);
		}

		$data = BannerMapper::updateData(
			$name,
			$alias,
			$catId,
			$state,
			$cid,
			$type,
			$clickUrl,
			$customBannerCode,
			$purchaseType,
			$trackImpressions,
			$trackClicks,
			$description,
			$language,
			$metakey
		);

		$this->saveItem($model, $data, $id, 'banner');

		return $this->readBanner($id);
	}

	#[McpTool(
		name: 'banners_trash',
		description: 'Moves a banner to the trash by setting its state to -2',
		destructiveHint: true,
	)]
	public function trashBanner(
		#[Schema(description: 'The ID of the banner to trash')]
		int $id
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);

		/** @var \Joomla\Component\Banners\Administrator\Model\BannerModel $model */
		$model = $this->createModel(self::OPTION, 'Banner');

		$current = $this->loadItem($model, $id, 'banner');

		$this->requireAuthorised('core.edit.state', self::OPTION . '.category.' . (int) ($current['catid'] ?? 0));

		$this->changeState($model, $id, -2, 'banner');

		return $this->readBanner($id);
	}

	#[McpTool(
		name: 'banners_delete',
		description: 'Permanently deletes a banner. Automatically trashes it first if needed.',
		destructiveHint: true,
	)]
	public function deleteBanner(
		#[Schema(description: 'The ID of the banner to delete')]
		int $id
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);

		/** @var \Joomla\Component\Banners\Administrator\Model\BannerModel $model */
		$model = $this->createModel(self::OPTION, 'Banner');

		$current = $this->loadItem($model, $id, 'banner');

		$this->requireAuthorised('core.delete', self::OPTION . '.category.' . (int) ($current['catid'] ?? 0));

		if ((int) ($current['state'] ?? 0) !== -2)
		{
			/** @var \Joomla\Component\Banners\Administrator\Model\BannerModel $trashModel */
			$trashModel = $this->createModel(self::OPTION, 'Banner');

			$this->changeState($trashModel, $id, -2, 'banner');
		}

		/** @var \Joomla\Component\Banners\Administrator\Model\BannerModel $deleteModel */
		$deleteModel = $this->createModel(self::OPTION, 'Banner');

		$this->deleteItem($deleteModel, $id, 'banner');

		return ['deleted' => true, 'id' => $id];
	}

	/**
	 * Refuses the call outright when `tags` and/or `metadesc` were given a non-empty value — see
	 * {@see BannerMapper::unsupported()}.
	 *
	 * @throws  ToolException
	 */
	private function refuseUnsupported(?array $tags, ?string $metadesc): void
	{
		$unsupported = BannerMapper::unsupported($tags, $metadesc);

		if ($unsupported !== [])
		{
			throw new ToolException(sprintf('Banners do not support: %s. Omit these parameters.', implode(', ', $unsupported)));
		}
	}

	/**
	 * Asserts `$catId` is an existing **`com_banners`** category — `BannerModel::save()` would otherwise
	 * (for a user with `core.create` on `com_banners`) silently create a new top-level category named after
	 * whatever `catid` value it was given when `CategoriesHelper::validateCategoryId()` finds no match (see
	 * `.plans/08-banners.md` Gotchas); this makes an invalid/foreign `catId` a clean `ToolException` instead.
	 *
	 * @throws  ToolException  `Not found: category #<catId>` — no such category, or it belongs to another
	 *                         extension (e.g. a `com_content` category id was passed in).
	 */
	private function requireBannerCategory(int $catId): void
	{
		$this->getApplication()->getInput()->set('extension', self::OPTION);

		/** @var \Joomla\Component\Categories\Administrator\Model\CategoryModel $model */
		$model = $this->createModel('com_categories', 'Category');

		$item = $this->loadItem($model, $catId, 'category');

		if (($item['extension'] ?? null) !== self::OPTION)
		{
			throw new ToolException(sprintf('Not found: category #%d', $catId));
		}
	}
}
