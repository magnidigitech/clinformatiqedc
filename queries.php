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

$pdo = getDB();

// --- 1. Filter Parameters ---
$status_filter = $_GET['status'] ?? ''; // e.g. 'all', 'new', 'open', 'answered', 'closed'
$site_filter   = $_GET['site'] ?? '';
$subject_filter = $_GET['subject'] ?? '';

// Fetch counts for each status tab
$stmt_counts = $pdo->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN q.status = 'new' THEN 1 ELSE 0 END) as new_count,
        SUM(CASE WHEN q.status = 'open' THEN 1 ELSE 0 END) as open_count,
        SUM(CASE WHEN q.status = 'answered' THEN 1 ELSE 0 END) as answered_count,
        SUM(CASE WHEN q.status = 'closed' THEN 1 ELSE 0 END) as closed_count
    FROM data_queries q
    JOIN subjects s ON q.subject_id = s.id
    LEFT JOIN subject_repeating_instances sri ON q.repeating_instance_id = sri.id
    WHERE q.study_id = ?
    AND (q.repeating_instance_id = 0 OR q.repeating_instance_id IS NULL OR sri.status = 'active')
");
$stmt_counts->execute([$study_id]);
$counts = $stmt_counts->fetch(PDO::FETCH_ASSOC);

$total_count = $counts['total'] ?? 0;
$new_count = $counts['new_count'] ?? 0;
$open_count = $counts['open_count'] ?? 0;
$answered_count = $counts['answered_count'] ?? 0;
$closed_count = $counts['closed_count'] ?? 0;

$status_clause = "";
$params = [$study_id];

if ($status_filter && $status_filter !== 'all') {
    $status_clause = "AND q.status = ?";
    $params[] = $status_filter;
}

if ($site_filter) {
    $status_clause .= " AND s.site_name = ?"; // Or site_id if we have it
    $params[] = $site_filter;
}

if ($subject_filter) {
    $status_clause .= " AND (s.subject_code LIKE ? OR q.query_text LIKE ?)";
    $params[] = "%$subject_filter%";
    $params[] = "%$subject_filter%";
}

// --- 2. Fetch Queries ---
// Join with subjects to get site/subject info
// Join with form_fields to get label
$sql = "
    SELECT 
        q.*,
        s.subject_code,
        s.site_name,
        f.name as form_name,
        f.repeating_module_id,
        ff.label as field_label,
        COALESCE(u.name, u.username) as created_by_name
    FROM data_queries q
    JOIN subjects s ON q.subject_id = s.id
    LEFT JOIN study_forms f ON q.form_id = f.id
    LEFT JOIN form_fields ff ON q.field_id = ff.id
    LEFT JOIN users u ON q.created_by = u.id
    LEFT JOIN subject_repeating_instances sri ON q.repeating_instance_id = sri.id
    WHERE q.study_id = ?
    AND (q.repeating_instance_id = 0 OR q.repeating_instance_id IS NULL OR sri.status = 'active')
    $status_clause
    ORDER BY q.created_at DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$queries = $stmt->fetchAll();

