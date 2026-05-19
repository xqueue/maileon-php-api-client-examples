<?php

namespace Maileon\Test;

use de\xqueue\maileon\api\client\utils\PingService;

class PingServiceTest extends IntegrationTestCase
{
    private static PingService $service;

    public static function setUpBeforeClass(): void
    {
        self::requireIntegrationEnv();
        self::$service = new PingService(self::config());
    }

    public function testPingGet(): void
    {
        $this->assertTrue(self::$service->pingGet()->isSuccess());
    }

    public function testPingPut(): void
    {
        $this->assertTrue(self::$service->pingPut()->isSuccess());
    }

    public function testPingPost(): void
    {
        $this->assertTrue(self::$service->pingPost()->isSuccess());
    }

    public function testPingDelete(): void
    {
        $this->assertTrue(self::$service->pingDelete()->isSuccess());
    }
}
