<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Tool\Contact;

defined('_JEXEC') || die;

use Akeeba\Component\Mcp4j\Administrator\Bridge\AbstractTool;
use Akeeba\Component\Mcp4j\Administrator\Mapper\Contact\ContactMapper;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\McpTool;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Attribute\Schema;
use Akeeba\Component\Mcp4j\Administrator\Mcp\Exception\ToolException;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Event\Contact\SubmitContactEvent;
use Joomla\CMS\Event\Contact\ValidateContactEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Mail\Exception\MailDisabledException;
use Joomla\CMS\Mail\MailTemplate;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\String\PunycodeHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\UserFactoryInterface;
use PHPMailer\PHPMailer\Exception as PhpMailerException;

/**
 * MCP tools for Joomla! contacts (`com_contact`) management, including public contact-form submission.
 *
 * Ported from the original `joomla-mcp-php` project's `src/Server/Contact/Contacts.php` — method names,
 * tool names/descriptions, parameters and annotations are copied verbatim from there (see
 * `.plans/00-conventions.md` §2); only the method *bodies* differ (Joomla model calls instead of
 * HTTP/JSON:API calls). `contact_delete`'s return type changes from the original's `bool` to the
 * framework-wide `['deleted' => true, 'id' => int]` shape. `contact_form_submit` is a from-scratch port of
 * core's own `Joomla\Component\Contact\Api\Controller\ContactController::submitForm()`/`_sendEmail()`
 * (5.4/6.1 semantics, applied on every supported version — see that method's own docblock) rather than an
 * HTTP call to it, since this component never dispatches into another component's API controllers (see
 * `.plans/00-conventions.md` §1). See the final report for the full list of deviations.
 *
 * @since  0.0.1
 */
class Contacts extends AbstractTool
{
	private const OPTION = 'com_contact';

