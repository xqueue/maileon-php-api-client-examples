#!/usr/bin/env php
<?php

// Usage:
//   php examples/contacts/create-contact.php --data examples/data/contact-create.json
//   php examples/contacts/create-contact.php --data examples/data/contact-create.json --dry-run
//   php examples/run.php contacts:create --data examples/data/contact-create.json

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use de\xqueue\maileon\api\client\contacts\Contact;
use de\xqueue\maileon\api\client\contacts\ContactsService;
use de\xqueue\maileon\api\client\contacts\Permission;
use de\xqueue\maileon\api\client\contacts\SynchronizationMode;

$dataFile = cli_option('data');
if ($dataFile === null) {
    fwrite(STDERR, "Usage: php examples/contacts/create-contact.php --data <json-file> [--dry-run]\n");
    exit(1);
}

$data = read_json_file($dataFile);

$contact              = new Contact();
$contact->email       = $data['email']       ?? null;
$contact->external_id = $data['external_id'] ?? null;

$permCode = strtoupper($data['permission'] ?? 'NONE');
$contact->permission = match ($permCode) {
    'SOI'      => Permission::$SOI,
    'DOI'      => Permission::$DOI,
    'DOI_PLUS' => Permission::$DOI_PLUS,
    'NONE'     => Permission::$NONE,
    default    => Permission::$NONE,
};

foreach ($data['standard_fields'] ?? [] as $key => $value) {
    $contact->standard_fields[$key] = $value;
}
foreach ($data['custom_fields'] ?? [] as $key => $value) {
    $contact->custom_fields[$key] = $value;
}

if (cli_flag('dry-run')) {
    echo "# Dry run — would send:\n";
    output_result(true, [
        'email'           => $contact->email,
        'external_id'     => $contact->external_id,
        'permission'      => $permCode,
        'standard_fields' => $contact->standard_fields,
        'custom_fields'   => $contact->custom_fields,
    ], 'Contact (not sent)');
    exit(0);
}

$service  = new ContactsService(maileon_config());
$response = $service->createContact($contact, SynchronizationMode::$UPDATE);

output_result($response->isSuccess(), [
    'status'  => $response->getStatusCode(),
    'email'   => $contact->email,
    'success' => $response->isSuccess(),
], 'Create contact');
