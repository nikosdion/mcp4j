<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Mcp\Exception;

defined('_JEXEC') || die;

/**
 * Thrown by a tool method for anything the calling LLM should read and possibly correct: not found,
 * permission denied, validation failed, record checked out, unsupported on this Joomla version, etc.
 *
 * {@see \Akeeba\Component\Mcp4j\Administrator\Mcp\ToolDispatcher::callTool()} catches this and turns it
 * into an MCP tool-call result with `isError: true` and this exception's message as the content — it is
 * a JSON-RPC-level success (the call itself completed), only the tool outcome was an error. Use
 * {@see \Akeeba\Component\Mcp4j\Administrator\Mcp\Exception\InvalidToolArgumentsException} instead for
 * argument-shape problems the framework should reject before the tool method even runs.
 *
 * @since  0.0.1
 */
class ToolException extends \RuntimeException
{
}
