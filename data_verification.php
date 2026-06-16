<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'includes/functions.php';
require_once 'includes/auth.php';

requireLogin();

// Check study context
$study_id = $_SESSION['active_study_id'] ?? null;
if (!$study_id) redirect('dashboard.php');

// Permissions Check - usually Monitors or Managers
if (!hasPermission('verify') && !hasPermission('all')) {
    echo "Access Denied";
    exit;
}

$pdo = getDB();

// --- Filter Parameters ---
// Monitors verify 'Completed' forms usually
// We can filter by site as well
$site_filter = $_GET['site'] ?? '';
$subject_filter = $_GET['subject'] ?? '';

$params = [$study_id];
$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
$is_complete_val = ($driver === 'pgsql') ? 'true' : '1';
$is_verified_val = ($driver === 'pgsql') ? 'false' : '0';

$sql = "
    SELECT 
        sfs.*,
        s.subject_code,
        s.site_name,
        f.name as form_name,
        v.name as visit_name
    FROM subject_form_status sfs
    JOIN subjects s ON sfs.subject_id = s.id
    JOIN study_forms f ON sfs.form_id = f.id
    JOIN study_visits v ON sfs.visit_id = v.id
    WHERE s.study_id = ?
    AND sfs.is_complete = $is_complete_val
    AND (sfs.is_verified = $is_verified_val OR sfs.is_verified IS NULL)
";

if ($site_filter) {
    $sql .= " AND s.site_name = ?";
    $params[] = $site_filter;
}

if ($subject_filter) {
    $sql .= " AND s.subject_code LIKE ?";
    $params[] = "%$subject_filter%";
}

$sql .= " ORDER BY sfs.updated_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$forms = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Verification - Clinformatiq EDC</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
</head>
<body>

<div class="app-layout">
    <?php include 'includes/sidebar.php'; ?>

    <main class="main-content">
        <header class="top-nav">
            <div>
                <h2 style="font-size: 1.125rem;"><?php echo htmlspecialchars($_SESSION['active_study_name']); ?></h2>
                <div style="display: flex; align-items: center; gap: 0.5rem; margin-top: 0.25rem;">
                    <span style="font-size: 0.75rem; color: var(--text-light); text-transform: uppercase;">Viewing as:</span>
                    <?php renderRoleSwitcher($_SESSION['active_study_id']); ?>
                </div>
            </div>
             <div style="display: flex; align-items: center; gap: 0.5rem;">
                <span style="font-weight: 500; font-size: 0.875rem;"><?php echo htmlspecialchars($_SESSION['username']); ?></span>
                <a href="logout.php" style="font-size: 0.875rem; color: var(--text-light);">Logout</a>
            </div>
        </header>

        <div class="page-content">
            
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
                <h2 style="font-size: 1.5rem;">Data Verification</h2>
                <div style="color: var(--text-light);">
                    Review pending forms for Source Data Verification (SDV).
                </div>
            </div>

            <!-- Filters -->
            <form method="GET" style="display: flex; gap: 1rem; align-items: center; background: white; padding: 1rem; border-radius: var(--radius-md); border: 1px solid var(--border-color); margin-bottom: 2rem;">
                <div style="flex: 1;">
                   <input type="text" name="subject" value="<?php echo htmlspecialchars($subject_filter); ?>" placeholder="Search subject..." class="form-input">
                </div>
                <button type="submit" class="btn btn-outline">Filter</button>
            </form>

            <!-- Grouped by Subject -->
            <?php 
                $grouped_forms = [];
                foreach ($forms as $form) {
                    $grouped_forms[$form['subject_id']]['subject_code'] = $form['subject_code'];
                    $grouped_forms[$form['subject_id']]['site_name'] = $form['site_name'];
                    $grouped_forms[$form['subject_id']]['forms'][] = $form;
                }
            ?>

            <?php if (empty($grouped_forms)): ?>
                 <div class="card" style="padding: 3rem; text-align: center; color: var(--text-light);">
                    <span class="material-icons-round" style="font-size: 3rem; display: block; margin-bottom: 1rem; color: #cbd5e1;">verified_user</span>
                    <h3>All caught up!</h3>
                    <p>No forms currently pending verification.</p>
                </div>
            <?php else: ?>
                <?php foreach ($grouped_forms as $subject_id => $group): ?>
                    <div class="card" style="margin-bottom: 1.5rem; padding: 0; overflow: hidden;">
                        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; background: #f8fafc; border-bottom: 1px solid #e2e8f0; padding: 1rem;">
                            <div>
                                <h3 style="margin: 0; font-size: 1.1rem; color: #1e293b;"><?php echo htmlspecialchars($group['subject_code']); ?></h3>
                                <div style="font-size: 0.85rem; color: #64748b; margin-top: 0.25rem;">
                                    <?php echo htmlspecialchars($group['site_name']); ?> • <?php echo count($group['forms']); ?> forms pending
                                </div>
                            </div>
                            <a href="subject_data_entry.php?subject_id=<?php echo $subject_id; ?>" class="btn btn-outline btn-sm">View Subject</a>
                        </div>
                        <div class="card-body" style="padding: 0;">
                            <table class="table" style="width: 100%; border-collapse: collapse; margin: 0;">
                                <thead>
                                    <tr style="border-bottom: 1px solid #e2e8f0; background: #fff;">
                                        <th style="padding: 0.75rem 1.5rem; text-align: left; font-size: 0.8rem; color: #64748b; font-weight: 600;">Visit / Form</th>
                                        <th style="padding: 0.75rem 1rem; text-align: left; font-size: 0.8rem; color: #64748b; font-weight: 600;">Completed On</th>
                                        <th style="padding: 0.75rem 1.5rem; text-align: right; font-size: 0.8rem; color: #64748b; font-weight: 600;">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($group['forms'] as $form): ?>
                                    <tr style="border-bottom: 1px solid #f1f5f9;">
                                        <td style="padding: 1rem 1.5rem;">
                                            <div style="font-weight: 500;"><?php echo htmlspecialchars($form['visit_name']); ?></div>
                                            <div style="color: #64748b; font-size: 0.85rem;"><?php echo htmlspecialchars($form['form_name']); ?></div>
                                            <?php if($form['repeating_instance_id'] > 0): ?>
                                                <div style="color: #64748b; font-size: 0.8em; font-style: italic;">Repeating Instance #<?php echo $form['repeating_instance_id']; ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td style="padding: 1rem; color: #64748b;"><?php echo date('d-M-Y H:i', strtotime($form['updated_at'])); ?></td>
                                        <td style="text-align: right; padding: 1rem 1.5rem;">
                                            <a href="subject_data_entry.php?subject_id=<?php echo $form['subject_id']; ?>&visit_id=<?php echo $form['visit_id']; ?>&form_id=<?php echo $form['form_id']; ?>&instance_id=<?php echo $form['repeating_instance_id'] ?: ''; ?>" class="btn btn-primary btn-sm">Review & Verify</a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

        </div>
    </main>
</div>
<script src="assets/js/app.js"></script>
</body>
</html>
