<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Mapper\ContentHistory;

use Akeeba\Component\Mcp4j\Administrator\Bridge\Support\OutputShaper;
use Akeeba\Component\Mcp4j\Administrator\Mapper\ContentHistory\HistoryResourceMapper;
use PHPUnit\Framework\TestCase;

class HistoryResourceMapperTest extends TestCase
{
	// -----------------------------------------------------------------------------------------------------
	// toTypeAlias()
	// -----------------------------------------------------------------------------------------------------

	public function testToTypeAliasResolvesArticles(): void
	{
		$this->assertSame('com_content.article', HistoryResourceMapper::toTypeAlias('content/articles'));
	}

	public function testToTypeAliasResolvesContacts(): void
	{
		$this->assertSame('com_contact.contact', HistoryResourceMapper::toTypeAlias('contacts'));
	}

	public function testToTypeAliasResolvesBanners(): void
	{
		$this->assertSame('com_banners.banner', HistoryResourceMapper::toTypeAlias('banners'));
	}

	public function testToTypeAliasStripsLeadingAndTrailingSlashes(): void
	{
		$this->assertSame('com_content.article', HistoryResourceMapper::toTypeAlias('/content/articles/'));
	}

	public function testToTypeAliasStripsLeadingV1Segment(): void
	{
		$this->assertSame('com_contact.contact', HistoryResourceMapper::toTypeAlias('v1/contacts'));
	}

	public function testToTypeAliasStripsLeadingV1SegmentWithSlashes(): void
	{
		$this->assertSame('com_content.article', HistoryResourceMapper::toTypeAlias('/v1/content/articles/'));
	}

	public function testToTypeAliasPassesThroughAKnownTypeAlias(): void
	{
		$this->assertSame('com_content.article', HistoryResourceMapper::toTypeAlias('com_content.article'));
	}

	public function testToTypeAliasPassesThroughATypeAliasNotAResourceShortcut(): void
	{
		// com_content.category has no core Web Services history route at all, but is still a valid type
		// alias — see .plans/12-fields-history-privacy.md deviation 2.
		$this->assertSame('com_content.category', HistoryResourceMapper::toTypeAlias('com_content.category'));
	}

	public function testToTypeAliasRejectsUnsupportedResource(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage(
			'Unsupported resource: users. Use content/articles, contacts, banners or a type alias such as com_content.article.'
		);

		HistoryResourceMapper::toTypeAlias('users');
	}

	public function testToTypeAliasRejectsGarbage(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		HistoryResourceMapper::toTypeAlias('nonsense');
	}

	// -----------------------------------------------------------------------------------------------------
	// itemId()
	// -----------------------------------------------------------------------------------------------------

	public function testItemIdConcatenatesTypeAliasAndId(): void
	{
		$this->assertSame('com_content.article.12', HistoryResourceMapper::itemId('com_content.article', 12));
	}

	// -----------------------------------------------------------------------------------------------------
	// renameId()
	// -----------------------------------------------------------------------------------------------------

	public function testRenameIdMovesVersionIdToLeadingId(): void
	{
		$result = HistoryResourceMapper::renameId(['version_id' => '5', 'item_id' => 'com_content.article.1']);

		$this->assertSame(['id' => 5, 'item_id' => 'com_content.article.1'], $result);
		$this->assertIsInt($result['id']);
		$this->assertSame(['id', 'item_id'], array_keys($result));
	}

	public function testRenameIdIsANoOpWhenVersionIdAbsent(): void
	{
		$row = ['id' => 5, 'item_id' => 'com_content.article.1'];

		$this->assertSame($row, HistoryResourceMapper::renameId($row));
	}

	// -----------------------------------------------------------------------------------------------------
	// version_data JSON decode through OutputShaper, using these field lists
	// -----------------------------------------------------------------------------------------------------

	public function testVersionDataIsJsonDecodedByOutputShaperUsingTheseFieldLists(): void
	{
		$row = [
			'version_id'      => '3',
			'item_id'         => 'com_content.article.1',
			'version_note'    => '',
			'save_date'       => '2026-01-01 00:00:00',
			'editor_user_id'  => '42',
			'editor'          => 'Super User',
			'character_count' => '10',
			'sha1_hash'       => 'abc',
			'version_data'    => '{"title":"Hello"}',
			'keep_forever'    => '0',
			'is_current'      => '1',
		];

		$fields    = HistoryResourceMapper::LIST_FIELDS;
		$fields[0] = 'version_id';

		$shaped = OutputShaper::shape($row, $fields, HistoryResourceMapper::INT_FIELDS, HistoryResourceMapper::JSON_FIELDS);
		$shaped = HistoryResourceMapper::renameId($shaped);

		$this->assertSame(3, $shaped['id']);
		$this->assertSame(['title' => 'Hello'], $shaped['version_data']);
		$this->assertSame(42, $shaped['editor_user_id']);
		$this->assertSame(1, $shaped['is_current']);
		$this->assertSame(0, $shaped['keep_forever']);
	}

	public function testFieldListsAreExactly(): void
	{
		$this->assertSame(
			['id', 'item_id', 'version_note', 'save_date', 'editor_user_id', 'editor', 'character_count', 'sha1_hash', 'version_data', 'keep_forever', 'is_current'],
			HistoryResourceMapper::LIST_FIELDS
		);
		$this->assertSame(['editor_user_id', 'character_count', 'keep_forever', 'is_current'], HistoryResourceMapper::INT_FIELDS);
		$this->assertSame(['version_data'], HistoryResourceMapper::JSON_FIELDS);
	}
}
