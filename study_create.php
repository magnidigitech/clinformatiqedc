<?php
require_once 'includes/functions.php';
require_once 'includes/auth.php';

requireLogin();

// Apart from admin, no other roles can create a new study
if (!isUserAdmin()) {
    redirect('dashboard.php');
}

$is_admin = true;

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $trial_id = trim($_POST['trial_id'] ?? '');
    $site_name = trim($_POST['site_name'] ?? '');
    $abbreviation = trim($_POST['abbreviation'] ?? '');
    $country = trim($_POST['country'] ?? '');
    $template = $is_admin ? ($_POST['template'] ?? 'none') : 'none';
    $type = $_POST['type'] ?? 'test';

    // Basic Validation
    if (strlen($name) < 3) $error = "Study Name must be at least 3 characters.";
    elseif (strlen($site_name) < 3) $error = "Site Name must be at least 3 characters.";
    elseif (strlen($abbreviation) < 3 || strlen($abbreviation) > 6) $error = "Abbreviation must be 3-6 characters.";
    elseif (empty($country)) $error = "Please select a country.";
    // Template and Type are optional/hidden now
    // elseif (empty($template)) $error = "Please select a template.";
    // elseif (empty($type)) $error = "Please select a study type.";
    
    if (empty($error)) {
        $pdo = getDB();
        
        // Generate a unique study code just in case
        // We'll use the abbreviation + random number for the internal unique code
        $study_code = strtoupper($abbreviation) . rand(100, 999);
        
        // Check uniqueness
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM studies WHERE study_code = ?");
        $stmt->execute([$study_code]);
        if ($stmt->fetchColumn() > 0) {
            $study_code = strtoupper($abbreviation) . rand(1000, 9999);
        }

        try {
            $pdo->beginTransaction();

            // 1. Insert Study
            $stmt = $pdo->prepare("INSERT INTO studies (name, trial_registry_id, study_code, site_name, site_abbreviation, site_country, template, type, created_by, status) VALUES (:name, :trial, :code, :site, :abbr, :country, :tpl, :type, :uid, 'design')");
            $stmt->execute([
                'name' => $name,
                'trial' => $trial_id,
                'code' => $study_code,
                'site' => $site_name,
                'abbr' => $abbreviation,
                'country' => $country,
                'tpl' => $template,
                'type' => $type,
                'uid' => $_SESSION['user_id']
            ]);
            
            $new_study_id = $pdo->lastInsertId();

            // 1b. Insert Initiating Site into 'sites' table
            // We set site_code to '01' as default for the main site
            $stmt = $pdo->prepare("INSERT INTO sites (study_id, name, country, site_code, abbreviation, date_format, main_site) VALUES (:sid, :name, :country, '01', :abbr, 'YYYY-MM-DD (ISO)', true)");
            $stmt->execute([
                'sid' => $new_study_id,
                'name' => $site_name,
                'country' => $country,
                'abbr' => $abbreviation
            ]);

            // 2. Assign Current User as Admin, Data Coordinator, AND Data Monitor
            // We use the new 'study_users' table structure
            
            $roles_to_assign = [
                ['name' => 'Admin', 'perms' => 'all'],
                ['name' => 'Data Coordinator', 'perms' => '{"view": true, "add": true, "add_subject": true, "enter_data": true, "edit": true}'],
                ['name' => 'Data Monitor', 'perms' => '{"view": true, "query": true, "raise_query": true, "verify": true}'],
                ['name' => 'Data Manager', 'perms' => '{"view": true, "query": true, "raise_query": true, "verify": true}'],
                ['name' => 'Principal Investigator', 'perms' => '{"view": true, "query": true, "sign_off": true}']
            ];

            $stmt = $pdo->prepare("INSERT INTO study_users (user_id, study_id, role_name, permissions) VALUES (:uid, :sid, :role, :perms)");
            
            foreach ($roles_to_assign as $role) {
                $stmt->execute([
                    'uid' => $_SESSION['user_id'],
                    'sid' => $new_study_id,
                    'role' => $role['name'],
                    'perms' => $role['perms']
                ]);
            }

            // 3. Auto-import Protocol Template if selected
            if ($template === 'oncology_protocol' || $template === 'standard' || $template === 'all') {
                require_once __DIR__ . '/includes/protocol_template.php';
                importProtocolTemplate($pdo, $new_study_id);
            }

            $pdo->commit();
            
            // Refresh session assignments
            // Ideally we'd just redirect and let the system refresh, but our session data might be stale.
            // For now, simple redirect. dashboard.php *should* re-fetch if we cleared the session cache, 
            // but we store assignments in SESSION. We need to clear it.
            unset($_SESSION['assignments']);
            
            redirect('dashboard.php');

        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Database Error: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Create New Study - Clinformatiq</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
    <style>
        .form-section { margin-bottom: 2rem; }
        .form-section h3 { font-size: 1rem; color: var(--text-light); margin-bottom: 1rem; border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem; }
        .radio-group { display: flex; flex-direction: column; gap: 0.5rem; }
        .radio-item { display: flex; align-items: center; gap: 0.5rem; }
        .radio-item input[type="radio"] { accent-color: var(--accent-color); }
        .helper-text { font-size: 0.75rem; color: var(--text-light); margin-top: 0.25rem; }
        .required { color: #dc2626; margin-left: 2px; }
    </style>
</head>
<body>

<div class="app-layout" style="display: block; background: #f8fafc; min-height: 100vh;">
    
    <!-- Simple Header -->
    <header style="background: white; border-bottom: 1px solid var(--border-color); padding: 1rem 2rem; display: flex; align-items: center; justify-content: space-between;">
        <div style="display: flex; align-items: center; gap: 1rem;">
            <a href="dashboard.php" style="color: var(--text-light); display: flex; align-items: center;">
                <span class="material-icons-round">arrow_back</span>
            </a>
            <h1 style="font-size: 1.25rem; margin: 0;">Create a new study</h1>
        </div>
        <div>
            <!-- Step indicator could go here -->
        </div>
    </header>

    <div class="container" style="max-width: 800px; padding: 2rem 1rem;">
        
        <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo sanitizeInput($error); ?></div>
        <?php endif; ?>
        
        <form method="POST" action="study_create.php" class="card">
            <!-- Study Information -->
            <div class="form-section">
                <div class="form-group">
                    <label class="form-label">Name of your study <span class="required">*</span></label>
                    <input type="text" name="name" class="form-input" placeholder="e.g. Clinical Trial Phase III" value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>" required>
                    <p class="helper-text">Minimum 3 characters.</p>
                </div>

                <div class="form-group">
                    <label class="form-label">Trial registry ID</label>
                    <input type="text" name="trial_id" class="form-input" placeholder="e.g. NCT01234567" value="<?php echo htmlspecialchars($_POST['trial_id'] ?? ''); ?>">
                    <p class="helper-text">If your study is linked to a trial registered in a trial database, please supply the trial registry ID.</p>
                </div>
            </div>

            <!-- Initiating Site Information -->
            <div class="form-section">
                <h3>Initiating site information</h3>
                
                <div class="form-group">
                    <label class="form-label">Name of your site <span class="required">*</span></label>
                    <input type="text" name="site_name" class="form-input" value="<?php echo htmlspecialchars($_POST['site_name'] ?? 'Test Site'); ?>" required>
                    <p class="helper-text">A default 'Test Site' will be created automatically. Please choose a different name for any additional site you want to create. Minimum 3 characters.</p>
                </div>

                <div class="form-group">
                    <label class="form-label">Abbreviation <span class="required">*</span></label>
                    <input type="text" name="abbreviation" class="form-input" placeholder="e.g. ABC" value="<?php echo htmlspecialchars($_POST['abbreviation'] ?? ''); ?>" maxlength="6" required>
                    <p class="helper-text">This will be used in participant IDs. 3-6 characters.</p>
                </div>

                <div class="form-group">
                    <label class="form-label">Country of your site <span class="required">*</span></label>
                    <select name="country" class="form-input" required>
                        <option value="">Please select</option>
                        <option value="India" selected>India</option>                        
                        <option value="United States">United States</option>
                        <option value="United Kingdom">United Kingdom</option>
                        <option value="Canada">Canada</option>
                        <option value="Germany">Germany</option>
                        <option value="France">France</option>
                        <!-- Add more as needed -->
                    </select>
                </div>
            </div>

            <!-- Templates (Admin Only) -->
            <?php if ($is_admin): ?>
            <div class="form-section">
                <label class="form-label">Study Protocol Template</label>
                <div style="margin-bottom: 0.75rem; color: var(--text-light); font-size: 0.85rem;">
                    Pre-configures clinical visits (Screening, Day 1, Day 2, Day 3, End of Treatment), standard forms, RECIST 1.1, lab panels, and skip logic.
                </div>
                
                <div class="radio-group">
                    <label class="radio-item" style="padding: 0.5rem; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; cursor: pointer;">
                        <input type="radio" name="template" value="oncology_protocol" checked>
                        <span>
                            <strong style="color: #166534;">Standard Protocol (Visits & Forms Specification)</strong>
                            <div style="font-size: 0.75rem; color: #15803d;">Includes Screening, Day 1, Day 2, Day 3, End of Treatment / Study with structured forms & skip logic</div>
                        </span>
                    </label>
                    <label class="radio-item" style="padding: 0.5rem; border-radius: 6px; cursor: pointer;">
                        <input type="radio" name="template" value="none">
                        <span>
                            <strong>Blank Study</strong>
                            <div style="font-size: 0.75rem; color: var(--text-light);">Start with empty visits and build forms manually</div>
                        </span>
                    </label>
                </div>
            </div>
            <?php else: ?>
            <input type="hidden" name="template" value="none">
            <?php endif; ?>

            <!-- Study Type (Hidden - Default: Test)
            <div class="form-section">
                <label class="form-label">Study type <span class="required">*</span></label>
                <div style="margin-bottom: 1rem; color: var(--text-main); font-size: 0.875rem;">
                   This will make your studies easy to identify and organise. If you are using this study for testing or learning how it works, choose the test type.
                </div>
                
                <div class="radio-group">
                    <label class="radio-item"><input type="radio" name="type" value="production"> Production</label>
                    <label class="radio-item"><input type="radio" name="type" value="test" checked> Test</label>
                    <label class="radio-item"><input type="radio" name="type" value="example"> Example</label>
                </div>
            </div>
            -->
            <div style="display: flex; gap: 1rem; margin-top: 2rem;">
                <button type="submit" class="btn btn-primary">Create Study</button>
                <a href="dashboard.php" class="btn btn-outline" style="background: white;">Cancel</a>
            </div>

        </form>


    </div>
</div>
<script src="assets/js/app.js"></script>
</body>
</html>
