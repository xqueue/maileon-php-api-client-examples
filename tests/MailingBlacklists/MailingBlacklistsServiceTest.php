<?php

namespace Maileon\Test\MailingBlacklists;

use de\xqueue\maileon\api\client\blacklists\mailings\MailingBlacklistExpression;
use de\xqueue\maileon\api\client\blacklists\mailings\MailingBlacklistExpressions;
use de\xqueue\maileon\api\client\blacklists\mailings\MailingBlacklistsService;
use Maileon\Test\IntegrationTestCase;

class MailingBlacklistsServiceTest extends IntegrationTestCase
{
    private static MailingBlacklistsService $service;
    private static int $createdId = 0;

    private const BL_NAME = 'php-api-test-mailing-bl';

    public static function setUpBeforeClass(): void
    {
        self::requireIntegrationEnv();
        self::$service = new MailingBlacklistsService(self::config());
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$createdId > 0) {
            try {
                self::$service->deleteMailingBlacklist(self::$createdId);
            } catch (\Throwable $t) {
            }
        }
    }

    public function testGetMailingBlacklists(): void
    {
        $response = self::$service->getMailingBlacklists(1, 10);
        $this->assertTrue($response->isSuccess());
    }

    public function testCreateMailingBlacklist(): void
    {
        $response = self::$service->createMailingBlacklist(self::BL_NAME . '-' . time());
        $this->assertTrue($response->isSuccess());
        self::$createdId = (int) $response->getResult();
        $this->assertGreaterThan(0, self::$createdId);
    }

    /**
     * @depends testCreateMailingBlacklist
     */
    public function testGetMailingBlacklist(): void
    {
        $this->assertGreaterThan(0, self::$createdId);
        $response = self::$service->getMailingBlacklist(self::$createdId);
        $this->assertTrue($response->isSuccess());
    }

    /**
     * @depends testGetMailingBlacklist
     */
    public function testUpdateMailingBlacklist(): void
    {
        $response = self::$service->updateMailingBlacklist(self::$createdId, self::BL_NAME . '-updated');
        $this->assertTrue($response->isSuccess());
    }

    /**
     * @depends testUpdateMailingBlacklist
     */
    public function testAddEntriesToMailingBlacklist(): void
    {
        $expr             = new MailingBlacklistExpression();
        $expr->expression = '@test-block.com';

        $expressions = new MailingBlacklistExpressions();
        $expressions->addExpression($expr);

        $response = self::$service->addEntriesToBlacklist(self::$createdId, $expressions);
        $this->assertTrue($response->isSuccess());
    }

    /**
     * @depends testAddEntriesToMailingBlacklist
     */
    public function testGetEntriesForMailingBlacklist(): void
    {
        $response = self::$service->getEntriesForBlacklist(self::$createdId, 1, 10);
        $this->assertTrue($response->isSuccess());
    }

    /**
     * @depends testGetEntriesForMailingBlacklist
     */
    public function testDeleteMailingBlacklist(): void
    {
        $response = self::$service->deleteMailingBlacklist(self::$createdId);
        $this->assertTrue($response->isSuccess());
        self::$createdId = 0;
    }
}
