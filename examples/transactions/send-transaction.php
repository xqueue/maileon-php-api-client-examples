#!/usr/bin/env php
<?php

// Usage:
//   php examples/transactions/send-transaction.php --data examples/data/transaction-order.json --dry-run
//   php examples/transactions/send-transaction.php --data examples/data/transaction-order.json --confirm
//   php examples/run.php transactions:send --data examples/data/transaction-order.json --confirm
//
// Required JSON fields:
//   type_name       — name of the transaction type (used as ID)
//   contact_email   — recipient email address
//   attributes      — key/value map of transaction attributes

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use de\xqueue\maileon\api\client\transactions\ContactReference;
use de\xqueue\maileon\api\client\transactions\Transaction;
use de\xqueue\maileon\api\client\transactions\TransactionsService;

$dataFile = cli_option('data');
if ($dataFile === null) {
    fwrite(STDERR, "Usage: php examples/transactions/send-transaction.php --data <json-file> [--dry-run | --confirm]\n");
    exit(1);
}

$data = read_json_file($dataFile);

$typeName     = $data['type_name']     ?? null;
$contactEmail = $data['contact_email'] ?? null;
$attributes   = $data['attributes']    ?? [];

if ($typeName === null || $contactEmail === null) {
    fwrite(STDERR, "JSON must contain: type_name, contact_email, attributes\n");
    exit(1);
}

if (cli_flag('dry-run')) {
    output_result(true, [
        'type_name'     => $typeName,
        'contact_email' => $contactEmail,
        'attributes'    => $attributes,
    ], 'Transaction (not sent)');
    exit(0);
}

require_confirm("This will send a real transaction to {$contactEmail}.");

$service = new TransactionsService(maileon_config());

// Resolve type name → ID.
$typeResponse = $service->getTransactionTypeByName($typeName);
if (!$typeResponse->isSuccess()) {
    fwrite(STDERR, "Transaction type '{$typeName}' not found (HTTP {$typeResponse->getStatusCode()}).\n");
    fwrite(STDERR, "Create it first: php examples/run.php transactions:create-type --name {$typeName}\n");
    exit(1);
}
$typeId = (int)($typeResponse->getResult()->id ?? 0);
if ($typeId === 0) {
    fwrite(STDERR, "Could not resolve type ID for '{$typeName}'.\n");
    exit(1);
}

$contact        = new ContactReference();
$contact->email = $contactEmail;

$tx          = new Transaction();
$tx->contact = $contact;
$tx->typeid  = $typeId;
$tx->content = $attributes;

$response = $service->createTransactions([$tx], true, false);

output_result($response->isSuccess(), [
    'status'        => $response->getStatusCode(),
    'type_name'     => $typeName,
    'type_id'       => $typeId,
    'contact_email' => $contactEmail,
    'success'       => $response->isSuccess(),
], 'Transaction sent');
