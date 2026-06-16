<?php
require_once 'includes/functions.php';
require_once 'includes/auth.php';

requireLogin();

if (!isset($_SESSION['active_study_id'])) {
    redirect('dashboard.php');
}

if (!hasPermission('all')) {
    die("Unauthorized access: Study Configuration is restricted to Administrators.");
}

$study_id = $_SESSION['active_study_id'];
$message = '';
$pdo = getDB();

require_once 'includes/id_generation.php';

// Handle Save
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $main_contact = $_POST['main_contact'] ?? '';
    // Enable Registry ID editing
    $registry_id = $_POST['trial_registry_id'] ?? '';
    
    $sponsor = $_POST['sponsor'] ?? '';
    $design = $_POST['study_design'] ?? '';
    
    // New Fields
    $category = $_POST['study_category'] ?? '';
    $approval_type = $_POST['approval_study_type'] ?? '';
    $therapeutic = $_POST['therapeutic_area'] ?? '';

    $randomization = isset($_POST['randomization']);
    $monitoring = isset($_POST['monitoring']);
    $surveys = isset($_POST['surveys']);
    $gcp_reason = isset($_POST['gcp_reason']);
    $status = $_POST['status'] ?? 'design';
    $id_method = $_POST['participant_id_method'] ?? 'incremental';

    // 1. Check if ID method changed
    $stmt_curr = $pdo->prepare("SELECT participant_id_method FROM studies WHERE id = ?");
    $stmt_curr->execute([$study_id]);
    $current_method = $stmt_curr->fetchColumn();

    $stmt = $pdo->prepare("UPDATE studies SET 
        main_contact = ?, trial_registry_id = ?, sponsor = ?, study_design = ?, 
        study_category = ?, approval_study_type = ?, therapeutic_area = ?,
        randomization_enabled = ?, monitoring_enabled = ?, surveys_enabled = ?, 
        gcp_reason_required = ?, status = ?, participant_id_method = ?
        WHERE id = ?");
    
    if($stmt->execute([
        $main_contact, $registry_id, $sponsor, $design, 
        $category, $approval_type, $therapeutic,
        $randomization, $monitoring, $surveys, $gcp_reason, $status, $id_method, $study_id
    ])) {
        $message = "Study configuration updated successfully.";
        
        // 2. Trigger Migration if ID method changed
        if ($id_method !== $current_method) {
            if (regenerateStudySubjectIDs($pdo, $study_id)) {
                $message .= " Subject IDs were automatically updated to the new format.";
            } else {
                $message .= " (Note: ID update skipped for free text method)";
            }
        }

    } else {
        $message = "Error updating configuration.";
    }
}

// Fetch Study Data
$stmt = $pdo->prepare("SELECT * FROM studies WHERE id = ?");
$stmt->execute([$study_id]);
$study = $stmt->fetch();

// Fetch Site Count
$stmt = $pdo->prepare("SELECT COUNT(*) FROM sites WHERE study_id = ?");
$stmt->execute([$study_id]);
$site_count = $stmt->fetchColumn();

// --- DATA LISTS ---
$therapeutic_areas = [
    "Allergy/Immunology", "Anesthesiology", "Cardiology/Vascular Diseases", "Dermatology", 
    "Endocrinology", "Gastroenterology", "Genetic/Inherited Diseases", "Haematology", 
    "Hepatology", "Infectious Diseases", "Internal Medicine", "Nephrology", "Neurology", 
    "Obstetrics/Gynecology", "Oncology", "Ophthalmology", "Orthopedics", "Otolaryngology", 
    "Pediatrics", "Pharmacology/Toxicology", "Psychiatry/Psychology", "Pulmonary/Respiratory Diseases", 
    "Rheumatology", "Trauma/Emergency Medicine", "Urology", "Vaccines", "Other"
];

