<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Field;

defined('_JEXEC') || die;

use Joomla\CMS\Form\Field\ListField;

/**
 * Renders one option per `Tool/<Category>` folder currently present under
 * `administrator/components/com_mcp4j/src/Tool`, as a Choices.js fancy multiselect (see the `categories`
 * field's `layout="joomla.form.field.list-fancy-select"` in `component/backend/config.xml`), for the
 * `categories` option on the component's Options page. The category list is not hand-maintained
 * anywhere — it is always exactly
 * {@see \Akeeba\Component\Mcp4j\Administrator\Mcp\Support\ToolRegistry}'s own definition of "category"
 * (a folder name), read straight off disk.
 *
 * Requires `addfieldprefix="Akeeba\Component\Mcp4j\Administrator\Field"` on the field's nearest ancestor
 * `<fieldset>`/`<fields>`/`<form>`/`<config>` element — see `~/.claude/CLAUDE.md` ("custom XML form field
 * types need addfieldprefix").
 *
 * @since  0.0.1
 */
class ToolCategoriesField extends ListField
{
	/**
	 * @var  string
	 */
	protected $type = 'ToolCategories';

	protected function getOptions()
	{
		$toolDirectory = JPATH_ADMINISTRATOR . '/components/com_mcp4j/src/Tool';

		$categories = is_dir($toolDirectory)
			? (glob($toolDirectory . '/*', GLOB_ONLYDIR) ?: [])
			: [];

		$categories = array_map('basename', $categories);

		sort($categories);

		$options = [];

		foreach ($categories as $category)
		{
			$option        = new \stdClass();
			$option->value = $category;
			$option->text  = $category;

			$options[] = $option;
		}

		return array_merge(parent::getOptions(), $options);
	}
}
