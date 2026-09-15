<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Integration\Tests\Languages;

use Akeeba\Mcp4j\Tests\Integration\AbstractToolTestCase;

/**
 * End-to-end coverage for the twelve `languages_overrides_*` tools
 * ({@see \Akeeba\Component\Mcp4j\Administrator\Tool\Languages\Overrides}). See
 * `.plans/11-overrides-redirects-messages.md` Part A for the coverage this class is meant to satisfy.
 */
class OverridesTest extends AbstractToolTestCase
{
	private function uniqueKey(): string
	{
		return 'MCP4J_TEST_' . strtoupper(bin2hex(random_bytes(4)));
	}

	public function testSiteLifecycle(): void
	{
		$key = $this->uniqueKey();

		$this->registerCleanup(fn () => $this->callTool('languages_overrides_site_delete', ['language' => 'en-GB', 'key' => $key]));

		$created = $this->callToolOk('languages_overrides_site_create', [
			'language' => 'en-GB',
			'key'      => $key,
			'value'    => 'Hello MCP',
		]);

		$this->assertSame($key, $created['key']);
		$this->assertSame('Hello MCP', $created['value']);
		$this->assertSame('en-GB', $created['language']);
		$this->assertSame('site', $created['client']);

		$read = $this->callToolOk('languages_overrides_site_read', ['language' => 'en-GB', 'key' => $key]);
		$this->assertSame('Hello MCP', $read['value']);

		$list = $this->callToolOk('languages_overrides_site_list', ['language' => 'en-GB', 'filterSearch' => $key]);
		$this->assertListShape($list);
		$this->assertCount(1, $list['items']);
		$this->assertSame($key, $list['items'][0]['key']);

		$updated = $this->callToolOk('languages_overrides_site_update', [
			'language' => 'en-GB',
			'key'      => $key,
			'value'    => 'Updated MCP',
		]);
		$this->assertSame('Updated MCP', $updated['value']);

		$readAgain = $this->callToolOk('languages_overrides_site_read', ['language' => 'en-GB', 'key' => $key]);
		$this->assertSame('Updated MCP', $readAgain['value']);

		$deleted = $this->callToolOk('languages_overrides_site_delete', ['language' => 'en-GB', 'key' => $key]);
		$this->assertSame(['deleted' => true, 'key' => $key, 'language' => 'en-GB', 'client' => 'site'], $deleted);

		$this->assertNotFound($this->callTool('languages_overrides_site_read', ['language' => 'en-GB', 'key' => $key]));
	}

	public function testAdminLifecycleAndClientIsolation(): void
	{
		$key = $this->uniqueKey();

		$this->registerCleanup(fn () => $this->callTool('languages_overrides_admin_delete', ['language' => 'en-GB', 'key' => $key]));

		$created = $this->callToolOk('languages_overrides_admin_create', [
			'language' => 'en-GB',
			'key'      => $key,
			'value'    => 'Hello Admin',
		]);
		$this->assertSame('administrator', $created['client']);

		$read = $this->callToolOk('languages_overrides_admin_read', ['language' => 'en-GB', 'key' => $key]);
		$this->assertSame('Hello Admin', $read['value']);

		// Regression guard for the OverrideModel::save() filter.client string/int core bug (Part A fact
		// 3): an admin-client override must never end up written to the site client's file.
		$this->assertNotFound($this->callTool('languages_overrides_site_read', ['language' => 'en-GB', 'key' => $key]));

		$updated = $this->callToolOk('languages_overrides_admin_update', [
			'language' => 'en-GB',
			'key'      => $key,
			'value'    => 'Updated Admin',
		]);
		$this->assertSame('Updated Admin', $updated['value']);

		$deleted = $this->callToolOk('languages_overrides_admin_delete', ['language' => 'en-GB', 'key' => $key]);
		$this->assertSame(['deleted' => true, 'key' => $key, 'language' => 'en-GB', 'client' => 'administrator'], $deleted);

		$this->assertNotFound($this->callTool('languages_overrides_admin_read', ['language' => 'en-GB', 'key' => $key]));
	}

	public function testCreateExistingKeyIsRejectedAndMissingKeyIsNotFound(): void
	{
		$key = $this->uniqueKey();
		$this->registerCleanup(fn () => $this->callTool('languages_overrides_site_delete', ['language' => 'en-GB', 'key' => $key]));

		$this->callToolOk('languages_overrides_site_create', ['language' => 'en-GB', 'key' => $key, 'value' => 'v1']);

		$duplicate = $this->callTool('languages_overrides_site_create', ['language' => 'en-GB', 'key' => $key, 'value' => 'v2']);
		$this->assertTrue($duplicate['isError'], 'Expected a tool error: ' . $duplicate['text']);
		$this->assertStringContainsString('already exists', $duplicate['text']);

		$missingKey = $this->uniqueKey();
		$this->assertNotFound($this->callTool('languages_overrides_site_update', ['language' => 'en-GB', 'key' => $missingKey, 'value' => 'x']));
		$this->assertNotFound($this->callTool('languages_overrides_site_delete', ['language' => 'en-GB', 'key' => $missingKey]));
	}

