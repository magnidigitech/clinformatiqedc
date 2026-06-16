<?php
require_once 'includes/functions.php';
require_once 'includes/auth.php';

requireLogin();

// Check if a study context is selected
if (!isset($_SESSION['active_study_id'])) {
    redirect('dashboard.php');
}

// Handle Role Switch within Study
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['switch_role_study'])) {
    $new_role_id = $_POST['new_role_id'];
    // Verify this assignment is for the CURRENT study
    $valid = false;
    foreach ($_SESSION['assignments'] as $assign) {
        if ($assign['id'] == $new_role_id && $assign['study_id'] == $_SESSION['active_study_id']) {
            $valid = true;
            break;
        }
    }
    
    if ($valid && setActiveContext($new_role_id)) {
        redirect('study.php');
    }
}

$study_name = $_SESSION['active_study_name'];
$active_role = $_SESSION['active_role_name'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($study_name); ?> - Clinformatiq EDC</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
</head>
<body>

<div class="app-layout">
    <?php include 'includes/sidebar.php'; ?>

    <main class="main-content">
        <header class="top-nav">
            <div>
                <h2 style="font-size: 1.125rem;"><?php echo htmlspecialchars($study_name); ?></h2>
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
            
            <!-- Welcome Actions Grid -->
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 1.5rem; margin-bottom: 2rem;">
                
                <?php if (hasPermission('add') || hasPermission('all')): ?>
                <div class="card" style="border-left: 4px solid var(--accent-color);">
                    <h4 style="margin-bottom: 0.5rem;">New Subject</h4>
                    <p style="font-size: 0.875rem; color: var(--text-light); margin-bottom: 1rem;">Enroll a new patient into the study.</p>
                    <a href="subject_entry.php" class="btn btn-primary btn-sm">Add Subject</a>
                </div>
                <?php endif; ?>

                <?php if (hasPermission('verify') || hasPermission('all')): ?>
                <?php
                    // Count pending verifications
                    $pdo = getDB();
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM subject_form_status sfs JOIN subjects s ON sfs.subject_id = s.id WHERE s.study_id = ? AND sfs.is_complete = 1 AND (sfs.is_verified = 0 OR sfs.is_verified IS NULL)");
                    $stmt->execute([$_SESSION['active_study_id']]);
                    $pending_verification = $stmt->fetchColumn();
                ?>
                <div class="card" style="border-left: 4px solid #10b981;">
                    <h4 style="margin-bottom: 0.5rem;">Data Verification</h4>
                    <p style="font-size: 0.875rem; color: var(--text-light); margin-bottom: 1rem;"><?php echo $pending_verification; ?> forms pending review.</p>
                    <a href="data_verification.php" class="btn btn-outline btn-sm">Review Data</a>
                </div>
                <?php endif; ?>

                <?php if (hasPermission('query') || hasPermission('all')): ?>
                <?php
                    // Count open queries
                    $pdo = getDB(); // Ensure DB connection
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM data_queries WHERE study_id = ? AND status IN ('new', 'open', 'answered', 'unconfirmed')");
                    $stmt->execute([$_SESSION['active_study_id']]);
                    $open_queries = $stmt->fetchColumn();
                ?>
                <div class="card" style="border-left: 4px solid #f59e0b;">
                    <h4 style="margin-bottom: 0.5rem;">Open Queries</h4>
                    <p style="font-size: 0.875rem; color: var(--text-light); margin-bottom: 1rem;"><?php echo $open_queries; ?> queries require your attention.</p>
                    <a href="queries.php?status=open" class="btn btn-outline btn-sm">View Queries</a>
                </div>
                <?php endif; ?>

            </div>

            <!-- Subject List Placeholder -->
            <div class="card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                    <h3>Recent Subjects</h3>
                     <div style="position: relative;">
                        <input type="text" placeholder="Search subject..." class="form-input" style="padding-left: 2rem; width: 200px;">
                        <span class="material-icons-round" style="position: absolute; left: 0.5rem; top: 50%; transform: translateY(-50%); font-size: 1rem; color: var(--text-light);">search</span>
                    </div>
                </div>
                
                <table style="width: 100%; border-collapse: collapse; font-size: 0.875rem;">
                    <thead>
                        <tr style="text-align: left; border-bottom: 2px solid var(--border-color);">
                            <th style="padding: 0.75rem 0.5rem;">Subject ID</th>
                            <th style="padding: 0.75rem 0.5rem;">Site</th>
                            <th style="padding: 0.75rem 0.5rem;">Status</th>
                            <th style="padding: 0.75rem 0.5rem;">Progress</th>
                            <th style="padding: 0.75rem 0.5rem;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $pdo = getDB();
                        $study_id = $_SESSION['active_study_id'];
                        // Fetch top 5 recent subjects
                        $stmt = $pdo->prepare("SELECT * FROM subjects WHERE study_id = ? ORDER BY created_at DESC LIMIT 5");
                        $stmt->execute([$study_id]);
                        $recent_subjects = $stmt->fetchAll();
                        ?>

                        <?php foreach ($recent_subjects as $sub): ?>
                        <tr style="border-bottom: 1px solid var(--border-color);">
                            <td style="padding: 1rem 0.5rem; font-weight: 500;">
                                <?php echo htmlspecialchars($sub['subject_code']); ?>
                            </td>
                            <td style="padding: 1rem 0.5rem;"><?php echo htmlspecialchars($sub['site_name']); ?></td>
                            <td style="padding: 1rem 0.5rem;">
                                <?php 
                                    $statusColor = '#64748b'; $bg = '#f1f5f9';
                                    if ($sub['status'] == 'Active') { $statusColor = '#166534'; $bg = '#dcfce7'; }
                                    if ($sub['status'] == 'Screening') { $statusColor = '#854d0e'; $bg = '#fef9c3'; }
                                ?>
                                <span style="color: <?php echo $statusColor; ?>; background: <?php echo $bg; ?>; padding: 0.1rem 0.4rem; border-radius: 4px; font-size: 0.75rem;"><?php echo htmlspecialchars($sub['status']); ?></span>
                            </td>
                            <td style="padding: 1rem 0.5rem;">
                                <?php $prog = $sub['progress'] ?? 0; ?>
                                <div style="width: 100px; height: 6px; background: #f1f5f9; border-radius: 99px; overflow: hidden;">
                                    <div style="width: <?php echo $prog; ?>%; height: 100%; background: var(--accent-color);"></div>
                                </div>
                                <span style="font-size: 0.7rem; color: var(--text-light);"><?php echo $prog; ?>%</span>
                            </td>
                            <td style="padding: 1rem 0.5rem;">
                                <?php if (hasPermission('edit') || hasPermission('all') || hasPermission('enter_data')): ?>
                                    <a href="subject_data_entry.php?subject_id=<?php echo $sub['id']; ?>" style="margin-right: 0.5rem;">Edit</a>
                                <?php endif; ?>
                                <a href="subject_data_entry.php?subject_id=<?php echo $sub['id']; ?>">View</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>

                        <?php if (empty($recent_subjects)): ?>
                        <tr>
                            <td colspan="5" style="padding: 3rem; text-align: center; color: var(--text-light);">
                                No recent subjects found.
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>
<script src="assets/js/app.js"></script>
</body>
</html>
