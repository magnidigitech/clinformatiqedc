<?php
/**
 * Clinformatiq EDC - Study & Subject Management Tool
 * Allows administrative deletion of subjects and studies with complete cascading cleanup.
 */
session_start();
require_once __DIR__ . '/config/db.php';

$pdo = getDB();

// Authentication Configuration
define('ADMIN_PASSWORD', 'Clinformatiq@3939');

// Logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    unset($_SESSION['managestudy_auth']);
    header('Location: managestudy.php');
    exit;
}

// Handle Login POST
$login_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_action'])) {
    $submitted_pass = $_POST['password'] ?? '';
    if ($submitted_pass === ADMIN_PASSWORD) {
        $_SESSION['managestudy_auth'] = true;
        header('Location: managestudy.php');
        exit;
    } else {
        $login_error = 'Invalid administrative password. Please try again.';
    }
}

// Authentication Check
$is_authenticated = !empty($_SESSION['managestudy_auth']) && $_SESSION['managestudy_auth'] === true;

// If not authenticated, render Login Page
if (!$is_authenticated) {
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Studies & Subjects - Authentication Required</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
    <style>
        :root {
            --primary: #1d6f97;
            --primary-hover: #155776;
            --bg-color: #f8fafc;
            --text-dark: #1e293b;
            --text-muted: #64748b;
            --border-color: #e2e8f0;
            --danger: #ef4444;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            color: var(--text-dark);
        }
        .login-card {
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.25), 0 8px 10px -6px rgba(0, 0, 0, 0.2);
            width: 100%;
            max-width: 440px;
            padding: 2.5rem;
            text-align: center;
        }
        .brand-icon {
            width: 56px;
            height: 56px;
            background: #e0f2fe;
            color: var(--primary);
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1.25rem;
        }
        .brand-icon span { font-size: 32px; }
        h1 {
            font-size: 1.45rem;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 0.5rem;
        }
        p.subtitle {
            font-size: 0.9rem;
            color: var(--text-muted);
            margin-bottom: 2rem;
            line-height: 1.5;
        }
        .form-group {
            text-align: left;
            margin-bottom: 1.5rem;
        }
        .form-group label {
            display: block;
            font-size: 0.85rem;
            font-weight: 600;
            color: #334155;
            margin-bottom: 0.5rem;
        }
        .input-wrapper {
            position: relative;
        }
        .input-wrapper span.icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 20px;
        }
        .form-input {
            width: 100%;
            padding: 0.75rem 1rem 0.75rem 2.6rem;
            font-size: 0.95rem;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            outline: none;
            transition: all 0.2s ease;
        }
        .form-input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(29, 111, 151, 0.15);
        }
        .btn-submit {
            width: 100%;
            padding: 0.8rem 1.25rem;
            background: var(--primary);
            color: #ffffff;
            font-size: 0.95rem;
            font-weight: 600;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: background 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        .btn-submit:hover { background: var(--primary-hover); }
        .alert-error {
            background: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fecaca;
            padding: 0.75rem 1rem;
            border-radius: 8px;
            font-size: 0.875rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            text-align: left;
        }
        .footer-note {
            margin-top: 2rem;
            font-size: 0.8rem;
            color: #94a3b8;
        }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="brand-icon">
            <span class="material-icons-round">admin_panel_settings</span>
        </div>
        <h1>Study & Subject Administration</h1>
        <p class="subtitle">Enter the authorized administrative password to access the study and subject management console.</p>

        <?php if ($login_error): ?>
            <div class="alert-error">
                <span class="material-icons-round" style="font-size: 20px;">error</span>
                <span><?php echo htmlspecialchars($login_error); ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" action="managestudy.php">
            <input type="hidden" name="login_action" value="1">
            <div class="form-group">
                <label for="password">Administrative Password</label>
                <div class="input-wrapper">
                    <span class="icon material-icons-round">lock</span>
                    <input type="password" id="password" name="password" class="form-input" placeholder="Enter password" required autofocus>
                </div>
            </div>
            <button type="submit" class="btn-submit">
                <span>Unlock Console</span>
                <span class="material-icons-round">arrow_forward</span>
            </button>
        </form>

        <div class="footer-note">
            Clinformatiq EDC &bull; Secure Administration
        </div>
    </div>
</body>
</html>
<?php
    exit;
}

