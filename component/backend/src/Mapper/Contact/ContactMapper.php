<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Mapper\Contact;

defined('_JEXEC') || die;

use Akeeba\Component\Mcp4j\Administrator\Bridge\Support\NullFilter;
use Akeeba\Component\Mcp4j\Administrator\Bridge\Support\TagIds;

/**
 * Pure input/output mapping for the `contact_*` tools: tool parameters -> `ContactModel`/`ContactsModel`
 * state and save data, the output field whitelist, and the `contact_form_submit` payload.
 *
 * `ITEM_FIELDS`/`LIST_FIELDS` are derived from core's own Web Services API view for contacts
 * (`api/components/com_contact/src/View/Contacts/JsonapiView.php`), plus `catid`, `published`, `featured`
 * and `language` — core's own view omits every one of those publishing-related fields (an oversight there,
 * not a deliberate whitelist decision), yet a caller of `contact_update`/`contact_trash` needs them to
 * verify what it just did. Not output: `params` (display options — never settable through these tools),
 * `asset_id`, `associations`, `schemaorg`. See `.plans/09-contact-newsfeeds.md`.
 *
 * The most likely bug in this class: `state` is the contact's *address* state/province — never confuse it
 * with `published` (the publishing state). Both exist on a contact record, are both plain scalars, and
 * "state" is a deceptively good name for either.
 *
 * Pure: no Joomla.
 *
 * @since  0.0.1
 */
final class ContactMapper
{
	public const ITEM_FIELDS = [
		'id',
		'name',
		'alias',
		'catid',
		'published',
		'featured',
		'access',
		'language',
		'con_position',
		'address',
		'suburb',
		'state',
		'country',
		'postcode',
		'telephone',
		'mobile',
		'fax',
		'webpage',
		'email_to',
		'misc',
		'image',
		'user_id',
		'default_con',
		'sortname1',
		'sortname2',
		'sortname3',
		'metakey',
		'metadesc',
		'metadata',
		'created',
		'created_by',
		'created_by_alias',
		'modified',
		'modified_by',
		'publish_up',
		'publish_down',
		'checked_out',
		'checked_out_time',
		'version',
		'hits',
		'tags',
	];

	public const LIST_FIELDS = [
		'id',
		'name',
		'alias',
		'catid',
		'category_title',
		'published',
		'featured',
		'access',
		'access_level',
		'language',
		'language_title',
		'user_id',
		'linked_user',
		'created',
		'created_by',
		'modified_by',
		'ordering',
		'publish_up',
		'publish_down',
		'checked_out',
		'checked_out_time',
		'editor',
	];

	public const INT_FIELDS = [
		'catid',
		'published',
		'featured',
		'access',
		'user_id',
		'default_con',
		'created_by',
		'modified_by',
		'ordering',
		'checked_out',
		'version',
		'hits',
	];

	public const JSON_FIELDS = ['metadata'];

	/**
	 * `ContactsModel::getListQuery()` state. `filter.published` is always set, to `''` when `$state` is
	 * null (the model then shows published + unpublished, states 0 and 1, exactly like the backend list).
	 *
	 * @return  array<string, mixed>
	 */
	public static function listState(?string $filterSearch, ?int $filterState, ?int $filterCategory, ?string $filterLanguage): array
	{
		$data = [
			'list.ordering'  => 'a.name',
			'list.direction' => 'ASC',
		];

		if ($filterSearch !== null && $filterSearch !== '')
		{
			$data['filter.search'] = $filterSearch;
		}

		$data['filter.published'] = $filterState !== null ? (string) $filterState : '';

		if ($filterCategory !== null)
		{
			$data['filter.category_id'] = $filterCategory;
		}

		if ($filterLanguage !== null)
		{
			$data['filter.language'] = $filterLanguage;
		}

		return $data;
	}

