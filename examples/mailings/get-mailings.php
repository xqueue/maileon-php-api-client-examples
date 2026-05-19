#!/usr/bin/env php
<?php

// Usage:
//   php examples/mailings/get-mailings.php [--type regular|trigger|doi] [--state draft] [--limit 10]
//   php examples/run.php mailings:list [--type regular] [--limit 10]

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use de\xqueue\maileon\api\client\mailings\MailingsService;

$type  = cli_option('type', 'regular');
$state = cli_option('state');
$limit = (int)(cli_option('limit', '10'));

$service  = new MailingsService(maileon_config());
$response = $state
    ? $service->getMailingsByStates(1, $limit, [$state])
    : $service->getMailingsByTypes(1, $limit, [$type]);

if (!$response->isSuccess()) {
    fwrite(STDERR, "API call failed (HTTP {$response->getStatusCode()}).\n");
    exit(1);
}

$mailings = $response->getResult();

if (empty($mailings)) {
    echo "# No mailings found.\n";
    exit(0);
}

$output = [];
foreach ($mailings as $m) {
    $output[] = [
        'id'      => $m->id ?? null,
        'name'    => $m->name ?? null,
        'subject' => $m->subject ?? null,
        'type'    => $m->type ?? null,
        'state'   => $m->state ?? null,
    ];
}

output_result(true, $output, "Mailings (type={$type}, limit={$limit})");
