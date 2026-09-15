<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Integration\Tests\Content;

use Akeeba\Mcp4j\Tests\Integration\AbstractToolTestCase;

/**
 * Live test for security.md finding M3: `content_articles_create`'s `$state` parameter defaults to `1`
 * (published), and because PHP can't distinguish "caller passed 1 explicitly" from "caller omitted it",
 * `Articles::createArticle()` maps *both* the omitted case and an explicit `state: 1` to `null` before
 * calling `ArticleMapper::touchesPublishingState()` — so the tool's own
 * `requireAuthorised('core.edit.state', ...)` pre-check is skipped for both, while every *other* state
 * value (0, 2, -2, or an explicit 1 that happened to differ from the default — impossible here, but the
 * point is the sentinel is "differs from 1", not "was explicitly given") does trigger it. See
 * `component/backend/src/Tool/Content/Articles.php:145-180` and
 * `component/backend/src/Mapper/Content/ArticleMapper.php:234,525-539`.
 *
 * Uses a custom-provisioned "Author" group (group 3) user who has `core.create` and (because a stock
 * Author actually lacks it — see finding below) an additionally-granted `core.manage` on `com_content`,
 * but deliberately NOT `core.edit.state` anywhere — see `docker/provision-author-user.php`'s docblock
 * for the full ACL shape and why the plain stock Author group turned out not to be sufficient on its
 * own to even reach `content_articles_create` at all.
 *
 * **Additional finding surfaced while building this test (not in the original security.md M3 write-up):**
 * `content_articles_create` gates the *entire* tool behind `requireAuthorised('core.manage', 'com_content')`
 * (`Articles.php:145`) before it ever reaches the per-category `core.create`/`core.edit.state` checks M3
 * is about. A stock Joomla "Author" does NOT have `core.manage` on `com_content` by default (confirmed
 * against a live install's `#__assets.rules` — only Manager/Administrator/Super User do), so a pure
 * stock Author can never reach the vulnerable code path through this tool at all; a call from one is
 * refused immediately with "Permission denied: core.manage on com_content", regardless of `state`. This
 * test therefore grants `core.manage` on `com_content` to the Author test user specifically (a
 * plausible real-world custom-ACL shape: "can manage/create articles but not publish them"), so the
 * actual M3 mechanism can be exercised at all. This outer gate is arguably itself worth a follow-up
 * look (it makes the tool stricter than real Joomla's own backend content-creation flow, which does not
 * require `core.manage` to create/save an article) but is out of scope for M3 itself.
 *
 * Every scenario below reads the created article back **as the Super User token** via
 * `content_articles_read` afterwards — never trusting the create call's own echoed response — to
 * determine the actually-persisted `state`.
 */
class ArticlesAuthorPublishAclTest extends AbstractToolTestCase
{
	/**
	 * Creates a fresh content category fixture (as the Super User) and registers its cleanup. A
	 * freshly-created category carries no category-specific ACL override, so it inherits the
	 * `com_content` component asset's rules unchanged — exactly the default shape M3 is about.
	 */
	private function fixtureCategory(): array
	{
		$category = $this->callToolOk('content_categories_create', ['title' => $this->uniqueName('MCP4j M3 Fixture Cat')]);
		$this->registerCleanup(fn () => $this->callTool('content_categories_delete', ['id' => $category['id']]));

		return $category;
	}

	/**
	 * Sanity check that the harness actually provisioned an Author token, so a skip further down reads
	 * unambiguously as "not run under run-tests.sh" rather than a silent no-op.
	 */
	public function testAuthorTokenIsProvisioned(): void
	{
		if (self::$authorToken === '')
		{
			$this->markTestSkipped('MCP4J_AUTHOR_TOKEN is not set — run via tests/integration/run-tests.sh.');
		}

		$this->assertNotSame('', self::$authorToken, 'Author token must be non-empty.');
	}

