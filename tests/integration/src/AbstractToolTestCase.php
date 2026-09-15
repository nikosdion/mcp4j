<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Integration;

/**
 * Base class for E2E tests of individual MCP tools (`tools/call`), on top of {@see AbstractE2ETestCase}'s
 * raw JSON-RPC HTTP plumbing.
 *
 * A tool's JSON-RPC result envelope is always `{"content":[{"type":"text","text":"<json>"}],"isError":bool}`
 * — see `.plans/02-e2e-harness.md`. {@see callTool()} and friends below parse that envelope once so
 * individual tool test classes never have to.
 *
 * Sessions: `Mcp-Session-Id` is bound to the user who created it (see
 * `Akeeba\Component\Mcp4j\Administrator\Helper\SessionManager::resume()`), so a different token
 * genuinely needs a different session — {@see sessionFor()} lazily initializes and caches one session
 * per token for the lifetime of the current test class (reset in {@see setUpBeforeClass()}), reusing
 * {@see AbstractE2ETestCase::initializeSession()} rather than reimplementing `initialize`.
 */
abstract class AbstractToolTestCase extends AbstractE2ETestCase
{
	/**
	 * The low-privilege ("Registered" group only) API token, provisioned by
	 * tests/integration/docker/provision-lowpriv-user.php and passed in via MCP4J_LOWPRIV_TOKEN — see
	 * run-tests.sh's provision_lowpriv(). Empty when the harness didn't provide one (e.g. a test run
	 * outside run-tests.sh); {@see callToolAsLowPriv()} skips rather than fails in that case.
	 */
	protected static string $lowPrivToken = '';

	/**
	 * The "Author" group (group 3) API token, provisioned by
	 * tests/integration/docker/provision-author-user.php and passed in via MCP4J_AUTHOR_TOKEN — see
	 * run-tests.sh's provision_author(). A stock Joomla install's Author group has `core.create` but
	 * NOT `core.edit.state` on `com_content` by default — used to test security.md finding M3 (whether
	 * `content_articles_create`'s default-published `$state` path can be used to publish without
	 * `core.edit.state`). Empty when the harness didn't provide one (e.g. a test run outside
	 * run-tests.sh); {@see callToolAsAuthor()} skips rather than fails in that case.
	 */
	protected static string $authorToken = '';

	/**
	 * The ATS scoped-manager API token, provisioned by
	 * tests/integration/docker/provision-ats-scoped-manager.php and passed in via
	 * MCP4J_SCOPED_MANAGER_TOKEN — see run-tests.sh's provision_scoped_manager(). This user holds
	 * `core.manage` on the `com_ats` component root (a genuine ATS manager) but is explicitly DENIED
	 * `core.manage`/`core.admin` at one specific ATS category ("MCP4j Scoped Denied") — the exact shape
	 * security.md findings M4/M5 describe. Empty when the harness didn't provide one (e.g. a test run
	 * outside run-tests.sh, or com_ats isn't installed on this run); {@see callToolAsScopedManager()}
	 * skips rather than fails in that case.
	 */
	protected static string $scopedManagerToken = '';

	/**
	 * token => Mcp-Session-Id, cached for the lifetime of the current test class only (reset in
	 * setUpBeforeClass()).
	 */
	private static array $sessions = [];

	/**
	 * Cached result of tools/list for the current test class only (reset in setUpBeforeClass()); null
	 * until first fetched.
	 */
	private static ?array $toolNames = null;

