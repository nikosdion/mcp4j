<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Api\Exception;

defined('_JEXEC') || die;

/**
 * A JSON-RPC 2.0 method-level error (goes into the `error` member of the response), as opposed to an
 * HTTP-transport-level failure (missing/invalid `Mcp-Session-Id`, bad auth, malformed body, …) which
 * {@see \Akeeba\Component\Mcp4j\Api\Controller\ServerController} handles directly with the relevant
 * HTTP status instead of throwing.
 *
 * @since  0.0.1
 */
class JsonRpcException extends \RuntimeException
{
}