	/**
	 * Control case: an Author explicitly requesting `state: 0` (unpublished) IS subject to the
	 * `core.edit.state` pre-check — 0 differs from the non-nullable parameter's default of 1, so
	 * `touchesPublishingState()` sees a non-null value and the check runs, and is refused because the
	 * Author lacks `core.edit.state`. This demonstrates the finding's own characterization precisely:
	 * "every other state value does trigger it" — including a harmless, more-restrictive explicit value.
	 */
	public function testAuthorExplicitState0IsRefused(): void
	{
		$category = $this->fixtureCategory();
		$title    = $this->uniqueName('MCP4j M3 Author ExplicitState0');

		$result = $this->callToolAsAuthor('content_articles_create', [
			'title'     => $title,
			'catId'     => $category['id'],
			'introText' => 'Intro',
			'fullText'  => '',
			'state'     => 0,
		]);

		$this->assertPermissionDenied($result, 'Explicit state=0 must be refused for lack of core.edit.state: ' . $result['text']);

		$search = $this->callToolOk('content_articles_list', ['filterSearch' => $title]);
		$this->assertSame([], $search['items'], 'A refused create must not have created any article.');
	}

	/**
	 * Control case: an Author explicitly passing `state: 1` (published). Per the finding, this is
	 * indistinguishable at the code level from omitting `state` entirely (both collapse to the same
	 * `null` sentinel), so the tool's own `core.edit.state` pre-check is skipped here too — this is
	 * itself worth noting since a literal reading of "only the default/omitted case is affected" might
	 * suggest explicit `1` behaves differently; it does not. The interesting question is what actually
	 * gets persisted despite the skipped check.
	 */
	public function testAuthorExplicitState1BypassesToolPrecheckButPersistsUnpublished(): void
	{
		$category = $this->fixtureCategory();

		$result = $this->callToolAsAuthor('content_articles_create', [
			'title'     => $this->uniqueName('MCP4j M3 Author ExplicitState1'),
			'catId'     => $category['id'],
			'introText' => 'Intro',
			'fullText'  => '',
			'state'     => 1,
		]);

		$this->assertFalse(
			$result['isError'],
			'Expected the tool-level core.edit.state pre-check to be skipped for explicit state=1 ' .
			'(collapses to the same null sentinel as omitted), i.e. the call should succeed: ' . $result['text']
		);

		$id = $result['data']['id'];
		$this->registerCleanup(fn () => $this->callTool('content_articles_delete', ['id' => $id]));

		$read = $this->callToolOk('content_articles_read', ['id' => $id]);

		$this->assertNotSame(
			1,
			$read['state'],
			'M3 CONFIRMED EXPLOITABLE: an Author (core.create/core.manage but NOT core.edit.state) was ' .
			'able to publish an article (persisted state=1) via content_articles_create with state=1 ' .
			'explicitly given. Echoed create response: ' . json_encode($result['data']) .
			'; persisted read-back: ' . json_encode($read)
		);
	}

	/**
	 * THE finding itself: an Author calling content_articles_create with `state` omitted entirely (the
	 * tool's own non-nullable parameter default of 1/published). Per the M3 finding, the tool's own
	 * `core.edit.state` pre-check is skipped in this exact case. This test determines empirically
	 * whether that gap is actually exploitable, by reading the persisted state back as the Super User
	 * afterwards rather than trusting the create call's own echoed response.
	 */
	public function testAuthorOmittingStateBypassesToolPrecheckButPersistsUnpublished(): void
	{
		$category = $this->fixtureCategory();

		$result = $this->callToolAsAuthor('content_articles_create', [
			'title'     => $this->uniqueName('MCP4j M3 Author OmittedState'),
			'catId'     => $category['id'],
			'introText' => 'Intro',
			'fullText'  => '',
			// 'state' deliberately omitted — this is the exact scenario the M3 finding describes.
		]);

		$this->assertFalse(
			$result['isError'],
			'Expected the tool-level core.edit.state pre-check to be skipped for omitted state ' .
			'(the documented M3 gap), i.e. the call should succeed: ' . $result['text']
		);

		$id = $result['data']['id'];
		$this->registerCleanup(fn () => $this->callTool('content_articles_delete', ['id' => $id]));

		$read = $this->callToolOk('content_articles_read', ['id' => $id]);

		$this->assertNotSame(
			1,
			$read['state'],
			'M3 CONFIRMED EXPLOITABLE: an Author (core.create/core.manage but NOT core.edit.state) was ' .
			'able to create-and-publish an article (persisted state=1) via content_articles_create with ' .
			'the "state" parameter omitted. Echoed create response: ' . json_encode($result['data']) .
			'; persisted read-back: ' . json_encode($read)
		);
	}
}