	#[McpTool(
		name: 'contact_list',
		description: 'List existing contacts',
		readOnlyHint: true,
	)]
	public function listContacts(
		#[Schema(description: 'Search contacts by name')]
		?string $filterSearch = null,
		#[Schema(
			description: 'Filter by publish state: 0=unpublished, 1=published, 2=archived, -2=trashed',
			enum: [null, 0, 1, 2, -2]
		)]
		?int $filterState = null,
		#[Schema(description: 'Filter by category ID')]
		?int $filterCategory = null,
		#[Schema(description: 'Filter by language code, or "*" for all languages')]
		?string $filterLanguage = null,
		#[Schema(description: 'Maximum number of items to return per page', minimum: 1)]
		?int $pageLimit = null,
		#[Schema(description: 'Starting record offset for pagination (0-based)', minimum: 0)]
		?int $pageOffset = null
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);

		/** @var \Joomla\Component\Contact\Administrator\Model\ContactsModel $model */
		$model = $this->createModel(self::OPTION, 'Contacts');

		return $this->listItems(
			$model,
			ContactMapper::listState($filterSearch, $filterState, $filterCategory, $filterLanguage),
			$pageLimit,
			$pageOffset,
			ContactMapper::LIST_FIELDS,
			ContactMapper::INT_FIELDS,
			ContactMapper::JSON_FIELDS
		);
	}

	#[McpTool(
		name: 'contact_read',
		description: 'Retrieve the information of the specified contact',
		readOnlyHint: true,
	)]
	public function readContact(
		#[Schema(description: 'The ID of the contact to retrieve')]
		int $id
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);

		return $this->readFresh($id);
	}

	#[McpTool(
		name: 'contact_create',
		description: 'Create a new contact'
	)]
	public function createContact(
		#[Schema(description: 'Contact name', minLength: 1, maxLength: 255)]
		string $name,
		#[Schema(description: 'Contact category ID', minimum: 0, exclusiveMinimum: true)]
		int $catId,
		#[Schema(description: 'URL slug for the contact', pattern: '^[a-z0-9-_]+$')]
		?string $alias = null,
		#[Schema(
			description: 'Publish state: 0=unpublished, 1=published, 2=archived, -2=trashed',
			enum: [0, 1, 2, -2]
		)]
		int $published = 1,
		#[Schema(description: 'The Joomla! viewing access level for the contact')]
		?int $access = null,
		#[Schema(description: 'Language code for the contact, or "*" for all languages')]
		string $language = '*',
		#[Schema(description: 'Contact email address')]
		?string $email = null,
		#[Schema(description: 'Contact street address')]
		?string $address = null,
		#[Schema(description: 'Contact city/suburb')]
		?string $suburb = null,
		#[Schema(description: 'Contact state/province')]
		?string $state = null,
		#[Schema(description: 'Contact country')]
		?string $country = null,
		#[Schema(description: 'Contact postal code')]
		?string $postcode = null,
		#[Schema(description: 'Contact telephone number')]
		?string $telephone = null,
		#[Schema(description: 'Contact fax number')]
		?string $fax = null,
		#[Schema(description: 'Contact webpage URL')]
		?string $webpage = null,
		#[Schema(description: 'Miscellaneous information about the contact')]
		?string $misc = null,
		#[Schema(description: 'The Joomla user ID linked to this contact')]
		?int $userId = null,
		#[Schema(description: 'An array of tag IDs to associate with the contact', items: ['type' => 'integer'], minItems: 0, uniqueItems: true)]
		?array $tags = null,
		#[Schema(description: 'Meta description for the contact')]
		?string $metadesc = null,
		#[Schema(description: 'Meta keywords for the contact')]
		?string $metakey = null
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);

		// A contact can never auto-create a category on the fly through this tool (unlike
		// ContactModel::save(), which would happily create one named after a nonexistent numeric catid) —
		// the category must already exist and belong to com_contact, or this is refused loudly instead.
		$this->requireContactCategory($catId);

		$this->requireAuthorised('core.create', self::OPTION . '.category.' . $catId);

		// getForm() would silently unset() featured/ordering/published for a user without core.edit.state
		// on the (new contact's) category — refuse loudly instead of the LLM believing it set the state.
		// $published's non-nullable default (1) only counts as "given" when it actually differs from the
		// no-op default, same convention as content_articles_create's $state/$featured.
		if ($published !== 1)
		{
			$this->requireAuthorised('core.edit.state', self::OPTION . '.category.' . $catId);
		}

		$data = ContactMapper::createData(
			$name,
			$catId,
			$alias,
			$published,
			$access,
			$language,
			$email,
			$address,
			$suburb,
			$state,
			$country,
			$postcode,
			$telephone,
			$fax,
			$webpage,
			$misc,
			$userId,
			$tags,
			$metadesc,
			$metakey
		);

		/** @var \Joomla\Component\Contact\Administrator\Model\ContactModel $model */
		$model = $this->createModel(self::OPTION, 'Contact');

		$newId = $this->saveItem($model, $data, null, 'contact');

		return $this->readFresh($newId);
	}

	#[McpTool(
		name: 'contact_update',
		description: 'Update an existing contact',
		idempotentHint: true,
	)]
	public function updateContact(
		#[Schema(description: 'The ID of the contact to modify')]
		int $id,
		#[Schema(description: 'Contact name', minLength: 1, maxLength: 255)]
		?string $name = null,
		#[Schema(description: 'Contact category ID', minimum: 0, exclusiveMinimum: true)]
		?int $catId = null,
		#[Schema(description: 'URL slug for the contact', pattern: '^[a-z0-9-_]+$')]
		?string $alias = null,
		#[Schema(
			description: 'Publish state: null=no change, 0=unpublished, 1=published, 2=archived, -2=trashed',
			enum: [null, 0, 1, 2, -2]
		)]
		?int $published = null,
		#[Schema(description: 'The Joomla! viewing access level for the contact')]
		?int $access = null,
		#[Schema(description: 'Language code for the contact, or "*" for all languages')]
		?string $language = null,
		#[Schema(description: 'Contact email address')]
		?string $email = null,
		#[Schema(description: 'Contact street address')]
		?string $address = null,
		#[Schema(description: 'Contact city/suburb')]
		?string $suburb = null,
		#[Schema(description: 'Contact state/province')]
		?string $state = null,
		#[Schema(description: 'Contact country')]
		?string $country = null,
		#[Schema(description: 'Contact postal code')]
		?string $postcode = null,
		#[Schema(description: 'Contact telephone number')]
		?string $telephone = null,
		#[Schema(description: 'Contact fax number')]
		?string $fax = null,
		#[Schema(description: 'Contact webpage URL')]
		?string $webpage = null,
		#[Schema(description: 'Miscellaneous information about the contact')]
		?string $misc = null,
		#[Schema(description: 'The Joomla user ID linked to this contact')]
		?int $userId = null,
		#[Schema(description: 'An array of tag IDs to associate with the contact', items: ['type' => 'integer'], minItems: 0, uniqueItems: true)]
		?array $tags = null,
		#[Schema(description: 'Meta description for the contact')]
		?string $metadesc = null,
		#[Schema(description: 'Meta keywords for the contact')]
		?string $metakey = null
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);

		/** @var \Joomla\Component\Contact\Administrator\Model\ContactModel $model */
		$model = $this->createModel(self::OPTION, 'Contact');

		$current      = ContactMapper::normaliseItem($this->loadItem($model, $id, 'contact'));
		$currentCatId = (int) ($current['catid'] ?? 0);

		// The backend controller's allowEdit() checks core.edit (or core.edit.own + ownership) on the
		// CATEGORY asset, not on the contact itself — requireEditAllowed() checks a single asset, so it is
		// called with that category asset, exactly matching the backend's own logic.
		$ownerId = isset($current['created_by']) ? (int) $current['created_by'] : null;
		$this->requireEditAllowed(self::OPTION . '.category.' . $currentCatId, $ownerId);

		if ($catId !== null && $catId !== $currentCatId)
		{
			// Moving a contact into a different category is, ACL-wise, "creating" it there.
			$this->requireContactCategory($catId);
			$this->requireAuthorised('core.create', self::OPTION . '.category.' . $catId);
		}

		if ($published !== null)
		{
			$this->requireAuthorised('core.edit.state', self::OPTION . '.category.' . $currentCatId);
		}

		$data = ContactMapper::updateData(
			$name,
			$catId,
			$alias,
			$published,
			$access,
			$language,
			$email,
			$address,
			$suburb,
			$state,
			$country,
			$postcode,
			$telephone,
			$fax,
			$webpage,
			$misc,
			$userId,
			$tags,
			$metadesc,
			$metakey,
			$current
		);

		$this->saveItem($model, $data, $id, 'contact');

		return $this->readFresh($id);
	}

	#[McpTool(
		name: 'contact_trash',
		description: 'Moves a contact to the trash by setting its state to -2',
		destructiveHint: true,
	)]
	public function trashContact(
		#[Schema(description: 'The ID of the contact to trash')]
		int $id
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);

		/** @var \Joomla\Component\Contact\Administrator\Model\ContactModel $model */
		$model = $this->createModel(self::OPTION, 'Contact');

		$current = $this->loadItem($model, $id, 'contact');

		$this->requireAuthorised('core.edit.state', self::OPTION . '.category.' . (int) ($current['catid'] ?? 0));

		$this->changeState($model, $id, -2, 'contact');

		return $this->readFresh($id);
	}

	#[McpTool(
		name: 'contact_delete',
		description: 'Permanently deletes a contact. Automatically trashes it first if needed.',
		destructiveHint: true,
	)]
	public function deleteContact(
		#[Schema(description: 'The ID of the contact to delete')]
		int $id
	): array
	{
		$this->requireAuthorised('core.manage', self::OPTION);

		/** @var \Joomla\Component\Contact\Administrator\Model\ContactModel $model */
		$model = $this->createModel(self::OPTION, 'Contact');

		$current = $this->loadItem($model, $id, 'contact');

		$this->requireAuthorised('core.delete', self::OPTION . '.category.' . (int) ($current['catid'] ?? 0));

		// AdminModel::publish() prunes (and no-ops on) a record already at the target state — see
		// AbstractTool::changeState()'s docblock — so only trash when it isn't already trashed, exactly
		// this tool's own contract ("automatically trashes it first if needed").
		if ((int) ($current['published'] ?? 0) !== -2)
		{
			/** @var \Joomla\Component\Contact\Administrator\Model\ContactModel $trashModel */
			$trashModel = $this->createModel(self::OPTION, 'Contact');

			$this->changeState($trashModel, $id, -2, 'contact');
		}

		/** @var \Joomla\Component\Contact\Administrator\Model\ContactModel $deleteModel */
		$deleteModel = $this->createModel(self::OPTION, 'Contact');

		$this->deleteItem($deleteModel, $id, 'contact');

		return ['deleted' => true, 'id' => $id];
	}

	/**
	 * Submits the SITE contact form for a specific contact — a from-scratch port of core's own
	 * `Joomla\Component\Contact\Api\Controller\ContactController::submitForm()` /
	 * `_sendEmail()` (5.4/6.1 semantics; see class docblock for why every supported version behaves this
	 * way, including 4.4, whose own API controller has a genuine bug here — it reports "Error sending
	 * message" even when `custom_reply` is enabled and no e-mail was ever supposed to be sent).
	 *
	 * Deliberately **not** gated behind `core.manage` on `com_contact`: this is the public-facing contact
	 * form every site visitor can already reach anonymously over HTTP, subject only to the target contact's
	 * own viewing access level and its `show_email_form` display option (checked below) — any authenticated
	 * MCP caller (any Joomla user with an API token) may submit it, exactly as any site visitor could.
	 *
	 * @throws  ToolException  Not found (missing/unpublished/not viewable contact — deliberately
	 *                         indistinguishable from each other, exactly like the site's own behaviour, so
	 *                         this never confirms the existence of a contact the caller cannot view), the
	 *                         contact does not accept form submissions, form validation failed (this
	 *                         includes any site-configured CAPTCHA plugin — never bypassed here), a contact
	 *                         plugin rejected the submission, or the e-mail could not be sent (mail server
	 *                         details are never included in the message).
	 */
	#[McpTool(
		name: 'contact_form_submit',
		description: 'Submit a contact form for a specific contact'
	)]
	public function submitContactForm(
		#[Schema(description: 'The ID of the contact to submit the form for')]
		int $contactId,
		#[Schema(description: 'The name of the person submitting the form', minLength: 1)]
		string $contactName,
		#[Schema(description: 'The email address of the person submitting the form')]
		string $contactEmail,
		#[Schema(description: 'The subject of the contact form message', minLength: 1)]
		string $contactSubject,
		#[Schema(description: 'The message body of the contact form', minLength: 1)]
		string $contactMessage
	): array
	{
		$app   = $this->getApplication();
		$input = $app->getInput();

		// See ensureSessionRegistryIsObject()'s own docblock — this must run before anything in this method
		// touches CMSApplication::getUserState() (SiteApplication::getParams()'s menu/language handling
		// does, indirectly, among others).
		$this->ensureSessionRegistryIsObject();

		// The site ContactModel::populateState() reads the contact id from $input->post when the current
		// client is 'api' — set it before the model's state is ever touched.
		$input->post->set('id', $contactId);

		$app->getLanguage()->load('com_contact', JPATH_SITE);

		// populateState() also calls $app->getParams() on a FRESH SiteApplication instance
		// (Factory::getContainer()->get(SiteApplication::class), lazily created on first access) that has
		// never gone through Joomla's own site routing, so it has no `option` request variable of its own —
		// SiteApplication::getParams(null) falls back to `$this->input->getCmd('option', null)` on ITS OWN
		// Input, which is NEVER the same object as $app->getInput() above (ApiApplication is always
		// constructed with a null $input of its own — see Joomla\CMS\Service\Provider\Application, every
		// supported version). Left unresolved, this has two effects: (1) it gets passed straight into
		// ComponentHelper::getParams() with no further guard, a PHP 8.1+ "Using null as an array offset"
		// deprecation whose HTML notice corrupts the JSON-RPC response body if displayed; (2) far worse,
		// ComponentHelper::getParams(null) silently returns a brand-new, EMPTY params Registry rather than
		// com_contact's real global configuration — meaning show_email_form/show_email_copy/custom_reply
		// etc. would wrongly read as unset for EVERY contact, breaking this tool outright.
		//
		// SiteApplication (on every supported version) constructs its own Input with `new Input()` when
		// none is injected (4.4 always; 5.x/6.x too, since it's the CONTAINER's shared `Joomla\Input\Input`
		// instance doing the injecting, itself lazily `new Input()`-constructed on first request) — and a
		// bare `new Input()` reads directly from PHP's own superglobals at construction time, not from
		// anything reachable through $app or the container after the fact. The one lever that reaches it on
		// every version alike is the superglobal itself: seed $_GET['option'] before anything triggers that
		// first construction (our own ApiApplication never touches 'option', so nothing does this already).
		// Restored in the `finally` block below regardless of how this method exits.
		$previousGetOption = $_GET['option'] ?? null;
		$_GET['option']    = self::OPTION;

		// Deprecation notices are also suppressed for the whole of this method — not silently swallowed
		// (the previous handler is always restored below), and only E_DEPRECATED is intercepted, so a
		// genuine warning/error still surfaces normally. This is defence in depth for any deprecation this
		// core code path may still emit on a given Joomla version despite the $_GET seed above.
		set_error_handler(static fn (int $errno, string $errstr): bool => true, E_DEPRECATED);

		try
		{
			/** @var \Joomla\Component\Contact\Site\Model\ContactModel $model */
			$model = $this->createModel('com_contact', 'Contact', 'Site', ['ignore_request' => false]);

			// Force populateState() to run now (see .plans/00-conventions.md §5.4) before overriding
			// filter.published below — calling setState() first would never trigger it, silently leaving
			// contact.id/params unset.
			$model->getState('contact.id');
			$model->setState('filter.published', 1);

			try
			{
				$contact = $model->getItem($contactId);
			}
			catch (\Exception $e)
			{
				if ($e->getCode() === 404)
				{
					throw new ToolException(sprintf('Not found: contact #%d', $contactId));
				}

				throw $e;
			}

			if (!$contact || $contact->id === null)
			{
				throw new ToolException(sprintf('Not found: contact #%d', $contactId));
			}

			// Hide contacts the current user may not view, exactly like the site's own contact view does —
			// deliberately indistinguishable from "does not exist" (see this method's own docblock).
			if (!$contact->params->get('access-view'))
			{
				throw new ToolException(sprintf('Not found: contact #%d', $contactId));
			}

			// ContactModel::getItem() built $contact->params by merging the contact's own (per-item)
			// params registry onto `$this->getState('params')` — populateState()'s `$app->getParams()`
			// call, `$app` being a FRESH SiteApplication instance that has never gone through Joomla's own
			// site routing. On every supported version that instance's `$option` request variable resolves
			// to null (nothing this synthetic request can reliably seed — see the $_GET seed above, kept as
			// it is still otherwise correct/harmless), so `SiteApplication::getParams(null)` bases the whole
			// result on an EMPTY params Registry instead of com_contact's real global configuration — only
			// generic site/menu meta (page_title, robots, ...) ends up set. The result: show_email_form,
			// show_email_copy, custom_reply, etc. all wrongly read as unset for every contact, breaking this
			// tool outright, not just corrupting the response body. Rebuild the base from com_contact's real
			// global params here instead, keeping whatever ended up in $contact->params (the genuine
			// per-item overrides, plus the harmless meta keys) layered on top exactly as core intended.
			$componentParams = ComponentHelper::getParams(self::OPTION);
			$componentParams->merge($contact->params);
			$contact->params = $componentParams;

			if (!$contact->params->get('show_email_form'))
			{
				throw new ToolException('This contact does not accept messages through its contact form.');
			}

			PluginHelper::importPlugin('contact');

			Form::addFormPath(JPATH_SITE . '/components/com_contact/forms');

			$form = $model->getForm();

			if (!$form)
			{
				throw new ToolException($this->modelError($model, 'Cannot load the contact form'));
			}

			$data = ContactMapper::submitData($contactName, $contactEmail, $contactSubject, $contactMessage);

			if (!$model->validate($form, $data))
			{
				$messages = [];

				foreach (array_slice($model->getErrors(), 0, 3) as $error)
				{
					$messages[] = $error instanceof \Throwable ? $error->getMessage() : (string) $error;
				}

				if ($messages === [])
				{
					$messages[] = $this->modelError($model, 'Unknown validation error');
				}

				throw new ToolException('Validation failed for contact form: ' . implode('; ', $messages));
			}

			// Joomla 5.4/6.1 dispatch typed events (ValidateContactEvent/SubmitContactEvent); 4.4 (where
			// libraries/src/Event/Contact/ doesn't exist yet) triggers the same two plugin events the
			// classic way. Both paths let a contact plugin veto the submission by returning/throwing an
			// \Exception.
			if (class_exists(ValidateContactEvent::class))
			{
				$results = $app->getDispatcher()->dispatch(
					'onValidateContact',
					new ValidateContactEvent('onValidateContact', ['subject' => $contact, 'data' => $data])
				)->getArgument('result', []);

				foreach ($results as $result)
				{
					if ($result instanceof \Exception)
					{
						throw new ToolException($result->getMessage());
					}
				}

				$event = $app->getDispatcher()->dispatch(
					'onSubmitContact',
					new SubmitContactEvent('onSubmitContact', ['subject' => $contact, 'data' => $data])
				);
				$data  = $event->getArgument('data', $data);
			}
			else
			{
				$results = $app->triggerEvent('onValidateContact', [&$contact, &$data]);

				foreach ((array) $results as $result)
				{
					if ($result instanceof \Exception)
					{
						throw new ToolException($result->getMessage());
					}
				}

				$app->triggerEvent('onSubmitContact', [&$contact, &$data]);
			}

			$params = ComponentHelper::getParams('com_contact');

			if ($params->get('custom_reply'))
			{
				// Unlike core's own (buggy, on 4.4) API controller, this never fails just because no e-mail
				// was ever supposed to be sent — see this method's docblock and the final report's
				// deviations list.
				return [
					'success'   => true,
					'emailSent' => false,
					'message'   => 'The contact form was processed; this site handles replies with a plugin (custom reply), so no e-mail was sent by Joomla.',
				];
			}

			if (!$this->sendContactFormEmail($data, $contact))
			{
				throw new ToolException('Error sending message');
			}

			return ['success' => true, 'emailSent' => true, 'message' => 'Message sent.'];
		}
		finally
		{
			restore_error_handler();

			if ($previousGetOption === null)
			{
				unset($_GET['option']);
			}
			else
			{
				$_GET['option'] = $previousGetOption;
			}
		}
	}

	/**
	 * `CategoryModel` reads `extension` from the request **input**, not from model state — set it before
	 * every `createModel('com_categories', ...)` (see `.plans/00-conventions.md` §5.4-5.5). Throws
	 * `Not found: category #<id>` both when the category doesn't exist at all and when it belongs to a
	 * different extension — `ContactModel::save()` would otherwise silently create a category named after
	 * the number for a nonexistent numeric catid; this tool refuses instead.
	 *
	 * @throws  ToolException
	 *
	 * @return  array<string, mixed>
	 */
	private function requireContactCategory(int $catId): array
	{
		$this->getApplication()->getInput()->set('extension', self::OPTION);

		/** @var \Joomla\Component\Categories\Administrator\Model\CategoryModel $model */
		$model = $this->createModel('com_categories', 'Category');

		$item = $this->loadItem($model, $catId, 'category');

		if (($item['extension'] ?? null) !== self::OPTION)
		{
			throw new ToolException(sprintf('Not found: category #%d', $catId));
		}

		return $item;
	}

	/**
	 * Re-reads a contact through a fresh model and shapes it — used by every tool method that returns a
	 * record, so a just-saved/state-changed contact is always read back rather than assembled from
	 * in-memory data.
	 *
	 * @return  array<string, mixed>
	 */
	private function readFresh(int $id): array
	{
		/** @var \Joomla\Component\Contact\Administrator\Model\ContactModel $model */
		$model = $this->createModel(self::OPTION, 'Contact');

		return $this->shape(
			ContactMapper::normaliseItem($this->loadItem($model, $id, 'contact')),
			ContactMapper::ITEM_FIELDS,
			ContactMapper::INT_FIELDS,
			ContactMapper::JSON_FIELDS
		);
	}

	/**
	 * Port of core's `ContactController::_sendEmail()`. The original `joomla-mcp-php` tool has no
	 * "send me a copy" parameter, so — unlike core, which offers one when `show_email_copy` is on — this
	 * never sends a copy to the sender. Never includes mail-server exception details in its return value:
	 * a failure is logged server-side (`Log::add()`) and reported to the caller only as a plain boolean.
	 */
	private function sendContactFormEmail(array $data, object $contact): bool
	{
		$app = $this->getApplication();
		$app->getLanguage()->load('com_contact', JPATH_SITE, $app->getLanguage()->getTag(), true);

		$emailTo = (string) ($contact->email_to ?? '');

		if ($emailTo === '' && (int) ($contact->user_id ?? 0) !== 0)
		{
			$contactUser = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById((int) $contact->user_id);
			$emailTo     = (string) $contactUser->email;
		}

		if ($emailTo === '')
		{
			return false;
		}

		$templateData = [
			'sitename'     => $app->get('sitename'),
			'name'         => $data['contact_name'],
			'contactname'  => $contact->name,
			'email'        => PunycodeHelper::emailToPunycode($data['contact_email']),
			'subject'      => $data['contact_subject'],
			'body'         => stripslashes($data['contact_message']),
			'url'          => Uri::base(),
			'customfields' => '',
		];

		try
		{
			$mailer = new MailTemplate('com_contact.mail', $app->getLanguage()->getTag());
			$mailer->addRecipient($emailTo);
			$mailer->setReplyTo($templateData['email'], $templateData['name']);
			$mailer->addTemplateData($templateData);

			return (bool) $mailer->send();
		}
		catch (MailDisabledException|PhpMailerException $e)
		{
			Log::add($e->getMessage(), Log::WARNING, 'jerror');

			return false;
		}
	}

	/**
	 * `CMSApplication::getUserState()` (called, among other places, from `SiteApplication::getParams()`
	 * indirectly via its menu/language handling — the site model path `submitContactForm()` exercises)
	 * unconditionally reads a `Registry` object out of the session under the `registry` key (populated by
	 * Joomla's own session bootstrap on the very first request of a session) and immediately calls `->get()`
	 * on it.
	 *
	 * This MCP session's data is persisted across requests via
	 * {@see \Akeeba\Component\Mcp4j\Administrator\Support\SessionDataCodec}, which is deliberately JSON-only
	 * (see that class's own docblock: PHP `unserialize()` of data keyed by a client-supplied
	 * `Mcp-Session-Id` would be an object-injection gadget) — so once a session has been persisted and
	 * restored at least once, its `registry` entry has decayed from a `Registry` object to a plain array,
	 * and the very next `getUserState()` call anywhere in Joomla core is a PHP fatal ("Call to a member
	 * function get() on array"). This is a deliberate, documented trade-off of the session bridge (owned by
	 * plan 01/03, `component/backend/src/Support/` is out of scope for this plan to change — see
	 * `.plans/00-conventions.md` §8) — {@see \Akeeba\Component\Mcp4j\Administrator\Tool\Config\Config} hit
	 * the exact same issue (see its own copy of this method) and this is the same narrow, local workaround:
	 * if the session's `registry` entry has decayed to a plain array, re-wrap it in a `Registry` first.
	 */
	private function ensureSessionRegistryIsObject(): void
	{
		$session  = $this->getApplication()->getSession();
		$registry = $session->get('registry');

		if (is_array($registry))
		{
			$session->set('registry', new \Joomla\Registry\Registry($registry));
		}
	}
}
