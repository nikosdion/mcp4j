<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Tool\Media;

defined('_JEXEC') || die;

use Akeeba\Component\Mcp4j\Administrator\Bridge\AbstractTool;
use Akeeba\Component\Mcp4j\Administrator\Bridge\Support\ItemNormalizer;
use Akeeba\Component\Mcp4j\Administrator\Bridge\Support\OutputShaper;
use Akeeba\Component\Mcp4j\Administrator\Mapper\Media\MediaMapper;
use Akeeba\Component\Mcp4j\Administrator\Mapper\Media\MediaPathMapper;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\McpTool;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\Schema;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\ToolRequirements;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Exception\ToolException;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Helper\MediaHelper;
use Joomla\CMS\MVC\Controller\Exception\ResourceNotFound;
use Joomla\CMS\MVC\Controller\Exception\Save;
use Joomla\CMS\MVC\Model\BaseModel;
use Joomla\Component\Media\Administrator\Exception\FileExistsException;
use Joomla\Component\Media\Administrator\Exception\FileNotFoundException;
use Joomla\Component\Media\Administrator\Exception\InvalidPathException;

/**
 * MCP tools for Joomla! media manager (files, folders and filesystem adapters).
 *
 * Ported from the original `joomla-mcp-php` project's `src/Server/Media/Media.php` — method names, tool
 * names/descriptions, parameters and annotations are copied verbatim from there (see
 * `.plans/00-conventions.md` §2), except `media_adapters_read`'s `$id` type (see the final report's
 * deviations list); only the method *bodies* differ.
 *
 * **Why this class does not use {@see AbstractTool::createModel()}.** com_media's Web Services API models
 * — `Joomla\Component\Media\Api\Model\{Adapters,Adapter,Media,Medium}Model` (`api/components/com_media/src/Model/`),
 * the ones every other Joomla version's Web Services API controller actually calls, and the ones this
 * class is deliberately ported to match (`.plans/13-media-joomlaupdate.md`) — extend `BaseModel`, not
 * `BaseDatabaseModel`: they hold no database table, only a thin wrapper around
 * `Administrator\Model\ApiModel` and the filesystem provider layer. `AbstractTool::createModel()`
 * (`.plans/00-conventions.md` §8 — owned by plan 03, not editable here) requires its result to be an
 * instance of `BaseDatabaseModel` and throws otherwise, so it cannot create these models.
 * {@see self::createMediaModel()} is this class's own equivalent — same `MVCFactory`, same
 * `ignore_request` config, same language-loading — narrowed to accept any `BaseModel`.
 *
 * **ACL.** Every tool starts with `core.manage` on `com_media`, exactly like every backend media-manager
 * screen and every `MediaController`/`AdaptersController` method
 * (`api/components/com_media/src/Controller/*.php`); writes add the specific `core.create`/`core.edit`/
 * `core.delete` check `MediaController::allowAdd()`/`allowEdit()`/`allowDelete()` perform for the
 * equivalent (non-override) request.
 *
 * **Extension/MIME/path safety is never bypassed.** Every byte this class writes goes through
 * `Api\Model\MediumModel::save()` → `Administrator\Model\ApiModel::createFile()`/`updateFile()` →
 * `plugins/filesystem/local/src/Adapter/LocalAdapter`'s `getLocalPath()` (path traversal via
 * `Joomla\Filesystem\Path::check()`) and `checkContent()` (`MediaHelper::canUpload()` — extension
 * blacklist, MIME sniffing, size) — this class never touches `file_put_contents()`/`fopen()`/`unlink()`
 * itself (see the plan's "Done when" clause).
 *
 * @since  0.0.1
 */
#[ToolRequirements(components: ['com_media'])]
class Media extends AbstractTool
{
	private const OPTION = 'com_media';

