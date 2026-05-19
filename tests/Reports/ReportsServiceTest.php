<?php

namespace Maileon\Test\Reports;

use de\xqueue\maileon\api\client\reports\ReportsService;
use Maileon\Test\IntegrationTestCase;

class ReportsServiceTest extends IntegrationTestCase
{
    private static ReportsService $service;
    private static int $mailingId = 0;

    public static function setUpBeforeClass(): void
    {
        self::requireIntegrationEnv();
        self::$service = new ReportsService(self::config());
        self::$mailingId = self::testdata()['mailing_id'];
    }

    private function mailingIdOrSkip(): int
    {
        if (self::$mailingId === 0) {
            $this->markTestSkipped('MAILEON_TEST_MAILING_ID not set — skipping mailing-scoped report test.');
        }
        return self::$mailingId;
    }

    // ── Unsubscribers ────────────────────────────────────────────────────────

    public function testGetUnsubscribers(): void
    {
        $id       = $this->mailingIdOrSkip();
        $response = self::$service->getUnsubscribers(null, null, [$id]);
        $this->assertTrue($response->isSuccess());
    }

    public function testGetUnsubscriberReasons(): void
    {
        // getUnsubscriberReasons returns account-wide reasons; no mailing ID filter available.
        $response = self::$service->getUnsubscriberReasons();
        $this->assertTrue($response->isSuccess());
    }

    // ── Subscribers ──────────────────────────────────────────────────────────

    public function testGetSubscribers(): void
    {
        $id       = $this->mailingIdOrSkip();
        $response = self::$service->getSubscribers(null, null, [$id]);
        $this->assertTrue($response->isSuccess());
    }

    // ── Recipients ───────────────────────────────────────────────────────────

    public function testGetRecipients(): void
    {
        $id       = $this->mailingIdOrSkip();
        $response = self::$service->getRecipients(null, null, [$id]);
        $this->assertTrue($response->isSuccess());
    }

    // ── Opens ────────────────────────────────────────────────────────────────

    public function testGetOpens(): void
    {
        $id       = $this->mailingIdOrSkip();
        $response = self::$service->getOpens(null, null, [$id]);
        $this->assertTrue($response->isSuccess());
    }

    public function testGetUniqueOpens(): void
    {
        $id       = $this->mailingIdOrSkip();
        $response = self::$service->getUniqueOpens(null, null, [$id]);
        $this->assertTrue($response->isSuccess());
    }

    // ── Clicks ───────────────────────────────────────────────────────────────

    public function testGetClicks(): void
    {
        $id       = $this->mailingIdOrSkip();
        $response = self::$service->getClicks(null, null, [$id]);
        $this->assertTrue($response->isSuccess());
    }

    public function testGetUniqueClicks(): void
    {
        $id       = $this->mailingIdOrSkip();
        $response = self::$service->getUniqueClicks(null, null, [$id]);
        $this->assertTrue($response->isSuccess());
    }

    // ── Bounces ──────────────────────────────────────────────────────────────

    public function testGetBounces(): void
    {
        $id       = $this->mailingIdOrSkip();
        $response = self::$service->getBounces(null, null, [$id]);
        $this->assertTrue($response->isSuccess());
    }

    public function testGetUniqueBounces(): void
    {
        $id       = $this->mailingIdOrSkip();
        $response = self::$service->getUniqueBounces(null, null, [$id]);
        $this->assertTrue($response->isSuccess());
    }

    // ── Blocks ───────────────────────────────────────────────────────────────

    public function testGetBlocks(): void
    {
        $id       = $this->mailingIdOrSkip();
        $response = self::$service->getBlocks(null, null, [$id]);
        $this->assertTrue($response->isSuccess());
    }

    // ── Conversions ──────────────────────────────────────────────────────────

    public function testGetConversions(): void
    {
        $id       = $this->mailingIdOrSkip();
        $response = self::$service->getConversions(null, null, [$id]);
        $this->assertTrue($response->isSuccess());
    }

    public function testGetUniqueConversions(): void
    {
        $id       = $this->mailingIdOrSkip();
        $response = self::$service->getUniqueConversions(null, null, [$id]);
        $this->assertTrue($response->isSuccess());
    }
}
