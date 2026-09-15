<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Api\Controller;

defined('_JEXEC') || die;

use Akeeba\Component\Mcp4j\Administrator\Helper\SessionManager;
use Akeeba\Component\Mcp4j\Api\Exception\JsonRpcException;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Controller\ApiController;
use Joomla\Database\DatabaseInterface;

/**
 * Handles the MCP Streamable HTTP transport endpoint (`/v1/mcp4j/server`).
 *
 * This deliberately bypasses the Tobscure JSON:API serialisation the rest of the Web Services API uses — MCP speaks
 * plain JSON-RPC 2.0, not JSON:API — by writing the response body directly and calling {@see close()}.
 *
 * Session handling (the `Mcp-Session-Id` header, resuming/persisting the ephemeral Joomla session into
 * `#__mcp4j_sessions`) is documented in `.claude/mcp-session-handling.md`.
 *
 * @since  0.0.1
 */
class ServerController extends ApiController
{
	protected $contentType = 'server';

	/**
	 * The MCP protocol revision this server implements. MCP4j always answers `initialize` with this
	 * version, regardless of what the client requested — per spec, the client decides whether to
	 * proceed once it sees which version the server actually supports.
	 */
	private const PROTOCOL_VERSION = '2025-06-18';

	private ?SessionManager $sessionManager = null;

	public function execute($task)
	{
		try
		{
			return parent::execute($task);
		}
		catch (\Throwable $e)
		{
			$this->respondError(null, $e->getCode() ?: -32603, $e->getMessage(), 500);
		}

		return '';
	}

	/**
	 * POST /v1/mcp4j/server — handles a single JSON-RPC 2.0 request from an MCP client.
	 *
	 * MCP does not support JSON-RPC batching (removed in the 2025-06-18 revision), so unlike a generic
	 * JSON-RPC endpoint this only ever accepts a single request object per call.
	 */
	public function dispatch()
	{
		$request = json_decode((string) $this->input->getRaw(), true);

		if (json_last_error() !== JSON_ERROR_NONE || !is_array($request) || array_is_list($request))
		{
			$this->respondError(null, -32600, 'Invalid Request: expected a single JSON-RPC 2.0 request object.', 400);

			return;
		}

		$id     = $request['id'] ?? null;
		$method = $request['method'] ?? null;
		$params = is_array($request['params'] ?? null) ? $request['params'] : [];

		if (($request['jsonrpc'] ?? null) !== '2.0' || !is_string($method) || $method === '')
		{
			$this->respondError($id, -32600, 'Invalid Request: expected a JSON-RPC 2.0 request object.', 400);

			return;
		}

		// Per JSON-RPC 2.0, a request with no `id` member at all is a notification: it gets no response body.
		$isNotification = !array_key_exists('id', $request);

		if ($method === 'initialize')
		{
			$this->handleInitialize($id, $params, $isNotification);

			return;
		}

		if ($method === 'notifications/initialized')
		{
			$this->respondAccepted();

			return;
		}

		// Every other method requires an already-established MCP session.
		$sessionId = $this->requireSession($id);

		if ($sessionId === null)
		{
			// requireSession() already sent the response (400/401/404).
			return;
		}

		try
		{
			$result = $this->dispatchMethod($method, $params);
			$error  = null;
		}
		catch (JsonRpcException $e)
		{
			$result = null;
			$error  = $e;
		}

		$this->getSessionManager()->persist($this->app->getSession(), $sessionId);
		$this->pruneIfConfigured();

		if ($isNotification)
		{
			$this->respondAccepted();

			return;
		}

		if ($error !== null)
		{
			$this->respondError($id, $error->getCode(), $error->getMessage());

			return;
		}

		$this->respondResult($id, $result);
	}

	/**
	 * DELETE /v1/mcp4j/server — explicitly terminates an `Mcp-Session-Id`.
	 */
	public function terminate()
	{
		$sessionId = $this->getSessionIdHeader();
		$userId    = $this->getAuthenticatedUserId();

		if ($sessionId === '' || $userId === null)
		{
			$this->app->setHeader('status', $userId === null ? 401 : 400);
			$this->app->close();

			return;
		}

		// resume() also validates the session belongs to this user before we touch it.
		if ($this->getSessionManager()->resume($this->app->getSession(), $sessionId, $userId))
		{
			$this->getSessionManager()->delete($sessionId);
		}

		$this->app->setHeader('status', 204);
		$this->app->close();
	}

	// -----------------------------------------------------------------------------------------------------------
	// Method handlers
	// -----------------------------------------------------------------------------------------------------------

