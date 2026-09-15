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
 * current request — see the `tools` fieldset in `component/backend/config.xml`. This mirrors the
 * `--categories`, `--tools` and `--non-destructive` command-line options of the original
 * `joomla-mcp-php` project (see `.plans/01-tool-framework.md`), plus the component-installed/Joomla
 * version requirements every plan's tools declare via `#[ToolRequirements]`.
 *
 * Pure value object: no Joomla types except via the caller-supplied closure.
 *
 * @since  0.0.1
 */
final class ToolFilterCriteria
{
	/**
	 * @param   list<string>  $enabledCategories  Category names to keep (case-insensitive). Empty = all.
	 * @param   list<string>  $allowlist          Exact tool names to keep. Empty = no restriction. When
	 *                                             non-empty, takes precedence over $enabledCategories.
	 * @param   bool          $readOnlyOnly       Keep only tools with `readOnlyHint === true`. Applied
	 *                                             after every other criterion.
	 * @param   string        $joomlaVersion      The running `JVERSION`, compared against each tool's
	 *                                             `#[ToolRequirements]` bounds.
	 * @param   \Closure      $isComponentEnabled `fn(string $option): bool` — whether a Joomla component
	 *                                             is installed and enabled.
	 */
	public function __construct(
		public readonly array $enabledCategories,
		public readonly array $allowlist,
		public readonly bool $readOnlyOnly,
		public readonly string $joomlaVersion,
		public readonly \Closure $isComponentEnabled,
	) {
	}
}
