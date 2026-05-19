<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// ── Environment loading ───────────────────────────────────────────────────────

(function (): void {
    $envFile = dirname(__DIR__) . '/.env';
    if (!file_exists($envFile)) {
        return;
    }
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
        $key   = trim($key);
        $value = trim($value, " \t\"'");
        if ($key !== '' && getenv($key) === false) {
            putenv("$key=$value");
        }
    }
})();

// Also load conf/config.php if no API key in env.
if (!getenv('MAILEON_API_KEY')) {
    $confFile = dirname(__DIR__) . '/conf/config.php';
    if (file_exists($confFile)) {
        require $confFile;
        if (isset($config['API_KEY']) && $config['API_KEY'] !== '') {
            putenv('MAILEON_API_KEY=' . $config['API_KEY']);
        }
        if (isset($config['BASE_URI'])) {
            putenv('MAILEON_BASE_URI=' . $config['BASE_URI']);
        }
    }
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function env_required(string $name): string
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        fwrite(STDERR, "Missing required environment variable: {$name}\n");
        exit(1);
    }
    return $value;
}

function maileon_config(): array
{
    return [
        'API_KEY'         => env_required('MAILEON_API_KEY'),
        'BASE_URI'        => getenv('MAILEON_BASE_URI') ?: 'https://api.maileon.com/1.0',
        'THROW_EXCEPTION' => true,
        'TIMEOUT'         => 30,
        'DEBUG'           => (bool)(getenv('MAILEON_DEBUG') ?: false),
    ];
}

function cli_option(string $name, ?string $default = null): ?string
{
    global $argv;
    $needle = '--' . $name;
    foreach ($argv as $index => $arg) {
        if ($arg === $needle && isset($argv[$index + 1]) && $argv[$index + 1][0] !== '-') {
            return $argv[$index + 1];
        }
        if (str_starts_with($arg, $needle . '=')) {
            return substr($arg, strlen($needle) + 1);
        }
    }
    return $default;
}

function cli_flag(string $name): bool
{
    global $argv;
    return in_array('--' . $name, $argv, true);
}

function require_confirm(string $message = 'This example modifies data.'): void
{
    if (!cli_flag('confirm')) {
        fwrite(STDERR, $message . " Re-run with --confirm to proceed.\n");
        exit(1);
    }
}

function output_result(bool $success, mixed $data, string $label = ''): void
{
    if ($label !== '') {
        echo "# {$label}\n";
    }
    if (!$success) {
        fwrite(STDERR, "API call failed.\n");
        if ($data !== null) {
            fwrite(STDERR, print_r($data, true) . "\n");
        }
        exit(1);
    }
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
}

function read_json_file(string $path): array
{
    if (!file_exists($path)) {
        fwrite(STDERR, "File not found: {$path}\n");
        exit(1);
    }
    $data = json_decode(file_get_contents($path), true);
    if (!is_array($data)) {
        fwrite(STDERR, "Invalid JSON in file: {$path}\n");
        exit(1);
    }
    return $data;
}
