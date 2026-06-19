<?php
/**
 * PostgreSQL Database Update Script
 * Run this script to apply the changes in update_schema.sql to the database.
 */
require_once 'config/db.php';

try {
    $pdo = getDB();
    $sql = file_get_contents('update_schema.sql');
    $pdo->exec($sql);
    echo "Successfully updated PostgreSQL database schema.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
