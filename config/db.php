<?php
// Set timezone to India Standard Time (IST)
date_default_timezone_set('Asia/Kolkata');

// Database Configuration for PostgreSQL

// Parse DATABASE_URL if available, otherwise fallback to individual environment variables or defaults
$dbUrl = getenv('DATABASE_URL') ?: 'postgres://postgres:Q1sn4NpjT7KKCeeE46Q1E8zg06zqQ4nm6EF7jyt2chex4XY8PBma6oajRNDlBnfs@u7uonf6y780uxeujaw91ep8k:5432/postgres';

$host = getenv('DB_HOST') ?: 'u7uonf6y780uxeujaw91ep8k';
$port = getenv('DB_PORT') ?: '5432';
$dbname = getenv('DB_NAME') ?: 'postgres';
$user = getenv('DB_USER') ?: 'postgres';
$pass = getenv('DB_PASS') ?: 'Q1sn4NpjT7KKCeeE46Q1E8zg06zqQ4nm6EF7jyt2chex4XY8PBma6oajRNDlBnfs';
$sslmode = '';

if (!empty($dbUrl)) {
    $parsedUrl = parse_url($dbUrl);
    if ($parsedUrl) {
        $host = $parsedUrl['host'] ?? $host;
        $port = $parsedUrl['port'] ?? $port;
        $user = $parsedUrl['user'] ?? $user;
        $pass = $parsedUrl['pass'] ?? $pass;
        $dbname = isset($parsedUrl['path']) ? ltrim($parsedUrl['path'], '/') : $dbname;
        
        if (isset($parsedUrl['query'])) {
            parse_str($parsedUrl['query'], $queryParams);
            if (isset($queryParams['sslmode'])) {
                $sslmode = $queryParams['sslmode'];
            }
        }
    }
}

define('DB_HOST', $host);
define('DB_PORT', $port);
define('DB_NAME', $dbname);
define('DB_USER', $user);
define('DB_PASS', $pass);
define('DB_SSLMODE', $sslmode);

/**
 * Get Database Connection
 * Returns a PDO config object
 */
function getDB() {
    static $pdo = null;

    if ($pdo === null) {
        if (!in_array('pgsql', PDO::getAvailableDrivers())) {
            $available = implode(', ', PDO::getAvailableDrivers()) ?: 'none';
            throw new \PDOException("PHP PDO PostgreSQL driver (pdo_pgsql) is not installed. Available drivers: [{$available}]. Please ensure the pdo_pgsql extension is enabled in PHP.");
        }

        $dsn = "pgsql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME;
        if (!empty(DB_SSLMODE)) {
            $dsn .= ";sslmode=" . DB_SSLMODE;
        }
        
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            $pdo->exec("SET TIME ZONE 'Asia/Kolkata'");
        } catch (\PDOException $e) {
            throw new \PDOException("Database connection failed: " . $e->getMessage(), (int)$e->getCode());
        }
    }

    return $pdo;
}
?>
