#!/usr/bin/env php
<?php

// Usage:
//   php examples/dataextensions/update-extension.php --id 42 [--description "New description"] --confirm
//   php examples/run.php dataextensions:update --id 42 --confirm

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use de\xqueue\maileon\api\client\dataextensions\DataExtension;
use de\xqueue\maileon\api\client\dataextensions\DataExtensionField;
use de\xqueue\maileon\api\client\dataextensions\DataExtensionsService;
use de\xqueue\maileon\api\client\dataextensions\FieldDataType;

$id = (int)(cli_option('id') ?? 0);
if ($id === 0) {
    fwrite(STDERR, "Usage: php examples/dataextensions/update-extension.php --id <extension-id> [--description \"...\"] --confirm\n");
    exit(1);
}

require_confirm("This example updates data extension {$id} in your Maileon account.");

$service = new DataExtensionsService(maileon_config());

// Fetch current state — the PUT endpoint requires name and retention_policy
// even when they are not changing.
$currentResp = $service->getDataExtension($id);
if (!$currentResp->isSuccess()) {
    fwrite(STDERR, "Extension {$id} not found (HTTP {$currentResp->getStatusCode()}).\n");
    exit(1);
}
$current = $currentResp->getResult();

$extension                    = new DataExtension();
$extension->name              = $current->name;
$extension->retention_policy  = $current->retention_policy;
$extension->delete_interval   = $current->delete_interval;
$extension->delete_interval_unit = $current->delete_interval_unit;
$extension->delete_date       = $current->delete_date;
$extension->description       = cli_option('description', $current->description ?? '');

// Optionally add a new field — comment out if not needed.
$newField            = new DataExtensionField();
$newField->name      = 'updated_at';
$newField->data_type = FieldDataType::TIMESTAMP;
$newField->nullable  = true;

$extension->fields = [$newField];

$response = $service->updateDataExtension($id, $extension);

if (!$response->isSuccess()) {
    fwrite(STDERR, "Failed to update extension {$id} (HTTP {$response->getStatusCode()}).\n");
    exit(1);
}

output_result(true, ['updated_id' => $id], "Data extension updated");
