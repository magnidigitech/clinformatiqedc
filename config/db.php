<?php
// Set timezone to India Standard Time (IST)
date_default_timezone_set('Asia/Kolkata');

// Database Configuration for PostgreSQL

// Optional local-only override (.env.local is gitignored and dockerignored)
$localEnvFile = __DIR__ . '/../.env.local';
$localEnv = [];
if (is_file($localEnvFile) && is_readable($localEnvFile)) {
    foreach (file($localEnvFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || $trimmed[0] === '#' || strpos($trimmed, '=') === false) {
            continue;
        }
        [$k, $v] = explode('=', $trimmed, 2);
        $k = trim($k);
        $v = trim($v, " \t\n\r\0\x0B\"'");
        if ($k !== '') {
            $localEnv[$k] = $v;
        }
    }
}

$getConfigEnv = static function ($key) use ($localEnv) {
    $val = getenv($key);
    if ($val !== false && $val !== '') {
        return $val;
    }
    if (isset($localEnv[$key]) && $localEnv[$key] !== '') {
        return $localEnv[$key];
    }
    return '';
};

// Parse DATABASE_URL if set, otherwise honor explicit DB_* overrides before falling back to .env.local or production container defaults
$envDatabaseUrl = getenv('DATABASE_URL');
$envExplicitHost = getenv('DB_HOST');

if ($envDatabaseUrl !== false && $envDatabaseUrl !== '') {
    $dbUrl = $envDatabaseUrl;
    $explicitHost = '';
} elseif ($envExplicitHost !== false && $envExplicitHost !== '') {
    $dbUrl = '';
    $explicitHost = $envExplicitHost;
} else {
    $dbUrl = $getConfigEnv('DATABASE_URL');
    $explicitHost = $getConfigEnv('DB_HOST');
}

$host = $explicitHost ?: 'u7uonf6y780uxeujaw91ep8k';
$port = $getConfigEnv('DB_PORT') ?: '5432';
$dbname = $getConfigEnv('DB_NAME') ?: 'postgres';
$user = $getConfigEnv('DB_USER') ?: 'postgres';
$pass = $getConfigEnv('DB_PASS');
if ($pass === '' && !$explicitHost && $dbUrl === '') {
    $pass = 'Q1sn4NpjT7KKCeeE46Q1E8zg06zqQ4nm6EF7jyt2chex4XY8PBma6oajRNDlBnfs';
}
$sslmode = $getConfigEnv('DB_SSLMODE');

if ($dbUrl === '' && $explicitHost === '') {
    $dbUrl = 'postgres://postgres:Q1sn4NpjT7KKCeeE46Q1E8zg06zqQ4nm6EF7jyt2chex4XY8PBma6oajRNDlBnfs@u7uonf6y780uxeujaw91ep8k:5432/postgres';
}

if (!empty($dbUrl)) {
    $parsedUrl = parse_url($dbUrl);
    if ($parsedUrl) {
        $host = $parsedUrl['host'] ?? $host;
        $port = isset($parsedUrl['port']) ? (string)$parsedUrl['port'] : $port;
        $user = $parsedUrl['user'] ?? $user;
        $pass = array_key_exists('pass', $parsedUrl) ? $parsedUrl['pass'] : '';
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
