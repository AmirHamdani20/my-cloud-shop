<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    $https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'httponly' => true,
        'secure' => $https,
        'samesite' => 'Lax',
    ]);
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function cloudcartPdo(): PDO
{
    $serverName = getenv('DB_SERVER') ?: 'tcp:amir-sql-server2.database.windows.net,1433';
    $databaseName = getenv('DB_NAME') ?: 'AmirDatabase';
    $databaseUser = getenv('DB_USER') ?: 'AdminAmirA';
    $databasePassword = getenv('DB_PASSWORD');

    if ($databasePassword === false || $databasePassword === '') {
        throw new RuntimeException('The DB_PASSWORD environment variable is not configured.');
    }

    $dsn = sprintf(
        'sqlsrv:Server=%s;Database=%s;Encrypt=true;TrustServerCertificate=false',
        $serverName,
        $databaseName
    );

    return new PDO($dsn, $databaseUser, $databasePassword, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}
