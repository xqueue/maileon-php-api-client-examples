<?php

namespace Maileon\Test\Webhooks;

use de\xqueue\maileon\api\client\webhooks\Webhook;
use de\xqueue\maileon\api\client\webhooks\WebhookBodySpecification;
use de\xqueue\maileon\api\client\webhooks\WebhooksService;
use de\xqueue\maileon\api\client\webhooks\WebhookUrlParameter;
use Maileon\Test\IntegrationTestCase;

class WebhooksServiceTest extends IntegrationTestCase
{
    private static WebhooksService $service;
    private static int $createdId = 0;

    public static function setUpBeforeClass(): void
    {
        self::requireIntegrationEnv();
        self::$service = new WebhooksService(self::config());
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$createdId > 0) {
            try {
                self::$service->deleteWebhook(self::$createdId);
            } catch (\Throwable $t) {
            }
        }
    }

    public function testGetWebhooks(): void
    {
        $response = self::$service->getWebhooks();
        $this->assertTrue($response->isSuccess());
    }

    public function testGetWebhookFromConfig(): void
    {
        $id = self::testdata()['webhook_id'];
        if ($id === 0) {
            $this->markTestSkipped('MAILEON_TEST_WEBHOOK_ID not set.');
        }
        $response = self::$service->getWebhook($id);
        $this->assertTrue($response->isSuccess());
    }

    public function testCreateWebhook(): void
    {
        $wh            = new Webhook();
        $wh->url       = 'https://webhook.site/php-api-test-' . time();
        $wh->name      = 'php-api-test-webhook';
        $wh->event     = 'open';
        $wh->active    = false;

        $response = self::$service->createWebhook($wh);
        $this->assertTrue($response->isSuccess());
        self::$createdId = (int) $response->getResult();
        $this->assertGreaterThan(0, self::$createdId);
    }

    /**
     * @depends testCreateWebhook
     */
    public function testGetCreatedWebhook(): void
    {
        $this->assertGreaterThan(0, self::$createdId);
        $response = self::$service->getWebhook(self::$createdId);
        $this->assertTrue($response->isSuccess());
    }

    /**
     * @depends testGetCreatedWebhook
     */
    public function testUpdateWebhook(): void
    {
        $wh         = new Webhook();
        $wh->url    = 'https://webhook.site/php-api-test-updated-' . time();
        $wh->name   = 'php-api-test-webhook-updated';
        $wh->event  = 'open';
        $wh->active = false;

        $response = self::$service->updateWebhook(self::$createdId, $wh);
        $this->assertTrue($response->isSuccess());
    }

    /**
     * @depends testUpdateWebhook
     */
    public function testDeleteWebhook(): void
    {
        $response = self::$service->deleteWebhook(self::$createdId);
        $this->assertTrue($response->isSuccess());
        self::$createdId = 0;
    }
}
