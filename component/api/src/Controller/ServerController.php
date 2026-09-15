<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Api\Controller;

defined('_JEXEC') || die;

use Akeeba\Component\Mcp4j\Administrator\Helper\SessionManager;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Exception\InvalidToolArgumentsException;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Exception\UnknownToolException;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Support\SecretLeakGuard;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Support\ServerInstructions;
use Akeeba\Component\Mcp4j\Administrator\Mcp\ToolContext;
use Akeeba\Component\Mcp4j\Administrator\Mcp\ToolDispatcher;
use Akeeba\Component\Mcp4j\Administrator\Support\JsonRpcRequest;
use Akeeba\Component\Mcp4j\Administrator\Support\JsonRpcRequestException;
use Akeeba\Component\Mcp4j\Api\Exception\JsonRpcException;
use Joomla\CMS\Component\ComponentHelper;
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

	private ?ToolDispatcher $toolDispatcher = null;

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
		try
		{
			// Deliberately not $this->input->getRaw(): depending on how the controller was constructed,
			// $this->input is only guaranteed to be a Joomla\Input\Input, not the Joomla\Input\Json subclass
			// that method lives on. php://input is safely re-readable here (we're not multipart/form-data),
			// and ApiApplication's own Json input already read it the same way when it built the input
			// object in the first place.
			$request = JsonRpcRequest::fromRawBody((string) file_get_contents('php://input'));
		}
		catch (JsonRpcRequestException $e)
		{
			$this->respondError($e->getRequestId(), $e->getCode(), $e->getMessage(), 400);

			return;
		}

		if ($request->method === 'initialize')
		{
			$this->handleInitialize($request->id, $request->params, $request->isNotification);

			return;
		}

		if ($request->method === 'notifications/initialized')
		{
			$this->respondAccepted();

			return;
		}

		// Every other method requires an already-established MCP session.
		$sessionId = $this->requireSession($request->id);

		if ($sessionId === null)
		{
			// requireSession() already sent the response (400/401/404).
			return;
		}

		try
		{
			$result = $this->dispatchMethod($request->method, $request->params);
			$error  = null;
		}
		catch (JsonRpcException $e)
		{
			$result = null;
			$error  = $e;
		}

		$this->getSessionManager()->persist($this->app->getSession(), $sessionId);
		$this->pruneIfConfigured();

		if ($request->isNotification)
		{
			$this->respondAccepted();

			return;
		}

		if ($error !== null)
		{
			$this->respondError($request->id, $error->getCode(), $error->getMessage());

			return;
		}

		$this->respondResult($request->id, $result);
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
			$this->send();

			return;
		}

		// resume() also validates the session belongs to this user before we touch it.
		if ($this->getSessionManager()->resume($this->app->getSession(), $sessionId, $userId))
		{
			$this->getSessionManager()->delete($sessionId);
		}

		$this->app->setHeader('status', 204);
		$this->send();
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
			'instructions'    => ServerInstructions::build($this->getToolDispatcher()->exposedCategories()),
		]);
	}

	/**
	 * @return array{tools: array}
	 * @throws JsonRpcException
	 */
	private function handleToolsList(array $params): array
	{
		return $this->getToolDispatcher()->listTools();
	}

	/**
	 * @throws JsonRpcException
	 */
	private function handleToolsCall(array $params): array
	{
		try
		{
			return $this->getToolDispatcher()->callTool($params);
		}
		catch (UnknownToolException|InvalidToolArgumentsException $e)
		{
			throw new JsonRpcException($e->getMessage(), -32602);
		}
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

	/**
	 * Resolves the bearer token into a Joomla user, or null if that fails.
	 *
	 * Our routes are marked `public => true` specifically so this — not
	 * `ApiApplication::route()`'s own forced pre-dispatch login-or-throw — is what decides how an
	 * authentication failure gets reported. See the `public` entry in
	 * {@see \Akeeba\Plugin\WebServices\Mcp4j\Extension\Mcp4j::registerRoutes()} for why: Joomla's own
	 * path throws {@see \Joomla\CMS\Access\Exception\AuthenticationFailed} with no arguments, which its
	 * JsonRenderer turns into a bare HTTP 500 instead of a 401.
	 */
	private function getAuthenticatedUserId(): ?int
	{
		if (!$this->app->login(['username' => ''], ['silent' => true, 'action' => 'core.login.api']))
		{
			return null;
		}

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

	/**
	 * Builds (and memoises, for the lifetime of this request) the tool dispatcher, with a
	 * {@see ToolContext} carrying the current API user, `com_mcp4j` parameters, and the secrets
	 * {@see SecretLeakGuard} must refuse to let a non-read-only tool call carry — see
	 * `.plans/01-tool-framework.md` §16.
	 */
	private function getToolDispatcher(): ToolDispatcher
	{
		if ($this->toolDispatcher !== null)
		{
			return $this->toolDispatcher;
		}

		$context = new ToolContext(
			$this->app,
			$this->app->getIdentity(),
			ComponentHelper::getParams('com_mcp4j'),
			$this->collectProtectedSecrets()
		);

		return $this->toolDispatcher = new ToolDispatcher($context);
	}

	/**
	 * @return array<int, string>
	 */
	private function collectProtectedSecrets(): array
	{
		$params = ComponentHelper::getParams('com_mcp4j');

		// The bearer token of *this* request — always protected, however short (a short token is still a
		// token, and site owners should not be encouraged to think a short one is safe to leak).
		$bearerCandidates = [
			$this->getBearerToken(),
			$this->input->server->getString('HTTP_X_JOOMLA_TOKEN', ''),
		];

		// Global configuration secrets — an 8-character minimum avoids false-positiving on a dev site's
		// trivially short DB/mail password (e.g. "root") blocking otherwise legitimate tool arguments.
		$siteSecretKeys = [
			'secret',
			'password',
			'smtppass',
			'ftp_pass',
			'proxy_pass',
			'redis_server_auth',
			'session_redis_server_auth',
		];

		$siteSecretCandidates = array_map(
			fn (string $key): string => (string) $this->app->get($key, ''),
			$siteSecretKeys
		);

		$forbiddenCandidates = preg_split('/\R/', (string) $params->get('forbidden_secrets', '')) ?: [];

		return array_values(array_unique(array_merge(
			SecretLeakGuard::normaliseSecrets($bearerCandidates, 1),
			SecretLeakGuard::normaliseSecrets($siteSecretCandidates, 8),
			SecretLeakGuard::normaliseSecrets($forbiddenCandidates, 1)
		)));
	}

	private function getBearerToken(): string
	{
		$header = $this->input->server->getString('HTTP_AUTHORIZATION', '');

		if ($header === '')
		{
			$header = $this->input->server->getString('REDIRECT_HTTP_AUTHORIZATION', '');
		}

		if (preg_match('/^Bearer\s+(.+)$/i', trim($header), $matches) === 1)
		{
			return trim($matches[1]);
		}

		return '';
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
		$this->send();
	}

	private function sendJson(array $body): void
	{
		$this->app->setHeader('Content-Type', 'application/json', true);
		$this->send(json_encode($body));
	}

	/**
	 * {@see \Joomla\CMS\Application\ApiApplication::close()} is a bare `exit()` — it never runs the
	 * `respond()`/`sendHeaders()` pipeline that would otherwise turn our queued {@see setHeader()} calls
	 * into real `header()` calls. Every response method funnels through here instead of calling
	 * `$this->app->close()` directly: headers first (so `Mcp-Session-Id`, `Content-Type`, etc. are real
	 * `header()` calls before any output exists), only then the body, only then `close()`. Doing this the
	 * other way around — echo the body, then try to send headers — happens to often "work" only because
	 * PHP hasn't flushed its output buffer yet, which is not something to rely on.
	 */
	private function send(?string $body = null): void
	{
		$this->app->sendHeaders();

		if ($body !== null)
		{
			echo $body;
		}

		$this->app->close();
	}
}
