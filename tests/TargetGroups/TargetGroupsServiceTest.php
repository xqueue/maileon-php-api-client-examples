<?php

namespace Maileon\Test\TargetGroups;

use de\xqueue\maileon\api\client\targetgroups\TargetGroup;
use de\xqueue\maileon\api\client\targetgroups\TargetGroupsService;
use Maileon\Test\IntegrationTestCase;

class TargetGroupsServiceTest extends IntegrationTestCase
{
    private static TargetGroupsService $service;
    private static int $createdId = 0;

    public static function setUpBeforeClass(): void
    {
        self::requireIntegrationEnv();
        self::$service = new TargetGroupsService(self::config());
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$createdId > 0) {
            try {
                self::$service->deleteTargetGroup(self::$createdId);
            } catch (\Throwable $t) {
            }
        }
    }

    public function testGetTargetGroupsCount(): void
    {
        $response = self::$service->getTargetGroupsCount();
        $this->assertTrue($response->isSuccess());
        $this->assertIsInt((int) $response->getResult());
    }

    public function testGetTargetGroups(): void
    {
        $response = self::$service->getTargetGroups(1, 10);
        $this->assertTrue($response->isSuccess());
    }

    public function testCreateTargetGroup(): void
    {
        $tg       = new TargetGroup();
        $tg->name = 'php-api-test-tg-' . time();

        $response = self::$service->createTargetGroup($tg);
        $this->assertTrue($response->isSuccess());
        self::$createdId = (int) $response->getResult();
        $this->assertGreaterThan(0, self::$createdId);
    }

    /**
     * @depends testCreateTargetGroup
     */
    public function testGetTargetGroup(): void
    {
        $this->assertGreaterThan(0, self::$createdId);
        $response = self::$service->getTargetGroup(self::$createdId);
        $this->assertTrue($response->isSuccess());
    }

    /**
     * @depends testGetTargetGroup
     */
    public function testDeleteTargetGroup(): void
    {
        $response = self::$service->deleteTargetGroup(self::$createdId);
        $this->assertTrue($response->isSuccess());
        self::$createdId = 0;
    }
}
