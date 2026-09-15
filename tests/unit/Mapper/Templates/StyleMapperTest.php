<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Mapper\Templates;

use Akeeba\Component\Mcp4j\Administrator\Mapper\Templates\StyleMapper;
use PHPUnit\Framework\TestCase;

class StyleMapperTest extends TestCase
{
	// -----------------------------------------------------------------------------------------------------
	// listState()
	// -----------------------------------------------------------------------------------------------------

	public function testListStateWithEverythingNullOnlySetsClientAndOrdering(): void
	{
		$result = StyleMapper::listState(0, null, null);

		$this->assertSame(
			[
				'client_id'      => 0,
				'list.ordering'  => 'a.template',
				'list.direction' => 'ASC',
			],
			$result
		);
	}

	public function testListStateClientIdIsAlwaysAnInt(): void
	{
		$this->assertSame(1, StyleMapper::listState(1, null, null)['client_id']);
	}

	public function testListStateSearchIsSetWhenGiven(): void
	{
		$this->assertSame('foo', StyleMapper::listState(0, 'foo', null)['filter.search']);
	}

	public function testListStateSearchIsAbsentWhenNull(): void
	{
		$this->assertArrayNotHasKey('filter.search', StyleMapper::listState(0, null, null));
	}

	public function testListStateTemplateIsSetWhenGiven(): void
	{
		$this->assertSame('cassiopeia', StyleMapper::listState(0, null, 'cassiopeia')['filter.template']);
	}

	public function testListStateTemplateIsAbsentWhenNull(): void
	{
		$this->assertArrayNotHasKey('filter.template', StyleMapper::listState(0, null, null));
	}

	public function testListStateAlwaysOrdersByTemplateAscending(): void
	{
		$result = StyleMapper::listState(1, 'x', 'atum');

		$this->assertSame('a.template', $result['list.ordering']);
		$this->assertSame('ASC', $result['list.direction']);
	}

	// -----------------------------------------------------------------------------------------------------
	// createData()
	// -----------------------------------------------------------------------------------------------------

	public function testCreateDataWithMinimalArguments(): void
	{
		$result = StyleMapper::createData(0, 'My Style', 'cassiopeia', null, null, 0, '');

		$this->assertSame(
			[
				'client_id'   => 0,
				'title'       => 'My Style',
				'template'    => 'cassiopeia',
				'home'        => '0',
				'inheritable' => 0,
				'parent'      => '',
				'params'      => [],
			],
			$result
		);
	}

	public function testCreateDataHomeTrueBecomesStringOne(): void
	{
		$this->assertSame('1', StyleMapper::createData(0, 'T', 'cassiopeia', true, null, 0, '')['home']);
	}

	public function testCreateDataHomeFalseBecomesStringZero(): void
	{
		$this->assertSame('0', StyleMapper::createData(0, 'T', 'cassiopeia', false, null, 0, '')['home']);
	}

	public function testCreateDataHomeNullBecomesStringZero(): void
	{
		$this->assertSame('0', StyleMapper::createData(0, 'T', 'cassiopeia', null, null, 0, '')['home']);
	}

	public function testCreateDataInheritableAndParentAreCopiedThrough(): void
	{
		$result = StyleMapper::createData(1, 'T', 'atum', null, null, 1, 'atum');

		$this->assertSame(1, $result['inheritable']);
		$this->assertSame('atum', $result['parent']);
	}

	public function testCreateDataParamsIncludedWhenGiven(): void
	{
		$result = StyleMapper::createData(0, 'T', 'cassiopeia', null, ['brand' => '1'], 0, '');

		$this->assertSame(['brand' => '1'], $result['params']);
	}

	public function testCreateDataParamsDefaultsToEmptyArrayWhenNull(): void
	{
		// params has no DB column default and must always be present, even empty — see the mapper's
		// own docblock.
		$result = StyleMapper::createData(0, 'T', 'cassiopeia', null, null, 0, '');

		$this->assertSame([], $result['params']);
	}

	// -----------------------------------------------------------------------------------------------------
	// updateData()
	// -----------------------------------------------------------------------------------------------------

	public function testUpdateDataWithEverythingNullReturnsEmptyArray(): void
	{
		$this->assertSame([], StyleMapper::updateData(null, null, null));
	}

	public function testUpdateDataKeepsOnlyTitleWhenOnlyTitleGiven(): void
	{
		$this->assertSame(['title' => 'New title'], StyleMapper::updateData('New title', null, null));
	}

	public function testUpdateDataHomeTrueBecomesStringOne(): void
	{
		$this->assertSame(['home' => '1'], StyleMapper::updateData(null, true, null));
	}

	public function testUpdateDataHomeFalseBecomesStringZeroNotOmitted(): void
	{
		// false is a deliberate value ("unset default"), distinct from null ("don't change") — must not be
		// dropped like NullFilter would drop it.
		$this->assertSame(['home' => '0'], StyleMapper::updateData(null, false, null));
	}

	public function testUpdateDataParamsIncludedWhenGiven(): void
	{
		$this->assertSame(['params' => ['a' => 1]], StyleMapper::updateData(null, null, ['a' => 1]));
	}

	public function testUpdateDataEveryFieldTogether(): void
	{
		$result = StyleMapper::updateData('Title', true, ['a' => 1]);

		$this->assertSame(
			[
				'title'  => 'Title',
				'home'   => '1',
				'params' => ['a' => 1],
			],
			$result
		);
	}

	public function testUpdateDataNeverSetsAssignedOrTemplate(): void
	{
		$result = StyleMapper::updateData('Title', true, ['a' => 1]);

		$this->assertArrayNotHasKey('assigned', $result);
		$this->assertArrayNotHasKey('template', $result);
	}

	// -----------------------------------------------------------------------------------------------------
	// Field whitelists — hard-coded so a future edit is deliberate.
	// -----------------------------------------------------------------------------------------------------

	public function testItemFieldsMatchesPlan(): void
	{
		$this->assertSame(
			['id', 'template', 'client_id', 'home', 'title', 'inheritable', 'parent', 'params'],
			StyleMapper::ITEM_FIELDS
		);
	}

	public function testListFieldsMatchesPlan(): void
	{
		$this->assertSame(
			['id', 'template', 'title', 'home', 'client_id', 'language_title', 'image', 'language_sef', 'assigned', 'e_id'],
			StyleMapper::LIST_FIELDS
		);
	}

	public function testItemFieldsExcludesXmlAndSensitiveKeys(): void
	{
		$excluded = ['xml', 'password', 'otpKey', 'otep', 'activation'];

		foreach ($excluded as $key)
		{
			$this->assertNotContains($key, StyleMapper::ITEM_FIELDS);
			$this->assertNotContains($key, StyleMapper::LIST_FIELDS);
		}
	}

	public function testHomeIsNotInIntFields(): void
	{
		$this->assertNotContains('home', StyleMapper::INT_FIELDS);
	}

	public function testIntFieldsAndJsonFields(): void
	{
		$this->assertSame(['client_id', 'inheritable', 'assigned', 'e_id'], StyleMapper::INT_FIELDS);
		$this->assertSame(['params'], StyleMapper::JSON_FIELDS);
	}
}