	/**
	 * Cleanup callables registered by the current test method via registerCleanup(), run in reverse
	 * order by tearDown().
	 */
	private array $cleanups = [];

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$lowPrivToken = getenv('MCP4J_LOWPRIV_TOKEN') ?: '';
		self::$authorToken  = getenv('MCP4J_AUTHOR_TOKEN') ?: '';
		self::$scopedManagerToken = getenv('MCP4J_SCOPED_MANAGER_TOKEN') ?: '';
		self::$sessions     = [];
		self::$toolNames    = null;
	}

	/**
	 * Runs every cleanup registered via registerCleanup(), most-recently-registered first (so a later
	 * cleanup that depends on an earlier one's target still existing runs first). A cleanup throwing
	 * never aborts the others or fails the test outright — a failed cleanup is a leaked fixture on the
	 * test site, not a reason to obscure the test's own assertion failures, but it IS surfaced (on
	 * STDERR) so it doesn't go unnoticed.
	 */
	protected function tearDown(): void
	{
		$failures = [];

		foreach (array_reverse($this->cleanups) as $cleanup)
		{
			try
			{
				$cleanup();
			}
			catch (\Throwable $e)
			{
				$failures[] = $e->getMessage();
			}
		}

		$this->cleanups = [];

		parent::tearDown();

		if ($failures !== [])
		{
			fwrite(
				STDERR,
				sprintf(
					"\n[%s::%s] %d cleanup callback(s) failed:\n  - %s\n",
					static::class,
					$this->getName(),
					count($failures),
					implode("\n  - ", $failures)
				)
			);
		}
	}

	/**
	 * Lazily initializes (and caches, per token, for the current test class) an MCP session for the
	 * given token. Pass null explicitly for the unauthenticated case — initialize itself will fail loudly
	 * via initializeSession()'s own assertion, which is the point: nothing here quietly tolerates a
	 * broken session.
	 */
	protected function sessionFor(?string $token = self::SELF_TOKEN): string
	{
		$key = $token === self::SELF_TOKEN ? self::$token : (string) $token;

		if (!isset(self::$sessions[$key]))
		{
			[$sessionId] = $this->initializeSession($token);
			self::$sessions[$key] = $sessionId;
		}

		return self::$sessions[$key];
	}

	/**
	 * Calls a tool via tools/call and parses its result envelope. Fails the test (rather than returning
	 * a half-parsed result) if the HTTP status isn't 200, the JSON-RPC body carries an "error" member (a
	 * *transport/protocol*-level failure — a missing tool, bad arguments the framework itself rejects,
	 * etc. — as opposed to a tool-level failure, which is isError:true with a 200 and no "error" member),
	 * or result.content[0].text is missing.
	 *
	 * @return array{isError: bool, text: string, data: mixed, raw: array}
	 */
	protected function callTool(string $name, array $arguments = [], ?string $token = self::SELF_TOKEN): array
	{
		$sessionId = $this->sessionFor($token);

		$response = $this->rpc(
			'tools/call',
			[
				'name'      => $name,
				// MUST be a JSON object even when empty — a bare `[]` would serialize as a JSON array,
				// which every real MCP client (and, more importantly, our own ArgumentCoercer) treats
				// as a type mismatch rather than "no arguments".
				'arguments' => (object) $arguments,
			],
			1,
			$sessionId,
			$token
		);

		$this->assertStatus(200, $response, sprintf('tools/call(%s) did not return HTTP 200: %s', $name, $response['raw']));

		$json = $response['json'];
		$this->assertIsArray($json, 'Response body is not valid JSON: ' . $response['raw']);
		$this->assertArrayNotHasKey(
			'error',
			$json,
			sprintf('tools/call(%s) returned a JSON-RPC-level error (expected a tool-level result): %s', $name, $response['raw'])
		);

		$result = $json['result'] ?? null;
		$this->assertIsArray($result, 'tools/call result is missing/not an array: ' . $response['raw']);

		$text = $result['content'][0]['text'] ?? null;
		$this->assertIsString($text, sprintf('tools/call(%s) result has no content[0].text: %s', $name, $response['raw']));

		return [
			'isError' => (bool) ($result['isError'] ?? false),
			'text'    => $text,
			'data'    => json_decode($text, true),
			'raw'     => $response,
		];
	}

	/**
	 * Calls a tool expecting a JSON-RPC *protocol*-level error (e.g. missing required arguments, an
	 * uncoercible argument type — handled by the framework before the tool method ever runs), rather than
	 * a tool-level isError result. Pass $arguments as a string to send deliberately malformed
	 * "arguments" (e.g. a JSON scalar instead of an object); pass null to omit the member entirely.
	 */
	protected function callToolRpcError(string $name, array|string|null $arguments, int $expectedCode): void
	{
		$sessionId = $this->sessionFor();

		$params = ['name' => $name];

		if ($arguments !== null)
		{
			$params['arguments'] = is_array($arguments) ? (object) $arguments : $arguments;
		}

		$response = $this->rpc('tools/call', $params, 1, $sessionId);

		$this->assertStatus(200, $response, 'tools/call did not return HTTP 200: ' . $response['raw']);
		$this->assertJsonRpcError($expectedCode, $response);
	}

	/**
	 * Calls a tool, asserts it succeeded (isError === false), and returns its decoded data.
	 */
	protected function callToolOk(string $name, array $arguments = []): mixed
	{
		$result = $this->callTool($name, $arguments);

		$this->assertFalse(
			$result['isError'],
			sprintf('callToolOk(%s) unexpectedly returned isError=true: %s', $name, $result['text'])
		);

		return $result['data'];
	}

	/**
	 * Calls a tool, asserts it failed (isError === true), and returns its error text.
	 */
	protected function callToolError(string $name, array $arguments = [], ?string $token = self::SELF_TOKEN): string
	{
		$result = $this->callTool($name, $arguments, $token);

		$this->assertTrue(
			$result['isError'],
			sprintf('callToolError(%s) unexpectedly succeeded: %s', $name, $result['text'])
		);

		return $result['text'];
	}

	/**
	 * Calls a tool as the low-privilege user, skipping the test (not failing it) when no low-privilege
	 * token was provided by the harness.
	 *
	 * @return array{isError: bool, text: string, data: mixed, raw: array}
	 */
	protected function callToolAsLowPriv(string $name, array $arguments = []): array
	{
		if (self::$lowPrivToken === '')
		{
			$this->markTestSkipped('MCP4J_LOWPRIV_TOKEN is not set — run via tests/integration/run-tests.sh.');
		}

		return $this->callTool($name, $arguments, self::$lowPrivToken);
	}

	/**
	 * Calls a tool as the "Author" group user, skipping the test (not failing it) when no Author token
	 * was provided by the harness.
	 *
	 * @return array{isError: bool, text: string, data: mixed, raw: array}
	 */
	protected function callToolAsAuthor(string $name, array $arguments = []): array
	{
		if (self::$authorToken === '')
		{
			$this->markTestSkipped('MCP4J_AUTHOR_TOKEN is not set — run via tests/integration/run-tests.sh.');
		}

		return $this->callTool($name, $arguments, self::$authorToken);
	}

	/**
	 * Calls a tool as the ATS scoped-manager user (see {@see self::$scopedManagerToken}), skipping the
	 * test (not failing it) when no scoped-manager token was provided by the harness.
	 *
	 * @return array{isError: bool, text: string, data: mixed, raw: array}
	 */
	protected function callToolAsScopedManager(string $name, array $arguments = []): array
	{
		if (self::$scopedManagerToken === '')
		{
			$this->markTestSkipped('MCP4J_SCOPED_MANAGER_TOKEN is not set — run via tests/integration/run-tests.sh.');
		}

		return $this->callTool($name, $arguments, self::$scopedManagerToken);
	}

	protected function assertPermissionDenied(array $result, string $message = ''): void
	{
		$this->assertTrue($result['isError'], $message !== '' ? $message : 'Expected a tool error: ' . $result['text']);
		$this->assertStringStartsWith(
			'Permission denied',
			$result['text'],
			$message !== '' ? $message : ('Expected a "Permission denied" error, got: ' . $result['text'])
		);
	}

	protected function assertNotFound(array $result, string $message = ''): void
	{
		$this->assertTrue($result['isError'], $message !== '' ? $message : 'Expected a tool error: ' . $result['text']);
		$this->assertStringStartsWith(
			'Not found',
			$result['text'],
			$message !== '' ? $message : ('Expected a "Not found" error, got: ' . $result['text'])
		);
	}

	/**
	 * Asserts $data has the standard `*_list` tool shape:
	 * ['items' => list<array>, 'pagination' => ['total' => int, 'limit' => int, 'offset' => int]].
	 */
	protected function assertListShape(mixed $data): void
	{
		$this->assertIsArray($data, 'Expected a list result (array), got: ' . var_export($data, true));

		$this->assertArrayHasKey('items', $data, 'List result is missing "items".');
		$this->assertIsArray($data['items'], '"items" must be an array.');

		$this->assertArrayHasKey('pagination', $data, 'List result is missing "pagination".');
		$this->assertIsArray($data['pagination'], '"pagination" must be an array.');

		foreach (['total', 'limit', 'offset'] as $key)
		{
			$this->assertArrayHasKey($key, $data['pagination'], "\"pagination.{$key}\" is missing.");
			$this->assertIsInt($data['pagination'][$key], "\"pagination.{$key}\" must be an integer.");
		}
	}

	protected function assertKeysAbsent(array $record, array $keys, string $context = ''): void
	{
		$prefix = $context !== '' ? $context . ': ' : '';

		foreach ($keys as $key)
		{
			$this->assertArrayNotHasKey($key, $record, $prefix . "key \"{$key}\" must not be present");
		}
	}

	protected function assertKeysPresent(array $record, array $keys, string $context = ''): void
	{
		$prefix = $context !== '' ? $context . ': ' : '';

		foreach ($keys as $key)
		{
			$this->assertArrayHasKey($key, $record, $prefix . "key \"{$key}\" must be present");
		}
	}

	protected function uniqueName(string $prefix): string
	{
		return $prefix . ' ' . bin2hex(random_bytes(4));
	}

	/**
	 * Like uniqueName(), but lowercase and restricted to [a-z0-9-] — safe to use directly as an "alias"
	 * field.
	 */
	protected function uniqueAlias(string $prefix): string
	{
		$alias = strtolower($prefix) . '-' . bin2hex(random_bytes(4));

		return preg_replace('/[^a-z0-9-]+/', '-', $alias);
	}

	/**
	 * Registers a callable to run during tearDown() (in reverse registration order), for tests that
	 * create records via a tool and need them removed afterward regardless of the test's outcome.
	 */
	protected function registerCleanup(callable $cleanup): void
	{
		$this->cleanups[] = $cleanup;
	}

	/**
	 * Skips the current test (with a message listing exactly what's missing) unless every one of
	 * $toolNames is present in tools/list — the mechanism third-party-extension-backed categories
	 * (Panopticon, Tickets, ...) use to self-skip when their extension isn't installed on this run.
	 */
	protected function requireToolsOrSkip(array $toolNames): void
	{
		$available = $this->listToolNames();
		$missing   = array_values(array_diff($toolNames, $available));

		if ($missing !== [])
		{
			$this->markTestSkipped('Required tool(s) not available on this install: ' . implode(', ', $missing));
		}
	}

	/**
	 * Fetches (and caches, for the current test class only) the list of every tool name tools/list
	 * currently advertises.
	 */
	protected function listToolNames(): array
	{
		if (self::$toolNames !== null)
		{
			return self::$toolNames;
		}

		$sessionId = $this->sessionFor();
		$response  = $this->rpc('tools/list', [], 1, $sessionId);

		$this->assertStatus(200, $response, 'tools/list failed: ' . $response['raw']);

		$tools = $response['json']['result']['tools'] ?? [];
		$names = [];

		foreach ((array) $tools as $tool)
		{
			if (is_array($tool) && isset($tool['name']))
			{
				$names[] = (string) $tool['name'];
			}
		}

		self::$toolNames = $names;

		return $names;
	}
}
