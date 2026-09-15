<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Shared\Templates;

defined('_JEXEC') || die;

use Akeeba\Component\Mcp4j\Administrator\Bridge\AbstractTool;
use Akeeba\Component\Mcp4j\Administrator\Bridge\Support\JsonObjectInput;
use Akeeba\Component\Mcp4j\Administrator\Mapper\Templates\StyleMapper;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Exception\ToolException;
use Joomla\CMS\MVC\Model\AdminModel;
use Joomla\CMS\MVC\Model\ListModel;
use Joomla\Database\ParameterType;

/**
 * Shared implementation for the site/administrator *template styles* tool pairs
 * ({@see \Akeeba\Component\Mcp4j\Administrator\Tool\Templates\SiteStyles} and
 * {@see \Akeeba\Component\Mcp4j\Administrator\Tool\Templates\AdminStyles}) — identical behaviour except for
 * the client id (0 = site, 1 = administrator), which the concrete subclass supplies via
 * {@see self::clientId()}.
 *
 * Ported from the original `joomla-mcp-php` project's `src/Server/Templates/SiteStyles.php` and
 * `AdminStyles.php` (which differ from each other only in tool names/descriptions/URLs) — see
 * `.plans/07-modules-templates.md` Part B.
 *
 * `StyleModel`/`StylesModel` extend `AdminModel`/`ListModel` on every supported Joomla version (verified in
 * boot4/boot5/boot6), so this class reuses {@see AbstractTool::loadItem()}/`saveItem()`/`deleteItem()}`
 * directly. There is no `doTrash()` — template styles have no publish state — and `changeState()` is never
 * used here.
 *
 * @since  0.0.1
 */
abstract class AbstractStylesTool extends AbstractTool
{
	private const OPTION = 'com_templates';

	/**
	 * 0 = site styles, 1 = administrator styles.
	 */
	abstract protected function clientId(): int;

	protected function resourceLabel(): string
	{
		return $this->clientId() === 0 ? 'site template style' : 'administrator template style';
	}

