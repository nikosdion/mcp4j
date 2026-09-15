<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Integration\Tests\Modules;

use Akeeba\Mcp4j\Tests\Integration\AbstractToolTestCase;

/**
 * Shared E2E scenarios for the `modules_site_*` and `modules_admin_*` tool pairs
 * ({@see \Akeeba\Component\Mcp4j\Administrator\Tool\Modules\SiteModules} and
 * {@see \Akeeba\Component\Mcp4j\Administrator\Tool\Modules\AdminModules}) — identical behaviour except for
 * the client id, parameterised here on the tool name prefix. See `SiteModulesTest`/`AdminModulesTest` for
 * the concrete subclasses (PHPUnit skips this abstract class during discovery) and
 * `.plans/07-modules-templates.md` Part A "Tests" for the scenario list this class implements.
 *
 * `mod_custom` is used throughout — it exists, enabled, for both clients on every supported Joomla version.
 */
abstract class AbstractModulesToolCase extends AbstractToolTestCase
{
	/**
	 * e.g. "modules_site" or "modules_admin".
	 */
	abstract protected function prefix(): string;

	/**
	 * The sibling class's prefix, used for the cross-client "Not found" scenario.
	 */
	abstract protected function otherPrefix(): string;

	abstract protected function expectedClientId(): int;

	/**
	 * A params JSON string with exactly one recognised key for this client's `mod_custom`
	 * `<config>` — site and admin have different config fields, see the plan.
	 */
	abstract protected function sampleParamsJson(): string;

	abstract protected function sampleParamsKey(): string;

	abstract protected function sampleParamsValue(): mixed;

	/**
	 * A `params` JSON string every `_create` call in this class that doesn't otherwise specify its own
	 * `params` passes along, or null when this client's `mod_custom` needs none.
	 *
	 * `mod_custom`'s *site* config.xml declares `layout` (`type="modulelayout"`, `validate="moduleLayout"`)
	 * with no `default` attribute. A real browser submission always has *some* option pre-selected (the
	 * `<select>` element's first `<option>`, e.g. `_:default`), so this is never actually empty in normal
	 * use — but our tool, going straight to the model, submits exactly what it's given. When `params`
	 * doesn't include `layout` at all, `Form::validate()` runs `ModuleLayoutRule` against a missing (`null`)
	 * value anyway (it only skips a rule for `required` fields left empty — see
	 * `Joomla\CMS\Form\FormField::validate()`), and the regex never matches null/empty, so the save fails
	 * with "Invalid field: Layout". This is a real Joomla core gap between the direct-model bridge and a
	 * browser form (found by this plan's own E2E suite) — see the final report — worked around here, for
	 * *site* modules only, by always supplying a concrete `layout` value.
	 */
	abstract protected function baselineParams(): ?string;

	public function testListShapeAndFilters(): void
	{
		$needle       = $this->uniqueName('MCP4j Module Needle');
		$hay          = $this->uniqueName('MCP4j Module Hay');
		$position     = $this->uniqueAlias('mcp4j-pos');
		$unpublished  = $this->uniqueName('MCP4j Module Unpublished');

		$needleModule = $this->callToolOk($this->prefix() . '_create', [
			'title'    => $needle,
			'module'   => 'mod_custom',
			'position' => $position,
			'params'   => $this->baselineParams(),
		]);
		$this->registerCleanup(fn () => $this->callTool($this->prefix() . '_delete', ['id' => $needleModule['id']]));

		$hayModule = $this->callToolOk($this->prefix() . '_create', [
			'title'  => $hay,
			'module' => 'mod_custom',
			'params' => $this->baselineParams(),
		]);
		$this->registerCleanup(fn () => $this->callTool($this->prefix() . '_delete', ['id' => $hayModule['id']]));

		$unpublishedModule = $this->callToolOk($this->prefix() . '_create', [
			'title'     => $unpublished,
			'module'    => 'mod_custom',
			'published' => 0,
			'params'    => $this->baselineParams(),
		]);
		$this->registerCleanup(fn () => $this->callTool($this->prefix() . '_delete', ['id' => $unpublishedModule['id']]));

		$all = $this->callToolOk($this->prefix() . '_list');
		$this->assertListShape($all);

		$filteredSearch = $this->callToolOk($this->prefix() . '_list', ['filterSearch' => $needle]);
		$titles          = array_column($filteredSearch['items'], 'title');
		$this->assertContains($needle, $titles);
		$this->assertNotContains($hay, $titles);

		$filteredModule = $this->callToolOk($this->prefix() . '_list', ['filterModule' => 'mod_custom', 'filterSearch' => $needle]);
		$this->assertContains($needle, array_column($filteredModule['items'], 'title'));

		$filteredPosition = $this->callToolOk($this->prefix() . '_list', ['filterPosition' => $position]);
		$positionTitles   = array_column($filteredPosition['items'], 'title');
		$this->assertContains($needle, $positionTitles);
		$this->assertNotContains($hay, $positionTitles);

		$filteredState = $this->callToolOk($this->prefix() . '_list', ['filterSearch' => $unpublished, 'filterState' => 0]);
		$this->assertContains($unpublished, array_column($filteredState['items'], 'title'));
	}

