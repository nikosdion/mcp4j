<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute;

defined('_JEXEC') || die;

/**
 * Declares that a tool class (and/or one of its tool methods) is only exposed when its runtime
 * requirements are met: a set of Joomla components must be installed and enabled, and/or the running
 * Joomla version must fall within a `[$minJoomla, $maxJoomla)` range.
 *
 * May be applied to a tool class, a tool method, or both — {@see
 * \Akeeba\Component\Mcp4j\Administrator\Mcp\Support\ToolRegistry} merges (adds together) class-level and
 * method-level requirements for each discovered tool.
 *
 * @since  0.0.1
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final class ToolRequirements
{
	/**
	 * @param   array<int, string>  $components  Component option names (e.g. `com_banners`) that must
	 *                                            all be installed and enabled for the tool to be exposed.
	 * @param   ?string              $minJoomla   Minimum Joomla version (inclusive), or null for no floor.
	 * @param   ?string              $maxJoomla   Maximum Joomla version (exclusive), or null for no ceiling.
	 */
	public function __construct(
		public readonly array $components = [],
		public readonly ?string $minJoomla = null,
		public readonly ?string $maxJoomla = null,
	) {
	}
}
