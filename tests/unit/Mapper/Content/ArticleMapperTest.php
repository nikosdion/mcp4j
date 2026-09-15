<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Mapper\Content;

use Akeeba\Component\Mcp4j\Administrator\Mapper\Content\ArticleMapper;
use PHPUnit\Framework\TestCase;

class ArticleMapperTest extends TestCase
{
	// -----------------------------------------------------------------------------------------------------
	// listState()
	// -----------------------------------------------------------------------------------------------------

	public function testListStateWithEverythingNullOnlySetsOrderingAndEmptyPublishedFilter(): void
	{
		$result = ArticleMapper::listState(null, null, null, null, null, null, null);

		$this->assertSame(
			[
				'list.ordering'    => 'a.id',
				'list.direction'   => 'DESC',
				'filter.published' => '',
			],
			$result
		);
	}

	public function testListStateAuthorIsSetWhenGiven(): void
	{
		$this->assertSame(7, ArticleMapper::listState(7, null, null, null, null, null, null)['filter.author_id']);
	}

	public function testListStateAuthorIsAbsentWhenNull(): void
	{
		$this->assertArrayNotHasKey('filter.author_id', ArticleMapper::listState(null, null, null, null, null, null, null));
	}

	public function testListStateCategoryIsSetWhenGiven(): void
	{
		$this->assertSame(3, ArticleMapper::listState(null, 3, null, null, null, null, null)['filter.category_id']);
	}

	public function testListStatePublishedIsStringifiedWhenGiven(): void
	{
		$this->assertSame('0', ArticleMapper::listState(null, null, 0, null, null, null, null)['filter.published']);
		$this->assertSame('1', ArticleMapper::listState(null, null, 1, null, null, null, null)['filter.published']);
		$this->assertSame('-2', ArticleMapper::listState(null, null, -2, null, null, null, null)['filter.published']);
	}

	public function testListStateFeaturedIsSetWhenGiven(): void
	{
		$this->assertSame(1, ArticleMapper::listState(null, null, null, 1, null, null, null)['filter.featured']);
		$this->assertSame(0, ArticleMapper::listState(null, null, null, 0, null, null, null)['filter.featured']);
	}

	public function testListStateFeaturedIsAbsentWhenNull(): void
	{
		$this->assertArrayNotHasKey('filter.featured', ArticleMapper::listState(null, null, null, null, null, null, null));
	}

	public function testListStateTagIsNormalisedWhenGiven(): void
	{
		$this->assertSame([3, 4], ArticleMapper::listState(null, null, null, null, ['3', '4'], null, null)['filter.tag']);
	}

	public function testListStateTagIsAbsentWhenNullOrEmpty(): void
	{
		$this->assertArrayNotHasKey('filter.tag', ArticleMapper::listState(null, null, null, null, null, null, null));
		$this->assertArrayNotHasKey('filter.tag', ArticleMapper::listState(null, null, null, null, [], null, null));
	}

	public function testListStateLanguageIsSetWhenGiven(): void
	{
		$this->assertSame('en-GB', ArticleMapper::listState(null, null, null, null, null, 'en-GB', null)['filter.language']);
	}

	public function testListStateSearchIsSetOnlyWhenNonEmpty(): void
	{
		$this->assertSame('foo', ArticleMapper::listState(null, null, null, null, null, null, 'foo')['filter.search']);
		$this->assertArrayNotHasKey('filter.search', ArticleMapper::listState(null, null, null, null, null, null, ''));
		$this->assertArrayNotHasKey('filter.search', ArticleMapper::listState(null, null, null, null, null, null, null));
	}

	// -----------------------------------------------------------------------------------------------------
	// composeArticleText()
	// -----------------------------------------------------------------------------------------------------

	public function testComposeArticleTextWithFullText(): void
	{
		$result = ArticleMapper::composeArticleText('<p>Intro</p>', '<p>Full</p>');

		$this->assertSame('<p>Intro</p>' . ArticleMapper::READMORE . '<p>Full</p>', $result);
	}

