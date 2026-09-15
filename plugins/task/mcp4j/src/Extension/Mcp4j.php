<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Plugin\Task\Mcp4j\Extension;

defined('_JEXEC') || die;

use Akeeba\Component\Mcp4j\Administrator\Helper\SessionManager;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Task\Status;
use Joomla\Component\Scheduler\Administrator\Traits\TaskPluginTrait;
use Joomla\Database\DatabaseAwareInterface;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\SubscriberInterface;

/**
 * Joomla Scheduled Task: prunes expired `#__mcp4j_sessions` rows.
 *
 * This is the recommended way to keep that table tidy — see the component's `prune_on_request`
 * option and `.claude/mcp-session-handling.md` for why the on-request alternative locks the table.
 *
 * @since  0.0.1
 */
final class Mcp4j extends CMSPlugin implements SubscriberInterface, DatabaseAwareInterface
{
	use DatabaseAwareTrait;
	use TaskPluginTrait;

	private const TASKS_MAP = [
		'mcp4j.pruneSessions' => [
			'langConstPrefix' => 'PLG_TASK_MCP4J_PRUNESESSIONS',
			'method'          => 'pruneSessions',
		],
	];

	protected $autoloadLanguage = true;

	/** @inheritDoc */
	public static function getSubscribedEvents(): array
	{
		if (!ComponentHelper::isEnabled('com_mcp4j'))
		{
			return [];
		}

		return [
			'onTaskOptionsList' => 'advertiseRoutines',
			'onExecuteTask'     => 'standardRoutineHandler',
		];
	}

	private function pruneSessions(ExecuteTaskEvent $event): int
	{
		$deleted = (new SessionManager($this->getDatabase()))->pruneExpired();

		$this->logTask(sprintf('Pruned %d expired MCP4j session(s).', $deleted));

		return Status::OK;
	}
}
