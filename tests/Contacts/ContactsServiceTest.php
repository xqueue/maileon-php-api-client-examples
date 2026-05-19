<?php

namespace Maileon\Test\Contacts;

use de\xqueue\maileon\api\client\contacts\Contact;
use de\xqueue\maileon\api\client\contacts\Contacts;
use de\xqueue\maileon\api\client\contacts\ContactsService;
use de\xqueue\maileon\api\client\contacts\Permission;
use de\xqueue\maileon\api\client\contacts\Preference;
use de\xqueue\maileon\api\client\contacts\PreferenceCategory;
use de\xqueue\maileon\api\client\contacts\StandardContactField;
use de\xqueue\maileon\api\client\contacts\SynchronizationMode;
use Maileon\Test\IntegrationTestCase;

class ContactsServiceTest extends IntegrationTestCase
{
    private static ContactsService $service;

    private static string $email;
    private static string $email2;
    private static string $externalId;
    private static string $externalId2;

    private static int $contactId         = 0;
    private static int $countBeforeCreate = 0;

    private const CUSTOM_FIELD_NAME  = 'PhpApiTestField';
    private const CUSTOM_FIELD_NAME2 = 'PhpApiTestFieldRenamed';
    private const CUSTOM_FIELD_TYPE  = 'string';
    private const CUSTOM_FIELD_VALUE = 'TestValue';
    private const PREF_CATEGORY_NAME = 'php-api-test-category';
    private const PREF_NAME          = 'php-api-test-pref';