	public function testComposeArticleTextWithoutFullText(): void
	{
		$this->assertSame('<p>Intro</p>', ArticleMapper::composeArticleText('<p>Intro</p>', ''));
	}

	public function testComposeArticleTextWhitespaceOnlyFullTextIsTreatedAsAbsent(): void
	{
		$this->assertSame('<p>Intro</p>', ArticleMapper::composeArticleText('<p>Intro</p>', "  \n\t "));
	}

	// -----------------------------------------------------------------------------------------------------
	// createData()
	// -----------------------------------------------------------------------------------------------------

	public function testCreateDataMinimal(): void
	{
		$result = ArticleMapper::createData('Title', 5, 'Intro', '');

		$this->assertSame('Title', $result['title']);
		$this->assertSame(5, $result['catid']);
		$this->assertStringContainsString('Intro', $result['introtext']);
		$this->assertSame('', $result['fulltext']);
		$this->assertSame($result['introtext'], $result['articletext']);
		$this->assertSame(1, $result['state']);
		$this->assertSame(1, $result['access']);
		$this->assertSame('*', $result['language']);
		$this->assertSame(0, $result['featured']);
		$this->assertSame([], $result['tags']);
		$this->assertSame('', $result['alias']);
		$this->assertArrayNotHasKey('images', $result);
		$this->assertArrayNotHasKey('urls', $result);
		$this->assertArrayNotHasKey('metadata', $result);
	}

	public function testCreateDataArticletextComposedFromConvertedIntroAndFull(): void
	{
		$result = ArticleMapper::createData('Title', 5, '**Bold** intro', 'Full *text*');

		$this->assertStringContainsString('<strong>Bold</strong>', $result['introtext']);
		$this->assertStringContainsString('<em>text</em>', $result['fulltext']);
		$this->assertSame($result['introtext'] . ArticleMapper::READMORE . $result['fulltext'], $result['articletext']);
	}

	public function testCreateDataEmptyOrNullAliasIsSentAsEmptyString(): void
	{
		// See createData()'s docblock comment: Table\Content::check() (unlike Table\Category::check())
		// calls trim($this->alias) with no null-guard, so an omitted alias must be sent as '' rather than
		// left out of the payload entirely, to avoid a PHP deprecation notice corrupting the response.
		$this->assertSame('', ArticleMapper::createData('Title', 5, 'Intro', '', alias: '')['alias']);
		$this->assertSame('', ArticleMapper::createData('Title', 5, 'Intro', '')['alias']);
	}

	public function testCreateDataNonEmptyAliasIncluded(): void
	{
		$result = ArticleMapper::createData('Title', 5, 'Intro', '', alias: 'my-article');

		$this->assertSame('my-article', $result['alias']);
	}

	public function testCreateDataPublishDatesOnlyWhenGiven(): void
	{
		$result = ArticleMapper::createData('Title', 5, 'Intro', '');
		$this->assertArrayNotHasKey('publish_up', $result);
		$this->assertArrayNotHasKey('publish_down', $result);

		$result = ArticleMapper::createData('Title', 5, 'Intro', '', publishStartTime: '2026-01-01 00:00:00', publishEndTime: '2026-02-01 00:00:00');
		$this->assertSame('2026-01-01 00:00:00', $result['publish_up']);
		$this->assertSame('2026-02-01 00:00:00', $result['publish_down']);
	}

	public function testCreateDataAccessDefaultsToOne(): void
	{
		$this->assertSame(1, ArticleMapper::createData('Title', 5, 'Intro', '')['access']);
		$this->assertSame(3, ArticleMapper::createData('Title', 5, 'Intro', '', access: 3)['access']);
	}

	public function testCreateDataCreatedFieldsOnlyWhenGiven(): void
	{
		$result = ArticleMapper::createData('Title', 5, 'Intro', '');
		$this->assertArrayNotHasKey('created', $result);
		$this->assertArrayNotHasKey('created_by', $result);
		$this->assertArrayNotHasKey('created_by_alias', $result);

		$result = ArticleMapper::createData(
			'Title', 5, 'Intro', '',
			createdTime: '2026-01-01 00:00:00', createdBy: 42, createdByAlias: 'Jane'
		);
		$this->assertSame('2026-01-01 00:00:00', $result['created']);
		$this->assertSame(42, $result['created_by']);
		$this->assertSame('Jane', $result['created_by_alias']);
	}

