<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 *
 * Tool-surface parity audit (plan 17, .plans/17-finalize.md §1).
 *
 * Compares every #[McpTool] method in the original `joomla-mcp-php` project (`src/Server/*\/*.php`)
 * against every #[McpTool] method in this project (`component/backend/src/Tool/*\/*.php`) and reports:
 *
 *   - tools that exist only in the original (should be empty except the one intentionally dropped tool);
 *   - tools that exist only here (should always be empty — we never invent tool names);
 *   - for tools present on both sides: differences in annotations, parameter names, parameter types,
 *     optional/default, and #[Schema(...)] attribute arguments.
 *
 * Parameter *order* differences are reported as INFO only (.plans/00-conventions.md §2 explicitly permits
 * moving an optional parameter after a required one; MCP binds arguments by name).
 *
 * Every other difference must be listed in $ALLOW_LIST below, each entry citing the exact domain plan
 * "Deviations from the original" section it comes from. Any unlisted difference makes the script exit 1.
 *
 * Usage: php tests/parity/compare-tool-surface.php [path-to-joomla-mcp-php]
 *        (default: ~/Projects/dionysopoulos/joomla-mcp-php)
 */

declare(strict_types=1);

error_reporting(E_ALL);

// -----------------------------------------------------------------------------------------------------
// Allow-list — every entry MUST cite the plan file and quote (or closely paraphrase) the deviation text.
// -----------------------------------------------------------------------------------------------------

/**
 * Each entry: ['tool' => <tool name>, 'aspect' => 'param-type'|'dropped'|'added', 'param' => <name|null>,
 * 'plan' => <plan file under .plans/>, 'note' => <citation/explanation>].
 */
$ALLOW_LIST = [
	// --- Plan 04 — content.md, "Deviations from the original" #1 -------------------------------------
	[
		'tool'  => 'content_categories_list',
		'aspect' => 'param-type',
		'param' => 'filterSearch',
		'plan'  => '04-content.md',
		'note'  => '"content_categories_list.filterSearch is typed ?int in the original (a bug — every '
			. 'other categories class uses ?string; a text search cannot be sent). Use ?string. Keep the '
			. 'Schema text."',
	],

	// --- Plan 07 (Part A) — modules-templates.md, "Deviations from the original (Part A)" #1 ----------
	[
		'tool'  => 'modules_site_list',
		'aspect' => 'param-type',
		'param' => 'filterPosition',
		'plan'  => '07-modules-templates.md',
		'note'  => '"filterPosition and position are declared ?string instead of the original\'s ?int '
			. '(module positions are strings like sidebar-right; the original type made the parameter '
			. 'unusable). Keep the original #[Schema] descriptions."',
	],
	[
		'tool'  => 'modules_admin_list',
		'aspect' => 'param-type',
		'param' => 'filterPosition',
		'plan'  => '07-modules-templates.md',
		'note'  => 'Same deviation as modules_site_list.filterPosition, Part A #1.',
	],
	[
		'tool'  => 'modules_site_create',
		'aspect' => 'param-type',
		'param' => 'position',
		'plan'  => '07-modules-templates.md',
		'note'  => 'Same deviation, Part A #1 ("position" half of the sentence).',
	],
	[
		'tool'  => 'modules_site_update',
		'aspect' => 'param-type',
		'param' => 'position',
		'plan'  => '07-modules-templates.md',
		'note'  => 'Same deviation, Part A #1.',
	],
	[
		'tool'  => 'modules_admin_create',
		'aspect' => 'param-type',
		'param' => 'position',
		'plan'  => '07-modules-templates.md',
		'note'  => 'Same deviation, Part A #1.',
	],
	[
		'tool'  => 'modules_admin_update',
		'aspect' => 'param-type',
		'param' => 'position',
		'plan'  => '07-modules-templates.md',
		'note'  => 'Same deviation, Part A #1.',
	],

	// --- Plan 13 — media-joomlaupdate.md, "Deviations from the original" #1 --------------------------
	[
		'tool'  => 'media_adapters_read',
		'aspect' => 'param-type',
		'param' => 'id',
		'plan'  => '13-media-joomlaupdate.md',
		'note'  => '"media_adapters_read takes string $id instead of int $id: adapter ids are strings such '
			. 'as \'local-images\'; the original\'s int made the tool unusable. Keep the description text '
			. 'but append \' (e.g. "local-images")\'."',
	],
	[
		'tool'  => 'media_adapters_read',
		'aspect' => 'param-schema',
		'param' => 'id',
		'plan'  => '13-media-joomlaupdate.md',
		'note'  => 'Direct consequence of the same deviation: "Keep the description text but append \' '
			. '(e.g. "local-images")\'."',
	],

	// --- Plan 16b — tickets-pro.md, "Deviations from the original (record in the final report)" #1 ---
	[
		'tool'  => 'tickets_attachments_save_to_file',
		'aspect' => 'dropped',
		'param' => null,
		'plan'  => '16b-tickets-pro.md',
		'note'  => '"tickets_attachments_save_to_file is dropped. In the original it wrote to the MCP '
			. 'client\'s disk. Run inside the web server, the same tool would let any caller write '
			. 'arbitrary bytes to any path the PHP user can write — a remote file-write primitive. There '
			. 'is no safe server-side equivalent." Also recorded in .plans/README.md: "tickets_attachments_'
			. 'save_to_file is intentionally dropped".',
	],
];

