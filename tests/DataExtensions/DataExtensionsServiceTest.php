<?php

namespace Maileon\Test\DataExtensions;

use de\xqueue\maileon\api\client\dataextensions\DataExtension;
use de\xqueue\maileon\api\client\dataextensions\DataExtensionField;
use de\xqueue\maileon\api\client\dataextensions\DataExtensionRecord;
use de\xqueue\maileon\api\client\dataextensions\DataExtensionSummary;
use de\xqueue\maileon\api\client\dataextensions\DataExtensionsService;
use de\xqueue\maileon\api\client\dataextensions\FieldDataType;
use de\xqueue\maileon\api\client\dataextensions\RetentionPolicy;
use Maileon\Test\IntegrationTestCase;

class DataExtensionsServiceTest extends IntegrationTestCase
{
    private static DataExtensionsService $service;

    /** ID of a pre-existing extension from config (optional) */
    private static int $extensionId = 0;

    /** ID of the extension created by this test run for full CRUD coverage */
    private static int $createdExtensionId = 0;

    /** Name of the extension created by this test run (needed for update validate()) */
    private static string $createdExtensionName = '';

    public static function setUpBeforeClass(): void
    {
        self::requireIntegrationEnv();
        self::$service    = new DataExtensionsService(self::config());
        self::$extensionId = self::testdata()['data_extension_id'];

        // Create a fresh extension for CRUD tests.
        $ext                   = new DataExtension();
        $ext->name             = 'php_api_test_' . date('YmdHis');
        $ext->description      = 'Temporary extension created by DataExtensionsServiceTest';
        $ext->retention_policy = RetentionPolicy::NONE;

        $keyField                    = new DataExtensionField();
        $keyField->name              = 'ref_id';
        $keyField->data_type         = FieldDataType::STRING;
        $keyField->nullable          = false;
        $keyField->unique_identifier = true;

        $valueField            = new DataExtensionField();
        $valueField->name      = 'label';
        $valueField->data_type = FieldDataType::STRING;
        $valueField->nullable  = true;

        $ext->fields = [$keyField, $valueField];

        $response = self::$service->createDataExtension($ext);
        if ($response && $response->isSuccess() && $response->getResult() > 0) {
            self::$createdExtensionId   = (int)$response->getResult();
            self::$createdExtensionName = $ext->name;
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$createdExtensionId > 0) {
            self::$service->deleteDataExtension(self::$createdExtensionId);
        }
    }

    private function extensionIdOrSkip(): int
    {
        if (self::$extensionId === 0) {
            $this->markTestSkipped('MAILEON_TEST_DE_ID not set — provide an existing data extension ID.');
        }
        return self::$extensionId;
    }

    private function createdExtensionIdOrSkip(): int
    {
        if (self::$createdExtensionId === 0) {
            $this->markTestSkipped('Failed to create a test data extension in setUpBeforeClass.');
        }
        return self::$createdExtensionId;
    }

    // ── Data types ───────────────────────────────────────────────────────────

    public function testGetDataTypes(): void
    {
        $response = self::$service->getDataTypes();
        $this->assertTrue($response->isSuccess());

        $types = $response->getResult();
        $this->assertIsArray($types);
        $this->assertNotEmpty($types);

        foreach ($types as $type) {
            $this->assertIsString($type);
            $this->assertNotEmpty($type);
        }
    }

    // ── Create extension ─────────────────────────────────────────────────────

    public function testCreateDataExtension(): void
    {
        $id = $this->createdExtensionIdOrSkip();
        $this->assertGreaterThan(0, $id);
    }

    // ── List ─────────────────────────────────────────────────────────────────

