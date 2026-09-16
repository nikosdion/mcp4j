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
 * `tools/list`/`tools/call`, applying criteria in a fixed order:
 *
 * 1. Requirements ({@see ToolDefinition::$requiredComponents}, `#[ToolRequirements]` Joomla version
 *    bounds) are always enforced, regardless of the other criteria.
 * 2. The category filter and the tool filter are independent, AND-combined predicates — see
 *    {@see ToolFilterCriteria} for the include/exclude semantics of each. Both apply; neither overrides
 *    the other.
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
		$definitions = self::applyCategory($definitions, $criteria);
		$definitions = self::applyTool($definitions, $criteria);
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
	private static function applyCategory(array $definitions, ToolFilterCriteria $criteria): array
	{
		if ($criteria->categories === [])
		{
			return $definitions;
		}

		$listed  = array_map('strtolower', $criteria->categories);
		$include = $criteria->categoryMode === ToolFilterCriteria::MODE_INCLUDE;

		return array_filter(
			$definitions,
			static function (ToolDefinition $d) use ($listed, $include): bool {
				$isListed = in_array(strtolower($d->category), $listed, true);

				return $isListed === $include;
			}
		);
	}

	/**
	 * @param   array<string, ToolDefinition>  $definitions
	 *
	 * @return  array<string, ToolDefinition>
	 */
	private static function applyTool(array $definitions, ToolFilterCriteria $criteria): array
	{
		if ($criteria->tools === [])
		{
			return $definitions;
		}

		$listed  = array_flip($criteria->tools);
		$include = $criteria->toolMode === ToolFilterCriteria::MODE_INCLUDE;

		return array_filter(
			$definitions,
			static function (ToolDefinition $d) use ($listed, $include): bool {
				$isListed = isset($listed[$d->name]);

				return $isListed === $include;
			}
		);
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
