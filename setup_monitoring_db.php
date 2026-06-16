<?php
require_once 'includes/functions.php';

try {
    $pdo = getDB();
    $sql = file_get_contents('create_monitoring_tables.sql');
    
    // Split by semicolon to execute multiple statements if needed, 
    // or just exec if the driver supports multiple statements (PDO usually does if implied)
    // But safely, let's just run it.
    
    $pdo->exec($sql);
    echo "Successfully created monitoring tables.\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
