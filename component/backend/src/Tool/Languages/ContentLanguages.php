<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Tool\Languages;

defined('_JEXEC') || die;

use Akeeba\Component\Mcp4j\Administrator\Bridge\AbstractTool;
use Akeeba\Component\Mcp4j\Administrator\Mapper\Languages\ContentLanguageMapper;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\McpTool;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\Schema;
use Joomla\CMS\Filter\InputFilter;
use Joomla\Database\ParameterType;

/**
 * MCP tools for Joomla! content language management.
 *
 * Ported from the original `joomla-mcp-php` project's `src/Server/Languages/ContentLanguages.php` — method
 * names, tool names/descriptions, parameters and annotations are copied verbatim from there (see
 * `.plans/00-conventions.md` §2); only the method *bodies* differ, and `*_delete`'s return type changes
 * from the original's `bool` to the framework-wide `['deleted' => true, 'id' => int]` shape.
 *
 * ACL mirrors `libraries/src/MVC/Controller/FormController.php`'s default `allowAdd()`/`allowEdit()`:
 * content languages have no per-record asset, so `core.edit`/`core.delete` are checked against the
 * `com_languages` component itself, not against the individual record.
 *
 * `#__languages.metakey` has no field in `forms/language.xml` in any supported Joomla version (4.4, 5.4,
 * 6.1) — see {@see ContentLanguageMapper::createData()}'s docblock — so create/update persist it with a
 * narrowly-scoped direct `UPDATE` after the normal form-validated save, rather than silently dropping a
 * value the tool's own parameter list promises to accept.
 *
 * @since  0.0.1
 */
class ContentLanguages extends AbstractTool
{
	private const OPTION = 'com_languages';

