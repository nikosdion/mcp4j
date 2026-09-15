<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Mapper\Tickets;

use Akeeba\Component\Mcp4j\Administrator\Mapper\Tickets\PostMapper;
use PHPUnit\Framework\TestCase;

class PostMapperTest extends TestCase
{
	// -----------------------------------------------------------------------------------------------------
	// createData()
	// -----------------------------------------------------------------------------------------------------

	public function testCreateDataWithoutCreatedBy(): void
	{
		$result = PostMapper::createData(42, '<p>Hello</p>', null);

		$this->assertSame(
			[
				'ticket_id'    => 42,
				'content_html' => '<p>Hello</p>',
				'origin'       => 'web',
				'enabled'      => 1,
			],
			$result
		);
	}

	public function testCreateDataWithCreatedBy(): void
	{
		$result = PostMapper::createData(42, '<p>Hello</p>', 7);

		$this->assertSame(7, $result['created_by']);
	}

	// -----------------------------------------------------------------------------------------------------
	// updateData()
	// -----------------------------------------------------------------------------------------------------

	public function testUpdateDataWithoutCreatedBy(): void
	{
		$result = PostMapper::updateData('<p>Edited</p>', null);

		$this->assertSame(['content_html' => '<p>Edited</p>'], $result);
	}

	public function testUpdateDataWithCreatedBy(): void
	{
		$result = PostMapper::updateData('<p>Edited</p>', 9);

		$this->assertSame(['content_html' => '<p>Edited</p>', 'created_by' => 9], $result);
	}

	// -----------------------------------------------------------------------------------------------------
	// timestampColumns()
	// -----------------------------------------------------------------------------------------------------

	public function testTimestampColumnsAllNull(): void
	{
		$result = PostMapper::timestampColumns(null, null, null);

		$this->assertSame(['created' => null, 'modified' => null, 'modified_by' => null], $result);
	}

	public function testTimestampColumnsAllGiven(): void
	{
		$result = PostMapper::timestampColumns('2021-05-06T07:08:09Z', '2021-05-06T08:00:00Z', 3);

		$this->assertSame(
			['created' => '2021-05-06 07:08:09', 'modified' => '2021-05-06 08:00:00', 'modified_by' => 3],
			$result
		);
	}

	public function testTimestampColumnsInvalidCreatedThrows(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		PostMapper::timestampColumns('nope', null, null);
	}

	// -----------------------------------------------------------------------------------------------------
	// Field whitelists
	// -----------------------------------------------------------------------------------------------------

	public function testListFields(): void
	{
		$this->assertSame(
			[
				'id',
				'ticket_id',
				'content_html',
				'origin',
				'timespent',
				'created',
				'created_by',
				'modified',
				'enabled',
				'attachment_id',
				'created_by_info',
			],
			PostMapper::LIST_FIELDS
		);
	}

	public function testItemFields(): void
	{
		$this->assertSame(
			[
				'id',
				'ticket_id',
				'content_html',
				'origin',
				'timespent',
				'created',
				'created_by',
				'modified',
				'modified_by',
				'enabled',
				'attachment_id',
				'created_by_info',
				'modified_by_info',
			],
			PostMapper::ITEM_FIELDS
		);
	}

	public function testFieldsExcludeEmailUid(): void
	{
		$this->assertNotContains('email_uid', PostMapper::LIST_FIELDS);
		$this->assertNotContains('email_uid', PostMapper::ITEM_FIELDS);
	}

	public function testIntFields(): void
	{
		$this->assertSame(['ticket_id', 'created_by', 'modified_by', 'enabled'], PostMapper::INT_FIELDS);
	}
}
