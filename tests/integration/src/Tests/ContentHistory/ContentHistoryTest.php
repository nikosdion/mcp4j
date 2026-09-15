<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Integration\Tests\ContentHistory;

use Akeeba\Mcp4j\Tests\Integration\AbstractToolTestCase;

/**
 * End-to-end coverage for the three `contenthistory_*` tools
 * ({@see \Akeeba\Component\Mcp4j\Administrator\Tool\ContentHistory\ContentHistory}).
 */
class ContentHistoryTest extends AbstractToolTestCase
{
	private ?int $articleId = null;

	protected function tearDown(): void
	{
		if ($this->articleId !== null)
		{
			$this->callTool('content_articles_delete', ['id' => $this->articleId]);
			$this->articleId = null;
		}

		parent::tearDown();
	}

	/**
	 * Creates a fresh category fixture and registers its cleanup — mirrors ArticlesTest::fixtureCategory(),
	 * avoiding any assumption that a category titled "Uncategorised" still exists (or is still named
	 * exactly that) on the test site.
	 */
	private function fixtureCategory(): array
	{
		$category = $this->callToolOk('content_categories_create', ['title' => $this->uniqueName('MCP4j History Fixture Cat')]);
		$this->registerCleanup(fn () => $this->callTool('content_categories_delete', ['id' => $category['id']]));

		return $category;
	}

	/**
	 * Creates an article and updates it twice (three distinct history versions), skipping the whole test
	 * with an explicit explanation if `com_content.save_history` is off on this test site rather than
	 * silently passing on zero history rows.
	 *
	 * @return array{0: int, 1: array} `[$articleId, $listResult]` — $listResult from a fresh
	 *                                 `contenthistory_list` call, guaranteed to have at least 2 items.
	 */
	private function createArticleWithHistory(): array
	{
		$category = $this->fixtureCategory();

		$title = $this->uniqueName('MCP4j History Article');

		$created = $this->callToolOk('content_articles_create', [
			'title'      => $title,
			'catId'      => $category['id'],
			'introText'  => 'Version 1',
			'fullText'   => '',
		]);
		$articleId = $created['id'];

		$this->callToolOk('content_articles_update', ['articleId' => $articleId, 'introText' => 'Version 2']);
		$this->callToolOk('content_articles_update', ['articleId' => $articleId, 'introText' => 'Version 3']);

		$list = $this->callToolOk('contenthistory_list', ['resource' => 'content/articles', 'itemId' => $articleId]);

		if (count($list['items']) < 2)
		{
			$config = $this->callToolOk('config_component_read', ['componentName' => 'com_content']);

			$this->callTool('content_articles_delete', ['id' => $articleId]);

			$this->fail(sprintf(
				'contenthistory_list returned %d item(s) for a freshly-created-and-twice-updated article — content version ' .
				'history appears to be disabled on this test site (com_content "save_history" = %s). This test requires ' .
				'it enabled.',
				count($list['items']),
				var_export($config['save_history'] ?? null, true)
			));
		}

		return [$articleId, $list];
	}

	public function testListByResourceShortcut(): void
	{
		[$articleId, $list] = $this->createArticleWithHistory();
		$this->articleId    = $articleId;

		$this->assertListShape($list);
		$this->assertGreaterThanOrEqual(2, count($list['items']));

		// Newest first (save_date descending).
		$dates = array_column($list['items'], 'save_date');
		$sorted = $dates;
		rsort($sorted);
		$this->assertSame($sorted, $dates, 'contenthistory_list must order by save_date descending.');

		foreach ($list['items'] as $item)
		{
			$this->assertIsArray($item['version_data']);
			$this->assertArrayHasKey('title', $item['version_data']);
		}
	}

	public function testListByTypeAliasReturnsTheSameIds(): void
	{
		[$articleId, $list] = $this->createArticleWithHistory();
		$this->articleId    = $articleId;

		$byAlias = $this->callToolOk('contenthistory_list', ['resource' => 'com_content.article', 'itemId' => $articleId]);

		$this->assertSame(
			array_column($list['items'], 'id'),
			array_column($byAlias['items'], 'id')
		);
	}

	public function testKeepThenDeleteBlocksThenAllowsDeletion(): void
	{
		[$articleId, $list] = $this->createArticleWithHistory();
		$this->articleId    = $articleId;

		// Oldest version (list is newest-first).
		$oldest = end($list['items']);
		$versionId = $oldest['id'];

		$kept = $this->callToolOk('contenthistory_keep', ['resource' => 'content/articles', 'itemId' => $articleId, 'versionId' => $versionId]);
		$this->assertSame(1, $kept['keep_forever']);

		$blocked = $this->callTool('contenthistory_delete', ['resource' => 'content/articles', 'itemId' => $articleId, 'versionId' => $versionId]);
		$this->assertTrue($blocked['isError']);
		$this->assertStringContainsString('kept forever', $blocked['text']);

		$unkept = $this->callToolOk('contenthistory_keep', ['resource' => 'content/articles', 'itemId' => $articleId, 'versionId' => $versionId]);
		$this->assertSame(0, $unkept['keep_forever']);

		$deleted = $this->callToolOk('contenthistory_delete', ['resource' => 'content/articles', 'itemId' => $articleId, 'versionId' => $versionId]);
		$this->assertSame(['deleted' => true, 'id' => $versionId], $deleted);

		$after = $this->callToolOk('contenthistory_list', ['resource' => 'content/articles', 'itemId' => $articleId]);
		$this->assertNotContains($versionId, array_column($after['items'], 'id'));
	}

	public function testKeepAndDeleteRefuseAVersionBelongingToAnotherArticle(): void
	{
		[$articleId1, $list1] = $this->createArticleWithHistory();
		$this->articleId      = $articleId1;

		[$articleId2, $list2] = $this->createArticleWithHistory();
		$this->registerCleanup(fn () => $this->callTool('content_articles_delete', ['id' => $articleId2]));

		$foreignVersionId = $list2['items'][0]['id'];

		$this->assertNotFound($this->callTool('contenthistory_keep', ['resource' => 'content/articles', 'itemId' => $articleId1, 'versionId' => $foreignVersionId]));
		$this->assertNotFound($this->callTool('contenthistory_delete', ['resource' => 'content/articles', 'itemId' => $articleId1, 'versionId' => $foreignVersionId]));
	}

	public function testUnsupportedResourceIsRejected(): void
	{
		$result = $this->callTool('contenthistory_list', ['resource' => 'users', 'itemId' => 1]);
		$this->assertTrue($result['isError']);
		$this->assertStringContainsString('Unsupported resource', $result['text']);
	}

	public function testLowPrivilegeUserIsRefused(): void
	{
		[$articleId, ] = $this->createArticleWithHistory();
		$this->articleId = $articleId;

		$result = $this->callToolAsLowPriv('contenthistory_list', ['resource' => 'content/articles', 'itemId' => $articleId]);
		$this->assertPermissionDenied($result);
	}
}
