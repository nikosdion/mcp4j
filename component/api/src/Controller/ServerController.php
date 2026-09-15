<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Api\Controller;

defined('_JEXEC') || die;

use Joomla\CMS\MVC\Controller\ApiController;

/**
 * Handles the MCP Streamable HTTP transport endpoint (`/v1/mcp4j/server`).
 *
 * This deliberately bypasses the Tobscure JSON:API serialisation the rest of the Web Services API uses — MCP speaks
 * plain JSON-RPC 2.0, not JSON:API — by writing the response body directly and calling {@see close()}.
 *
 * @since  0.0.1
 */
class ServerController extends ApiController
{
	protected $contentType = 'server';

	public function execute($task)
	{
		try
		{
			return parent::execute($task);
		}
		catch (\Throwable $e)
		{
			$this->respondWithJsonRpcError($e->getCode() ?: -32603, $e->getMessage());
		}

		return '';
	}

	/**
	 * POST /v1/mcp4j/server — handles a single JSON-RPC 2.0 request (or batch) from an MCP client.
	 *
	 * @todo   Implement `initialize`, `tools/list`, `tools/call`. This is also where the
	 *         `Mcp-Session-Id` request header needs to be read and handed to
	 *         {@see \Akeeba\Component\Mcp4j\Administrator\Helper\SessionManager::resume()} before
	 *         dispatching, and {@see \Akeeba\Component\Mcp4j\Administrator\Helper\SessionManager::persist()}
	 *         called before the response is sent — see .claude/mcp-session-handling.md.
	 */
	public function dispatch()
	{
		$this->respondWithJsonRpcError(-32601, 'MCP4j has not implemented any JSON-RPC methods yet.');
	}

	/**
	 * DELETE /v1/mcp4j/server — explicitly terminates an `Mcp-Session-Id`.
	 *
	 * @todo   Implement session deletion from `#__mcp4j_sessions`.
	 */
	public function terminate()
	{
		$this->app->setHeader('status', 204);
		$this->app->close();
	}

	private function respondWithJsonRpcError(int $code, string $message): void
	{
		$this->app->setHeader('Content-Type', 'application/json', true);
		$this->app->setHeader('status', 200);

		echo json_encode([
			'jsonrpc' => '2.0',
			'id'      => null,
			'error'   => [
				'code'    => $code,
				'message' => $message,
			],
		]);

		$this->app->close();
	}
}