	/**
	 * `forms/override.xml`'s `override` field validates `DisallowQuotes` on Joomla 5.4.7+/6.1.2+ (absent
	 * entirely on 4.4 — see `Rule/DisallowQuotesRule.php`'s own `@since`), but that rule's very first
	 * check is `Factory::getApplication()->getIdentity()->authorise('core.admin')`: a Super User is always
	 * exempt from the quote restriction. Every E2E credential this suite has (`self::$token`,
	 * `self::$lowPrivToken`) is either a Super User (core.admin — exempt) or lacks `core.manage` on
	 * `com_languages` entirely (refused before validation ever runs) — there is no credential this harness
	 * can use that would actually observe the rule refusing a quoted value, on any supported Joomla
	 * version. Rather than assert a "Validation failed" that can never actually happen for the identity
	 * this suite authenticates as, this documents the real, verified behaviour instead — see the final
	 * report's deviations for the plan's original ("value containing a double quote -> Validation failed")
	 * expectation and why it does not hold.
	 */
	public function testDoubleQuoteValueIsAllowedForASuperUser(): void
	{
		$key = $this->uniqueKey();
		$this->registerCleanup(fn () => $this->callTool('languages_overrides_site_delete', ['language' => 'en-GB', 'key' => $key]));

		$created = $this->callToolOk('languages_overrides_site_create', ['language' => 'en-GB', 'key' => $key, 'value' => 'Say "hi"']);
		$this->assertSame('Say "hi"', $created['value']);
	}

	public function testReservedKeyIsRejected(): void
	{
		$yesResult = $this->callTool('languages_overrides_site_create', ['language' => 'en-GB', 'key' => 'yes', 'value' => 'x']);
		$this->assertTrue($yesResult['isError'], 'Expected a tool error: ' . $yesResult['text']);
	}

	public function testLowerCaseKeyIsStoredUppercase(): void
	{
		$key      = strtolower($this->uniqueKey());
		$upperKey = strtoupper($key);

		$this->registerCleanup(fn () => $this->callTool('languages_overrides_site_delete', ['language' => 'en-GB', 'key' => $upperKey]));

		$created = $this->callToolOk('languages_overrides_site_create', ['language' => 'en-GB', 'key' => $key, 'value' => 'lower']);
		$this->assertSame($upperKey, $created['key']);

		$read = $this->callToolOk('languages_overrides_site_read', ['language' => 'en-GB', 'key' => $upperKey]);
		$this->assertSame('lower', $read['value']);
	}

	public function testUnknownAndTraversalLanguageTagsAreNotFound(): void
	{
		$unknown = $this->callTool('languages_overrides_site_list', ['language' => 'xx-XX']);
		$this->assertTrue($unknown['isError'], 'Expected a tool error: ' . $unknown['text']);
		$this->assertStringStartsWith('Not found: language', $unknown['text']);

		// Must never reach a filesystem path build, let alone throw/500 — see class docblock.
		$traversal = $this->callTool('languages_overrides_site_list', ['language' => '../../../tmp/evil']);
		$this->assertTrue($traversal['isError'], 'Expected a tool error: ' . $traversal['text']);
		$this->assertStringStartsWith('Not found: language', $traversal['text']);
	}

	public function testPagination(): void
	{
		$prefix = 'MCP4J_PAGE_' . strtoupper(bin2hex(random_bytes(3)));

		for ($i = 0; $i < 3; $i++)
		{
			$key = $prefix . '_' . $i;
			$this->registerCleanup(fn () => $this->callTool('languages_overrides_site_delete', ['language' => 'en-GB', 'key' => $key]));
			$this->callToolOk('languages_overrides_site_create', ['language' => 'en-GB', 'key' => $key, 'value' => 'v' . $i]);
		}

		$page1 = $this->callToolOk('languages_overrides_site_list', [
			'language'     => 'en-GB',
			'filterSearch' => $prefix,
			'pageLimit'    => 2,
		]);
		$this->assertListShape($page1);
		$this->assertCount(2, $page1['items']);
		$this->assertSame(3, $page1['pagination']['total']);

		$page2 = $this->callToolOk('languages_overrides_site_list', [
			'language'     => 'en-GB',
			'filterSearch' => $prefix,
			'pageLimit'    => 2,
			'pageOffset'   => 2,
		]);
		$this->assertCount(1, $page2['items']);
	}

	public function testCacheRefreshAndSearch(): void
	{
		$refreshed = $this->callToolOk('languages_overrides_cache_refresh');
		$this->assertTrue($refreshed['success']);
		$this->assertSame('site', $refreshed['client']);
		$this->assertSame('en-GB', $refreshed['language']);

		$search = $this->callToolOk('languages_overrides_search', [
			'language'     => 'en-GB',
			'searchString' => 'JSAVE',
			'searchType'   => 'constant',
		]);
		$this->assertListShape($search);
		$this->assertNotEmpty($search['items'], 'Expected at least one result for a JSAVE constant search.');

		$found = false;

		foreach ($search['items'] as $item)
		{
			$this->assertArrayHasKey('constant', $item);
			$this->assertArrayHasKey('string', $item);
			$this->assertArrayHasKey('file', $item);
			$this->assertFalse(str_starts_with($item['file'], '/'), 'Search result "file" must be a relative path: ' . $item['file']);

			if (str_starts_with($item['constant'], 'JSAVE'))
			{
				$found = true;
			}
		}

		$this->assertTrue($found, 'Expected at least one constant starting with JSAVE.');

		$bogus = $this->callTool('languages_overrides_search', [
			'language'     => 'en-GB',
			'searchString' => 'x',
			'searchType'   => 'bogus',
		]);
		$this->assertTrue($bogus['isError'], 'Expected a tool error: ' . $bogus['text']);
	}

	public function testLowPrivilegeUserIsRefused(): void
	{
		$this->assertPermissionDenied($this->callToolAsLowPriv('languages_overrides_site_list', ['language' => 'en-GB']));

		$this->assertPermissionDenied($this->callToolAsLowPriv('languages_overrides_site_create', [
			'language' => 'en-GB',
			'key'      => $this->uniqueKey(),
			'value'    => 'x',
		]));

		$this->assertPermissionDenied($this->callToolAsLowPriv('languages_overrides_search', [
			'language'     => 'en-GB',
			'searchString' => 'x',
		]));
	}
}
