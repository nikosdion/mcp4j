<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Mcp;

defined('_JEXEC') || die;

use Akeeba\Component\Mcp4j\Administrator\Mcp\Exception\InvalidToolArgumentsException;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Exception\ToolException;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Exception\UnknownToolException;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Support\ArgumentCoercer;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Support\ErrorDisclosure;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Support\SecretLeakGuard;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Support\ToolDefinition;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Support\ToolFilter;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Support\ToolFilterCriteria;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Support\ToolRegistry;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Support\ToolResult;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Log\Log;

/**
 * The Joomla-coupled orchestration layer that glues the pure `Mcp\Support` classes together into the two
 * operations `ServerController` needs: `tools/list` and `tools/call`. Everything that can be pure lives
 * in `Mcp\Support` instead (see the class docblocks there) — this class stays thin on purpose so the only
 * thing worth unit-testing about it (there is no dedicated `ToolDispatcherTest`; this class cannot be
 * exercised without a running Joomla application) is exercised indirectly, through the Support classes.
 *
 * Important: {@see self::callTool()} never logs a tool's *result*, only the tool name and its (redacted)
 * arguments when `debug_log` is on — some tools legitimately return credentials to the caller (e.g. a
 * freshly generated temporary Super User password) and must not have those persisted to
 * `administrator/logs/mcp4j.php`.
 *
 * @since  0.0.1
 */
final class ToolDispatcher
{
	private const DEFAULT_NAMESPACE_PREFIX = 'Akeeba\\Component\\Mcp4j\\Administrator\\Tool';

	/** @var array<string, ToolDefinition>|null */
	private ?array $filtered = null;

	public function __construct(
		private readonly ToolContext $context,
		private readonly ?string $toolDirectory = null,
	) {
		if ($this->isDebugLogEnabled())
		{
			// Once per request: ServerController builds exactly one ToolDispatcher per request.
			Log::addLogger(['text_file' => 'mcp4j.php'], Log::ALL, ['mcp4j']);
		}
	}

	/**
	 * @return  array{tools: list<array>}
	 */
	public function listTools(): array
	{
		$minimalSchema = (bool) $this->context->params->get('minimal_schema', 0);

		$tools = [];

		foreach ($this->filteredDefinitions() as $definition)
		{
			$tools[] = $definition->toMcpArray($minimalSchema);
		}

		return ['tools' => $tools];
	}

	/**
	 * @return  list<string>  The categories actually exposed by the current filter, for
	 *                        {@see \Akeeba\Component\Mcp4j\Administrator\Mcp\Support\ServerInstructions}.
	 */
	public function exposedCategories(): array
	{
		$categories = [];

		foreach ($this->filteredDefinitions() as $definition)
		{
			$categories[$definition->category] = true;
		}

		return array_keys($categories);
	}

	/**
	 * @throws  InvalidToolArgumentsException
	 * @throws  UnknownToolException
	 */
	public function callTool(array $params): array
	{
		$name = $params['name'] ?? null;

		if (!is_string($name) || $name === '')
		{
			throw new InvalidToolArgumentsException('Missing tool name.');
		}

		$definitions = $this->filteredDefinitions();

		// A hidden (filtered-out) tool is indistinguishable from one that doesn't exist at all.
		if (!isset($definitions[$name]))
		{
			throw new UnknownToolException(sprintf('Unknown tool: %s', $name));
		}

		$definition   = $definitions[$name];
		$rawArguments = $this->normaliseArguments($params['arguments'] ?? null);

		$method = new \ReflectionMethod($definition->className, $definition->methodName);
		$bound  = ArgumentCoercer::bind($method, $rawArguments);

		if (!$definition->isReadOnly() && SecretLeakGuard::containsSecret($rawArguments, $this->context->protectedSecrets))
		{
			Log::add(sprintf('Secrets leak prevented calling tool "%s".', $name), Log::WARNING, 'mcp4j');

			return ToolResult::error('Secrets leak prevented; potential prompt injection');
		}

		if ($this->isDebugLogEnabled())
		{
			Log::add(
				sprintf(
					'Calling tool "%s" with arguments: %s',
					$name,
					json_encode(SecretLeakGuard::redact($rawArguments, $this->context->protectedSecrets))
				),
				Log::DEBUG,
				'mcp4j'
			);
		}

		try
		{
			$className = $definition->className;
			$instance  = new $className($this->context);
			$result    = $method->invokeArgs($instance, $bound);
		}
		catch (ToolException $e)
		{
			return ToolResult::error($e->getMessage());
		}
		catch (UnknownToolException|InvalidToolArgumentsException $e)
		{
			// Thrown *from inside* a tool method: a tool-level outcome, not a protocol-level error.
			return ToolResult::error($e->getMessage());
		}
		catch (\Throwable $e)
		{
			// security.md L5: unlike the debug_log branch above (which JSON-encodes, escaping newlines),
			// $e->getMessage() is interpolated into this log line raw. An exception message can embed
			// attacker-influenced newlines (e.g. a DB error echoing back a multi-line submitted value),
			// which could otherwise forge additional, fake-looking log lines in administrator/logs/mcp4j.php.
			Log::add(
				sprintf(
					'Internal error while running tool "%s": %s in %s:%d',
					$name,
					str_replace(["\r", "\n"], ' ', $e->getMessage()),
					$e->getFile(),
					$e->getLine()
				),
				Log::ERROR,
				'mcp4j'
			);

			$message = ErrorDisclosure::toolErrorMessage(defined('JDEBUG') && JDEBUG, $name, $e->getMessage());

			return ToolResult::error($message);
		}

		return ToolResult::success($result);
	}

