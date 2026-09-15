<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Support;

defined('_JEXEC') || die;

/**
 * The request body isn't a well-formed single JSON-RPC 2.0 request object — malformed JSON, a batch
 * (arrays are rejected: MCP dropped batching in the 2025-06-18 revision), or missing/wrong `jsonrpc`
 * or `method` members.
 *
 * Carries whatever `id` could be salvaged from the (partially) decoded body, so the caller can still
 * build a spec-shaped `{"jsonrpc":"2.0","id":..., "error": ...}` response — `null` if the body wasn't
 * even a JSON object/array to begin with.
 *
 * @since  0.0.1
 */
class JsonRpcRequestException extends \RuntimeException
{
	private readonly mixed $requestId;

	public function __construct(mixed $requestId, int $jsonRpcErrorCode, string $message)
	{
		parent::__construct($message, $jsonRpcErrorCode);

		$this->requestId = $requestId;
	}

	public function getRequestId(): mixed
	{
		return $this->requestId;
	}
}
