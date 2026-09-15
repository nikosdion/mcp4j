<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Tool\Languages;

defined('_JEXEC') || die;

use Akeeba\Component\Mcp4j\Administrator\Bridge\AbstractTool;
use Akeeba\Component\Mcp4j\Administrator\Bridge\Support\ItemNormalizer;
use Akeeba\Component\Mcp4j\Administrator\Bridge\Support\Pagination;
use Akeeba\Component\Mcp4j\Administrator\Mapper\Languages\OverrideMapper;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\McpTool;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\Schema;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Exception\ToolException;
use Joomla\CMS\Language\LanguageHelper;
use Joomla\Registry\Registry;

/**
 * MCP tools for Joomla! language override management (both the `site` and `administrator` client).
 *
 * Ported from the original `joomla-mcp-php` project's `src/Server/Languages/Overrides.php` — method
 * names, tool names/descriptions, parameters and annotations are copied verbatim from there (see
 * `.plans/00-conventions.md` §2); only the method *bodies* differ. The original's ten `site`/
 * `administrator` CRUD methods are near-identical pairs that only differ in the `$client` string they
 * pass down an HTTP path — here they delegate to five private `do*(string $client, ...)` helpers instead
 * of duplicating the Joomla model logic ten times.
 *
 * Overrides live in `.ini` files (`JPATH_SITE|JPATH_ADMINISTRATOR/language/overrides/<tag>.override.ini`)
 * — there is no database table and no `Table` class, so none of `AbstractTool`'s `loadItem()`/
 * `saveItem()`/`changeState()`/`deleteItem()` helpers apply here (see
 * `.plans/11-overrides-redirects-messages.md` Part A). The language tag is concatenated straight into a
 * filesystem path by every core model method it touches, so every public entry point validates it first,
 * against the languages actually installed for that client ({@see self::requireLanguage()}) — an
 * unvalidated tag like `../../x` would otherwise let a "language override" tool read/write arbitrary
 * `*.override.ini` files anywhere the process can reach.
 *
 * @since  0.0.1
 */
class Overrides extends AbstractTool
{
	private const OPTION = 'com_languages';

	// =====================================================================================================
	// Site language overrides
	// =====================================================================================================

