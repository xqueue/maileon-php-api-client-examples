<?php

namespace Maileon\Test\Mailings;

use de\xqueue\maileon\api\client\mailings\CustomProperty;
use de\xqueue\maileon\api\client\mailings\MailingsService;
use Maileon\Test\IntegrationTestCase;

class MailingsServiceTest extends IntegrationTestCase
{
    private static MailingsService $service;

    private static int $mailingId = 0;

    private const MAILING_NAME    = 'php-api-test-mailing';
    private const MAILING_SUBJECT = 'PHP API integration test';
    private const MAILING_HTML    = '<html><body><strong>Integration test mailing</strong></body></html>';

    public static function setUpBeforeClass(): void
    {
        self::requireIntegrationEnv();
        self::$service = new MailingsService(self::config());
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$mailingId > 0) {
            try {
                self::$service->deleteMailing(self::$mailingId);
            } catch (\Throwable $t) {
            }
        }
    }

    // ── List ─────────────────────────────────────────────────────────────────

    public function testGetMailingsByTypes(): void
    {
        $response = self::$service->getMailingsByTypes(1, 10, ['regular']);
        $this->assertTrue($response->isSuccess());
    }

    public function testGetMailingsByStates(): void
    {
        $response = self::$service->getMailingsByStates(1, 10, ['draft']);
        $this->assertTrue($response->isSuccess());
    }

    // ── Create ───────────────────────────────────────────────────────────────

    public function testCreateRegularMailing(): void
    {
        $response = self::$service->createMailing(self::MAILING_NAME, self::MAILING_SUBJECT);
        $this->assertTrue($response->isSuccess());
        self::$mailingId = (int) $response->getResult();
        $this->assertGreaterThan(0, self::$mailingId);
    }

    /**
     * @depends testCreateRegularMailing
     */
    public function testGetMailingIdByName(): void
    {
        $response = self::$service->getMailingIdByName(self::MAILING_NAME);
        $this->assertTrue($response->isSuccess());
        $this->assertEquals(self::$mailingId, (int) $response->getResult());
    }

    /**
     * @depends testCreateRegularMailing
     */
    public function testCheckIfMailingExistsByName(): void
    {
        $exists = self::$service->checkIfMailingExistsByName(self::MAILING_NAME);
        $this->assertTrue($exists);
    }

    // ── HTML content ─────────────────────────────────────────────────────────

    /**
     * @depends testCreateRegularMailing
     */
    public function testSetHTMLContent(): void
    {
        $response = self::$service->setHTMLContent(self::$mailingId, self::MAILING_HTML);
        $this->assertTrue($response->isSuccess());
    }

    /**
     * @depends testSetHTMLContent
     */
    public function testGetHTMLContent(): void
    {
        $response = self::$service->getHTMLContent(self::$mailingId);
        $this->assertTrue($response->isSuccess());
        $this->assertEquals(self::MAILING_HTML, $response->getResult());
    }

    // ── Subject ──────────────────────────────────────────────────────────────

    /**
     * @depends testCreateRegularMailing
     */
    public function testGetSubject(): void
    {
        $response = self::$service->getSubject(self::$mailingId);
        $this->assertTrue($response->isSuccess());
    }

    // ── Sender ───────────────────────────────────────────────────────────────

    /**
     * @depends testCreateRegularMailing
     */
    public function testGetSender(): void
    {
        $response = self::$service->getSender(self::$mailingId);
        $this->assertTrue($response->isSuccess());
    }

    /**
     * @depends testCreateRegularMailing
     */
    public function testGetSenderAlias(): void
    {
        $response = self::$service->getSenderAlias(self::$mailingId);
        $this->assertTrue($response->isSuccess());
    }

    /**
     * @depends testGetSender
     */
    public function testSetSender(): void
    {
        $response = self::$service->setSender(self::$mailingId, 'noreply@example.com');
        $this->assertTrue($response->isSuccess());
    }

    // ── Reply-to ─────────────────────────────────────────────────────────────

    /**
     * @depends testCreateRegularMailing
     */
    public function testGetReplyToAddress(): void
    {
        $response = self::$service->getReplyToAddress(self::$mailingId);
        $this->assertTrue($response->isSuccess());
    }

    /**
     * @depends testGetReplyToAddress
     */
    public function testSetReplyToAddress(): void
    {
        $response = self::$service->setReplyToAddress(self::$mailingId, 'noreply@example.com');
        $this->assertTrue($response->isSuccess());
    }

    // ── Preview text ─────────────────────────────────────────────────────────

    /**
     * @depends testCreateRegularMailing
     */
    public function testSetPreviewText(): void
    {
        $response = self::$service->setPreviewText(self::$mailingId, 'Preview text for test mailing');
        $this->assertTrue($response->isSuccess());
    }

    /**
     * @depends testSetPreviewText
     */
    public function testGetPreviewText(): void
    {
        $response = self::$service->getPreviewText(self::$mailingId);
        $this->assertTrue($response->isSuccess());
        $this->assertEquals('Preview text for test mailing', $response->getResult());
    }

    // ── Tags ─────────────────────────────────────────────────────────────────

    /**
     * @depends testCreateRegularMailing
     */
    public function testSetTags(): void
    {
        $response = self::$service->setTags(self::$mailingId, ['integration-test', 'php-api']);
        $this->assertTrue($response->isSuccess());
    }

    /**
     * @depends testSetTags
     */
    public function testGetTags(): void
    {
        $response = self::$service->getTags(self::$mailingId);
        $this->assertTrue($response->isSuccess());
    }

    // ── Locale ───────────────────────────────────────────────────────────────

    /**
     * @depends testCreateRegularMailing
     */
    public function testSetLocale(): void
    {
        $response = self::$service->setLocale(self::$mailingId, 'de_DE');
        $this->assertTrue($response->isSuccess());
    }

    /**
     * @depends testSetLocale
     */
    public function testGetLocale(): void
    {
        $response = self::$service->getLocale(self::$mailingId);
        $this->assertTrue($response->isSuccess());
    }

    // ── Custom properties ────────────────────────────────────────────────────

    /**
     * @depends testCreateRegularMailing
     */
    public function testGetCustomProperties(): void
    {
        $response = self::$service->getCustomProperties(self::$mailingId);
        $this->assertTrue($response->isSuccess());
    }

    /**
     * @depends testGetCustomProperties
     */
    public function testAddCustomProperties(): void
    {
        $prop        = new CustomProperty();
        $prop->name  = 'php_api_test_prop';
        $prop->value = 'test_value';

        $response = self::$service->addCustomProperties(self::$mailingId, [$prop]);
        $this->assertTrue($response->isSuccess());
    }

    /**
     * @depends testAddCustomProperties
     */
    public function testUpdateCustomProperty(): void
    {
        $prop        = new CustomProperty();
        $prop->name  = 'php_api_test_prop';
        $prop->value = 'updated_value';

        $response = self::$service->updateCustomProperty(self::$mailingId, $prop);
        $this->assertTrue($response->isSuccess());
    }

    /**
     * @depends testUpdateCustomProperty
     */
    public function testDeleteCustomProperty(): void
    {
        $response = self::$service->deleteCustomProperty(self::$mailingId, 'php_api_test_prop');
        $this->assertTrue($response->isSuccess());
    }

    // ── QoS ──────────────────────────────────────────────────────────────────

    /**
     * @depends testCreateRegularMailing
     */
    public function testDisableQosChecks(): void
    {
        $response = self::$service->disableQosChecks(self::$mailingId);
        $this->assertTrue($response->isSuccess());
    }

    // ── Archive URL ───────────────────────────────────────────────────────────

    /**
     * @depends testCreateRegularMailing
     */
    public function testGetArchiveUrl(): void
    {
        $response = self::$service->getArchiveUrl(self::$mailingId);
        $this->assertTrue($response->isSuccess());
    }

    // ── Report URL ────────────────────────────────────────────────────────────

    /**
     * @depends testCreateRegularMailing
     */
    public function testGetReportUrl(): void
    {
        $response = self::$service->getReportUrl(self::$mailingId);
        $this->assertTrue($response->isSuccess());
    }

    // ── Domain ───────────────────────────────────────────────────────────────

    /**
     * @depends testCreateRegularMailing
     */
    public function testGetDomain(): void
    {
        $response = self::$service->getMailingDomain(self::$mailingId);
        $this->assertTrue($response->isSuccess());
    }

    // ── Copy ─────────────────────────────────────────────────────────────────

    /**
     * @depends testCreateRegularMailing
     */
    public function testCopyMailing(): void
    {
        $response = self::$service->copyMailing(self::$mailingId);
        $this->assertTrue($response->isSuccess());

        $copyId = (int) $response->getResult();
        $this->assertGreaterThan(0, $copyId);

        // Cleanup the copy immediately.
        self::$service->setDebug(false);
        self::$service->deleteMailing($copyId);
    }

    // ── Contact filter restrictions ───────────────────────────────────────────

    /**
     * @depends testCreateRegularMailing
     */
    public function testGetContactFilterRestrictionsCount(): void
    {
        $response = self::$service->getContactFilterRestrictionsCount(self::$mailingId);
        $this->assertTrue($response->isSuccess());
    }

    // ── State / type ─────────────────────────────────────────────────────────

    /**
     * @depends testCreateRegularMailing
     */
    public function testGetState(): void
    {
        $response = self::$service->getState(self::$mailingId);
        $this->assertTrue($response->isSuccess());
    }

    /**
     * @depends testCreateRegularMailing
     */
    public function testGetType(): void
    {
        $response = self::$service->getType(self::$mailingId);
        $this->assertTrue($response->isSuccess());
    }

    // ── Delete ───────────────────────────────────────────────────────────────

    /**
     * @depends testCreateRegularMailing
     */
    public function testDeleteMailing(): void
    {
        $this->assertGreaterThan(0, self::$mailingId);
        $response = self::$service->deleteMailing(self::$mailingId);
        $this->assertTrue($response->isSuccess());
        self::$mailingId = 0;
    }
}
