<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Mcp\Support;

defined('_JEXEC') || die;

use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\McpTool;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\ToolRequirements;

/**
 * Discovers MCP tool classes by scanning a `Tool/<Category>/<Class>.php` directory tree, exactly one
 * level of category folders deep (not recursive — see `.plans/00-conventions.md` §3), and reflects every
 * `#[McpTool]`-annotated public instance method into a {@see ToolDefinition}.
 *
 * Pure in the sense that matters for unit testing: it touches the filesystem (glob/require) and PHP
 * reflection only, never Joomla. `class_exists()` is allowed to trigger the Composer autoloader, which
 * is how tool classes normally get found; `require_once` is a fallback for directories that are not
 * (yet) covered by a registered PSR-4 prefix, which is the case for the unit test fixtures.
 *
 * @since  0.0.1
 */
final class ToolRegistry
{
	private const TOOL_NAME_PATTERN = '/^[a-z0-9_]{1,64}$/';

	/**
	 * @return  array<string, ToolDefinition>  Keyed by tool name, sorted by category then name.
	 * @throws  \LogicException  Duplicate tool name, or a tool name that doesn't match
	 *                           {@see self::TOOL_NAME_PATTERN}.
	 */
	public static function discover(string $toolDirectory, string $namespacePrefix): array
	{
		$definitions = [];

		if (!is_dir($toolDirectory))
		{
			return $definitions;
		}

		$namespacePrefix = rtrim($namespacePrefix, '\\');
		$categoryDirs    = glob($toolDirectory . '/*', GLOB_ONLYDIR) ?: [];

		sort($categoryDirs);

		foreach ($categoryDirs as $categoryDir)
		{
			$category = basename($categoryDir);
			$files    = glob($categoryDir . '/*.php') ?: [];

			sort($files);

			foreach ($files as $file)
			{
				$className = $namespacePrefix . '\\' . $category . '\\' . basename($file, '.php');

				if (!class_exists($className))
				{
					require_once $file;
				}

				if (!class_exists($className, false))
				{
					continue;
				}

				self::discoverClass($className, $category, $definitions);
			}
		}

		uasort(
			$definitions,
			static fn (ToolDefinition $a, ToolDefinition $b): int => [$a->category, $a->name] <=> [$b->category, $b->name]
		);

		return $definitions;
	}

	/**
	 * @param   array<string, ToolDefinition>  $definitions  Accumulator, added to by reference.
	 *
	 * @throws  \LogicException
	 */
	private static function discoverClass(string $className, string $category, array &$definitions): void
	{
		$reflectionClass = new \ReflectionClass($className);

		if ($reflectionClass->isAbstract() || $reflectionClass->isInterface() || $reflectionClass->isTrait())
		{
			return;
		}

		$classRequirements = self::requirementsAttribute($reflectionClass);

		foreach ($reflectionClass->getMethods(\ReflectionMethod::IS_PUBLIC) as $method)
		{
			if ($method->isStatic())
			{
				continue;
			}

			$toolAttributes = $method->getAttributes(McpTool::class);

			if ($toolAttributes === [])
			{
				continue;
			}

			/** @var McpTool $toolAttribute */
			$toolAttribute = $toolAttributes[0]->newInstance();

			if (preg_match(self::TOOL_NAME_PATTERN, $toolAttribute->name) !== 1)
			{
				throw new \LogicException(sprintf(
					'Invalid MCP tool name "%s" declared on %s::%s() — must match %s.',
					$toolAttribute->name,
					$className,
					$method->getName(),
					self::TOOL_NAME_PATTERN
				));
			}

			if (isset($definitions[$toolAttribute->name]))
			{
				$existing = $definitions[$toolAttribute->name];

				throw new \LogicException(sprintf(
					'Duplicate MCP tool name "%s" declared on both %s::%s() and %s::%s().',
					$toolAttribute->name,
					$existing->className,
					$existing->methodName,
					$className,
					$method->getName()
				));
			}

			$methodRequirements = self::requirementsAttribute($method);
			$requirements        = self::mergeRequirements($classRequirements, $methodRequirements);

			$annotations = array_filter(
				[
					'readOnlyHint'    => $toolAttribute->readOnlyHint,
					'destructiveHint' => $toolAttribute->destructiveHint,
					'idempotentHint'  => $toolAttribute->idempotentHint,
					'openWorldHint'   => $toolAttribute->openWorldHint,
				],
				static fn (?bool $value): bool => $value !== null
			);

			$definitions[$toolAttribute->name] = new ToolDefinition(
				name: $toolAttribute->name,
				description: $toolAttribute->description,
				category: $category,
				className: $className,
				methodName: $method->getName(),
				inputSchema: SchemaGenerator::forMethod($method),
				annotations: $annotations,
				requiredComponents: $requirements['components'],
				minJoomla: $requirements['minJoomla'],
				maxJoomla: $requirements['maxJoomla'],
			);
		}
	}

	private static function requirementsAttribute(\ReflectionClass|\ReflectionMethod $reflector): ?ToolRequirements
	{
		$attributes = $reflector->getAttributes(ToolRequirements::class);

		if ($attributes === [])
		{
			return null;
		}

		return $attributes[0]->newInstance();
	}

	/**
	 * Merges class-level and method-level requirements. Components are unioned (deduplicated). Version
	 * bounds are combined by taking whichever bound is *more restrictive* — the higher of the two minima,
	 * the lower of the two maxima — so that stacking a class-level and a method-level requirement always
	 * narrows (never widens) the range in which the tool is exposed.
	 *
	 * @return  array{components: list<string>, minJoomla: ?string, maxJoomla: ?string}
	 */
	private static function mergeRequirements(?ToolRequirements $class, ?ToolRequirements $method): array
	{
		$components = array_values(array_unique(array_merge(
			$class?->components ?? [],
			$method?->components ?? []
		)));

		return [
			'components' => $components,
			'minJoomla'  => self::mostRestrictive($class?->minJoomla, $method?->minJoomla, '>='),
			'maxJoomla'  => self::mostRestrictive($class?->maxJoomla, $method?->maxJoomla, '<='),
		];
	}

	private static function mostRestrictive(?string $a, ?string $b, string $keepIfOperatorTrue): ?string
	{
		if ($a === null)
		{
			return $b;
		}

		if ($b === null)
		{
			return $a;
		}

		return version_compare($a, $b, $keepIfOperatorTrue) ? $a : $b;
	}
}
