<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Mapper\Privacy;

use Akeeba\Component\Mcp4j\Administrator\Bridge\Support\OutputShaper;
use Akeeba\Component\Mcp4j\Administrator\Mapper\Privacy\ConsentMapper;
use PHPUnit\Framework\TestCase;

class ConsentMapperTest extends TestCase
{
	// -----------------------------------------------------------------------------------------------------
	// listState()
	// -----------------------------------------------------------------------------------------------------

	public function testListStateWithNullState(): void
	{
		$this->assertSame(
			[
				'filter.state'   => '',
				'filter.search'  => '',
				'filter.subject' => '',
				'list.ordering'  => 'a.created',
				'list.direction' => 'DESC',
			],
			ConsentMapper::listState(null)
		);
	}

	public function testListStateWithGivenState(): void
	{
		$this->assertSame(1, ConsentMapper::listState(1)['filter.state']);
		$this->assertSame(0, ConsentMapper::listState(0)['filter.state']);
	}

	// -----------------------------------------------------------------------------------------------------
	// readState()
	// -----------------------------------------------------------------------------------------------------

	public function testReadStateSetsIdSearchAndLeavesOtherKeysAsListState(): void
	{
		$result = ConsentMapper::readState(42);

		$this->assertSame('id:42', $result['filter.search']);
		$this->assertSame('', $result['filter.state']);
		$this->assertSame('', $result['filter.subject']);
		$this->assertSame('a.created', $result['list.ordering']);
		$this->assertSame('DESC', $result['list.direction']);
	}

	// -----------------------------------------------------------------------------------------------------
	// Field whitelists — token must never appear, even though the raw model row (`a.*`) carries it.
	// -----------------------------------------------------------------------------------------------------

	public function testFieldWhitelistsExactlyAndNeverContainToken(): void
	{
		$expected = ['id', 'user_id', 'username', 'name', 'state', 'created', 'subject', 'body', 'remind'];

		$this->assertSame($expected, ConsentMapper::ITEM_FIELDS);
		$this->assertSame($expected, ConsentMapper::LIST_FIELDS);
		$this->assertSame(['user_id', 'state', 'remind'], ConsentMapper::INT_FIELDS);

		$this->assertNotContains('token', ConsentMapper::ITEM_FIELDS);
		$this->assertNotContains('token', ConsentMapper::LIST_FIELDS);
	}

	public function testTokenIsDroppedByOutputShaperEvenWhenPresentInTheRawRow(): void
	{
		$row = [
			'id'      => '9',
			'user_id' => '3',
			'username' => 'lowpriv',
			'name'    => 'Low Priv',
			'state'   => '1',
			'created' => '2026-01-01 00:00:00',
			'subject' => 'PLG_SYSTEM_PRIVACYCONSENT_SUBJECT',
			'body'    => 'mcp4j',
			'remind'  => '0',
			'token'   => 'super-secret-token-value',
		];

		$shaped = OutputShaper::shape($row, ConsentMapper::ITEM_FIELDS, ConsentMapper::INT_FIELDS);

		$this->assertArrayNotHasKey('token', $shaped);
		$this->assertNotContains('super-secret-token-value', $shaped);
	}
}
