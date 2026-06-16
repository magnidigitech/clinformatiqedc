<?php
// Database Configuration for PostgreSQL

// Parse DATABASE_URL if available, otherwise fallback to individual environment variables or defaults
$dbUrl = getenv('DATABASE_URL') ?: 'postgres://postgres:JrLskPQkOWNzR22bhx1LRHkbVh8I4MGPFDrFkKeNZWC7IO60bnwC6Zlz9EMPAiPq@127.0.0.1:5432/postgres';

$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: '5432';
$dbname = getenv('DB_NAME') ?: 'postgres';
$user = getenv('DB_USER') ?: 'postgres';
$pass = getenv('DB_PASS') ?: '';
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
        } catch (\PDOException $e) {
            throw new \PDOException("Database connection failed: " . $e->getMessage(), (int)$e->getCode());
        }
    }

    return $pdo;
}
?>
