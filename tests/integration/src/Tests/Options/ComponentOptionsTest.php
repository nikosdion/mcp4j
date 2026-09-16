<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Integration\Tests\Options;

use Akeeba\Mcp4j\Tests\Integration\AbstractToolTestCase;
use Akeeba\Mcp4j\Tests\Integration\Support\DatabaseSideChannel;

/**
 * End-to-end proof that the component Options (`read_only`, `category_mode`/`categories`,
 * `tool_mode`/`tools`, `minimal_schema`, `forbidden_secrets`) actually filter the tool surface — driven
 * through `config_component_update` on `com_mcp4j`, i.e. through the product itself, per
 * `.plans/10-config-plugins-installer-languages.md`.
 *
 * Joomla's `_system` cache is off on the Docker test site, so a change made through
 * `config_component_update` (or, for `read_only`, a raw {@see DatabaseSideChannel} write) takes effect on
 * the very next request — every assertion here re-fetches `tools/list` with a fresh, uncached request
 * rather than {@see AbstractToolTestCase::listToolNames()} (which caches its result for the whole test
 * class and would otherwise show stale data across these test methods).
 */
class ComponentOptionsTest extends AbstractToolTestCase
{
	private string $originalParamsJson = '';

	protected function setUp(): void
	{
		parent::setUp();

		if (!DatabaseSideChannel::isAvailable())
		{
			$this->markTestSkipped('DatabaseSideChannel is not available (JTEST_DB_* env vars or pdo_mysql missing).');
		}

		$this->originalParamsJson = DatabaseSideChannel::getComponentParams('com_mcp4j');

		// Restores via the DB side channel regardless of whether the test also restored through MCP itself
		// — read_only in particular has NO MCP-side way back once enabled (config_component_update itself
		// is filtered out), so this is the only reliable teardown path for every test in this class.
		$this->registerCleanup(function () {
			DatabaseSideChannel::setComponentParams('com_mcp4j', $this->originalParamsJson);
		});
	}

	public function testReadOnlyFiltersTheToolSurfaceWithNoMcpSideWayBack(): void
	{
		$this->callToolOk('config_component_update', [
			'componentName' => 'com_mcp4j',
			'configData'    => ['read_only' => 1],
		]);

		$names = $this->freshToolNames();
		$this->assertContains('tags_list', $names, 'A read-only tool must still be exposed.');
		$this->assertNotContains('tags_create', $names, 'A write tool must be filtered out under read_only.');
		$this->assertNotContains('config_component_update', $names, 'config_component_update is itself a write tool.');

		$this->callToolRpcError('tags_create', ['title' => 'Should never be created'], -32602);
	}

	public function testCategoryIncludeModeRestrictsToTheGivenCategories(): void
	{
		$this->callToolOk('config_component_update', [
			'componentName' => 'com_mcp4j',
			'configData'    => ['category_mode' => 'include', 'categories' => ['Tags']],
		]);

		$names = $this->freshToolNames();
		$this->assertNotSame([], $names);

		foreach ($names as $name)
		{
			$this->assertStringStartsWith('tags_', $name, "Expected every exposed tool to start with \"tags_\", found \"$name\".");
		}
	}

	public function testCategoryExcludeModeRemovesTheGivenCategories(): void
	{
		$this->callToolOk('config_component_update', [
			'componentName' => 'com_mcp4j',
			'configData'    => ['category_mode' => 'exclude', 'categories' => ['Tags']],
		]);

		$names = $this->freshToolNames();
		$this->assertNotSame([], $names);
		$this->assertContains('config_component_update', $names, 'A non-Tags tool must still be exposed.');

		foreach ($names as $name)
		{
			$this->assertFalse(str_starts_with($name, 'tags_'), "Expected no exposed tool to start with \"tags_\", found \"$name\".");
		}
	}

