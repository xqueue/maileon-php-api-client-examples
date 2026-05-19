#!/usr/bin/env php
<?php

// Usage:
//   php examples/mailings/create-mailing-draft.php --name "My Draft" --subject "Hello World"
//   php examples/mailings/create-mailing-draft.php --name "My Draft" --subject "Hello" --dry-run
//   php examples/run.php mailings:create --name "My Draft" --subject "Hello"

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use de\xqueue\maileon\api\client\mailings\MailingsService;

$name    = cli_option('name');
$subject = cli_option('subject');

if ($name === null || $subject === null) {
    fwrite(STDERR, "Usage: php examples/mailings/create-mailing-draft.php --name <name> --subject <subject> [--dry-run]\n");
    exit(1);
}

if (cli_flag('dry-run')) {
    output_result(true, ['name' => $name, 'subject' => $subject], 'Mailing draft (not created)');
    exit(0);
}

$service  = new MailingsService(maileon_config());
$response = $service->createMailing($name, $subject);

if (!$response->isSuccess()) {
    fwrite(STDERR, "API call failed (HTTP {$response->getStatusCode()}).\n");
    exit(1);
}

$id = (int) $response->getResult();

output_result(true, [
    'id'      => $id,
    'name'    => $name,
    'subject' => $subject,
    'status'  => $response->getStatusCode(),
], 'Mailing draft created');
