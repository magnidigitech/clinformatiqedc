<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// manually include config if not working
include 'config/db.php';

echo "<h1>Diagnostic Report</h1>";

try {
    $pdo = getDB();
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    echo "<p style='color:green'>Database Connected Successfully (Driver: " . htmlspecialchars($driver) . ")</p>";
    
    // Auto-initialize PostgreSQL database if it is empty (studies table missing)
    $initSchema = false;
    try {
        $pdo->query("SELECT 1 FROM studies LIMIT 1");
    } catch (Exception $e) {
        $initSchema = true;
    }

    if ($initSchema) {
        echo "<p style='color:orange'>Database tables are missing. Auto-initializing database from schema.sql...</p>";
        if (file_exists(__DIR__ . '/schema.sql')) {
            try {
                $sql = file_get_contents(__DIR__ . '/schema.sql');
                $pdo->exec($sql);
                echo "<p style='color:green'><strong>Success:</strong> Database tables, triggers, and default admin user initialized successfully!</p>";
            } catch (Exception $ex) {
                echo "<p style='color:red'><strong>Error:</strong> Failed to run schema.sql: " . htmlspecialchars($ex->getMessage()) . "</p>";
            }
        } else {
            echo "<p style='color:red'><strong>Error:</strong> schema.sql file not found in root directory!</p>";
        }
    }

    
    if ($driver === 'pgsql') {
        $tables = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public'")->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    }
    
    echo "<h3>Database Tables:</h3><ul>";
    foreach ($tables as $t) {
        echo "<li>$t</li>";
    }
    echo "</ul>";
    
    try {
        $pdo->query("SELECT 1 FROM data_queries LIMIT 1");
        echo "<p style='color:green'>Table <code>data_queries</code> exists.</p>";
    } catch (Exception $e) {
        echo "<p style='color:red'>Table <code>data_queries</code> MISSING: " . $e->getMessage() . "</p>";
        // Attempt fix
        if ($driver === 'pgsql') {
            $sql = "CREATE TABLE IF NOT EXISTS data_queries (
                    id SERIAL PRIMARY KEY,
                    study_id INT NOT NULL,
                    subject_id INT NOT NULL,
                    visit_id INT NOT NULL,
                    form_id INT NOT NULL,
                    field_id INT NOT NULL,
                    repeating_instance_id INT DEFAULT 0,
                    query_text TEXT,
                    status VARCHAR(50) DEFAULT 'new',
                    created_by INT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )";
        } else {
            $sql = "CREATE TABLE IF NOT EXISTS data_queries (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    study_id INT NOT NULL,
                    subject_id INT NOT NULL,
                    visit_id INT NOT NULL,
                    form_id INT NOT NULL,
                    field_id INT NOT NULL,
                    repeating_instance_id INT DEFAULT 0,
                    query_text TEXT,
                    status ENUM('new','open','answered','closed','unconfirmed') DEFAULT 'new',
                    created_by INT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )";
        }
        $pdo->exec($sql);
        echo "<p style='color:blue'>Attempted to create <code>data_queries</code>.</p>";
    }

    // Check subject_form_status columns
    echo "<h3>Table: subject_form_status</h3>";
    try {
        if ($driver === 'pgsql') {
            $stmt = $pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_name = ?");
            $stmt->execute(['subject_form_status']);
            $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } else {
            $stmt = $pdo->query("DESCRIBE subject_form_status");
            $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
        echo "<p>Current Columns: " . implode(', ', $columns) . "</p>";

        // 1. Fix is_verified
        if (!in_array('is_verified', $columns)) {
            echo "<p style='color:orange'>Column <code>is_verified</code> MISSING. Adding it...</p>";
            try {
                if ($driver === 'pgsql') {
                    $pdo->exec("ALTER TABLE subject_form_status ADD COLUMN is_verified BOOLEAN DEFAULT FALSE");
                } else {
                    $pdo->exec("ALTER TABLE subject_form_status ADD COLUMN is_verified TINYINT(1) DEFAULT 0");
                }
                echo "<p style='color:green'>Success: Added <code>is_verified</code>.</p>";
            } catch (Exception $e) {
                echo "<p style='color:red'>Failed to add is_verified: " . $e->getMessage() . "</p>";
            }
        } else {
            echo "<p style='color:green'>Column <code>is_verified</code> exists.</p>";
        }

        // 2. Fix is_complete (if missing)
        if (!in_array('is_complete', $columns)) {
             echo "<p style='color:orange'>Column <code>is_complete</code> MISSING.</p>";
             try {
                if ($driver === 'pgsql') {
                    $pdo->exec("ALTER TABLE subject_form_status ADD COLUMN is_complete BOOLEAN DEFAULT FALSE");
                } else {
                    $pdo->exec("ALTER TABLE subject_form_status ADD COLUMN is_complete TINYINT(1) DEFAULT 0");
                }
                echo "<p style='color:blue'>Added missing <code>is_complete</code> column.</p>";
             } catch (Exception $e) {
                  echo "<p style='color:red'>Failed to add is_complete: " . $e->getMessage() . "</p>";
             }
        } else {
            echo "<p style='color:green'>Column <code>is_complete</code> exists.</p>";
        }

        // 3. Backfill is_complete based on status
        echo "<h3>Backfill Data</h3>";
        try {
            if ($driver === 'pgsql') {
                $updated = $pdo->exec("UPDATE subject_form_status SET is_complete = true WHERE (status = 'complete' OR status = 'verified' OR progress_percent = 100) AND is_complete = false");
            } else {
                $updated = $pdo->exec("UPDATE subject_form_status SET is_complete = 1 WHERE (status = 'complete' OR status = 'verified' OR progress_percent = 100) AND is_complete = 0");
            }
            echo "<p style='color:blue'>Backfilled <code>is_complete = true</code> for $updated rows.</p>";
        } catch (Exception $e) {
             echo "<p style='color:red'>Backfill failed: " . $e->getMessage() . "</p>";
        }

        // 4. Default Admin User Check
        echo "<h3>Default Admin User Check</h3>";
        try {
            $stmt = $pdo->prepare("SELECT id, username, email FROM users WHERE username = ? OR email = ?");
            $stmt->execute(['admin', 'admin@clinformatiq.com']);
            $admin_user = $stmt->fetch();
            
            $resetRequested = isset($_GET['reset_admin']) && $_GET['reset_admin'] == '1';
            
            if ($admin_user && !$resetRequested) {
                echo "<p style='color:green'>Default Admin User exists: <code>" . htmlspecialchars($admin_user['username']) . "</code> (Email: <code>" . htmlspecialchars($admin_user['email']) . "</code>)</p>";
                echo "<p>To reset the admin password to <code>Admin@123!</code>, visit: <a href='diagnose_errors_web.php?reset_admin=1'>diagnose_errors_web.php?reset_admin=1</a></p>";
            } else {
                $hash = '$2y$12$tOmIbpAofxtIpuiuCjbaW.D2VaBh0tZzzTrSLt/4tB9dVI09WvpCa'; // Admin@123!
                if ($admin_user) {
                    $upd = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                    $upd->execute([$hash, $admin_user['id']]);
                    echo "<p style='color:green'><strong>Success:</strong> Default Admin User password has been reset to <code>Admin@123!</code></p>";
                } else {
                    $ins = $pdo->prepare("INSERT INTO users (username, email, password_hash) VALUES ('admin', 'admin@clinformatiq.com', ?)");
                    $ins->execute([$hash]);
                    echo "<p style='color:green'><strong>Success:</strong> Default Admin User successfully seeded with password <code>Admin@123!</code></p>";
                }
            }
        } catch (Exception $e) {
            echo "<p style='color:red'>Failed to check/seed admin user: " . $e->getMessage() . "</p>";
        }

    } catch (Exception $e) {
        echo "<p style='color:red'>Error describing table: " . $e->getMessage() . "</p>";
    }

} catch (Exception $e) {
    echo "<p style='color:red'>Database Connection Failed: " . $e->getMessage() . "</p>";
    echo "<p>Host: " . DB_HOST . "</p>";
    echo "<p>User: " . DB_USER . "</p>";
}
?>
