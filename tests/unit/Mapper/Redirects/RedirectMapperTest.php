<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Mapper\Redirects;

use Akeeba\Component\Mcp4j\Administrator\Mapper\Redirects\RedirectMapper;
use PHPUnit\Framework\TestCase;

class RedirectMapperTest extends TestCase
{
	// -----------------------------------------------------------------------------------------------------
	// listState()
	// -----------------------------------------------------------------------------------------------------

	public function testListStateWithEverythingNullDefaultsFilterStateToEmptyString(): void
	{
		$result = RedirectMapper::listState(null, null);

		$this->assertSame(
			[
				'list.ordering'  => 'a.old_url',
				'list.direction' => 'ASC',
				'filter.state'   => '',
			],
			$result
		);
	}

	public function testListStateSearchIsSetWhenGiven(): void
	{
		$result = RedirectMapper::listState('/old-url', null);

		$this->assertSame('/old-url', $result['filter.search']);
	}

	public function testListStateSearchIsAbsentWhenNull(): void
	{
		$result = RedirectMapper::listState(null, null);

		$this->assertArrayNotHasKey('filter.search', $result);
	}

	public function testListStateFilterStateIsStringifiedWhenGiven(): void
	{
		$this->assertSame('0', RedirectMapper::listState(null, 0)['filter.state']);
		$this->assertSame('1', RedirectMapper::listState(null, 1)['filter.state']);
		$this->assertSame('-2', RedirectMapper::listState(null, -2)['filter.state']);
	}

	public function testListStateAlwaysOrdersByOldUrlAscending(): void
	{
		$result = RedirectMapper::listState('x', 1);

		$this->assertSame('a.old_url', $result['list.ordering']);
		$this->assertSame('ASC', $result['list.direction']);
	}

	// -----------------------------------------------------------------------------------------------------
	// createData()
	// -----------------------------------------------------------------------------------------------------

	public function testCreateDataStoresStatusCodeInHeaderNeverReferer(): void
	{
		$result = RedirectMapper::createData('/old', '/new', 302, 1, null);

		$this->assertSame(302, $result['header']);
		$this->assertArrayNotHasKey('referer', $result);
	}

	public function testCreateDataDefaultsStatusCodeTo301(): void
	{
		$result = RedirectMapper::createData('/old', '/new', null, 1, null);

		$this->assertSame(301, $result['header']);
	}

	public function testCreateDataIncludesOldNewUrlAndPublished(): void
	{
		$result = RedirectMapper::createData('/old', '/new', 301, 0, null);

		$this->assertSame('/old', $result['old_url']);
		$this->assertSame('/new', $result['new_url']);
		$this->assertSame(0, $result['published']);
	}

	public function testCreateDataCommentOmittedWhenNull(): void
	{
		$result = RedirectMapper::createData('/old', '/new', 301, 1, null);

		$this->assertArrayNotHasKey('comment', $result);
	}

	public function testCreateDataCommentIncludedWhenGiven(): void
	{
		$result = RedirectMapper::createData('/old', '/new', 301, 1, 'A comment');

		$this->assertSame('A comment', $result['comment']);
	}

	// -----------------------------------------------------------------------------------------------------
	// updateData()
	// -----------------------------------------------------------------------------------------------------

	public function testUpdateDataWithEverythingNullReturnsEmptyArray(): void
	{
		$this->assertSame([], RedirectMapper::updateData(null, null, null, null, null));
	}

	public function testUpdateDataOmitsNulls(): void
	{
		$result = RedirectMapper::updateData(null, null, null, null, 'Only a comment');

		$this->assertSame(['comment' => 'Only a comment'], $result);
	}

	public function testUpdateDataStatusCodeMapsToHeaderNeverReferer(): void
	{
		$result = RedirectMapper::updateData(null, null, 307, null, null);

		$this->assertSame(['header' => 307], $result);
		$this->assertArrayNotHasKey('referer', $result);
	}

	public function testUpdateDataPublishedZeroIsKeptNotTreatedAsNull(): void
	{
		$result = RedirectMapper::updateData(null, null, null, 0, null);

		$this->assertSame(['published' => 0], $result);
	}

	public function testUpdateDataEveryFieldTogether(): void
	{
		$result = RedirectMapper::updateData('/old', '/new', 303, 1, 'A comment');

		$this->assertSame(
			[
				'old_url'   => '/old',
				'new_url'   => '/new',
				'header'    => 303,
				'published' => 1,
				'comment'   => 'A comment',
			],
			$result
		);
	}

	// -----------------------------------------------------------------------------------------------------
	// Field whitelists
	// -----------------------------------------------------------------------------------------------------

	public function testItemFieldsAndListFieldsAreTheSame(): void
	{
		$this->assertSame(RedirectMapper::ITEM_FIELDS, RedirectMapper::LIST_FIELDS);
		$this->assertSame(
			['id', 'old_url', 'new_url', 'referer', 'comment', 'hits', 'published', 'created_date', 'modified_date', 'header'],
			RedirectMapper::ITEM_FIELDS
		);
	}

	public function testIntFields(): void
	{
		$this->assertSame(['hits', 'published', 'header'], RedirectMapper::INT_FIELDS);
	}
}
