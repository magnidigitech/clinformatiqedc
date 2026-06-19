<?php
require_once 'includes/functions.php';
require_once 'includes/auth.php';

requireLogin();

if (!isset($_SESSION['active_study_id'])) {
    redirect('dashboard.php');
}

if (!hasPermission('view')) {
    die("Unauthorized access");
}

$study_id = $_SESSION['active_study_id'];
$study_name = $_SESSION['active_study_name'];
$active_role = $_SESSION['active_role_name'];

$pdo = getDB();

// Check for Site Restrictions
$user_id = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT s.name FROM sites s JOIN study_user_sites sus ON s.id = sus.site_id WHERE sus.user_id = ? AND sus.study_id = ?");
$stmt->execute([$user_id, $study_id]);
$assigned_site_names = $stmt->fetchAll(PDO::FETCH_COLUMN);

$sql = "SELECT * FROM subjects WHERE study_id = ?";
$params = [$study_id];

if (!empty($assigned_site_names)) {
    $placeholders = implode(',', array_fill(0, count($assigned_site_names), '?'));
    $sql .= " AND site_name IN ($placeholders)";
    $params = array_merge($params, $assigned_site_names);
}

// Search Logic
$search_term = $_GET['search'] ?? '';
if ($search_term) {
    $sql .= " AND subject_code LIKE ?";
    $params[] = "%$search_term%";
}

$sql .= " ORDER BY created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$subjects = $stmt->fetchAll();

// Handle API Request
if (isApiRequest()) {
    foreach ($subjects as $sub) {
        // Render Row
        include 'includes/subject_row.php'; 
    }
    if (empty($subjects)) {
        echo '<tr><td colspan="6" style="padding: 3rem; text-align: center; color: var(--text-light);"><span class="material-icons-round" style="font-size: 3rem; color: var(--border-color); display: block; margin-bottom: 1rem;">person_off</span>No subjects found.</td></tr>';
    }
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subjects - <?php echo htmlspecialchars($study_name); ?></title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
</head>
<body>

<div class="app-layout">
    <?php include 'includes/sidebar.php'; ?>

    <main class="main-content">
        <header class="top-nav">
            <div>
                <h2 style="font-size: 1.125rem;">Subjects</h2>
                <?php renderRoleSwitcher($study_id); ?>
            </div>
             <div style="display: flex; align-items: center; gap: 0.5rem;">
                <span style="font-weight: 500; font-size: 0.875rem;"><?php echo htmlspecialchars($_SESSION['username']); ?></span>
                <a href="logout.php" style="font-size: 0.875rem; color: var(--text-light);">Logout</a>
            </div>
        </header>

        <div class="page-content">
            
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                <div style="position: relative;">
                    <input type="text" placeholder="Search subjects..." class="form-input" style="padding-left: 2rem; width: 300px;">
                    <span class="material-icons-round" style="position: absolute; left: 0.5rem; top: 50%; transform: translateY(-50%); font-size: 1rem; color: var(--text-light);">search</span>
                </div>

                <?php if (hasPermission('add_subject')): ?>
                <a href="subject_entry.php" class="btn btn-primary">
                    <span class="material-icons-round" style="font-size: 1.25rem; margin-right: 0.5rem;">add</span>
                    New Subject
                </a>
                <?php endif; ?>
            </div>

            <div class="card" style="padding: 0;">
                <table style="width: 100%; border-collapse: collapse; font-size: 0.875rem;">
                    <thead>
                        <tr style="text-align: left; border-bottom: 1px solid var(--border-color); background: #f8fafc;">
                            <th style="padding: 1rem;">Subject ID</th>
                            <th style="padding: 1rem;">Site</th>
                            <th style="padding: 1rem;">Status</th>
                            <th style="padding: 1rem;">Progress</th>
                            <th style="padding: 1rem;">Created Date</th>
                            <th style="padding: 1rem; text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($subjects as $sub): ?>
                            <?php include 'includes/subject_row.php'; ?>
                        <?php endforeach; ?>
                        
                        <?php if (empty($subjects)): ?>
                        <tr>
                            <td colspan="6" style="padding: 3rem; text-align: center; color: var(--text-light);">
                                <span class="material-icons-round" style="font-size: 3rem; color: var(--border-color); display: block; margin-bottom: 1rem;">person_off</span>
                                No subjects found.
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
<script>
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.querySelector('input[placeholder="Search subjects..."]');
    const tableBody = document.querySelector('table tbody');
    let debounceTimer;

    searchInput.addEventListener('input', function(e) {
        clearTimeout(debounceTimer);
        const query = e.target.value.trim();

        debounceTimer = setTimeout(() => {
            fetch(`subjects_list.php?search=${encodeURIComponent(query)}`, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.text())
            .then(html => {
                tableBody.innerHTML = html;
            })
            .catch(err => console.error('Search error:', err));
        }, 300); // 300ms debounce
    });
});
</script>
</body>
</html>
