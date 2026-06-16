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
                    $pdo->exec("ALTER TABLE subject_form_status ADD COLUMN is_verified INT DEFAULT 0");
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
                    $pdo->exec("ALTER TABLE subject_form_status ADD COLUMN is_complete INT DEFAULT 0");
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
            $updated = $pdo->exec("UPDATE subject_form_status SET is_complete = 1 WHERE (status = 'complete' OR status = 'verified' OR progress_percent = 100) AND is_complete = 0");
            echo "<p style='color:blue'>Backfilled <code>is_complete = 1</code> for $updated rows.</p>";
        } catch (Exception $e) {
             echo "<p style='color:red'>Backfill failed: " . $e->getMessage() . "</p>";
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
