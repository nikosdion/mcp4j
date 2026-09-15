<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Mapper\Tickets;

use Akeeba\Component\Mcp4j\Administrator\Mapper\Tickets\TicketCategoryMapper;
use PHPUnit\Framework\TestCase;

class TicketCategoryMapperTest extends TestCase
{
	public function testListStateWithNullSearch(): void
	{
		$result = TicketCategoryMapper::listState(null);

		$this->assertSame(
			[
				'filter.extension' => 'com_ats',
				'filter.component' => 'com_ats',
				'filter.section'   => null,
				'filter.published' => '',
				'list.ordering'    => 'a.lft',
				'list.direction'   => 'ASC',
			],
			$result
		);
	}

	public function testListStateWithSearch(): void
	{
		$result = TicketCategoryMapper::listState('support');

		$this->assertSame('support', $result['filter.search']);
	}

	public function testListStateSearchAbsentWhenNull(): void
	{
		$result = TicketCategoryMapper::listState(null);

		$this->assertArrayNotHasKey('filter.search', $result);
	}

	public function testItemFieldsMatchesCoreJsonapiView(): void
	{
		$this->assertSame(
			[
				'id',
				'title',
				'alias',
				'note',
				'published',
				'access',
				'checked_out',
				'checked_out_time',
				'created_user_id',
				'parent_id',
				'level',
				'extension',
				'lft',
				'rgt',
				'language',
				'language_title',
				'language_image',
				'editor',
				'access_level',
				'author_name',
				'count_trashed',
				'count_unpublished',
				'count_published',
				'count_archived',
				'params',
				'description',
			],
			TicketCategoryMapper::ITEM_FIELDS
		);
	}

	public function testListFieldsIsItemFieldsMinusExtension(): void
	{
		$this->assertSame(
			array_values(array_diff(TicketCategoryMapper::ITEM_FIELDS, ['extension'])),
			TicketCategoryMapper::LIST_FIELDS
		);
	}

	public function testFieldsExcludeSensitiveKeys(): void
	{
		foreach (['password', 'otpKey', 'otep', 'activation', 'asset_id'] as $key)
		{
			$this->assertNotContains($key, TicketCategoryMapper::ITEM_FIELDS);
			$this->assertNotContains($key, TicketCategoryMapper::LIST_FIELDS);
		}
	}

	public function testIntAndJsonFields(): void
	{
		$this->assertSame(
			[
				'published',
				'access',
				'checked_out',
				'created_user_id',
				'parent_id',
				'level',
				'lft',
				'rgt',
				'count_trashed',
				'count_unpublished',
				'count_published',
				'count_archived',
			],
			TicketCategoryMapper::INT_FIELDS
		);

		$this->assertSame(['params'], TicketCategoryMapper::JSON_FIELDS);
	}
}
