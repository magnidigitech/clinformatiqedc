<?php
require_once 'includes/functions.php';
require_once 'includes/auth.php';
require_once 'includes/id_generation.php';

requireLogin();
$pdo = getDB();

if (!isset($_SESSION['active_study_id'])) {
    redirect('dashboard.php');
}

if (!hasPermission('add_subject')) {
    die("Unauthorized access. You do not have permission to add subjects.");
}

$study_id = $_SESSION['active_study_id'];
$study_name = $_SESSION['active_study_name'];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $site_name = $_POST['site_name'] ?? 'General Hospital'; // Default for now
    
    // Generate Subject ID logic
    $study_config_stmt = $pdo->prepare("SELECT participant_id_method, study_code FROM studies WHERE id = ?");
    $study_config_stmt->execute([$study_id]);
    $s_config = $study_config_stmt->fetch();
    $method = $s_config['participant_id_method'] ?? 'incremental';
    
    // If method is NOT free text, generate next available unique ID that fills gaps and prevents duplicates
    if ($method !== 'free_text') {
        $subject_code = getNextAvailableSubjectCode($pdo, $study_id, $site_name);
    } else {
        $subject_code = strtoupper(trim($_POST['subject_code'] ?? ''));
    }

    if (empty($subject_code)) {
        $error = "Subject ID is required";
    } else {
        // Enforce uniqueness
        $chk = $pdo->prepare("SELECT id FROM subjects WHERE study_id = ? AND UPPER(subject_code) = UPPER(?)");
        $chk->execute([$study_id, $subject_code]);
        if ($chk->fetch()) {
            $error = "Subject ID $subject_code already exists in this study. Please use a unique ID.";
        } else {
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
