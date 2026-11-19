<?php
echo "Deploy Version: 2026-11-19 12:00\n";
echo "PHP Version: " . phpversion() . "\n";
echo "PDO Drivers: " . implode(', ', PDO::getAvailableDrivers()) . "\n";
echo "Loaded Extensions: " . implode(', ', get_loaded_extensions()) . "\n";