// --- 3. Dynamic Title ---
$page_title = "Queries";
if ($status_filter === 'all' || $status_filter === '') $page_title = "All Queries";
else $page_title = ucfirst($status_filter) . " Queries";

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?> - Clinformatiq EDC</title>
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
            
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                <h2 style="font-size: 1.5rem;"><?php echo htmlspecialchars($page_title); ?></h2>
            </div>

            <?php
            $tab_params = '';
            if (!empty($subject_filter)) {
                $tab_params .= '&subject=' . urlencode($subject_filter);
            }
            if (!empty($site_filter)) {
                $tab_params .= '&site=' . urlencode($site_filter);
            }
            ?>
            <!-- Tabs Navigation -->
            <div class="query-tabs" style="display: flex; gap: 1.5rem; border-bottom: 2px solid #e2e8f0; margin-bottom: 2rem; padding-bottom: 0.5rem; flex-wrap: wrap;">
                <a href="queries.php?status=all<?php echo $tab_params; ?>" class="query-tab" style="text-decoration: none; color: <?php echo ($status_filter === '' || $status_filter === 'all') ? 'var(--primary-color)' : '#64748b'; ?>; font-weight: 600; padding-bottom: 0.5rem; border-bottom: 2px solid <?php echo ($status_filter === '' || $status_filter === 'all') ? 'var(--primary-color)' : 'transparent'; ?>; margin-bottom: -0.65rem; display: flex; align-items: center; gap: 0.5rem; transition: all 0.2s;">
                    All <span style="font-size: 0.75rem; background: #f1f5f9; color: #475569; padding: 2px 6px; border-radius: 99px; font-weight: 500;"><?php echo $total_count; ?></span>
                </a>
                <a href="queries.php?status=new<?php echo $tab_params; ?>" class="query-tab" style="text-decoration: none; color: <?php echo $status_filter === 'new' ? 'var(--primary-color)' : '#64748b'; ?>; font-weight: 600; padding-bottom: 0.5rem; border-bottom: 2px solid <?php echo $status_filter === 'new' ? 'var(--primary-color)' : 'transparent'; ?>; margin-bottom: -0.65rem; display: flex; align-items: center; gap: 0.5rem; transition: all 0.2s;">
                    New <span style="font-size: 0.75rem; background: #e0f2fe; color: var(--primary-color); padding: 2px 6px; border-radius: 99px; font-weight: 500;"><?php echo $new_count; ?></span>
                </a>
                <a href="queries.php?status=open<?php echo $tab_params; ?>" class="query-tab" style="text-decoration: none; color: <?php echo $status_filter === 'open' ? '#0284c7' : '#64748b'; ?>; font-weight: 600; padding-bottom: 0.5rem; border-bottom: 2px solid <?php echo $status_filter === 'open' ? '#0284c7' : 'transparent'; ?>; margin-bottom: -0.65rem; display: flex; align-items: center; gap: 0.5rem; transition: all 0.2s;">
                    Open <span style="font-size: 0.75rem; background: #e0f2fe; color: #0284c7; padding: 2px 6px; border-radius: 99px; font-weight: 500;"><?php echo $open_count; ?></span>
                </a>
                <a href="queries.php?status=answered<?php echo $tab_params; ?>" class="query-tab" style="text-decoration: none; color: <?php echo $status_filter === 'answered' ? '#ea580c' : '#64748b'; ?>; font-weight: 600; padding-bottom: 0.5rem; border-bottom: 2px solid <?php echo $status_filter === 'answered' ? '#ea580c' : 'transparent'; ?>; margin-bottom: -0.65rem; display: flex; align-items: center; gap: 0.5rem; transition: all 0.2s;">
                    Answered <span style="font-size: 0.75rem; background: #ffedd5; color: #ea580c; padding: 2px 6px; border-radius: 99px; font-weight: 500;"><?php echo $answered_count; ?></span>
                </a>
                <a href="queries.php?status=closed<?php echo $tab_params; ?>" class="query-tab" style="text-decoration: none; color: <?php echo $status_filter === 'closed' ? '#16a34a' : '#64748b'; ?>; font-weight: 600; padding-bottom: 0.5rem; border-bottom: 2px solid <?php echo $status_filter === 'closed' ? '#16a34a' : 'transparent'; ?>; margin-bottom: -0.65rem; display: flex; align-items: center; gap: 0.5rem; transition: all 0.2s;">
                    Closed <span style="font-size: 0.75rem; background: #dcfce7; color: #16a34a; padding: 2px 6px; border-radius: 99px; font-weight: 500;"><?php echo $closed_count; ?></span>
                </a>
            </div>

            <!-- Filters Bar -->
            <form method="GET" style="display: flex; gap: 1rem; align-items: center; background: white; padding: 1rem; border-radius: var(--radius-md); border: 1px solid var(--border-color); margin-bottom: 2rem;">
                <input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>">
                
                <div style="flex: 1;">
                   <input type="text" name="subject" value="<?php echo htmlspecialchars($subject_filter); ?>" placeholder="Search subject or query text..." class="form-input">
                </div>
                
                <button type="submit" class="btn btn-outline">Filter</button>
            </form>

            <!-- Queries Table -->
            <div class="card" style="padding: 0; overflow: hidden;">
                <table style="width: 100%; border-collapse: collapse; font-size: 0.875rem;">
                    <thead style="background: #f8fafc; border-bottom: 1px solid var(--border-color);">
                        <tr>
                            <th style="padding: 1rem; text-align: left; font-weight: 600; color: var(--text-light);">ID</th>
                            <th style="padding: 1rem; text-align: left; font-weight: 600; color: var(--text-light);">Subject</th>
                            <th style="padding: 1rem; text-align: left; font-weight: 600; color: var(--text-light);">Site</th>
                            <th style="padding: 1rem; text-align: left; font-weight: 600; color: var(--text-light);">Field / Form</th>
                            <th style="padding: 1rem; text-align: left; font-weight: 600; color: var(--text-light);">Status</th>
                            <th style="padding: 1rem; text-align: left; font-weight: 600; color: var(--text-light);">Remark</th>
                            <th style="padding: 1rem; text-align: left; font-weight: 600; color: var(--text-light);">Raised By</th>
                            <th style="padding: 1rem; text-align: left; font-weight: 600; color: var(--text-light);">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($queries)): ?>
                        <tr>
                            <td colspan="8" style="padding: 3rem; text-align: center; color: var(--text-light);">
                                No queries found matching your filters.
                            </td>
                        </tr>
                        <?php else: ?>
                            <?php foreach ($queries as $q): ?>
                            <tr style="border-bottom: 1px solid var(--border-color);">
                                <td style="padding: 1rem;">
                                    <span style="font-family: monospace; color: var(--text-light);">#<?php echo $q['id']; ?></span>
                                </td>
                                <td style="padding: 1rem; font-weight: 500;">
                                    <?php echo htmlspecialchars($q['subject_code']); ?>
                                </td>
                                <td style="padding: 1rem;">
                                    <?php echo htmlspecialchars($q['site_name']); ?>
                                </td>
                                <td style="padding: 1rem;">
                                    <div style="font-weight: 500; font-size: 0.8rem;"><?php echo htmlspecialchars($q['field_label']); ?></div>
                                    <div style="font-size: 0.75rem; color: var(--text-light);"><?php echo htmlspecialchars($q['form_name']); ?></div>
                                </td>
                                <td style="padding: 1rem;">
                                    <?php 
                                        $sColor = '#64748b'; $sBg = '#f1f5f9';
                                        switch($q['status']) {
                                            case 'new': $sColor = 'var(--primary-color)'; $sBg = '#e0f2fe'; break; // Blue
                                            case 'open': $sColor = '#0284c7'; $sBg = '#e0f2fe'; break; // Sky Blue
                                            case 'answered': $sColor = '#ea580c'; $sBg = '#ffedd5'; break; // Orange
                                            case 'closed': $sColor = '#16a34a'; $sBg = '#dcfce7'; break; // Green
                                        }
                                    ?>
                                    <span style="color: <?php echo $sColor; ?>; background: <?php echo $sBg; ?>; padding: 0.1rem 0.5rem; border-radius: 99px; font-size: 0.75rem; font-weight: 500; text-transform: uppercase;">
                                        <?php echo htmlspecialchars($q['status']); ?>
                                    </span>
                                </td>
                                <td style="padding: 1rem; max-width: 250px;">
                                    <div style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?php echo htmlspecialchars($q['query_text']); ?>">
                                        <?php echo htmlspecialchars($q['query_text']); ?>
                                    </div>
                                </td>
                                <td style="padding: 1rem; font-size: 0.8rem;">
                                    <div><?php echo htmlspecialchars($q['created_by_name']); ?></div>
                                    <div style="color: var(--text-light);"><?php echo formatDate($q['created_at']); ?></div>
                                </td>
                                <td style="padding: 1rem;">
                                    <?php 
                                    $link_params = "subject_id=" . $q['subject_id'] . "&form_id=" . $q['form_id'];
                                    if ($q['repeating_module_id']) {
                                        $link_params .= "&module_id=" . $q['repeating_module_id'] . "&instance_id=" . $q['repeating_instance_id'];
                                    } else {
                                        $link_params .= "&visit_id=" . $q['visit_id'];
                                    }
                                    $link_params .= "&focus_query=" . $q['id'] . "#field-" . $q['field_id'];
                                    ?>
                                    <a href="subject_data_entry.php?<?php echo $link_params; ?>" class="btn btn-sm btn-outline">View</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
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