	public function testCreateDataMetaKeywordsAndDescriptionOnlyWhenNonNull(): void
	{
		$result = ArticleMapper::createData('Title', 5, 'Intro', '');
		$this->assertArrayNotHasKey('metakey', $result);
		$this->assertArrayNotHasKey('metadesc', $result);

		$result = ArticleMapper::createData('Title', 5, 'Intro', '', metaKeywords: 'a,b', metaDescription: 'desc');
		$this->assertSame('a,b', $result['metakey']);
		$this->assertSame('desc', $result['metadesc']);
	}

	public function testCreateDataFeaturedCastToInt(): void
	{
		$this->assertSame(0, ArticleMapper::createData('Title', 5, 'Intro', '', featured: false)['featured']);
		$this->assertSame(1, ArticleMapper::createData('Title', 5, 'Intro', '', featured: true)['featured']);
	}

	public function testCreateDataFeaturedDatesOnlyWhenGiven(): void
	{
		$result = ArticleMapper::createData('Title', 5, 'Intro', '', featuredStartTime: '2026-01-01 00:00:00', featuredEndTime: '2026-02-01 00:00:00');
		$this->assertSame('2026-01-01 00:00:00', $result['featured_up']);
		$this->assertSame('2026-02-01 00:00:00', $result['featured_down']);
	}

	public function testCreateDataNoteOnlyWhenGiven(): void
	{
		$this->assertArrayNotHasKey('note', ArticleMapper::createData('Title', 5, 'Intro', ''));
		$this->assertSame('a note', ArticleMapper::createData('Title', 5, 'Intro', '', note: 'a note')['note']);
	}

	public function testCreateDataTagsNormalisedOrEmpty(): void
	{
		$this->assertSame([], ArticleMapper::createData('Title', 5, 'Intro', '')['tags']);
		$this->assertSame([3, 4], ArticleMapper::createData('Title', 5, 'Intro', '', tags: ['3', '4'])['tags']);
	}

	public function testCreateDataImagesIndividualParametersOnly(): void
	{
		$result = ArticleMapper::createData('Title', 5, 'Intro', '', imageIntro: 'a.jpg', imageIntroAlt: 'Alt');

		$this->assertSame(['image_intro' => 'a.jpg', 'image_intro_alt' => 'Alt'], $result['images']);
	}

	public function testCreateDataImagesGroupOmittedWhenEmpty(): void
	{
		$result = ArticleMapper::createData('Title', 5, 'Intro', '');

		$this->assertArrayNotHasKey('images', $result);
	}

	public function testCreateDataImagesNestedThenIndividualOverrides(): void
	{
		$result = ArticleMapper::createData(
			'Title', 5, 'Intro', '',
			imageIntro: 'individual.jpg',
			images: ['image_intro' => 'nested.jpg', 'image_fulltext' => 'nested-full.jpg']
		);

		$this->assertSame(
			['image_intro' => 'individual.jpg', 'image_fulltext' => 'nested-full.jpg'],
			$result['images']
		);
	}

	public function testCreateDataUrlsIndividualAndOmission(): void
	{
		$result = ArticleMapper::createData('Title', 5, 'Intro', '', urlA: 'https://example.com/a', urlAText: 'Example A');

		$this->assertSame(['urla' => 'https://example.com/a', 'urlatext' => 'Example A'], $result['urls']);

		$this->assertArrayNotHasKey('urls', ArticleMapper::createData('Title', 5, 'Intro', ''));
	}

