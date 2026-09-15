<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Mcp\Support;

defined('_JEXEC') || die;

/**
 * Guards non-read-only tool calls against prompt-injection attacks that try to exfiltrate a secret (the
 * bearer token of the current request, a site secret, an operator-configured "forbidden" string) by
 * embedding it in the arguments of a write tool, so it ends up echoed back to the LLM through the tool
 * result, or forwarded on to wherever the write tool sends its data.
 *
 * A port of the original `joomla-mcp-php` project's `SecretLeakPreventionTrait` idea, restructured as
 * pure static functions operating on plain values instead of a trait reading from `$this`.
 *
 * Pure: no Joomla, no I/O.
 *
 * @since  0.0.1
 */
final class SecretLeakGuard
{
	/**
	 * Recursively checks whether any string leaf of `$value` contains any of `$secrets` as a substring.
	 * Non-string, non-array leaves (int, float, bool, null) can never carry a secret and are skipped.
	 *
	 * @param   list<string>  $secrets
	 */
	public static function containsSecret(mixed $value, array $secrets): bool
	{
		if ($secrets === [])
		{
			return false;
		}

		if (is_string($value))
		{
			foreach ($secrets as $secret)
			{
				if ($secret !== '' && str_contains($value, $secret))
				{
					return true;
				}
			}

			return false;
		}

		if (is_array($value))
		{
			foreach ($value as $item)
			{
				if (self::containsSecret($item, $secrets))
				{
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Same traversal as {@see self::containsSecret()}, returning a copy of `$value` with every occurrence
	 * of every secret replaced by `***`. Used only for logging (`debug_log`) — never for what is actually
	 * sent to a tool method or returned to the LLM.
	 *
	 * @param   list<string>  $secrets
	 */
	public static function redact(mixed $value, array $secrets): mixed
	{
		if ($secrets === [])
		{
			return $value;
		}

		if (is_string($value))
		{
			$redacted = $value;

			foreach ($secrets as $secret)
			{
				if ($secret !== '')
				{
					$redacted = str_replace($secret, '***', $redacted);
				}
			}

			return $redacted;
		}

		if (is_array($value))
		{
			$redacted = [];

			foreach ($value as $key => $item)
			{
				$redacted[$key] = self::redact($item, $secrets);
			}

			return $redacted;
		}

		return $value;
	}

	/**
	 * Cleans up a list of secret candidates gathered from various sources (bearer token, site
	 * configuration values, the `forbidden_secrets` option): trims whitespace, drops non-strings, empty
	 * strings, and anything shorter than `$minLength` (short values would false-positive against
	 * ordinary tool arguments — see the caller for why the minimum differs per source), and deduplicates.
	 *
	 * @param   array<mixed>  $candidates
	 *
	 * @return  list<string>
	 */
	public static function normaliseSecrets(array $candidates, int $minLength): array
	{
		$secrets = [];

		foreach ($candidates as $candidate)
		{
			if (!is_string($candidate))
			{
				continue;
			}

			$trimmed = trim($candidate);

			if ($trimmed === '' || mb_strlen($trimmed) < $minLength)
			{
				continue;
			}

			$secrets[$trimmed] = $trimmed;
		}

		return array_values($secrets);
	}
}