	private function handleInitialize($id, array $params, bool $isNotification): void
	{
		$userId = $this->getAuthenticatedUserId();

		if ($userId === null)
		{
			$this->respondError($id, -32001, 'Authentication required.', 401);

			return;
		}

		$clientInfo = is_array($params['clientInfo'] ?? null) ? $params['clientInfo'] : [];

		$sessionId = $this->getSessionManager()->create(
			$this->app->getSession(),
			$userId,
			(string) ($params['protocolVersion'] ?? self::PROTOCOL_VERSION),
			$clientInfo
		);

		$this->app->setHeader('Mcp-Session-Id', $sessionId);

		if ($isNotification)
		{
			$this->respondAccepted();

			return;
		}

		$this->respondResult($id, [
			'protocolVersion' => self::PROTOCOL_VERSION,
			'capabilities'    => [
				'tools' => new \stdClass(),
			],
			'serverInfo'      => [
				'name'    => 'MCP4j',
				'version' => '0.0.1',
			],
		]);
	}

	/**
	 * @return array{tools: array}
	 * @throws JsonRpcException
	 * @todo   Return the actual Joomla-facing tool surface once it exists. Empty for now.
	 */
	private function handleToolsList(array $params): array
	{
		return ['tools' => []];
	}

	/**
	 * @throws JsonRpcException
	 * @todo   Dispatch to a real tool once any exist. Every call is currently "unknown tool".
	 */
	private function handleToolsCall(array $params): array
	{
		$name = is_string($params['name'] ?? null) ? $params['name'] : '(missing)';

		throw new JsonRpcException(sprintf('Unknown tool: %s', $name), -32602);
	}

	/**
	 * @throws JsonRpcException
	 */
	private function dispatchMethod(string $method, array $params): array|\stdClass
	{
		return match ($method)
		{
			'ping' => new \stdClass(),
			'tools/list' => $this->handleToolsList($params),
			'tools/call' => $this->handleToolsCall($params),
			default => throw new JsonRpcException(sprintf('Method not found: %s', $method), -32601),
		};
	}

	// -----------------------------------------------------------------------------------------------------------
	// Session/auth plumbing — see .claude/mcp-session-handling.md
	// -----------------------------------------------------------------------------------------------------------

	/**
	 * Resolves the `Mcp-Session-Id` header and the requesting user, and resumes the stored session into
	 * the current request's ephemeral session. Sends the appropriate HTTP-level error response itself
	 * (400/401/404) and returns null if any of that fails — callers just need to bail out in that case.
	 */
	private function requireSession($id): ?string
	{
		$sessionId = $this->getSessionIdHeader();

		if ($sessionId === '')
		{
			$this->respondError($id, -32000, 'Missing Mcp-Session-Id header.', 400);

			return null;
		}

		$userId = $this->getAuthenticatedUserId();

		if ($userId === null)
		{
			$this->respondError($id, -32001, 'Authentication required.', 401);

			return null;
		}

		if (!$this->getSessionManager()->resume($this->app->getSession(), $sessionId, $userId))
		{
			$this->respondError($id, -32001, 'Unknown or expired Mcp-Session-Id. Re-initialize.', 404);

			return null;
		}

		return $sessionId;
	}

	private function getAuthenticatedUserId(): ?int
	{
		$identity = $this->app->getIdentity();

		if ($identity === null || $identity->guest)
		{
			return null;
		}

		return (int) $identity->id;
	}

	private function getSessionIdHeader(): string
	{
		return trim((string) $this->input->server->getString('HTTP_MCP_SESSION_ID', ''));
	}

	private function getSessionManager(): SessionManager
	{
		return $this->sessionManager ??= new SessionManager(Factory::getContainer()->get(DatabaseInterface::class));
	}

	private function pruneIfConfigured(): void
	{
		if (!$this->getSessionManager()->shouldPruneOnRequest())
		{
			return;
		}

		try
		{
			$this->getSessionManager()->pruneExpired();
		}
		catch (\Throwable $e)
		{
			// Never fail the response over housekeeping; plg_task_mcp4j is the recommended path anyway.
		}
	}

	// -----------------------------------------------------------------------------------------------------------
	// Response envelope
	// -----------------------------------------------------------------------------------------------------------

	private function respondResult($id, $result): void
	{
		$this->app->setHeader('status', 200);
		$this->sendJson([
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		]);
	}

	private function respondError($id, int $code, string $message, int $status = 200): void
	{
		$this->app->setHeader('status', $status);
		$this->sendJson([
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => [
				'code'    => $code,
				'message' => $message,
			],
		]);
	}

	private function respondAccepted(): void
	{
		$this->app->setHeader('status', 202);
		$this->app->close();
	}

	private function sendJson(array $body): void
	{
		$this->app->setHeader('Content-Type', 'application/json', true);

		echo json_encode($body);

		$this->app->close();
	}
}
