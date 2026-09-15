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
 * End-to-end coverage for the four `privacy_requests_*` tools
 * ({@see \Akeeba\Component\Mcp4j\Administrator\Tool\Privacy\Requests}).
 *
 * There is no `privacy_requests_delete` tool (core has none either) — fixtures are removed directly from
 * `#__privacy_requests` via {@see DatabaseSideChannel} in {@see self::tearDown()}, which is arranging/
 * restoring state, not proving tool behaviour (see that class's own docblock).
 */
class RequestsTest extends AbstractToolTestCase
{
	/** @var list<int> */
	private array $createdRequestIds = [];

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
		foreach ($this->createdRequestIds as $id)
		{
			DatabaseSideChannel::execute('DELETE FROM ' . DatabaseSideChannel::table('#__privacy_requests') . ' WHERE id = :id', ['id' => $id]);
		}

		$this->createdRequestIds = [];

		parent::tearDown();
	}

	private function uniqueEmail(): string
	{
		return 'mcp4j-' . bin2hex(random_bytes(6)) . '@example.com';
	}

	public function testCreateReadAndListRoundTrip(): void
	{
		$email = $this->uniqueEmail();

		$created = $this->callToolOk('privacy_requests_create', ['email' => $email, 'requestType' => 1]);
		$this->createdRequestIds[] = $created['id'];

		$this->assertSame(0, $created['status']);
		$this->assertSame('export', $created['request_type']);
		$this->assertSame($email, $created['email']);
		$this->assertIsBool($created['notification_sent'], 'notification_sent must always be a bool, even when the Docker test site has no MTA.');

		$read = $this->callToolOk('privacy_requests_read', ['id' => $created['id']]);
		$this->assertSame($email, $read['email']);
		$this->assertSame('export', $read['request_type']);
		$this->assertSame(0, $read['status']);
		$this->assertKeysAbsent($read, ['confirm_token', 'confirm_token_created_at']);

		$list = $this->callToolOk('privacy_requests_list', ['filterState' => 0]);
		$this->assertListShape($list);
		$this->assertContains($created['id'], array_column($list['items'], 'id'));
	}

	public function testDuplicateRequestInvalidTypeAndOwnEmailAreRejected(): void
	{
		$email = $this->uniqueEmail();

		$first = $this->callToolOk('privacy_requests_create', ['email' => $email, 'requestType' => 1]);
		$this->createdRequestIds[] = $first['id'];

		// Same email + same (active) request type: RequestModel::validate() refuses this.
		$duplicate = $this->callTool('privacy_requests_create', ['email' => $email, 'requestType' => 1]);
		$this->assertTrue($duplicate['isError']);

		$invalidType = $this->callTool('privacy_requests_create', ['email' => $this->uniqueEmail(), 'requestType' => 3]);
		$this->assertTrue($invalidType['isError']);
		$this->assertStringContainsString('Invalid request type', $invalidType['text']);

		// The E2E super user is admin@example.com — see tests/integration/run-tests.sh.
		$ownEmail = $this->callTool('privacy_requests_create', ['email' => 'admin@example.com', 'requestType' => 1]);
		$this->assertTrue($ownEmail['isError']);
	}

	public function testExportOfAnUnconfirmedRequestIsRejected(): void
	{
		$created = $this->callToolOk('privacy_requests_create', ['email' => $this->uniqueEmail(), 'requestType' => 1]);
		$this->createdRequestIds[] = $created['id'];

		$export = $this->callTool('privacy_requests_export', ['id' => $created['id']]);
		$this->assertTrue($export['isError']);
		$this->assertStringContainsString('Cannot export privacy request', $export['text']);
	}

	public function testExportOfAConfirmedRequestReturnsTheUsersData(): void
	{
		$created = $this->callToolOk('privacy_requests_create', ['email' => 'lowpriv@example.com', 'requestType' => 1]);
		$this->createdRequestIds[] = $created['id'];

		DatabaseSideChannel::execute(
			'UPDATE ' . DatabaseSideChannel::table('#__privacy_requests') . ' SET status = 1 WHERE id = :id',
			['id' => $created['id']]
		);

		$export = $this->callToolOk('privacy_requests_export', ['id' => $created['id']]);

		$this->assertSame($created['id'], $export['request_id']);
		$this->assertIsArray($export['domains']);
		$this->assertNotEmpty($export['domains']);

		$found = false;

		foreach ($export['domains'] as $domain)
		{
			foreach ($domain['items'] as $item)
			{
				foreach ($item['fields'] as $value)
				{
					if (is_string($value) && str_contains($value, 'lowpriv'))
					{
						$found = true;
					}
				}
			}
		}

		$this->assertTrue($found, 'Expected at least one exported field value to mention "lowpriv".');
	}

	public function testLowPrivilegeUserIsRefused(): void
	{
		$listResult = $this->callToolAsLowPriv('privacy_requests_list');
		$this->assertPermissionDenied($listResult);

		$createResult = $this->callToolAsLowPriv('privacy_requests_create', ['email' => $this->uniqueEmail(), 'requestType' => 1]);
		$this->assertPermissionDenied($createResult);

		$exportResult = $this->callToolAsLowPriv('privacy_requests_export', ['id' => 1]);
		$this->assertPermissionDenied($exportResult);
	}
}
