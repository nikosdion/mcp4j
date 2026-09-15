<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Shared;

defined('_JEXEC') || die;

use Akeeba\Component\Mcp4j\Administrator\Bridge\AbstractTool;
use Akeeba\Component\Mcp4j\Administrator\Mapper\Categories\CategoryMapper;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Exception\ToolException;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\Component\Categories\Administrator\Model\CategoriesModel;
use Joomla\Component\Categories\Administrator\Model\CategoryModel;

/**
 * Joomla-coupled machinery shared by every `com_categories`-backed category tool class: the plan that
 * introduces this file provides the first concrete subclass (`Tool/Content/Categories.php`), and —
 * without any change needed to this file — later plans add further subclasses for the other content-type
 * extensions that share `com_categories` as their category backend. See `.plans/04-content.md` Part B.
 *
 * A subclass only has to implement {@see self::getExtension()} and expose the six public `#[McpTool]`
 * methods, each a one-line delegation to the matching `do*` method here.
 *
 * No extension-specific code: this file is deliberately parameterised entirely by
 * {@see self::getExtension()} and must never mention any single extension's option name (e.g. the one the
 * first subclass above supplies) directly.
 *
 * @since  0.0.1
 */
abstract class AbstractCategoriesTool extends AbstractTool
{
	private const CATEGORIES_OPTION = 'com_categories';

	/**
	 * The extension this tool class manages categories for (a `com_categories` extension name, e.g. the
	 * option a subclass in `Tool/<Category>/Categories.php` was written for).
	 */
	abstract protected function getExtension(): string;

	protected function doListCategories(
		?string $filterSearch,
		?int $filterState,
		?string $filterLanguage,
		?int $filterLevel,
		?int $pageLimit,
		?int $pageOffset
	): array {
		$ext = $this->getExtension();

		$this->requireAuthorised('core.manage', $ext);

		$this->prepareInput();

		/** @var CategoriesModel $model */
		$model = $this->createModel(self::CATEGORIES_OPTION, 'Categories');

		$result = $this->listItems(
			$model,
			CategoryMapper::listState($ext, $filterSearch, $filterState, $filterLanguage, $filterLevel),
			$pageLimit,
			$pageOffset,
			CategoryMapper::LIST_FIELDS,
			CategoryMapper::INT_FIELDS,
			CategoryMapper::JSON_FIELDS
		);

		// CategoriesModel::getListQuery() never selects `extension` (core's own backend list doesn't need
		// it — filter.extension already scopes the query to a single extension, exactly as it does here),
		// so the raw row simply has no such column and OutputShaper::shape() omits the key entirely. We
		// know every returned row belongs to $ext (that is what filter.extension guarantees), so it is
		// injected here rather than left absent from a field LIST_FIELDS explicitly promises.
		foreach ($result['items'] as &$item)
		{
			$item['extension'] = $ext;
		}

		unset($item);

		return $result;
	}

	protected function doReadCategory(int $id): array
	{
		$ext = $this->getExtension();

		$this->requireAuthorised('core.manage', $ext);

		$model = $this->categoryModel();

		return $this->shape(
			CategoryMapper::normaliseItem($this->loadOwn($model, $id)),
			CategoryMapper::ITEM_FIELDS,
			CategoryMapper::INT_FIELDS,
			CategoryMapper::JSON_FIELDS
		);
	}

	protected function doCreateCategory(
		string $title,
		?string $alias,
		?int $parentId,
		int $published,
		?int $access,
		string $language,
		?string $description,
		?array $tags,
		?string $metadesc,
		?string $metakey,
		?string $note
	): array {
		$ext = $this->getExtension();

		$this->requireAuthorised('core.manage', $ext);

		if (!$this->isAuthorised('core.create', $ext) && count($this->getUser()->getAuthorisedCategories($ext, 'core.create')) === 0)
		{
			throw new ToolException(sprintf('Permission denied: %s on %s', 'core.create', $ext));
		}

		if ($parentId !== null && $parentId > 1)
		{
			// Throws Not found: category #<parentId> if it doesn't exist or belongs to another extension.
			$this->loadOwn($this->categoryModel(), $parentId);
		}

		$data = CategoryMapper::createData(
			$ext,
			$title,
			$alias,
			$parentId,
			$published,
			$access,
			$language,
			$description,
			$tags,
			$metadesc,
			$metakey,
			$note
		);

		$newId = $this->saveItem($this->categoryModel(), $data, null, 'category');

		return $this->doReadCategory($newId);
	}