	public function testCreateDataMetadataIndividualAndOmission(): void
	{
		$result = ArticleMapper::createData('Title', 5, 'Intro', '', metadataRobots: 'noindex, nofollow');

		$this->assertSame(['robots' => 'noindex, nofollow'], $result['metadata']);

		$this->assertArrayNotHasKey('metadata', ArticleMapper::createData('Title', 5, 'Intro', ''));
	}

	// -----------------------------------------------------------------------------------------------------
	// updateData()
	// -----------------------------------------------------------------------------------------------------

	private function currentArticle(array $overrides = []): array
	{
		return array_replace(
			[
				'id'        => 10,
				'introtext' => '<p>Current intro</p>',
				'fulltext'  => '<p>Current full</p>',
				'tags'      => [1, 2],
				'images'    => ['image_intro' => 'current-intro.jpg', 'image_fulltext' => 'current-full.jpg'],
				'urls'      => ['urla' => 'https://example.com/current-a'],
				'metadata'  => ['robots' => 'index, follow'],
			],
			$overrides
		);
	}

	private function updateData(array $overrides, array $currentItem): array
	{
		$defaults = [
			'title'                => null,
			'catId'                => null,
			'introText'            => null,
			'fullText'             => null,
			'state'                => null,
			'publishStartTime'     => null,
			'publishEndTime'       => null,
			'access'               => null,
			'alias'                => null,
			'language'             => null,
			'createdTime'          => null,
			'createdBy'            => null,
			'createdByAlias'       => null,
			'imageIntro'           => null,
			'imageIntroAlt'        => null,
			'imageIntroFloat'      => null,
			'imageIntroCaption'    => null,
			'imageFulltext'        => null,
			'imageFulltextAlt'     => null,
			'imageFulltextFloat'   => null,
			'imageFulltextCaption' => null,
			'urlA'                 => null,
			'urlAText'             => null,
			'urlATarget'           => null,
			'urlB'                 => null,
			'urlBText'             => null,
			'urlBTarget'           => null,
			'urlC'                 => null,
			'urlCText'             => null,
			'urlCTarget'           => null,
			'metaKeywords'         => null,
			'metaDescription'      => null,
			'metadataRobots'       => null,
			'metadataAuthor'       => null,
			'metadataRights'       => null,
			'featured'             => null,
			'featuredStartTime'    => null,
			'featuredEndTime'      => null,
			'note'                 => null,
			'tags'                 => null,
			'images'               => null,
			'urls'                 => null,
			'metadata'             => null,
		];

		$args = array_replace($defaults, $overrides);

		return ArticleMapper::updateData(...array_values($args), currentItem: $currentItem);
	}

	public function testUpdateDataWithEverythingNullKeepsCurrentTextAndTags(): void
	{
		$result = $this->updateData([], $this->currentArticle());

		$this->assertSame('<p>Current intro</p>', $result['introtext']);
		$this->assertSame('<p>Current full</p>', $result['fulltext']);
		$this->assertSame('<p>Current intro</p>' . ArticleMapper::READMORE . '<p>Current full</p>', $result['articletext']);
		$this->assertSame([1, 2], $result['tags']);
		$this->assertArrayNotHasKey('title', $result);
	}

	public function testUpdateDataOnlyTitleGiven(): void
	{
		$result = $this->updateData(['title' => 'New title'], $this->currentArticle());

		$this->assertSame('New title', $result['title']);
		$this->assertArrayNotHasKey('catid', $result);
	}

	public function testUpdateDataChangingFullTextOnlyKeepsCurrentIntroAndRecomposesArticletext(): void
	{
		$result = $this->updateData(['fullText' => 'New *full*'], $this->currentArticle());

		$this->assertSame('<p>Current intro</p>', $result['introtext'], 'introtext must be unchanged when only fullText is given.');
		$this->assertStringContainsString('<em>full</em>', $result['fulltext']);
		$this->assertSame($result['introtext'] . ArticleMapper::READMORE . $result['fulltext'], $result['articletext']);
	}

	public function testUpdateDataChangingIntroTextOnlyKeepsCurrentFullText(): void
	{
		$result = $this->updateData(['introText' => 'New **intro**'], $this->currentArticle());

		$this->assertStringContainsString('<strong>intro</strong>', $result['introtext']);
		$this->assertSame('<p>Current full</p>', $result['fulltext']);
	}

