<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Mcp\Support;

defined('_JEXEC') || die;

/**
 * Decides what an uncaught `\Throwable`'s client-facing message should look like at MCP4j's two
 * catch-all sites ({@see \Akeeba\Component\Mcp4j\Administrator\Mcp\ToolDispatcher} and
 * {@see \Akeeba\Component\Mcp4j\Api\Controller\ServerController::execute()}).
 *
 * security.md M5: a raw `\Throwable::getMessage()` can embed a full filesystem path, line number, or
 * SQL/schema detail (a PHP `TypeError` or a DB driver exception, for instance) — never echo it to an
 * MCP client by default. The one exception is when the site owner has explicitly turned on Joomla's own
 * Debug System (`JDEBUG`) — the same signal Joomla core itself uses to decide whether error detail is
 * safe to show, so this deliberately doesn't invent a separate opt-in. This does NOT apply to a
 * deliberately-crafted exception (`ToolException`/`UnknownToolException`/`InvalidToolArgumentsException`/
 * `JsonRpcException`/...) or to a Joomla model/table error string surfaced via `AbstractTool::
 * modelError()` — both call sites catch those separately and pass their message through unconditionally,
 * since those are already curated to be safe to show and, per the operator's own review, are never
 * specific enough to leak anything (they don't know where they'll end up).
 *
 * Pure: no Joomla, no I/O — callers pass in whether `JDEBUG` is truthy rather than this class reading the
 * global constant itself, so this is unit-testable without a running Joomla application.
 *
 * @since  0.0.1
 */
final class ErrorDisclosure
{
	public static function toolErrorMessage(bool $debugEnabled, string $toolName, string $rawMessage): string
	{
		return $debugEnabled
			? sprintf('Internal error while running %s: %s', $toolName, $rawMessage)
			: sprintf('Internal error while running %s. See the server log for details.', $toolName);
	}

	public static function serverErrorMessage(bool $debugEnabled, string $rawMessage): string
	{
		return $debugEnabled ? $rawMessage : 'Internal server error. See the server log for details.';
	}
}
