<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Bridge;

defined('_JEXEC') || die;

use Akeeba\Component\Mcp4j\Administrator\Bridge\Support\DataMerger;
use Akeeba\Component\Mcp4j\Administrator\Bridge\Support\ItemNormalizer;
use Akeeba\Component\Mcp4j\Administrator\Bridge\Support\OutputShaper;
use Akeeba\Component\Mcp4j\Administrator\Bridge\Support\Pagination;
use Akeeba\Component\Mcp4j\Administrator\Bridge\Support\TagIds;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Exception\ToolException;
use Akeeba\Component\Mcp4j\Administrator\Mcp\ToolContext;
use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\MVC\Model\AdminModel;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\CMS\MVC\Model\ListModel;
use Joomla\CMS\User\CurrentUserInterface;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;

/**
 * Base class every `Tool/<Category>/<Class>.php` class extends to call Joomla MVC models safely.
 *
 * This is a de-HTTP'd version of what `Joomla\CMS\MVC\Controller\ApiController` and `FormController` do
 * for a real Web Services API request — `listItems()`/`loadItem()`/`saveItem()`/`changeState()`/
 * `deleteItem()` mirror `displayList()`/`displayItem()`/`save()`/`delete()` respectively, minus anything
 * HTTP/JSON:API-shaped. See `.plans/03-joomla-bridge-and-tags-pilot.md` Part B for the full contract this
 * class implements; domain plans (04 onward) should not need to touch this file — extend by writing a
 * `Mapper/<Category>` class and a thin `Tool/<Category>` class on top of it instead.
 *
 * Conventions every domain plan relies on:
 *
 * - **Never generate an alias in PHP.** Pass `alias` through only when the caller explicitly gave one;
 *   `Table::check()` (called from inside `AdminModel::save()`) generates one itself, honouring the site's
 *   Unicode-alias setting. This deliberately differs from the original `joomla-mcp-php` project's
 *   `TitleToAliasTrait`, which generated aliases client-side against a fixed transliteration table with
 *   no way to know the site's `unicodeslugs` setting.
 * - **Always check `core.manage` on the component first.** Every tool method should call
 *   `$this->requireAuthorised('core.manage', $option)` before anything else, then the action-specific
 *   check ({@see self::requireAuthorised()} again for `core.create`/`core.delete`/`core.edit.state`, or
 *   {@see self::requireEditAllowed()} for `core.edit`/`core.edit.own`). Models do NOT enforce ACL on
 *   `save()` themselves — that is a controller-layer concern in stock Joomla, and this class is standing
 *   in for that controller layer.
 *
 * @since  0.0.1
 */
abstract class AbstractTool
{
	/**
	 * Options whose language files have already been loaded this request — {@see self::loadLanguage()} is
	 * called from {@see self::createModel()} on every single model creation, so memoising avoids repeated
	 * filesystem scans for the same component within one tool call (and across tool calls that create more
	 * than one model of the same component).
	 *
	 * @var  array<string, bool>
	 */
	private array $loadedLanguages = [];

	/**
	 * State for {@see self::beginLegacyCurrentUserWorkaround()}/{@see self::endLegacyCurrentUserWorkaround()}.
	 * Not re-entrant by design (the pattern here is always begin/try/finally-end around a single core
	 * call, never nested) — see that pair's docblock.
	 */
	private bool $legacyCurrentUserHadPrevious = false;

	private mixed $legacyCurrentUserPrevious = null;

	public function __construct(protected readonly ToolContext $context)
	{
	}

	protected function getApplication(): CMSApplicationInterface
	{
		return $this->context->app;
	}

	protected function getUser(): User
	{
		return $this->context->user;
	}

	protected function getDatabase(): DatabaseInterface
	{
		return Factory::getContainer()->get(DatabaseInterface::class);
	}

	/**
	 * Loads the standard set of language files for a component's backend, exactly as core's own
	 * controllers do before touching a model/form — some validation/display logic depends on strings
	 * already being loaded (e.g. form field labels used in validation error messages).
	 */
	protected function loadLanguage(string $option): void
	{
		if (isset($this->loadedLanguages[$option]))
		{
			return;
		}

		$this->loadedLanguages[$option] = true;

		$lang = $this->getApplication()->getLanguage();
		$lang->load('lib_joomla', JPATH_ADMINISTRATOR);
		$lang->load($option, JPATH_ADMINISTRATOR);
		$lang->load($option, JPATH_ADMINISTRATOR . '/components/' . $option);
	}