	#[McpTool(
		name: 'media_adapters_list',
		description: 'List available media adapters',
		readOnlyHint: true,
	)]
	public function listAdapters(): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);

		try
		{
			$model = $this->createMediaModel('Adapters');
			$items = $model->getItems();
		}
		catch (\Throwable $e)
		{
			throw $this->mapMediaException($e, '');
		}

		$shaped = [];

		foreach ($items as $item)
		{
			$shaped[] = MediaMapper::shapeAdapter(ItemNormalizer::toArray($item));
		}

		$total = count($shaped);

		return [
			'items'      => $shaped,
			'pagination' => ['total' => $total, 'limit' => $total, 'offset' => 0],
		];
	}

	#[McpTool(
		name: 'media_adapters_read',
		description: 'Retrieve a specific media adapter (e.g. "local-images")',
		readOnlyHint: true,
	)]
	public function readAdapter(
		#[Schema(description: 'The ID of the media adapter to retrieve (e.g. "local-images")')]
		string $id
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);

		try
		{
			$model = $this->createMediaModel('Adapter');
			$model->setState('id', $id);

			$item = $model->getItem();
		}
		catch (\Throwable $e)
		{
			throw new ToolException(sprintf('Not found: media adapter %s', $id), 0, $e);
		}

		return MediaMapper::shapeAdapter(ItemNormalizer::toArray($item));
	}

	#[McpTool(
		name: 'media_files_list',
		description: 'List media files',
		readOnlyHint: true,
	)]
	public function listFiles(
		#[Schema(description: 'Subdirectory path to filter by')]
		?string $path = null,
		#[Schema(description: 'Media adapter to filter by')]
		?string $adapter = null,
		#[Schema(description: 'Maximum number of items to return per page', minimum: 1)]
		?int $pageLimit = null,
		#[Schema(description: 'Starting record offset for pagination (0-based)', minimum: 0)]
		?int $pageOffset = null
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);

		$this->setAllMediaTypes();

		try
		{
			$model = $this->createMediaModel('Media');
			$model->setState('path', MediaPathMapper::combine($path, $adapter));
			$model->setState('url', true);

			$items = $model->getItems();
		}
		catch (\Throwable $e)
		{
			throw $this->mapMediaException($e, (string) $path);
		}

		$shaped = OutputShaper::shapeList($items, MediaMapper::FILE_FIELDS, MediaMapper::INT_FIELDS);

		return MediaPathMapper::paginate($shaped, $pageLimit, $pageOffset);
	}

	#[McpTool(
		name: 'media_files_read',
		description: 'Retrieve a specific media file',
		readOnlyHint: true,
	)]
	public function readFile(
		#[Schema(description: 'URL-encoded file path')]
		string $path
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);

		$this->setAllMediaTypes();

		try
		{
			$model = $this->createMediaModel('Medium');
			$model->setState('path', $path);
			$model->setState('url', true);

			$item = $model->getItem();
		}
		catch (\Throwable $e)
		{
			throw $this->mapMediaException($e, $path);
		}

		return $this->shape($item, MediaMapper::FILE_FIELDS, MediaMapper::INT_FIELDS);
	}

	#[McpTool(
		name: 'media_files_create',
		description: 'Create a new media file'
	)]
	public function createFile(
		#[Schema(description: 'Destination path for the file')]
		string $path,
		#[Schema(description: 'Base64-encoded file content')]
		string $content
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);
		$this->requireAuthorised('core.create', self::OPTION);

		$this->setAllMediaTypes();

		try
		{
			$bytes = MediaPathMapper::decodeContent($content);
		}
		catch (\InvalidArgumentException $e)
		{
			throw new ToolException($e->getMessage(), 0, $e);
		}

		if ($bytes === '' && !MediaPathMapper::isFolderPath($path))
		{
			throw new ToolException('The content is required when creating a file.');
		}

		$this->requireContentWithinSizeLimit($bytes);

		try
		{
			$model = $this->createMediaModel('Medium');
			$model->setState('path', $path);
			$model->setState('content', $bytes);
			$model->setState('override', false);

			$resultPath = $model->save();

			return $this->readBack($resultPath);
		}
		catch (\Throwable $e)
		{
			throw $this->mapMediaException($e, $path);
		}
	}

	#[McpTool(
		name: 'media_files_update',
		description: 'Update an existing media file',
		idempotentHint: true,
	)]
	public function updateFile(
		#[Schema(description: 'Path of the file to update')]
		string $path,
		#[Schema(description: 'Base64-encoded new file content')]
		string $content
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);
		$this->requireAuthorised('core.edit', self::OPTION);

		$this->setAllMediaTypes();

		try
		{
			$bytes = MediaPathMapper::decodeContent($content);
		}
		catch (\InvalidArgumentException $e)
		{
			throw new ToolException($e->getMessage(), 0, $e);
		}

		if ($bytes === '')
		{
			throw new ToolException('The content is required when updating a file.');
		}

		$this->requireContentWithinSizeLimit($bytes);

		try
		{
			// media_files_read-style lookup first: media_files_update must fail "Not found" for a
			// nonexistent path rather than silently creating it (see below for why simply setting
			// 'override' => true on a create-shaped save() would otherwise do exactly that).
			$lookupModel = $this->createMediaModel('Medium');
			$lookupModel->setState('path', $path);
			$lookupModel->getItem();

			// Deliberately NOT the literal `setState('path', ''), setState('old_path', $path)` shape a
			// content-only PATCH sends over HTTP: `MediumModel::save()` resolves the *save's* adapter from
			// its 'path' state via `resolveAdapterAndPath()`, and an empty 'path' resolves to the SITE'S
			// DEFAULT adapter (`ComponentHelper::getParams('com_media')->get('file_path', ...)`) rather
			// than the file's actual adapter — wrong whenever the target lives under a non-default local
			// adapter (reproduced against a real Joomla 6.1 install, which registers both "images", the
			// default, and "files": updating a file under "local-files" silently touched "local-images"
			// instead and threw a spurious "Not found"). Passing the full `"<adapter>:/path"` as 'path'
			// (with no 'old_path') instead routes through `save()`'s *create* branch, which resolves the
			// adapter from that same full path correctly; `override: true` makes `ApiModel::createFile()`
			// skip its "already exists" check and `LocalAdapter::createFile()`'s `File::write()` overwrite
			// the existing file in place — the lookup above already proved the file exists, so this can
			// never *create* one.
			$model = $this->createMediaModel('Medium');
			$model->setState('path', $path);
			$model->setState('content', $bytes);
			$model->setState('override', true);

			$resultPath = $model->save();

			return $this->readBack($resultPath);
		}
		catch (\Throwable $e)
		{
			throw $this->mapMediaException($e, $path);
		}
	}

	#[McpTool(
		name: 'media_files_delete',
		description: 'Delete a media file',
		destructiveHint: true,
	)]
	public function deleteFile(
		#[Schema(description: 'Path of the file to delete')]
		string $path
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);
		$this->requireAuthorised('core.delete', self::OPTION);

		$this->setAllMediaTypes();

		try
		{
			// media_files_read-style lookup first, purely so a nonexistent path produces the same
			// "Not found" semantics as every other *_delete tool, rather than whatever error message the
			// adapter's own delete() call happens to throw for a missing path.
			$lookupModel = $this->createMediaModel('Medium');
			$lookupModel->setState('path', $path);
			$lookupModel->getItem();

			$model = $this->createMediaModel('Medium');
			$model->setState('path', $path);
			$model->delete();
		}
		catch (\Throwable $e)
		{
			throw $this->mapMediaException($e, $path);
		}

		return ['deleted' => true, 'path' => $path];
	}

	/**
	 * Reads a just-saved file/folder back via a fresh `Medium` model, exactly as `media_files_read` would,
	 * so `media_files_create`/`update` return the same shape a follow-up read would.
	 */
	private function readBack(string $resultPath): array
	{
		$model = $this->createMediaModel('Medium');
		$model->setState('path', $resultPath);
		$model->setState('url', true);

		return $this->shape($model->getItem(), MediaMapper::FILE_FIELDS, MediaMapper::INT_FIELDS);
	}

	/**
	 * `ApiModel::isMediaFile()` (and therefore every media model's extension check) memoises the allowed
	 * extension list from the **input** `mediatypes` key the first time it runs, defaulting to `'0'`
	 * (images only) when unset. This package always widens it to every media type com_media itself
	 * supports — images, audio, video, documents — exactly as the backend media manager UI does; the
	 * actual extension/MIME whitelist enforcement (`image_extensions`/`audio_extensions`/`video_extensions`/
	 * `doc_extensions` component options, plus `MediaHelper::canUpload()`) still fully applies. Must be
	 * called before the first model of a tool call is created — see the class docblock.
	 */
	private function setAllMediaTypes(): void
	{
		$this->getApplication()->getInput()->set('mediatypes', '0,1,2,3');
	}

	/**
	 * Pure mirror of `MediaController::checkContent()`, using {@see MediaPathMapper::exceedsSizeLimit()}.
	 *
	 * @throws  ToolException  The content exceeds `com_media`'s configured upload size, or a server-imposed
	 *                         `upload_max_filesize`/`post_max_size` limit.
	 */
	private function requireContentWithinSizeLimit(string $bytes): void
	{
		$params = ComponentHelper::getParams(self::OPTION);
		$helper = new MediaHelper();

		$exceeds = MediaPathMapper::exceedsSizeLimit(
			strlen($bytes),
			(int) $params->get('upload_maxsize', 0),
			(int) $helper->toBytes((string) ini_get('upload_max_filesize')),
			(int) $helper->toBytes((string) ini_get('post_max_size'))
		);

		if ($exceeds)
		{
			throw new ToolException('The file is too large to upload.');
		}
	}

	/**
	 * Creates one of com_media's Web Services API models (`Joomla\Component\Media\Api\Model\*`) the way
	 * `MediaController`/`AdaptersController` would — see the class docblock for why
	 * {@see AbstractTool::createModel()} cannot be reused here.
	 *
	 * @throws  ToolException  The model could not be created.
	 */
	private function createMediaModel(string $name): BaseModel
	{
		$this->loadLanguage(self::OPTION);

		try
		{
			$factory = $this->getApplication()->bootComponent(self::OPTION)->getMVCFactory();
			$model   = $factory->createModel($name, 'Api', ['ignore_request' => true]);
		}
		catch (\Throwable $e)
		{
			throw new ToolException(sprintf('Cannot create the Api %s model of %s', $name, self::OPTION), 0, $e);
		}

		if (!$model instanceof BaseModel)
		{
			throw new ToolException(sprintf('Cannot create the Api %s model of %s', $name, self::OPTION));
		}

		return $model;
	}

	/**
	 * Turns whatever a com_media model call throws into a {@see ToolException} the LLM can act on.
	 *
	 * `Api\Model\MediumModel::save()`/`delete()` catch the specific `Administrator\Exception\*` classes
	 * below and rethrow a generic `Joomla\CMS\MVC\Controller\Exception\Save`, discarding the original class
	 * but keeping the HTTP-like status code a real Web Services API response would have used (404 "not
	 * found", 400 "exists"/"invalid path or type") — both the raw exception classes (thrown directly by
	 * `Administrator\Model\ApiModel`/`getFile()`/`getFiles()`, and by `Api\Model\MediaModel::getItems()`/
	 * `MediumModel::getItem()` as `ResourceNotFound`) and that wrapped `Save` form are handled here so every
	 * call site gets the same three well-known messages regardless of which model method it went through.
	 * Anything else (including `LocalAdapter::checkContent()`'s plain `\Exception` for a rejected upload)
	 * falls through to the exception's own message, with `$app->getMessageQueue(true)` appended —
	 * `MediaHelper::canUpload()` reports the specific reason (bad extension, MIME mismatch, ...) through
	 * `enqueueMessage()`, not the exception message itself.
	 */
	private function mapMediaException(\Throwable $e, string $path): ToolException
	{
		if ($e instanceof FileNotFoundException || $e instanceof ResourceNotFound)
		{
			return new ToolException(sprintf('Not found: media file %s', $path), 0, $e);
		}

		if ($e instanceof FileExistsException)
		{
			return new ToolException(sprintf('Media file already exists: %s', $path), 0, $e);
		}

		if ($e instanceof InvalidPathException)
		{
			return new ToolException(sprintf('Invalid media path or file type: %s', $path), 0, $e);
		}

		if ($e instanceof Save)
		{
			if ($e->getCode() === 404)
			{
				return new ToolException(sprintf('Not found: media file %s', $path), 0, $e);
			}

			if ($e->getCode() === 400)
			{
				return str_contains(strtolower($e->getMessage()), 'exist')
					? new ToolException(sprintf('Media file already exists: %s', $path), 0, $e)
					: new ToolException(sprintf('Invalid media path or file type: %s', $path), 0, $e);
			}
		}

		$queued = [];

		foreach ($this->getApplication()->getMessageQueue(true) as $message)
		{
			$text = is_array($message) ? (string) ($message['message'] ?? '') : (string) $message;

			if ($text !== '')
			{
				$queued[] = $text;
			}
		}

		$text = $e->getMessage() !== '' ? $e->getMessage() : 'Unknown media error';

		if ($queued !== [])
		{
			$text .= ' (' . implode('; ', $queued) . ')';
		}

		return new ToolException($text, 0, $e);
	}
}
