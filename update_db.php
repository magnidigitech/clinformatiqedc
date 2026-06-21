<?php
require_once 'config/db.php';

// Verify DB connection
try {
    $pdo = getDB();
} catch (Exception $e) {
    die("Database connection failed: " . $e->getMessage());
}

$success_count = 0;
$error_count = 0;
$results = [];

// Read update_schema.sql
$sql_file = __DIR__ . '/update_schema.sql';
if (!file_exists($sql_file)) {
    die("update_schema.sql file not found!");
}

$sql_content = file_get_contents($sql_file);

// Simple parser to split SQL queries by semicolon, ignoring comments
$queries = [];
$lines = explode("\n", $sql_content);
$current_query = '';

foreach ($lines as $line) {
    $trimmed = trim($line);
    // Ignore comments
    if (empty($trimmed) || strpos($trimmed, '--') === 0) {
        continue;
    }
    
    $current_query .= $line . "\n";
    
    // If line ends with semicolon, it's the end of a query
    if (substr($trimmed, -1) === ';') {
        $queries[] = trim($current_query);
        $current_query = '';
    }
}
if (!empty(trim($current_query))) {
    $queries[] = trim($current_query);
}

// Execute queries
foreach ($queries as $index => $query) {
    // Extract first line of query as a summary title
    $lines_q = explode("\n", $query);
    $query_title = trim($lines_q[0]);
    
    try {
        $pdo->exec($query);
        $results[] = [
            'sql' => $query,
            'title' => $query_title,
            'success' => true,
            'message' => 'Successfully executed.'
        ];
        $success_count++;
    } catch (PDOException $e) {
        // If column or change already exists, or it failed
        $msg = $e->getMessage();
        $is_ignorable = (
            strpos($msg, 'already exists') !== false || 
            strpos($msg, 'duplicate column') !== false
        );
        
        $results[] = [
            'sql' => $query,
            'title' => $query_title,
            'success' => $is_ignorable,
            'message' => $msg,
            'warning' => $is_ignorable
        ];
        
        if ($is_ignorable) {
            $success_count++;
        } else {
            $error_count++;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Schema Update - Clinformatiq</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
    <style>
        :root {
            --primary: #1d6f97;
            --accent: #0d8e6f;
            --text-dark: #1e293b;
            --text-light: #64748b;
            --bg: #f8fafc;
            --border: #e2e8f0;
        }
        body {
            font-family: 'Outfit', sans-serif;
            background: var(--bg);
            color: var(--text-dark);
            margin: 0;
            padding: 2rem;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            box-sizing: border-box;
        }
        .container {
            background: white;
            border: 1px solid var(--border);
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.05);
            max-width: 800px;
            width: 100%;
            padding: 2.5rem;
        }
        .header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 2rem;
            border-bottom: 1px solid var(--border);
            padding-bottom: 1.5rem;
        }
        .header img {
            height: 48px;
            width: auto;
        }
        .header h1 {
            font-size: 1.5rem;
            margin: 0;
            color: var(--primary);
        }
        .status-summary {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
        }
        .status-card {
            flex: 1;
            padding: 1rem;
            border-radius: 8px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .status-card.success {
            background: #ecfdf5;
            color: #047857;
            border: 1px solid #a7f3d0;
        }
        .status-card.error {
            background: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fca5a5;
        }
        .query-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        .query-item {
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 1rem;
            background: #fafafa;
        }
        .query-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }
        .query-title {
            font-weight: 600;
            font-size: 0.95rem;
            color: #334155;
        }
        .query-status {
            font-size: 0.75rem;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 99px;
            text-transform: uppercase;
        }
        .query-status.success {
            background: #d1fae5;
            color: #065f46;
        }
        .query-status.warning {
            background: #fef3c7;
            color: #92400e;
        }
        .query-status.error {
            background: #fee2e2;
            color: #991b1b;
        }
        .query-code {
            font-family: monospace;
            font-size: 0.85rem;
            background: #f1f5f9;
            padding: 0.75rem;
            border-radius: 4px;
            overflow-x: auto;
            margin: 0.5rem 0 0 0;
            color: #475569;
        }
        .query-msg {
            font-size: 0.8rem;
            color: var(--text-light);
            margin-top: 0.5rem;
        }
        .btn {
            display: inline-block;
            background: var(--primary);
            color: white;
            text-decoration: none;
            padding: 0.75rem 1.5rem;
            border-radius: 6px;
            font-weight: 600;
            margin-top: 2rem;
            transition: opacity 0.2s;
            border: none;
            cursor: pointer;
        }
        .btn:hover {
            opacity: 0.9;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <img src="edc_large_logo.png" alt="Logo">
            <div>
                <h1>Database Schema Update</h1>
                <div style="font-size: 0.85rem; color: var(--text-light); margin-top: 0.25rem;">Automatic Migration Log</div>
            </div>
        </div>

        <div class="status-summary">
            <div class="status-card success">
                <span class="material-icons-round">check_circle</span>
                <span><?php echo $success_count; ?> Steps Verified / Updated</span>
            </div>
            <?php if ($error_count > 0): ?>
                <div class="status-card class error">
                    <span class="material-icons-round">error</span>
                    <span><?php echo $error_count; ?> Errors Encountered</span>
                </div>
            <?php endif; ?>
        </div>

        <div class="query-list">
            <?php foreach ($results as $i => $res): ?>
                <div class="query-item">
                    <div class="query-header">
                        <span class="query-title">Step <?php echo $i + 1; ?>: <?php echo htmlspecialchars($res['title']); ?></span>
                        <?php if ($res['success'] && empty($res['warning'])): ?>
                            <span class="query-status success">Success</span>
                        <?php elseif ($res['success'] && !empty($res['warning'])): ?>
                            <span class="query-status warning">Already Done</span>
                        <?php else: ?>
                            <span class="query-status error">Failed</span>
                        <?php endif; ?>
                    </div>
                    <pre class="query-code"><code><?php echo htmlspecialchars($res['sql']); ?></code></pre>
                    <div class="query-msg">
                        <strong>Log:</strong> <?php echo htmlspecialchars($res['message']); ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div style="text-align: right;">
            <a href="index.php" class="btn">Proceed to Login</a>
        </div>
    </div>
</body>
</html>
