<?php

// Sets up the emergency administrator used when the database is down.
//   php scripts/recovery-account.php
// It asks for an email and a password (at least 12 characters), then writes
// ADMIN_RECOVERY_EMAIL and ADMIN_RECOVERY_PASSWORD_HASH to .env. Only the hash is stored.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Command line only.');
}

$envPath = dirname(__DIR__) . '/.env';

function ask(string $label, bool $hidden = false): string
{
    echo $label;
    $stty = $hidden && DIRECTORY_SEPARATOR !== '\\';
    if ($stty) {
        shell_exec('stty -echo');
    }
    $value = trim((string) fgets(STDIN));
    if ($stty) {
        shell_exec('stty echo');
        echo "\n";
    }
    return $value;
}

$email = strtolower(ask('Emergency admin email: '));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "That is not a valid email address.\n");
    exit(1);
}

$password = ask('Password (min. 12 characters): ', true);
if (strlen($password) < 12) {
    fwrite(STDERR, "The password must be at least 12 characters.\n");
    exit(1);
}
if ($password !== ask('Repeat password: ', true)) {
    fwrite(STDERR, "The passwords do not match.\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$env = is_file($envPath) ? (string) file_get_contents($envPath) : '';

$set = static function (string $env, string $key, string $value): string {
    $line = $key . '=' . $value;
    if (preg_match('/^' . preg_quote($key, '/') . '=.*$/m', $env)) {
        return preg_replace_callback('/^' . preg_quote($key, '/') . '=.*$/m', static fn() => $line, $env, 1);
    }
    return rtrim($env, "\r\n") . "\n" . $line . "\n";
};

$env = $set($env, 'ADMIN_RECOVERY_EMAIL', $email);
$env = $set($env, 'ADMIN_RECOVERY_PASSWORD_HASH', $hash);

if (file_put_contents($envPath, $env) === false) {
    fwrite(STDERR, "Could not write .env.\n");
    exit(1);
}

echo "Done. The emergency admin is saved in .env (password stored as a hash only).\n";
echo "Sign in at /public/recovery-login.php while the database is down.\n";