// -----------------------------------------------------------------------------------------------------
// Bootstrap both sides
// -----------------------------------------------------------------------------------------------------

$repoRoot     = dirname(__DIR__, 2);
$originalRoot = rtrim($argv[1] ?? (getenv('HOME') . '/Projects/dionysopoulos/joomla-mcp-php'), '/');

if (!is_dir($originalRoot))
{
	fwrite(STDERR, "Original project not found at: $originalRoot\n");
	exit(2);
}

$originalAutoload = $originalRoot . '/vendor/autoload.php';

if (!is_file($originalAutoload))
{
	fwrite(STDERR, "Original project's vendor/autoload.php not found — run composer install there first.\n");
	exit(2);
}

require $originalAutoload;

if (!defined('_JEXEC'))
{
	define('_JEXEC', 1);
}

spl_autoload_register(static function (string $class) use ($repoRoot): void {
	$prefix = 'Akeeba\\Component\\Mcp4j\\Administrator\\';

	if (strncmp($class, $prefix, strlen($prefix)) !== 0)
	{
		return;
	}

	$relative = substr($class, strlen($prefix));
	$file     = $repoRoot . '/component/backend/src/' . str_replace('\\', '/', $relative) . '.php';

	if (is_file($file))
	{
		require $file;
	}
});

// -----------------------------------------------------------------------------------------------------
// Reflection helpers
// -----------------------------------------------------------------------------------------------------

/**
 * @return array<int, string> Fully-qualified class names discovered under $dir/*\/*.php, derived from the
 *                             file path (one directory level of "category", exactly as 00 §3 requires).
 */
function discoverClasses(string $dir, string $namespaceRoot): array
{
	$classes = [];

	foreach (glob($dir . '/*', GLOB_ONLYDIR) ?: [] as $categoryDir)
	{
		$category = basename($categoryDir);

		foreach (glob($categoryDir . '/*.php') ?: [] as $file)
		{
			$class     = basename($file, '.php');
			$classes[] = $namespaceRoot . '\\' . $category . '\\' . $class;
		}
	}

	sort($classes);

	return $classes;
}

/**
 * @return array<string, mixed>|null Tool descriptor, or null if the method has no McpTool attribute.
 */
function describeOriginalMethod(ReflectionMethod $method): ?array
{
	$attrs = $method->getAttributes(\PhpMcp\Server\Attributes\McpTool::class);

	if ($attrs === [])
	{
		return null;
	}

	/** @var \PhpMcp\Server\Attributes\McpTool $mcpTool */
	$mcpTool = $attrs[0]->newInstance();

	$annotations = $mcpTool->annotations;

	return [
		'name'        => $mcpTool->name ?? $method->getName(),
		'description' => $mcpTool->description,
		'annotations' => [
			'readOnlyHint'    => $annotations?->readOnlyHint,
			'destructiveHint' => $annotations?->destructiveHint,
			'idempotentHint'  => $annotations?->idempotentHint,
			'openWorldHint'   => $annotations?->openWorldHint,
		],
		'params' => describeParams($method, \PhpMcp\Server\Attributes\Schema::class),
	];
}

/**
 * @return array<string, mixed>|null
 */
function describeOurMethod(ReflectionMethod $method): ?array
{
	$attrs = $method->getAttributes(\Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\McpTool::class);

	if ($attrs === [])
	{
		return null;
	}

	/** @var \Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\McpTool $mcpTool */
	$mcpTool = $attrs[0]->newInstance();

	return [
		'name'        => $mcpTool->name,
		'description' => $mcpTool->description,
		'annotations' => [
			'readOnlyHint'    => $mcpTool->readOnlyHint,
			'destructiveHint' => $mcpTool->destructiveHint,
			'idempotentHint'  => $mcpTool->idempotentHint,
			'openWorldHint'   => $mcpTool->openWorldHint,
		],
		'params' => describeParams($method, \Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\Schema::class),
	];
}