// ------------------------------------------------------------------------------------------------
// Authenticated Administration Section
// ------------------------------------------------------------------------------------------------

$flash_success = '';
$flash_error = '';

/**
 * Helper: Delete a single Subject and all its clinical data
 */
function deleteSubjectCascade($pdo, $subject_id) {
    // 1. Delete data query history
    $pdo->prepare("DELETE FROM data_query_history WHERE query_id IN (SELECT id FROM data_queries WHERE subject_id = ?)")->execute([$subject_id]);
    // 2. Delete data queries
    $pdo->prepare("DELETE FROM data_queries WHERE subject_id = ?")->execute([$subject_id]);
    // 3. Delete comments
    $pdo->prepare("DELETE FROM data_comments WHERE subject_id = ?")->execute([$subject_id]);
    // 4. Delete audit log
    $pdo->prepare("DELETE FROM data_audit_log WHERE subject_id = ?")->execute([$subject_id]);
    // 5. Delete subject form data
    $pdo->prepare("DELETE FROM subject_data WHERE subject_id = ?")->execute([$subject_id]);
    // 6. Delete form statuses
    $pdo->prepare("DELETE FROM subject_form_status WHERE subject_id = ?")->execute([$subject_id]);
    
    // 7. Common Form Queries & History
    $pdo->prepare("DELETE FROM common_form_query_history WHERE query_id IN (SELECT id FROM common_form_queries WHERE subject_id = ?)")->execute([$subject_id]);
    $pdo->prepare("DELETE FROM common_form_queries WHERE subject_id = ?")->execute([$subject_id]);
    
    // 8. Common Form Links & Audit & SDR History
    $pdo->prepare("DELETE FROM common_form_sdr_history WHERE record_id IN (SELECT id FROM subject_common_records WHERE subject_id = ?)")->execute([$subject_id]);
    $pdo->prepare("DELETE FROM common_form_audit_log WHERE record_id IN (SELECT id FROM subject_common_records WHERE subject_id = ?)")->execute([$subject_id]);
    $pdo->prepare("DELETE FROM cm_record_links WHERE cm_record_id IN (SELECT id FROM subject_common_records WHERE subject_id = ?) OR target_record_id IN (SELECT id FROM subject_common_records WHERE subject_id = ?)")->execute([$subject_id, $subject_id]);
    
    // 9. Common Form Records
    $pdo->prepare("DELETE FROM subject_common_records WHERE subject_id = ?")->execute([$subject_id]);
    
    // 10. Repeating Instances
    $pdo->prepare("DELETE FROM subject_repeating_instances WHERE subject_id = ?")->execute([$subject_id]);
    
    // 11. Delete Subject record itself
    $stmt_del = $pdo->prepare("DELETE FROM subjects WHERE id = ?");
    $stmt_del->execute([$subject_id]);
    return $stmt_del->rowCount();
}

/**
 * Helper: Delete an entire Study and all associated subjects, structure, visits, forms, and data
 */
