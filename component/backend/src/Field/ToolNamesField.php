<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Field;

defined('_JEXEC') || die;

use Akeeba\Component\Mcp4j\Administrator\Mcp\Support\ToolRegistry;
use Joomla\CMS\Form\Field\GroupedlistField;

/**
 * Renders one option per discovered MCP tool, grouped by `Tool/<Category>`, as a Choices.js grouped fancy
 * multiselect (see the `tools` field's `layout="joomla.form.field.groupedlist-fancy-select"` in
 * `component/backend/config.xml`), for the `tools` option on the component's Options page. Like
 * {@see ToolCategoriesField}, the tool list is never hand-maintained: it comes straight from
 * {@see \Akeeba\Component\Mcp4j\Administrator\Mcp\Support\ToolRegistry::discover()}, the exact same
 * source of truth `ToolDispatcher` filters against, so this field can never drift out of sync with what
 * the server can actually expose.
 *
 * Requires `addfieldprefix="Akeeba\Component\Mcp4j\Administrator\Field"` on the field's nearest ancestor
 * `<fieldset>`/`<fields>`/`<form>`/`<config>` element — see `~/.claude/CLAUDE.md` ("custom XML form field
 * types need addfieldprefix").
 *
 * @since  0.0.1
 */
class ToolNamesField extends GroupedlistField
{
	/**
	 * @var  string
	 */
	protected $type = 'ToolNames';

	protected function getGroups()
	{
		$toolDirectory = JPATH_ADMINISTRATOR . '/components/com_mcp4j/src/Tool';

		$definitions = is_dir($toolDirectory)
			? ToolRegistry::discover($toolDirectory, ToolRegistry::DEFAULT_NAMESPACE_PREFIX)
			: [];

		$groups = [];

		foreach ($definitions as $definition)
		{
			$option        = new \stdClass();
			$option->value = $definition->name;
			$option->text  = $definition->name;

			$groups[$definition->category][] = $option;
		}

		return array_merge(parent::getGroups(), $groups);
	}
}
