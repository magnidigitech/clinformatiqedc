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
$study_id = $_SESSION['active_study_id'];

$pdo = getDB();
$site_filter = $_GET['site_filter'] ?? '';

// Check for Site Restrictions
$user_id = $_SESSION['user_id'];
$stmt_user_sites = $pdo->prepare("SELECT s.id, s.name FROM sites s JOIN study_user_sites sus ON s.id = sus.site_id WHERE sus.user_id = ? AND sus.study_id = ? ORDER BY s.name");
$stmt_user_sites->execute([$user_id, $study_id]);
$user_sites = $stmt_user_sites->fetchAll();

if (!empty($user_sites)) {
    $available_sites = $user_sites;
    $assigned_site_names = array_column($user_sites, 'name');
    
    // If site_filter is not in assigned sites, force first assigned site
    if ($site_filter && !in_array($site_filter, $assigned_site_names)) {
        $site_filter = $assigned_site_names[0];
    }
} else {
    // Admin / unrestricted monitor: can see all sites
    $stmt_all_sites = $pdo->prepare("SELECT id, name FROM sites WHERE study_id = ? ORDER BY name");
    $stmt_all_sites->execute([$study_id]);
    $available_sites = $stmt_all_sites->fetchAll();
}

// Compute dashboard metrics
$params_sites = [$study_id];
$sql_sites = "SELECT COUNT(*) FROM sites WHERE study_id = ?";
if (!empty($user_sites)) {
    $placeholders = implode(',', array_fill(0, count($user_sites), '?'));
    if ($site_filter) {
        $sql_sites .= " AND name = ?";
        $params_sites[] = $site_filter;
    } else {
        $sql_sites .= " AND id IN ($placeholders)";
        foreach ($user_sites as $s) $params_sites[] = $s['id'];
    }
} else {
    if ($site_filter) {
        $sql_sites .= " AND name = ?";
        $params_sites[] = $site_filter;
    }
}
$stmt_sites_cnt = $pdo->prepare($sql_sites);
$stmt_sites_cnt->execute($params_sites);
$num_sites = $stmt_sites_cnt->fetchColumn();

// Number of Subjects
$params_subs = [$study_id];
$sql_subs = "SELECT COUNT(*) FROM subjects WHERE study_id = ?";
if (!empty($user_sites)) {
    $assigned_site_names = array_column($user_sites, 'name');
    $placeholders = implode(',', array_fill(0, count($assigned_site_names), '?'));
    if ($site_filter) {
        $sql_subs .= " AND site_name = ?";
        $params_subs[] = $site_filter;
    } else {
        $sql_subs .= " AND site_name IN ($placeholders)";
        $params_subs = array_merge($params_subs, $assigned_site_names);
    }
} else {
    if ($site_filter) {
        $sql_subs .= " AND site_name = ?";
        $params_subs[] = $site_filter;
    }
}
$stmt_subs_cnt = $pdo->prepare($sql_subs);
$stmt_subs_cnt->execute($params_subs);
$num_subjects = $stmt_subs_cnt->fetchColumn();

// Average Completion Progress
$params_prog = [$study_id];
$sql_prog = "SELECT AVG(COALESCE(progress, 0)) FROM subjects WHERE study_id = ?";
if (!empty($user_sites)) {
    $assigned_site_names = array_column($user_sites, 'name');
    $placeholders = implode(',', array_fill(0, count($assigned_site_names), '?'));
    if ($site_filter) {
        $sql_prog .= " AND site_name = ?";
        $params_prog[] = $site_filter;
    } else {
        $sql_prog .= " AND site_name IN ($placeholders)";
        $params_prog = array_merge($params_prog, $assigned_site_names);
    }
} else {
    if ($site_filter) {
        $sql_prog .= " AND site_name = ?";
        $params_prog[] = $site_filter;
    }
}
$stmt_prog_avg = $pdo->prepare($sql_prog);
$stmt_prog_avg->execute($params_prog);
$avg_progress = round($stmt_prog_avg->fetchColumn() ?? 0);

