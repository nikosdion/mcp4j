<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Mcp4j\Tests\Integration\Tests\Contact;

use Akeeba\Mcp4j\Tests\Integration\AbstractToolTestCase;

/**
 * End-to-end coverage for `contact_form_submit`
 * ({@see \Akeeba\Component\Mcp4j\Administrator\Tool\Contact\Contacts::submitContactForm()}). See
 * `.plans/09-contact-newsfeeds.md` Tests for the coverage this class is meant to satisfy.
 *
 * The Docker test site has no MTA, so a successful `_sendEmail()` cannot be asserted directly — tests that
 * would otherwise depend on it (scenario 4) only assert that the request reached that point (either a
 * reported mail failure, or a reported success), which is as far as this suite can prove without a real
 * mail transport. Scenario 5/6 (custom_reply enabled) sidestep the mail transport question entirely, since
 * no e-mail is sent in that path — see `Tool/Contact/Contacts.php::submitContactForm()`.
 */
class ContactFormSubmitTest extends AbstractToolTestCase
{
	private function fixtureCategory(): array
	{
		$category = $this->callToolOk('contact_categories_create', ['title' => $this->uniqueName('MCP4j Contact Form Fixture Cat')]);
		$this->registerCleanup(fn () => $this->callTool('contact_categories_delete', ['id' => $category['id']]));

		return $category;
	}

	/**
	 * @return  array{contact: array, category: array}
	 */
	private function fixturePublishedContact(array $overrides = []): array
	{
		$category = $this->fixtureCategory();

		$contact = $this->callToolOk('contact_create', array_replace([
			'name'      => $this->uniqueName('MCP4j Contact Form Recipient'),
			'catId'     => $category['id'],
			'email'     => 'recipient@example.com',
			'published' => 1,
		], $overrides));
		$this->registerCleanup(fn () => $this->callTool('contact_delete', ['id' => $contact['id']]));

		return ['contact' => $contact, 'category' => $category];
	}

	/**
	 * Temporarily sets com_contact's `custom_reply` option, registering a cleanup that restores the
	 * original value. Skips (not fails) when plan 10's config tools aren't available on this install.
	 */
	private function withCustomReplyEnabled(): void
	{
		$this->requireToolsOrSkip(['config_component_read', 'config_component_update']);

		$original = $this->callToolOk('config_component_read', ['componentName' => 'com_contact'])['custom_reply'] ?? 0;

		$this->registerCleanup(fn () => $this->callTool('config_component_update', [
			'componentName' => 'com_contact',
			'configData'    => ['custom_reply' => $original],
		]));

		$this->callToolOk('config_component_update', [
			'componentName' => 'com_contact',
			'configData'    => ['custom_reply' => 1],
		]);
	}

	public function testNonexistentContactIsNotFound(): void
	{
		$result = $this->callTool('contact_form_submit', [
			'contactId'      => 999999,
			'contactName'    => 'Jane Doe',
			'contactEmail'   => 'jane@example.com',
			'contactSubject' => 'Hello',
			'contactMessage' => 'A message',
		]);
		$this->assertNotFound($result);
	}

	public function testUnpublishedContactIsNotFound(): void
	{
		$fixture = $this->fixturePublishedContact(['published' => 0]);

		$result = $this->callTool('contact_form_submit', [
			'contactId'      => $fixture['contact']['id'],
			'contactName'    => 'Jane Doe',
			'contactEmail'   => 'jane@example.com',
			'contactSubject' => 'Hello',
			'contactMessage' => 'A message',
		]);
		$this->assertNotFound($result);
	}

	public function testInvalidEmailFailsValidation(): void
	{
		$fixture = $this->fixturePublishedContact();

		$result = $this->callTool('contact_form_submit', [
			'contactId'      => $fixture['contact']['id'],
			'contactName'    => 'Jane Doe',
			'contactEmail'   => 'nope',
			'contactSubject' => 'Hello',
			'contactMessage' => 'A message',
		]);

		$this->assertTrue($result['isError'], 'An invalid e-mail address must fail validation: ' . $result['text']);
		$this->assertStringStartsWith('Validation failed', $result['text']);
	}

	/**
	 * Proves the full validation/event/send path runs for a valid submission. The Docker site has no MTA,
	 * so either outcome (a reported mail failure, or a reported success) is accepted — see class docblock.
	 */
	public function testValidSubmissionWithoutCustomReplyRunsTheFullPath(): void
	{
		$fixture = $this->fixturePublishedContact();

		$result = $this->callTool('contact_form_submit', [
			'contactId'      => $fixture['contact']['id'],
			'contactName'    => 'Jane Doe',
			'contactEmail'   => 'jane@example.com',
			'contactSubject' => 'Hello',
			'contactMessage' => 'A message',
		]);

		if ($result['isError'])
		{
			$this->assertSame('Error sending message', $result['text']);
		}
		else
		{
			$this->assertTrue($result['data']['success']);
			$this->assertTrue($result['data']['emailSent']);
		}
	}

	public function testValidSubmissionWithCustomReplyEnabledSucceedsWithoutSendingEmail(): void
	{
		$this->withCustomReplyEnabled();

		$fixture = $this->fixturePublishedContact();

		$result = $this->callToolOk('contact_form_submit', [
			'contactId'      => $fixture['contact']['id'],
			'contactName'    => 'Jane Doe',
			'contactEmail'   => 'jane@example.com',
			'contactSubject' => 'Hello',
			'contactMessage' => 'A message',
		]);

		$this->assertTrue($result['success']);
		$this->assertFalse($result['emailSent']);
	}

	public function testLowPrivilegeUserMaySubmitThePublicFormWhenCustomReplyIsEnabled(): void
	{
		$this->withCustomReplyEnabled();

		$fixture = $this->fixturePublishedContact();

		$result = $this->callToolAsLowPriv('contact_form_submit', [
			'contactId'      => $fixture['contact']['id'],
			'contactName'    => 'Jane Doe',
			'contactEmail'   => 'jane@example.com',
			'contactSubject' => 'Hello',
			'contactMessage' => 'A message',
		]);

		$this->assertFalse($result['isError'], 'contact_form_submit is the public contact form and must not require core.manage: ' . $result['text']);
		$this->assertTrue($result['data']['success']);
		$this->assertFalse($result['data']['emailSent']);
	}

	public function testLowPrivilegeUserCannotSubmitFormForAContactAboveTheirViewingAccess(): void
	{
		$this->withCustomReplyEnabled();

		// Access level 3 is the standard Joomla "Special" viewing access level, granted only to
		// Manager/Administrator/Super Users on a default install — never to the "Registered"-only
		// low-privilege test user.
		$fixture = $this->fixturePublishedContact(['access' => 3]);

		$result = $this->callToolAsLowPriv('contact_form_submit', [
			'contactId'      => $fixture['contact']['id'],
			'contactName'    => 'Jane Doe',
			'contactEmail'   => 'jane@example.com',
			'contactSubject' => 'Hello',
			'contactMessage' => 'A message',
		]);

		$this->assertNotFound($result, 'A contact above the caller\'s viewing access must be indistinguishable from a nonexistent one: ' . $result['text']);
	}
}
