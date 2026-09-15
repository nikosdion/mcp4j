<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Integration\Tests\Messages;

use Akeeba\Mcp4j\Tests\Integration\AbstractToolTestCase;

/**
 * End-to-end coverage for the six `messages_*` tools
 * ({@see \Akeeba\Component\Mcp4j\Administrator\Tool\Messages\Messages}). See
 * `.plans/11-overrides-redirects-messages.md` Part C for the coverage this class is meant to satisfy.
 *
 * Uses `users_list` (plan 05) to find the super user's and low-privilege user's ids — see
 * {@see self::resolveUserIds()}.
 */
class MessagesTest extends AbstractToolTestCase
{
	private static ?int $adminUserId = null;

	private static ?int $lowPrivUserId = null;

	private static bool $resolved = false;

	protected function setUp(): void
	{
		parent::setUp();

		$this->requireToolsOrSkip(['users_list']);
		$this->resolveUserIds();

		if (self::$adminUserId === null)
		{
			$this->markTestSkipped('Could not find the "admin" user via users_list.');
		}
	}

	public function testCreateToSelfListAndRead(): void
	{
		$subject = $this->uniqueName('MCP4j Message');

		$created = $this->createToAdminOrSkip($subject, 'Hello from the MCP4j E2E suite.');

		$id = $created['id'];
		$this->registerCleanup(fn () => $this->callTool('messages_delete', ['id' => $id]));

		$this->assertSame(self::$adminUserId, $created['user_id_from']);
		$this->assertSame(self::$adminUserId, $created['user_id_to']);
		$this->assertSame($subject, $created['subject']);

		$list = $this->callToolOk('messages_list');
		$this->assertListShape($list);
		$this->assertContains($id, array_column($list['items'], 'id'));

		$read = $this->callToolOk('messages_read', ['id' => $id]);
		$this->assertSame(1, $read['state'], 'Reading a message must mark it read (state = 1).');
		$this->assertNotSame('', $read['from_user_name']);
	}

	public function testUpdateStateAndRejectsFieldEdits(): void
	{
		$subject = $this->uniqueName('MCP4j Message State');

		$created = $this->createToAdminOrSkip($subject, 'Body');
		$id      = $created['id'];
		$this->registerCleanup(fn () => $this->callTool('messages_delete', ['id' => $id]));

		$this->callToolOk('messages_update', ['id' => $id, 'state' => 0]);

		$unread = $this->callToolOk('messages_list', ['filterState' => 0]);
		$this->assertContains($id, array_column($unread['items'], 'id'));

		$rejected = $this->callTool('messages_update', ['id' => $id, 'subject' => 'New subject']);
		$this->assertTrue($rejected['isError'], 'Expected a tool error: ' . $rejected['text']);
		$this->assertStringContainsString('cannot be edited', $rejected['text']);
	}

	public function testTrashAndDelete(): void
	{
		$subject = $this->uniqueName('MCP4j Message Trash');

		$created = $this->createToAdminOrSkip($subject, 'Body');
		$id      = $created['id'];
		$this->registerCleanup(fn () => $this->callTool('messages_delete', ['id' => $id]));

		$this->callToolOk('messages_trash', ['id' => $id]);

		$trashedList = $this->callToolOk('messages_list', ['filterState' => -2]);
		$this->assertContains($id, array_column($trashedList['items'], 'id'));

		$defaultList = $this->callToolOk('messages_list');
		$this->assertNotContains($id, array_column($defaultList['items'], 'id'), 'The default list must not include trashed messages.');

		$deleted = $this->callToolOk('messages_delete', ['id' => $id]);
		$this->assertSame(['deleted' => true, 'id' => $id], $deleted);

		$this->assertNotFound($this->callTool('messages_read', ['id' => $id]));
	}

	public function testCreateToLowPrivilegeUserIsRejected(): void
	{
		if (self::$lowPrivUserId === null)
		{
			$this->markTestSkipped('Could not find the "lowpriv" user via users_list.');
		}

		$result = $this->callTool('messages_create', [
			'userIdTo' => self::$lowPrivUserId,
			'subject'  => $this->uniqueName('MCP4j Message To LowPriv'),
			'message'  => 'Body',
		]);

		$this->assertTrue($result['isError'], 'Expected a tool error: ' . $result['text']);
	}

	public function testReadNonexistentIdIsNotFound(): void
	{
		$this->assertNotFound($this->callTool('messages_read', ['id' => 999999]));
	}

	public function testLowPrivilegeUserIsRefused(): void
	{
		$this->assertPermissionDenied($this->callToolAsLowPriv('messages_list'));
	}

	/**
	 * `MessageModel::save()` always tries to e-mail the recipient when their `mail_on_new` config is on
	 * (the default), and the Docker `php:*-apache` image this suite runs on has no MTA. On Joomla 5.4/6.x
	 * that failure is caught and only enqueues a warning (`save()` still returns true — see
	 * `Tool/Messages/Messages.php`'s class docblock and `.plans/11-overrides-redirects-messages.md` Part
	 * C's own footnote on this). **Joomla 4.4's `MessageModel::save()` does not have that fix**: it
	 * `return false`s on exactly the same `MailDisabledException`/PHPMailer exception, with the error
	 * `COM_MESSAGES_ERROR_MAIL_FAILED` ("The email could not be sent.") — a genuine Joomla-version
	 * behaviour difference, not a bug in this port, that makes `messages_create` uncreatable end-to-end on
	 * 4.4 in an MTA-less environment. Detected narrowly (by this exact error text) and skipped, rather
	 * than either silently passing a test that didn't actually exercise anything, or failing the suite for
	 * an environment limitation outside this plan's control (tests/integration/docker/** is out of scope
	 * to change — see `.plans/00-conventions.md` §8).
	 *
	 * @return  array<string, mixed>  The created message record.
	 */
	private function createToAdminOrSkip(string $subject, string $message): array
	{
		$result = $this->callTool('messages_create', [
			'userIdTo' => self::$adminUserId,
			'subject'  => $subject,
			'message'  => $message,
		]);

		if ($result['isError'] && str_contains($result['text'], 'email could not be sent'))
		{
			$this->markTestSkipped(
				'No MTA is available in this test environment, and this Joomla version (unlike 5.4/6.x) ' .
				'fails messages_create outright when the notification e-mail cannot be sent: ' . $result['text']
			);
		}

		$this->assertFalse($result['isError'], 'messages_create unexpectedly failed: ' . $result['text']);

		return $result['data'];
	}

	private function resolveUserIds(): void
	{
		if (self::$resolved)
		{
			return;
		}

		self::$resolved = true;

		$admin = $this->callToolOk('users_list', ['filterSearch' => 'admin', 'pageLimit' => 100]);

		foreach ($admin['items'] as $item)
		{
			if (($item['username'] ?? null) === 'admin')
			{
				self::$adminUserId = (int) $item['id'];
				break;
			}
		}

		$lowPriv = $this->callToolOk('users_list', ['filterSearch' => 'lowpriv', 'pageLimit' => 100]);

		foreach ($lowPriv['items'] as $item)
		{
			if (($item['username'] ?? null) === 'lowpriv')
			{
				self::$lowPrivUserId = (int) $item['id'];
				break;
			}
		}
	}
}
