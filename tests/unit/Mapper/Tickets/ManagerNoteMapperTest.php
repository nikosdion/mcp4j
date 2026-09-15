<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Mapper\Tickets;

use Akeeba\Component\Mcp4j\Administrator\Mapper\Tickets\ManagerNoteMapper;
use PHPUnit\Framework\TestCase;

class ManagerNoteMapperTest extends TestCase
{
	// -----------------------------------------------------------------------------------------------------
	// createData()
	// -----------------------------------------------------------------------------------------------------

	public function testCreateData(): void
	{
		$result = ManagerNoteMapper::createData(42, '<p>Internal note</p>');

		$this->assertSame(
			[
				'ticket_id' => 42,
				'note_html' => '<p>Internal note</p>',
				'enabled'   => 1,
			],
			$result
		);
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
				'note_html',
				'created',
				'created_by',
				'modified',
				'enabled',
				'created_by_info',
			],
			ManagerNoteMapper::LIST_FIELDS
		);
	}

	public function testItemFields(): void
	{
		$this->assertSame(
			[
				'id',
				'ticket_id',
				'note_html',
				'created',
				'created_by',
				'modified',
				'modified_by',
				'enabled',
				'created_by_info',
				'modified_by_info',
			],
			ManagerNoteMapper::ITEM_FIELDS
		);
	}

	public function testIntFields(): void
	{
		$this->assertSame(['ticket_id', 'created_by', 'modified_by', 'enabled'], ManagerNoteMapper::INT_FIELDS);
	}
}
