<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

defined('_JEXEC') || die;

use Akeeba\Component\Mcp4j\Administrator\Extension\Mcp4jComponent;
use Joomla\CMS\Dispatcher\ComponentDispatcherFactoryInterface;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\Extension\Service\Provider\ComponentDispatcherFactory;
use Joomla\CMS\Extension\Service\Provider\MVCFactory;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

/**
 * The com_mcp4j service provider.
 *
 * @since  0.0.1
 */
return new class () implements ServiceProviderInterface {
	/**
	 * Registers the service provider with a DI container.
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  void
	 *
	 * @since   0.0.1
	 */
	public function register(Container $container)
	{
		$container->registerServiceProvider(new MVCFactory('Akeeba\\Component\\Mcp4j'));
		$container->registerServiceProvider(new ComponentDispatcherFactory('Akeeba\\Component\\Mcp4j'));

		$container->set(
			ComponentInterface::class,
			function (Container $container) {
				try
				{
					require_once JPATH_ADMINISTRATOR . '/components/com_mcp4j/vendor/autoload.php';
				}
				catch (Throwable $e)
				{
					// Well, we tried…
				}

				$component = new Mcp4jComponent($container->get(ComponentDispatcherFactoryInterface::class));

				$component->setMVCFactory($container->get(MVCFactoryInterface::class));

				return $component;
			}
		);
	}
};
