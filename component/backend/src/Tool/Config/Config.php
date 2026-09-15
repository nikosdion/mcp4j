<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Tool\Config;

defined('_JEXEC') || die;

use Akeeba\Component\Mcp4j\Administrator\Bridge\AbstractTool;
use Akeeba\Component\Mcp4j\Administrator\Bridge\Support\DataMerger;
use Akeeba\Component\Mcp4j\Administrator\Bridge\Support\ItemNormalizer;
use Akeeba\Component\Mcp4j\Administrator\Mapper\Config\ConfigAcl;
use Akeeba\Component\Mcp4j\Administrator\Mapper\Config\ConfigDataMapper;
use Akeeba\Component\Mcp4j\Administrator\Mapper\Config\SecretRedactor;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\McpTool;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\Schema;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Exception\ToolException;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Extension\ExtensionHelper;

/**
 * MCP tools for Joomla! site and component configuration management.
 *
 * Ported from the original `joomla-mcp-php` project's `src/Server/Config/Config.php` — method names, tool
 * names/descriptions, parameters and annotations are copied verbatim from there (see
 * `.plans/00-conventions.md` §2); only the method *bodies* differ.
 *
 * Site configuration (`configuration.php`) and component params routinely hold live secrets — database and
 * SMTP passwords, third-party API keys, licence/download IDs, ... — so **every** value this class returns
 * is passed through {@see SecretRedactor} first, and every value an MCP client sends back is passed
 * through {@see SecretRedactor::stripPlaceholders()} so that a round-tripped placeholder never overwrites
 * a live secret with the literal {@see SecretRedactor::PLACEHOLDER} string. See
 * `.plans/10-config-plugins-installer-languages.md`
 * for the full rationale and the final report for the list of deviations from the original.
 *
 * @since  0.0.1
 */