	/**
	 * Creates a Joomla MVC model for another component, the way that component's own controllers would —
	 * working around `JPATH_COMPONENT`/`JPATH_COMPONENT_ADMINISTRATOR` pointing at `com_mcp4j` during our
	 * request (see `.plans/00-conventions.md` §5.5) by registering the *target* component's form paths
	 * ourselves before the model (or anything it calls, e.g. `FormBehaviorTrait::loadForm()`) gets a chance
	 * to register `com_mcp4j`'s instead. `ignore_request => true` stops the model from trying to read
	 * state out of the current (com_mcp4j) request — see §5.4: every state key the model needs must then be
	 * set explicitly by the caller.
	 *
	 * @param   string  $option  The target component's option, e.g. `com_tags`.
	 * @param   string  $name    The model name, e.g. `Tag` or `Tags`.
	 * @param   string  $prefix  The model prefix — `Administrator` (default) or `Site`.
	 * @param   array   $config  Extra model config, merged under `ignore_request => true`.
	 *
	 * @throws  ToolException  The model could not be created.
	 */
	protected function createModel(string $option, string $name, string $prefix = 'Administrator', array $config = []): BaseDatabaseModel
	{
		$this->loadLanguage($option);

		if ($prefix === 'Site')
		{
			Form::addFormPath(JPATH_SITE . "/components/$option/forms");
			Form::addFormPath(JPATH_SITE . "/components/$option/models/forms");
		}
		else
		{
			Form::addFormPath(JPATH_ADMINISTRATOR . "/components/$option/forms");
			Form::addFormPath(JPATH_ADMINISTRATOR . "/components/$option/models/forms");
		}

		try
		{
			$factory = $this->getApplication()->bootComponent($option)->getMVCFactory();
			$model   = $factory->createModel($name, $prefix, $config + ['ignore_request' => true]);
		}
		catch (\Throwable $e)
		{
			throw new ToolException(sprintf('Cannot create the %s %s model of %s', $prefix, $name, $option), 0, $e);
		}

		if (!$model instanceof BaseDatabaseModel)
		{
			throw new ToolException(sprintf('Cannot create the %s %s model of %s', $prefix, $name, $option));
		}

		// Every model implements CurrentUserInterface (via CurrentUserTrait on BaseDatabaseModel, all
		// three supported Joomla versions), but nothing in the MVCFactory chain ever calls
		// setCurrentUser() on it — left unset, CurrentUserTrait::getCurrentUser() lazily falls back to
		// `Factory::getApplication()->getIdentity() ?: Factory::getUser()` on first use. That fallback is
		// normally fine, but AdminModel::canEditState()/canDelete() (used by publish()/delete() — see
		// `.plans/00-conventions.md` §5.3) call it, and on Joomla 4.4 specifically, com_tags' own
		// TagTable does not implement CurrentUserInterface (added to it only in a later Joomla version),
		// so nothing ever populates the model's currentUser this way in practice on that version — it was
		// observed resolving to the guest user rather than this request's bearer-token identity, wrongly
		// denying trash/delete for a fully-authorised Super User. Setting it explicitly and immediately,
		// from the same identity {@see ToolContext} already resolved, removes any dependency on that
		// lazy-fallback chain across all three supported Joomla versions.
		if ($model instanceof CurrentUserInterface)
		{
			$model->setCurrentUser($this->getUser());
		}

		return $model;
	}

	/**
	 * @throws  ToolException  `$action` is not authorised on `$asset` for the current user.
	 */
	protected function requireAuthorised(string $action, string $asset): void
	{
		if (!$this->isAuthorised($action, $asset))
		{
			throw new ToolException(sprintf('Permission denied: %s on %s', $action, $asset));
		}
	}

	protected function isAuthorised(string $action, string $asset): bool
	{
		return (bool) $this->getUser()->authorise($action, $asset);
	}

