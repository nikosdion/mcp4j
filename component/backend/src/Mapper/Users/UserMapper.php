<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Mapper\Users;

defined('_JEXEC') || die;

use Akeeba\Component\Mcp4j\Administrator\Bridge\Support\NullFilter;

/**
 * Pure input/output mapping for the `users_*` tools: tool parameters -> `UsersModel`/`UserModel` state and
 * save data, and the output field whitelist.
 *
 * `ITEM_FIELDS`/`LIST_FIELDS` are derived from `api/components/com_users/src/View/Users/JsonapiView.php`'s
 * `$fieldsToRenderItem`/`$fieldsToRenderList`, plus `requireReset` (useful for an LLM deciding whether a
 * user needs a password reset; not a secret) — custom user fields (`FieldsHelper::getFields()`) are out of
 * scope, matching the original `joomla-mcp-php` project, which never exposed them either. `UsersModel`/
 * `UserModel` select `a.*`, so {@see SENSITIVE} is the only thing standing between an LLM and password
 * hashes/MFA secrets — see `.plans/00-conventions.md` §5.9 and `.plans/05-users.md`.
 *
 * Pure: no Joomla.
 *
 * @since  0.0.1
 */
final class UserMapper
{
	public const ITEM_FIELDS = [
		'id',
		'name',
		'username',
		'email',
		'block',
		'sendEmail',
		'registerDate',
		'lastvisitDate',
		'lastResetTime',
		'resetCount',
		'requireReset',
		'groups',
	];

	public const LIST_FIELDS = [
		'id',
		'name',
		'username',
		'email',
		'block',
		'sendEmail',
		'registerDate',
		'lastvisitDate',
		'lastResetTime',
		'resetCount',
		'requireReset',
		'group_count',
		'group_names',
	];

	public const INT_FIELDS = ['block', 'sendEmail', 'resetCount', 'requireReset', 'group_count'];

	public const JSON_FIELDS = [];

	/**
	 * Every key that must never appear in any Users-category tool output — {@see \Akeeba\Component\Mcp4j\Administrator\Bridge\Support\OutputShaper}'s
	 * whitelist (`ITEM_FIELDS`/`LIST_FIELDS`) is what actually keeps these out (an explicit whitelist,
	 * never `a.*` passthrough); this constant exists so a unit test can assert the two never intersect,
	 * and to document the list `.plans/05-users.md` calls out by name.
	 */
	public const SENSITIVE = [
		'password',
		'password_clear',
		'otpKey',
		'otep',
		'activation',
		'params',
		'authProvider',
		'mfaRecords',
	];

	/**
	 * `UsersModel::getListQuery()`/`getStoreId()` state — see `.plans/05-users.md`'s Mappers section.
	 * `filter.state` filters the `a.block` column (0 = not blocked, 1 = blocked); `filter.range` is
	 * restricted to the backend UI's own range keywords (`UsersModel::buildDateRange()`), rejecting
	 * anything else rather than silently ignoring it the way the original's HTTP passthrough did — see
	 * the final report's deviations list.
	 *
	 * @throws  \InvalidArgumentException  `$range` is not one of the allowed keywords.
	 *
	 * @return  array<string, mixed>
	 */
	public static function listState(?string $search, ?int $state, ?int $group, ?string $range): array
	{
		$data = [
			'list.ordering'  => 'a.name',
			'list.direction' => 'ASC',
		];

		if ($search !== null && $search !== '')
		{
			$data['filter.search'] = $search;
		}

		if ($state !== null)
		{
			$data['filter.state'] = $state;
		}

		if ($group !== null && $group > 0)
		{
			$data['filter.group_id'] = $group;
		}

		if ($range !== null)
		{
			if (!in_array($range, self::ALLOWED_RANGES, true))
			{
				throw new \InvalidArgumentException(sprintf(
					'Invalid filterRange "%s". Allowed values: %s.',
					$range,
					implode(', ', self::ALLOWED_RANGES)
				));
			}

			$data['filter.range'] = $range;
		}

		return $data;
	}