	#[McpTool(
		name: 'languages_content_list',
		description: 'List existing content languages',
		readOnlyHint: true,
	)]
	public function listLanguages(
		#[Schema(description: 'Filter by published state', enum: [0, 1])]
		?int $filterState = null,
		#[Schema(description: 'Maximum number of items to return per page', minimum: 1)]
		?int $pageLimit = null,
		#[Schema(description: 'Starting record offset for pagination (0-based)', minimum: 0)]
		?int $pageOffset = null
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);

		/** @var \Joomla\Component\Languages\Administrator\Model\LanguagesModel $model */
		$model = $this->createModel(self::OPTION, 'Languages');

		$result = $this->listItems(
			$model,
			ContentLanguageMapper::listState($filterState),
			$pageLimit,
			$pageOffset,
			ContentLanguageMapper::RAW_LIST_FIELDS,
			ContentLanguageMapper::RAW_INT_FIELDS
		);

		$result['items'] = array_map([ContentLanguageMapper::class, 'renameId'], $result['items']);

		return $result;
	}

	#[McpTool(
		name: 'languages_content_read',
		description: 'Retrieve the information of the specified content language',
		readOnlyHint: true,
	)]
	public function readLanguage(
		#[Schema(description: 'The ID of the content language to retrieve')]
		int $id
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);

		/** @var \Joomla\Component\Languages\Administrator\Model\LanguageModel $model */
		$model = $this->createModel(self::OPTION, 'Language');

		return $this->shapedItem($model, $id);
	}

	#[McpTool(
		name: 'languages_content_create',
		description: 'Create a new content language'
	)]
	public function createLanguage(
		#[Schema(description: 'The language code, e.g. "en-GB"')]
		string $langCode,
		#[Schema(description: 'The title of the content language')]
		string $title,
		#[Schema(description: 'The native title of the content language')]
		string $titleNative,
		#[Schema(description: 'The SEF tag for the language')]
		?string $sef = null,
		#[Schema(description: 'The flag image for the language')]
		?string $image = null,
		#[Schema(description: 'Published state: 0=unpublished, 1=published', enum: [0, 1])]
		int $published = 1,
		#[Schema(description: 'The viewing access level for the language')]
		?int $access = null,
		#[Schema(description: 'Meta description for the language')]
		?string $metadesc = null,
		#[Schema(description: 'Meta keywords for the language')]
		?string $metakey = null,
		#[Schema(description: 'Site name for this language')]
		?string $sitename = null
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);
		$this->requireAuthorised('core.create', self::OPTION);

		/** @var \Joomla\Component\Languages\Administrator\Model\LanguageModel $model */
		$model = $this->createModel(self::OPTION, 'Language');

		$data = ContentLanguageMapper::createData(
			$langCode,
			$title,
			$titleNative,
			$sef,
			$image,
			$published,
			$access,
			$metadesc,
			$sitename
		);

		$newId = $this->saveItem($model, $data, null, 'content language');

		$this->maybePersistMetakey($newId, ContentLanguageMapper::extractMetakey($metakey));

		/** @var \Joomla\Component\Languages\Administrator\Model\LanguageModel $freshModel */
		$freshModel = $this->createModel(self::OPTION, 'Language');

		return $this->shapedItem($freshModel, $newId);
	}

	#[McpTool(
		name: 'languages_content_update',
		description: 'Update an existing content language',
		idempotentHint: true,
	)]
	public function updateLanguage(
		#[Schema(description: 'The ID of the content language to update')]
		int $id,
		#[Schema(description: 'The language code, e.g. "en-GB"')]
		?string $langCode = null,
		#[Schema(description: 'The title of the content language')]
		?string $title = null,
		#[Schema(description: 'The native title of the content language')]
		?string $titleNative = null,
		#[Schema(description: 'The SEF tag for the language')]
		?string $sef = null,
		#[Schema(description: 'The flag image for the language')]
		?string $image = null,
		#[Schema(description: 'Published state: 0=unpublished, 1=published', enum: [0, 1])]
		?int $published = null,
		#[Schema(description: 'The viewing access level for the language')]
		?int $access = null,
		#[Schema(description: 'Meta description for the language')]
		?string $metadesc = null,
		#[Schema(description: 'Meta keywords for the language')]
		?string $metakey = null,
		#[Schema(description: 'Site name for this language')]
		?string $sitename = null
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);
		$this->requireAuthorised('core.edit', self::OPTION);

		if ($published !== null)
		{
			$this->requireAuthorised('core.edit.state', self::OPTION);
		}

		/** @var \Joomla\Component\Languages\Administrator\Model\LanguageModel $model */
		$model = $this->createModel(self::OPTION, 'Language');

		$data = ContentLanguageMapper::updateData(
			$langCode,
			$title,
			$titleNative,
			$sef,
			$image,
			$published,
			$access,
			$metadesc,
			$sitename
		);

		$this->saveItem($model, $data, $id, 'content language');

		$this->maybePersistMetakey($id, ContentLanguageMapper::extractMetakey($metakey));

		/** @var \Joomla\Component\Languages\Administrator\Model\LanguageModel $freshModel */
		$freshModel = $this->createModel(self::OPTION, 'Language');

		return $this->shapedItem($freshModel, $id);
	}

	#[McpTool(
		name: 'languages_content_delete',
		description: 'Delete a content language',
		destructiveHint: true,
	)]
	public function deleteLanguage(
		#[Schema(description: 'The ID of the content language to delete')]
		int $id
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);
		$this->requireAuthorised('core.delete', self::OPTION);

		/** @var \Joomla\Component\Languages\Administrator\Model\LanguageModel $model */
		$model = $this->createModel(self::OPTION, 'Language');

		// Content languages have no trash state (published only ever goes to 0/1 — see
		// forms/language.xml) — a plain delete, exactly like the original tool's own behaviour.
		$this->deleteItem($model, $id, 'content language');

		return ['deleted' => true, 'id' => $id];
	}

	private function shapedItem(\Joomla\CMS\MVC\Model\AdminModel $model, int $id): array
	{
		return $this->shape(
			ContentLanguageMapper::renameId($this->loadItem($model, $id, 'content language')),
			ContentLanguageMapper::ITEM_FIELDS,
			ContentLanguageMapper::INT_FIELDS
		);
	}

	/**
	 * `metakey` has no field in `forms/language.xml` (in any supported Joomla version), so
	 * `AdminModel::save()` never persists it — write it directly, with both bound values held in their own
	 * variables (never a loop variable — see `.plans/00-conventions.md` §5.2) and the incoming string run
	 * through Joomla's own STRING input filter before it ever reaches the query.
	 */
	private function maybePersistMetakey(int $id, ?string $metakey): void
	{
		if ($metakey === null)
		{
			return;
		}

		$db           = $this->getDatabase();
		$cleanMetakey = InputFilter::getInstance()->clean($metakey, 'STRING');
		$idValue      = $id;

		$query = $db->createQuery()
			->update($db->quoteName('#__languages'))
			->set($db->quoteName('metakey') . ' = :metakey')
			->where($db->quoteName('lang_id') . ' = :id')
			->bind(':metakey', $cleanMetakey)
			->bind(':id', $idValue, ParameterType::INTEGER);

		$db->setQuery($query)->execute();
	}
}
