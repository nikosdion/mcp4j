<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Mapper\Languages;

use Akeeba\Component\Mcp4j\Administrator\Mapper\Languages\ContentLanguageMapper;
use PHPUnit\Framework\TestCase;

class ContentLanguageMapperTest extends TestCase
{
	// -----------------------------------------------------------------------------------------------------
	// renameId()
	// -----------------------------------------------------------------------------------------------------

	public function testRenameIdMovesLangIdToLeadingId(): void
	{
		$result = ContentLanguageMapper::renameId(['lang_id' => '5', 'lang_code' => 'en-GB']);

		$this->assertSame(['id' => 5, 'lang_code' => 'en-GB'], $result);
	}

	// -----------------------------------------------------------------------------------------------------
	// listState()
	// -----------------------------------------------------------------------------------------------------

	public function testListStateWithNullState(): void
	{
		$this->assertSame(
			['filter.published' => '', 'list.ordering' => 'a.ordering', 'list.direction' => 'ASC'],
			ContentLanguageMapper::listState(null)
		);
	}

	public function testListStateStatePublishedIsStringified(): void
	{
		$this->assertSame('1', ContentLanguageMapper::listState(1)['filter.published']);
		$this->assertSame('0', ContentLanguageMapper::listState(0)['filter.published']);
	}

	// -----------------------------------------------------------------------------------------------------
	// createData()
	// -----------------------------------------------------------------------------------------------------

	public function testCreateDataWithMinimalArguments(): void
	{
		$result = ContentLanguageMapper::createData('fr-FR', 'French', 'Français', null, null, 1, null, null, null);

		$this->assertSame(
			[
				'lang_code'    => 'fr-FR',
				'title'        => 'French',
				'title_native' => 'Français',
				'published'    => 1,
				'access'       => 1,
				'description'  => '',
				'image'        => '',
				'metadesc'     => '',
			],
			$result
		);
	}

	public function testCreateDataSefIncludedWhenGiven(): void
	{
		$result = ContentLanguageMapper::createData('fr-FR', 'French', 'Français', 'fr', 'fr_fr.gif', 1, null, null, null);

		$this->assertSame('fr', $result['sef']);
		$this->assertSame('fr_fr.gif', $result['image']);
	}

	public function testCreateDataImageDescriptionAndMetadescDefaultToEmptyStringNotOmitted(): void
	{
		// #__languages.image/description/metadesc are NOT NULL columns with no DB default — Table::bind()
		// only sets a property when the key is present, so these must always be present (never omitted),
		// or Table::store() fails at INSERT time. See createData()'s own docblock.
		$result = ContentLanguageMapper::createData('fr-FR', 'French', 'Français', 'fr', null, 1, null, null, null);

		$this->assertSame('', $result['image']);
		$this->assertSame('', $result['description']);
		$this->assertSame('', $result['metadesc']);
	}

	public function testCreateDataMetadescAndSitenameIncludedWhenGiven(): void
	{
		$result = ContentLanguageMapper::createData('fr-FR', 'French', 'Français', null, null, 1, null, 'desc', 'My Site FR');

		$this->assertSame('desc', $result['metadesc']);
		$this->assertSame('My Site FR', $result['sitename']);
	}

	public function testCreateDataExplicitAccessOverridesDefault(): void
	{
		$result = ContentLanguageMapper::createData('fr-FR', 'French', 'Français', null, null, 1, 3, null, null);

		$this->assertSame(3, $result['access']);
	}

	public function testCreateDataNeverContainsMetakey(): void
	{
		$result = ContentLanguageMapper::createData('fr-FR', 'French', 'Français', null, null, 1, null, null, null);

		$this->assertArrayNotHasKey('metakey', $result);
	}

	// -----------------------------------------------------------------------------------------------------
	// updateData()
	// -----------------------------------------------------------------------------------------------------

	public function testUpdateDataWithEverythingNullReturnsEmptyArray(): void
	{
		$result = ContentLanguageMapper::updateData(null, null, null, null, null, null, null, null, null);

		$this->assertSame([], $result);
	}

	public function testUpdateDataKeepsOnlyExplicitlyGivenValues(): void
	{
		$result = ContentLanguageMapper::updateData(null, 'New Title', null, null, null, null, null, null, null);

		$this->assertSame(['title' => 'New Title'], $result);
	}

	public function testUpdateDataPublishedZeroIsKeptNotTreatedAsNull(): void
	{
		$result = ContentLanguageMapper::updateData(null, null, null, null, null, 0, null, null, null);

		$this->assertSame(['published' => 0], $result);
	}

	public function testUpdateDataNeverContainsMetakey(): void
	{
		$result = ContentLanguageMapper::updateData('fr-FR', 'French', 'Français', 'fr', 'fr.gif', 1, 1, 'desc', 'site');

		$this->assertArrayNotHasKey('metakey', $result);
	}

	// -----------------------------------------------------------------------------------------------------
	// extractMetakey()
	// -----------------------------------------------------------------------------------------------------

	public function testExtractMetakeyReturnsNullForNull(): void
	{
		$this->assertNull(ContentLanguageMapper::extractMetakey(null));
	}

	public function testExtractMetakeyKeepsEmptyString(): void
	{
		$this->assertSame('', ContentLanguageMapper::extractMetakey(''));
	}

	public function testExtractMetakeyReturnsGivenValue(): void
	{
		$this->assertSame('french, france', ContentLanguageMapper::extractMetakey('french, france'));
	}

	// -----------------------------------------------------------------------------------------------------
	// Field whitelists — hard-coded so a future edit is deliberate.
	// -----------------------------------------------------------------------------------------------------

	public function testItemAndListFieldsAreIdentical(): void
	{
		$this->assertSame(ContentLanguageMapper::ITEM_FIELDS, ContentLanguageMapper::LIST_FIELDS);
		$this->assertSame(
			['id', 'asset_id', 'lang_code', 'title', 'title_native', 'sef', 'image', 'description', 'metakey',
				'metadesc', 'sitename', 'published', 'access', 'ordering', 'access_level', 'home'],
			ContentLanguageMapper::ITEM_FIELDS
		);
	}

	public function testRawFieldsStartWithLangId(): void
	{
		$this->assertSame('lang_id', ContentLanguageMapper::RAW_ITEM_FIELDS[0]);
		$this->assertContains('lang_id', ContentLanguageMapper::RAW_INT_FIELDS);
	}
}
