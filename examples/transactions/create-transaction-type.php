#!/usr/bin/env php
<?php

// Usage:
//   php examples/transactions/create-transaction-type.php --name my_order_type
//   php examples/transactions/create-transaction-type.php --name my_order_type --dry-run
//   php examples/run.php transactions:create-type --name my_order_type

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use de\xqueue\maileon\api\client\transactions\AttributeType;
use de\xqueue\maileon\api\client\transactions\DataType;
use de\xqueue\maileon\api\client\transactions\TransactionsService;
use de\xqueue\maileon\api\client\transactions\TransactionType;

$name = cli_option('name');
if ($name === null) {
    fwrite(STDERR, "Usage: php examples/transactions/create-transaction-type.php --name <name> [--dry-run]\n");
    exit(1);
}

// Build a sensible default order-style transaction type.
$trt       = new TransactionType();
$trt->name = $name;

$attrs = [];
foreach ([
    ['order_id', DataType::$STRING],
    ['order_total', DataType::$DOUBLE],
    ['currency', DataType::$STRING],
    ['product_name', DataType::$STRING],
    ['quantity', DataType::$INTEGER],
] as [$attrName, $attrType]) {
    $a          = new AttributeType();
    $a->name    = $attrName;
    $a->type    = $attrType;
    $a->required = false;
    $attrs[]    = $a;
}
$trt->attributes = $attrs;

if (cli_flag('dry-run')) {
    output_result(true, [
        'name'       => $trt->name,
        'attributes' => array_map(fn($a) => ['name' => $a->name, 'type' => $a->type->name ?? (string)$a->type], $attrs),
    ], 'Transaction type (not created)');
    exit(0);
}

$service  = new TransactionsService(maileon_config());
$response = $service->createTransactionType($trt);

if (!$response->isSuccess()) {
    fwrite(STDERR, "API call failed (HTTP {$response->getStatusCode()}).\n");
    exit(1);
}

output_result(true, [
    'id'     => (int) $response->getResult(),
    'name'   => $name,
    'status' => $response->getStatusCode(),
], 'Transaction type created');
