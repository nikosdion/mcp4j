<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\View\Controlpanel;

defined('_JEXEC') || die;

use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;

class HtmlView extends BaseHtmlView
{
	public function display($tpl = null)
	{
		$this->addToolbar();

		return parent::display($tpl);
	}

	protected function addToolbar(): void
	{
		ToolbarHelper::title('MCP4j', 'cog');
		ToolbarHelper::preferences('com_mcp4j');
	}
}
