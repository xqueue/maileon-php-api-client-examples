#!/usr/bin/env php
<?php

// Usage:
//   php examples/dataextensions/create-extension.php --name "My Extension" [--description "Desc"] --confirm
//   php examples/run.php dataextensions:create --name "My Extension" --confirm

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use de\xqueue\maileon\api\client\dataextensions\DataExtension;
use de\xqueue\maileon\api\client\dataextensions\DataExtensionField;
use de\xqueue\maileon\api\client\dataextensions\DataExtensionsService;
use de\xqueue\maileon\api\client\dataextensions\FieldDataType;
use de\xqueue\maileon\api\client\dataextensions\RetentionPolicy;

require_confirm('This example creates a new data extension in your Maileon account.');

$name        = cli_option('name') ?? ('php_api_example_' . date('YmdHis'));
$description = cli_option('description', 'Created by maileon-php-api-client example');

$extension              = new DataExtension();
$extension->name        = $name;
$extension->description = $description;
$extension->retention_policy = RetentionPolicy::NONE;

$emailField                   = new DataExtensionField();
$emailField->name             = 'email';
$emailField->data_type        = FieldDataType::STRING;
$emailField->nullable         = false;
$emailField->unique_identifier = true;

$valueField            = new DataExtensionField();
$valueField->name      = 'value';
$valueField->data_type = FieldDataType::STRING;
$valueField->nullable  = true;

$extension->fields = [$emailField, $valueField];

$service  = new DataExtensionsService(maileon_config());
$response = $service->createDataExtension($extension);

if (!$response->isSuccess()) {
    fwrite(STDERR, "Failed to create extension (HTTP {$response->getStatusCode()}).\n");
    exit(1);
}

$newId = $response->getResult();
output_result(true, ['created_id' => $newId, 'name' => $name], "Data extension created");
