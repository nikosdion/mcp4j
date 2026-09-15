<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Mapper\Privacy;

use Akeeba\Component\Mcp4j\Administrator\Mapper\Privacy\RequestMapper;
use PHPUnit\Framework\TestCase;

class RequestMapperTest extends TestCase
{
	// -----------------------------------------------------------------------------------------------------
	// requestTypeFromInt()
	// -----------------------------------------------------------------------------------------------------

	public function testRequestTypeFromIntOneIsExport(): void
	{
		$this->assertSame('export', RequestMapper::requestTypeFromInt(1));
	}

	public function testRequestTypeFromIntTwoIsRemove(): void
	{
		$this->assertSame('remove', RequestMapper::requestTypeFromInt(2));
	}

	public function testRequestTypeFromIntZeroIsRejected(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid request type: use 1 (export) or 2 (removal)');

		RequestMapper::requestTypeFromInt(0);
	}

	public function testRequestTypeFromIntThreeIsRejected(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		RequestMapper::requestTypeFromInt(3);
	}

	// -----------------------------------------------------------------------------------------------------
	// listState()
	// -----------------------------------------------------------------------------------------------------

	public function testListStateWithNullStatus(): void
	{
		$result = RequestMapper::listState(null);

		$this->assertSame(
			[
				'filter.status'       => '',
				'filter.request_type' => '',
				'filter.search'       => '',
				'list.ordering'       => 'a.requested_at',
				'list.direction'      => 'DESC',
			],
			$result
		);
	}

	public function testListStateWithGivenStatus(): void
	{
		$this->assertSame(0, RequestMapper::listState(0)['filter.status']);
		$this->assertSame(1, RequestMapper::listState(1)['filter.status']);
	}

	// -----------------------------------------------------------------------------------------------------
	// Field whitelists
	// -----------------------------------------------------------------------------------------------------

	public function testFieldWhitelistsExcludeTheConfirmationToken(): void
	{
		$this->assertSame(['id', 'email', 'requested_at', 'status', 'request_type'], RequestMapper::ITEM_FIELDS);
		$this->assertSame(RequestMapper::ITEM_FIELDS, RequestMapper::LIST_FIELDS);
		$this->assertSame(['status'], RequestMapper::INT_FIELDS);

		foreach (['confirm_token', 'confirm_token_created_at'] as $key)
		{
			$this->assertNotContains($key, RequestMapper::ITEM_FIELDS);
		}
	}
}