// Helper function to render pagination links HTML
function renderPaginationHtml($page, $total_pages, $total_matching_subjects, $limit) {
    $start = ($total_matching_subjects > 0) ? ($page - 1) * $limit + 1 : 0;
    $end = min($page * $limit, $total_matching_subjects);
    
    echo '<div style="display: flex; justify-content: space-between; align-items: center; margin-top: 1rem; font-size: 0.875rem; color: var(--text-light);">';
    echo '<div>Showing ' . $start . ' to ' . $end . ' of ' . $total_matching_subjects . ' subjects</div>';
    
    echo '<div style="display: flex; gap: 0.25rem;">';
    
    // Previous button
    if ($page > 1) {
        echo '<button class="btn btn-outline btn-sm page-link" data-page="' . ($page - 1) . '" style="padding: 0.25rem 0.5rem; font-size: 0.75rem;">Prev</button>';
    } else {
        echo '<button class="btn btn-outline btn-sm" disabled style="padding: 0.25rem 0.5rem; opacity: 0.5; font-size: 0.75rem;">Prev</button>';
    }
    
    // Page numbers
    $start_page = max(1, $page - 2);
    $end_page = min($total_pages, $page + 2);
    
    if ($start_page > 1) {
        echo '<button class="btn btn-outline btn-sm page-link" data-page="1" style="padding: 0.25rem 0.5rem; font-size: 0.75rem;">1</button>';
        if ($start_page > 2) {
            echo '<span style="padding: 0.25rem 0.5rem; font-size: 0.75rem;">...</span>';
        }
    }
    
    for ($i = $start_page; $i <= $end_page; $i++) {
        $active_style = ($i == $page) ? 'background: var(--accent-color); color: white; border-color: var(--accent-color);' : '';
        echo '<button class="btn btn-outline btn-sm page-link" data-page="' . $i . '" style="padding: 0.25rem 0.5rem; font-size: 0.75rem; ' . $active_style . '">' . $i . '</button>';
    }
    
    if ($end_page < $total_pages) {
        if ($end_page < $total_pages - 1) {
            echo '<span style="padding: 0.25rem 0.5rem; font-size: 0.75rem;">...</span>';
        }
        echo '<button class="btn btn-outline btn-sm page-link" data-page="' . $total_pages . '" style="padding: 0.25rem 0.5rem; font-size: 0.75rem;">' . $total_pages . '</button>';
    }
    
    // Next button
    if ($page < $total_pages) {
        echo '<button class="btn btn-outline btn-sm page-link" data-page="' . ($page + 1) . '" style="padding: 0.25rem 0.5rem; font-size: 0.75rem;">Next</button>';
    } else {
        echo '<button class="btn btn-outline btn-sm" disabled style="padding: 0.25rem 0.5rem; opacity: 0.5; font-size: 0.75rem;">Next</button>';
    }
    
    echo '</div>';
    echo '</div>';
}

// --- AJAX Pagination & Search Backend ---
$search_term = $_GET['search'] ?? '';
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
if (!in_array($limit, [10, 25, 50, 75, 100])) {
    $limit = 10;
}
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

// SQL construction
$sql_recent = "SELECT * FROM subjects WHERE study_id = ?";
$sql_count = "SELECT COUNT(*) FROM subjects WHERE study_id = ?";
$params_recent = [$study_id];
$params_count = [$study_id];

if (!empty($user_sites)) {
    $assigned_site_names = array_column($user_sites, 'name');
    $placeholders = implode(',', array_fill(0, count($assigned_site_names), '?'));
    if ($site_filter) {
        $sql_recent .= " AND site_name = ?";
        $sql_count .= " AND site_name = ?";
        $params_recent[] = $site_filter;
        $params_count[] = $site_filter;
    } else {
        $sql_recent .= " AND site_name IN ($placeholders)";
        $sql_count .= " AND site_name IN ($placeholders)";
        $params_recent = array_merge($params_recent, $assigned_site_names);
        $params_count = array_merge($params_count, $assigned_site_names);
    }
} else {
    if ($site_filter) {
        $sql_recent .= " AND site_name = ?";
        $sql_count .= " AND site_name = ?";
        $params_recent[] = $site_filter;
        $params_count[] = $site_filter;
    }
}

