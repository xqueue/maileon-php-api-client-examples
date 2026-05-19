#!/usr/bin/env php
<?php

// Usage:
//   php examples/dataextensions/list-extensions.php [--page 1] [--page-size 20]
//   php examples/run.php dataextensions:list

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use de\xqueue\maileon\api\client\dataextensions\DataExtensionsService;

$page     = (int)(cli_option('page', '1'));
$pageSize = (int)(cli_option('page-size', '20'));

$service  = new DataExtensionsService(maileon_config());
$response = $service->listDataExtensions($page, $pageSize);

if (!$response->isSuccess()) {
    fwrite(STDERR, "API call failed (HTTP {$response->getStatusCode()}).\n");
    exit(1);
}

$extensions = $response->getResult();

if (empty($extensions)) {
    echo "# No data extensions found.\n";
    exit(0);
}

$output = [];
foreach ($extensions as $ext) {
    $output[] = [
        'id'             => $ext->id,
        'name'           => $ext->name,
        'description'    => $ext->description ?? null,
        'count_fields'   => $ext->count_fields ?? null,
        'count_records'  => $ext->count_records ?? null,
        'created'        => $ext->created ?? null,
    ];
}

output_result(true, $output, "Data extensions (page={$page}, page_size={$pageSize})");