	/**
	 * @return  array<string, mixed>
	 */
	public static function createData(
		string $name,
		int $catId,
		?string $alias,
		int $published,
		?int $access,
		string $language,
		?string $email,
		?string $address,
		?string $suburb,
		?string $state,
		?string $country,
		?string $postcode,
		?string $telephone,
		?string $fax,
		?string $webpage,
		?string $misc,
		?int $userId,
		?array $tags,
		?string $metadesc,
		?string $metakey
	): array {
		$data = [
			'name'      => $name,
			'catid'     => $catId,
			'published' => $published,
			'access'    => $access ?? 1,
			'language'  => $language,
			'tags'      => $tags !== null ? TagIds::fromMixed($tags) : [],
		];

		// "Never generate an alias in PHP" (see AbstractTool's docblock) — leave it unset for an empty
		// value and let Table\Contact::check()'s own generateAlias() derive one from the name.
		if ($alias !== null && $alias !== '')
		{
			$data['alias'] = $alias;
		}

		if ($email !== null)
		{
			$data['email_to'] = $email;
		}

		if ($address !== null)
		{
			$data['address'] = $address;
		}

		if ($suburb !== null)
		{
			$data['suburb'] = $suburb;
		}

		// The contact's address state/province, NOT the publishing state ("published", above).
		if ($state !== null)
		{
			$data['state'] = $state;
		}

		if ($country !== null)
		{
			$data['country'] = $country;
		}

		if ($postcode !== null)
		{
			$data['postcode'] = $postcode;
		}

		if ($telephone !== null)
		{
			$data['telephone'] = $telephone;
		}

		if ($fax !== null)
		{
			$data['fax'] = $fax;
		}

		if ($webpage !== null)
		{
			$data['webpage'] = $webpage;
		}

		if ($misc !== null)
		{
			$data['misc'] = $misc;
		}

		if ($userId !== null)
		{
			$data['user_id'] = $userId;
		}

		if ($metadesc !== null)
		{
			$data['metadesc'] = $metadesc;
		}

		if ($metakey !== null)
		{
			$data['metakey'] = $metakey;
		}

		return $data;
	}

	/**
	 * Only non-null parameters are included (null = "don't change" — see `.plans/00-conventions.md` §6).
	 * `tags` is always sent explicitly: the given ids when non-null, otherwise the current record's own
	 * tags (re-normalised) — `getItem()` returns `tags` as a `TagsHelper`-shaped value that must never
	 * reach `save()` unconverted.
	 *
	 * @return  array<string, mixed>
	 */
	public static function updateData(
		?string $name,
		?int $catId,
		?string $alias,
		?int $published,
		?int $access,
		?string $language,
		?string $email,
		?string $address,
		?string $suburb,
		?string $state,
		?string $country,
		?string $postcode,
		?string $telephone,
		?string $fax,
		?string $webpage,
		?string $misc,
		?int $userId,
		?array $tags,
		?string $metadesc,
		?string $metakey,
		array $currentItem
	): array {
		$data = NullFilter::withoutNulls([
			'name'      => $name,
			'catid'     => $catId,
			'alias'     => $alias,
			'published' => $published,
			'access'    => $access,
			'language'  => $language,
			'email_to'  => $email,
			'address'   => $address,
			'suburb'    => $suburb,
			'state'     => $state,
			'country'   => $country,
			'postcode'  => $postcode,
			'telephone' => $telephone,
			'fax'       => $fax,
			'webpage'   => $webpage,
			'misc'      => $misc,
			'user_id'   => $userId,
			'metadesc'  => $metadesc,
			'metakey'   => $metakey,
		]);

		$data['tags'] = $tags !== null ? TagIds::fromMixed($tags) : TagIds::fromMixed($currentItem['tags'] ?? null);

		return $data;
	}

	/**
	 * `tags` -> a clean `list<int>`.
	 *
	 * @return  array<string, mixed>
	 */
	public static function normaliseItem(array $item): array
	{
		if (array_key_exists('tags', $item))
		{
			$item['tags'] = TagIds::fromMixed($item['tags']);
		}

		return $item;
	}

	/**
	 * The `com_contact.contact` site form's field names for `contact_form_submit`
	 * ({@see \Akeeba\Component\Mcp4j\Administrator\Tool\Contact\Contacts::submitContactForm()}).
	 *
	 * @return  array<string, mixed>
	 */
	public static function submitData(string $name, string $email, string $subject, string $message): array
	{
		return [
			'contact_name'    => $name,
			'contact_email'   => $email,
			'contact_subject' => $subject,
			'contact_message' => $message,
		];
	}
}
