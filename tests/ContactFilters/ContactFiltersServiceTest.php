<?php

namespace Maileon\Test\ContactFilters;

use de\xqueue\maileon\api\client\contactfilters\ContactFilter;
use de\xqueue\maileon\api\client\contactfilters\ContactfiltersService;
use de\xqueue\maileon\api\client\contactfilters\Rule;
use Maileon\Test\IntegrationTestCase;

class ContactFiltersServiceTest extends IntegrationTestCase
{
    private static ContactfiltersService $service;

    private static int $createdFilterId = 0;

    public static function setUpBeforeClass(): void
    {
        self::requireIntegrationEnv();
        self::$service = new ContactfiltersService(self::config());
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$createdFilterId > 0) {
            try {
                self::$service->deleteContactFilter(self::$createdFilterId);
            } catch (\Throwable $t) {
            }
        }
    }

    public function testGetContactFiltersCount(): void
    {
        $response = self::$service->getContactFiltersCount();
        $this->assertTrue($response->isSuccess());
        $this->assertIsInt((int) $response->getResult());
    }

    public function testGetContactFilters(): void
    {
        $response = self::$service->getContactFilters(1, 10);
        $this->assertTrue($response->isSuccess());
    }

    public function testGetContactFilterByIdFromConfig(): void
    {
        $id = self::testdata()['contact_filter_id'];
        if ($id === 0) {
            $this->markTestSkipped('MAILEON_TEST_CF_ID not set.');
        }
        $response = self::$service->getContactFilter($id);
        $this->assertTrue($response->isSuccess());
    }

    public function testCreateContactFilter(): void
    {
        $filter       = new ContactFilter();
        $filter->name = 'php-api-test-filter-' . time();

        $response = self::$service->createContactFilter($filter, false, 1.0);
        $this->assertTrue($response->isSuccess());
        self::$createdFilterId = (int) $response->getResult();
        $this->assertGreaterThan(0, self::$createdFilterId);
    }

    /**
     * @depends testCreateContactFilter
     */
    public function testGetCreatedContactFilter(): void
    {
        $this->assertGreaterThan(0, self::$createdFilterId);
        $response = self::$service->getContactFilter(self::$createdFilterId);
        $this->assertTrue($response->isSuccess());
    }

    /**
     * @depends testGetCreatedContactFilter
     */
    public function testUpdateContactFilterName(): void
    {
        $filter       = new ContactFilter();
        $filter->name = 'php-api-test-filter-renamed-' . time();

        $response = self::$service->updateContactFilter(self::$createdFilterId, $filter);
        $this->assertTrue($response->isSuccess());
    }

    /**
     * @depends testUpdateContactFilterName
     */
    public function testRefreshContactFilterContacts(): void
    {
        $response = self::$service->refreshContactFilterContacts(self::$createdFilterId, null);
        $this->assertTrue($response->isSuccess());
    }

    /**
     * @depends testRefreshContactFilterContacts
     */
    public function testDeleteContactFilter(): void
    {
        $response = self::$service->deleteContactFilter(self::$createdFilterId);
        $this->assertTrue($response->isSuccess());
        self::$createdFilterId = 0;
    }
}
