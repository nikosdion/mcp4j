<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Unit\Mapper\Menus;

use Akeeba\Component\Mcp4j\Administrator\Mapper\Menus\MenuItemMapper;
use PHPUnit\Framework\TestCase;

class MenuItemMapperTest extends TestCase
{
	// -----------------------------------------------------------------------------------------------------
	// listState()
	// -----------------------------------------------------------------------------------------------------

	public function testListStateWithEverythingNull(): void
	{
		$this->assertSame(
			[
				'filter.client_id' => 0,
				'filter.published' => '',
				'filter.menutype'  => '',
				'list.ordering'    => 'a.lft',
				'list.direction'   => 'ASC',
			],
			MenuItemMapper::listState(0, null, null, null, null, null)
		);
	}

	public function testListStateClientIdIsAlwaysSet(): void
	{
		$this->assertSame(1, MenuItemMapper::listState(1, null, null, null, null, null)['filter.client_id']);
	}

	public function testListStateSearchIsAbsentWhenNull(): void
	{
		$this->assertArrayNotHasKey('filter.search', MenuItemMapper::listState(0, null, null, null, null, null));
	}

	public function testListStateSearchIsSetWhenGiven(): void
	{
		$this->assertSame('foo', MenuItemMapper::listState(0, 'foo', null, null, null, null)['filter.search']);
	}

	public function testListStatePublishedDefaultsToEmptyStringWhenNull(): void
	{
		$this->assertSame('', MenuItemMapper::listState(0, null, null, null, null, null)['filter.published']);
	}

	public function testListStatePublishedIsStringifiedWhenGiven(): void
	{
		$this->assertSame('0', MenuItemMapper::listState(0, null, 0, null, null, null)['filter.published']);
		$this->assertSame('1', MenuItemMapper::listState(0, null, 1, null, null, null)['filter.published']);
		$this->assertSame('-2', MenuItemMapper::listState(0, null, -2, null, null, null)['filter.published']);
	}

	public function testListStateLanguageIsAbsentWhenNull(): void
	{
		$this->assertArrayNotHasKey('filter.language', MenuItemMapper::listState(0, null, null, null, null, null));
	}

	public function testListStateLanguageIsSetWhenGiven(): void
	{
		$this->assertSame('en-GB', MenuItemMapper::listState(0, null, null, 'en-GB', null, null)['filter.language']);
	}

	public function testListStateMenutypeDefaultsToEmptyStringWhenNull(): void
	{
		$this->assertSame('', MenuItemMapper::listState(0, null, null, null, null, null)['filter.menutype']);
	}

	public function testListStateMenutypeIsSetWhenGiven(): void
	{
		$this->assertSame('mainmenu', MenuItemMapper::listState(0, null, null, null, 'mainmenu', null)['filter.menutype']);
	}

	public function testListStateLevelIsAbsentWhenNull(): void
	{
		$this->assertArrayNotHasKey('filter.level', MenuItemMapper::listState(0, null, null, null, null, null));
	}

	public function testListStateLevelIsSetWhenGiven(): void
	{
		$this->assertSame(2, MenuItemMapper::listState(0, null, null, null, null, 2)['filter.level']);
	}

	public function testListStateAlwaysOrdersByLftAscending(): void
	{
		$result = MenuItemMapper::listState(1, 'x', 1, 'en-GB', 'mainmenu', 2);

		$this->assertSame('a.lft', $result['list.ordering']);
		$this->assertSame('ASC', $result['list.direction']);
	}

	// -----------------------------------------------------------------------------------------------------
	// createData()
	// -----------------------------------------------------------------------------------------------------

	public function testCreateDataWithMinimalArguments(): void
	{
		$result = MenuItemMapper::createData(0, 'My Item', null, 'mainmenu', 'url', 'https://example.com', null, 1, null, '*', null, null, null, null);

		$this->assertSame('My Item', $result['title']);
		$this->assertSame('mainmenu', $result['menutype']);
		$this->assertSame('url', $result['type']);
		$this->assertSame('https://example.com', $result['link']);
		$this->assertSame(1, $result['parent_id'], 'A null parentId must become 1 (the root).');
		$this->assertSame(1, $result['published']);
		$this->assertSame(1, $result['access'], 'access must default to 1 when null.');
		$this->assertSame('*', $result['language']);
		$this->assertSame(0, $result['client_id']);
		$this->assertSame('', $result['alias'], 'alias has no DB column default and must always be present (empty when not given).');
		$this->assertArrayNotHasKey('note', $result);
		$this->assertArrayNotHasKey('browserNav', $result);
		$this->assertArrayNotHasKey('home', $result);
		$this->assertArrayNotHasKey('request', $result);
	}

