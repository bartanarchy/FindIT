<?php

// declare(strict_types=1);

// This file handles the FindIT database connection.
// Keep it outside the public folder to prevent direct access.

// Load the .env file from the project root directory.
$envPath = dirname(__DIR__) . '/.env';

if (!is_file($envPath) || !is_readable($envPath)) {
    throw new RuntimeException(
        'The environment configuration file was not found.'
    );
}

$env = parse_ini_file($envPath, false, INI_SCANNER_RAW);

if ($env === false) {
    throw new RuntimeException(
        'The environment configuration file could not be read.'
    );
}

// Retrieve configuration values and apply default values when available.
$dbHost = $env['DB_HOST'] ?? '127.0.0.1';
$dbPort = $env['DB_PORT'] ?? '5432';
$dbName = $env['DB_NAME'] ?? 'FindIT';
$dbUser = $env['DB_USER'] ?? 'postgres';
$dbPass = $env['DB_PASS'] ?? '1';

// Validate the configuration before attempting to connect.
if (
    $dbHost === '' ||
    !ctype_digit((string) $dbPort) ||
    (int) $dbPort < 1 ||
    (int) $dbPort > 65535 ||
    $dbName === '' ||
    $dbUser === '' ||
    $dbPass === ''
) {
    throw new RuntimeException(
        'The database configuration is incomplete or invalid.'
    );
}

try {
    // Build the PostgreSQL Data Source Name (DSN).
    $dsn = sprintf(
        'pgsql:host=%s;port=%s;dbname=%s',
        $dbHost,
        $dbPort,
        $dbName
    );

    // Establish a PDO connection with secure settings.
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        // Throw exceptions when database errors occur.
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,

        // Return query results as associative arrays.
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,

        // Use native prepared statements for PostgreSQL.
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

} catch (PDOException $e) {
    // Log detailed error information on the server.
    error_log(
        '[FindIT] PostgreSQL connection failed: ' . $e->getMessage()
    );

    // Throw a safe error message without exposing database details.
    throw new RuntimeException(
        'Database connection failed. Please check the server configuration.',
        0,
        $e
    );
}