<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Helper;

defined('_JEXEC') || die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Date\Date;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Session\SessionInterface;

/**
 * Persists MCP session state — including a full snapshot of the request's ephemeral
 * {@see SessionInterface} — into `#__mcp4j_sessions`, independently of Joomla's own PHP
 * session lifecycle.
 *
 * See `.claude/mcp-session-handling.md` for the reasoning behind this design.
 *
 * @since  0.0.1
 */
class SessionManager
{
	public function __construct(private readonly DatabaseInterface $db)
	{
	}

	/**
	 * Mints a new MCP session row and returns its (opaque) session ID.
	 *
	 * @param   SessionInterface  $session         The current request's ephemeral session, snapshotted as-is.
	 * @param   int               $userId          The Joomla user resolved from the bearer token.
	 * @param   string            $protocolVersion The MCP protocol version negotiated at `initialize`.
	 * @param   array             $clientInfo      The client's `clientInfo` object from `initialize`.
	 *
	 * @since   0.0.1
	 */
	public function create(SessionInterface $session, int $userId, string $protocolVersion, array $clientInfo): string
	{
		$sessionId = $this->generateSessionId();
		$now       = Factory::getDate();
		$expires   = $this->expiryFrom($now);

		$object = (object) [
			'session_id'       => $sessionId,
			'user_id'          => $userId,
			'protocol_version' => $protocolVersion,
			'client_info'      => json_encode($clientInfo),
			'session_data'     => $this->serializeSession($session),
			'created'          => $now->toSql(),
			'last_active'      => $now->toSql(),
			'expires'          => $expires->toSql(),
		];

		$this->db->insertObject('#__mcp4j_sessions', $object);

		return $sessionId;
	}

	/**
	 * Loads a stored MCP session's data into the current request's ephemeral session.
	 *
	 * @param   SessionInterface  $session   The current request's ephemeral session, populated in place.
	 * @param   string            $sessionId The `Mcp-Session-Id` header value sent by the client.
	 * @param   int               $userId    The Joomla user resolved from *this* request's bearer token.
	 *
	 * @return  bool  False if the session does not exist, has expired, or belongs to a different user.
	 * @since   0.0.1
	 */
	public function resume(SessionInterface $session, string $sessionId, int $userId): bool
	{
		$row = $this->loadRow($sessionId);

		if ($row === null)
		{
			return false;
		}

		if ((int) $row->user_id !== $userId)
		{
			return false;
		}

		if (strtotime($row->expires) < time())
		{
			$this->delete($sessionId);

			return false;
		}

		foreach ($this->unserializeSession($row->session_data) as $key => $value)
		{
			$session->set($key, $value);
		}

		return true;
	}

	/**
	 * Snapshots the current request's ephemeral session back into its stored row, refreshing
	 * `last_active` and `expires`.
	 *
	 * @since   0.0.1
	 */
	public function persist(SessionInterface $session, string $sessionId): void
	{
		$now     = Factory::getDate();
		$expires = $this->expiryFrom($now);

		$object = (object) [
			'session_id'   => $sessionId,
			'session_data' => $this->serializeSession($session),
			'last_active'  => $now->toSql(),
			'expires'      => $expires->toSql(),
		];

		$this->db->updateObject('#__mcp4j_sessions', $object, 'session_id');
	}

	/**
	 * Deletes a single MCP session row, e.g. in response to `DELETE /v1/mcp4j/server`.
	 *
	 * @since   0.0.1
	 */
	public function delete(string $sessionId): void
	{
		$query = $this->db->getQuery(true)
			->delete($this->db->quoteName('#__mcp4j_sessions'))
			->where($this->db->quoteName('session_id') . ' = :sessionId')
			->bind(':sessionId', $sessionId, ParameterType::STRING);

		$this->db->setQuery($query)->execute();
	}

	/**
	 * Deletes every expired MCP session row.
	 *
	 * Locks the table for the duration of the delete (portable across every driver Joomla ships —
	 * MySQL(i), PostgreSQL, SQLite, SQL Server all implement {@see DatabaseInterface::lockTable()}).
	 * Callers decide *when* to call this: the component's `prune_on_request` option gates the
	 * opportunistic per-request sweep, while `plg_task_mcp4j` calls it unconditionally on its own
	 * schedule — see `.claude/mcp-session-handling.md`.
	 *
	 * @return  int  The number of deleted rows.
	 * @since   0.0.1
	 */
	public function pruneExpired(): int
	{
		$now = Factory::getDate()->toSql();

		try
		{
			$this->db->lockTable('#__mcp4j_sessions');

			$query = $this->db->getQuery(true)
				->delete($this->db->quoteName('#__mcp4j_sessions'))
				->where($this->db->quoteName('expires') . ' < :now')
				->bind(':now', $now, ParameterType::STRING);

			$this->db->setQuery($query)->execute();

			return $this->db->getAffectedRows();
		}
		finally
		{
			$this->db->unlockTables();
		}
	}

	/**
	 * Whether the `prune_on_request` component option is enabled.
	 *
	 * @since   0.0.1
	 */
	public function shouldPruneOnRequest(): bool
	{
		return (bool) ComponentHelper::getParams('com_mcp4j')->get('prune_on_request', 0);
	}

	private function loadRow(string $sessionId): ?object
	{
		$query = $this->db->getQuery(true)
			->select('*')
			->from($this->db->quoteName('#__mcp4j_sessions'))
			->where($this->db->quoteName('session_id') . ' = :sessionId')
			->bind(':sessionId', $sessionId, ParameterType::STRING);

		return $this->db->setQuery($query)->loadObject() ?: null;
	}

	private function expiryFrom(Date $from): Date
	{
		$expires = clone $from;
		$expires->add(new \DateInterval('PT' . $this->getTtl() . 'S'));

		return $expires;
	}

	private function getTtl(): int
	{
		return (int) ComponentHelper::getParams('com_mcp4j')->get('session_ttl', 3600);
	}

	private function generateSessionId(): string
	{
		return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
	}

	private function serializeSession(SessionInterface $session): string
	{
		return base64_encode(serialize($session->all()));
	}

	private function unserializeSession(string $data): array
	{
		if ($data === '')
		{
			return [];
		}

		$data = @unserialize(base64_decode($data), ['allowed_classes' => true]);

		return is_array($data) ? $data : [];
	}
}
