<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Mcp;

defined('_JEXEC') || die;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\User\User;
use Joomla\Registry\Registry;

/**
 * Everything a tool method needs from the surrounding Joomla request, bundled into one immutable value
 * so tool constructors (and, later, `Bridge\AbstractTool`) take a single dependency instead of pulling
 * `Factory::getApplication()`/`Factory::getContainer()` themselves — makes tool classes constructible in
 * isolation for testing.
 *
 * Deliberately no logic here — see {@see \Akeeba\Component\Mcp4j\Administrator\Mcp\ToolDispatcher} for
 * how this is built and used.
 *
 * @since  0.0.1
 */
final class ToolContext
{
	/**
	 * @param   CMSApplicationInterface  $app               The running (API) application.
	 * @param   User                     $user                The Joomla user the current bearer token
	 *                                                         resolved to — every tool call runs with
	 *                                                         exactly this user's ACL, never elevated.
	 * @param   Registry                 $params              `com_mcp4j` component parameters.
	 * @param   array<int, string>       $protectedSecrets    Secret values that must never appear in a
	 *                                                         non-read-only tool call's arguments — see
	 *                                                         {@see \Akeeba\Component\Mcp4j\Administrator\Mcp\Support\SecretLeakGuard}.
	 */
	public function __construct(
		public readonly CMSApplicationInterface $app,
		public readonly User $user,
		public readonly Registry $params,
		public readonly array $protectedSecrets,
	) {
	}
}
