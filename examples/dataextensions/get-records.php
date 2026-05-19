#!/usr/bin/env php
<?php

// Usage:
//   php examples/dataextensions/get-records.php --id 42 [--page-size 20] [--fields name,email]
//   php examples/run.php dataextensions:records --id 42

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use de\xqueue\maileon\api\client\dataextensions\DataExtensionsService;

$id = (int)(cli_option('id') ?? 0);
if ($id === 0) {
    fwrite(STDERR, "Usage: php examples/dataextensions/get-records.php --id <extension-id> [--page-size 20] [--fields field1,field2]\n");
    exit(1);
}

$pageSize  = (int)(cli_option('page-size', '20'));
$fieldsArg = cli_option('fields');
$fields    = $fieldsArg ? array_map('trim', explode(',', $fieldsArg)) : [];

$service  = new DataExtensionsService(maileon_config());

// Show extension metadata first.
$metaResp = $service->getDataExtension($id);
if (!$metaResp->isSuccess()) {
    fwrite(STDERR, "Extension {$id} not found (HTTP {$metaResp->getStatusCode()}).\n");
    exit(1);
}
$ext = $metaResp->getResult();
echo "# Data Extension: {$ext->name} (id={$id})\n";
echo "# Fields: " . implode(', ', array_column((array)($ext->fields ?? []), 'name')) . "\n\n";

// Fetch records.
$response = $service->getDataExtensionRecords($id, 1, $pageSize, true, $fields);
if (!$response->isSuccess()) {
    fwrite(STDERR, "Failed to fetch records (HTTP {$response->getStatusCode()}).\n");
    exit(1);
}

$records = $response->getResult();
if (empty($records)) {
    echo "# No records found.\n";
    exit(0);
}

$output = [];
foreach ($records as $record) {
    $output[] = $record->values ?? [];
}

output_result(true, $output, "Records (first {$pageSize})");
