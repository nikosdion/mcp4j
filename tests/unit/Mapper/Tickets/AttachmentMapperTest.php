<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Mapper\Tickets;

use Akeeba\Component\Mcp4j\Administrator\Mapper\Tickets\AttachmentMapper;
use PHPUnit\Framework\TestCase;

class AttachmentMapperTest extends TestCase
{
	public function testListFields(): void
	{
		$this->assertSame(
			[
				'id',
				'post_id',
				'original_filename',
				'mime_type',
				'origin',
				'created',
				'created_by',
				'enabled',
				'created_by_info',
			],
			AttachmentMapper::LIST_FIELDS
		);
	}

	public function testItemFields(): void
	{
		$this->assertSame(
			[
				'id',
				'post_id',
				'original_filename',
				'mime_type',
				'origin',
				'created',
				'created_by',
				'enabled',
				'file_size',
				'created_by_info',
			],
			AttachmentMapper::ITEM_FIELDS
		);
	}

	public function testIntFields(): void
	{
		$this->assertSame(['post_id', 'created_by', 'enabled', 'file_size'], AttachmentMapper::INT_FIELDS);
	}

	public function testFieldsExcludeMangledFilename(): void
	{
		$this->assertNotContains('mangled_filename', AttachmentMapper::LIST_FIELDS);
		$this->assertNotContains('mangled_filename', AttachmentMapper::ITEM_FIELDS);
	}

	public function testSensitiveListsMangledFilename(): void
	{
		$this->assertSame(['mangled_filename'], AttachmentMapper::SENSITIVE);
	}
}
