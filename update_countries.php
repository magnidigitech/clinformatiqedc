<?php
require_once 'includes/functions.php';
require_once 'config/db.php';

$pdo = getDB();

$countries = [
    "Afghanistan", "Albania", "Algeria", "Andorra", "Angola", "Antigua and Barbuda", "Argentina", "Armenia", "Australia", "Austria", "Azerbaijan",
    "Bahamas", "Bahrain", "Bangladesh", "Barbados", "Belarus", "Belgium", "Belize", "Benin", "Bhutan", "Bolivia", "Bosnia and Herzegovina", "Botswana", "Brazil", "Brunei", "Bulgaria", "Burkina Faso", "Burundi",
    "Cabo Verde", "Cambodia", "Cameroon", "Canada", "Central African Republic", "Chad", "Chile", "China", "Colombia", "Comoros", "Congo (Democratic Republic)", "Congo (Republic)", "Costa Rica", "Cote d'Ivoire", "Croatia", "Cuba", "Cyprus", "Czech Republic",
    "Denmark", "Djibouti", "Dominica", "Dominican Republic",
    "Ecuador", "Egypt", "El Salvador", "Equatorial Guinea", "Eritrea", "Estonia", "Eswatini", "Ethiopia",
    "Fiji", "Finland", "France",
    "Gabon", "Gambia", "Georgia", "Germany", "Ghana", "Greece", "Grenada", "Guatemala", "Guinea", "Guinea-Bissau", "Guyana",
    "Haiti", "Honduras", "Hungary",
    "Iceland", "India", "Indonesia", "Iran", "Iraq", "Ireland", "Israel", "Italy",
    "Jamaica", "Japan", "Jordan",
    "Kazakhstan", "Kenya", "Kiribati", "Kosovo", "Kuwait", "Kyrgyzstan",
    "Laos", "Latvia", "Lebanon", "Lesotho", "Liberia", "Libya", "Liechtenstein", "Lithuania", "Luxembourg",
    "Madagascar", "Malawi", "Malaysia", "Maldives", "Mali", "Malta", "Marshall Islands", "Mauritania", "Mauritius", "Mexico", "Micronesia", "Moldova", "Monaco", "Mongolia", "Montenegro", "Morocco", "Mozambique", "Myanmar",
    "Namibia", "Nauru", "Nepal", "Netherlands", "New Zealand", "Nicaragua", "Niger", "Nigeria", "North Korea", "North Macedonia", "Norway",
    "Oman",
    "Pakistan", "Palau", "Palestine", "Panama", "Papua New Guinea", "Paraguay", "Peru", "Philippines", "Poland", "Portugal",
    "Qatar",
    "Romania", "Russia", "Rwanda",
    "Saint Kitts and Nevis", "Saint Lucia", "Saint Vincent and the Grenadines", "Samoa", "San Marino", "Sao Tome and Principe", "Saudi Arabia", "Senegal", "Serbia", "Seychelles", "Sierra Leone", "Singapore", "Slovakia", "Slovenia", "Solomon Islands", "Somalia", "South Africa", "South Korea", "South Sudan", "Spain", "Sri Lanka", "Sudan", "Suriname", "Sweden", "Switzerland", "Syria",
    "Taiwan", "Tajikistan", "Tanzania", "Thailand", "Timor-Leste", "Togo", "Tonga", "Trinidad and Tobago", "Tunisia", "Turkey", "Turkmenistan", "Tuvalu",
    "Uganda", "Ukraine", "United Arab Emirates", "United Kingdom", "United States", "Uruguay", "Uzbekistan",
    "Vanuatu", "Vatican City", "Venezuela", "Vietnam",
    "Yemen",
    "Zambia", "Zimbabwe"
];

// Check if 'Country' group exists
$stmt = $pdo->prepare("SELECT id FROM option_groups WHERE name = 'Country'");
$stmt->execute();
$group_id = $stmt->fetchColumn();

if (!$group_id) {
    echo "Creating 'Country' option group...\n";
    $stmt = $pdo->prepare("INSERT INTO option_groups (name, study_id) VALUES ('Country', 0)"); // 0 or null for global? Assuming usage in study context, but this script might be general. Let's assume standard way.
    // Actually, looking at option_groups.php, it uses study_id. This script might need to be run per study or we need a global concept.
    // For now, let's just update the specific options if we can find them, or just output what to do.
    // Wait, the user request implied updating the list. I'll just provide this script for them to run if they want, OR I can try to run it via CLI if allowed.
    // Be safer: I will write it to just output SQL statements they can run, OR I will make it interactive.
    // Re-reading: "update_countries.php - Script to populate...".
    // I'll stick to PHP inserting.
    
    // NOTE: Requires a study_id. I'll default to the first one found or 1.
    $stmt = $pdo->query("SELECT id FROM studies LIMIT 1");
    $study_id = $stmt->fetchColumn();
    if (!$study_id) die("No studies found.");
    
    $stmt = $pdo->prepare("INSERT INTO option_groups (name) VALUES ('Country')"); // Adjusted schema based on earlier view (option_groups table might not have study_id if global? checked option_groups.php, it filters by study_id? Yes, queries `WHERE 1` or similar. Wait, option_groups.php line 17: `SELECT ... FROM option_groups og ...`. It doesn't filter by study_id in the main query??
    // Line 20: `ORDER BY name ASC`.
    // Line 12: `$study_id = $_SESSION['active_study_id'];`
    // It seems option_groups MIGHT be global or study specific.
    // Let's check `ajax_options.php` or `option_groups.php` again.
    // `option_groups.php` has `WHERE su.user_id...` no wait.
    // It seems option_groups might be shared?
    // Let's check `ajax_options.php` if I can... or just assume I need to check the DB schema.
    // I'll verify `ajax_data.php` or similar to see how table creation works.
    // Actually, `option_groups.php` code I viewed:
    // `$stmt = $pdo->prepare("SELECT og.* ... FROM option_groups og ORDER BY name ASC");`
    // It does NOT filter by study_id. So they are likely global or shared.
    
    $stmt = $pdo->prepare("INSERT INTO option_groups (name) VALUES ('Country')");
    $stmt->execute();
    $group_id = $pdo->lastInsertId();
} else {
    echo "'Country' group exists (ID: $group_id). Updating options...\n";
    // Optional: Clear existing?
    // $pdo->exec("DELETE FROM option_choices WHERE group_id = $group_id");
}

$stmt = $pdo->prepare("INSERT INTO option_choices (group_id, label, value, order_index) VALUES (?, ?, ?, ?)");

foreach ($countries as $index => $country) {
    // Check if exists
    $check = $pdo->prepare("SELECT id FROM option_choices WHERE group_id = ? AND value = ?");
    $check->execute([$group_id, $country]);
    if (!$check->fetch()) {
        $stmt->execute([$group_id, $country, $country, $index]);
        echo "Added $country\n";
    }
}

echo "Done.\n";