    public function testListDataExtensions(): void
    {
        $response = self::$service->listDataExtensions(1, 100);
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
            $this->assertNotEmpty($field->data_type);
        }
    }

    public function testGetCreatedExtensionHasCorrectFields(): void
    {
        $id = $this->createdExtensionIdOrSkip();

        $ext = self::$service->getDataExtension($id)->getResult();
        $this->assertInstanceOf(DataExtension::class, $ext);
        $this->assertCount(2, $ext->fields);

        $fieldNames = array_column($ext->fields, 'name');
        $this->assertContains('ref_id', $fieldNames);
        $this->assertContains('label', $fieldNames);
    }

    // ── Update extension ─────────────────────────────────────────────────────

    /**
     * @depends testCreateDataExtension
     */
    public function testUpdateDataExtension(): void
    {
        $id = $this->createdExtensionIdOrSkip();

        // name and retention_policy are required by the API on every PUT.
        $update                  = new DataExtension();
        $update->name            = self::$createdExtensionName;
        $update->retention_policy = RetentionPolicy::NONE;
        $update->description     = 'Updated by DataExtensionsServiceTest';

        // Add a new field during update.
        $newField            = new DataExtensionField();
        $newField->name      = 'score';
        $newField->data_type = FieldDataType::INTEGER;
        $newField->nullable  = true;

        $update->fields = [$newField];

        $response = self::$service->updateDataExtension($id, $update);
        $this->assertTrue($response->isSuccess());
    }

    /**
     * @depends testUpdateDataExtension
     */
    public function testUpdateAddedField(): void
    {
        $id  = $this->createdExtensionIdOrSkip();
        $ext = self::$service->getDataExtension($id)->getResult();

        $fieldNames = array_column($ext->fields, 'name');
        $this->assertContains('score', $fieldNames);
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
        $r1 = new DataExtensionRecord(); $r1->values = [$firstField => 'php-api-test-record-1-' . time()];
        $r2 = new DataExtensionRecord(); $r2->values = [$firstField => 'php-api-test-record-2-' . time()];

        $response = self::$service->synchronizeRecords($id, [$r1, $r2], 'UPSERT');
        $this->assertTrue($response->isSuccess());
    }

    /**
     * @depends testCreateDataExtension
     */
    public function testSynchronizeRecordsOnCreatedExtension(): void
    {
        $id = $this->createdExtensionIdOrSkip();

        $r1 = new DataExtensionRecord(); $r1->values = ['ref_id' => 'test-001', 'label' => 'First'];
        $r2 = new DataExtensionRecord(); $r2->values = ['ref_id' => 'test-002', 'label' => 'Second'];

        $response = self::$service->synchronizeRecords($id, [$r1, $r2], 'UPSERT');
        $this->assertTrue($response->isSuccess());
    }

    // ── Records — read ────────────────────────────────────────────────────────

    /**
     * @depends testSynchronizeRecordsUpsert
     */
    public function testGetDataExtensionRecords(): void
    {
        $id = $this->extensionIdOrSkip();

        $response = self::$service->getDataExtensionRecords($id, 1, 100);
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

        $response = self::$service->getDataExtensionRecords($id, 1, 100, false);
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

        $response = self::$service->getDataExtensionRecords($id, 1, 100, true, [$firstFieldName]);
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
        $r1 = new DataExtensionRecord(); $r1->values = [$firstField => 'php-api-dedup-test-' . time()];

        $response = self::$service->synchronizeRecords($id, [$r1], 'INSERT_IGNORE_DUPLICATES');
        $this->assertTrue($response->isSuccess());
    }

    // ── Delete all records ────────────────────────────────────────────────────

    /**
     * @depends testSynchronizeRecordsOnCreatedExtension
     */
    public function testDeleteAllRecords(): void
    {
        $id = $this->createdExtensionIdOrSkip();

        $response = self::$service->deleteAllRecords($id);
        $this->assertTrue($response->isSuccess());
    }

    /**
     * @depends testDeleteAllRecords
     */
    public function testGetRecordsAfterDeleteIsEmpty(): void
    {
        $id = $this->createdExtensionIdOrSkip();

        $response = self::$service->getDataExtensionRecords($id, 1, 100);
        $this->assertTrue($response->isSuccess());
        $this->assertEmpty($response->getResult());
    }

    // ── Empty payload guard ───────────────────────────────────────────────────

    public function testSynchronizeEmptyRecordsReturnsNull(): void
    {
        $id     = $this->extensionIdOrSkip();
        $result = self::$service->synchronizeRecords($id, []);
        $this->assertNull($result, 'synchronizeRecords() must return null for empty input.');
    }
}
