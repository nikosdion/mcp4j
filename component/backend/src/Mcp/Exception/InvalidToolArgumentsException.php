<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Mcp\Exception;

defined('_JEXEC') || die;

/**
 * Thrown when a `tools/call` request's `arguments` cannot be bound to a tool method's parameters
 * (missing required argument, wrong shape, uncoercible type) — a malformed request, not a tool-level
 * outcome. {@see \Akeeba\Component\Mcp4j\Api\Controller\ServerController} maps this to JSON-RPC error
 * `-32602` ("Invalid params").
 *
 * @since  0.0.1
 */
class InvalidToolArgumentsException extends \InvalidArgumentException
{
}
