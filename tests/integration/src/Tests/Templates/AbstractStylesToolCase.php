<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Integration\Tests\Templates;

use Akeeba\Mcp4j\Tests\Integration\AbstractToolTestCase;

/**
 * Shared E2E scenarios for the `templates_sitestyles_*` and `templates_adminstyles_*` tool pairs
 * ({@see \Akeeba\Component\Mcp4j\Administrator\Tool\Templates\SiteStyles} and
 * {@see \Akeeba\Component\Mcp4j\Administrator\Tool\Templates\AdminStyles}) — identical behaviour except for
 * the client id and template used, parameterised here. See `SiteStylesTest`/`AdminStylesTest` for the
 * concrete subclasses (PHPUnit skips this abstract class during discovery) and
 * `.plans/07-modules-templates.md` Part B "Tests" for the scenario list this class implements.
 *
 * Fact 6 in the plan ("assignment preservation is not E2E-testable: no MCP tool can set a menu item's
 * template_style_id") is covered by code review only, not by a test here — see the final report.
 */
abstract class AbstractStylesToolCase extends AbstractToolTestCase
{
	/**
	 * e.g. "templates_sitestyles" or "templates_adminstyles".
	 */
	abstract protected function prefix(): string;

	/**
	 * The sibling class's prefix, used for the cross-client "Not found" scenario.
	 */
	abstract protected function otherPrefix(): string;

	abstract protected function expectedClientId(): int;

	/**
	 * The template every fresh Joomla install ships exactly one (default) style for, on this client.
	 */
	abstract protected function template(): string;

	abstract protected function sampleParamsJson(): string;

	abstract protected function sampleParamsKey(): string;

	abstract protected function sampleParamsValue(): mixed;

	/**
	 * Locates the default style via `_list` (whose `LIST_FIELDS` deliberately omits `inheritable`/`parent`
	 * — see `StyleMapper`), then re-reads it via `_read` for the full record.
	 *
	 * @return array{id: int, home: string, template: string, inheritable: int, parent: string}
	 */
	private function findDefaultStyle(): array
	{
		$list = $this->callToolOk($this->prefix() . '_list', ['filterTemplate' => $this->template()]);
		$this->assertListShape($list);

		foreach ($list['items'] as $item)
		{
			if (($item['home'] ?? null) === '1')
			{
				return $this->callToolOk($this->prefix() . '_read', ['id' => $item['id']]);
			}
		}

		$this->fail(sprintf('Could not find the default %s style by filterTemplate.', $this->template()));
	}

	public function testListShapeAndFilters(): void
	{
		$default = $this->findDefaultStyle();

		$filteredTemplate = $this->callToolOk($this->prefix() . '_list', ['filterTemplate' => $this->template()]);
		$this->assertListShape($filteredTemplate);

		foreach ($filteredTemplate['items'] as $item)
		{
			$this->assertSame($this->template(), $item['template']);
		}

		$title = $this->uniqueName('MCP4j Style Search');

		$created = $this->callToolOk($this->prefix() . '_create', [
			'title'    => $title,
			'template' => $this->template(),
		]);
		$id = $created['id'];
		$this->registerCleanup(fn () => $this->callTool($this->prefix() . '_delete', ['id' => $id]));

		$filteredSearch = $this->callToolOk($this->prefix() . '_list', ['filterSearch' => $title]);
		$this->assertContains($title, array_column($filteredSearch['items'], 'title'));
		$this->assertNotContains($default['title'] ?? '', array_column($filteredSearch['items'], 'title'));
	}

	public function testFullLifecycle(): void
	{
		$default = $this->findDefaultStyle();
		$title   = $this->uniqueName('MCP4j Style Lifecycle');

		$created = $this->callToolOk($this->prefix() . '_create', [
			'title'    => $title,
			'template' => $this->template(),
			'params'   => $this->sampleParamsJson(),
		]);
		$id = $created['id'];
		$this->registerCleanup(fn () => $this->callTool($this->prefix() . '_delete', ['id' => $id]));

		$this->assertKeysPresent($created, ['id', 'template', 'client_id', 'home', 'title', 'inheritable', 'parent', 'params']);
		$this->assertSame($this->template(), $created['template']);
		$this->assertSame($this->expectedClientId(), $created['client_id']);
		$this->assertSame('0', $created['home']);
		$this->assertSame($default['inheritable'], $created['inheritable']);
		$this->assertSame($default['parent'], $created['parent']);
		$this->assertSame($this->sampleParamsValue(), $created['params'][$this->sampleParamsKey()] ?? null);

		$read = $this->callToolOk($this->prefix() . '_read', ['id' => $id]);
		$this->assertSame($this->template(), $read['template']);
		$this->assertSame($this->expectedClientId(), $read['client_id']);
		$this->assertSame('0', $read['home']);
		$this->assertKeysAbsent($read, ['password', 'otpKey', 'otep', 'activation', 'xml']);

		// Partial update: only "title" changes — params must be unaffected.
		$newTitle = $this->uniqueName('MCP4j Style Lifecycle Renamed');
		$updated  = $this->callToolOk($this->prefix() . '_update', ['id' => $id, 'title' => $newTitle]);
		$this->assertSame($newTitle, $updated['title']);
		$this->assertSame($this->sampleParamsValue(), $updated['params'][$this->sampleParamsKey()] ?? null);

		$deleted = $this->callToolOk($this->prefix() . '_delete', ['id' => $id]);
		$this->assertSame(['deleted' => true, 'id' => $id], $deleted);

		$this->assertNotFound($this->callTool($this->prefix() . '_read', ['id' => $id]));
	}

