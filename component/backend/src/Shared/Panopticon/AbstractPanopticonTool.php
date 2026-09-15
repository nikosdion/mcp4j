<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Shared\Panopticon;

defined('_JEXEC') || die;

use Akeeba\Component\Mcp4j\Administrator\Bridge\AbstractTool;
use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\Database\ParameterType;
use Joomla\Registry\Registry;

/**
 * Shared base for every Panopticon Connector tool class (plans 14 and 15) — helpers for calling the
 * connector's Api-namespace models (`Akeeba\Component\Panopticon\Api\Model\*`) and com_installer's models
 * without HTTP, plus the MCP-session-backed state a few multi-step core-update tools need. See
 * `.plans/14-panopticon-core.md` for the full rationale.
 *
 * @since  0.0.1
 */
abstract class AbstractPanopticonTool extends AbstractTool
{
	/**
	 * The Panopticon Connector is designed to be driven by a Super User API token — its own CLAUDE.md
	 * notes "Controllers handle authorization via Super User checks" — but several of its Api-namespace
	 * controller tasks (extensions list, updates refresh, template overrides, ...) check nothing at all,
	 * relying instead on `core.login.api` being Super-User-only by default. MCP4j tokens can be issued to
	 * lower-privileged users, so every Panopticon tool makes the Super User requirement explicit here
	 * rather than inheriting it implicitly from the site's default ACL configuration.
	 *
	 * @throws  \Akeeba\Component\Mcp4j\Administrator\Mcp\Exception\ToolException
	 */
	protected function requireSuperUser(): void
	{
		$this->requireAuthorised('core.admin', 'root.1');
	}

	/**
	 * Creates one of the connector's own Api-namespace models (`Akeeba\Component\Panopticon\Api\Model\*`),
	 * the way its own `ApiController`s would. Joomla resolves `createModel($name, 'Api', ...)` against
	 * `Akeeba\Component\Panopticon\Api\` (booting `com_panopticon`'s service provider along the way, which
	 * also registers its own `vendor/autoload.php`).
	 */
	protected function createPanopticonModel(string $name): BaseDatabaseModel
	{
		$this->loadLanguage('com_panopticon');
		$this->loadLanguage('com_installer');
		$this->loadLanguage('com_joomlaupdate');

		return $this->createModel('com_panopticon', $name, 'Api');
	}

	protected function createInstallerModel(string $name): BaseDatabaseModel
	{
		return $this->createModel('com_installer', $name);
	}

	/**
	 * Works around the same session-registry decay `Tool/Config/Config.php::ensureSessionRegistryIsObject()`
	 * documents (see that class — `component/backend/src/Support/` itself is out of scope for this plan to
	 * change, per `.plans/00-conventions.md` §8): once an MCP session has been persisted and restored at
	 * least once, `CMSApplication::getUserState()`'s `registry` session entry has decayed from a `Registry`
	 * object to a plain array (the session bridge's `SessionDataCodec` is deliberately JSON-only), and the
	 * next `getUserState()`/`getUserStateFromRequest()` call anywhere in Joomla core is a PHP fatal ("Call
	 * to a member function get() on array").
	 *
	 * Every model this plan creates via {@see self::createPanopticonModel()}/{@see self::createInstallerModel()}
	 * passes `ignore_request => true`, which skips `populateState()` (and therefore `getUserState()`)
	 * entirely — so this normally never matters here. The one exception is the connector's own
	 * `ExtensionModel::getItem()`, which internally instantiates a **fresh** `ExtensionsModel` **without**
	 * `ignore_request` (see `.plans/14-panopticon-core.md` "Gotchas" — this is deliberate connector
	 * behaviour we must not "fix"), so its `populateState()` genuinely runs and can hit this. Call this
	 * immediately before any such call.
	 */
	protected function ensureSessionRegistryIsObject(): void
	{
		$session  = $this->getApplication()->getSession();
		$registry = $session->get('registry');

		if (is_array($registry))
		{
			$session->set('registry', new Registry($registry));
		}
	}

	/**
	 * Drains and normalises the application's message queue — several connector controller methods being
	 * ported here communicate outcomes (including errors) via `enqueueMessage()`/`getMessageQueue()`
	 * rather than exceptions or return values.
	 *
	 * @return  list<array{type: string, message: string}>
	 */
	protected function takeMessages(): array
	{
		$queue    = $this->getApplication()->getMessageQueue(true);
		$messages = [];

		foreach ((array) $queue as $entry)
		{
			$entry = (array) $entry;

			$messages[] = [
				'type'    => (string) ($entry['type'] ?? 'message'),
				'message' => (string) ($entry['message'] ?? ''),
			];
		}

		return $messages;
	}

	/**
	 * Reads MCP-session-scoped state under the `mcp4j.panopticon.` prefix — used by the resumable
	 * core-update download/checksum tools to keep state between tool calls without echoing it back through
	 * the LLM (see the plan's deviations list). Persisted by
	 * {@see \Akeeba\Component\Mcp4j\Administrator\Helper\SessionManager}; values must be JSON-safe
	 * scalars/arrays.
	 */
	protected function sessionGet(string $key, mixed $default = null): mixed
	{
		return $this->getApplication()->getSession()->get('mcp4j.panopticon.' . $key, $default);
	}

	/**
	 * @param   mixed  $value  A JSON-safe scalar/array, or `null` to remove the key entirely.
	 */
	protected function sessionSet(string $key, mixed $value): void
	{
		$session   = $this->getApplication()->getSession();
		$sessionKey = 'mcp4j.panopticon.' . $key;

		if ($value === null)
		{
			$session->remove($sessionKey);

			return;
		}

		$session->set($sessionKey, $value);
	}

	/**
	 * Persists `$params` into `#__extensions.params` for the given component, then clears the `_system`
	 * cache group — the same effect as saving that component's configuration form, without going through
	 * `com_config` (needed here because `panopticon_coreupdate_changesource` must be able to switch the
	 * update source back to `default`, which the connector's own `changeUpdateSource()` refuses to do).
	 *
	 * `$params` must be the `Registry` instance returned by `ComponentHelper::getParams($option)` (the
	 * cached one), already mutated by the caller — mutating that same instance means later reads in this
	 * request see the change immediately, without waiting for the database round-trip below.
	 */
	protected function componentParamsSave(string $option, Registry $params): void
	{
		$db           = $this->getDatabase();
		$paramsString = $params->toString('JSON');

		$query = $db->getQuery(true)
			->update($db->quoteName('#__extensions'))
			->set($db->quoteName('params') . ' = :params')
			->where(
				[
					$db->quoteName('element') . ' = :element',
					$db->quoteName('type') . ' = ' . $db->quote('component'),
				]
			)
			->bind(':params', $paramsString, ParameterType::STRING)
			->bind(':element', $option, ParameterType::STRING);

		$db->setQuery($query)->execute();

		try
		{
			Factory::getContainer()->get(CacheControllerFactoryInterface::class)
				->createCacheController('callback', ['defaultgroup' => '_system'])
				->clean();
		}
		catch (\Throwable $e)
		{
			// Cache cleaning is best-effort; a stale _system cache is not worth failing the tool call over.
		}
	}
}
