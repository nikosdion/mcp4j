<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Integration\Tests\Privacy;

use Akeeba\Mcp4j\Tests\Integration\AbstractToolTestCase;
use Akeeba\Mcp4j\Tests\Integration\Support\DatabaseSideChannel;

/**
 * End-to-end coverage for the two `privacy_consents_*` tools
 * ({@see \Akeeba\Component\Mcp4j\Administrator\Tool\Privacy\Consents}).
 *
 * There is no `privacy_consents_create`/`_delete` tool (core has none either) — the fixture row is written
 * directly into `#__privacy_consents` via {@see DatabaseSideChannel} in {@see self::setUp()} and removed in
 * {@see self::tearDown()}, which is arranging/restoring state, not proving tool behaviour.
 *
 * `#__privacy_consents`'s column set (`id, user_id, state, created, subject, body, remind, token`) is
 * identical across Joomla 4.4/5.4/6.1 (verified with `SHOW COLUMNS` against all three local dev sites while
 * writing this test).
 */
class ConsentsTest extends AbstractToolTestCase
{
	private ?int $consentId = null;

	private string $consentToken = '';

	protected function setUp(): void
	{
		parent::setUp();

		if (!DatabaseSideChannel::isAvailable())
		{
			$this->markTestSkipped('DatabaseSideChannel is not available (JTEST_DB_* env vars or pdo_mysql missing).');
		}
	}

	protected function tearDown(): void
	{
		if ($this->consentId !== null)
		{
			DatabaseSideChannel::execute(
				'DELETE FROM ' . DatabaseSideChannel::table('#__privacy_consents') . ' WHERE id = :id',
				['id' => $this->consentId]
			);
			$this->consentId = null;
		}

		parent::tearDown();
	}

	/**
	 * @return int  The low-privilege user's numeric id.
	 */
	private function lowPrivUserId(): int
	{
		$row = DatabaseSideChannel::fetchOne(
			'SELECT id FROM ' . DatabaseSideChannel::table('#__users') . ' WHERE username = :username',
			['username' => 'lowpriv']
		);

		$this->assertIsArray($row, 'Could not find the low-privilege user row (has run-tests.sh provisioned it?).');

		return (int) $row['id'];
	}

	private function createConsentFixture(): void
	{
		$this->consentToken = 'mcp4j-secret-' . bin2hex(random_bytes(8));

		$table = DatabaseSideChannel::table('#__privacy_consents');

		DatabaseSideChannel::execute(
			"INSERT INTO {$table} (user_id, state, created, subject, body, remind, token) " .
			'VALUES (:user_id, :state, :created, :subject, :body, :remind, :token)',
			[
				'user_id' => $this->lowPrivUserId(),
				'state'   => 1,
				'created' => gmdate('Y-m-d H:i:s'),
				'subject' => 'PLG_SYSTEM_PRIVACYCONSENT_SUBJECT',
				'body'    => 'mcp4j',
				'remind'  => 0,
				'token'   => $this->consentToken,
			]
		);

		$row = DatabaseSideChannel::fetchOne(
			'SELECT id FROM ' . $table . ' WHERE token = :token',
			['token' => $this->consentToken]
		);

		$this->assertIsArray($row);
		$this->consentId = (int) $row['id'];
	}

	public function testListContainsTheFixtureWithoutTheToken(): void
	{
		$this->createConsentFixture();

		$listResult = $this->callTool('privacy_consents_list');
		$this->assertFalse($listResult['isError'], $listResult['text']);
		$this->assertListShape($listResult['data']);

		$found = null;

		foreach ($listResult['data']['items'] as $item)
		{
			if ((int) $item['id'] === $this->consentId)
			{
				$found = $item;
			}
		}

		$this->assertNotNull($found, 'The fixture consent must be present in privacy_consents_list.');
		$this->assertSame('lowpriv', $found['username']);
		$this->assertArrayNotHasKey('token', $found);

		// Belt-and-braces: the token must not appear anywhere in the raw JSON response text either.
		$this->assertStringNotContainsString($this->consentToken, $listResult['text']);
	}

	public function testReadByIdAndFilterState(): void
	{
		$this->createConsentFixture();

		$read = $this->callToolOk('privacy_consents_read', ['id' => $this->consentId]);
		$this->assertSame('lowpriv', $read['username']);
		$this->assertSame(1, $read['state']);
		$this->assertArrayNotHasKey('token', $read);

		// The fixture's state is 1 — filterState 0 must not find it.
		$filtered = $this->callToolOk('privacy_consents_list', ['filterState' => 0]);
		$this->assertNotContains($this->consentId, array_column($filtered['items'], 'id'));

		$this->assertNotFound($this->callTool('privacy_consents_read', ['id' => 999999]));
	}

	public function testLowPrivilegeUserIsRefused(): void
	{
		$this->createConsentFixture();

		$listResult = $this->callToolAsLowPriv('privacy_consents_list');
		$this->assertPermissionDenied($listResult);

		$readResult = $this->callToolAsLowPriv('privacy_consents_read', ['id' => $this->consentId]);
		$this->assertPermissionDenied($readResult);
	}
}