	#[McpTool(
		name: 'languages_overrides_site_list',
		description: 'List site language overrides for a given language',
		readOnlyHint: true,
	)]
	public function listSiteOverrides(
		#[Schema(description: 'The language code, e.g. "en-GB"')]
		string $language,
		#[Schema(description: 'Search overrides by key or value')]
		?string $filterSearch = null,
		#[Schema(description: 'Maximum number of items to return per page', minimum: 1)]
		?int $pageLimit = null,
		#[Schema(description: 'Starting record offset for pagination (0-based)', minimum: 0)]
		?int $pageOffset = null
	): array
	{
		return $this->doList('site', $language, $filterSearch, $pageLimit, $pageOffset);
	}

	#[McpTool(
		name: 'languages_overrides_site_read',
		description: 'Retrieve a specific site language override',
		readOnlyHint: true,
	)]
	public function readSiteOverride(
		#[Schema(description: 'The language code, e.g. "en-GB"')]
		string $language,
		#[Schema(description: 'The language constant key')]
		string $key
	): array
	{
		return $this->doRead('site', $language, $key);
	}

	#[McpTool(
		name: 'languages_overrides_site_create',
		description: 'Create a new site language override'
	)]
	public function createSiteOverride(
		#[Schema(description: 'The language code, e.g. "en-GB"')]
		string $language,
		#[Schema(description: 'The language constant key')]
		string $key,
		#[Schema(description: 'The override value')]
		string $value
	): array
	{
		return $this->doCreate('site', $language, $key, $value);
	}

	#[McpTool(
		name: 'languages_overrides_site_update',
		description: 'Update an existing site language override',
		idempotentHint: true,
	)]
	public function updateSiteOverride(
		#[Schema(description: 'The language code, e.g. "en-GB"')]
		string $language,
		#[Schema(description: 'The language constant key')]
		string $key,
		#[Schema(description: 'The new override value')]
		string $value
	): array
	{
		return $this->doUpdate('site', $language, $key, $value);
	}

	#[McpTool(
		name: 'languages_overrides_site_delete',
		description: 'Delete a site language override',
		destructiveHint: true,
	)]
	public function deleteSiteOverride(
		#[Schema(description: 'The language code, e.g. "en-GB"')]
		string $language,
		#[Schema(description: 'The language constant key')]
		string $key
	): array
	{
		return $this->doDelete('site', $language, $key);
	}

	// =====================================================================================================
	// Administrator language overrides
	// =====================================================================================================

	#[McpTool(
		name: 'languages_overrides_admin_list',
		description: 'List administrator language overrides for a given language',
		readOnlyHint: true,
	)]
	public function listAdminOverrides(
		#[Schema(description: 'The language code, e.g. "en-GB"')]
		string $language,
		#[Schema(description: 'Search overrides by key or value')]
		?string $filterSearch = null,
		#[Schema(description: 'Maximum number of items to return per page', minimum: 1)]
		?int $pageLimit = null,
		#[Schema(description: 'Starting record offset for pagination (0-based)', minimum: 0)]
		?int $pageOffset = null
	): array
	{
		return $this->doList('administrator', $language, $filterSearch, $pageLimit, $pageOffset);
	}

	#[McpTool(
		name: 'languages_overrides_admin_read',
		description: 'Retrieve a specific administrator language override',
		readOnlyHint: true,
	)]
	public function readAdminOverride(
		#[Schema(description: 'The language code, e.g. "en-GB"')]
		string $language,
		#[Schema(description: 'The language constant key')]
		string $key
	): array
	{
		return $this->doRead('administrator', $language, $key);
	}

	#[McpTool(
		name: 'languages_overrides_admin_create',
		description: 'Create a new administrator language override'
	)]
	public function createAdminOverride(
		#[Schema(description: 'The language code, e.g. "en-GB"')]
		string $language,
		#[Schema(description: 'The language constant key')]
		string $key,
		#[Schema(description: 'The override value')]
		string $value
	): array
	{
		return $this->doCreate('administrator', $language, $key, $value);
	}

	#[McpTool(
		name: 'languages_overrides_admin_update',
		description: 'Update an existing administrator language override',
		idempotentHint: true,
	)]
	public function updateAdminOverride(
		#[Schema(description: 'The language code, e.g. "en-GB"')]
		string $language,
		#[Schema(description: 'The language constant key')]
		string $key,
		#[Schema(description: 'The new override value')]
		string $value
	): array
	{
		return $this->doUpdate('administrator', $language, $key, $value);
	}

	#[McpTool(
		name: 'languages_overrides_admin_delete',
		description: 'Delete an administrator language override',
		destructiveHint: true,
	)]
	public function deleteAdminOverride(
		#[Schema(description: 'The language code, e.g. "en-GB"')]
		string $language,
		#[Schema(description: 'The language constant key')]
		string $key
	): array
	{
		return $this->doDelete('administrator', $language, $key);
	}

	// =====================================================================================================
	// Language override search
	// =====================================================================================================

	#[McpTool(
		name: 'languages_overrides_search',
		description: 'Search language strings for creating overrides',
		readOnlyHint: true,
	)]
	public function searchOverrides(
		#[Schema(description: 'The language code, e.g. "en-GB"')]
		string $language,
		#[Schema(description: 'The string to search for')]
		string $searchString,
		#[Schema(description: 'The type of search: "constant" or "value"')]
		?string $searchType = null
	): array
	{
		// Search/refresh are site-only in the original (which has no client parameter on either method)
		// — see .plans/11-overrides-redirects-messages.md Part A.
		$this->requireAuthorised('core.manage', self::OPTION);

		$language = $this->requireLanguage('site', $language);

		try
		{
			$type = OverrideMapper::normaliseSearchType($searchType);
		}
		catch (\InvalidArgumentException $e)
		{
			throw new ToolException($e->getMessage());
		}

		$this->ensureSessionRegistryIsObject();

		$app = $this->getApplication();

		$cachedTime     = $app->getUserState('com_languages.overrides.cachedtime.site.' . $language);
		$cachedLanguage = $app->getUserState('com_languages.overrides.filter.language');

		if (empty($cachedTime) || $cachedLanguage !== $language || !$this->hasIndexedOverriderRows())
		{
			$this->refresh($language);
		}

		$input = $app->getInput();
		$input->set('searchstring', $searchString);
		$input->set('searchtype', $type);
		$input->set('more', 0);

		/** @var \Joomla\Component\Languages\Administrator\Model\StringsModel $model */
		$model  = $this->createModel(self::OPTION, 'Strings');
		$result = $model->search();

		if ($result instanceof \Exception)
		{
			throw new ToolException($result->getMessage());
		}

		$rows  = is_array($result) ? ($result['results'] ?? []) : [];
		$items = [];

		foreach ($rows as $row)
		{
			$normalised = ItemNormalizer::toArray($row);

			$items[] = [
				'constant' => (string) ($normalised['constant'] ?? ''),
				'string'   => (string) ($normalised['string'] ?? ''),
				'file'     => OverrideMapper::relativePath((string) ($normalised['file'] ?? ''), JPATH_ROOT),
			];
		}

		return [
			'items'      => $items,
			'pagination' => ['total' => count($items), 'limit' => 10, 'offset' => 0],
			'more'       => is_array($result) && isset($result['more']),
		];
	}

	#[McpTool(
		name: 'languages_overrides_cache_refresh',
		description: 'Refresh the language override search cache'
	)]
	public function refreshCache(): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);

		$this->ensureSessionRegistryIsObject();

		$language = $this->getApplication()->getUserState('com_languages.overrides.filter.language') ?: 'en-GB';
		$language = $this->requireLanguage('site', (string) $language);

		$this->refresh($language);

		return ['success' => true, 'client' => 'site', 'language' => $language];
	}

	// =====================================================================================================
	// Shared CRUD implementation
	// =====================================================================================================

	private function doList(string $client, string $language, ?string $filterSearch, ?int $pageLimit, ?int $pageOffset): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);

		$language = $this->requireLanguage($client, $language);

		[$limit, $offset] = Pagination::normalise($pageLimit, $pageOffset);

		/** @var \Joomla\Component\Languages\Administrator\Model\OverridesModel $model */
		$model = $this->createModel(self::OPTION, 'Overrides');
		$model->setState('filter.client', $client);
		$model->setState('filter.language', $language);

		if ($filterSearch !== null)
		{
			$model->setState('filter.search', $filterSearch);
		}

		$model->setState('list.ordering', 'key');
		$model->setState('list.direction', 'ASC');
		$model->setState('list.start', $offset);
		$model->setState('list.limit', $limit);

		$strings = $model->getOverrides();
		$total   = (int) $model->getTotal();

		return [
			'items'      => OverrideMapper::toListItems($strings),
			'pagination' => [
				'total'  => $total,
				'limit'  => $limit,
				// OverridesModel::getOverrides() slices by $this->getStart(), which clamps an
				// out-of-range start to the last page — report the clamped value, not the raw $offset.
				'offset' => (int) $model->getStart(),
			],
		];
	}

	private function doRead(string $client, string $language, string $key): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);

		$language = $this->requireLanguage($client, $language);
		$key      = $this->requireKey($key);

		return $this->readRecord($client, $language, $key);
	}

	private function doCreate(string $client, string $language, string $key, string $value): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);
		$this->requireAuthorised('core.create', self::OPTION);

		$language = $this->requireLanguage($client, $language);
		$key      = $this->requireKey($key);

		if (array_key_exists($key, $this->strings($client, $language)))
		{
			throw new ToolException(sprintf('The language override %s already exists; use the update tool.', $key));
		}

		$savedKey = $this->writeOverride($client, $language, $key, $value, '');

		return $this->readRecord($client, $language, $savedKey);
	}

	private function doUpdate(string $client, string $language, string $key, string $value): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);
		$this->requireAuthorised('core.edit', self::OPTION);

		$language = $this->requireLanguage($client, $language);
		$key      = $this->requireKey($key);

		if (!array_key_exists($key, $this->strings($client, $language)))
		{
			throw new ToolException('Not found: language override ' . $key);
		}

		$savedKey = $this->writeOverride($client, $language, $key, $value, $key);

		return $this->readRecord($client, $language, $savedKey);
	}

	private function doDelete(string $client, string $language, string $key): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);
		$this->requireAuthorised('core.delete', self::OPTION);

		$language = $this->requireLanguage($client, $language);
		$key      = $this->requireKey($key);

		if (!array_key_exists($key, $this->strings($client, $language)))
		{
			throw new ToolException('Not found: language override ' . $key);
		}

		/** @var \Joomla\Component\Languages\Administrator\Model\OverridesModel $model */
		$model = $this->createModel(self::OPTION, 'Overrides');
		$model->setState('filter.client', $client);
		$model->setState('filter.language', $language);

		// OverridesModel::delete() takes $cids by value (not by reference, unlike AdminModel::delete()) —
		// still assigned to its own variable rather than an inline array literal, for consistency with
		// every other delete call site in this codebase (see .plans/00-conventions.md §5.2).
		//
		// @-suppressed: on Joomla 4.4 only, this ends up calling
		// `\Joomla\Filesystem\File::write($file, &$buffer)` (a by-reference *parameter*) with a non-variable
		// (a method-call return value) as the argument, several frames down inside
		// `LanguageHelper::saveToIniFile()` — a genuine bug in Joomla 4.4's own
		// `LanguageHelper::saveToIniFile()`/joomla/filesystem pairing (fixed by 5.4/6.x), entirely outside
		// this component. The write itself still succeeds (PHP falls back to a temporary copy); the only
		// effect of the notice is that, on a stack with `display_errors` on, the HTML-formatted notice text
		// gets echoed into (and corrupts) this JSON-RPC response body — the exact same class of Joomla
		// 4.4-only "stray notice corrupts JSON output" issue already documented in
		// `Mapper/Languages/ContentLanguageMapper.php`/`Mapper/Tags/TagMapper.php` (plans 03/10). We do not
		// control this call site (it is several frames inside core), so it is suppressed here rather than
		// left to corrupt every `_delete` response on that Joomla version.
		$cids   = [$key];
		$result = @$model->delete($cids);

		if ($result === false)
		{
			throw new ToolException($this->modelError($model, sprintf('Delete failed for language override %s', $key)));
		}

		$this->invalidateOverrideCache($client, $language);

		return [
			'deleted'  => true,
			'key'      => $key,
			'language' => $language,
			'client'   => $client,
		];
	}

	// =====================================================================================================
	// Private helpers
	// =====================================================================================================

	private function basePath(string $client): string
	{
		return $client === 'administrator' ? JPATH_ADMINISTRATOR : JPATH_SITE;
	}

	/**
	 * @throws  ToolException  `$language` isn't a well-formed tag, or isn't installed for `$client`.
	 */
	private function requireLanguage(string $client, string $language): string
	{
		if (
			!OverrideMapper::isWellFormedLanguageTag($language)
			|| !array_key_exists($language, LanguageHelper::getKnownLanguages($this->basePath($client)))
		)
		{
			throw new ToolException(sprintf('Not found: language %s (%s)', $language, $client));
		}

		return $language;
	}

	/**
	 * @throws  ToolException  `$key` is not a valid (non-reserved) language constant.
	 */
	private function requireKey(string $key): string
	{
		try
		{
			return OverrideMapper::normaliseKey($key);
		}
		catch (\InvalidArgumentException $e)
		{
			throw new ToolException('Invalid language constant: ' . $e->getMessage());
		}
	}

	/**
	 * @return  array<string, string>
	 */
	private function strings(string $client, string $language): array
	{
		return LanguageHelper::parseIniFile($this->basePath($client) . '/language/overrides/' . $language . '.override.ini');
	}

	/**
	 * Works around a real caching bug in `LanguageHelper::parseIniFile()`: its file-based cache key is
	 * `filemtime($fileName)`, which only has one-second resolution — two writes to the same override file
	 * within the same wall-clock second collide on the very same cache filename, and whichever call reads
	 * the file *after* that collision gets the *first* write's stale content back. This is not
	 * hypothetical: it is exactly what a fast, automated create -> read -> update -> read -> delete cycle
	 * over HTTP does in well under a second, and this plan's own E2E suite hit it directly (a
	 * `languages_overrides_site_update` immediately followed by a `..._read` returning the *pre*-update
	 * value). `LanguageHelper` is core and out of scope to change, so every write this class makes is
	 * followed by deleting that specific ini file's *current* cache entry (replicating
	 * `parseIniFile()`'s own cache-path formula), forcing the next `parseIniFile()` call — this request or
	 * any later one — to re-parse from disk instead of serving a stale cache.
	 */
	private function invalidateOverrideCache(string $client, string $language): void
	{
		$fileName = $this->basePath($client) . '/language/overrides/' . $language . '.override.ini';

		if (!is_file($fileName))
		{
			// The write emptied the file and OverridesModel/OverrideModel deleted it -- nothing to do.
			return;
		}

		$cacheFile = JPATH_CACHE . '/language/' . ltrim(str_replace([JPATH_ROOT, '/'], ['', '-'], $fileName), '-') . '.' . filemtime($fileName) . '.php';

		if (is_file($cacheFile))
		{
			@unlink($cacheFile);
		}
	}

	private function readRecord(string $client, string $language, string $key): array
	{
		$strings = $this->strings($client, $language);

		if (!array_key_exists($key, $strings))
		{
			throw new ToolException('Not found: language override ' . $key);
		}

		return OverrideMapper::toRecord($key, $strings[$key], $language, $client);
	}

	/**
	 * Replaces `AbstractTool::saveItem()` for this resource (there is no `Table`/form-bound save path a
	 * generic helper could drive) — see `.plans/11-overrides-redirects-messages.md` Part A.
	 *
	 * @return  string  The saved (form-filtered) key.
	 * @throws  ToolException
	 */
	private function writeOverride(string $client, string $language, string $key, string $value, string $originalId): string
	{
		/** @var \Joomla\Component\Languages\Administrator\Model\OverrideModel $model */
		$model = $this->createModel(self::OPTION, 'Override');
		$model->setState('filter.client', $client);
		$model->setState('filter.language', $language);

		$data = ['key' => $key, 'override' => $value, 'both' => 0, 'id' => $originalId];

		$this->getApplication()->getInput()->set('jform', $data);

		$form = $model->getForm($data, false);

		if (!$form)
		{
			throw new ToolException('Cannot load the language override form: ' . $this->modelError($model, 'Unknown error'));
		}

		$valid = $model->validate($form, $data);

		if ($valid === false)
		{
			$messages = [];

			foreach (array_slice($model->getErrors(), 0, 3) as $error)
			{
				$messages[] = $error instanceof \Throwable ? $error->getMessage() : (string) $error;
			}

			if ($messages === [])
			{
				$messages[] = $this->modelError($model, 'Unknown validation error');
			}

			throw new ToolException('Validation failed for language override: ' . implode('; ', $messages));
		}

		$valid['id']   = $originalId;
		$valid['both'] = 0;

		// OverrideModel::save() reads the client from state differently than every other method on this
		// same model: in the API application it does `$client = $this->getState('filter.client')` then
		// `$client = $client ? 'administrator' : 'site'` — i.e. it treats the value as an int/bool, not
		// the client STRING getForm()/getItem()/OverridesModel::* all need. A non-empty string is always
		// truthy, so leaving the string here would silently save every override to the administrator
		// file. Switch to the int form only right here, immediately before save() — getForm() with an
		// int client crashes (constant('JPATH_1') does not exist).
		$model->setState('filter.client', OverrideMapper::clientInt($client));

		// @-suppressed: see the matching comment in doDelete() — OverrideModel::save() -> saveToIniFile()
		// hits the exact same Joomla-4.4-only File::write() by-reference notice on every create/update.
		if (!@$model->save($valid))
		{
			throw new ToolException('Save failed for language override: ' . $this->modelError($model, 'Unknown error'));
		}

		$this->invalidateOverrideCache($client, $language);

		return $valid['key'];
	}

	/**
	 * Shared by `languages_overrides_search` (when the index isn't built/current) and
	 * `languages_overrides_cache_refresh`. Always indexes the `site` client, like the original tool (see
	 * `.plans/11-overrides-redirects-messages.md` Part A) — `StringsModel::refresh()` reads client/
	 * language from *user state*, which this sets first.
	 *
	 * @throws  ToolException
	 */
	private function refresh(string $language): void
	{
		$this->ensureSessionRegistryIsObject();

		$app = $this->getApplication();
		$app->setUserState('com_languages.overrides.filter.client', 0);
		$app->setUserState('com_languages.overrides.filter.language', $language);

		/** @var \Joomla\Component\Languages\Administrator\Model\StringsModel $model */
		$model  = $this->createModel(self::OPTION, 'Strings');
		$result = $model->refresh();

		if ($result instanceof \Exception)
		{
			throw new ToolException($result->getMessage());
		}
	}

	private function hasIndexedOverriderRows(): bool
	{
		$db    = $this->getDatabase();
		$query = $db->createQuery()
			->select('COUNT(*)')
			->from($db->quoteName('#__overrider'));

		return (int) $db->setQuery($query)->loadResult() > 0;
	}

	/**
	 * `CMSApplication::getUserState()`/`setUserState()` (used throughout this class) read/write a
	 * `Joomla\Registry\Registry` object cached in the session under the `registry` key. This MCP
	 * session's data is persisted across requests via
	 * {@see \Akeeba\Component\Mcp4j\Administrator\Support\SessionDataCodec}, which is deliberately
	 * JSON-only, so once a session has round-tripped through storage at least once, its `registry` entry
	 * has decayed from a `Registry` object to a plain array — and the very next `getUserState()`/
	 * `setUserState()` call anywhere in Joomla core is a PHP fatal ("Call to a member function get()/
	 * set() on array"). This is a documented trade-off of the session bridge, owned by plan 01/03
	 * (`component/backend/src/Support/` is out of scope for this plan to change — see
	 * `.plans/00-conventions.md` §8); worked around here, narrowly, exactly as plan 10's
	 * `Tool/Config/Config.php::ensureSessionRegistryIsObject()` already does for the same reason.
	 */
	private function ensureSessionRegistryIsObject(): void
	{
		$session  = $this->getApplication()->getSession();
		$registry = $session->get('registry');

		if (is_array($registry))
		{
			$session->set('registry', new Registry($registry));
		}
	}
}
