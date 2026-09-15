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
			// MCP clients typically send `Accept: application/json, text/event-stream` per the Streamable
			// HTTP transport spec. We only ever respond with plain JSON — no SSE, see
			// .claude/mcp-session-handling.md — so we don't offer text/event-stream as a priority; Joomla's
			// content negotiation will settle on `application/json`, which ApiApplication's constructor
			// already maps to a real document type. Without this, Joomla defaults every route's negotiable
			// formats to only `application/vnd.api+json`, which a plain JSON-RPC client won't be offering.
			'format'    => ['application/json', 'application/vnd.api+json'],
			// Not actually public — see ServerController::attemptLogin(). A non-public route makes
			// ApiApplication::route() call $this->login() itself and *throw* Access\Exception\
			// AuthenticationFailed when it fails, before our controller ever runs. That exception is
			// constructed with no arguments, so Joomla's own JsonRenderer — which only trusts
			// $error->getCode() when it's greater than 0 — renders it as a bare HTTP 500 instead of 401.
			// Marking the route public skips that forced call so we can call $this->login() ourselves,
			// inside the controller, and turn a failure into our own proper 401 JSON-RPC error instead.
			'public'    => true,
		];

		$routes = [
			new Route(['POST'], self::API_PREFIX . 'server', 'server.dispatch', [], $defaults),
			new Route(['DELETE'], self::API_PREFIX . 'server', 'server.terminate', [], $defaults),
		];

		$router->addRoutes($routes);

		$this->ensureAcceptHeaderForOurRoute($router, $routes);
	}

	/**
	 * Joomla's content negotiation (willdurand/negotiation) throws on a missing `Accept` header — it treats
	 * an empty header as invalid input, not as "accept anything" — which turns into a hard 406 before our
	 * controller ever runs. Not every MCP HTTP client bothers sending one, so we fill one in ourselves,
	 * before routing reaches that check.
	 *
	 * This only ever touches the request when *both* of these hold: the `Accept` header is genuinely absent
	 * (so it's a no-op for the overwhelming majority of requests, on any endpoint), and the current request
	 * path actually matches one of *our own* routes — reusing the exact `Route` objects and regex-matching
	 * `parseApiRoute()` itself will use, rather than guessing from a hand-maintained path string. Other Web
	 * Services routes are therefore never affected by this.
	 *
	 * @param   Route[]  $routes  The routes just registered above.
	 */
	private function ensureAcceptHeaderForOurRoute(ApiRouter $router, array $routes): void
	{
		$app = $this->getApplication();

		if ($app->input->server->getString('HTTP_ACCEPT', '') !== '')
		{
			return;
		}

		$path = ltrim($router->getRoutePath(), '/');

		foreach ($routes as $route)
		{
			if (preg_match($route->getRegex(), $path))
			{
				$app->input->server->set('HTTP_ACCEPT', 'application/json');

				return;
			}
		}
	}
}
