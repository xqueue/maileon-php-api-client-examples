<?php

namespace Maileon\Test\Media;

use de\xqueue\maileon\api\client\media\MediaService;
use Maileon\Test\IntegrationTestCase;

class MediaServiceTest extends IntegrationTestCase
{
    private static MediaService $service;

    public static function setUpBeforeClass(): void
    {
        self::requireIntegrationEnv();
        self::$service = new MediaService(self::config());
    }

    public function testGetMailingTemplates(): void
    {
        $response = self::$service->getMailingTemplates();
        $this->assertTrue($response->isSuccess());
    }

    public function testGetCms2MailingTemplates(): void
    {
        $response = self::$service->getCms2MailingTemplates();
        $this->assertTrue($response->isSuccess());
    }
}