/**
 * @return array<int, array<string, mixed>> One entry per parameter, in declared order.
 */
function describeParams(ReflectionMethod $method, string $schemaAttributeClass): array
{
	$out = [];

	foreach ($method->getParameters() as $param)
	{
		$type = $param->getType();

		$typeString = $type instanceof ReflectionNamedType
			? ($type->allowsNull() && $type->getName() !== 'null' ? '?' : '') . $type->getName()
			: (string) $type;

		$default = null;

		if ($param->isDefaultValueAvailable())
		{
			try
			{
				$default = $param->getDefaultValue();
			}
			catch (\Throwable)
			{
				$default = '<unreadable>';
			}
		}

		$schemaArgs = null;
		$schemaAttrs = $param->getAttributes($schemaAttributeClass);

		if ($schemaAttrs !== [])
		{
			$schemaArgs = $schemaAttrs[0]->getArguments();
		}

		$out[] = [
			'name'       => $param->getName(),
			'type'       => $typeString,
			'optional'   => $param->isOptional(),
			'hasDefault' => $param->isDefaultValueAvailable(),
			'default'    => $default,
			'schema'     => $schemaArgs,
		];
	}

	return $out;
}

/**
 * @return array<string, array<string, mixed>> tool name => descriptor (+ 'class', 'method', 'unloadable')
 */
function collectTools(array $classes, callable $describe, array &$unloadable): array
{
	$tools = [];

	foreach ($classes as $class)
	{
		try
		{
			if (!class_exists($class))
			{
				$unloadable[] = $class . ' (file did not declare the expected class)';

				continue;
			}

			$ref = new ReflectionClass($class);
		}
		catch (\Throwable $e)
		{
			$unloadable[] = $class . ' (' . $e->getMessage() . ')';

			continue;
		}

		foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method)
		{
			if ($method->getDeclaringClass()->getName() !== $ref->getName())
			{
				continue; // inherited method, not declared here
			}

			try
			{
				$descriptor = $describe($method);
			}
			catch (\Throwable $e)
			{
				$unloadable[] = $class . '::' . $method->getName() . ' (' . $e->getMessage() . ')';

				continue;
			}

			if ($descriptor === null)
			{
				continue;
			}

			$descriptor['class']  = $class;
			$descriptor['method'] = $method->getName();

			$tools[$descriptor['name']] = $descriptor;
		}
	}

	ksort($tools);

	return $tools;
}

function allowListMatch(array $allowList, string $tool, string $aspect, ?string $param): ?array
{
	foreach ($allowList as $entry)
	{
		if ($entry['tool'] === $tool && $entry['aspect'] === $aspect
			&& ($entry['param'] === $param || $entry['param'] === null))
		{
			return $entry;
		}
	}

	return null;
}

// -----------------------------------------------------------------------------------------------------
// Collect both sides
// -----------------------------------------------------------------------------------------------------

$unloadableOriginal = [];
$unloadableOurs     = [];

$originalClasses = discoverClasses($originalRoot . '/src/Server', 'Dionysopoulos\\Mcp4Joomla\\Server');
$ourClasses      = discoverClasses($repoRoot . '/component/backend/src/Tool', 'Akeeba\\Component\\Mcp4j\\Administrator\\Tool');

$originalTools = collectTools($originalClasses, 'describeOriginalMethod', $unloadableOriginal);
$ourTools      = collectTools($ourClasses, 'describeOurMethod', $unloadableOurs);

// -----------------------------------------------------------------------------------------------------
// Category -> tool count table (for the README)
// -----------------------------------------------------------------------------------------------------

$categoryCounts = [];

foreach ($ourTools as $tool)
{
	// class is Akeeba\...\Tool\<Category>\<Class>
	$parts    = explode('\\', $tool['class']);
	$category = $parts[count($parts) - 2];

	$categoryCounts[$category] = ($categoryCounts[$category] ?? 0) + 1;
}

ksort($categoryCounts);

echo "=== Category -> tool count (our side) ===\n";

$totalOurs = 0;

foreach ($categoryCounts as $category => $count)
{
	printf("%-16s %d\n", $category, $count);
	$totalOurs += $count;
}

printf("%-16s %d\n\n", 'TOTAL', $totalOurs);

echo "=== Totals ===\n";
printf("Original tools reflected: %d\n", count($originalTools));
printf("Our tools reflected:      %d\n\n", count($ourTools));

