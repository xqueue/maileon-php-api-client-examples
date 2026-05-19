#!/usr/bin/env php
<?php

// Usage:
//   php examples/contacts/synchronize-contacts.php --data examples/data/contact-create.json --dry-run
//   php examples/contacts/synchronize-contacts.php --data contacts.json --confirm
//   php examples/run.php contacts:sync --data examples/data/contact-create.json --dry-run
//
// The JSON file may contain either a single contact object or an array of contact objects.

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use de\xqueue\maileon\api\client\contacts\Contact;
use de\xqueue\maileon\api\client\contacts\Contacts;
use de\xqueue\maileon\api\client\contacts\ContactsService;
use de\xqueue\maileon\api\client\contacts\Permission;
use de\xqueue\maileon\api\client\contacts\SynchronizationMode;

$dataFile = cli_option('data');
if ($dataFile === null) {
    fwrite(STDERR, "Usage: php examples/contacts/synchronize-contacts.php --data <json-file> [--dry-run]\n");
    exit(1);
}

$raw = read_json_file($dataFile);

// Accept single object or array.
$rows = isset($raw[0]) ? $raw : [$raw];

$contacts = new Contacts();
$summary  = [];

foreach ($rows as $row) {
    $c              = new Contact();
    $c->email       = $row['email']       ?? null;
    $c->external_id = $row['external_id'] ?? null;

    $permCode    = strtoupper($row['permission'] ?? 'NONE');
    $c->permission = match ($permCode) {
        'SOI'      => Permission::$SOI,
        'DOI'      => Permission::$DOI,
        'DOI_PLUS' => Permission::$DOI_PLUS,
        default    => Permission::$NONE,
    };

    foreach ($row['standard_fields'] ?? [] as $key => $value) {
        $c->standard_fields[$key] = $value;
    }
    foreach ($row['custom_fields'] ?? [] as $key => $value) {
        $c->custom_fields[$key] = $value;
    }

    $contacts->addContact($c);
    $summary[] = $c->email;
}

if (cli_flag('dry-run')) {
    echo "# Dry run — would synchronize " . count($summary) . " contact(s):\n";
    output_result(true, $summary, 'Contacts (not sent)');
    exit(0);
}

require_confirm('Synchronizing contacts will create or update contacts in your Maileon account.');

$service  = new ContactsService(maileon_config());
$response = $service->synchronizeContacts($contacts, null, SynchronizationMode::$UPDATE);

output_result($response->isSuccess(), [
    'status'  => $response->getStatusCode(),
    'count'   => count($summary),
    'emails'  => $summary,
    'success' => $response->isSuccess(),
], 'Synchronize contacts');
