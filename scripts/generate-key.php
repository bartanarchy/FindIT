<?php

// Creates the secret key that encrypts the database columns and the backup.
//   php scripts/generate-key.php            -> prints a key
//   php scripts/generate-key.php --write    -> adds APP_ENC_KEY to .env (only if none is set)

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Command line only.');
}

$key = base64_encode(random_bytes(32));
$envPath = dirname(__DIR__) . '/.env';

if (!in_array('--write', $argv, true)) {
    echo "APP_ENC_KEY=$key\n\nAdd this line to .env, then keep an OFFLINE copy of it.\n";
    exit(0);
}

$env = is_file($envPath) ? (string) file_get_contents($envPath) : '';

if (preg_match('/^APP_ENC_KEY=[ \t]*\S+/m', $env)) {
    fwrite(STDERR, "APP_ENC_KEY already exists in .env - not replacing it.\n"
        . "Replacing the key would make existing encrypted data unreadable.\n");
    exit(1);
}

if (preg_match('/^APP_ENC_KEY=[ \t]*\r?$/m', $env)) {
    $env = preg_replace('/^APP_ENC_KEY=[ \t]*\r?$/m', 'APP_ENC_KEY=' . $key, $env, 1);
} else {
    $env = rtrim($env) . "\n\nAPP_ENC_KEY=$key\n";
}

file_put_contents($envPath, $env);
echo "Key written to .env\n\nIMPORTANT: copy this key to a safe place OFFLINE (password manager, USB).\n"
    . "If it is lost, encrypted data and backups can NEVER be recovered:\n\n  APP_ENC_KEY=$key\n";