if ($unloadableOriginal !== [])
{
	echo "=== UNLOADABLE (original side) ===\n";

	foreach ($unloadableOriginal as $entry)
	{
		echo " - $entry\n";
	}

	echo "\n";
}

if ($unloadableOurs !== [])
{
	echo "=== UNLOADABLE (our side) ===\n";

	foreach ($unloadableOurs as $entry)
	{
		echo " - $entry\n";
	}

	echo "\n";
}

// -----------------------------------------------------------------------------------------------------
// Diff
// -----------------------------------------------------------------------------------------------------

$violations = [];
$infos      = [];

$onlyOriginal = array_diff(array_keys($originalTools), array_keys($ourTools));
$onlyOurs     = array_diff(array_keys($ourTools), array_keys($originalTools));

foreach ($onlyOriginal as $toolName)
{
	$allowed = allowListMatch($ALLOW_LIST, $toolName, 'dropped', null);

	if ($allowed !== null)
	{
		$infos[] = "DROPPED (allow-listed, {$allowed['plan']}): $toolName";
	}
	else
	{
		$violations[] = "Only in ORIGINAL (missing here, not allow-listed): $toolName";
	}
}

foreach ($onlyOurs as $toolName)
{
	$allowed = allowListMatch($ALLOW_LIST, $toolName, 'added', null);

	if ($allowed !== null)
	{
		$infos[] = "ADDED (allow-listed, {$allowed['plan']}): $toolName";
	}
	else
	{
		$violations[] = "Only in OUR project (invented tool name, not allow-listed): $toolName";
	}
}

$common = array_intersect(array_keys($originalTools), array_keys($ourTools));
sort($common);