class Config extends AbstractTool
{
	#[McpTool(
		name: 'config_application_read',
		description: 'Read the Joomla application configuration',
		readOnlyHint: true,
	)]
	public function readApplicationConfig(): array
	{
		$this->requireAuthorised('core.admin', 'root.1');

		/** @var \Joomla\Component\Config\Administrator\Model\ApplicationModel $model */
		$model = $this->createModel('com_config', 'Application');

		$this->ensureSessionRegistryIsObject();

		$data = ItemNormalizer::toArray($model->getData());

		return SecretRedactor::redactKeys($data, SecretRedactor::APPLICATION_SECRET_KEYS);
	}

	#[McpTool(
		name: 'config_application_update',
		description: 'Update the Joomla application configuration',
		idempotentHint: true,
	)]
	public function updateApplicationConfig(
		#[Schema(description: 'Configuration key-value pairs as a JSON string or object')]
		array|string $configData
	): array
	{
		$this->requireAuthorised('core.admin', 'root.1');

		$changes = $this->stripAndNormalise($configData);

		// The `secret` protected configuration key signs the local part of every issued Joomla API token —
		// changing it invalidates every token site-wide, including the one authenticating this very
		// request. See `.plans/10-config-plugins-installer-languages.md`'s deviations list.
		if (array_key_exists('secret', $changes))
		{
			throw new ToolException('Changing the site secret is not allowed through MCP: it invalidates every API token, including the one in use.');
		}

		/** @var \Joomla\Component\Config\Administrator\Model\ApplicationModel $model */
		$model = $this->createModel('com_config', 'Application');

		$this->ensureSessionRegistryIsObject();

		// ApplicationModel::getData() merges in `com_config.config.global.data` user state, which the MCP
		// session bridge persists across requests — never write to that key ourselves.
		$currentData = ItemNormalizer::toArray($model->getData());
		$data        = DataMerger::merge($currentData, $changes);

		$form = $model->getForm([], false);

		if (!$form)
		{
			throw new ToolException(sprintf('Cannot load the application configuration form: %s', $this->modelError($model, 'Unknown error')));
		}

		$valid = $model->validate($form, $data);

		if ($valid === false)
		{
			throw new ToolException(sprintf('Validation failed for application configuration: %s', $this->formErrors($model)));
		}

		// Joomla 4.4's ApplicationModel::save() unconditionally reads $data['cors'], $data['cors_allow_origin'],
		// $data['cors_allow_headers'] and $data['cors_allow_methods'] with no isset()/array_key_exists() guard
		// (a real, full HTML form submission always posts every one of the "Web Services" tab's fields, even
		// an unchecked checkbox, via a hidden fallback input — something this tool's partial-update payload
		// never does). On a fresh install whose configuration.php has never had that tab saved, `$valid` (and
		// even $currentData, since \JConfig simply never declared those properties) can lack all four keys,
		// turning into PHP 8.1+ "Undefined array key" warnings that get echoed into (and corrupt) the JSON-RPC
		// response body. Joomla 5.4/6.x's ApplicationModel::save() has the same unconditional reads, but their
		// installer always writes all four keys into configuration.php, so this never manifests there. Found
		// by this plan's own E2E suite on the Joomla 4.4 matrix entry — filled in here (only when actually
		// absent — an existing value, however it got there, is never overwritten) with the same defaults
		// `forms/application.xml` itself declares, rather than by branching on JVERSION. See the final
		// report's deviations list.
		$valid += [
			'cors'                => 0,
			'cors_allow_origin'   => '*',
			'cors_allow_headers'  => 'Content-Type,X-Joomla-Token',
			'cors_allow_methods'  => '',
		];

		// ApplicationModel::save() reloads any protected configuration field ABSENT from the array it is
		// given straight from the live configuration (see `.plans/00-conventions.md`'s reading list) — so
		// every protected key the caller did NOT explicitly change must be removed here, otherwise `$valid`
		// (which now holds this request's already-redacted-then-merged view of that key) would overwrite
		// the live value with stale/placeholder-derived data instead of being reloaded fresh.
		foreach (SecretRedactor::APPLICATION_SECRET_KEYS as $secretKey)
		{
			if (!array_key_exists($secretKey, $changes))
			{
				unset($valid[$secretKey]);
			}
		}

		if (!$model->save($valid))
		{
			throw new ToolException(sprintf('Save failed for application configuration: %s', $this->applicationMessages()));
		}

		// Do not re-read $model->getData() here: the JConfig class backing it is already loaded in this
		// request with the pre-save values, so a fresh read would show stale data, not what was just saved.
		return SecretRedactor::redactKeys(DataMerger::merge($data, $valid), SecretRedactor::APPLICATION_SECRET_KEYS);
	}

	#[McpTool(
		name: 'config_component_read',
		description: 'Read the configuration of a Joomla component',
		readOnlyHint: true,
	)]
	public function readComponentConfig(
		#[Schema(description: 'The component name, e.g. "com_content"')]
		string $componentName
	): array
	{
		$this->requireValidInstalledComponent($componentName);
		$this->requireComponentConfigAuthorised($componentName);

		return SecretRedactor::redactByPattern(ComponentHelper::getParams($componentName)->toArray());
	}

	#[McpTool(
		name: 'config_component_update',
		description: 'Update the configuration of a Joomla component',
		idempotentHint: true,
	)]
	public function updateComponentConfig(
		#[Schema(description: 'The component name, e.g. "com_content"')]
		string $componentName,
		#[Schema(description: 'Configuration key-value pairs as a JSON string or object')]
		array|string $configData
	): array
	{
		$this->requireValidInstalledComponent($componentName);
		$this->requireComponentConfigAuthorised($componentName);

		$changes = $this->stripAndNormalise($configData);

		/** @var \Joomla\Component\Config\Administrator\Model\ComponentModel $model */
		$model = $this->createModel('com_config', 'Component');
		$model->setState('component.option', $componentName);

		// createModel() only loads language files for 'com_config' (the $option it was called with) — the
		// component actually being configured needs its own language loaded too, for form field labels
		// used in validation error messages.
		$this->loadLanguage($componentName);

		$currentParams = ComponentHelper::getParams($componentName)->toArray();
		$data          = DataMerger::merge($currentParams, $changes);

		$form = $model->getForm([], false);

		if (!$form)
		{
			throw new ToolException(sprintf('Cannot load the %s configuration form: %s', $componentName, $this->modelError($model, 'Unknown error')));
		}

		$valid = $model->validate($form, $data);

		if ($valid === false)
		{
			throw new ToolException(sprintf('Validation failed for %s configuration: %s', $componentName, $this->formErrors($model)));
		}

		$record = ExtensionHelper::getExtensionRecord($componentName, 'component');

		if ($record === null)
		{
			throw new ToolException(sprintf('Not found: component configuration %s', $componentName));
		}

		try
		{
			$saved = $model->save([
				'params' => $valid,
				'id'     => $record->extension_id,
				'option' => $componentName,
			]);
		}
		catch (\RuntimeException $e)
		{
			// ComponentModel::save() throws (rather than returning false) for ACL/rules problems, e.g. a
			// UserGroupList params field trying to grant core.admin without being a Super User.
			throw new ToolException($e->getMessage());
		}

		if (!$saved)
		{
			throw new ToolException(sprintf('Save failed for %s configuration: %s', $componentName, $this->modelError($model, 'Unknown error')));
		}

		return SecretRedactor::redactByPattern($valid);
	}

	/**
	 * @throws  ToolException  `$configData` is not valid JSON, or does not decode to a JSON object.
	 */
	private function stripAndNormalise(array|string $configData): array
	{
		try
		{
			return SecretRedactor::stripPlaceholders(ConfigDataMapper::normaliseConfigData($configData));
		}
		catch (\InvalidArgumentException $e)
		{
			throw new ToolException($e->getMessage());
		}
	}

	/**
	 * `ApplicationModel::getData()` unconditionally calls `Factory::getApplication()->getUserState(...)`
	 * (`libraries/src/Application/CMSApplication.php::getUserState()`), which reads a `Registry` object out
	 * of the session under the `registry` key (populated by Joomla's own session bootstrap on the very
	 * first request of a session) and immediately calls `->get()` on it.
	 *
	 * This MCP session's data is persisted across requests via
	 * {@see \Akeeba\Component\Mcp4j\Administrator\Support\SessionDataCodec}, which is deliberately JSON-only
	 * (see that class's own docblock: PHP `unserialize()` of data keyed by a client-supplied
	 * `Mcp-Session-Id` would be an object-injection gadget) — so once a session has been persisted and
	 * restored at least once, its `registry` entry has decayed from a `Registry` object to a plain array,
	 * and the very next `getUserState()` call anywhere in Joomla core is a PHP fatal ("Call to a member
	 * function get() on array"). This is a deliberate, documented trade-off of the session bridge (owned by
	 * plan 01/03, `component/backend/src/Support/` is out of scope for this plan to change — see
	 * `.plans/00-conventions.md` §8), so it is worked around here, narrowly and only where this class
	 * actually calls `ApplicationModel::getData()`, rather than by touching the session bridge itself: if
	 * the session's `registry` entry has decayed to a plain array, re-wrap it in a `Registry` first. See the
	 * final report for plan 10 for the full writeup and a recommendation for the framework owner.
	 */
	private function ensureSessionRegistryIsObject(): void
	{
		$session  = $this->getApplication()->getSession();
		$registry = $session->get('registry');

		if (is_array($registry))
		{
			$session->set('registry', new \Joomla\Registry\Registry($registry));
		}
	}

	/**
	 * @throws  ToolException  `$option` isn't a plausible component name, isn't installed, or has no
	 *                         configuration form at all.
	 */
	private function requireValidInstalledComponent(string $option): void
	{
		if (!ConfigAcl::isValidComponentName($option))
		{
			throw new ToolException(sprintf('Invalid component name: %s', $option));
		}

		if (!ComponentHelper::isInstalled($option) || !is_file(JPATH_ADMINISTRATOR . '/components/' . $option . '/config.xml'))
		{
			throw new ToolException(sprintf('Not found: component configuration %s', $option));
		}
	}

	/**
	 * Mirrors `ConfigHelper::canChangeComponentConfig()` via {@see ConfigAcl::componentConfigRequirement()}:
	 * authorised if ANY one of the returned `[action, asset]` pairs is granted.
	 *
	 * @throws  ToolException  None of the pairs are authorised for the current user.
	 */
	private function requireComponentConfigAuthorised(string $option): void
	{
		$pairs = ConfigAcl::componentConfigRequirement($option);

		foreach ($pairs as [$action, $asset])
		{
			if ($this->isAuthorised($action, $asset))
			{
				return;
			}
		}

		[$action, $asset] = $pairs[0];

		throw new ToolException(sprintf('Permission denied: %s on %s', $action, $asset));
	}

	private function formErrors(object $model): string
	{
		$messages = [];

		foreach (array_slice($model->getErrors(), 0, 3) as $error)
		{
			$messages[] = $error instanceof \Throwable ? $error->getMessage() : (string) $error;
		}

		return $messages !== [] ? implode('; ', $messages) : $this->modelError($model, 'Unknown validation error');
	}

	/**
	 * `ApplicationModel::save()` reports failures via `enqueueMessage()`, not `setError()` — drain the
	 * application message queue for `error`/`warning` entries rather than asking the model for an error.
	 */
	private function applicationMessages(): string
	{
		$messages = [];

		foreach ($this->getApplication()->getMessageQueue(true) as $message)
		{
			if (!is_array($message))
			{
				continue;
			}

			if (!in_array($message['type'] ?? '', ['error', 'warning'], true))
			{
				continue;
			}

			$text = (string) ($message['message'] ?? '');

			if ($text !== '')
			{
				$messages[] = $text;
			}
		}

		return $messages !== [] ? implode('; ', $messages) : 'Unknown error';
	}
}
