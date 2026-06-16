<?php
require_once 'includes/functions.php';
require_once 'includes/auth.php';

requireLogin();
$pdo = getDB();

if (!isset($_SESSION['active_study_id'])) {
    redirect('dashboard.php');
}

if (!hasPermission('add_subject') && !hasPermission('all')) {
    die("Unauthorized access. You do not have permission to add subjects.");
}

$study_id = $_SESSION['active_study_id'];
$study_name = $_SESSION['active_study_name'];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $subject_code = $_POST['subject_code'] ?? '';
    $site_name = $_POST['site_name'] ?? 'General Hospital'; // Default for now
    
    // Generate Subject ID logic
    $study_config_stmt = $pdo->prepare("SELECT participant_id_method, study_code FROM studies WHERE id = ?");
    $study_config_stmt->execute([$study_id]);
    $s_config = $study_config_stmt->fetch();
    $method = $s_config['participant_id_method'] ?? 'incremental';
    
    // If method is NOT free text, we generate it
    if ($method !== 'free_text') {
        // fetch site details for codes
        $site_stmt = $pdo->prepare("SELECT * FROM sites WHERE name = ? AND study_id = ?");
        $site_stmt->execute([$site_name, $study_id]);
        $site_data = $site_stmt->fetch();
        
        $country_code = getCountryCode($site_data['country'] ?? 'India', 2); // Default to IN if missing
        $site_code = $site_data['site_code'] ?? '01'; // Default
        $site_abbr = $site_data['abbreviation'] ?? 'SITE'; 
        
        // Count existing subjects to determine sequence
        // We need to count based on the pattern effectively, or just global count for this site/study
        // Simple incremental: Count in study + 1
        // Per site: Count in site + 1
        
        $count_sql = "SELECT COUNT(*) FROM subjects WHERE study_id = ?";
        $params = [$study_id];
        
        if (strpos($method, 'site') !== false || strpos($method, 'abbr') !== false) {
             $count_sql .= " AND site_name = ?";
             $params[] = $site_name;
        }
        
        $count_stmt = $pdo->prepare($count_sql);
        $count_stmt->execute($params);
        $seq = $count_stmt->fetchColumn() + 1;
        $seq_pad = str_pad($seq, 3, '0', STR_PAD_LEFT); // Default 3 digits
        
        $generated_code = '';
        
        switch ($method) {
            case 'incremental':
                $generated_code = str_pad($seq, 4, '0', STR_PAD_LEFT);
                break;
            case 'random':
                $generated_code = rand(100000, 999999);
                break;
            case 'incremental_site':
                $generated_code = $site_code . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);
                break;
            case 'country_site_2': // Country(2) - Site(2) - Seq(3) e.g. IN-01-001
                $generated_code = $country_code . '-' . substr($site_code, 0, 2) . '-' . str_pad($seq, 3, '0', STR_PAD_LEFT);
                break;
            case 'country_site_3': // Country(2) - Site(3) - Seq(3)
                $generated_code = $country_code . '-' . substr($site_code, 0, 3) . '-' . str_pad($seq, 3, '0', STR_PAD_LEFT);
                break;
            case 'country_abbr_2': // Country(2) - Abbr(2) - Seq(3)
                $generated_code = $country_code . '-' . substr($site_abbr, 0, 2) . '-' . str_pad($seq, 3, '0', STR_PAD_LEFT);
                break;
            default:
                $generated_code = str_pad($seq, 4, '0', STR_PAD_LEFT);
        }
        
        $subject_code = strtoupper($generated_code);
    }

    if (empty($subject_code)) {
        $error = "Subject ID is required";
    } else {
        $pdo = getDB();
        try {
            $stmt = $pdo->prepare("INSERT INTO subjects (study_id, subject_code, site_name, status, created_by) VALUES (:sid, :code, :site, 'Screening', :uid)");
            $stmt->execute([
                'sid' => $study_id,
                'code' => $subject_code,
                'site' => $site_name,
                'uid' => $_SESSION['user_id']
            ]);
            redirect('subjects_list.php');
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) { // Duplicate entry
                $error = "Subject ID $subject_code already exists. Please try again.";
            } else {
                $error = "Database Error: " . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Subject - <?php echo htmlspecialchars($study_name); ?></title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
</head>
<body>

<div class="app-layout">
    <?php include 'includes/sidebar.php'; ?>
    <main class="main-content">
        <header class="top-nav">
             <h2 style="font-size: 1.125rem;">Add New Subject</h2>
        </header>

        <div class="page-content">
            <div class="container" style="max-width: 600px; margin: 0;">
                
                <?php if ($error): ?>
                <div class="alert alert-danger">
                    <?php echo sanitizeInput($error); ?>
                </div>
                <?php endif; ?>

                <div class="card">
                    <form method="POST" action="subject_entry.php">
                        <div class="form-group">
                            <label class="form-label">Study Name</label>
                            <input type="text" class="form-input" value="<?php echo htmlspecialchars($study_name); ?>" disabled style="background: #f1f5f9;">
                        </div>

                        <?php
                            // Fetch method for UI
                            $pdo = getDB();
                            $stmt = $pdo->prepare("SELECT participant_id_method FROM studies WHERE id = ?");
                            $stmt->execute([$study_id]);
                            $method = $stmt->fetchColumn() ?: 'incremental';
                            $is_auto = ($method !== 'free_text');
                        ?>
                        <div class="form-group">
                            <label class="form-label">Subject ID / Screening Number</label>
                            <?php if ($is_auto): ?>
                                <input type="text" name="subject_code" class="form-input" placeholder="(Auto-generated)" disabled style="background: #f1f5f9; cursor: not-allowed;">
                                <input type="hidden" name="subject_code_dummy" value="auto"> 
                                <!-- Note: We handle generation in POST even if this is empty, but the post handler checks $_POST['subject_code']. 
                                     If disabled, it won't be sent. We need to ensure the POST handler logic (which I just added) runs. 
                                     The POST handler checks if method != free_text, so it ignores $_POST['subject_code'] mostly, EXCEPT for the initial check.
                                     Wait, my previous code: ` $subject_code = $_POST['subject_code'] ?? ''; ... if ($method !== 'free_text') { ... $subject_code = ... }`
                                     So if I don't send it, it defaults to empty, then gets overwritten. Perfect.
                                -->
                                <p style="font-size: 0.75rem; color: var(--accent-color); margin-top: 0.25rem;">
                                    <span class="material-icons-round" style="font-size: 10px; vertical-align: middle;">auto_awesome</span>
                                    ID will be generated automatically.
                                </p>
                            <?php else: ?>
                                <input type="text" name="subject_code" class="form-input" placeholder="e.g. S-001" required autofocus>
                                <p style="font-size: 0.75rem; color: var(--text-light); margin-top: 0.25rem;">Must be unique within the study.</p>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Site Name</label>
                            <select name="site_name" class="form-input" required>
                                <?php
                                $u_id = $_SESSION['user_id'];
                                // Check restrictions
                                $res_stmt = $pdo->prepare("SELECT site_id FROM study_user_sites WHERE user_id = ? AND study_id = ?");
                                $res_stmt->execute([$u_id, $study_id]);
                                $res_ids = $res_stmt->fetchAll(PDO::FETCH_COLUMN);

                                $s_sql = "SELECT name FROM sites WHERE study_id = ?";
                                $s_params = [$study_id];

                                if (!empty($res_ids)) {
                                    $ph = implode(',', array_fill(0, count($res_ids), '?'));
                                    $s_sql .= " AND id IN ($ph)";
                                    $s_params = array_merge([$study_id], $res_ids);
                                }
                                
                                $s_sql .= " ORDER BY name";
                                $site_opt_stmt = $pdo->prepare($s_sql);
                                $site_opt_stmt->execute($s_params);
                                $site_opts = $site_opt_stmt->fetchAll(PDO::FETCH_COLUMN);
                                
                                foreach ($site_opts as $sname): ?>
                                    <option value="<?php echo htmlspecialchars($sname); ?>"><?php echo htmlspecialchars($sname); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div style="margin-top: 2rem; display: flex; gap: 1rem;">
                            <button type="submit" class="btn btn-primary">Create Subject</button>
                            <a href="subjects_list.php" class="btn btn-outline">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </main>
</div>
<script src="assets/js/app.js"></script>
</body>
</html>
