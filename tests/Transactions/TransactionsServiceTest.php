<?php

namespace Maileon\Test\Transactions;

use de\xqueue\maileon\api\client\transactions\AttributeType;
use de\xqueue\maileon\api\client\transactions\ContactReference;
use de\xqueue\maileon\api\client\transactions\DataType;
use de\xqueue\maileon\api\client\transactions\Transaction;
use de\xqueue\maileon\api\client\transactions\TransactionsService;
use de\xqueue\maileon\api\client\transactions\TransactionType;
use Maileon\Test\IntegrationTestCase;

class TransactionsServiceTest extends IntegrationTestCase
{
    private static TransactionsService $service;

    private static string $typeName  = '';
    private static string $typeName2 = '';
    private static int    $typeId    = 0;
    private static int    $typeId2   = 0;

    public static function setUpBeforeClass(): void
    {
        self::requireIntegrationEnv();
        self::$service = new TransactionsService(self::config());

        self::$typeName  = 'php-api-test-type-' . time();
        self::$typeName2 = 'php-api-test-type-complex-' . time();
    }

    public static function tearDownAfterClass(): void
    {
        self::$service->setDebug(false);
        foreach ([self::$typeId, self::$typeId2] as $id) {
            if ($id > 0) {
                try {
                    self::$service->deleteTransactionType($id);
                } catch (\Throwable $t) {
                }
            }
        }
    }

    // ── Type read ────────────────────────────────────────────────────────────

    public function testGetTransactionTypesCount(): void
    {
        $response = self::$service->getTransactionTypesCount();
        $this->assertTrue($response->isSuccess());
        $this->assertIsInt((int) $response->getResult());
    }

    public function testGetTransactionTypes(): void
    {
        $response = self::$service->getTransactionTypes(1, 10);
        $this->assertTrue($response->isSuccess());
    }

    public function testGetTransactionTypeByIdFromConfig(): void
    {
        $id = self::testdata()['transaction_type_id'];
        if ($id === 0) {
            $this->markTestSkipped('MAILEON_TEST_TX_TYPE_ID not set.');
        }
        $response = self::$service->getTransactionType($id);
        $this->assertTrue($response->isSuccess());
    }

    // ── Type create (simple) ─────────────────────────────────────────────────

    public function testCreateSimpleTransactionType(): void
    {
        $trt       = new TransactionType();
        $trt->name = self::$typeName;

        $attr1          = new AttributeType();
        $attr1->name    = 'order_id';
        $attr1->type    = DataType::$STRING;
        $attr1->required = false;

        $attr2          = new AttributeType();
        $attr2->name    = 'amount';
        $attr2->type    = DataType::$DOUBLE;
        $attr2->required = false;

        $trt->attributes = [$attr1, $attr2];

        $response = self::$service->createTransactionType($trt);
        $this->assertTrue($response->isSuccess());
        self::$typeId = (int) $response->getResult();
        $this->assertGreaterThan(0, self::$typeId);
    }

    // ── Type create (complex with transaction_id attr) ────────────────────────

    public function testCreateComplexTransactionType(): void
    {
        $trt       = new TransactionType();
        $trt->name = self::$typeName2;

        $txIdAttr          = new AttributeType();
        $txIdAttr->name    = 'transaction_id';
        $txIdAttr->type    = DataType::$STRING;
        $txIdAttr->required = false;

        $prodAttr          = new AttributeType();
        $prodAttr->name    = 'product_name';
        $prodAttr->type    = DataType::$STRING;
        $prodAttr->required = false;

        $trt->attributes = [$txIdAttr, $prodAttr];

        $response = self::$service->createTransactionType($trt);
        $this->assertTrue($response->isSuccess());
        self::$typeId2 = (int) $response->getResult();
        $this->assertGreaterThan(0, self::$typeId2);
    }

    // ── Get type by name ─────────────────────────────────────────────────────

    /**
     * @depends testCreateSimpleTransactionType
     */
    public function testGetTransactionTypeByName(): void
    {
        $response = self::$service->getTransactionTypeByName(self::$typeName);
        $this->assertTrue($response->isSuccess());
    }

    // ── Create transactions ───────────────────────────────────────────────────

