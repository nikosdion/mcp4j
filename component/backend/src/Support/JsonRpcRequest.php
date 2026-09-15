<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Support;

defined('_JEXEC') || die;

/**
 * A parsed, validated JSON-RPC 2.0 request envelope.
 *
 * Pure and framework-free: no Joomla, no I/O, just the parsing/validation rules from the JSON-RPC 2.0
 * spec plus the one MCP-specific rule (batching is rejected — MCP dropped it in the 2025-06-18
 * revision, unlike base JSON-RPC 2.0). Everything Joomla-specific (sessions, auth, method dispatch)
 * happens in {@see \Akeeba\Component\Mcp4j\Api\Controller\ServerController}, which is what makes this
 * class unit-testable without a framework or database.
 *
 * @since  0.0.1
 */
final class JsonRpcRequest
{
	private function __construct(
		public readonly mixed $id,
		public readonly string $method,
		public readonly array $params,
		public readonly bool $isNotification,
	) {
	}

	/**
	 * @throws  JsonRpcRequestException
	 */
	public static function fromRawBody(string $rawBody): self
	{
		$decoded = json_decode($rawBody, true);

		if (json_last_error() !== JSON_ERROR_NONE)
		{
			throw new JsonRpcRequestException(null, -32700, 'Parse error: request body is not valid JSON.');
		}

		return self::fromDecoded($decoded);
	}

	/**
	 * @param   mixed  $decoded  The result of json_decode($json, true).
	 *
	 * @throws  JsonRpcRequestException
	 */
	public static function fromDecoded(mixed $decoded): self
	{
		if (!is_array($decoded) || array_is_list($decoded))
		{
			throw new JsonRpcRequestException(
				null,
				-32600,
				'Invalid Request: expected a single JSON-RPC 2.0 request object.'
			);
		}

		$id     = $decoded['id'] ?? null;
		$method = $decoded['method'] ?? null;

		if (($decoded['jsonrpc'] ?? null) !== '2.0' || !is_string($method) || $method === '')
		{
			throw new JsonRpcRequestException(
				$id,
				-32600,
				'Invalid Request: expected a JSON-RPC 2.0 request object.'
			);
		}

		$params = is_array($decoded['params'] ?? null) ? $decoded['params'] : [];

		// Per JSON-RPC 2.0, a request with no `id` member at all is a notification: it gets no response body.
		$isNotification = !array_key_exists('id', $decoded);

		return new self($id, $method, $params, $isNotification);
	}
}
