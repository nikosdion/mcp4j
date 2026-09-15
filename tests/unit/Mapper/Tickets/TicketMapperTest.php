<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Mapper\Tickets;

use Akeeba\Component\Mcp4j\Administrator\Mapper\Tickets\TicketMapper;
use PHPUnit\Framework\TestCase;

class TicketMapperTest extends TestCase
{
	// -----------------------------------------------------------------------------------------------------
	// listState()
	// -----------------------------------------------------------------------------------------------------

	public function testListStateWithEverythingNullIsEmpty(): void
	{
		$this->assertSame([], TicketMapper::listState(null, null, null, null, null, null, null));
	}

	public function testListStateSearch(): void
	{
		$result = TicketMapper::listState('title:foo', null, null, null, null, null, null);

		$this->assertSame('title:foo', $result['filter.search']);
	}

	public function testListStateCategory(): void
	{
		$result = TicketMapper::listState(null, 5, null, null, null, null, null);

		$this->assertSame(5, $result['filter.catid']);
	}

	public function testListStateStatus(): void
	{
		$result = TicketMapper::listState(null, null, 'O', null, null, null, null);

		$this->assertSame('O', $result['filter.status']);
	}

	public function testListStateUserMapsToCreatedBy(): void
	{
		$result = TicketMapper::listState(null, null, null, 12, null, null, null);

		$this->assertSame(12, $result['filter.created_by']);
		$this->assertArrayNotHasKey('filter.user', $result);
	}

	public function testListStatePriority(): void
	{
		$result = TicketMapper::listState(null, null, null, null, 3, null, null);

		$this->assertSame(3, $result['filter.priority']);
	}

	public function testListStateOrderByKnownColumn(): void
	{
		$result = TicketMapper::listState(null, null, null, null, null, 'title', null);

		$this->assertSame('title', $result['list.ordering']);
	}

	public function testListStateOrderByCreatedOnMapsToCreated(): void
	{
		$result = TicketMapper::listState(null, null, null, null, null, 'created_on', null);

		$this->assertSame('created', $result['list.ordering']);
	}

	public function testListStateOrderByModifiedOnMapsToModified(): void
	{
		$result = TicketMapper::listState(null, null, null, null, null, 'modified_on', null);

		$this->assertSame('modified', $result['list.ordering']);
	}

	public function testListStateOrderByUnknownColumnThrows(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid orderBy: password');

		TicketMapper::listState(null, null, null, null, null, 'password', null);
	}

	public function testListStateOrderDirectionAscDesc(): void
	{
		$this->assertSame('ASC', TicketMapper::listState(null, null, null, null, null, null, 'asc')['list.direction']);
		$this->assertSame('DESC', TicketMapper::listState(null, null, null, null, null, null, 'DESC')['list.direction']);
	}

	public function testListStateOrderDirectionInvalidThrows(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid orderDirection: sideways');

		TicketMapper::listState(null, null, null, null, null, null, 'sideways');
	}

	public function testOrderByMappingIsComplete(): void
	{
		$this->assertSame(
			[
				'id'          => 'id',
				'title'       => 'title',
				'status'      => 'status',
				'priority'    => 'priority',
				'catid'       => 'catid',
				'created_by'  => 'created_by',
				'created_on'  => 'created',
				'modified_on' => 'modified',
			],
			TicketMapper::ORDER_BY
		);
	}

	// -----------------------------------------------------------------------------------------------------
	// createTicketData()
	// -----------------------------------------------------------------------------------------------------

	public function testCreateTicketDataDefaults(): void
	{
		$result = TicketMapper::createTicketData(3, 'My ticket', null, null, null);

		$this->assertSame(3, $result['catid']);
		$this->assertSame('My ticket', $result['title']);
		$this->assertSame(5, $result['priority'], 'priority must default to 5 (Normal).');
		$this->assertSame(1, $result['public'], 'public must default to 1.');
		$this->assertSame('web', $result['origin']);
		$this->assertArrayNotHasKey('created_by', $result);
	}

	public function testCreateTicketDataTitleIsStripped(): void
	{
		$result = TicketMapper::createTicketData(3, '<b>bold</b> title', null, null, null);

		$this->assertSame('bold title', $result['title']);
	}

	public function testCreateTicketDataExplicitOverrides(): void
	{
		$result = TicketMapper::createTicketData(3, 'Title', 0, 0, 9);

		$this->assertSame(0, $result['priority']);
		$this->assertSame(0, $result['public']);
		$this->assertSame(9, $result['created_by']);
	}

	// -----------------------------------------------------------------------------------------------------
	// firstPostData() (delegates to PostMapper::createData())
	// -----------------------------------------------------------------------------------------------------

	public function testFirstPostData(): void
	{
		$result = TicketMapper::firstPostData(10, '<p>Hi</p>', null);

		$this->assertSame(
			['ticket_id' => 10, 'content_html' => '<p>Hi</p>', 'origin' => 'web', 'enabled' => 1],
			$result
		);
	}

	public function testFirstPostDataWithCreatedBy(): void
	{
		$result = TicketMapper::firstPostData(10, '<p>Hi</p>', 4);

		$this->assertSame(4, $result['created_by']);
	}

	// -----------------------------------------------------------------------------------------------------
	// updateTicketData()
	// -----------------------------------------------------------------------------------------------------