	public function testUpdateDataFeaturedCastToIntWhenGiven(): void
	{
		$this->assertSame(1, $this->updateData(['featured' => true], $this->currentArticle())['featured']);
		$this->assertSame(0, $this->updateData(['featured' => false], $this->currentArticle())['featured']);
		$this->assertArrayNotHasKey('featured', $this->updateData([], $this->currentArticle()));
	}

	public function testUpdateDataTagsFallsBackToCurrentWhenNull(): void
	{
		$result = $this->updateData([], $this->currentArticle(['tags' => [5, 6]]));

		$this->assertSame([5, 6], $result['tags']);
	}

	public function testUpdateDataTagsExplicitOverridesCurrent(): void
	{
		$result = $this->updateData(['tags' => [9]], $this->currentArticle());

		$this->assertSame([9], $result['tags']);
	}

	public function testUpdateDataTagsExplicitEmptyClearsThem(): void
	{
		$result = $this->updateData(['tags' => []], $this->currentArticle());

		$this->assertSame([], $result['tags']);
	}

	public function testUpdateDataImagesMergedOntoCurrent(): void
	{
		$result = $this->updateData(['imageIntroAlt' => 'New alt'], $this->currentArticle());

		$this->assertSame(
			[
				'image_intro'     => 'current-intro.jpg',
				'image_fulltext'  => 'current-full.jpg',
				'image_intro_alt' => 'New alt',
			],
			$result['images']
		);
	}

	public function testUpdateDataImagesNestedPayloadMergedOntoCurrentThenIndividualOverrides(): void
	{
		$result = $this->updateData(
			[
				'images'     => ['image_intro' => 'nested.jpg'],
				'imageIntro' => 'individual.jpg',
			],
			$this->currentArticle()
		);

		$this->assertSame('individual.jpg', $result['images']['image_intro']);
		$this->assertSame('current-full.jpg', $result['images']['image_fulltext'], 'Untouched current images must survive the merge.');
	}

	public function testUpdateDataUrlsMergedOntoCurrent(): void
	{
		$result = $this->updateData(['urlBText' => 'B text'], $this->currentArticle());

		$this->assertSame('https://example.com/current-a', $result['urls']['urla']);
		$this->assertSame('B text', $result['urls']['urlbtext']);
	}

	public function testUpdateDataMetadataMergedOntoCurrent(): void
	{
		$result = $this->updateData(['metadataAuthor' => 'Jane'], $this->currentArticle());

		$this->assertSame('index, follow', $result['metadata']['robots']);
		$this->assertSame('Jane', $result['metadata']['author']);
	}

	// -----------------------------------------------------------------------------------------------------
	// finalUrls()
	// -----------------------------------------------------------------------------------------------------

	public function testFinalUrlsWithNothingGivenReturnsCurrent(): void
	{
		$result = ArticleMapper::finalUrls($this->currentArticle(), null, []);

		$this->assertSame(['urla' => 'https://example.com/current-a'], $result);
	}

	public function testFinalUrlsIndividualOverridesCurrent(): void
	{
		$result = ArticleMapper::finalUrls($this->currentArticle(), null, ['urla' => 'https://example.com/new-a']);

		$this->assertSame('https://example.com/new-a', $result['urla']);
	}

	public function testFinalUrlsNestedThenIndividualPrecedence(): void
	{
		$result = ArticleMapper::finalUrls(
			$this->currentArticle(),
			['urla' => 'https://example.com/nested-a', 'urlb' => 'https://example.com/nested-b'],
			['urla' => 'https://example.com/individual-a']
		);

		$this->assertSame('https://example.com/individual-a', $result['urla']);
		$this->assertSame('https://example.com/nested-b', $result['urlb']);
	}

	public function testFinalUrlsToleratesMissingCurrentUrls(): void
	{
		$result = ArticleMapper::finalUrls(['id' => 1], null, ['urla' => 'https://example.com/a']);

		$this->assertSame(['urla' => 'https://example.com/a'], $result);
	}