	public function testCreateDataParentIdZeroBecomesRoot(): void
	{
		$result = MenuItemMapper::createData(0, 'T', null, 'm', 'url', 'l', 0, 1, null, '*', null, null, null, null);

		$this->assertSame(1, $result['parent_id']);
	}

	public function testCreateDataParentIdNegativeBecomesRoot(): void
	{
		$result = MenuItemMapper::createData(0, 'T', null, 'm', 'url', 'l', -5, 1, null, '*', null, null, null, null);

		$this->assertSame(1, $result['parent_id']);
	}

	public function testCreateDataPositiveParentIdIsKept(): void
	{
		$result = MenuItemMapper::createData(0, 'T', null, 'm', 'url', 'l', 42, 1, null, '*', null, null, null, null);

		$this->assertSame(42, $result['parent_id']);
	}

	public function testCreateDataEmptyAliasBecomesEmptyString(): void
	{
		$result = MenuItemMapper::createData(0, 'T', '', 'm', 'url', 'l', null, 1, null, '*', null, null, null, null);

		$this->assertSame('', $result['alias']);
	}

	public function testCreateDataNonEmptyAliasIsIncluded(): void
	{
		$result = MenuItemMapper::createData(0, 'T', 'my-alias', 'm', 'url', 'l', null, 1, null, '*', null, null, null, null);

		$this->assertSame('my-alias', $result['alias']);
	}

	public function testCreateDataExplicitAccessOverridesDefault(): void
	{
		$result = MenuItemMapper::createData(0, 'T', null, 'm', 'url', 'l', null, 1, 5, '*', null, null, null, null);

		$this->assertSame(5, $result['access']);
	}

	public function testCreateDataHomeTrueBecomesOne(): void
	{
		$result = MenuItemMapper::createData(0, 'T', null, 'm', 'component', 'l', null, 1, null, '*', null, null, true, null);

		$this->assertSame(1, $result['home']);
	}

	public function testCreateDataHomeFalseBecomesZero(): void
	{
		$result = MenuItemMapper::createData(0, 'T', null, 'm', 'component', 'l', null, 1, null, '*', null, null, false, null);

		$this->assertSame(0, $result['home']);
	}

	public function testCreateDataBrowserNavStringIsCastToInt(): void
	{
		$result = MenuItemMapper::createData(0, 'T', null, 'm', 'url', 'l', null, 1, null, '*', null, '1', null, null);

		$this->assertSame(1, $result['browserNav']);
		$this->assertIsInt($result['browserNav']);
	}

	public function testCreateDataRequestIsIncludedWhenGiven(): void
	{
		$result = MenuItemMapper::createData(0, 'T', null, 'm', 'component', 'l', null, 1, null, '*', null, null, null, ['id' => 5]);

		$this->assertSame(['id' => 5], $result['request']);
	}

	public function testCreateDataNoteIsIncludedWhenGiven(): void
	{
		$result = MenuItemMapper::createData(0, 'T', null, 'm', 'url', 'l', null, 1, null, '*', 'A note', null, null, null);

		$this->assertSame('A note', $result['note']);
	}

	// -----------------------------------------------------------------------------------------------------
	// updateData()
	// -----------------------------------------------------------------------------------------------------

	public function testUpdateDataWithEverythingNullReturnsEmptyArray(): void
	{
		$result = MenuItemMapper::updateData(null, null, null, null, null, null, null, null, null, null, null, null, null);

		$this->assertSame([], $result);
	}

	public function testUpdateDataKeepsOnlyExplicitlyGivenValues(): void
	{
		$result = MenuItemMapper::updateData('New note title', null, null, null, null, null, null, null, null, null, null, null, null);

		$this->assertSame(['title' => 'New note title'], $result);
	}

	public function testUpdateDataParentIdZeroBecomesOne(): void
	{
		$result = MenuItemMapper::updateData(null, null, null, null, null, 0, null, null, null, null, null, null, null);

		$this->assertSame(['parent_id' => 1], $result);
	}

	public function testUpdateDataPositiveParentIdIsKept(): void
	{
		$result = MenuItemMapper::updateData(null, null, null, null, null, 9, null, null, null, null, null, null, null);

		$this->assertSame(['parent_id' => 9], $result);
	}

	public function testUpdateDataPublishedZeroIsKeptNotTreatedAsNull(): void
	{
		$result = MenuItemMapper::updateData(null, null, null, null, null, null, 0, null, null, null, null, null, null);

		$this->assertSame(['published' => 0], $result);
	}