    /**
     * @depends testCreateSimpleTransactionType
     */
    public function testCreateSimpleTransaction(): void
    {
        $contact        = new ContactReference();
        $contact->email = self::testdata()['email'];

        $tx         = new Transaction();
        $tx->contact = $contact;
        $tx->typeid  = self::$typeId;
        $tx->content = [
            'order_id' => 'ORD-001',
            'amount'   => 42.50,
        ];

        $response = self::$service->createTransactions([$tx], true, true);
        $this->assertTrue($response->isSuccess());
    }

    /**
     * @depends testCreateComplexTransactionType
     */
    public function testCreateComplexTransaction(): void
    {
        $contact        = new ContactReference();
        $contact->email = self::testdata()['email'];

        $tx          = new Transaction();
        $tx->contact = $contact;
        $tx->typeid  = self::$typeId2;
        $tx->content = [
            'transaction_id' => 'PHP-API-TX-001',
            'product_name'   => 'Test Product',
        ];

        $response = self::$service->createTransactions([$tx], true, true, false);
        $this->assertTrue($response->isSuccess());
    }

    /**
     * @depends testCreateSimpleTransactionType
     */
    public function testCreateMultipleTransactions(): void
    {
        $contact        = new ContactReference();
        $contact->email = self::testdata()['email'];

        $txs = [];
        for ($i = 0; $i < 5; $i++) {
            $tx          = new Transaction();
            $tx->contact = $contact;
            $tx->typeid  = self::$typeId;
            $tx->content = ['order_id' => "ORD-00{$i}", 'amount' => ($i + 1) * 10.0];
            $txs[]       = $tx;
        }

        $response = self::$service->createTransactions($txs, true, true);
        $this->assertTrue($response->isSuccess());
    }

    // ── Recent transactions ───────────────────────────────────────────────────

    /**
     * @depends testCreateSimpleTransaction
     */
    public function testGetRecentTransactions(): void
    {
        $response = self::$service->getRecentTransactions(self::$typeId, 10);
        $this->assertTrue($response->isSuccess());
    }

    // ── Get / Delete by transaction_id ────────────────────────────────────────

    /**
     * @depends testCreateComplexTransaction
     */
    public function testGetTransactionByTransactionId(): void
    {
        $response = self::$service->getTransaction(self::$typeId2, 'PHP-API-TX-001');
        // May return 404 if archival is not configured; just verify call works.
        $this->assertContains($response->getStatusCode(), [200, 404]);
    }

    /**
     * @depends testGetTransactionByTransactionId
     */
    public function testDeleteTransactionByTransactionId(): void
    {
        $response = self::$service->deleteTransaction(self::$typeId2, 'PHP-API-TX-001');
        $this->assertContains($response->getStatusCode(), [200, 404]);
    }

    // ── Get / Delete from config ──────────────────────────────────────────────

    public function testGetTransactionFromConfig(): void
    {
        $td = self::testdata();
        if ($td['transaction_type_id'] === 0 || $td['transaction_id'] === '') {
            $this->markTestSkipped('MAILEON_TEST_TX_TYPE_ID and MAILEON_TEST_TX_ID not set.');
        }
        $response = self::$service->getTransaction($td['transaction_type_id'], $td['transaction_id']);
        $this->assertContains($response->getStatusCode(), [200, 404]);
    }

    // ── Delete transactions by date ───────────────────────────────────────────

    /**
     * @depends testCreateSimpleTransaction
     */
    public function testDeleteTransactionsByDate(): void
    {
        // Delete transactions older than 1970 — effectively none, just verifies endpoint works.
        $response = self::$service->deleteTransactions(self::$typeId, 1000);
        $this->assertTrue($response->isSuccess());
    }

    // ── Delete types ─────────────────────────────────────────────────────────

    /**
     * @depends testGetRecentTransactions
     * @depends testDeleteTransactionsByDate
     */
    public function testDeleteSimpleTransactionType(): void
    {
        $response = self::$service->deleteTransactionType(self::$typeId);
        $this->assertTrue($response->isSuccess());
        self::$typeId = 0;
    }

    /**
     * @depends testDeleteTransactionByTransactionId
     */
    public function testDeleteComplexTransactionTypeByName(): void
    {
        $response = self::$service->deleteTransactionTypeByName(self::$typeName2);
        $this->assertTrue($response->isSuccess());
        self::$typeId2 = 0;
    }
}
