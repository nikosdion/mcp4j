<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Extension;

defined('_JEXEC') || die;

use Joomla\CMS\Extension\BootableExtensionInterface;
use Joomla\CMS\Extension\MVCComponent;
use Psr\Container\ContainerInterface;

class Mcp4jComponent extends MVCComponent implements BootableExtensionInterface
{
	public function boot(ContainerInterface $container)
	{
		// No-op.
	}
}
