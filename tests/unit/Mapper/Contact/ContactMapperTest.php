<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Mapper\Contact;

use Akeeba\Component\Mcp4j\Administrator\Mapper\Contact\ContactMapper;
use PHPUnit\Framework\TestCase;

class ContactMapperTest extends TestCase
{
	// -----------------------------------------------------------------------------------------------------
	// listState()
	// -----------------------------------------------------------------------------------------------------

	public function testListStateWithEverythingNullOnlySetsOrderingAndEmptyPublishedFilter(): void
	{
		$result = ContactMapper::listState(null, null, null, null);

		$this->assertSame(
			[
				'list.ordering'    => 'a.name',
				'list.direction'   => 'ASC',
				'filter.published' => '',
			],
			$result
		);
	}

	public function testListStateSearchIsSetOnlyWhenNonEmpty(): void
	{
		$this->assertSame('foo', ContactMapper::listState('foo', null, null, null)['filter.search']);
		$this->assertArrayNotHasKey('filter.search', ContactMapper::listState('', null, null, null));
		$this->assertArrayNotHasKey('filter.search', ContactMapper::listState(null, null, null, null));
	}

	public function testListStatePublishedIsStringifiedWhenGiven(): void
	{
		$this->assertSame('0', ContactMapper::listState(null, 0, null, null)['filter.published']);
		$this->assertSame('1', ContactMapper::listState(null, 1, null, null)['filter.published']);
		$this->assertSame('-2', ContactMapper::listState(null, -2, null, null)['filter.published']);
	}

	public function testListStateCategoryIsSetWhenGiven(): void
	{
		$this->assertSame(7, ContactMapper::listState(null, null, 7, null)['filter.category_id']);
	}

	public function testListStateCategoryIsAbsentWhenNull(): void
	{
		$this->assertArrayNotHasKey('filter.category_id', ContactMapper::listState(null, null, null, null));
	}

	public function testListStateLanguageIsSetWhenGiven(): void
	{
		$this->assertSame('en-GB', ContactMapper::listState(null, null, null, 'en-GB')['filter.language']);
	}

	public function testListStateLanguageIsAbsentWhenNull(): void
	{
		$this->assertArrayNotHasKey('filter.language', ContactMapper::listState(null, null, null, null));
	}

	// -----------------------------------------------------------------------------------------------------
	// createData()
	// -----------------------------------------------------------------------------------------------------

	private function createMinimal(array $overrides = []): array
	{
		$defaults = [
			'name'      => 'Jane Doe',
			'catId'     => 5,
			'alias'     => null,
			'published' => 1,
			'access'    => null,
			'language'  => '*',
			'email'     => null,
			'address'   => null,
			'suburb'    => null,
			'state'     => null,
			'country'   => null,
			'postcode'  => null,
			'telephone' => null,
			'fax'       => null,
			'webpage'   => null,
			'misc'      => null,
			'userId'    => null,
			'tags'      => null,
			'metadesc'  => null,
			'metakey'   => null,
		];

		$args = array_replace($defaults, $overrides);

		return ContactMapper::createData(...array_values($args));
	}

	public function testCreateDataMinimalOnlySetsNameCatidPublishedAccessLanguageTags(): void
	{
		$result = $this->createMinimal();

		$this->assertSame('Jane Doe', $result['name']);
		$this->assertSame(5, $result['catid']);
		$this->assertSame(1, $result['published']);
		$this->assertSame(1, $result['access'], 'access must default to 1 when null.');
		$this->assertSame('*', $result['language']);
		$this->assertSame([], $result['tags']);
		$this->assertArrayNotHasKey('alias', $result);
		$this->assertArrayNotHasKey('email_to', $result);
		$this->assertArrayNotHasKey('address', $result);
		$this->assertArrayNotHasKey('suburb', $result);
		$this->assertArrayNotHasKey('state', $result);
		$this->assertArrayNotHasKey('country', $result);
		$this->assertArrayNotHasKey('postcode', $result);
		$this->assertArrayNotHasKey('telephone', $result);
		$this->assertArrayNotHasKey('fax', $result);
		$this->assertArrayNotHasKey('webpage', $result);
		$this->assertArrayNotHasKey('misc', $result);
		$this->assertArrayNotHasKey('user_id', $result);
		$this->assertArrayNotHasKey('metadesc', $result);
		$this->assertArrayNotHasKey('metakey', $result);
	}