function deleteStudyCascade($pdo, $study_id) {
    // 1. Get all subjects in this study
    $stmt_subs = $pdo->prepare("SELECT id FROM subjects WHERE study_id = ?");
    $stmt_subs->execute([$study_id]);
    $subject_ids = $stmt_subs->fetchAll(PDO::FETCH_COLUMN);

    // Delete each subject and all its dependencies
    foreach ($subject_ids as $sid) {
        deleteSubjectCascade($pdo, $sid);
    }

    // 2. Clean up any remaining study-level records
    $pdo->prepare("DELETE FROM data_query_history WHERE query_id IN (SELECT id FROM data_queries WHERE study_id = ?)")->execute([$study_id]);
    $pdo->prepare("DELETE FROM data_queries WHERE study_id = ?")->execute([$study_id]);
    $pdo->prepare("DELETE FROM data_comments WHERE study_id = ?")->execute([$study_id]);
    $pdo->prepare("DELETE FROM data_audit_log WHERE study_id = ?")->execute([$study_id]);
    $pdo->prepare("DELETE FROM subject_data WHERE study_id = ?")->execute([$study_id]);
    $pdo->prepare("DELETE FROM subject_form_status WHERE study_id = ?")->execute([$study_id]);
    
    $pdo->prepare("DELETE FROM common_form_query_history WHERE query_id IN (SELECT id FROM common_form_queries WHERE study_id = ?)")->execute([$study_id]);
    $pdo->prepare("DELETE FROM common_form_queries WHERE study_id = ?")->execute([$study_id]);
    
    // SDR history & CM links link to subject_common_records.id
    $pdo->prepare("DELETE FROM cm_record_links WHERE cm_record_id IN (SELECT id FROM subject_common_records WHERE study_id = ?) OR target_record_id IN (SELECT id FROM subject_common_records WHERE study_id = ?)")->execute([$study_id, $study_id]);
    $pdo->prepare("DELETE FROM common_form_sdr_history WHERE record_id IN (SELECT id FROM subject_common_records WHERE study_id = ?)")->execute([$study_id]);
    $pdo->prepare("DELETE FROM common_form_audit_log WHERE study_id = ?")->execute([$study_id]);
    $pdo->prepare("DELETE FROM subject_common_records WHERE study_id = ?")->execute([$study_id]);
    $pdo->prepare("DELETE FROM subject_repeating_instances WHERE study_id = ?")->execute([$study_id]);
    $pdo->prepare("DELETE FROM subjects WHERE study_id = ?")->execute([$study_id]);

    // 3. Form fields
    $pdo->prepare("DELETE FROM form_fields WHERE form_id IN (
        SELECT id FROM study_forms WHERE 
        visit_id IN (SELECT id FROM study_visits WHERE study_id = ?) 
        OR repeating_module_id IN (SELECT id FROM study_repeating_modules WHERE study_id = ?)
    )")->execute([$study_id, $study_id]);

    // 4. Option choices and groups
    $pdo->prepare("DELETE FROM option_choices WHERE group_id IN (SELECT id FROM option_groups WHERE study_id = ?)")->execute([$study_id]);
    $pdo->prepare("DELETE FROM option_groups WHERE study_id = ?")->execute([$study_id]);

    // 5. Forms, Modules, Visits
    $pdo->prepare("DELETE FROM study_forms WHERE 
        visit_id IN (SELECT id FROM study_visits WHERE study_id = ?) 
        OR repeating_module_id IN (SELECT id FROM study_repeating_modules WHERE study_id = ?)")->execute([$study_id, $study_id]);
    $pdo->prepare("DELETE FROM study_repeating_modules WHERE study_id = ?")->execute([$study_id]);
    $pdo->prepare("DELETE FROM study_visits WHERE study_id = ?")->execute([$study_id]);

    // 6. User assignments & Sites
    $pdo->prepare("DELETE FROM study_user_sites WHERE study_id = ?")->execute([$study_id]);
    $pdo->prepare("DELETE FROM study_users WHERE study_id = ?")->execute([$study_id]);
    $pdo->prepare("DELETE FROM sites WHERE study_id = ?")->execute([$study_id]);

    // 7. Delete the study record
    $stmt_del = $pdo->prepare("DELETE FROM studies WHERE id = ?");
    $stmt_del->execute([$study_id]);
    return $stmt_del->rowCount();
}

// Handle Subject Deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_subject') {
    $del_subject_id = (int)($_POST['subject_id'] ?? 0);
    $confirm_name = trim($_POST['confirm_subject_code'] ?? '');
    
    // Verify subject exists
    $stmt = $pdo->prepare("SELECT subject_code FROM subjects WHERE id = ?");
    $stmt->execute([$del_subject_id]);
    $sub_row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$sub_row) {
        $flash_error = "Subject ID #{$del_subject_id} not found.";
    } else {
        $expected_code = $sub_row['subject_code'];
        try {
            $pdo->beginTransaction();
            deleteSubjectCascade($pdo, $del_subject_id);
            $pdo->commit();
            $flash_success = "Subject <strong>" . htmlspecialchars($expected_code) . "</strong> and all associated clinical forms, visits, common forms, queries, and audit logs were permanently deleted.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $flash_error = "Failed to delete subject: " . $e->getMessage();
        }
    }
}