	/**
	 * The `core.edit` / `core.edit.own` check every backend edit form performs: full edit rights on the
	 * asset, or "edit own" rights when the current user is the record's owner.
	 *
	 * @param   ?int  $ownerId  The record's `created_user_id` (or equivalent), or null/0 if unknown/unset —
	 *                          "own" never matches an unknown owner.
	 *
	 * @throws  ToolException
	 */
	protected function requireEditAllowed(string $asset, ?int $ownerId): void
	{
		if ($this->isAuthorised('core.edit', $asset))
		{
			return;
		}

		$userId = (int) $this->getUser()->id;

		if ($ownerId !== null && $ownerId > 0 && $ownerId === $userId && $this->isAuthorised('core.edit.own', $asset))
		{
			return;
		}

		throw new ToolException(sprintf('Permission denied: %s on %s', 'core.edit', $asset));
	}

	/**
	 * De-HTTP'd equivalent of `ApiController::displayList()`: sets every given state key, paginates, runs
	 * the list query, and shapes the result into the standard `*_list` tool return shape.
	 *
	 * All state must be set before the first `getItems()`/`getTotal()` call — Joomla list models cache
	 * their result by a "store id" computed from state at first access, so setting state afterwards would
	 * silently be ignored.
	 *
	 * @param   array<string, mixed>  $state       `setState()` key => value pairs the model's
	 *                                              `getListQuery()` reads (see
	 *                                              `.plans/00-conventions.md` §5.4 — read the model).
	 * @param   list<string>          $fields      Output field whitelist for each item.
	 * @param   list<string>          $intFields   Fields to coerce to `int`.
	 * @param   list<string>          $jsonFields  Fields holding JSON-encoded strings to decode.
	 *
	 * @return  array{items: list<array>, pagination: array{total: int, limit: int, offset: int}}
	 * @throws  ToolException
	 */
	protected function listItems(
		ListModel $model,
		array $state,
		?int $pageLimit,
		?int $pageOffset,
		array $fields,
		array $intFields = [],
		array $jsonFields = []
	): array {
		[$limit, $offset] = Pagination::normalise($pageLimit, $pageOffset);

		foreach ($state as $key => $value)
		{
			$model->setState($key, $value);
		}

		$model->setState('list.start', $offset);
		$model->setState('list.limit', $limit);

		$items = $model->getItems();

		if ($items === false)
		{
			throw new ToolException($this->modelError($model, 'Cannot list items'));
		}

		$total = (int) $model->getTotal();

		return [
			'items'      => OutputShaper::shapeList($items, $fields, $intFields, $jsonFields),
			'pagination' => [
				'total'  => $total,
				'limit'  => $limit,
				'offset' => $offset,
			],
		];
	}

	/**
	 * De-HTTP'd equivalent of `ApiController::displayItem()`.
	 *
	 * @throws  ToolException  `$id` is not a positive integer, or no such record exists.
	 *
	 * @return  array<string, mixed>  The normalised (not yet output-shaped) item.
	 */
	protected function loadItem(AdminModel $model, int $id, string $resource): array
	{
		if ($id <= 0)
		{
			// Checked before touching the model: some core models (e.g. ModuleModel::getItem(0)) call
			// $app->redirect() for a falsy id, which ends the request outright.
			throw new ToolException(sprintf('Not found: %s #%d', $resource, $id));
		}

		$item = $model->getItem($id);

		if ($item === false || $item === null)
		{
			throw new ToolException(sprintf('Not found: %s #%d', $resource, $id));
		}

		$normalised = ItemNormalizer::toArray($item);

		$keyName  = $model->getTable()->getKeyName();
		$keyValue = $normalised[$keyName] ?? null;

		if ($keyValue === null || $keyValue === '' || (int) $keyValue !== $id)
		{
			throw new ToolException(sprintf('Not found: %s #%d', $resource, $id));
		}

		return $normalised;
	}

