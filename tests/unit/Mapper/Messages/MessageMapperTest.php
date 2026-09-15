<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Mapper\Messages;

use Akeeba\Component\Mcp4j\Administrator\Mapper\Messages\MessageMapper;
use PHPUnit\Framework\TestCase;

class MessageMapperTest extends TestCase
{
	// -----------------------------------------------------------------------------------------------------
	// withId()
	// -----------------------------------------------------------------------------------------------------

	public function testWithIdRenamesMessageIdToId(): void
	{
		$result = MessageMapper::withId(['message_id' => '42', 'subject' => 'Hi']);

		$this->assertSame(42, $result['id']);
		$this->assertArrayNotHasKey('message_id', $result);
		$this->assertSame('Hi', $result['subject']);
	}

	public function testWithIdCoercesToInt(): void
	{
		$result = MessageMapper::withId(['message_id' => '7']);

		$this->assertSame(7, $result['id']);
		$this->assertIsInt($result['id']);
	}

	public function testWithIdMissingMessageIdDefaultsToZero(): void
	{
		$result = MessageMapper::withId(['subject' => 'Hi']);

		$this->assertSame(0, $result['id']);
	}

	// -----------------------------------------------------------------------------------------------------
	// listState()
	// -----------------------------------------------------------------------------------------------------

	public function testListStateNullDefaultsToEmptyStringFilter(): void
	{
		$result = MessageMapper::listState(null);

		$this->assertSame('', $result['filter.state']);
	}

	public function testListStateStringifiesGivenState(): void
	{
		$this->assertSame('0', MessageMapper::listState(0)['filter.state']);
		$this->assertSame('1', MessageMapper::listState(1)['filter.state']);
		$this->assertSame('-2', MessageMapper::listState(-2)['filter.state']);
	}

	public function testListStateOrdersByDateTimeDescending(): void
	{
		$result = MessageMapper::listState(null);

		$this->assertSame('a.date_time', $result['list.ordering']);
		$this->assertSame('DESC', $result['list.direction']);
	}

	// -----------------------------------------------------------------------------------------------------
	// createData()
	// -----------------------------------------------------------------------------------------------------

	public function testCreateData(): void
	{
		$this->assertSame(
			['user_id_to' => 42, 'subject' => 'Subject', 'message' => 'Body'],
			MessageMapper::createData(42, 'Subject', 'Body')
		);
	}

	// -----------------------------------------------------------------------------------------------------
	// assertStateOnlyUpdate()
	// -----------------------------------------------------------------------------------------------------

	public function testAssertStateOnlyUpdateAcceptsAllNull(): void
	{
		MessageMapper::assertStateOnlyUpdate(null, null, null);

		$this->expectNotToPerformAssertions();
	}

	public function testAssertStateOnlyUpdateRejectsUserIdTo(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		MessageMapper::assertStateOnlyUpdate(5, null, null);
	}

	public function testAssertStateOnlyUpdateRejectsSubject(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		MessageMapper::assertStateOnlyUpdate(null, 'New subject', null);
	}

	public function testAssertStateOnlyUpdateRejectsMessage(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		MessageMapper::assertStateOnlyUpdate(null, null, 'New body');
	}

	public function testAssertStateOnlyUpdateExceptionMessage(): void
	{
		try
		{
			MessageMapper::assertStateOnlyUpdate(null, 'x', null);
			$this->fail('Expected an InvalidArgumentException.');
		}
		catch (\InvalidArgumentException $e)
		{
			$this->assertStringContainsString('cannot be edited', $e->getMessage());
		}
	}

	// -----------------------------------------------------------------------------------------------------
	// Field whitelists
	// -----------------------------------------------------------------------------------------------------

	public function testItemFieldsIncludesFromUserNameNotUserFrom(): void
	{
		$this->assertContains('from_user_name', MessageMapper::ITEM_FIELDS);
		$this->assertNotContains('user_from', MessageMapper::ITEM_FIELDS);
	}

	public function testListFieldsIncludesUserFromNotFromUserName(): void
	{
		$this->assertContains('user_from', MessageMapper::LIST_FIELDS);
		$this->assertNotContains('from_user_name', MessageMapper::LIST_FIELDS);
	}

	public function testFieldsExcludeEmailAddresses(): void
	{
		foreach (['email', 'user_email', 'from_email', 'to_email'] as $key)
		{
			$this->assertNotContains($key, MessageMapper::ITEM_FIELDS);
			$this->assertNotContains($key, MessageMapper::LIST_FIELDS);
		}
	}

	public function testIntFields(): void
	{
		$this->assertSame(['user_id_from', 'user_id_to', 'priority', 'state'], MessageMapper::INT_FIELDS);
	}
}
