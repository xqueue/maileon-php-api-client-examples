#!/usr/bin/env php
<?php

// Usage:
//   php examples/contacts/delete-contact.php --email foo@example.com --confirm
//   php examples/run.php contacts:delete --email foo@example.com --confirm

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use de\xqueue\maileon\api\client\contacts\ContactsService;

$email = cli_option('email');
if ($email === null) {
    fwrite(STDERR, "Usage: php examples/contacts/delete-contact.php --email <email> --confirm\n");
    exit(1);
}

require_confirm("Deleting contact {$email} is irreversible.");

$service  = new ContactsService(maileon_config());
$response = $service->deleteContactByEmail($email);

output_result($response->isSuccess(), [
    'status'  => $response->getStatusCode(),
    'email'   => $email,
    'deleted' => $response->isSuccess(),
], 'Delete contact');