	public function testCreateDataAliasOnlyIncludedWhenNonEmpty(): void
	{
		$this->assertArrayNotHasKey('alias', $this->createMinimal(['alias' => null]));
		$this->assertArrayNotHasKey('alias', $this->createMinimal(['alias' => '']));
		$this->assertSame('jane-doe', $this->createMinimal(['alias' => 'jane-doe'])['alias']);
	}

	public function testCreateDataAccessDefaultsToOneWhenNull(): void
	{
		$this->assertSame(1, $this->createMinimal(['access' => null])['access']);
		$this->assertSame(3, $this->createMinimal(['access' => 3])['access']);
	}

	public function testCreateDataEmailMapsToEmailTo(): void
	{
		$result = $this->createMinimal(['email' => 'jane@example.com']);

		$this->assertSame('jane@example.com', $result['email_to']);
	}

	public function testCreateDataStateFieldIsTheAddressStateNotThePublishedField(): void
	{
		// Regression guard: "state" is the address province, never the publishing state.
		$result = $this->createMinimal(['state' => 'Attica', 'published' => 1]);

		$this->assertSame('Attica', $result['state']);
		$this->assertSame(1, $result['published']);
		$this->assertNotSame($result['state'], $result['published']);
	}

	public function testCreateDataAddressFieldsOnlySetWhenGiven(): void
	{
		$result = $this->createMinimal([
			'address'   => '1 Main St',
			'suburb'    => 'Downtown',
			'country'   => 'Greece',
			'postcode'  => '12345',
			'telephone' => '555-1234',
			'fax'       => '555-5678',
			'webpage'   => 'https://example.com',
			'misc'      => 'Some notes',
		]);

		$this->assertSame('1 Main St', $result['address']);
		$this->assertSame('Downtown', $result['suburb']);
		$this->assertSame('Greece', $result['country']);
		$this->assertSame('12345', $result['postcode']);
		$this->assertSame('555-1234', $result['telephone']);
		$this->assertSame('555-5678', $result['fax']);
		$this->assertSame('https://example.com', $result['webpage']);
		$this->assertSame('Some notes', $result['misc']);
	}

	public function testCreateDataUserIdOnlyWhenGiven(): void
	{
		$this->assertArrayNotHasKey('user_id', $this->createMinimal());
		$this->assertSame(42, $this->createMinimal(['userId' => 42])['user_id']);
	}

	public function testCreateDataMetaFieldsOnlyWhenGiven(): void
	{
		$result = $this->createMinimal(['metadesc' => 'desc', 'metakey' => 'a,b']);

		$this->assertSame('desc', $result['metadesc']);
		$this->assertSame('a,b', $result['metakey']);
	}

	public function testCreateDataTagsNormalisedOrEmpty(): void
	{
		$this->assertSame([], $this->createMinimal(['tags' => null])['tags']);
		$this->assertSame([3, 4], $this->createMinimal(['tags' => ['3', '4']])['tags']);
	}

	// -----------------------------------------------------------------------------------------------------
	// updateData()
	// -----------------------------------------------------------------------------------------------------

	private function updateFields(array $overrides, array $currentItem = []): array
	{
		$defaults = [
			'name'      => null,
			'catId'     => null,
			'alias'     => null,
			'published' => null,
			'access'    => null,
			'language'  => null,
			'email'     => null,
			'address'   => null,
			'suburb'    => null,
			'state'     => null,
			'country'   => null,
			'postcode'  => null,
			'telephone' => null,
			'fax'       => null,
			'webpage'   => null,
			'misc'      => null,
			'userId'    => null,
			'tags'      => null,
			'metadesc'  => null,
			'metakey'   => null,
		];

		$args = array_replace($defaults, $overrides);
		$args['currentItem'] = $currentItem;

		return ContactMapper::updateData(...array_values($args));
	}

	public function testUpdateDataAllNullProducesOnlyTags(): void
	{
		$result = $this->updateFields([], ['tags' => [1, 2]]);

		$this->assertSame(['tags' => [1, 2]], $result);
	}

