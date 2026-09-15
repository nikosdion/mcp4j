<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Mcp\Support;

defined('_JEXEC') || die;

/**
 * Narrows a set of discovered tool definitions down to the ones that should actually be exposed by
 * `tools/list`/`tools/call`, applying criteria in a fixed precedence order that mirrors the original
 * `joomla-mcp-php` project's `--categories`/`--tools`/`--non-destructive` command-line options:
 *
 * 1. Requirements ({@see ToolDefinition::$requiredComponents}, `#[ToolRequirements]` Joomla version
 *    bounds) are always enforced, regardless of the other criteria.
 * 2. If an allow-list of exact tool names is given, it wins outright — categories are ignored entirely.
 *    Otherwise, if a set of categories is given, only tools in those categories survive.
 * 3. Read-only filtering, if requested, is applied last, against whatever survived steps 1-2.
 *
 * Pure: no Joomla, only what {@see ToolFilterCriteria} was given.
 *
 * @since  0.0.1
 */
final class ToolFilter
{
	/**
	 * @param   array<string, ToolDefinition>  $definitions
	 *
	 * @return  array<string, ToolDefinition>
	 */
	public static function apply(array $definitions, ToolFilterCriteria $criteria): array
	{
		$definitions = self::applyRequirements($definitions, $criteria);
		$definitions = self::applyNameOrCategory($definitions, $criteria);
		$definitions = self::applyReadOnly($definitions, $criteria);

		return $definitions;
	}

	/**
	 * @param   array<string, ToolDefinition>  $definitions
	 *
	 * @return  array<string, ToolDefinition>
	 */
	private static function applyRequirements(array $definitions, ToolFilterCriteria $criteria): array
	{
		return array_filter($definitions, function (ToolDefinition $definition) use ($criteria): bool {
			foreach ($definition->requiredComponents as $component)
			{
				if (!($criteria->isComponentEnabled)($component))
				{
					return false;
				}
			}

			if ($definition->minJoomla !== null && version_compare($criteria->joomlaVersion, $definition->minJoomla, '<'))
			{
				return false;
			}

			if ($definition->maxJoomla !== null && version_compare($criteria->joomlaVersion, $definition->maxJoomla, '>='))
			{
				return false;
			}

			return true;
		});
	}

	/**
	 * @param   array<string, ToolDefinition>  $definitions
	 *
	 * @return  array<string, ToolDefinition>
	 */
	private static function applyNameOrCategory(array $definitions, ToolFilterCriteria $criteria): array
	{
		if ($criteria->allowlist !== [])
		{
			$allowed = array_flip($criteria->allowlist);

			return array_filter($definitions, static fn (ToolDefinition $d): bool => isset($allowed[$d->name]));
		}

		if ($criteria->enabledCategories !== [])
		{
			$allowedCategories = array_map('strtolower', $criteria->enabledCategories);

			return array_filter(
				$definitions,
				static fn (ToolDefinition $d): bool => in_array(strtolower($d->category), $allowedCategories, true)
			);
		}

		return $definitions;
	}

	/**
	 * @param   array<string, ToolDefinition>  $definitions
	 *
	 * @return  array<string, ToolDefinition>
	 */
	private static function applyReadOnly(array $definitions, ToolFilterCriteria $criteria): array
	{
		if (!$criteria->readOnlyOnly)
		{
			return $definitions;
		}

		return array_filter($definitions, static fn (ToolDefinition $d): bool => $d->isReadOnly());
	}
}
