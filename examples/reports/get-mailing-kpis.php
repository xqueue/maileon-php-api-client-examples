#!/usr/bin/env php
<?php

// Usage:
//   php examples/reports/get-mailing-kpis.php --mailing-id 123456
//   php examples/run.php reports:kpis --mailing-id 123456

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use de\xqueue\maileon\api\client\reports\ReportsService;

$mailingId = (int)(cli_option('mailing-id') ?? 0);
if ($mailingId === 0) {
    fwrite(STDERR, "Usage: php examples/reports/get-mailing-kpis.php --mailing-id <id>\n");
    exit(1);
}

$service = new ReportsService(maileon_config());

// Fetch all KPI metrics in parallel-style sequential calls.
$kpis = [];

$calls = [
    'recipients'        => fn() => $service->getRecipientsCount(null, null, [$mailingId]),
    'opens'             => fn() => $service->getOpensCount(null, null, [$mailingId]),
    'unique_opens'      => fn() => $service->getUniqueOpensCount(null, null, [$mailingId]),
    'clicks'            => fn() => $service->getClicksCount(null, null, [$mailingId]),
    'unique_clicks'     => fn() => $service->getUniqueClicksCount(null, null, [$mailingId]),
    'bounces'           => fn() => $service->getBouncesCount(null, null, [$mailingId]),
    'unique_bounces'    => fn() => $service->getUniqueBouncesCount(null, null, [$mailingId]),
    'unsubscribers'     => fn() => $service->getUnsubscribersCount(null, null, [$mailingId]),
    'blocks'            => fn() => $service->getBlocksCount(null, null, [$mailingId]),
    'conversions'       => fn() => $service->getConversionsCount(null, null, [$mailingId]),
    'unique_conversions' => fn() => $service->getUniqueConversionsCount(null, null, [$mailingId]),
];

foreach ($calls as $key => $call) {
    $response   = $call();
    $kpis[$key] = $response->isSuccess() ? $response->getResult() : null;
}

// Compute derived rates.
$recipients = (int)($kpis['recipients'] ?? 0);
if ($recipients > 0) {
    $kpis['open_rate']        = round(((int)($kpis['unique_opens'] ?? 0)) / $recipients * 100, 2);
    $kpis['click_rate']       = round(((int)($kpis['unique_clicks'] ?? 0)) / $recipients * 100, 2);
    $kpis['bounce_rate']      = round(((int)($kpis['unique_bounces'] ?? 0)) / $recipients * 100, 2);
    $kpis['unsubscribe_rate'] = round(((int)($kpis['unsubscribers'] ?? 0)) / $recipients * 100, 2);
}

output_result(true, array_merge(['mailing_id' => $mailingId], $kpis), "Mailing KPIs");
