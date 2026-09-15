<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Plugin\WebServices\Mcp4j\Extension;

defined('_JEXEC') || die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Router\ApiRouter;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;
use Joomla\Router\Route;

/**
 * Registers the MCP4j Streamable HTTP endpoint with Joomla's Web Services API router.
 *
 * @since  0.0.1
 */
class Mcp4j extends CMSPlugin implements SubscriberInterface
{
	private const API_PREFIX = 'v1/mcp4j/';

	protected $allowLegacyListeners = false;

	/** @inheritDoc */
	public static function getSubscribedEvents(): array
	{
		return [
			'onBeforeApiRoute' => 'registerRoutes',
		];
	}

	public function registerRoutes(Event $event): void
	{
		/** @var ApiRouter $router */
		[$router] = array_values($event->getArguments());

		$defaults = [
			'component' => 'com_mcp4j',
		];

		$routes = [
			new Route(['POST'], self::API_PREFIX . 'server', 'server.dispatch', [], $defaults),
			new Route(['DELETE'], self::API_PREFIX . 'server', 'server.terminate', [], $defaults),
		];

		$router->addRoutes($routes);
	}
}