	/**
	 * @return  array{items: list<array>, pagination: array{total: int, limit: int, offset: int}}
	 */
	protected function doList(?string $filterSearch, ?string $filterTemplate, ?int $pageLimit, ?int $pageOffset): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);

		/** @var ListModel $model */
		$model = $this->createModel(self::OPTION, 'Styles');

		return $this->listItems(
			$model,
			StyleMapper::listState($this->clientId(), $filterSearch, $filterTemplate),
			$pageLimit,
			$pageOffset,
			StyleMapper::LIST_FIELDS,
			StyleMapper::INT_FIELDS,
			StyleMapper::JSON_FIELDS
		);
	}

	/**
	 * @return  array<string, mixed>
	 */
	protected function doRead(int $id): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);

		return $this->shape($this->loadOwn($id), StyleMapper::ITEM_FIELDS, StyleMapper::INT_FIELDS, StyleMapper::JSON_FIELDS);
	}

	/**
	 * @return  array<string, mixed>
	 */
	protected function doCreate(string $title, string $template, ?bool $home, array|string|null $params): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);
		$this->requireAuthorised('core.create', self::OPTION);

		if ($home === true)
		{
			$this->requireAuthorised('core.edit.state', self::OPTION);
		}

		$decodedParams = JsonObjectInput::decode($params, 'params');

		$this->requireTemplate($template);

		[$inheritable, $parent] = $this->inheritanceFor($template);

		$data = StyleMapper::createData($this->clientId(), $title, $template, $home, $decodedParams, $inheritable, $parent);

		/** @var AdminModel $model */
		$model = $this->createModel(self::OPTION, 'Style');

		$newId = $this->withCurrentUserInSession(function () use ($model, $data) {
			$data = $this->fillParamsDefaults($model, $data);

			return $this->saveStyleItem($model, $data, null);
		});

		return $this->shape($this->loadOwn($newId), StyleMapper::ITEM_FIELDS, StyleMapper::INT_FIELDS, StyleMapper::JSON_FIELDS);
	}

	/**
	 * @return  array<string, mixed>
	 */
	protected function doUpdate(int $id, ?string $title, ?string $template, ?bool $home, array|string|null $params): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);
		$this->requireAuthorised('core.edit', self::OPTION);

		if ($home !== null)
		{
			$this->requireAuthorised('core.edit.state', self::OPTION);
		}

		$current = $this->loadOwn($id);

		if ($template !== null && $template !== ($current['template'] ?? null))
		{
			throw new ToolException('The template of an existing style cannot be changed.');
		}

		if (($current['home'] ?? '0') !== '0' && $home === false)
		{
			throw new ToolException('The default template style cannot be unset. Make another style the default instead.');
		}

		$decodedParams = JsonObjectInput::decode($params, 'params');

		$data             = StyleMapper::updateData($title, $home, $decodedParams);
		$data['assigned'] = $this->assignedMenuItems($id);

		/** @var AdminModel $model */
		$model = $this->createModel(self::OPTION, 'Style');

		$this->withCurrentUserInSession(function () use ($model, $data, $id, $current) {
			$this->warmFormCache($model, $data + ['client_id' => $current['client_id'], 'template' => $current['template']]);

			$this->saveStyleItem($model, $data, $id);
		});

		return $this->shape($this->loadOwn($id), StyleMapper::ITEM_FIELDS, StyleMapper::INT_FIELDS, StyleMapper::JSON_FIELDS);
	}

	/**
	 * @return  array{deleted: true, id: int}
	 */
	protected function doDelete(int $id): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);
		$this->requireAuthorised('core.delete', self::OPTION);

		$current = $this->loadOwn($id);

		if (($current['home'] ?? '0') !== '0')
		{
			throw new ToolException('The default template style cannot be deleted.');
		}

		/** @var AdminModel $model */
		$model = $this->createModel(self::OPTION, 'Style');

		// StyleModel::delete() throws a plain \Exception (not ToolException) when the ACL check it also
		// performs internally fails — see .plans/07-modules-templates.md Part B "Read before coding" and
		// this class's own docblock. We already checked core.delete above, but convert defensively rather
		// than letting a bare \Exception fall through to the dispatcher's generic "Internal error" handler.
		try
		{
			$this->deleteItem($model, $id, $this->resourceLabel());
		}
		catch (ToolException $e)
		{
			throw $e;
		}
		catch (\Exception $e)
		{
			throw new ToolException($e->getMessage());
		}

		return ['deleted' => true, 'id' => $id];
	}

	/**
	 * @param   array<string, mixed>  $data
	 *
	 * @throws  ToolException
	 */
	private function saveStyleItem(AdminModel $model, array $data, ?int $id): int
	{
		try
		{
			return $this->saveItem($model, $data, $id, $this->resourceLabel());
		}
		catch (ToolException $e)
		{
			throw $e;
		}
		catch (\Exception $e)
		{
			throw new ToolException($e->getMessage());
		}
	}

	/**
	 * Fills in every declared `params.*` field this create call didn't explicitly supply with that field's
	 * own XML `default` — the missing-key-means-absent-from-filtered-output rule that
	 * {@see \Akeeba\Component\Mcp4j\Administrator\Shared\Modules\AbstractModulesTool::fillParamsDefaults()}'s
	 * docblock explains in full (identical root cause here: `#__template_styles.params` is `text NOT NULL`
	 * with no column default, and a template's own `<config>` params group is otherwise only populated in
	 * the filtered save data for subfields our own input already names).
	 *
	 * Must be called from inside {@see self::withCurrentUserInSession()} — see that method's docblock for
	 * why, and for the *other* Joomla-4.4-only bug (a PHP warning corrupting the response) its suppressed
	 * `getForm()` call underneath also happens to dodge.
	 *
	 * Deliberately fills gaps with each field's raw XML `default` string (via `Form::getFieldAttribute()`)
	 * rather than `FormField::$value` (the bound, display-oriented value `Form::getGroup()` would otherwise
	 * hand back) — a field's *display* value is not always the same representation `Form::filter()` would
	 * later save (e.g. a `type="radio"` switcher field's `value` renders as a genuine PHP `bool`, while
	 * `filter()` — never invoked for a key {@see self::doCreate()} never actually supplies, before this
	 * method ran — would have produced the field's own filtered/typed form of the string default instead).
	 * Every value this method adds, like every value the caller supplied, is left as a raw, unfiltered
	 * scalar precisely so the *real* `Form::filter()`/`validate()` pass inside `AbstractTool::saveItem()`
	 * — not this method — is what ultimately decides each field's saved type/representation, exactly as it
	 * would for a value a real browser submission had sent. Found (a "true" `bool` where a "1" `string` was
	 * expected) by this plan's own E2E suite; see the final report.
	 *
	 * @param   array<string, mixed>  $data  Must already carry `client_id`/`template` (see
	 *                                       `StyleModel::getForm()`).
	 *
	 * @return  array<string, mixed>
	 */
	private function fillParamsDefaults(AdminModel $model, array $data): array
	{
		$form = @$model->getForm($data, false);

		if (!$form)
		{
			return $data;
		}

		$full = is_array($data['params'] ?? null) ? $data['params'] : [];

		foreach ($form->getGroup('params') as $field)
		{
			$name = $field->fieldname;

			if (!array_key_exists($name, $full))
			{
				$full[$name] = $form->getFieldAttribute($name, 'default', '', 'params');
			}
		}

		if ($full !== [])
		{
			$data['params'] = $full;
		}

		return $data;
	}

	/**
	 * Primes `$model`'s own Form cache (`FormBehaviorTrait::$_forms`, keyed only by form name/source/control
	 * — never by `$data` or `$loadData`) for an *update*, without touching `$data` itself the way
	 * {@see self::fillParamsDefaults()} does (which must stay create-only — recomputing every `params.*`
	 * field's value from a bind of only the *given* update fields would wrongly reset every untouched
	 * `params` key back to its XML default, breaking the "null/absent means don't change" contract).
	 *
	 * Why this is needed at all: `AbstractTool::saveItem()` (`.plans/00-conventions.md` §8 forbidden file)
	 * always calls `$model->getForm($data, false)` — `loadData: false` makes `FormBehaviorTrait::loadForm()`
	 * bind `preprocessForm()` to a hard-coded empty **array** (`$data = [];`) regardless of what `$data` we
	 * pass in. `StyleModel::preprocessForm()` on Joomla 4.4 (only — 5.4/6.x cast `$styleObj = (object) $data`
	 * first) dereferences that array directly as `$data->parent`, which is *always* a PHP "Attempt to read
	 * property on array" warning on that version, regardless of the actual record — and that warning gets
	 * echoed into (and corrupts) the JSON-RPC response body, the same class of bug as
	 * `ModuleMapper::createData()`'s "content" fix. Calling `getForm()` ourselves first, with the warning
	 * `@`-suppressed, populates that form-name-keyed cache so `saveItem()`'s own later call — same model
	 * instance, same form name — hits the cache and never re-runs `preprocessForm()` a second time. Found by
	 * this plan's own E2E suite; see the final report.
	 *
	 * Must be called from inside {@see self::withCurrentUserInSession()} — see that method's docblock.
	 *
	 * @param   array<string, mixed>  $data  Should carry the real `client_id`/`template` for this record
	 *                                       (see `StyleModel::getForm()`), even though — being cache-keyed
	 *                                       only by form name — it makes no difference to *whether* the
	 *                                       warning is suppressed, only to which template's `<config>` the
	 *                                       (correctly cached) form ends up validating against.
	 */
	private function warmFormCache(AdminModel $model, array $data): void
	{
		@$model->getForm($data, false);
	}

	/**
	 * Wraps every call in `$callback` that might trigger `StyleModel::getForm()` — directly, via
	 * {@see self::fillParamsDefaults()}/{@see self::warmFormCache()}, or indirectly, via
	 * `AbstractTool::saveItem()`'s own later call on the same `$model` — with a fix for a Joomla-4.4-only
	 * bug that corrupts every style **create or update** response and, worse, silently drops an intended
	 * `home` change entirely.
	 *
	 * `StyleModel::getForm()` itself calls `$this->canEditState((object) $data)` on **every** invocation
	 * (never memoised — unlike the `loadForm()` call just above it in the same method, which *is* cached per
	 * form name) to decide whether to `disabled="true"`/`filter="unset"` the `home` field.
	 * `AdminModel::canEditState()` on Joomla 4.4 (only — 5.4/6.x already call `$this->getCurrentUser()`)
	 * resolves "the current user" via the classic, session-cookie-based `Factory::getUser()` — the exact bug
	 * `AbstractTool::beginLegacyCurrentUserWorkaround()` documents for `publish()`/`delete()` (a *different*
	 * call site; its `private` visibility means that exact method isn't reusable here, so this is a local,
	 * identical re-implementation) — which in our API request context (no browser session) resolves to the
	 * *guest* user, wrongly reporting no `core.edit.state` permission even for a fully authorised Super
	 * User.
	 *
	 * Because `canEditState()` re-runs on every single `getForm()` call rather than once per cached form, a
	 * fix applied to only *one* of those calls (e.g. only this class's own warm-up call) gets silently undone
	 * the next time anything — including `AbstractTool::saveItem()`'s own internal call — asks the same
	 * `$model` for its form again: `setFieldAttribute()` mutates the cached form's shared `SimpleXMLElement`
	 * in place, so whichever call ran *last* wins. The whole save sequence (warm-up through
	 * `AbstractTool::saveItem()`) must therefore run under one continuous, correctly-populated session, not
	 * a workaround scoped to a single call — found the hard way, by this plan's own E2E suite, after a
	 * narrower "fix the form after the fact" attempt proved to be exactly this kind of transient no-op; see
	 * the final report.
	 *
	 * @template T
	 * @param   callable(): T  $callback
	 * @return  T
	 */
	private function withCurrentUserInSession(callable $callback): mixed
	{
		$session = $this->getApplication()->getSession();

		$hadPrevious = $session->has('user');
		$previous    = $hadPrevious ? $session->get('user') : null;

		$session->set('user', $this->getUser());

		try
		{
			return $callback();
		}
		finally
		{
			if ($hadPrevious)
			{
				$session->set('user', $previous);
			}
			else
			{
				$session->remove('user');
			}
		}
	}

	/**
	 * @return  array<string, mixed>
	 * @throws  ToolException
	 */
	private function loadOwn(int $id): array
	{
		/** @var AdminModel $model */
		$model = $this->createModel(self::OPTION, 'Style');

		$item = $this->loadItem($model, $id, $this->resourceLabel());

		if ((int) ($item['client_id'] ?? -1) !== $this->clientId())
		{
			throw new ToolException(sprintf('Not found: %s #%d', $this->resourceLabel(), $id));
		}

		return $item;
	}

	/**
	 * @throws  ToolException  No such template is registered for this client, or it is disabled.
	 */
	private function requireTemplate(string $template): void
	{
		$db       = $this->getDatabase();
		$query    = $db->createQuery();
		$clientId = $this->clientId();

		$query->select([$db->quoteName('extension_id'), $db->quoteName('enabled')])
			->from($db->quoteName('#__extensions'))
			->where($db->quoteName('type') . ' = ' . $db->quote('template'))
			->where($db->quoteName('element') . ' = :template')
			->where($db->quoteName('client_id') . ' = :client')
			->bind(':template', $template)
			->bind(':client', $clientId, ParameterType::INTEGER);

		$db->setQuery($query);

		$row = $db->loadObject();

		if ($row === null)
		{
			throw new ToolException(sprintf('Not found: template %s', $template));
		}

		if ((int) $row->enabled === 0)
		{
			throw new ToolException(sprintf('The template %s is disabled.', $template));
		}
	}

	/**
	 * @return  array{0: int, 1: string}
	 */
	private function inheritanceFor(string $template): array
	{
		$db       = $this->getDatabase();
		$query    = $db->createQuery();
		$clientId = $this->clientId();

		$query->select([$db->quoteName('inheritable'), $db->quoteName('parent')])
			->from($db->quoteName('#__template_styles'))
			->where($db->quoteName('template') . ' = :template')
			->where($db->quoteName('client_id') . ' = :client')
			->order($db->quoteName('home') . ' DESC, ' . $db->quoteName('id') . ' ASC')
			->bind(':template', $template)
			->bind(':client', $clientId, ParameterType::INTEGER);

		$db->setQuery($query, 0, 1);

		$row = $db->loadObject();

		if ($row === null)
		{
			return [0, ''];
		}

		return [(int) $row->inheritable, (string) $row->parent];
	}

	/**
	 * Site menu items assigned to this style, so a partial update can round-trip them into `$data['assigned']`
	 * — see `.plans/07-modules-templates.md` Part B "Key facts" §3. Administrator styles have no menu
	 * assignment concept at all.
	 *
	 * @return  list<int>
	 */
	private function assignedMenuItems(int $styleId): array
	{
		if ($this->clientId() !== 0)
		{
			return [];
		}

		$db    = $this->getDatabase();
		$query = $db->createQuery();

		$query->select($db->quoteName('id'))
			->from($db->quoteName('#__menu'))
			->where($db->quoteName('template_style_id') . ' = :styleid')
			->where($db->quoteName('client_id') . ' = 0')
			->bind(':styleid', $styleId, ParameterType::INTEGER);

		$db->setQuery($query);

		$ids = $db->loadColumn();

		return array_map('intval', $ids ?: []);
	}
}
