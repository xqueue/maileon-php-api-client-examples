#!/usr/bin/env php
<?php

// Usage: php examples/contacts/get-contact.php --email foo@example.com
//        php examples/run.php contacts:get --email foo@example.com

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use de\xqueue\maileon\api\client\contacts\ContactsService;
use de\xqueue\maileon\api\client\contacts\StandardContactField;

$email = cli_option('email');
if ($email === null) {
    fwrite(STDERR, "Usage: php examples/contacts/get-contact.php --email foo@example.com\n");
    exit(1);
}

$service  = new ContactsService(maileon_config());
$stdFields = [
    StandardContactField::$FIRSTNAME,
    StandardContactField::$LASTNAME,
    StandardContactField::$CITY,
    StandardContactField::$COUNTRY,
];
$response = $service->getContactByEmail($email, $stdFields);

if (!$response->isSuccess()) {
    fwrite(STDERR, "API call failed (HTTP {$response->getStatusCode()}).\n");
    exit(1);
}

$contact = $response->getResult();

output_result(true, [
    'id'              => $contact->id,
    'email'           => $contact->email,
    'external_id'     => $contact->external_id,
    'permission'      => $contact->permission?->getType(),
    'standard_fields' => $contact->standard_fields,
    'custom_fields'   => $contact->custom_fields,
], 'Contact');
