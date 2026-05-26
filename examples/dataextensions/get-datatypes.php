#!/usr/bin/env php
<?php

// Usage:
//   php examples/dataextensions/get-datatypes.php
//   php examples/run.php dataextensions:datatypes

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use de\xqueue\maileon\api\client\dataextensions\DataExtensionsService;

$service  = new DataExtensionsService(maileon_config());
$response = $service->getDataTypes();

if (!$response->isSuccess()) {
    fwrite(STDERR, "API call failed (HTTP {$response->getStatusCode()}).\n");
    exit(1);
}

$types = $response->getResult();
output_result(true, $types, "Available data extension field types");