if ($search_term !== '') {
    $sql_recent .= " AND subject_code LIKE ?";
    $sql_count .= " AND subject_code LIKE ?";
    $params_recent[] = "%$search_term%";
    $params_count[] = "%$search_term%";
}

// Total subjects matching criteria
$stmt_count = $pdo->prepare($sql_count);
$stmt_count->execute($params_count);
$total_matching_subjects = (int)$stmt_count->fetchColumn();

$total_pages = ceil($total_matching_subjects / $limit);
if ($total_pages < 1) $total_pages = 1;
if ($page > $total_pages) {
    $page = $total_pages;
    $offset = ($page - 1) * $limit;
}

$sql_recent .= " ORDER BY created_at DESC LIMIT $limit OFFSET $offset";
$stmt = $pdo->prepare($sql_recent);
$stmt->execute($params_recent);
$recent_subjects = $stmt->fetchAll();

// Handle AJAX Response
if (isApiRequest() || isset($_GET['ajax_search'])) {
    ob_start();
    foreach ($recent_subjects as $sub) {
        ?>
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
                <?php if (hasPermission('edit') || hasPermission('enter_data')): ?>
                    <a href="subject_data_entry.php?subject_id=<?php echo $sub['id']; ?>" style="margin-right: 0.5rem;">Edit</a>
                <?php endif; ?>
                <a href="subject_data_entry.php?subject_id=<?php echo $sub['id']; ?>">View</a>
            </td>
        </tr>
        <?php
    }
    if (empty($recent_subjects)) {
        echo '<tr><td colspan="5" style="padding: 3rem; text-align: center; color: var(--text-light);">No subjects found.</td></tr>';
    }
    $html = ob_get_clean();

    ob_start();
    renderPaginationHtml($page, $total_pages, $total_matching_subjects, $limit);
    $pagination_html = ob_get_clean();

    header('Content-Type: application/json');
    echo json_encode([
        'html' => $html,
        'pagination' => $pagination_html
    ]);
    exit;
}
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
            
            <!-- Site Filter Dashboard Bar -->
            <div style="background: white; padding: 1rem 1.5rem; border-radius: var(--radius-md); border: 1px solid var(--border-color); margin-bottom: 2rem; display: flex; align-items: center; justify-content: space-between; gap: 1.5rem; flex-wrap: wrap; box-shadow: var(--shadow-sm);">
                <h3 style="font-size: 1.125rem; margin: 0; display: flex; align-items: center; gap: 0.5rem;">
                    <span class="material-icons-round" style="color: var(--accent-color);">dashboard</span> Study Dashboard
                </h3>
                <form method="GET" style="display: flex; align-items: center; gap: 0.75rem;">
                    <label style="font-size: 0.875rem; font-weight: 500; color: var(--text-light);">Filter by Site:</label>
                    <select name="site_filter" class="form-input" onchange="this.form.submit()" style="width: 220px; padding: 0.375rem 0.75rem; font-size: 0.875rem; height: auto;">
                        <option value="">All Sites</option>
                        <?php foreach ($available_sites as $site): ?>
                            <option value="<?php echo htmlspecialchars($site['name']); ?>" <?php echo $site_filter === $site['name'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($site['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>

            <!-- Dashboard Grid -->
            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 1.5rem; margin-bottom: 2rem;">
                <div class="card" style="margin-bottom: 0; padding: 1.25rem 1.5rem; display: flex; align-items: center; gap: 1rem;">
                    <div style="width: 48px; height: 48px; background: #eff6ff; border-radius: var(--radius-md); display: flex; align-items: center; justify-content: center; color: var(--accent-color);">
                        <span class="material-icons-round" style="font-size: 1.5rem;">apartment</span>
                    </div>
                    <div>
                        <div style="font-size: 0.75rem; color: var(--text-light); text-transform: uppercase; font-weight: 600;">Number of Sites</div>
                        <div style="font-size: 1.75rem; font-weight: 700; color: var(--primary-color); line-height: 1.2; margin-top: 0.25rem;"><?php echo $num_sites; ?></div>
                    </div>
                </div>
                
                <div class="card" style="margin-bottom: 0; padding: 1.25rem 1.5rem; display: flex; align-items: center; gap: 1rem;">
                    <div style="width: 48px; height: 48px; background: #f0fdf4; border-radius: var(--radius-md); display: flex; align-items: center; justify-content: center; color: #16a34a;">
                        <span class="material-icons-round" style="font-size: 1.5rem;">people</span>
                    </div>
                    <div>
                        <div style="font-size: 0.75rem; color: var(--text-light); text-transform: uppercase; font-weight: 600;">Number of Subjects</div>
                        <div style="font-size: 1.75rem; font-weight: 700; color: var(--primary-color); line-height: 1.2; margin-top: 0.25rem;"><?php echo $num_subjects; ?></div>
                    </div>
                </div>
                
                <div class="card" style="margin-bottom: 0; padding: 1.25rem 1.5rem; display: flex; align-items: center; gap: 1rem;">
                    <div style="width: 48px; height: 48px; background: #fffbeb; border-radius: var(--radius-md); display: flex; align-items: center; justify-content: center; color: #d97706;">
                        <span class="material-icons-round" style="font-size: 1.5rem;">percent</span>
                    </div>
                    <div>
                        <div style="font-size: 0.75rem; color: var(--text-light); text-transform: uppercase; font-weight: 600;">Avg. Subject Progress</div>
                        <div style="font-size: 1.75rem; font-weight: 700; color: var(--primary-color); line-height: 1.2; margin-top: 0.25rem;"><?php echo $avg_progress; ?>%</div>
                    </div>
                </div>
            </div>
            
            <!-- Welcome Actions Grid -->
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 1.5rem; margin-bottom: 2rem;">
                
                <?php if (hasPermission('add_subject')): ?>
                <div class="card" style="border-left: 4px solid var(--accent-color);">
                     <h4 style="margin-bottom: 0.5rem;">New Subject</h4>
                     <p style="font-size: 0.875rem; color: var(--text-light); margin-bottom: 1rem;">Enroll a new patient into the study.</p>
                     <a href="subject_entry.php" class="btn btn-primary btn-sm">Add Subject</a>
                </div>
                <?php endif; ?>

                <?php if (hasPermission('verify')): ?>
                <?php
                    // Count pending verifications based on site_filter and restrictions
                    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
                    $is_complete_val = ($driver === 'pgsql') ? 'true' : '1';
                    $is_verified_val = ($driver === 'pgsql') ? 'false' : '0';
                    
                    $sql_verify = "SELECT COUNT(*) FROM subject_form_status sfs JOIN subjects s ON sfs.subject_id = s.id WHERE s.study_id = ? AND sfs.is_complete = $is_complete_val AND (sfs.is_verified = $is_verified_val OR sfs.is_verified IS NULL)";
                    $params_verify = [$_SESSION['active_study_id']];
                    
                    if (!empty($user_sites)) {
                        $assigned_site_names = array_column($user_sites, 'name');
                        $placeholders = implode(',', array_fill(0, count($assigned_site_names), '?'));
                        if ($site_filter) {
                            $sql_verify .= " AND s.site_name = ?";
                            $params_verify[] = $site_filter;
                        } else {
                            $sql_verify .= " AND s.site_name IN ($placeholders)";
                            $params_verify = array_merge($params_verify, $assigned_site_names);
                        }
                    } else {
                        if ($site_filter) {
                            $sql_verify .= " AND s.site_name = ?";
                            $params_verify[] = $site_filter;
                        }
                    }
                    
                    $stmt = $pdo->prepare($sql_verify);
                    $stmt->execute($params_verify);
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
                    // Count open queries based on site_filter and restrictions
                    $sql_q = "SELECT COUNT(*) FROM data_queries q JOIN subjects s ON q.subject_id = s.id WHERE q.study_id = ? AND q.status IN ('new', 'open', 'answered', 'unconfirmed')";
                    $params_q = [$_SESSION['active_study_id']];
                    
                    if (!empty($user_sites)) {
                        $assigned_site_names = array_column($user_sites, 'name');
                        $placeholders = implode(',', array_fill(0, count($assigned_site_names), '?'));
                        if ($site_filter) {
                            $sql_q .= " AND s.site_name = ?";
                            $params_q[] = $site_filter;
                        } else {
                            $sql_q .= " AND s.site_name IN ($placeholders)";
                            $params_q = array_merge($params_q, $assigned_site_names);
                        }
                    } else {
                        if ($site_filter) {
                            $sql_q .= " AND s.site_name = ?";
                            $params_q[] = $site_filter;
                        }
                    }
                    
                    $stmt = $pdo->prepare($sql_q);
                    $stmt->execute($params_q);
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
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
                    <h3>Subjects</h3>
                    <div style="display: flex; align-items: center; gap: 1rem;">
                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                            <label style="font-size: 0.875rem; color: var(--text-light); white-space: nowrap;">Show:</label>
                            <select id="subject-limit" class="form-input" style="width: auto; padding: 0.25rem 0.5rem; font-size: 0.875rem; height: auto;">
                                <option value="10" <?php echo $limit == 10 ? 'selected' : ''; ?>>10</option>
                                <option value="25" <?php echo $limit == 25 ? 'selected' : ''; ?>>25</option>
                                <option value="50" <?php echo $limit == 50 ? 'selected' : ''; ?>>50</option>
                                <option value="75" <?php echo $limit == 75 ? 'selected' : ''; ?>>75</option>
                                <option value="100" <?php echo $limit == 100 ? 'selected' : ''; ?>>100</option>
                            </select>
                        </div>
                        <div style="position: relative;">
                            <input type="text" id="subject-search" placeholder="Search subject..." class="form-input" style="padding-left: 2rem; width: 200px;">
                            <span class="material-icons-round" style="position: absolute; left: 0.5rem; top: 50%; transform: translateY(-50%); font-size: 1rem; color: var(--text-light);">search</span>
                        </div>
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
                    <tbody id="subjects-tbody">
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
                                <?php if (hasPermission('edit') || hasPermission('enter_data')): ?>
                                    <a href="subject_data_entry.php?subject_id=<?php echo $sub['id']; ?>" style="margin-right: 0.5rem;">Edit</a>
                                <?php endif; ?>
                                <a href="subject_data_entry.php?subject_id=<?php echo $sub['id']; ?>">View</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>

                        <?php if (empty($recent_subjects)): ?>
                        <tr>
                            <td colspan="5" style="padding: 3rem; text-align: center; color: var(--text-light);">
                                No subjects found.
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                <div id="subjects-pagination" style="margin-top: 1rem;">
                    <?php renderPaginationHtml($page, $total_pages, $total_matching_subjects, $limit); ?>
                </div>
            </div>
        </div>
    </main>
</div>
<script src="assets/js/app.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('subject-search');
    const limitSelect = document.getElementById('subject-limit');
    const tableBody = document.getElementById('subjects-tbody');
    const paginationContainer = document.getElementById('subjects-pagination');
    
    let currentPage = 1;
    let debounceTimer;

    function fetchSubjects() {
        const query = searchInput.value.trim();
        const limit = limitSelect.value;
        const siteFilter = '<?php echo htmlspecialchars($site_filter); ?>';
        
        const url = `study.php?ajax_search=1&search=${encodeURIComponent(query)}&limit=${limit}&page=${currentPage}&site_filter=${encodeURIComponent(siteFilter)}`;
        
        fetch(url, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => {
            tableBody.innerHTML = data.html;
            paginationContainer.innerHTML = data.pagination;
        })
        .catch(err => console.error('Error fetching subjects:', err));
    }

    searchInput.addEventListener('input', function() {
        clearTimeout(debounceTimer);
        currentPage = 1; // reset to first page on new search
        debounceTimer = setTimeout(fetchSubjects, 300); // 300ms debounce
    });

    limitSelect.addEventListener('change', function() {
        currentPage = 1; // reset to first page on limit change
        fetchSubjects();
    });

    // Pagination links click handler using event delegation
    paginationContainer.addEventListener('click', function(e) {
        const link = e.target.closest('.page-link');
        if (link) {
            e.preventDefault();
            currentPage = parseInt(link.getAttribute('data-page')) || 1;
            fetchSubjects();
        }
    });
});
</script>
</body>
</html>