	/**
	 * De-HTTP'd equivalent of `ApiController::save()` (both the create and the update path). Mirrors the
	 * core controller's flow exactly — see `.plans/03-joomla-bridge-and-tags-pilot.md` Part B for the
	 * step-by-step rationale — because that flow is precisely what applies the form's own validation and
	 * filtering (`Form::filter()`, `safehtml`, `user_utc`, ...), which is a security boundary a tool must
	 * never bypass by binding a `Table` directly.
	 *
	 * @param   array<string, mixed>  $data  For a create, the full set of fields. For an update, only the
	 *                                       fields to change — merged onto the current record first.
	 * @param   ?int                   $id    Null/0 for create, the record id for update.
	 *
	 * @return  int  The saved record's id.
	 * @throws  ToolException
	 */
	protected function saveItem(AdminModel $model, array $data, ?int $id, string $resource): int
	{
		$table       = $model->getTable();
		$key         = $table->getKeyName();
		$hasCheckout = $table->hasField('checked_out');
		$app         = $this->getApplication();

		if ($id)
		{
			$current = $this->loadItem($model, $id, $resource);

			if (array_key_exists('tags', $current))
			{
				$current['tags'] = TagIds::fromMixed($current['tags']);
			}

			$data = DataMerger::merge($current, $data);

			if ($hasCheckout && !$model->checkout($id))
			{
				throw new ToolException(sprintf('Cannot edit %s #%d: %s', $resource, $id, $this->modelError($model, 'Checked out by another user')));
			}
		}

		$data[$key] = $id ?: 0;

		$input = $app->getInput();
		$input->set('jform', $data);
		$input->post->set('jform', $data);
		$input->set('id', $data[$key]);

		try
		{
			$form = $model->getForm($data, false);

			if (!$form)
			{
				throw new ToolException(sprintf('Cannot load the %s form: %s', $resource, $this->modelError($model, 'Unknown error')));
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

				throw new ToolException(sprintf('Validation failed for %s: %s', $resource, implode('; ', $messages)));
			}

			if ($form->getField('tags') !== false && !array_key_exists('tags', $valid))
			{
				$valid['tags'] = [];
			}

			// Some 4.4-era Tables (e.g. TagTable::store()) default created_user_id/modified_user_id via
			// the classic Factory::getUser() rather than $this->getCurrentUser() — see
			// beginLegacyCurrentUserWorkaround()'s docblock; harmless to do unconditionally on every
			// version, since a Table that already resolves the current user correctly just reads the same
			// (correct) value from the session as it would have from anywhere else.
			$this->beginLegacyCurrentUserWorkaround();

			try
			{
				$saved = $model->save($valid);
			}
			finally
			{
				$this->endLegacyCurrentUserWorkaround();
			}

			if (!$saved)
			{
				throw new ToolException(sprintf('Save failed for %s: %s', $resource, $this->modelError($model, 'Unknown error')));
			}
		}
		finally
		{
			if ($id && $hasCheckout)
			{
				$model->checkin($id);
			}
		}

		$newId = (int) $model->getState($model->getName() . '.id');

		if ($newId <= 0)
		{
			throw new ToolException(sprintf('Save failed for %s: %s', $resource, $this->modelError($model, 'No id was returned by the model')));
		}

		if (!$id && $hasCheckout)
		{
			$model->checkin($newId);
		}

		return $newId;
	}

	/**
	 * De-HTTP'd equivalent of the Web Services API's PATCH-a-`published`-field path, via
	 * `AdminModel::publish()` (which enforces `core.edit.state` and check-out ownership itself — see
	 * `.plans/00-conventions.md` §5.3) rather than a plain field update.
	 *
	 * `AdminModel::publish()` also silently prunes (and, if that leaves nothing to change, returns `true`
	 * for) a record that is already at the target state — callers that want "trash it if it isn't already
	 * trashed" (e.g. `tags_delete`) should check the current state themselves before calling this, rather
	 * than relying on this method to no-op cleanly; see `Tool/Tags/Tags.php::deleteTag()`.
	 *
	 * @throws  ToolException  Not found, or the state change was refused/pruned.
	 */
	protected function changeState(AdminModel $model, int $id, int $state, string $resource): void
	{
		$this->loadItem($model, $id, $resource);

		$pks = [$id];

		$this->beginLegacyCurrentUserWorkaround();

		try
		{
			$result = $model->publish($pks, $state);
		}
		finally
		{
			$this->endLegacyCurrentUserWorkaround();
		}

		if (!$result || $pks === [])
		{
			throw new ToolException($this->modelError($model, sprintf('Permission denied: core.edit.state on %s #%d', $resource, $id)));
		}
	}

	/**
	 * De-HTTP'd equivalent of `ApiController::delete()`, via `AdminModel::delete()` (enforces `core.delete`
	 * itself — see `.plans/00-conventions.md` §5.3).
	 *
	 * @throws  ToolException  Not found, or the delete was refused.
	 */
	protected function deleteItem(AdminModel $model, int $id, string $resource): void
	{
		$this->loadItem($model, $id, $resource);

		$pks = [$id];

		$this->beginLegacyCurrentUserWorkaround();

		try
		{
			$result = $model->delete($pks);
		}
		finally
		{
			$this->endLegacyCurrentUserWorkaround();
		}

		if (!$result || $pks === [])
		{
			throw new ToolException($this->modelError($model, sprintf('Permission denied: core.delete on %s #%d', $resource, $id)));
		}
	}