	public function testUpdateDataHomeFalseIsKeptAsZero(): void
	{
		$result = MenuItemMapper::updateData(null, null, null, null, null, null, null, null, null, null, null, false, null);

		$this->assertSame(['home' => 0], $result);
	}

	public function testUpdateDataHomeTrueIsKeptAsOne(): void
	{
		$result = MenuItemMapper::updateData(null, null, null, null, null, null, null, null, null, null, null, true, null);

		$this->assertSame(['home' => 1], $result);
	}

	public function testUpdateDataBrowserNavIsCastToInt(): void
	{
		$result = MenuItemMapper::updateData(null, null, null, null, null, null, null, null, null, null, '1', null, null);

		$this->assertSame(['browserNav' => 1], $result);
	}

	public function testUpdateDataRequestEmptyArrayIsKeptAsAClear(): void
	{
		$result = MenuItemMapper::updateData(null, null, null, null, null, null, null, null, null, null, null, null, []);

		$this->assertSame(['request' => []], $result);
	}

	public function testUpdateDataEveryFieldTogether(): void
	{
		$result = MenuItemMapper::updateData(
			'Title',
			'alias',
			'menutype',
			'component',
			'index.php?option=com_content',
			3,
			1,
			2,
			'en-GB',
			'Note',
			'1',
			true,
			['id' => 5]
		);

		$this->assertSame(
			[
				'title'      => 'Title',
				'alias'      => 'alias',
				'menutype'   => 'menutype',
				'type'       => 'component',
				'link'       => 'index.php?option=com_content',
				'parent_id'  => 3,
				'published'  => 1,
				'access'     => 2,
				'language'   => 'en-GB',
				'note'       => 'Note',
				'browserNav' => 1,
				'home'       => 1,
				'request'    => ['id' => 5],
			],
			$result
		);
	}

	// -----------------------------------------------------------------------------------------------------
	// buildComponentLink()
	// -----------------------------------------------------------------------------------------------------

	public function testBuildComponentLinkMergesRequestIntoLink(): void
	{
		$result = MenuItemMapper::buildComponentLink('index.php?option=com_content&view=category', ['id' => 2]);

		$this->assertSame('index.php?option=com_content&view=category&id=2', $result['link']);
		$this->assertSame(['id' => 2], $result['request']);
	}

	public function testBuildComponentLinkExistingLinkArgumentSatisfiesRequest(): void
	{
		// The link already carries "id" in its query string — it must survive even though $request is empty.
		$result = MenuItemMapper::buildComponentLink('index.php?option=com_content&view=article&id=7', []);

		$this->assertSame(7, (int) $result['request']['id']);
		$this->assertStringContainsString('id=7', $result['link']);
	}

	public function testBuildComponentLinkDropsEmptyStringRequestValues(): void
	{
		$result = MenuItemMapper::buildComponentLink('index.php?option=com_content&view=category', ['id' => '']);

		$this->assertArrayNotHasKey('id', $result['request']);
		$this->assertStringNotContainsString('id=', $result['link']);
	}

	public function testBuildComponentLinkDropsNullRequestValues(): void
	{
		$result = MenuItemMapper::buildComponentLink('index.php?option=com_content&view=category', ['id' => null]);

		$this->assertArrayNotHasKey('id', $result['request']);
	}

	public function testBuildComponentLinkPreservesOtherExistingArguments(): void
	{
		$result = MenuItemMapper::buildComponentLink('index.php?option=com_content&view=article&id=7&Itemid=42', ['id' => 9]);

		$this->assertSame(9, (int) $result['request']['id'], 'An explicit $request value overrides the link\'s own.');
		$this->assertStringContainsString('Itemid=42', $result['link']);
		$this->assertStringContainsString('id=9', $result['link']);
	}

	public function testBuildComponentLinkExcludesOptionViewLayoutFromRequest(): void
	{
		$result = MenuItemMapper::buildComponentLink('index.php?option=com_content&view=category&layout=blog', []);

		$this->assertArrayNotHasKey('option', $result['request']);
		$this->assertArrayNotHasKey('view', $result['request']);
		$this->assertArrayNotHasKey('layout', $result['request']);
		$this->assertStringContainsString('option=com_content', $result['link']);
		$this->assertStringContainsString('view=category', $result['link']);
		$this->assertStringContainsString('layout=blog', $result['link']);
	}

	public function testBuildComponentLinkWithNoQueryStringAtAll(): void
	{
		$result = MenuItemMapper::buildComponentLink('index.php', ['id' => 3]);

		$this->assertSame(['id' => 3], $result['request']);
		$this->assertSame('index.php?id=3', $result['link']);
	}