// Handle Study Deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_study') {
    $del_study_id = (int)($_POST['study_id'] ?? 0);
    
    // Verify study exists
    $stmt = $pdo->prepare("SELECT name, study_code FROM studies WHERE id = ?");
    $stmt->execute([$del_study_id]);
    $study_row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$study_row) {
        $flash_error = "Study ID #{$del_study_id} not found.";
    } else {
        $study_name = $study_row['study_code'] . ' - ' . $study_row['name'];
        try {
            $pdo->beginTransaction();
            deleteStudyCascade($pdo, $del_study_id);
            $pdo->commit();
            $flash_success = "Study <strong>" . htmlspecialchars($study_name) . "</strong>, along with all subjects, visits, forms, clinical data, and configurations, was permanently deleted.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $flash_error = "Failed to delete study: " . $e->getMessage();
        }
    }
}

// Fetch All Studies with Subject Count and Sites Count
$stmt_studies = $pdo->query("
    SELECT s.*, 
           (SELECT COUNT(*) FROM subjects WHERE study_id = s.id) as subjects_count,
           (SELECT COUNT(*) FROM sites WHERE study_id = s.id) as sites_count,
           (SELECT COUNT(*) FROM study_visits WHERE study_id = s.id) as visits_count
    FROM studies s 
    ORDER BY s.id DESC
");
$studies = $stmt_studies->fetchAll(PDO::FETCH_ASSOC);

// Fetch All Subjects with Study Info
$filter_study_id = (int)($_GET['study_id'] ?? 0);
$search_query = trim($_GET['q'] ?? '');

$sub_sql = "
    SELECT sub.*, s.study_code, s.name as study_title,
           (SELECT COUNT(*) FROM subject_data WHERE subject_id = sub.id) as data_count,
           (SELECT COUNT(*) FROM data_queries WHERE subject_id = sub.id) as query_count
    FROM subjects sub
    JOIN studies s ON sub.study_id = s.id
    WHERE 1=1
";
$sub_params = [];

if ($filter_study_id > 0) {
    $sub_sql .= " AND sub.study_id = ?";
    $sub_params[] = $filter_study_id;
}
if ($search_query !== '') {
    $sub_sql .= " AND (sub.subject_code ILIKE ? OR sub.site_name ILIKE ?)";
    $sub_params[] = '%' . $search_query . '%';
    $sub_params[] = '%' . $search_query . '%';
}
$sub_sql .= " ORDER BY sub.id DESC";

$stmt_subs = $pdo->prepare($sub_sql);
$stmt_subs->execute($sub_params);
$subjects = $stmt_subs->fetchAll(PDO::FETCH_ASSOC);

$active_tab = $_GET['tab'] ?? 'subjects';
if (!in_array($active_tab, ['studies', 'subjects'])) {
    $active_tab = 'subjects';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Studies & Subjects - Clinformatiq EDC</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
    <style>
        :root {
            --primary: #1d6f97;
            --primary-hover: #155776;
            --bg-color: #f8fafc;
            --card-bg: #ffffff;
            --text-dark: #0f172a;
            --text-muted: #64748b;
            --border-color: #e2e8f0;
            --danger: #ef4444;
            --danger-hover: #dc2626;
            --danger-light: #fef2f2;
            --success: #16a34a;
            --success-light: #f0fdf4;
            --radius: 10px;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--bg-color);
            color: var(--text-dark);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* Top Navigation */
        .admin-header {
            background: #ffffff;
            border-bottom: 1px solid var(--border-color);
            padding: 0.9rem 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .brand-area {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .brand-logo {
            width: 36px;
            height: 36px;
            background: #e0f2fe;
            color: var(--primary);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .brand-title {
            font-size: 1.15rem;
            font-weight: 700;
            color: #0f172a;
        }
        .brand-badge {
            font-size: 0.75rem;
            background: #f1f5f9;
            color: #475569;
            padding: 0.2rem 0.5rem;
            border-radius: 99px;
            font-weight: 600;
        }
        .header-actions {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .btn-link {
            text-decoration: none;
            color: var(--text-muted);
            font-size: 0.9rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.5rem 0.85rem;
            border-radius: 6px;
            transition: all 0.15s ease;
        }
        .btn-link:hover {
            background: #f1f5f9;
            color: var(--text-dark);
        }
        .btn-logout {
            color: var(--danger);
        }
        .btn-logout:hover {
            background: var(--danger-light);
            color: var(--danger-hover);
        }

        /* Container Layout */
        .admin-container {
            max-width: 1300px;
            width: 100%;
            margin: 0 auto;
            padding: 2rem;
            flex: 1;
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1.25rem;
            margin-bottom: 2rem;
        }
        .stat-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1.25rem 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .stat-icon.studies { background: #e0f2fe; color: #0284c7; }
        .stat-icon.subjects { background: #dcfce7; color: #16a34a; }
        .stat-info h3 {
            font-size: 1.6rem;
            font-weight: 700;
            color: #0f172a;
        }
        .stat-info p {
            font-size: 0.85rem;
            color: var(--text-muted);
            font-weight: 500;
        }

        /* Alerts */
        .alert {
            padding: 1rem 1.25rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 0.92rem;
        }
        .alert-success {
            background: var(--success-light);
            color: #166534;
            border: 1px solid #bbf7d0;
        }
        .alert-danger {
            background: var(--danger-light);
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        /* Tabs */
        .tabs-header {
            display: flex;
            gap: 0.5rem;
            border-bottom: 1px solid var(--border-color);
            margin-bottom: 1.5rem;
        }
        .tab-btn {
            padding: 0.75rem 1.25rem;
            font-size: 0.95rem;
            font-weight: 600;
            color: var(--text-muted);
            border-bottom: 2px solid transparent;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.15s ease;
        }
        .tab-btn.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
        }
        .tab-btn:hover:not(.active) {
            color: var(--text-dark);
        }

        /* Content Card & Table */
        .card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            overflow: hidden;
        }
        .card-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 1rem;
        }
        .card-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--text-dark);
        }
        .filter-bar {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            flex-wrap: wrap;
        }
        .select-filter, .search-filter {
            padding: 0.55rem 0.85rem;
            font-size: 0.85rem;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            outline: none;
            background: #ffffff;
        }
        .select-filter:focus, .search-filter:focus {
            border-color: var(--primary);
        }

        /* Table */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
            text-align: left;
        }
        .data-table th {
            background: #f8fafc;
            color: #475569;
            font-weight: 600;
            padding: 0.85rem 1.25rem;
            border-bottom: 1px solid var(--border-color);
            white-space: nowrap;
        }
        .data-table td {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--border-color);
            color: #334155;
            vertical-align: middle;
        }
        .data-table tbody tr:hover {
            background: #f8fafc;
        }
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.65rem;
            border-radius: 99px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .badge-info { background: #e0f2fe; color: #0369a1; }
        .badge-success { background: #dcfce7; color: #15803d; }
        .badge-warning { background: #fef3c7; color: #b45309; }
        .badge-danger { background: #fee2e2; color: #b91c1c; }

        /* Action Buttons */
        .btn-delete {
            background: var(--danger-light);
            color: var(--danger);
            border: 1px solid #fecaca;
            padding: 0.45rem 0.85rem;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            transition: all 0.15s ease;
        }
        .btn-delete:hover {
            background: var(--danger);
            color: #ffffff;
            border-color: var(--danger);
        }

        /* Modal */
        .modal-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(4px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            padding: 1rem;
        }
        .modal-overlay.active {
            display: flex;
        }
        .modal-box {
            background: #ffffff;
            border-radius: 14px;
            width: 100%;
            max-width: 480px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            overflow: hidden;
            animation: modalFadeIn 0.2s ease;
        }
        @keyframes modalFadeIn {
            from { transform: scale(0.95); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }
        .modal-head {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .modal-head .icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            background: #fee2e2;
            color: #dc2626;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .modal-head h3 {
            font-size: 1.15rem;
            color: #0f172a;
            font-weight: 700;
        }
        .modal-body {
            padding: 1.5rem;
            font-size: 0.92rem;
            line-height: 1.6;
            color: #475569;
        }
        .modal-warning-box {
            background: #fff1f2;
            border: 1px solid #fecdd3;
            border-radius: 8px;
            padding: 0.85rem 1rem;
            margin-top: 1rem;
            font-size: 0.85rem;
            color: #9f1239;
        }
        .modal-foot {
            padding: 1rem 1.5rem;
            border-top: 1px solid var(--border-color);
            background: #f8fafc;
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
        }
        .btn-secondary {
            padding: 0.6rem 1.1rem;
            background: #ffffff;
            border: 1px solid var(--border-color);
            color: #334155;
            border-radius: 6px;
            font-size: 0.88rem;
            font-weight: 600;
            cursor: pointer;
        }
        .btn-secondary:hover { background: #f1f5f9; }
        .btn-confirm-delete {
            padding: 0.6rem 1.25rem;
            background: var(--danger);
            border: 1px solid var(--danger);
            color: #ffffff;
            border-radius: 6px;
            font-size: 0.88rem;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
        }
        .btn-confirm-delete:hover { background: var(--danger-hover); }

        .empty-state {
            padding: 3rem 1.5rem;
            text-align: center;
            color: var(--text-muted);
        }
        .empty-state span {
            font-size: 48px;
            color: #cbd5e1;
            margin-bottom: 0.5rem;
        }
    </style>
</head>
<body>

    <!-- Admin Navigation Header -->
    <header class="admin-header">
        <div class="brand-area">
            <div class="brand-logo">
                <span class="material-icons-round">settings_suggest</span>
            </div>
            <div class="brand-title">Clinformatiq EDC</div>
            <span class="brand-badge">Study & Subject Manager</span>
        </div>
        <div class="header-actions">
            <a href="dashboard.php" class="btn-link">
                <span class="material-icons-round" style="font-size: 18px;">dashboard</span>
                <span>Back to EDC</span>
            </a>
            <a href="managestudy.php?action=logout" class="btn-link btn-logout">
                <span class="material-icons-round" style="font-size: 18px;">logout</span>
                <span>Lock & Sign Out</span>
            </a>
        </div>
    </header>

    <div class="admin-container">

        <!-- Flash Messages -->
        <?php if ($flash_success): ?>
            <div class="alert alert-success">
                <span class="material-icons-round" style="font-size: 22px;">check_circle</span>
                <div><?php echo $flash_success; ?></div>
            </div>
        <?php endif; ?>

        <?php if ($flash_error): ?>
            <div class="alert alert-danger">
                <span class="material-icons-round" style="font-size: 22px;">error</span>
                <div><?php echo $flash_error; ?></div>
            </div>
        <?php endif; ?>

        <!-- Quick Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon studies">
                    <span class="material-icons-round">science</span>
                </div>
                <div class="stat-info">
                    <h3><?php echo count($studies); ?></h3>
                    <p>Total Clinical Studies</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon subjects">
                    <span class="material-icons-round">groups</span>
                </div>
                <div class="stat-info">
                    <h3><?php echo count($subjects); ?></h3>
                    <p>Active Subjects Tracked</p>
                </div>
            </div>
        </div>

        <!-- Tab Navigation -->
        <div class="tabs-header">
            <a href="managestudy.php?tab=subjects<?php echo $filter_study_id ? '&study_id=' . $filter_study_id : ''; ?>" class="tab-btn <?php echo ($active_tab === 'subjects') ? 'active' : ''; ?>">
                <span class="material-icons-round" style="font-size: 20px;">groups</span>
                <span>Manage Subjects (<?php echo count($subjects); ?>)</span>
            </a>
            <a href="managestudy.php?tab=studies" class="tab-btn <?php echo ($active_tab === 'studies') ? 'active' : ''; ?>">
                <span class="material-icons-round" style="font-size: 20px;">science</span>
                <span>Manage Studies (<?php echo count($studies); ?>)</span>
            </a>
        </div>

        <!-- TAB 1: SUBJECTS MANAGEMENT -->
        <?php if ($active_tab === 'subjects'): ?>
            <div class="card">
                <div class="card-header">
                    <div class="card-title">Enrolled Subjects</div>
                    <form method="GET" action="managestudy.php" class="filter-bar">
                        <input type="hidden" name="tab" value="subjects">
                        
                        <select name="study_id" class="select-filter" onchange="this.form.submit()">
                            <option value="0">All Studies</option>
                            <?php foreach ($studies as $std): ?>
                                <option value="<?php echo $std['id']; ?>" <?php echo ($filter_study_id == $std['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($std['study_code']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <input type="text" name="q" class="search-filter" placeholder="Search Subject Code or Site..." value="<?php echo htmlspecialchars($search_query); ?>">
                        
                        <button type="submit" class="btn-secondary" style="padding: 0.55rem 0.9rem;">
                            <span class="material-icons-round" style="font-size: 16px;">search</span>
                        </button>
                        
                        <?php if ($filter_study_id || $search_query): ?>
                            <a href="managestudy.php?tab=subjects" class="btn-link" style="font-size: 0.82rem;">Reset</a>
                        <?php endif; ?>
                    </form>
                </div>

                <?php if (empty($subjects)): ?>
                    <div class="empty-state">
                        <span class="material-icons-round">person_off</span>
                        <h4>No subjects found</h4>
                        <p style="margin-top: 0.25rem;">No subjects match the selected filters or none exist in the database.</p>
                    </div>
                <?php else: ?>
                    <div style="overflow-x: auto;">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Subject Code</th>
                                    <th>Study</th>
                                    <th>Site Name</th>
                                    <th>Status</th>
                                    <th>Data Records</th>
                                    <th>Open Queries</th>
                                    <th style="text-align: right;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($subjects as $sub): ?>
                                    <tr>
                                        <td><span style="color: #94a3b8; font-family: monospace;">#<?php echo $sub['id']; ?></span></td>
                                        <td>
                                            <strong style="color: #0f172a;"><?php echo htmlspecialchars($sub['subject_code']); ?></strong>
                                        </td>
                                        <td>
                                            <span class="badge badge-info"><?php echo htmlspecialchars($sub['study_code']); ?></span>
                                        </td>
                                        <td><?php echo htmlspecialchars($sub['site_name'] ?? 'Default Site'); ?></td>
                                        <td>
                                            <?php 
                                                $stat = strtolower($sub['status'] ?? 'enrolled');
                                                $badge_class = ($stat === 'completed') ? 'badge-success' : (($stat === 'screen failed') ? 'badge-danger' : 'badge-info');
                                            ?>
                                            <span class="badge <?php echo $badge_class; ?>"><?php echo htmlspecialchars($sub['status'] ?? 'Enrolled'); ?></span>
                                        </td>
                                        <td>
                                            <span style="font-weight: 600;"><?php echo (int)$sub['data_count']; ?></span> fields
                                        </td>
                                        <td>
                                            <?php if ($sub['query_count'] > 0): ?>
                                                <span class="badge badge-warning"><?php echo $sub['query_count']; ?> queries</span>
                                            <?php else: ?>
                                                <span style="color: #94a3b8;">None</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align: right;">
                                            <button type="button" class="btn-delete" onclick="confirmDeleteSubject(<?php echo $sub['id']; ?>, '<?php echo addslashes($sub['subject_code']); ?>', '<?php echo addslashes($sub['study_code']); ?>')">
                                                <span class="material-icons-round" style="font-size: 16px;">delete</span>
                                                <span>Delete</span>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- TAB 2: STUDIES MANAGEMENT -->
        <?php if ($active_tab === 'studies'): ?>
            <div class="card">
                <div class="card-header">
                    <div class="card-title">Registered Studies</div>
                </div>

                <?php if (empty($studies)): ?>
                    <div class="empty-state">
                        <span class="material-icons-round">science</span>
                        <h4>No studies registered</h4>
                    </div>
                <?php else: ?>
                    <div style="overflow-x: auto;">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Study Code</th>
                                    <th>Study Title</th>
                                    <th>Phase</th>
                                    <th>Status</th>
                                    <th>Visits</th>
                                    <th>Sites</th>
                                    <th>Subjects</th>
                                    <th style="text-align: right;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($studies as $std): ?>
                                    <tr>
                                        <td><span style="color: #94a3b8; font-family: monospace;">#<?php echo $std['id']; ?></span></td>
                                        <td>
                                            <strong style="color: var(--primary);"><?php echo htmlspecialchars($std['study_code']); ?></strong>
                                        </td>
                                        <td style="max-width: 320px;">
                                            <div style="font-weight: 500;"><?php echo htmlspecialchars($std['name']); ?></div>
                                            <div style="font-size: 0.8rem; color: var(--text-muted);"><?php echo htmlspecialchars($std['therapeutic_area'] ?? ''); ?></div>
                                        </td>
                                        <td><?php echo htmlspecialchars($std['type'] ?? 'Clinical'); ?></td>
                                        <td>
                                            <span class="badge badge-success"><?php echo htmlspecialchars($std['status'] ?? 'Active'); ?></span>
                                        </td>
                                        <td><?php echo (int)$std['visits_count']; ?> visits</td>
                                        <td><?php echo (int)$std['sites_count']; ?> sites</td>
                                        <td>
                                            <span class="badge badge-info"><?php echo (int)$std['subjects_count']; ?> subjects</span>
                                        </td>
                                        <td style="text-align: right;">
                                            <button type="button" class="btn-delete" onclick="confirmDeleteStudy(<?php echo $std['id']; ?>, '<?php echo addslashes($std['study_code']); ?>', '<?php echo addslashes($std['name']); ?>', <?php echo (int)$std['subjects_count']; ?>)">
                                                <span class="material-icons-round" style="font-size: 16px;">delete_forever</span>
                                                <span>Delete Study</span>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    </div>

    <!-- Modal: Delete Subject -->
    <div id="deleteSubjectModal" class="modal-overlay">
        <div class="modal-box">
            <div class="modal-head">
                <div class="icon">
                    <span class="material-icons-round">warning</span>
                </div>
                <h3>Delete Subject</h3>
            </div>
            <form method="POST" action="managestudy.php">
                <input type="hidden" name="action" value="delete_subject">
                <input type="hidden" name="subject_id" id="deleteSubjectId">
                <div class="modal-body">
                    <p>Are you sure you want to permanently delete subject <strong id="deleteSubjectCode" style="color: #0f172a;"></strong> from study <strong id="deleteSubjectStudy"></strong>?</p>
                    
                    <div class="modal-warning-box">
                        <strong>Warning: This action is permanent!</strong>
                        <ul style="margin: 0.5rem 0 0 1.25rem;">
                            <li>All CRF data, visit records, and repeating modules</li>
                            <li>Common forms (AE, ConMeds, MH, Tumor)</li>
                            <li>All queries, SDR reviews, and audit trails</li>
                        </ul>
                    </div>
                </div>
                <div class="modal-foot">
                    <button type="button" class="btn-secondary" onclick="closeModal('deleteSubjectModal')">Cancel</button>
                    <button type="submit" class="btn-confirm-delete">
                        <span class="material-icons-round" style="font-size: 18px;">delete</span>
                        <span>Confirm Deletion</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Delete Study -->
    <div id="deleteStudyModal" class="modal-overlay">
        <div class="modal-box">
            <div class="modal-head">
                <div class="icon">
                    <span class="material-icons-round">delete_forever</span>
                </div>
                <h3>Delete Study Permanently</h3>
            </div>
            <form method="POST" action="managestudy.php">
                <input type="hidden" name="action" value="delete_study">
                <input type="hidden" name="study_id" id="deleteStudyId">
                <div class="modal-body">
                    <p>Are you sure you want to permanently delete study <strong id="deleteStudyCode" style="color: #0f172a;"></strong>?</p>
                    <p style="margin-top: 0.35rem; font-size: 0.85rem; color: var(--text-muted);" id="deleteStudyTitle"></p>

                    <div class="modal-warning-box">
                        <strong>Critical Notice:</strong> This will completely remove:
                        <ul style="margin: 0.5rem 0 0 1.25rem;">
                            <li>All <strong id="deleteStudySubjectsCount">0</strong> subjects enrolled in this study</li>
                            <li>All study visits, forms, modules, and form fields</li>
                            <li>All clinical CRF data, queries, and audit logs</li>
                            <li>All site allocations and user permissions</li>
                        </ul>
                    </div>
                </div>
                <div class="modal-foot">
                    <button type="button" class="btn-secondary" onclick="closeModal('deleteStudyModal')">Cancel</button>
                    <button type="submit" class="btn-confirm-delete">
                        <span class="material-icons-round" style="font-size: 18px;">delete_forever</span>
                        <span>Delete Entire Study</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function confirmDeleteSubject(id, code, study) {
            document.getElementById('deleteSubjectId').value = id;
            document.getElementById('deleteSubjectCode').innerText = code;
            document.getElementById('deleteSubjectStudy').innerText = study;
            document.getElementById('deleteSubjectModal').classList.add('active');
        }

        function confirmDeleteStudy(id, code, title, subsCount) {
            document.getElementById('deleteStudyId').value = id;
            document.getElementById('deleteStudyCode').innerText = code;
            document.getElementById('deleteStudyTitle').innerText = title;
            document.getElementById('deleteStudySubjectsCount').innerText = subsCount;
            document.getElementById('deleteStudyModal').classList.add('active');
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('active');
        }

        // Close modal on escape key
        window.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal('deleteSubjectModal');
                closeModal('deleteStudyModal');
            }
        });
    </script>
</body>
</html>
