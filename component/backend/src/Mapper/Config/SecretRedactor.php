<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Mapper\Config;

defined('_JEXEC') || die;

/**
 * Redacts secret values out of Joomla configuration data before it is ever returned to an MCP client, and
 * strips the redaction placeholder back out of data an MCP client sends us (so that an LLM round-tripping
 * a previously-read record never accidentally writes the literal placeholder string into
 * `configuration.php` or an extension's `params`).
 *
 * Site configuration and component/plugin params routinely hold live secrets — database and SMTP
 * passwords, FTP/proxy credentials, third-party API keys and tokens, download IDs, ... — that a real
 * Joomla backend never displays in plain text either. See `.plans/00-conventions.md` §5.9 and
 * `.plans/10-config-plugins-installer-languages.md`.
 *
 * Why empty stays empty: an LLM must be able to tell "no SMTP password set" (empty string) from "set but
 * hidden" (the placeholder) — redacting an empty value to the placeholder would lie about whether a secret
 * is even configured.
 *
 * Pure: no Joomla.
 *
 * @since  0.0.1
 */
final class SecretRedactor
{
	public const PLACEHOLDER = '********';

	/**
	 * Global configuration keys whose values are always redacted, regardless of content — the fields
	 * `administrator/components/com_config/forms/application.xml` marks `filter="raw"` (i.e. never
	 * filtered/validated as plain text) plus the two Redis auth keys, which are just as sensitive.
	 */
	public const APPLICATION_SECRET_KEYS = [
		'password',
		'secret',
		'smtppass',
		'ftp_pass',
		'proxy_pass',
		'redis_server_auth',
		'session_redis_server_auth',
	];

	/**
	 * Key-name heuristic for component and plugin params (case-insensitive, matched against the leaf key)
	 * — there is no whitelist of "every possible secret param name" across every third-party extension, so
	 * this pattern is deliberately broad. It intentionally does NOT match `smtpauth`/`smtpuser`/`author`
	 * (auth-adjacent or containing "auth"/"user" is not the same as holding a secret).
	 */
	public const SECRET_KEY_PATTERN = '/(password|passwd|secret|token|api_?key|private_?key|credential|salt|_pass$|^pass$|smtppass|access_?key|download_?id|^dlid$)/i';

	/**
	 * Redacts a fixed, top-level list of keys (used for the application/global configuration, whose secret
	 * fields are known in advance and not param-shaped).
	 *
	 * @param   array<string, mixed>  $data  The configuration data.
	 * @param   list<string>          $keys  The exact top-level keys to redact.
	 *
	 * @return  array<string, mixed>
	 */
	public static function redactKeys(array $data, array $keys): array
	{
		foreach ($keys as $key)
		{
			if (!array_key_exists($key, $data))
			{
				continue;
			}

			if (self::isEmpty($data[$key]))
			{
				continue;
			}

			$data[$key] = self::PLACEHOLDER;
		}

		return $data;
	}

	/**
	 * Recursively redacts every string/int/float leaf whose key matches {@see self::SECRET_KEY_PATTERN},
	 * anywhere in a nested params array (used for component/plugin params, whose secret keys are not known
	 * in advance).
	 *
	 * @param   array<string, mixed>  $data
	 *
	 * @return  array<string, mixed>
	 */
	public static function redactByPattern(array $data): array
	{
		foreach ($data as $key => $value)
		{
			if (is_array($value))
			{
				$data[$key] = self::redactByPattern($value);

				continue;
			}

			if (!is_string($key) || !preg_match(self::SECRET_KEY_PATTERN, $key))
			{
				continue;
			}

			if (!is_string($value) && !is_int($value) && !is_float($value))
			{
				continue;
			}

			if (self::isEmpty($value))
			{
				continue;
			}

			$data[$key] = self::PLACEHOLDER;
		}

		return $data;
	}

	/**
	 * Recursively removes every leaf that is exactly {@see self::PLACEHOLDER} out of an incoming "changes"
	 * payload — an LLM will often send back the whole object it previously read (placeholder included),
	 * and that placeholder must never be written into `configuration.php` or an extension's params. Removing
	 * the key entirely (rather than, say, replacing it with an empty string) means the merge onto the
	 * current record ({@see \Akeeba\Component\Mcp4j\Administrator\Bridge\Support\DataMerger::merge()})
	 * leaves the live value untouched — i.e. "********" means "keep".
	 *
	 * @param   array<string, mixed>  $changes
	 *
	 * @return  array<string, mixed>
	 */
	public static function stripPlaceholders(array $changes): array
	{
		$result = [];

		foreach ($changes as $key => $value)
		{
			if ($value === self::PLACEHOLDER)
			{
				continue;
			}

			if (is_array($value))
			{
				$value = self::stripPlaceholders($value);
			}

			$result[$key] = $value;
		}

		return $result;
	}

	private static function isEmpty(mixed $value): bool
	{
		return $value === null || $value === '';
	}
}