$approval_types = [
    "Phase I", "Phase I/II", "Phase II", "Phase II/III", "Phase III", "Phase III/IV", "Phase IV", 
    "Bioequivalence", "Observational", "Registry", "Device Feasibility", "Pivotal", "Post-marketing", "Not Applicable"
];

$study_categories = [
    "Interventional", "Observational", "Expanded Access", "Patient Registry", "Basic Science", "Health Services Research", "Other"
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Settings - <?php echo htmlspecialchars($study['name']); ?></title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
</head>
<body>

<div class="app-layout">
    <?php include 'includes/sidebar.php'; ?>

    <main class="main-content">
        <header class="top-nav">
             <div>
                <!-- Dynamic Header as requested: Site Name + Status -->
                <h2 style="font-size: 1.125rem;"><?php echo htmlspecialchars($study['name']); ?></h2>
                <div style="font-size: 0.75rem; color: var(--text-light); display: flex; align-items: center; gap: 0.5rem; margin-top: 0.25rem;">
                    <?php echo htmlspecialchars($study['study_code']); ?>
                    <span style="width: 4px; height: 4px; border-radius: 50%; background: var(--text-light);"></span>
                    <span style="<?php echo $study['status'] == 'live' ? 'color: var(--success-color);' : ''; ?>">
                        <?php echo $study['status'] == 'live' ? 'Live' : 'Not Live'; ?>
                    </span>
                    
                    <span style="margin-left: 1rem; font-size: 0.75rem; color: var(--text-light); text-transform: uppercase;">Viewing as:</span>
                    <?php renderRoleSwitcher($_SESSION['active_study_id']); ?>
                </div>
            </div>
             <div style="display: flex; align-items: center; gap: 0.5rem;">
                <span style="font-weight: 500; font-size: 0.875rem;"><?php echo htmlspecialchars($_SESSION['username']); ?></span>
                <a href="logout.php" style="font-size: 0.875rem; color: var(--text-light);">Logout</a>
            </div>
        </header>

        <div class="page-content">
            <div class="container" style="max-width: 900px; margin: 0;">
                
                <?php if ($message): ?>
                <div class="alert" style="background: #dcfce7; color: #166534; border: 1px solid #bbf7d0;">
                    <?php echo sanitizeInput($message); ?>
                </div>
                <?php endif; ?>

                <div class="tabs">
                    <button type="button" class="tab-link active" data-tab="general">General</button>
                    <button type="button" class="tab-link" data-tab="properties">Study properties</button>
                    <button type="button" class="tab-link" data-tab="gcp">Good clinical practice</button>
                    <button type="button" class="tab-link" data-tab="other">Other</button>
                </div>

                <form method="POST" class="card">
                    
                    <!-- General Tab -->
                    <div id="general" class="tab-content active">
                        <div class="form-group">
                            <label class="form-label">Study name <span style="color:red">*</span></label>
                            <input type="text" class="form-input" value="<?php echo htmlspecialchars($study['name']); ?>" disabled style="background: #f1f5f9;">
                            <p style="font-size: 0.75rem; color: var(--text-light);">Contact support to change study name.</p>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Trial registry ID</label>
                            <!-- Enabled editing as requested -->
                            <input type="text" name="trial_registry_id" class="form-input" value="<?php echo htmlspecialchars($study['trial_registry_id']); ?>" placeholder="NCT12345678">
                        </div>

                        <div class="form-group">
                            <label class="form-label" style="display: block; margin-bottom: 0.5rem;">Multicenter study</label>
                            <div style="font-size: 1rem; margin-bottom: 0.5rem;">
                                <?php echo $site_count; ?> sites participating
                            </div>
                            <a href="manage_sites.php" class="btn btn-outline" style="display: inline-flex; color: var(--accent-color); border-color: var(--border-color);">
                                Manage sites
                            </a>
                        </div>

                        <div class="form-group">
                             <!-- Moved Searchable Fields here based on user screenshot flow -->
                            <label class="form-label">Study category</label>
                            <select name="study_category" class="form-input">
                                <option value="">Please select</option>
                                <?php foreach($study_categories as $opt): ?>
                                    <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo ($study['study_category'] ?? '') == $opt ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($opt); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                         <div class="form-group">
                            <label class="form-label">Approval study</label>
                            <select name="approval_study_type" class="form-input">
                                <option value="">Please select</option>
                                <?php foreach($approval_types as $opt): ?>
                                    <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo ($study['approval_study_type'] ?? '') == $opt ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($opt); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                         <div class="form-group">
                            <label class="form-label">Therapeutic area</label>
                            <select name="therapeutic_area" class="form-input">
                                <option value="">Please select</option>
                                <?php foreach($therapeutic_areas as $opt): ?>
                                    <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo ($study['therapeutic_area'] ?? '') == $opt ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($opt); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Main contact</label>
                            <input type="text" name="main_contact" class="form-input" value="<?php echo htmlspecialchars($study['main_contact'] ?? ''); ?>" placeholder="Name (email@example.com)">
                        </div>
                        
                        <!-- Rest of General Tab items (Type, Status) -->

                        <div class="form-group">
                            <label class="form-label">Type of study</label>
                            <div style="display: flex; gap: 1rem; flex-direction: column;">
                                <label style="display: flex; gap: 0.5rem; align-items: center;">
                                    <input type="radio" name="type" value="production" <?php echo $study['type'] == 'production' ? 'checked' : ''; ?> disabled>
                                    <div>
                                        <strong>Production</strong><br>
                                        <span style="font-size: 0.8rem; color: var(--text-light);">for real participant data</span>
                                    </div>
                                </label>
                                <label style="display: flex; gap: 0.5rem; align-items: center;">
                                    <input type="radio" name="type" value="test" <?php echo $study['type'] == 'test' ? 'checked' : ''; ?> disabled>
                                    <div>
                                        <strong>Test</strong><br>
                                        <span style="font-size: 0.8rem; color: var(--text-light);">to try study structures</span>
                                    </div>
                                </label>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Status <span style="color:red">*</span></label>
                            <div style="display: flex; gap: 1.5rem;">
                                <label style="display: flex; gap: 0.5rem; align-items: center;">
                                    <input type="radio" name="status" value="live" <?php echo $study['status'] == 'live' ? 'checked' : ''; ?>>
                                    Live
                                </label>
                                <label style="display: flex; gap: 0.5rem; align-items: center;">
                                    <input type="radio" name="status" value="design" <?php echo $study['status'] != 'live' ? 'checked' : ''; ?>>
                                    Not Live
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Properties Tab -->
                    <div id="properties" class="tab-content">
                         <div class="form-group">
                            <label class="form-label">Sponsor (GCP)</label>
                            <input type="text" name="sponsor" class="form-input" value="<?php echo htmlspecialchars($study['sponsor'] ?? ''); ?>" placeholder="Institution name">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Study design</label>
                            <select name="study_design" class="form-input">
                                <option value="">Please select</option>
                                <option value="prospective" <?php echo ($study['study_design'] ?? '') == 'prospective' ? 'selected' : ''; ?>>Prospective</option>
                                <option value="retrospective" <?php echo ($study['study_design'] ?? '') == 'retrospective' ? 'selected' : ''; ?>>Retrospective</option>
                                <option value="registry" <?php echo ($study['study_design'] ?? '') == 'registry' ? 'selected' : ''; ?>>Registry</option>
                                <option value="other" <?php echo ($study['study_design'] ?? '') == 'other' ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>

                        <h4 class="form-section-title" style="margin-top: 2rem;">Features</h4>
                        
                        <div class="checkbox-group">
                            <input type="checkbox" id="rand" name="randomization" <?php echo ($study['randomization_enabled'] ?? 0) ? 'checked' : ''; ?>>
                            <div>
                                <label for="rand" style="font-weight: 500;">Randomization</label>
                                <div style="font-size: 0.8rem; color: var(--text-light);">Weighted variable block randomization.</div>
                            </div>
                        </div>

                        <div class="checkbox-group">
                            <input type="checkbox" id="mon" name="monitoring" <?php echo ($study['monitoring_enabled'] ?? 1) ? 'checked' : ''; ?>>
                            <div>
                                <label for="mon" style="font-weight: 500;">Monitoring</label>
                                <div style="font-size: 0.8rem; color: var(--text-light);">Enable tools for monitoring and queries.</div>
                            </div>
                        </div>

                        <!-- Surveys Removed -->
                    </div>

                    <!-- GCP Tab -->
                    <div id="gcp" class="tab-content">
                        <div class="form-group">
                            <label class="form-label">Amending study visits and data</label>
                            <div class="checkbox-group">
                                <input type="checkbox" id="gcpr" name="gcp_reason" <?php echo ($study['gcp_reason_required'] ?? 1) ? 'checked' : ''; ?>>
                                <div>
                                    <label for="gcpr" style="font-weight: 500; color: var(--text-main);">Require a 'reason for change' for each and every field edited</label>
                                </div>
                            </div>
                        </div>

                         <div style="background: #fffbeb; padding: 1rem; border: 1px solid #fcd34d; border-radius: 0.5rem; margin-top: 1rem;">
                            <h4 style="margin: 0 0 0.5rem 0; font-size: 0.9rem; color: #92400e;">GCP Compliance Note</h4>
                            <p style="margin: 0; font-size: 0.8rem; color: #b45309;">
                                Enabling 'Reason for Change' is required for 21 CFR Part 11 compliance. Disabling this may compromise audit trails.
                            </p>
                        </div>
                    </div>

                    <!-- Other Tab -->
                    <div id="other" class="tab-content">
                        <div class="form-group">
                            <label class="form-label">Generate participant IDs with</label>
                            <select name="participant_id_method" class="form-input">
                                <option value="incremental" <?php echo ($study['participant_id_method'] ?? '') == 'incremental' ? 'selected' : ''; ?>>Incremental (e.g. 1001)</option>
                                <option value="random" <?php echo ($study['participant_id_method'] ?? '') == 'random' ? 'selected' : ''; ?>>Random number</option>
                                <option value="free_text" <?php echo ($study['participant_id_method'] ?? '') == 'free_text' ? 'selected' : ''; ?>>Patient Study ID (free text)</option>
                                <option value="incremental_site" <?php echo ($study['participant_id_method'] ?? '') == 'incremental_site' ? 'selected' : ''; ?>>Incremental per site (e.g. 01-1001)</option>
                                <optgroup label="Advanced (Country & Site Codes)">
                                    <option value="country_site_2" <?php echo ($study['participant_id_method'] ?? '') == 'country_site_2' ? 'selected' : ''; ?>>Country(2) - Site(2) - Seq (e.g. IN-01-001)</option>
                                    <option value="country_site_3" <?php echo ($study['participant_id_method'] ?? '') == 'country_site_3' ? 'selected' : ''; ?>>Country(2) - Site(3) - Seq (e.g. IN-001-001)</option>
                                    <option value="country_abbr_2" <?php echo ($study['participant_id_method'] ?? '') == 'country_abbr_2' ? 'selected' : ''; ?>>Country(2) - Abbr(2) - Seq (e.g. IN-GH-001)</option>
                                </optgroup>
                            </select>
                        </div>
                    </div>

                    <!-- Buttons -->
                    <div style="margin-top: 2rem; padding-top: 1.5rem; border-top: 1px solid var(--border-color); display: flex; justify-content: flex-end;">
                        <button type="submit" class="btn btn-primary">Save Settings</button>
                    </div>

                </form>
            </div>
        </div>
    </main>
</div>
<script src="assets/js/app.js"></script>
</body>
</html>