	protected function doUpdateCategory(
		int $id,
		?string $title,
		?string $alias,
		?int $parentId,
		?int $published,
		?int $access,
		?string $language,
		?string $description,
		?array $tags,
		?string $metadesc,
		?string $metakey,
		?string $note
	): array {
		$ext = $this->getExtension();

		$this->requireAuthorised('core.manage', $ext);

		$current = $this->loadOwn($this->categoryModel(), $id);

		$ownerId = isset($current['created_user_id']) ? (int) $current['created_user_id'] : null;
		$this->requireEditAllowed("$ext.category.$id", $ownerId);

		if ($published !== null)
		{
			$this->requireAuthorised('core.edit.state', "$ext.category.$id");
		}

		if ($parentId !== null && $parentId > 1)
		{
			if ($parentId === $id)
			{
				throw new ToolException(sprintf('Cannot set category #%d as its own parent', $id));
			}

			// Throws Not found: category #<parentId> if it doesn't exist or belongs to another extension.
			$this->loadOwn($this->categoryModel(), $parentId);
		}

		$data = CategoryMapper::updateData(
			$title,
			$alias,
			$parentId,
			$published,
			$access,
			$language,
			$description,
			$tags,
			$metadesc,
			$metakey,
			$note,
			$current
		);

		$this->saveItem($this->categoryModel(), $data, $id, 'category');

		return $this->doReadCategory($id);
	}

	protected function doTrashCategory(int $id): array
	{
		$ext = $this->getExtension();

		$this->requireAuthorised('core.manage', $ext);

		$this->loadOwn($this->categoryModel(), $id);
		$this->requireAuthorised('core.edit.state', "$ext.category.$id");

		$this->changeState($this->categoryModel(), $id, -2, 'category');

		return $this->doReadCategory($id);
	}

	protected function doDeleteCategory(int $id): array
	{
		$ext = $this->getExtension();

		$this->requireAuthorised('core.manage', $ext);

		$current = $this->loadOwn($this->categoryModel(), $id);
		$this->requireAuthorised('core.delete', $ext);

		if ((int) ($current['published'] ?? 0) !== -2)
		{
			$this->doTrashCategory($id);
		}

		$this->deleteItem($this->categoryModel(), $id, 'category');

		return ['deleted' => true, 'id' => $id];
	}

	/**
	 * `CategoryModel`'s constructor, `preprocessForm()`, `publish()` and `cleanCache()` all read
	 * `extension` from the request **input**, not from model state — this must run before every
	 * `createModel('com_categories', ...)` call (see `.plans/00-conventions.md` §5.4-5.5 and
	 * `.plans/04-content.md` Gotchas).
	 */
	private function prepareInput(): void
	{
		$this->getApplication()->getInput()->set('extension', $this->getExtension());
	}

	/**
	 * Creates a fresh `CategoryModel`, pre-seeded with every state key `CategoryModel::getForm()` /
	 * `save()` / `publish()` reads — `ignore_request => true` means `populateState()` never runs, so
	 * these have to be set explicitly, every time, on every fresh model instance.
	 */
	private function categoryModel(): CategoryModel
	{
		$this->prepareInput();

		$ext = $this->getExtension();

		/** @var CategoryModel $model */
		$model = $this->createModel(self::CATEGORIES_OPTION, 'Category');

		$model->setState('category.extension', $ext);
		$model->setState('category.component', explode('.', $ext)[0]);
		$model->setState('category.section', null);
		$model->setState('category.parent_id', 0);
		$model->setState('params', ComponentHelper::getParams(self::CATEGORIES_OPTION));

		// preprocessForm() loads the target component's own category.xml form fragment; its labels need
		// the component's language files loaded, exactly as a real backend request would have them.
		$this->loadLanguage($ext);

		return $model;
	}

	/**
	 * Loads a category and asserts it actually belongs to this tool's extension — a category record is
	 * otherwise readable/writable through any extension's category tools, which core's own Web Services
	 * API view does not allow either (see `.plans/04-content.md` Part B).
	 *
	 * @throws  ToolException  Not found, or the category belongs to a different extension.
	 */
	private function loadOwn(CategoryModel $model, int $id): array
	{
		$item = $this->loadItem($model, $id, 'category');

		if (($item['extension'] ?? null) !== $this->getExtension())
		{
			throw new ToolException(sprintf('Not found: category #%d', $id));
		}

		return $item;
	}
}
