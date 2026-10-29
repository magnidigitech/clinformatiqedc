<?php
echo "PHP Version: " . phpversion() . "\n";
echo "PDO Drivers: " . implode(', ', PDO::getAvailableDrivers()) . "\n";
echo "Loaded Extensions: " . implode(', ', get_loaded_extensions()) . "\n";