foreach ($common as $toolName)
{
	$orig = $originalTools[$toolName];
	$ours = $ourTools[$toolName];

	// --- annotations --------------------------------------------------------------------------------
	foreach (['readOnlyHint', 'destructiveHint', 'idempotentHint', 'openWorldHint'] as $hint)
	{
		$a = $orig['annotations'][$hint];
		$b = $ours['annotations'][$hint];

		if ($a !== $b)
		{
			$allowed = allowListMatch($ALLOW_LIST, $toolName, 'annotation', $hint);

			$msg = sprintf(
				'%s: annotation %s differs (original=%s, ours=%s)',
				$toolName,
				$hint,
				var_export($a, true),
				var_export($b, true)
			);

			if ($allowed !== null)
			{
				$infos[] = "ALLOWED ({$allowed['plan']}): $msg";
			}
			else
			{
				$violations[] = $msg;
			}
		}
	}

	// --- parameters ----------------------------------------------------------------------------------
	$origParamsByName = [];

	foreach ($orig['params'] as $i => $p)
	{
		$origParamsByName[$p['name']] = $p + ['order' => $i];
	}

	$ourParamsByName = [];

	foreach ($ours['params'] as $i => $p)
	{
		$ourParamsByName[$p['name']] = $p + ['order' => $i];
	}

	$origNames = array_keys($origParamsByName);
	$ourNames  = array_keys($ourParamsByName);

	$missingHere = array_diff($origNames, $ourNames);
	$extraHere   = array_diff($ourNames, $origNames);

	foreach ($missingHere as $paramName)
	{
		$allowed = allowListMatch($ALLOW_LIST, $toolName, 'param-missing', $paramName);
		$msg     = "$toolName: parameter '$paramName' present in original, missing here";

		if ($allowed !== null)
		{
			$infos[] = "ALLOWED ({$allowed['plan']}): $msg";
		}
		else
		{
			$violations[] = $msg;
		}
	}

	foreach ($extraHere as $paramName)
	{
		$allowed = allowListMatch($ALLOW_LIST, $toolName, 'param-added', $paramName);
		$msg     = "$toolName: parameter '$paramName' present here, missing in original";

		if ($allowed !== null)
		{
			$infos[] = "ALLOWED ({$allowed['plan']}): $msg";
		}
		else
		{
			$violations[] = $msg;
		}
	}

	// order differences: INFO only, never a violation (00 §2)
	$commonParamNames = array_values(array_intersect($origNames, $ourNames));

	$origOrderOfCommon = array_values(array_filter($origNames, static fn ($n) => in_array($n, $commonParamNames, true)));
	$ourOrderOfCommon  = array_values(array_filter($ourNames, static fn ($n) => in_array($n, $commonParamNames, true)));

	if ($origOrderOfCommon !== $ourOrderOfCommon)
	{
		$infos[] = "$toolName: parameter order differs (original: " . implode(', ', $origOrderOfCommon)
			. ') vs (ours: ' . implode(', ', $ourOrderOfCommon) . ') — INFO only, 00 §2 permits moving '
			. 'optional parameters after required ones.';
	}

	$orderDiffers = $origOrderOfCommon !== $ourOrderOfCommon;

	foreach ($commonParamNames as $paramName)
	{
		$op = $origParamsByName[$paramName];
		$np = $ourParamsByName[$paramName];

		// PHP silently drops a parameter's default (isDefaultValueAvailable() becomes false, isOptional()
		// becomes false) when it is declared before a required parameter — exactly the bug 00 §2's first
		// permitted change fixes by moving the optional parameter after the required one(s). When the tool
		// already shows a parameter-order difference, and the original side lost its default this way,
		// the resulting optional/default differences are a direct, mechanical consequence of that single
		// permitted fix — not a separate deviation requiring its own allow-list entry.
		$isReorderArtifact = $orderDiffers
			&& $op['optional'] === false && $op['hasDefault'] === false
			&& $np['optional'] === true && $np['hasDefault'] === true && $np['default'] === null;

		if ($isReorderArtifact)
		{
			$infos[] = "$toolName.$paramName: optional/default differ only because the original declared "
				. 'it before a required parameter (PHP drops the reflectable default in that position) — '
				. 'INFO only, 00 §2 permitted change, see the order-difference line above for this tool.';

			continue;
		}

		if ($op['type'] !== $np['type'])
		{
			$allowed = allowListMatch($ALLOW_LIST, $toolName, 'param-type', $paramName);
			$msg     = "$toolName.$paramName: type differs (original={$op['type']}, ours={$np['type']})";

			if ($allowed !== null)
			{
				$infos[] = "ALLOWED ({$allowed['plan']}): $msg";
			}
			else
			{
				$violations[] = $msg;
			}
		}

		if ($op['optional'] !== $np['optional'])
		{
			$allowed = allowListMatch($ALLOW_LIST, $toolName, 'param-optional', $paramName);
			$msg     = "$toolName.$paramName: optional differs (original="
				. var_export($op['optional'], true) . ', ours=' . var_export($np['optional'], true) . ')';

			if ($allowed !== null)
			{
				$infos[] = "ALLOWED ({$allowed['plan']}): $msg";
			}
			else
			{
				$violations[] = $msg;
			}
		}

		$defaultDiffers = $op['hasDefault'] !== $np['hasDefault']
			|| ($op['hasDefault'] && $np['hasDefault'] && $op['default'] !== $np['default']);

		if ($defaultDiffers)
		{
			$allowed = allowListMatch($ALLOW_LIST, $toolName, 'param-default', $paramName);
			$msg     = "$toolName.$paramName: default differs (original="
				. var_export($op['default'], true) . ', ours=' . var_export($np['default'], true) . ')';

			if ($allowed !== null)
			{
				$infos[] = "ALLOWED ({$allowed['plan']}): $msg";
			}
			else
			{
				$violations[] = $msg;
			}
		}

		if ($op['schema'] !== $np['schema'])
		{
			$allowed = allowListMatch($ALLOW_LIST, $toolName, 'param-schema', $paramName);
			$msg     = "$toolName.$paramName: #[Schema] arguments differ\n    original: "
				. var_export($op['schema'], true) . "\n    ours:     " . var_export($np['schema'], true);

			if ($allowed !== null)
			{
				$infos[] = "ALLOWED ({$allowed['plan']}): $msg";
			}
			else
			{
				$violations[] = $msg;
			}
		}
	}
}

// -----------------------------------------------------------------------------------------------------
// Report
// -----------------------------------------------------------------------------------------------------

echo "=== INFO (allow-listed or order-only, not exit-code-affecting) ===\n";

if ($infos === [])
{
	echo "(none)\n";
}
else
{
	foreach ($infos as $info)
	{
		echo " - $info\n";
	}
}

echo "\n=== VIOLATIONS ===\n";

if ($violations === [])
{
	echo "(none)\n";
}
else
{
	foreach ($violations as $violation)
	{
		echo " - $violation\n";
	}
}

echo "\n";

if ($unloadableOriginal !== [] || $unloadableOurs !== [])
{
	fwrite(STDERR, "One or more classes could not be reflected — compare them by hand (see UNLOADABLE above).\n");
}

if ($violations !== [])
{
	printf("FAIL: %d unallow-listed difference(s).\n", count($violations));
	exit(1);
}

echo "PASS: tool surfaces match (module differences, if any, are all allow-listed).\n";
exit(0);