	public function testBuildComponentLinkWithoutIndexPhpPrefixStillRoundTrips(): void
	{
		// No "index.php" prefix, but the "?" still marks the query string, which is all parse_url() needs.
		$result = MenuItemMapper::buildComponentLink('?option=com_content&view=category&id=2', []);

		$this->assertSame(2, (int) $result['request']['id']);
	}

	// -----------------------------------------------------------------------------------------------------
	// isAllowedUrlLink()
	// -----------------------------------------------------------------------------------------------------

	public function testIsAllowedUrlLinkAcceptsHttps(): void
	{
		$this->assertTrue(MenuItemMapper::isAllowedUrlLink('https://example.com'));
	}

	public function testIsAllowedUrlLinkAcceptsHttp(): void
	{
		$this->assertTrue(MenuItemMapper::isAllowedUrlLink('http://example.com'));
	}

	public function testIsAllowedUrlLinkAcceptsMailto(): void
	{
		$this->assertTrue(MenuItemMapper::isAllowedUrlLink('mailto:a@b.example'));
	}

	public function testIsAllowedUrlLinkAcceptsRelativePath(): void
	{
		$this->assertTrue(MenuItemMapper::isAllowedUrlLink('/foo'));
	}

	public function testIsAllowedUrlLinkRejectsJavascript(): void
	{
		$this->assertFalse(MenuItemMapper::isAllowedUrlLink('javascript:alert(1)'));
	}

	public function testIsAllowedUrlLinkRejectsJavascriptRegardlessOfCase(): void
	{
		$this->assertFalse(MenuItemMapper::isAllowedUrlLink('JaVaScRiPt:alert(1)'));
	}

	public function testIsAllowedUrlLinkRejectsDataUri(): void
	{
		$this->assertFalse(MenuItemMapper::isAllowedUrlLink('data:text/html,<script>alert(1)</script>'));
	}

	// -----------------------------------------------------------------------------------------------------
	// Field whitelists — hard-coded so a future edit is deliberate.
	// -----------------------------------------------------------------------------------------------------

	public function testItemFieldsMatchesCoreJsonapiViewMinusTypeAlias(): void
	{
		$this->assertSame(
			[
				'id', 'parent_id', 'level', 'lft', 'rgt', 'alias', 'menutype', 'title', 'note', 'path', 'link', 'type',
				'published', 'component_id', 'checked_out', 'checked_out_time', 'browserNav', 'access', 'img', 'template_style_id',
				'params', 'home', 'language', 'client_id', 'publish_up', 'publish_down', 'request', 'associations', 'menuordering',
			],
			MenuItemMapper::ITEM_FIELDS
		);
		$this->assertNotContains('typeAlias', MenuItemMapper::ITEM_FIELDS);
	}

	public function testListFieldsMatchesCoreJsonapiViewMinusPublishedAndLanguageImage(): void
	{
		$this->assertSame(
			[
				'id', 'menutype', 'title', 'alias', 'note', 'path', 'link', 'type', 'parent_id', 'level',
				'component_id', 'checked_out', 'checked_out_time', 'browserNav', 'access', 'img', 'template_style_id', 'params',
				'lft', 'rgt', 'home', 'language', 'client_id', 'enabled', 'publish_up', 'publish_down', 'published',
				'language_title', 'language_sef', 'editor', 'componentname', 'access_level', 'menutype_id', 'menutype_title',
				'association', 'name',
			],
			MenuItemMapper::LIST_FIELDS
		);
		$this->assertNotContains('a.published', MenuItemMapper::LIST_FIELDS);
		$this->assertNotContains('language_image', MenuItemMapper::LIST_FIELDS);
	}

	public function testItemFieldsExcludesSensitiveKeys(): void
	{
		$sensitive = ['password', 'otpKey', 'otep', 'activation', 'params_encrypted'];

		foreach ($sensitive as $key)
		{
			$this->assertNotContains($key, MenuItemMapper::ITEM_FIELDS);
			$this->assertNotContains($key, MenuItemMapper::LIST_FIELDS);
		}
	}

	public function testIntFieldsAndJsonFields(): void
	{
		$this->assertSame(
			['parent_id', 'level', 'lft', 'rgt', 'published', 'component_id', 'checked_out', 'browserNav',
				'access', 'template_style_id', 'home', 'client_id', 'enabled', 'menutype_id', 'menuordering'],
			MenuItemMapper::INT_FIELDS
		);
		$this->assertSame(['params'], MenuItemMapper::JSON_FIELDS);
	}
}
