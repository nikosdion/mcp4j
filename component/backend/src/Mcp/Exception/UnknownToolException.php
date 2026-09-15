<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Mcp\Exception;

defined('_JEXEC') || die;

/**
 * Thrown when a `tools/call` request names a tool that either does not exist, or exists but is not in
 * the currently exposed (filtered) set — the two are deliberately indistinguishable to the caller.
 * {@see \Akeeba\Component\Mcp4j\Api\Controller\ServerController} maps this to JSON-RPC error `-32602`
 * ("Invalid params"), the same as {@see InvalidToolArgumentsException}.
 *
 * @since  0.0.1
 */
class UnknownToolException extends \InvalidArgumentException
{
}