	public function testUpdateDataOnlyTelephoneChangesOnlyThatKey(): void
	{
		$result = $this->updateFields(['telephone' => '555-0000'], ['tags' => []]);

		$this->assertSame(['telephone' => '555-0000', 'tags' => []], $result);
	}

	public function testUpdateDataStateFieldUpdatesAddressStateNotPublished(): void
	{
		$result = $this->updateFields(['state' => 'Attica'], []);

		$this->assertSame('Attica', $result['state']);
		$this->assertArrayNotHasKey('published', $result);
	}

	public function testUpdateDataEmailMapsToEmailTo(): void
	{
		$result = $this->updateFields(['email' => 'new@example.com'], []);

		$this->assertSame('new@example.com', $result['email_to']);
	}

	public function testUpdateDataTagsFallBackToCurrentItemWhenNull(): void
	{
		$result = $this->updateFields([], ['tags' => [5, 6]]);

		$this->assertSame([5, 6], $result['tags']);
	}

	public function testUpdateDataTagsUseGivenValueOverCurrent(): void
	{
		$result = $this->updateFields(['tags' => [9]], ['tags' => [5, 6]]);

		$this->assertSame([9], $result['tags']);
	}

	public function testUpdateDataExplicitEmptyTagsClearsThem(): void
	{
		$result = $this->updateFields(['tags' => []], ['tags' => [5, 6]]);

		$this->assertSame([], $result['tags']);
	}

	public function testUpdateDataTagsFromTagsHelperShapedCurrentItem(): void
	{
		$result = $this->updateFields([], ['tags' => ['tags' => '1,2', 'typeAlias' => 'com_contact.contact']]);

		$this->assertSame([1, 2], $result['tags']);
	}

	// -----------------------------------------------------------------------------------------------------
	// normaliseItem()
	// -----------------------------------------------------------------------------------------------------

	public function testNormaliseItemConvertsTagsHelperShapedValueToIdList(): void
	{
		$result = ContactMapper::normaliseItem(['id' => 1, 'tags' => ['tags' => '3,4', 'typeAlias' => 'com_contact.contact']]);

		$this->assertSame([3, 4], $result['tags']);
	}

	public function testNormaliseItemLeavesOtherFieldsUntouched(): void
	{
		$result = ContactMapper::normaliseItem(['id' => 1, 'name' => 'Jane', 'state' => 'Attica']);

		$this->assertSame('Jane', $result['name']);
		$this->assertSame('Attica', $result['state']);
	}

	// -----------------------------------------------------------------------------------------------------
	// submitData()
	// -----------------------------------------------------------------------------------------------------

	public function testSubmitDataMapsToContactFormFieldNames(): void
	{
		$result = ContactMapper::submitData('Jane Doe', 'jane@example.com', 'Hello', 'A message');

		$this->assertSame(
			[
				'contact_name'    => 'Jane Doe',
				'contact_email'   => 'jane@example.com',
				'contact_subject' => 'Hello',
				'contact_message' => 'A message',
			],
			$result
		);
	}

	// -----------------------------------------------------------------------------------------------------
	// output whitelist
	// -----------------------------------------------------------------------------------------------------

	public function testItemFieldsWhitelistExcludesParamsAndAssetId(): void
	{
		$this->assertNotContains('params', ContactMapper::ITEM_FIELDS);
		$this->assertNotContains('asset_id', ContactMapper::ITEM_FIELDS);
		$this->assertNotContains('associations', ContactMapper::ITEM_FIELDS);
	}

	public function testItemFieldsWhitelistIncludesPublishingAndAddressStateFields(): void
	{
		$this->assertContains('id', ContactMapper::ITEM_FIELDS);
		$this->assertContains('catid', ContactMapper::ITEM_FIELDS);
		$this->assertContains('published', ContactMapper::ITEM_FIELDS);
		$this->assertContains('featured', ContactMapper::ITEM_FIELDS);
		$this->assertContains('language', ContactMapper::ITEM_FIELDS);
		$this->assertContains('state', ContactMapper::ITEM_FIELDS);
		$this->assertContains('email_to', ContactMapper::ITEM_FIELDS);
	}
}