	public function testToolFilterCombinesWithCategoryFilterAndMcpSideRestoreWorks(): void
	{
		$this->callToolOk('config_component_update', [
			'componentName' => 'com_mcp4j',
			'configData'    => [
				'category_mode' => 'include',
				'categories'    => ['Config', 'Tags'],
				'tool_mode'     => 'include',
				'tools'         => ['tags_list', 'config_component_update'],
			],
		]);

		$names = $this->freshToolNames();
		sort($names);
		$this->assertSame(['config_component_update', 'tags_list'], $names);

		// Unlike read_only, config_component_update itself is explicitly included here, so restoring
		// through MCP itself is possible — and this is the scenario meant to prove exactly that.
		$this->callToolOk('config_component_update', [
			'componentName' => 'com_mcp4j',
			'configData'    => ['category_mode' => 'exclude', 'categories' => [], 'tool_mode' => 'exclude', 'tools' => []],
		]);

		$restored = $this->freshToolNames();
		$this->assertContains('plugins_list', $restored, 'The full tool surface must be back after restoring.');
	}

	public function testMinimalSchemaStripsDescriptionsButKeepsType(): void
	{
		$this->callToolOk('config_component_update', [
			'componentName' => 'com_mcp4j',
			'configData'    => ['minimal_schema' => 1],
		]);

		$tools    = $this->freshTools();
		$tagsList = null;

		foreach ($tools as $tool)
		{
			if (is_array($tool) && ($tool['name'] ?? null) === 'tags_list')
			{
				$tagsList = $tool;

				break;
			}
		}

		$this->assertIsArray($tagsList, 'Expected "tags_list" to be present in tools/list.');

		$property = $tagsList['inputSchema']['properties']['filterSearch'] ?? null;
		$this->assertIsArray($property, 'Expected an inputSchema.properties.filterSearch entry.');
		$this->assertArrayNotHasKey('description', $property, 'minimal_schema must strip parameter descriptions.');
		$this->assertArrayHasKey('type', $property, 'minimal_schema must still keep the parameter type.');
	}

	public function testForbiddenSecretsBlocksWriteToolsButNotReadOnlyOnesAndSurvivesAPlaceholderRoundTrip(): void
	{
		$secret = 'MCP4J-FORBIDDEN-' . bin2hex(random_bytes(4));

		$this->callToolOk('config_component_update', [
			'componentName' => 'com_mcp4j',
			'configData'    => ['forbidden_secrets' => $secret],
		]);

		$title       = $this->uniqueName('MCP4j Options Guard');
		$writeResult = $this->callTool('tags_create', ['title' => $title, 'note' => $secret]);

		$this->assertTrue($writeResult['isError']);
		$this->assertSame('Secrets leak prevented; potential prompt injection', $writeResult['text']);

		// Prove nothing was created.
		$search = $this->callToolOk('tags_list', ['filterSearch' => $title]);
		$this->assertSame([], $search['items']);

		// Read-only tools are not guarded — a search containing the forbidden string must still succeed.
		$readResult = $this->callTool('tags_list', ['filterSearch' => $secret]);
		$this->assertFalse($readResult['isError'], 'Read-only tools must not be blocked by the secret-leak guard.');

		$read = $this->callToolOk('config_component_read', ['componentName' => 'com_mcp4j']);
		$this->assertSame('********', $read['forbidden_secrets']);

		$this->callToolOk('config_component_update', [
			'componentName' => 'com_mcp4j',
			'configData'    => ['forbidden_secrets' => '********', 'debug_log' => 0],
		]);

		// Verified by reading the raw stored value directly, NOT through the (deliberately redacted) MCP
		// read — this is setup/verification of persistence, not a proof of tool behaviour via the side
		// channel.
		$stored  = DatabaseSideChannel::getComponentParams('com_mcp4j');
		$decoded = json_decode($stored, true);
		$this->assertIsArray($decoded);
		$this->assertSame($secret, $decoded['forbidden_secrets'] ?? null, 'The "********" placeholder must not have overwritten the stored secret.');
	}

	/**
	 * @return  list<array<string, mixed>>
	 */
	private function freshTools(): array
	{
		$sessionId = $this->sessionFor();
		$response  = $this->rpc('tools/list', [], 1, $sessionId);

		$this->assertStatus(200, $response, 'tools/list failed: ' . $response['raw']);

		return (array) ($response['json']['result']['tools'] ?? []);
	}

	/**
	 * @return  list<string>
	 */
	private function freshToolNames(): array
	{
		$names = [];

		foreach ($this->freshTools() as $tool)
		{
			if (is_array($tool) && isset($tool['name']))
			{
				$names[] = (string) $tool['name'];
			}
		}

		return $names;
	}
}