	/**
	 * Joomla 4.4's `AdminModel::canEditState()`/`canDelete()` (used by `publish()`/`delete()`) resolve
	 * "the current user" via the classic `Factory::getUser()`, which reads
	 * `Factory::getApplication()->getSession()->get('user')` — populated by a normal browser-authenticated
	 * `site`/`administrator` request, but never by ours: the `api` application's session is a throwaway
	 * per-request object, not the browser's cookie session (see `.claude/mcp-session-handling.md`), so
	 * `Factory::getUser()` resolves to a guest user and every `changeState()`/`deleteItem()` call is
	 * wrongly refused, even for a fully-authorised Super User. Joomla 5.4 and 6.x already fixed this
	 * upstream (`canEditState()`/`canDelete()` there call `$this->getCurrentUser()`, which
	 * {@see self::createModel()}'s `setCurrentUser()` call already fixes for every version) — this pair
	 * only matters on 4.4, but is harmless to call unconditionally on every version rather than branching
	 * on `JVERSION`.
	 *
	 * Deliberately temporary and narrowly scoped: `endLegacyCurrentUserWorkaround()` must always run (call
	 * it from a `finally` block) immediately after the single `publish()`/`delete()` call that needs it,
	 * restoring (or removing) the session's previous `user` value straight away. A `User` object
	 * (`password` property included) must never be left sitting in the session for longer than that single
	 * call — `SessionManager::persist()` snapshots the *entire* session into the `#__mcp4j_sessions` table
	 * at the end of the request (see `.claude/mcp-session-handling.md`), and while
	 * {@see \Akeeba\Component\Mcp4j\Administrator\Support\SessionDataCodec} only round-trips scalars/arrays
	 * (so a lingering `User` object wouldn't survive a restore intact), `json_encode()`-ing it into that
	 * snapshot in the first place would still leak sensitive user fields into stored session data.
	 */
	private function beginLegacyCurrentUserWorkaround(): void
	{
		$session = $this->getApplication()->getSession();

		$this->legacyCurrentUserHadPrevious = $session->has('user');
		$this->legacyCurrentUserPrevious    = $this->legacyCurrentUserHadPrevious ? $session->get('user') : null;

		$session->set('user', $this->getUser());
	}

	private function endLegacyCurrentUserWorkaround(): void
	{
		$session = $this->getApplication()->getSession();

		if ($this->legacyCurrentUserHadPrevious)
		{
			$session->set('user', $this->legacyCurrentUserPrevious);
		}
		else
		{
			$session->remove('user');
		}

		$this->legacyCurrentUserPrevious    = null;
		$this->legacyCurrentUserHadPrevious = false;
	}

	/**
	 * Extracts a human-readable error out of a model/table, tolerating the differences between Joomla
	 * 4/5/6 (the legacy `getError()`/`getErrors()` pair is deprecated from 5.4 onward but still present;
	 * guard with `method_exists()` regardless, in case a future core version removes it outright).
	 */
	protected function modelError(object $model, string $fallback): string
	{
		if (method_exists($model, 'getError'))
		{
			$error = $model->getError();

			if ($error instanceof \Throwable)
			{
				return $error->getMessage();
			}

			if (is_string($error) && $error !== '')
			{
				return $error;
			}
		}

		if (method_exists($model, 'getErrors'))
		{
			$errors = $model->getErrors();

			if (is_array($errors) && $errors !== [])
			{
				$last = end($errors);

				if ($last instanceof \Throwable)
				{
					return $last->getMessage();
				}

				if (is_string($last) && $last !== '')
				{
					return $last;
				}
			}
		}

		return $fallback;
	}

	/**
	 * Convenience: {@see ItemNormalizer::toArray()} then {@see OutputShaper::shape()} in one call — the
	 * shape every `*_read`/`*_create`/`*_update`/`*_trash` tool method returns through.
	 *
	 * @return  array<string, mixed>
	 */
	protected function shape(array|object $item, array $fields, array $intFields = [], array $jsonFields = []): array
	{
		$normalised = is_array($item) ? $item : ItemNormalizer::toArray($item);

		return OutputShaper::shape($normalised, $fields, $intFields, $jsonFields);
	}
}
