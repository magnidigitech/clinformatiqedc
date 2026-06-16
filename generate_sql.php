<?php
// Output SQL file
$outputFile = 'insert_defaults.sql';
$study_id = 1; // Default study ID

$raw_content = file_get_contents('raw_options.txt');
$lines = explode("\n", $raw_content);

$parsed_groups = [];
$current_group = null;

foreach ($lines as $line) {
    $line = trim($line);
    if (!$line) continue;
    
    if (strpos($line, ':') === false) {
        $current_group = $line;
        $parsed_groups[$current_group] = [];
    } else {
        $parts = explode(':', $line, 2);
        $label = trim($parts[0]);
        $value = trim($parts[1] ?? '');
        if ($current_group) {
            $parsed_groups[$current_group][$label] = $value;
        }
    }
}

$sql = "-- SQL Import for Option Groups\n";
$sql .= "-- Study ID: $study_id (Modify if needed)\n\n";

foreach ($parsed_groups as $groupName => $options) {
    // Escape group name
    $safeGroupName = addslashes($groupName);
    
    // We use a variable to store the inserted ID to handle multiple groups robustly in one script
    // But basic PHPMyAdmin import might prefer simple statements.
    // Let's assume we insert group, then get ID using a subquery lookup to be safe for re-runs?
    // No, standard LAST_INSERT_ID() is best for single session import.
    
    $sql .= "-- Group: $groupName\n";
    $sql .= "INSERT INTO option_groups (study_id, name) VALUES ((SELECT id FROM studies ORDER BY id ASC LIMIT 1), '$safeGroupName');\n";
    $sql .= "SET @last_group_id = LAST_INSERT_ID();\n";
    
    $order = 0;
    foreach ($options as $label => $val) {
        $safeLabel = addslashes($label);
        $safeVal = addslashes($val);
        $sql .= "INSERT INTO option_choices (group_id, label, value, order_index) VALUES (@last_group_id, '$safeLabel', '$safeVal', $order);\n";
        $order++;
    }
    $sql .= "\n";
}

file_put_contents($outputFile, $sql);
echo "Generated $outputFile with " . count($parsed_groups) . " groups.\n";
?>