	/**
	 * Default switching: create style B, make it default, verify A demoted; then restore A as default so
	 * the fixture is fully cleaned up regardless of test outcome.
	 */
	public function testDefaultSwitching(): void
	{
		$default = $this->findDefaultStyle();
		$aId     = $default['id'];

		$bTitle = $this->uniqueName('MCP4j Style B');
		$b      = $this->callToolOk($this->prefix() . '_create', [
			'title'    => $bTitle,
			'template' => $this->template(),
		]);
		$bId = $b['id'];

		// Whatever happens below, always try to restore A as the default and remove B.
		$this->registerCleanup(function () use ($aId, $bId) {
			$this->callTool($this->prefix() . '_update', ['id' => $aId, 'home' => true]);
			$this->callTool($this->prefix() . '_delete', ['id' => $bId]);
		});

		$this->assertSame('0', $b['home']);

		$madeDefault = $this->callToolOk($this->prefix() . '_update', ['id' => $bId, 'home' => true]);
		$this->assertSame('1', $madeDefault['home']);

		$aAfter = $this->callToolOk($this->prefix() . '_read', ['id' => $aId]);
		$this->assertSame('0', $aAfter['home'], 'Making B the default must demote A.');

		$restored = $this->callToolOk($this->prefix() . '_update', ['id' => $aId, 'home' => true]);
		$this->assertSame('1', $restored['home']);

		$bAfter = $this->callToolOk($this->prefix() . '_read', ['id' => $bId]);
		$this->assertSame('0', $bAfter['home'], 'Restoring A as default must demote B.');
	}

	public function testDefaultStyleCannotBeUnsetOrDeleted(): void
	{
		$default = $this->findDefaultStyle();

		$unset = $this->callTool($this->prefix() . '_update', ['id' => $default['id'], 'home' => false]);
		$this->assertTrue($unset['isError']);
		$this->assertStringContainsString('default', strtolower($unset['text']));

		$delete = $this->callTool($this->prefix() . '_delete', ['id' => $default['id']]);
		$this->assertTrue($delete['isError']);
		$this->assertStringContainsString('default', strtolower($delete['text']));

		// Both styles (or at least the default) must still be present afterwards.
		$stillThere = $this->callToolOk($this->prefix() . '_read', ['id' => $default['id']]);
		$this->assertSame('1', $stillThere['home']);
	}

	public function testUpdateCannotChangeTemplateAndCreateRejectsUnknownTemplate(): void
	{
		$title = $this->uniqueName('MCP4j Style Immutable');

		$created = $this->callToolOk($this->prefix() . '_create', ['title' => $title, 'template' => $this->template()]);
		$id      = $created['id'];
		$this->registerCleanup(fn () => $this->callTool($this->prefix() . '_delete', ['id' => $id]));

		$changeTemplate = $this->callTool($this->prefix() . '_update', ['id' => $id, 'template' => 'does_not_exist']);
		$this->assertTrue($changeTemplate['isError']);
		$this->assertStringContainsString('template', strtolower($changeTemplate['text']));

		$badTemplate = $this->callTool($this->prefix() . '_create', ['title' => $this->uniqueName('MCP4j Style BadTpl'), 'template' => 'nope']);
		$this->assertTrue($badTemplate['isError']);
		$this->assertStringStartsWith('Not found: template nope', $badTemplate['text']);
	}

	public function testCrossClientReadIsNotFound(): void
	{
		$title = $this->uniqueName('MCP4j Style CrossClient');

		$created = $this->callToolOk($this->prefix() . '_create', ['title' => $title, 'template' => $this->template()]);
		$id      = $created['id'];
		$this->registerCleanup(fn () => $this->callTool($this->prefix() . '_delete', ['id' => $id]));

		$this->assertNotFound($this->callTool($this->otherPrefix() . '_read', ['id' => $id]));
	}

	public function testReadNonexistentIdIsNotFound(): void
	{
		$this->assertNotFound($this->callTool($this->prefix() . '_read', ['id' => 999999]));
	}

	public function testLowPrivilegeUserIsRefusedListAndUpdate(): void
	{
		$default = $this->findDefaultStyle();

		$listResult = $this->callToolAsLowPriv($this->prefix() . '_list');
		$this->assertPermissionDenied($listResult);

		$updateResult = $this->callToolAsLowPriv($this->prefix() . '_update', ['id' => $default['id'], 'title' => 'Should not be applied']);
		$this->assertPermissionDenied($updateResult);

		// Prove nothing was actually changed, as the super user.
		$stillThere = $this->callToolOk($this->prefix() . '_read', ['id' => $default['id']]);
		$this->assertNotSame('Should not be applied', $stillThere['title']);
	}
}
