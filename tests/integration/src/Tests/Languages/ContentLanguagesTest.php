<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Integration\Tests\Languages;

use Akeeba\Mcp4j\Tests\Integration\AbstractToolTestCase;

/**
 * End-to-end coverage for the five `languages_content_*` tools
 * ({@see \Akeeba\Component\Mcp4j\Administrator\Tool\Languages\ContentLanguages}). See
 * `.plans/10-config-plugins-installer-languages.md` for the coverage this class is meant to satisfy.
 */
class ContentLanguagesTest extends AbstractToolTestCase
{
	public function testListContainsEnGbAndHomeField(): void
	{
		$result = $this->callToolOk('languages_content_list', ['pageLimit' => 100]);
		$this->assertListShape($result);

		$byCode = array_column($result['items'], null, 'lang_code');
		$this->assertArrayHasKey('en-GB', $byCode);
		$this->assertArrayHasKey('home', $byCode['en-GB']);
	}

	public function testFullLifecycle(): void
	{
		$langCode = $this->randomLangCode();
		$sef      = $this->uniqueAlias('mcp4j-lang');

		$created = $this->callToolOk('languages_content_create', [
			'langCode'    => $langCode,
			'title'       => 'MCP4j Test Language',
			'titleNative' => 'MCP4j Test Language Native',
			'sef'         => $sef,
			'metakey'     => 'mcp4j, test, language',
			'published'   => 0,
		]);

		$id = $created['id'];
		$this->registerCleanup(fn () => $this->callTool('languages_content_delete', ['id' => $id]));

		$this->assertSame($langCode, $created['lang_code']);
		$this->assertSame($sef, $created['sef']);

		$read = $this->callToolOk('languages_content_read', ['id' => $id]);
		$this->assertSame('mcp4j, test, language', $read['metakey']);
		$this->assertSame($sef, $read['sef']);

		$newTitle = 'MCP4j Test Language Updated';
		$updated  = $this->callToolOk('languages_content_update', ['id' => $id, 'title' => $newTitle]);
		$this->assertSame($newTitle, $updated['title']);
		$this->assertSame($sef, $updated['sef'], 'A partial update must not touch fields it was not given.');

		$deleted = $this->callToolOk('languages_content_delete', ['id' => $id]);
		$this->assertSame(['deleted' => true, 'id' => $id], $deleted);

		$this->assertNotFound($this->callTool('languages_content_read', ['id' => $id]));
	}

	public function testCreateDuplicateSefIsRejected(): void
	{
		$result = $this->callTool('languages_content_create', [
			'langCode'    => $this->randomLangCode(),
			'title'       => 'MCP4j Duplicate SEF',
			'titleNative' => 'MCP4j Duplicate SEF',
			// en-GB's own SEF tag — Table\Language::store() enforces uniqueness on this column.
			'sef'         => 'en',
		]);

		$this->assertTrue($result['isError'], 'Expected a tool error: ' . $result['text']);
	}

	public function testCreateWithInvalidLangCodeIsRejected(): void
	{
		$result = $this->callTool('languages_content_create', [
			'langCode'    => 'english',
			'title'       => 'MCP4j Invalid Lang Code',
			'titleNative' => 'MCP4j Invalid Lang Code',
		]);

		$this->assertTrue($result['isError']);
	}

	public function testLowPrivilegeUserIsRefusedCreate(): void
	{
		$langCode = $this->randomLangCode();

		$result = $this->callToolAsLowPriv('languages_content_create', [
			'langCode'    => $langCode,
			'title'       => 'MCP4j LowPriv',
			'titleNative' => 'MCP4j LowPriv',
		]);

		$this->assertPermissionDenied($result);

		$list  = $this->callToolOk('languages_content_list', ['pageLimit' => 100]);
		$codes = array_column($list['items'], 'lang_code');
		$this->assertNotContains($langCode, $codes, 'The low-privilege create must not have created anything.');
	}

	/**
	 * Random `[a-z]{3}-[A-Z]{2}` code matching `LanguageModel::save()`'s own tag regex, unlikely to collide
	 * with a real installed language.
	 */
	private function randomLangCode(): string
	{
		$lower = '';
		$upper = '';

		for ($i = 0; $i < 3; $i++)
		{
			$lower .= chr(random_int(97, 122));
		}

		for ($i = 0; $i < 2; $i++)
		{
			$upper .= chr(random_int(65, 90));
		}

		return $lower . '-' . $upper;
	}
}
