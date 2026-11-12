<?php

// Shared start-up for the maintenance scripts. They run from the command line only.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Command line only.');
}

$root = dirname(__DIR__);

// Loads .env, the database connection ($pdo) and the Crypto helper.
require_once $root . '/config/config.php';

function out(string $line = ''): void
{
    echo $line . PHP_EOL;
}

function hasFlag(string $flag): bool
{
    global $argv;
    return in_array($flag, $argv, true);
}

function confirmOrExit(string $question): void
{
    if (hasFlag('--yes')) {
        return;
    }
    echo $question . ' Type "yes" to continue: ';
    if (trim((string) fgets(STDIN)) !== 'yes') {
        out('Cancelled.');
        exit(1);
    }
}

// Run the SQL files in database/migrations in order (each one is safe to repeat).
function runMigrations(PDO $pdo, string $root): void
{
    $files = glob($root . '/database/migrations/*.sql') ?: [];
    sort($files);
    foreach ($files as $file) {
        $pdo->exec((string) file_get_contents($file));
        out('  applied ' . basename($file));
    }
}

// Stop with a readable message (not a stack trace) when the key is missing.
function requireKey(): void
{
    try {
        Crypto::parseKey(APP_ENC_KEY);
    } catch (RuntimeException $e) {
        fwrite(STDERR, $e->getMessage() . PHP_EOL);
        exit(1);
    }
}