	// -----------------------------------------------------------------------------------------------------
	// touchesPublishingState()
	// -----------------------------------------------------------------------------------------------------

	public function testTouchesPublishingStateFalseWhenEverythingNull(): void
	{
		$this->assertFalse(ArticleMapper::touchesPublishingState(null, null, null, null, null, null));
	}

	public function testTouchesPublishingStateTrueForState(): void
	{
		$this->assertTrue(ArticleMapper::touchesPublishingState(0, null, null, null, null, null));
	}

	public function testTouchesPublishingStateTrueForFeatured(): void
	{
		$this->assertTrue(ArticleMapper::touchesPublishingState(null, true, null, null, null, null));
		$this->assertTrue(ArticleMapper::touchesPublishingState(null, false, null, null, null, null));
	}

	public function testTouchesPublishingStateTrueForPublishDates(): void
	{
		$this->assertTrue(ArticleMapper::touchesPublishingState(null, null, '2026-01-01', null, null, null));
		$this->assertTrue(ArticleMapper::touchesPublishingState(null, null, null, '2026-01-01', null, null));
	}

	public function testTouchesPublishingStateTrueForFeaturedDates(): void
	{
		$this->assertTrue(ArticleMapper::touchesPublishingState(null, null, null, null, '2026-01-01', null));
		$this->assertTrue(ArticleMapper::touchesPublishingState(null, null, null, null, null, '2026-01-01'));
	}

	// -----------------------------------------------------------------------------------------------------
	// normaliseItem()
	// -----------------------------------------------------------------------------------------------------

	public function testNormaliseItemDropsArticletext(): void
	{
		$result = ArticleMapper::normaliseItem(['id' => 1, 'articletext' => 'stale', 'introtext' => 'a']);

		$this->assertArrayNotHasKey('articletext', $result);
		$this->assertSame('a', $result['introtext']);
	}

	public function testNormaliseItemConvertsTags(): void
	{
		$result = ArticleMapper::normaliseItem(['id' => 1, 'tags' => ['tags' => '3,4', 'typeAlias' => 'com_content.article']]);

		$this->assertSame([3, 4], $result['tags']);
	}

	// -----------------------------------------------------------------------------------------------------
	// Field whitelists — hard-coded so a future edit is deliberate.
	// -----------------------------------------------------------------------------------------------------

	public function testItemFieldsMatchesPlan(): void
	{
		$this->assertSame(
			[
				'id', 'title', 'alias', 'catid', 'state', 'featured', 'featured_up', 'featured_down',
				'access', 'language', 'introtext', 'fulltext', 'images', 'urls', 'metakey', 'metadesc',
				'metadata', 'note', 'publish_up', 'publish_down', 'created', 'created_by',
				'created_by_alias', 'modified', 'modified_by', 'checked_out', 'checked_out_time', 'hits',
				'version', 'tags',
			],
			ArticleMapper::ITEM_FIELDS
		);
	}

	public function testItemFieldsExcludesSensitiveOrInternalKeys(): void
	{
		$excluded = ['password', 'otpKey', 'otep', 'activation', 'asset_id', 'rules', 'attribs', 'associations', 'typeAlias', 'schemaorg'];

		foreach ($excluded as $key)
		{
			$this->assertNotContains($key, ArticleMapper::ITEM_FIELDS);
			$this->assertNotContains($key, ArticleMapper::LIST_FIELDS);
		}

		$this->assertNotContains('articletext', ArticleMapper::ITEM_FIELDS, 'articletext is write-only convenience, never output.');
	}

	public function testIntFieldsAndJsonFields(): void
	{
		$this->assertSame(
			['catid', 'state', 'featured', 'access', 'created_by', 'modified_by', 'checked_out', 'hits', 'version'],
			ArticleMapper::INT_FIELDS
		);

		$this->assertSame(['images', 'urls', 'metadata'], ArticleMapper::JSON_FIELDS);
	}
}
