<?php

namespace Maileon\Test\Account;

use de\xqueue\maileon\api\client\account\AccountPlaceholder;
use de\xqueue\maileon\api\client\account\AccountService;
use Maileon\Test\IntegrationTestCase;

class AccountServiceTest extends IntegrationTestCase
{
    private static AccountService $service;

    private const PLACEHOLDER_NAME  = 'php_api_test_ph';
    private const PLACEHOLDER_VALUE = 'test_value';

    public static function setUpBeforeClass(): void
    {
        self::requireIntegrationEnv();
        self::$service = new AccountService(self::config());

        // Remove any leftover test placeholder.
        try {
            self::$service->deleteAccountPlaceholder(self::PLACEHOLDER_NAME);
        } catch (\Throwable $t) {
        }
    }

    public static function tearDownAfterClass(): void
    {
        try {
            self::$service->deleteAccountPlaceholder(self::PLACEHOLDER_NAME);
        } catch (\Throwable $t) {
        }
    }

    public function testGetAccountInfo(): void
    {
        $response = self::$service->getAccountInfo();
        $this->assertTrue($response->isSuccess());
    }

    public function testGetAccountPlaceholders(): void
    {
        $response = self::$service->getAccountPlaceholders();
        $this->assertTrue($response->isSuccess());
    }

    public function testSetAccountPlaceholders(): void
    {
        $ph        = new AccountPlaceholder();
        $ph->name  = self::PLACEHOLDER_NAME;
        $ph->value = self::PLACEHOLDER_VALUE;

        $response = self::$service->setAccountPlaceholders([$ph]);
        $this->assertTrue($response->isSuccess());
    }

    /**
     * @depends testSetAccountPlaceholders
     */
    public function testUpdateAccountPlaceholders(): void
    {
        $ph        = new AccountPlaceholder();
        $ph->name  = self::PLACEHOLDER_NAME;
        $ph->value = self::PLACEHOLDER_VALUE . '_updated';

        $response = self::$service->updateAccountPlaceholders([$ph]);
        $this->assertTrue($response->isSuccess());
    }

    /**
     * @depends testUpdateAccountPlaceholders
     */
    public function testDeleteAccountPlaceholder(): void
    {
        $response = self::$service->deleteAccountPlaceholder(self::PLACEHOLDER_NAME);
        $this->assertTrue($response->isSuccess());
    }

    public function testGetAccountMailingDomains(): void
    {
        $response = self::$service->getAccountMailingDomains();
        $this->assertTrue($response->isSuccess());
    }
}
