<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Mcp\Support;

defined('_JEXEC') || die;

/**
 * The inputs {@see ToolFilter::apply()} needs, gathered from the `com_mcp4j` component options and the
 * current request — see the `tools` fieldset in `component/backend/config.xml`.
 *
 * Category and tool filtering are two independent, AND-combined predicates, each switchable between
 * "include only the listed ones" and "exclude the listed ones" (default: exclude, nothing listed — i.e.
 * everything is exposed until an operator opts into narrowing the surface). This mirrors the original
 * `joomla-mcp-php` project's `--categories`/`--tools`/`--non-destructive` command-line options in spirit,
 * but not in precedence: unlike that project (and unlike this class's own predecessor), the tool filter
 * does not override or ignore the category filter — both apply together.
 *
 * Pure value object: no Joomla types except via the caller-supplied closure.
 *
 * @since  0.0.1
 */
final class ToolFilterCriteria
{
	public const MODE_INCLUDE = 'include';

	public const MODE_EXCLUDE = 'exclude';

	/**
	 * @param   string        $categoryMode       {@see self::MODE_INCLUDE} or {@see self::MODE_EXCLUDE}.
	 *                                             Meaningless when $categories is empty.
	 * @param   list<string>  $categories         Category names the mode applies to (case-insensitive).
	 *                                             Empty = no category filtering at all, regardless of mode.
	 * @param   string        $toolMode           {@see self::MODE_INCLUDE} or {@see self::MODE_EXCLUDE}.
	 *                                             Meaningless when $tools is empty.
	 * @param   list<string>  $tools              Exact tool names the mode applies to. Empty = no tool
	 *                                             filtering at all, regardless of mode. Applied independently
	 *                                             of, and in addition to, the category filter.
	 * @param   bool          $readOnlyOnly       Keep only tools with `readOnlyHint === true`. Applied
	 *                                             after every other criterion.
	 * @param   string        $joomlaVersion      The running `JVERSION`, compared against each tool's
	 *                                             `#[ToolRequirements]` bounds.
	 * @param   \Closure      $isComponentEnabled `fn(string $option): bool` — whether a Joomla component
	 *                                             is installed and enabled.
	 */
	public function __construct(
		public readonly string $categoryMode,
		public readonly array $categories,
		public readonly string $toolMode,
		public readonly array $tools,
		public readonly bool $readOnlyOnly,
		public readonly string $joomlaVersion,
		public readonly \Closure $isComponentEnabled,
	) {
	}
}