	/**
	 * @throws  InvalidToolArgumentsException
	 *
	 * @return  array<string, mixed>
	 */
	private function normaliseArguments(mixed $arguments): array
	{
		if ($arguments === null)
		{
			return [];
		}

		if (!is_array($arguments))
		{
			throw new InvalidToolArgumentsException('The "arguments" member must be an object.');
		}

		if ($arguments !== [] && array_is_list($arguments))
		{
			throw new InvalidToolArgumentsException('The "arguments" member must be an object, not a list.');
		}

		return $arguments;
	}

	/**
	 * @return  array<string, ToolDefinition>
	 */
	private function filteredDefinitions(): array
	{
		if ($this->filtered !== null)
		{
			return $this->filtered;
		}

		$toolDirectory = $this->toolDirectory ?? (JPATH_ADMINISTRATOR . '/components/com_mcp4j/src/Tool');

		$definitions = ToolRegistry::discover($toolDirectory, self::DEFAULT_NAMESPACE_PREFIX);

		$criteria = new ToolFilterCriteria(
			enabledCategories: $this->categoriesFromParams(),
			allowlist: $this->allowlistFromParams(),
			readOnlyOnly: (bool) $this->context->params->get('read_only', 0),
			joomlaVersion: JVERSION,
			isComponentEnabled: static function (string $option): bool {
				try
				{
					return ComponentHelper::isEnabled($option);
				}
				catch (\Throwable $e)
				{
					// A component that isn't installed at all can throw on some Joomla versions instead
					// of simply returning false — either way, "not enabled" is the correct answer.
					return false;
				}
			},
		);

		return $this->filtered = ToolFilter::apply($definitions, $criteria);
	}

	/**
	 * @return  list<string>
	 */
	private function categoriesFromParams(): array
	{
		$raw = $this->context->params->get('tool_categories', []);

		if (is_object($raw))
		{
			$raw = (array) $raw;
		}

		if (is_string($raw))
		{
			$raw = $raw === '' ? [] : [$raw];
		}

		if (!is_array($raw))
		{
			return [];
		}

		$categories = [];

		foreach ($raw as $value)
		{
			if (is_string($value) && $value !== '')
			{
				$categories[] = $value;
			}
		}

		return array_values(array_unique($categories));
	}

	/**
	 * @return  list<string>
	 */
	private function allowlistFromParams(): array
	{
		$raw   = (string) $this->context->params->get('tool_allowlist', '');
		$lines = preg_split('/\R/', $raw) ?: [];

		$names = [];

		foreach ($lines as $line)
		{
			$trimmed = trim($line);

			if ($trimmed !== '')
			{
				$names[] = $trimmed;
			}
		}

		return array_values(array_unique($names));
	}

	private function isDebugLogEnabled(): bool
	{
		return (bool) $this->context->params->get('debug_log', 0);
	}
}