	public function testFullLifecycle(): void
	{
		$title    = $this->uniqueName('MCP4j Module Lifecycle');
		$position = $this->uniqueAlias('mcp4j-pos');

		$created = $this->callToolOk($this->prefix() . '_create', [
			'title'    => $title,
			'module'   => 'mod_custom',
			'position' => $position,
			'params'   => $this->sampleParamsJson(),
		]);
		$id = $created['id'];
		$this->registerCleanup(fn () => $this->callTool($this->prefix() . '_delete', ['id' => $id]));

		$this->assertKeysPresent($created, ['id', 'title', 'module', 'position', 'client_id', 'params', 'assignment']);
		$this->assertSame($title, $created['title']);
		$this->assertSame('mod_custom', $created['module']);
		$this->assertSame($position, $created['position']);
		$this->assertSame($this->expectedClientId(), $created['client_id']);
		// ModuleModel::getItem() computes "assignment" itself, from #__modules_menu rows, as a genuine PHP
		// int (0/1/-1) or the string '-' — never a stringified int — see
		// .plans/07-modules-templates.md Part A "Key facts" §3.
		$this->assertSame(0, $created['assignment'], 'assignment must default to 0 (all pages).');
		$this->assertIsArray($created['params']);
		$this->assertSame($this->sampleParamsValue(), $created['params'][$this->sampleParamsKey()] ?? null);

		$read = $this->callToolOk($this->prefix() . '_read', ['id' => $id]);
		$this->assertSame('mod_custom', $read['module']);
		$this->assertSame($position, $read['position']);
		$this->assertSame($this->expectedClientId(), $read['client_id']);
		$this->assertSame($this->sampleParamsValue(), $read['params'][$this->sampleParamsKey()] ?? null);
		$this->assertKeysAbsent($read, ['password', 'otpKey', 'otep', 'activation', 'xml']);

		// Partial update: only "note" changes — title/position/params/assignment must be unaffected.
		$updated = $this->callToolOk($this->prefix() . '_update', ['id' => $id, 'note' => 'Updated note']);
		$this->assertSame('Updated note', $updated['note']);
		$this->assertSame($title, $updated['title'], 'A partial update must not touch fields it was not given.');
		$this->assertSame($position, $updated['position']);
		$this->assertSame($this->sampleParamsValue(), $updated['params'][$this->sampleParamsKey()] ?? null);
		$this->assertSame(0, $updated['assignment']);

		// Partial params update: only one (different, but real — mod_custom's config.xml <fields> group
		// only keeps known field names; an arbitrary key would be silently dropped by Form::filter()) params
		// key changes — others must be kept.
		$otherParamsUpdate = $this->callToolOk($this->prefix() . '_update', [
			'id'     => $id,
			'params' => ['cache_time' => 1800],
		]);
		$this->assertSame(1800, $otherParamsUpdate['params']['cache_time'] ?? null);
		$this->assertSame(
			$this->sampleParamsValue(),
			$otherParamsUpdate['params'][$this->sampleParamsKey()] ?? null,
			'A partial params update must keep params keys it did not mention.'
		);

		$trashed = $this->callToolOk($this->prefix() . '_trash', ['id' => $id]);
		$this->assertSame(-2, $trashed['published']);

		$deleted = $this->callToolOk($this->prefix() . '_delete', ['id' => $id]);
		$this->assertSame(['deleted' => true, 'id' => $id], $deleted);

		$this->assertNotFound($this->callTool($this->prefix() . '_read', ['id' => $id]));
	}