    public static function setUpBeforeClass(): void
    {
        self::requireIntegrationEnv();

        self::$service   = new ContactsService(self::config());
        $td              = self::testdata();
        self::$email     = $td['email'];
        self::$email2    = $td['email2'];
        self::$externalId  = $td['external_id'];
        self::$externalId2 = $td['external_id2'];

        self::$service->setDebug(false);

        // Clean up any leftover contacts from a previous run.
        foreach ([self::$email, self::$email2] as $e) {
            try {
                self::$service->deleteContactByEmail($e);
            } catch (\Throwable $t) {
            }
        }
        foreach ([self::$externalId, self::$externalId2] as $eid) {
            try {
                self::$service->deleteContactsByExternalId($eid);
            } catch (\Throwable $t) {
            }
        }
        foreach ([self::CUSTOM_FIELD_NAME, self::CUSTOM_FIELD_NAME2] as $field) {
            try {
                self::$service->deleteCustomField($field);
            } catch (\Throwable $t) {
            }
        }
        // Clean up any leftover preference category from a previous run.
        try {
            self::$service->deleteContactPreferenceCategory(self::PREF_CATEGORY_NAME);
        } catch (\Throwable $t) {
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (!isset(self::$service)) {
            return;
        }
        self::$service->setDebug(false);
        foreach ([self::$email, self::$email2] as $e) {
            try {
                self::$service->deleteContactByEmail($e);
            } catch (\Throwable $t) {
            }
        }
        foreach ([self::CUSTOM_FIELD_NAME, self::CUSTOM_FIELD_NAME2] as $field) {
            try {
                self::$service->deleteCustomField($field);
            } catch (\Throwable $t) {
            }
        }
        try {
            self::$service->deleteContactPreferenceCategory(self::PREF_CATEGORY_NAME);
        } catch (\Throwable $t) {
        }
    }

    // ── Count ────────────────────────────────────────────────────────────────

    public function testGetContactsCount(): void
    {
        $response = self::$service->getContactsCount();
        $this->assertTrue($response->isSuccess());
        self::$countBeforeCreate = (int) $response->getResult();
    }

    // ── Create ───────────────────────────────────────────────────────────────

    /**
     * @depends testGetContactsCount
     */
    public function testCreateContact(): void
    {
        $contact              = new Contact();
        $contact->email       = self::$email;
        $contact->external_id = self::$externalId;
        $contact->permission  = Permission::$DOI_PLUS;
        $contact->standard_fields[StandardContactField::$FIRSTNAME] = 'TestFirst';
        $contact->standard_fields[StandardContactField::$LASTNAME]  = 'TestLast';

        $response = self::$service->createContact($contact, SynchronizationMode::$UPDATE);
        $this->assertTrue($response->isSuccess());
    }

    /**
     * @depends testCreateContact
     */
    public function testCountIncreasedAfterCreate(): void
    {
        $response = self::$service->getContactsCount();
        $this->assertTrue($response->isSuccess());
        $this->assertGreaterThan(self::$countBeforeCreate, (int) $response->getResult());
    }

    /**
     * @depends testCreateContact
     */
    public function testCreateContactByExternalId(): void
    {
        $contact              = new Contact();
        $contact->email       = self::$email2;
        $contact->external_id = self::$externalId2;
        $contact->permission  = Permission::$SOI;
        $contact->standard_fields[StandardContactField::$FIRSTNAME] = 'TestFirst2';
        $contact->standard_fields[StandardContactField::$LASTNAME]  = 'TestLast2';

        $response = self::$service->createContactByExternalId($contact, SynchronizationMode::$UPDATE);
        $this->assertTrue($response->isSuccess());
    }

    // ── Read ─────────────────────────────────────────────────────────────────

    /**
     * @depends testCreateContact
     */
    public function testGetContactByEmail(): void
    {
        $stdFields = [StandardContactField::$FIRSTNAME, StandardContactField::$LASTNAME];
        $response  = self::$service->getContactByEmail(self::$email, $stdFields);
        $this->assertTrue($response->isSuccess());

        $contact = $response->getResult();
        $this->assertInstanceOf(Contact::class, $contact);
        $this->assertEquals('TestFirst', $contact->standard_fields[StandardContactField::$FIRSTNAME]);
        $this->assertGreaterThan(0, $contact->id);

        self::$contactId = $contact->id;
    }

    /**
     * @depends testGetContactByEmail
     */
    public function testGetContactById(): void
    {
        $this->assertGreaterThan(0, self::$contactId);

        $stdFields = [StandardContactField::$FIRSTNAME, StandardContactField::$LASTNAME];
        $response  = self::$service->getContact(self::$contactId, null, $stdFields);
        $this->assertTrue($response->isSuccess());

        $contact = $response->getResult();
        $this->assertInstanceOf(Contact::class, $contact);
        $this->assertEquals(self::$contactId, $contact->id);
    }

    /**
     * @depends testCreateContact
     */
    public function testGetContacts(): void
    {
        $response = self::$service->getContacts(1, 100);
        $this->assertTrue($response->isSuccess());
    }

    /**
     * @depends testCreateContact
     */
    public function testGetContactsWithUpdateAfter(): void
    {
        // Pass a timestamp in the past (0 = epoch) so our new contact is included.
        $response = self::$service->getContacts(1, 100, [], [], 0);
        $this->assertTrue($response->isSuccess());
    }

    /**
     * @depends testCreateContact
     */
    public function testGetContactsCountWithUpdateAfter(): void
    {
        $response = self::$service->getContactsCount(0);
        $this->assertTrue($response->isSuccess());
        $this->assertGreaterThan(0, (int) $response->getResult());
    }

    /**
     * @depends testCreateContactByExternalId
     */
    public function testGetContactsByExternalId(): void
    {
        $response = self::$service->getContactsByExternalId(self::$externalId2);
        $this->assertTrue($response->isSuccess());

        $contacts = $response->getResult();
        $found    = false;
        foreach ($contacts as $c) {
            if ($c->external_id === self::$externalId2) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found);
    }

    // ── Update ───────────────────────────────────────────────────────────────

    /**
     * @depends testGetContactByEmail
     */
    public function testUpdateContactByEmail(): void
    {
        $contact              = new Contact();
        $contact->email       = self::$email;
        $contact->external_id = self::$externalId;
        $contact->permission  = Permission::$DOI_PLUS;
        $contact->standard_fields[StandardContactField::$FIRSTNAME] = 'UpdatedFirst';

        $response = self::$service->updateContact($contact, null, null, null, false, false, true);
        $this->assertTrue($response->isSuccess());
    }

    /**
     * @depends testUpdateContactByEmail
     */
    public function testContactWasUpdated(): void
    {
        $stdFields = [StandardContactField::$FIRSTNAME];
        $response  = self::$service->getContactByEmail(self::$email, $stdFields);
        $this->assertTrue($response->isSuccess());

        $contact = $response->getResult();
        $this->assertEquals('UpdatedFirst', $contact->standard_fields[StandardContactField::$FIRSTNAME]);
    }

    /**
     * @depends testGetContactByEmail
     */
    public function testUpdateContactByExternalId(): void
    {
        $contact              = new Contact();
        $contact->external_id = self::$externalId;
        $contact->permission  = Permission::$DOI_PLUS;
        $contact->standard_fields[StandardContactField::$CITY] = 'Offenbach';

        $response = self::$service->updateContactByExternalId($contact);
        $this->assertTrue($response->isSuccess());
    }

    // ── Synchronize ──────────────────────────────────────────────────────────

    /**
     * @depends testContactWasUpdated
     */
    public function testSynchronizeContacts(): void
    {
        $contacts = new Contacts();
        $contacts->addContact(new Contact(
            null,
            self::$email,
            null,
            self::$externalId,
            null,
            [StandardContactField::$FIRSTNAME => 'SyncFirst', StandardContactField::$LASTNAME => 'SyncLast'],
            []
        ));
        $contacts->addContact(new Contact(
            null,
            self::$email2,
            null,
            self::$externalId2,
            null,
            [StandardContactField::$FIRSTNAME => 'SyncFirst2', StandardContactField::$LASTNAME => 'SyncLast2'],
            []
        ));

        $response = self::$service->synchronizeContacts($contacts, null, SynchronizationMode::$UPDATE);
        $this->assertTrue($response->isSuccess());
    }

    /**
     * @depends testSynchronizeContacts
     */
    public function testContactsWereSynchronized(): void
    {
        $stdFields = [StandardContactField::$FIRSTNAME, StandardContactField::$LASTNAME];
        $response  = self::$service->getContactByEmail(self::$email, $stdFields);
        $this->assertTrue($response->isSuccess());

        $contact = $response->getResult();
        $this->assertEquals('SyncFirst', $contact->standard_fields[StandardContactField::$FIRSTNAME]);
    }

    // ── Unsubscribe ──────────────────────────────────────────────────────────

    /**
     * @depends testSynchronizeContacts
     */
    public function testUnsubscribeContactByEmail(): void
    {
        $response = self::$service->unsubscribeContactByEmail(self::$email2);
        $this->assertTrue($response->isSuccess());
    }

    /**
     * @depends testGetContactByEmail
     */
    public function testUnsubscribeContactById(): void
    {
        $this->assertGreaterThan(0, self::$contactId);
        // Re-create so the contact is active again.
        $contact             = new Contact();
        $contact->email      = self::$email;
        $contact->permission = Permission::$DOI_PLUS;
        self::$service->createContact($contact, SynchronizationMode::$UPDATE);

        $response = self::$service->unsubscribeContactById(self::$contactId);
        $this->assertTrue($response->isSuccess());
    }

    // ── Delete ───────────────────────────────────────────────────────────────

    /**
     * @depends testUnsubscribeContactByEmail
     */
    public function testDeleteContactByEmail(): void
    {
        $response = self::$service->deleteContactByEmail(self::$email2);
        $this->assertTrue($response->isSuccess());

        self::$service->setDebug(false);
        $check = self::$service->getContactByEmail(self::$email2, [], [], []);
        $this->assertEquals(404, $check->getStatusCode());
    }

    /**
     * @depends testUnsubscribeContactById
     */
    public function testDeleteContactById(): void
    {
        $this->assertGreaterThan(0, self::$contactId);
        $response = self::$service->deleteContact(self::$contactId);
        $this->assertTrue($response->isSuccess());

        self::$service->setDebug(false);
        $check = self::$service->getContact(self::$contactId);
        $this->assertEquals(404, $check->getStatusCode());
    }

    /**
     * @depends testDeleteContactById
     */
    public function testDeleteContactsByExternalId(): void
    {
        // Re-create a contact with the external ID so we can delete by it.
        $contact              = new Contact();
        $contact->email       = self::$email;
        $contact->external_id = self::$externalId;
        $contact->permission  = Permission::$SOI;
        self::$service->createContact($contact, SynchronizationMode::$UPDATE);

        $response = self::$service->deleteContactsByExternalId(self::$externalId);
        $this->assertTrue($response->isSuccess());
    }

    // ── Blocked contacts ─────────────────────────────────────────────────────

    public function testGetBlockedContacts(): void
    {
        $response = self::$service->getBlockedContacts();
        $this->assertTrue($response->isSuccess());
    }

    // ── Standard field values ─────────────────────────────────────────────────

    public function testDeleteStandardFieldValues(): void
    {
        // Ensure the contact exists with a CITY value first.
        $contact              = new Contact();
        $contact->email       = self::$email;
        $contact->permission  = Permission::$DOI_PLUS;
        $contact->standard_fields[StandardContactField::$CITY] = 'Offenbach';
        self::$service->createContact($contact, SynchronizationMode::$UPDATE);

        $response = self::$service->deleteStandardFieldValues(StandardContactField::$CITY);
        $this->assertTrue($response->isSuccess());
    }

    // ── Custom field management ───────────────────────────────────────────────

    public function testCreateCustomField(): void
    {
        $response = self::$service->createCustomField(self::CUSTOM_FIELD_NAME, self::CUSTOM_FIELD_TYPE);
        $this->assertTrue($response->isSuccess());
    }

    /**
     * @depends testCreateCustomField
     */
    public function testGetCustomFields(): void
    {
        $response     = self::$service->getCustomFields();
        $this->assertTrue($response->isSuccess());
        $customFields = $response->getResult()->custom_fields ?? [];
        $this->assertArrayHasKey(self::CUSTOM_FIELD_NAME, $customFields);
        $this->assertEquals(self::CUSTOM_FIELD_TYPE, $customFields[self::CUSTOM_FIELD_NAME]);
    }

    /**
     * @depends testCreateCustomField
     */
    public function testWriteCustomField(): void
    {
        $contact              = new Contact();
        $contact->email       = self::$email;
        $contact->permission  = Permission::$DOI_PLUS;
        $contact->custom_fields[self::CUSTOM_FIELD_NAME] = self::CUSTOM_FIELD_VALUE;
        $response = self::$service->createContact($contact, SynchronizationMode::$UPDATE);
        $this->assertTrue($response->isSuccess());
    }

    /**
     * @depends testGetCustomFields
     * @depends testWriteCustomField
     */
    public function testRenameCustomField(): void
    {
        $response = self::$service->renameCustomField(self::CUSTOM_FIELD_NAME, self::CUSTOM_FIELD_NAME2);
        $this->assertTrue($response->isSuccess());
    }

    /**
     * @depends testRenameCustomField
     */
    public function testRenamedFieldHasRightValue(): void
    {
        $response = self::$service->getContactByEmail(self::$email, [], [self::CUSTOM_FIELD_NAME2]);
        $this->assertTrue($response->isSuccess());
        $this->assertEquals(
            self::CUSTOM_FIELD_VALUE,
            $response->getResult()->custom_fields[self::CUSTOM_FIELD_NAME2]
        );
    }

    /**
     * @depends testRenamedFieldHasRightValue
     */
    public function testDeleteCustomFieldValues(): void
    {
        self::$service->deleteCustomFieldValues(self::CUSTOM_FIELD_NAME2);
        $response = self::$service->getContactByEmail(self::$email, [], [self::CUSTOM_FIELD_NAME2]);
        $this->assertArrayNotHasKey(
            self::CUSTOM_FIELD_NAME2,
            $response->getResult()->custom_fields
        );
    }

    /**
     * @depends testDeleteCustomFieldValues
     */
    public function testDeleteCustomField(): void
    {
        self::$service->deleteCustomField(self::CUSTOM_FIELD_NAME2);
        $response     = self::$service->getCustomFields();
        $customFields = $response->getResult()->custom_fields ?? [];
        $this->assertArrayNotHasKey(self::CUSTOM_FIELD_NAME2, $customFields);
    }

    // ── Preference categories (all operations use NAME-based lookup) ──────────

    public function testGetPreferenceCategories(): void
    {
        $response = self::$service->getContactPreferenceCategories();
        $this->assertTrue($response->isSuccess());
    }

    public function testCreatePreferenceCategory(): void
    {
        $category       = new PreferenceCategory();
        $category->name = self::PREF_CATEGORY_NAME;

        $response = self::$service->createContactPreferenceCategory($category);
        $this->assertTrue($response->isSuccess());
    }

    /**
     * @depends testCreatePreferenceCategory
     */
    public function testGetPreferenceCategory(): void
    {
        $response = self::$service->getContactPreferenceCategoryByName(self::PREF_CATEGORY_NAME);
        $this->assertTrue($response->isSuccess());
    }

    /**
     * @depends testGetPreferenceCategory
     */
    public function testUpdatePreferenceCategory(): void
    {
        $category       = new PreferenceCategory();
        $category->name = self::PREF_CATEGORY_NAME;

        $response = self::$service->updateContactPreferenceCategory(self::PREF_CATEGORY_NAME, $category);
        $this->assertTrue($response->isSuccess());
    }

    /**
     * @depends testUpdatePreferenceCategory
     */
    public function testGetPreferencesOfCategory(): void
    {
        $response = self::$service->getPreferencesOfContactPreferencesCategory(self::PREF_CATEGORY_NAME);
        $this->assertTrue($response->isSuccess());
    }

    /**
     * @depends testGetPreferencesOfCategory
     */
    public function testCreatePreference(): void
    {
        $pref = new Preference(self::PREF_NAME, null, 'Email', 'true');

        $response = self::$service->createContactPreference(self::PREF_CATEGORY_NAME, $pref);
        $this->assertTrue($response->isSuccess());
    }

    /**
     * @depends testCreatePreference
     */
    public function testGetPreference(): void
    {
        $response = self::$service->getContactPreference(self::PREF_CATEGORY_NAME, self::PREF_NAME);
        $this->assertTrue($response->isSuccess());
    }

    /**
     * @depends testGetPreference
     */
    public function testUpdatePreference(): void
    {
        $pref     = new Preference(self::PREF_NAME, null, 'Email', 'false');
        $response = self::$service->updateContactPreference(self::PREF_CATEGORY_NAME, self::PREF_NAME, $pref);
        $this->assertTrue($response->isSuccess());
    }

    /**
     * @depends testUpdatePreference
     */
    public function testDeletePreference(): void
    {
        $response = self::$service->deleteContactPreference(self::PREF_CATEGORY_NAME, self::PREF_NAME);
        $this->assertTrue($response->isSuccess());
    }

    /**
     * @depends testDeletePreference
     */
    public function testDeletePreferenceCategory(): void
    {
        $response = self::$service->deleteContactPreferenceCategory(self::PREF_CATEGORY_NAME);
        $this->assertTrue($response->isSuccess());
    }
}
