<?php

namespace Maileon\Test\DataExtensions;

use de\xqueue\maileon\api\client\dataextensions\DataExtension;
use de\xqueue\maileon\api\client\dataextensions\DataExtensionRecord;
use de\xqueue\maileon\api\client\dataextensions\DataExtensionSummary;
use de\xqueue\maileon\api\client\dataextensions\DataExtensionsService;
use Maileon\Test\IntegrationTestCase;

class DataExtensionsServiceTest extends IntegrationTestCase
{
    private static DataExtensionsService $service;
    private static int $extensionId = 0;

    public static function setUpBeforeClass(): void
    {
        self::requireIntegrationEnv();
        self::$service   = new DataExtensionsService(self::config());
        self::$extensionId = self::testdata()['data_extension_id'];
    }

    private function extensionIdOrSkip(): int
    {
        if (self::$extensionId === 0) {
            $this->markTestSkipped('MAILEON_TEST_DE_ID not set — provide an existing data extension ID.');
        }
        return self::$extensionId;
    }

    // ── List ─────────────────────────────────────────────────────────────────

    public function testListDataExtensions(): void
    {
        $response = self::$service->listDataExtensions(1, 10);
        $this->assertTrue($response->isSuccess());

        $result = $response->getResult();
        $this->assertIsArray($result);

        if (!empty($result)) {
            $this->assertInstanceOf(DataExtensionSummary::class, $result[0]);
            $this->assertGreaterThan(0, $result[0]->id);
            $this->assertNotEmpty($result[0]->name);
        }
    }

    public function testListDataExtensionsPagination(): void
    {
        // Page 2 with page size 1 — must not throw even if empty.
        $response = self::$service->listDataExtensions(2, 1);
        $this->assertTrue($response->isSuccess());
        $this->assertIsArray($response->getResult());
    }

    // ── Get single extension ─────────────────────────────────────────────────

    public function testGetDataExtension(): void
    {
        $id = $this->extensionIdOrSkip();

        $response = self::$service->getDataExtension($id);
        $this->assertTrue($response->isSuccess());

        $ext = $response->getResult();
        $this->assertInstanceOf(DataExtension::class, $ext);
        $this->assertNotEmpty($ext->name);
        $this->assertIsArray($ext->fields);
    }

    /**
     * @depends testGetDataExtension
     */
    public function testGetDataExtensionHasFields(): void
    {
        $id  = $this->extensionIdOrSkip();
        $ext = self::$service->getDataExtension($id)->getResult();

        foreach ($ext->fields as $field) {
            $this->assertNotEmpty($field->name);
            $this->assertNotEmpty($field->type);
        }
    }

    // ── Records — upsert ─────────────────────────────────────────────────────

    /**
     * @depends testGetDataExtension
     */
    public function testSynchronizeRecordsUpsert(): void
    {
        $id = $this->extensionIdOrSkip();

        // Fetch field names from the extension so we build a valid payload.
        $ext    = self::$service->getDataExtension($id)->getResult();
        $fields = $ext->fields;

        if (empty($fields)) {
            $this->markTestSkipped('Data extension has no fields — cannot test record import.');
        }

        // Build minimal records using only the first field.
        $firstField = $fields[0]->name;
        $records    = [
            [$firstField => 'php-api-test-record-1-' . time()],
            [$firstField => 'php-api-test-record-2-' . time()],
        ];

        $response = self::$service->synchronizeRecords($id, $records, 'UPSERT');
        $this->assertTrue($response->isSuccess());
    }

    // ── Records — read ────────────────────────────────────────────────────────

    /**
     * @depends testSynchronizeRecordsUpsert
     */
    public function testGetDataExtensionRecords(): void
    {
        $id = $this->extensionIdOrSkip();

        $response = self::$service->getDataExtensionRecords($id, 1, 10);
        $this->assertTrue($response->isSuccess());

        $records = $response->getResult();
        $this->assertIsArray($records);

        if (!empty($records)) {
            $this->assertInstanceOf(DataExtensionRecord::class, $records[0]);
            $this->assertIsArray($records[0]->values);
        }
    }

    /**
     * @depends testGetDataExtensionRecords
     */
    public function testGetDataExtensionRecordsDescending(): void
    {
        $id = $this->extensionIdOrSkip();

        $response = self::$service->getDataExtensionRecords($id, 1, 5, false);
        $this->assertTrue($response->isSuccess());
        $this->assertIsArray($response->getResult());
    }

    /**
     * @depends testGetDataExtension
     */
    public function testGetDataExtensionRecordsFilteredFields(): void
    {
        $id  = $this->extensionIdOrSkip();
        $ext = self::$service->getDataExtension($id)->getResult();

        if (empty($ext->fields)) {
            $this->markTestSkipped('Data extension has no fields.');
        }

        $firstFieldName = $ext->fields[0]->name;

        $response = self::$service->getDataExtensionRecords($id, 1, 10, true, [$firstFieldName]);
        $this->assertTrue($response->isSuccess());

        $records = $response->getResult();
        $this->assertIsArray($records);
    }

    // ── Records — other import modes ──────────────────────────────────────────

    /**
     * @depends testGetDataExtension
     */
    public function testSynchronizeRecordsInsertIgnoreDuplicates(): void
    {
        $id     = $this->extensionIdOrSkip();
        $ext    = self::$service->getDataExtension($id)->getResult();
        $fields = $ext->fields;

        if (empty($fields)) {
            $this->markTestSkipped('Data extension has no fields.');
        }

        $firstField = $fields[0]->name;
        $records    = [[$firstField => 'php-api-dedup-test-' . time()]];

        $response = self::$service->synchronizeRecords($id, $records, 'INSERT_IGNORE_DUPLICATES');
        $this->assertTrue($response->isSuccess());
    }

    // ── Empty payload guard ───────────────────────────────────────────────────

    public function testSynchronizeEmptyRecordsReturnsNull(): void
    {
        $id  = $this->extensionIdOrSkip();
        $result = self::$service->synchronizeRecords($id, []);
        $this->assertNull($result, 'synchronizeRecords() must return null for empty input.');
    }
}