	public function testUpdateTicketDataAllNullIsEmpty(): void
	{
		$this->assertSame([], TicketMapper::updateTicketData(null, null, null));
	}

	public function testUpdateTicketDataTitleOnly(): void
	{
		$result = TicketMapper::updateTicketData('<i>New</i> title', null, null);

		$this->assertSame(['title' => 'New title'], $result);
	}

	public function testUpdateTicketDataStatusOnly(): void
	{
		$this->assertSame(['status' => 'C'], TicketMapper::updateTicketData(null, 'C', null));
	}

	public function testUpdateTicketDataCreatedByOnly(): void
	{
		$this->assertSame(['created_by' => 5], TicketMapper::updateTicketData(null, null, 5));
	}

	public function testUpdateTicketDataAllFields(): void
	{
		$result = TicketMapper::updateTicketData('Title', 'P', 2);

		$this->assertSame(['title' => 'Title', 'status' => 'P', 'created_by' => 2], $result);
	}

	// -----------------------------------------------------------------------------------------------------
	// mergeComFields()
	// -----------------------------------------------------------------------------------------------------

	public function testMergeComFieldsNullIncomingKeepsExisting(): void
	{
		$result = TicketMapper::mergeComFields(['a' => '1', 'b' => '2'], null);

		$this->assertSame(['a' => '1', 'b' => '2'], $result);
	}

	public function testMergeComFieldsTopLevelReplaceNotRecursive(): void
	{
		// A multi-value field's new list must wholesale replace the old one.
		$existing = ['tags' => ['x', 'y', 'z']];
		$incoming = ['tags' => ['x']];

		$result = TicketMapper::mergeComFields($existing, $incoming);

		$this->assertSame(['tags' => ['x']], $result);
	}

	public function testMergeComFieldsAddsNewKeys(): void
	{
		$result = TicketMapper::mergeComFields(['a' => '1'], ['b' => '2']);

		$this->assertSame(['a' => '1', 'b' => '2'], $result);
	}

	public function testMergeComFieldsOverwritesExistingKey(): void
	{
		$result = TicketMapper::mergeComFields(['a' => '1'], ['a' => '2']);

		$this->assertSame(['a' => '2'], $result);
	}

	public function testMergeComFieldsDropsNullValues(): void
	{
		$result = TicketMapper::mergeComFields(['a' => '1', 'b' => '2'], ['b' => null]);

		$this->assertSame(['a' => '1'], $result);
	}

	// -----------------------------------------------------------------------------------------------------
	// timestampColumns()
	// -----------------------------------------------------------------------------------------------------

	public function testTimestampColumnsAllNull(): void
	{
		$this->assertSame(
			['created' => null, 'modified' => null, 'modified_by' => null],
			TicketMapper::timestampColumns(null, null, null)
		);
	}

	public function testTimestampColumnsGiven(): void
	{
		$result = TicketMapper::timestampColumns('2020-01-02T03:04:05Z', '2020-01-03T00:00:00Z', 7);

		$this->assertSame(
			['created' => '2020-01-02 03:04:05', 'modified' => '2020-01-03 00:00:00', 'modified_by' => 7],
			$result
		);
	}

	public function testTimestampColumnsInvalidThrows(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		TicketMapper::timestampColumns('nope', null, null);
	}

	// -----------------------------------------------------------------------------------------------------
	// Field whitelists — hard-coded so a future edit is deliberate.
	// -----------------------------------------------------------------------------------------------------

	public function testListFields(): void
	{
		$this->assertSame(
			[
				'id',
				'catid',
				'status',
				'title',
				'alias',
				'public',
				'priority',
				'origin',
				'assigned_to',
				'timespent',
				'created',
				'created_by',
				'modified',
				'enabled',
				'language',
				'tags',
				'created_by_info',
				'assigned_to_info',
				'com_fields',
			],
			TicketMapper::LIST_FIELDS
		);
	}

	public function testItemFields(): void
	{
		$this->assertSame(
			[
				'id',
				'catid',
				'status',
				'title',
				'alias',
				'public',
				'priority',
				'origin',
				'assigned_to',
				'timespent',
				'created',
				'created_by',
				'modified',
				'modified_by',
				'enabled',
				'params',
				'language',
				'tags',
				'created_by_info',
				'modified_by_info',
				'assigned_to_info',
				'com_fields',
				'posts',
			],
			TicketMapper::ITEM_FIELDS
		);
	}

	public function testFieldsExcludeSensitiveKeys(): void
	{
		foreach (['password', 'otpKey', 'otep', 'activation', 'requireReset'] as $key)
		{
			$this->assertNotContains($key, TicketMapper::ITEM_FIELDS);
			$this->assertNotContains($key, TicketMapper::LIST_FIELDS);
		}
	}

	public function testIntAndJsonFields(): void
	{
		$this->assertSame(
			['catid', 'public', 'priority', 'assigned_to', 'created_by', 'modified_by', 'enabled'],
			TicketMapper::INT_FIELDS
		);

		$this->assertSame(['params'], TicketMapper::JSON_FIELDS);
		$this->assertNotContains('status', TicketMapper::INT_FIELDS, 'status stays a string (O/P/C or a custom 1-99).');
	}
}
