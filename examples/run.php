#!/usr/bin/env php
<?php

declare(strict_types=1);

// Dispatcher: routes <command> to the appropriate example script.
//
// Usage:
//   php examples/run.php list
//   php examples/run.php contacts:get  --email foo@example.com
//   php examples/run.php contacts:create --data examples/data/contact-create.json --dry-run
//   php examples/run.php contacts:delete --email foo@example.com --confirm
//   php examples/run.php contacts:sync  --data examples/data/contact-create.json --dry-run
//   php examples/run.php mailings:list
//   php examples/run.php mailings:create --name "My Draft" --subject "Hello"
//   php examples/run.php reports:kpis   --mailing-id 123456
//   php examples/run.php transactions:create-type --name my_order
//   php examples/run.php transactions:send   --data examples/data/transaction-order.json --confirm
//   php examples/run.php dataextensions:list
//   php examples/run.php dataextensions:records --id 42 --page-size 10

$commands = [
    'contacts:get'             => __DIR__ . '/contacts/get-contact.php',
    'contacts:create'          => __DIR__ . '/contacts/create-contact.php',
    'contacts:delete'          => __DIR__ . '/contacts/delete-contact.php',
    'contacts:sync'            => __DIR__ . '/contacts/synchronize-contacts.php',
    'mailings:list'            => __DIR__ . '/mailings/get-mailings.php',
    'mailings:create'          => __DIR__ . '/mailings/create-mailing-draft.php',
    'reports:kpis'             => __DIR__ . '/reports/get-mailing-kpis.php',
    'transactions:create-type' => __DIR__ . '/transactions/create-transaction-type.php',
    'transactions:send'        => __DIR__ . '/transactions/send-transaction.php',
    'dataextensions:list'      => __DIR__ . '/dataextensions/list-extensions.php',
    'dataextensions:records'   => __DIR__ . '/dataextensions/get-records.php',
];

$safety = [
    'contacts:get'             => 'read-only',
    'contacts:create'          => 'write',
    'contacts:delete'          => 'destructive',
    'contacts:sync'            => 'write',
    'mailings:list'            => 'read-only',
    'mailings:create'          => 'write',
    'reports:kpis'             => 'read-only',
    'transactions:create-type' => 'write',
    'transactions:send'        => 'send',
    'dataextensions:list'      => 'read-only',
    'dataextensions:records'   => 'read-only',
];

$command = $argv[1] ?? 'list';

if ($command === 'list' || $command === '--help' || $command === '-h') {
    echo "Maileon PHP API Client — CLI Examples\n";
    echo "======================================\n\n";
    echo sprintf("%-35s %-12s\n", 'Command', 'Safety');
    echo str_repeat('-', 50) . "\n";
    foreach ($commands as $cmd => $_) {
        printf("  %-33s %-12s\n", $cmd, $safety[$cmd] ?? '?');
    }
    echo "\nSafety levels:\n";
    echo "  read-only   — no data modification\n";
    echo "  write       — creates or updates data\n";
    echo "  send        — sends emails or transactions (use --confirm)\n";
    echo "  destructive — deletes data (use --confirm)\n";
    echo "\nSetup:\n";
    echo "  cp .env.example .env && edit .env\n";
    echo "  composer install\n";
    exit(0);
}

if (!isset($commands[$command])) {
    fwrite(STDERR, "Unknown command: {$command}\n");
    fwrite(STDERR, "Run: php examples/run.php list\n");
    exit(1);
}

$script = $commands[$command];
if (!file_exists($script)) {
    fwrite(STDERR, "Script not found: {$script}\n");
    exit(1);
}

require $script;
