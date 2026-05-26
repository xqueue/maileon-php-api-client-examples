#!/usr/bin/env php
<?php

// Usage:
//   php examples/dataextensions/delete-extension.php --id 42 --confirm
//   php examples/run.php dataextensions:delete --id 42 --confirm

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use de\xqueue\maileon\api\client\dataextensions\DataExtensionsService;

$id = (int)(cli_option('id') ?? 0);
if ($id === 0) {
    fwrite(STDERR, "Usage: php examples/dataextensions/delete-extension.php --id <extension-id> --confirm\n");
    exit(1);
}

require_confirm("This example PERMANENTLY DELETES data extension {$id} from your Maileon account.");

$service  = new DataExtensionsService(maileon_config());
$response = $service->deleteDataExtension($id);

if (!$response->isSuccess()) {
    fwrite(STDERR, "Failed to delete extension {$id} (HTTP {$response->getStatusCode()}).\n");
    exit(1);
}

output_result(true, ['deleted_id' => $id], "Data extension deleted");