	public function testCreateWithNoPagesAssignment(): void
	{
		$title = $this->uniqueName('MCP4j Module NoPages');

		$created = $this->callToolOk($this->prefix() . '_create', [
			'title'      => $title,
			'module'     => 'mod_custom',
			'assignment' => '-',
			'params'     => $this->baselineParams(),
		]);
		$id = $created['id'];
		$this->registerCleanup(fn () => $this->callTool($this->prefix() . '_delete', ['id' => $id]));

		$this->assertSame('-', $created['assignment']);

		$read = $this->callToolOk($this->prefix() . '_read', ['id' => $id]);
		$this->assertSame('-', $read['assignment']);
		$this->assertSame([], $read['assigned']);
	}

	public function testReadNonexistentAndZeroIdAreNotFound(): void
	{
		$this->assertNotFound($this->callTool($this->prefix() . '_read', ['id' => 999999]));

		// Regression guard: ModuleModel::getItem(0) calls $app->redirect() (exits the request) unless
		// guarded before the model is touched — see .plans/07-modules-templates.md Part A "Key facts" §3.
		$this->assertNotFound($this->callTool($this->prefix() . '_read', ['id' => 0]));
	}

	public function testCrossClientReadIsNotFound(): void
	{
		$title = $this->uniqueName('MCP4j Module CrossClient');

		$created = $this->callToolOk($this->prefix() . '_create', ['title' => $title, 'module' => 'mod_custom', 'params' => $this->baselineParams()]);
		$id      = $created['id'];
		$this->registerCleanup(fn () => $this->callTool($this->prefix() . '_delete', ['id' => $id]));

		$this->assertNotFound($this->callTool($this->otherPrefix() . '_read', ['id' => $id]));
	}

	public function testCreateWithUnknownModuleTypeIsNotFound(): void
	{
		$result = $this->callTool($this->prefix() . '_create', [
			'title'  => $this->uniqueName('MCP4j Module BadType'),
			'module' => 'mod_does_not_exist',
		]);

		$this->assertTrue($result['isError']);
		$this->assertStringStartsWith('Not found: module type', $result['text']);
	}

	public function testUpdateCannotChangeModuleTypeOrAcceptBadParams(): void
	{
		$title = $this->uniqueName('MCP4j Module Immutable');

		$created = $this->callToolOk($this->prefix() . '_create', ['title' => $title, 'module' => 'mod_custom', 'params' => $this->baselineParams()]);
		$id      = $created['id'];
		$this->registerCleanup(fn () => $this->callTool($this->prefix() . '_delete', ['id' => $id]));

		$changeType = $this->callTool($this->prefix() . '_update', ['id' => $id, 'module' => 'mod_login']);
		$this->assertTrue($changeType['isError']);
		$this->assertStringContainsString('module type', $changeType['text']);

		$badParams = $this->callTool($this->prefix() . '_update', ['id' => $id, 'params' => 'not json']);
		$this->assertTrue($badParams['isError']);
	}

	public function testModuleTypesListsModCustom(): void
	{
		$result = $this->callToolOk($this->prefix() . '_types');
		$this->assertListShape($result);

		$modCustom = null;

		foreach ($result['items'] as $item)
		{
			if (($item['module'] ?? null) === 'mod_custom')
			{
				$modCustom = $item;

				break;
			}
		}

		$this->assertNotNull($modCustom, 'mod_custom must be present in the module types list.');
		$this->assertNotSame('', $modCustom['name']);
		$this->assertStringStartsNotWith('MOD_', $modCustom['name'], 'name must be the translated string, not the raw language key.');
	}

	public function testLowPrivilegeUserIsRefusedListAndCreate(): void
	{
		$listResult = $this->callToolAsLowPriv($this->prefix() . '_list');
		$this->assertPermissionDenied($listResult);

		$title       = $this->uniqueName('MCP4j Module LowPriv');
		$writeResult = $this->callToolAsLowPriv($this->prefix() . '_create', ['title' => $title, 'module' => 'mod_custom']);
		$this->assertPermissionDenied($writeResult);

		// Prove nothing was actually created, as the super user.
		$search = $this->callToolOk($this->prefix() . '_list', ['filterSearch' => $title]);
		$this->assertSame([], $search['items']);
	}
}
