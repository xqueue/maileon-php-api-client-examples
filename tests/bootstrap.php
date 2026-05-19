<?php

require __DIR__ . '/../vendor/autoload.php';

// Load optional conf/config.php for local convenience (gitignored).
// Environment variables always take precedence.
$confFile = __DIR__ . '/../conf/config.php';
if (file_exists($confFile)) {
    require $confFile;
}

$apiKey  = getenv('MAILEON_API_KEY')  ?: ($config['API_KEY']  ?? '');
$baseUri = getenv('MAILEON_BASE_URI') ?: ($config['BASE_URI'] ?? 'https://api.maileon.com/1.0');

$GLOBALS['config'] = [
    'API_KEY'         => $apiKey,
    'BASE_URI'        => $baseUri,
    'THROW_EXCEPTION' => true,
    'TIMEOUT'         => 30,
    'DEBUG'           => false,
];

$td = $testdata ?? [];

$GLOBALS['testdata'] = [
    'email'               => getenv('MAILEON_TEST_EMAIL')          ?: ($td['email']               ?? 'php-api-test@example.com'),
    'email2'              => getenv('MAILEON_TEST_EMAIL2')         ?: ($td['email2']              ?? 'php-api-test-2@example.com'),
    'external_id'         => getenv('MAILEON_TEST_EXTERNAL_ID')    ?: ($td['external_id']         ?? 'php-api-ext-001'),
    'external_id2'        => getenv('MAILEON_TEST_EXTERNAL_ID2')   ?: ($td['external_id2']        ?? 'php-api-ext-002'),
    'mailing_id'          => (int)(getenv('MAILEON_TEST_MAILING_ID')  ?: ($td['mailing_id']       ?? 0)),
    'contact_filter_id'   => (int)(getenv('MAILEON_TEST_CF_ID')       ?: ($td['contact_filter_id'] ?? 0)),
    'blacklist_id'        => (int)(getenv('MAILEON_TEST_BLACKLIST_ID') ?: ($td['blacklist_id']     ?? 0)),
    'doi_mailing_key'     => getenv('MAILEON_TEST_DOI_MAILING_KEY') ?: ($td['doi_mailing_key']    ?? ''),
    'data_extension_id'   => (int)(getenv('MAILEON_TEST_DE_ID')       ?: ($td['data_extension_id'] ?? 0)),
    'transaction_type_id' => (int)(getenv('MAILEON_TEST_TX_TYPE_ID')  ?: ($td['transaction_type_id'] ?? 0)),
    'transaction_id'      => getenv('MAILEON_TEST_TX_ID')          ?: ($td['transaction_id']      ?? ''),
    'webhook_id'          => (int)(getenv('MAILEON_TEST_WEBHOOK_ID')  ?: ($td['webhook_id']        ?? 0)),
];