	private const ALLOWED_RANGES = [
		'today',
		'past_week',
		'past_1month',
		'past_3month',
		'past_6month',
		'past_year',
		'post_year',
	];

	/**
	 * @return  array<string, mixed>
	 */
	public static function createData(
		string $name,
		string $username,
		string $email,
		string $password,
		int $block,
		?bool $sendEmail,
		?bool $requireReset,
		?array $groups,
		int $defaultGroup
	): array {
		return [
			'name'         => $name,
			'username'     => $username,
			'email'        => $email,
			'password'     => $password,
			'password2'    => $password,
			'block'        => $block,
			'sendEmail'    => $sendEmail !== null ? (int) $sendEmail : 0,
			'requireReset' => $requireReset !== null ? (int) $requireReset : 0,
			'groups'       => ($groups !== null && $groups !== [])
				? array_values(array_map('intval', $groups))
				: [$defaultGroup],
		];
	}

	/**
	 * Only non-null parameters are included (null = "don't change" — see `.plans/00-conventions.md` §6),
	 * **except** `password`/`password2`, which are always both present: `libraries/src/User/User.php::bind()`
	 * keeps the current hash only when `password` is empty, and would otherwise compare it against
	 * `password2` for equality — so an explicit `''` (rather than an absent key) is what makes "don't
	 * change the password" work once {@see \Akeeba\Component\Mcp4j\Administrator\Bridge\AbstractTool::saveItem()}
	 * merges this onto the current record (whose own `password` column holds the hash, which must never
	 * be echoed back into the save payload as a literal password value).
	 *
	 * @param   array<string, mixed>  $currentItem  The current (normalised) user record — only `groups` is
	 *                                               read from it, as the "keep the existing groups" fallback.
	 *
	 * @return  array<string, mixed>
	 */
	public static function updateData(
		?string $name,
		?string $username,
		?string $email,
		?string $password,
		?int $block,
		?bool $sendEmail,
		?bool $requireReset,
		?array $groups,
		array $currentItem
	): array {
		$data = NullFilter::withoutNulls([
			'name'         => $name,
			'username'     => $username,
			'email'        => $email,
			'block'        => $block,
			'sendEmail'    => $sendEmail !== null ? (int) $sendEmail : null,
			'requireReset' => $requireReset !== null ? (int) $requireReset : null,
		]);

		$data['password']  = $password ?? '';
		$data['password2'] = $password ?? '';

		$data['groups'] = $groups !== null
			? array_values(array_map('intval', $groups))
			: array_values(array_map('intval', $currentItem['groups'] ?? []));

		return $data;
	}

	/**
	 * Normalises `groups` from the shape `Table\User::load()` produces (`['8' => '8', '2' => '2']`, string
	 * keys and values) into a sorted `list<int>`. Leaves every other key untouched.
	 *
	 * @return  array<string, mixed>
	 */
	public static function normaliseItem(array $item): array
	{
		if (array_key_exists('groups', $item))
		{
			$item['groups'] = self::normaliseGroups($item['groups']);
		}

		return $item;
	}

	/**
	 * @return  list<int>
	 */
	private static function normaliseGroups(mixed $groups): array
	{
		if (!is_array($groups))
		{
			return [];
		}

		$ids = array_values(array_unique(array_map('intval', array_values($groups))));

		sort($ids);

		return $ids;
	}

	/**
	 * Pure helper: does any of `$groupIds` grant `core.admin` (i.e. Super User)? `$checkGroup` is supplied
	 * by the tool as `fn (int $g): bool => Access::checkGroup($g, 'core.admin') === true` — this class must
	 * never reference `\Joomla\CMS\Access\Access` directly (see the class docblock, "Pure: no Joomla").
	 */
	public static function grantsSuperUser(array $groupIds, \Closure $checkGroup): bool
	{
		foreach ($groupIds as $groupId)
		{
			if ($checkGroup((int) $groupId))
			{
				return true;
			}
		}

		return false;
	}
}
