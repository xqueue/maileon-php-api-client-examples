<?php

namespace Maileon\Test\Blacklists;

use de\xqueue\maileon\api\client\blacklists\BlacklistsService;
use Maileon\Test\IntegrationTestCase;

class BlacklistsServiceTest extends IntegrationTestCase
{
    private static BlacklistsService $service;
    private static int $blacklistId = 0;

    public static function setUpBeforeClass(): void
    {
        self::requireIntegrationEnv();
        self::$service    = new BlacklistsService(self::config());
        self::$blacklistId = self::testdata()['blacklist_id'];
    }

    public function testGetBlacklists(): void
    {
        $response = self::$service->getBlacklists();
        $this->assertTrue($response->isSuccess());
    }

    public function testGetBlacklist(): void
    {
        if (self::$blacklistId === 0) {
            $this->markTestSkipped('MAILEON_TEST_BLACKLIST_ID not set.');
        }
        $response = self::$service->getBlacklist(self::$blacklistId);
        $this->assertTrue($response->isSuccess());
    }

    public function testAddEntriesToBlacklist(): void
    {
        if (self::$blacklistId === 0) {
            $this->markTestSkipped('MAILEON_TEST_BLACKLIST_ID not set.');
        }
        $response = self::$service->addEntriesToBlacklist(
            self::$blacklistId,
            ['php-api-test-entry@example.com'],
            'php-api-test-import-' . time()
        );
        $this->assertTrue($response->isSuccess());
    }
}
