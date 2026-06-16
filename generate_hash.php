<?php
// Simple utility to generate password hashes compatible with the system
// Usage: Visit this page in browser ?password=yourpassword or run via CLI

$password = $_GET['password'] ?? $argv[1] ?? 'password123';
$hash = password_hash($password, PASSWORD_BCRYPT);

echo "<h3>Password Hash Generator</h3>";
echo "<strong>Password:</strong> " . htmlspecialchars($password) . "<br>";
echo "<strong>Hash:</strong> " . htmlspecialchars($hash) . "<br>";
echo "<br><em>Copy the hash above and paste it into the 'password_hash' column in your 'users' table.</em>";
