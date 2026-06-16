<?php
require_once 'includes/functions.php';
require_once 'includes/auth.php';

requireLogin();

if (!isset($_SESSION['active_study_id'])) {
    redirect('dashboard.php');
}

// Only Admin access
if (!hasPermission('all')) {
    die("Unauthorized access.");
}

$study_id = $_SESSION['active_study_id'];
$study_name = $_SESSION['active_study_name'];
$pdo = getDB();
$error = '';
$success = '';

// Handle Add Site
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $country = $_POST['country'] ?? '';
    $site_code = trim($_POST['site_code'] ?? '');
    $abbreviation = trim($_POST['abbreviation'] ?? '');
    $date_format = $_POST['date_format'] ?? 'YYYY-MM-DD (ISO)';

    if (empty($name) || empty($country) || empty($abbreviation)) {
        $error = "Name, Country and Abbreviation are required.";
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO sites (study_id, name, country, site_code, abbreviation, date_format) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$study_id, $name, $country, $site_code, $abbreviation, $date_format]);
            $success = "Site created successfully.";
        } catch (PDOException $e) {
            $error = "Database Error: " . $e->getMessage();
        }
    }
}

// Fetch Sites
$stmt = $pdo->prepare("SELECT * FROM sites WHERE study_id = ? ORDER BY created_at DESC");
$stmt->execute([$study_id]);
$sites = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Manage Sites - <?php echo htmlspecialchars($study_name); ?></title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
</head>
<body>

<div class="app-layout">
    <aside class="sidebar">
        <div class="sidebar-header">
            <span>Clinformatiq</span>
        </div>
        <nav style="padding: 1rem 0;">
            <a href="study.php" class="nav-link">
                <span class="material-icons-round">home</span>
                <span class="nav-link-text">Overview</span>
            </a>
            
            <?php if (hasPermission('view')): ?>
            <a href="subjects_list.php" class="nav-link">
                <span class="material-icons-round">people</span>
                <span class="nav-link-text">Subjects</span>
            </a>
            <?php endif; ?>

            <?php if (hasPermission('query') || hasPermission('all')): ?>
             <a href="#" class="nav-link">
                <span class="material-icons-round">analytics</span>
                <span class="nav-link-text">Queries</span>
            </a>
            <?php endif; ?>
            
            <?php if (hasPermission('all')): ?>
            <div class="sidebar-divider"></div>
            
            <a href="study_structure.php" class="nav-link">
                <span class="material-icons-round">design_services</span>
                <span class="nav-link-text">Structure</span>
            </a>
            <a href="study_users.php" class="nav-link">
                <span class="material-icons-round">group_add</span>
                <span class="nav-link-text">Users & Roles</span>
            </a>
            <a href="study_config.php" class="nav-link active">
                <span class="material-icons-round">settings</span>
                <span class="nav-link-text">Configuration</span>
            </a>
            <?php endif; ?>
        </nav>
        
        <div style="margin-top: auto; padding: 1rem 0;">
             <a href="dashboard.php" class="nav-link">
                <span class="material-icons-round">arrow_back</span>
                <span class="nav-link-text">Exit Study</span>
            </a>
        </div>
    </aside>

    <main class="main-content">
        <header class="top-nav">
             <div>
                <h2 style="font-size: 1.125rem;">Manage Sites</h2>
                <div style="font-size: 0.75rem; color: var(--text-light);">
                    <?php echo htmlspecialchars($study_name); ?>
                </div>
            </div>
             <div style="display: flex; align-items: center; gap: 0.5rem;">
                <span style="font-weight: 500; font-size: 0.875rem;"><?php echo htmlspecialchars($_SESSION['username']); ?></span>
            </div>
        </header>

        <div class="page-content">
            <div class="container" style="max-width: 1200px; margin: 0;">
                
                <?php if ($success): ?><div class="alert" style="background: #dcfce7; color: #166534;"><?php echo $success; ?></div><?php endif; ?>
                <?php if ($error): ?><div class="alert alert-danger"><?php echo $error; ?></div><?php endif; ?>

                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                    <div></div>
                    <button class="btn btn-primary" onclick="openModal()">
                        <span class="material-icons-round">add</span> Add site
                    </button>
                </div>

                <div class="card" style="padding: 0; overflow: hidden;">
                    <table style="width: 100%; border-collapse: collapse; font-size: 0.875rem;">
                        <thead>
                            <tr style="text-align: left; border-bottom: 1px solid var(--border-color); background: #f8fafc;">
                                <th style="padding: 1rem; font-weight: 600;">Name</th>
                                <th style="padding: 1rem; font-weight: 600;">Code</th>
                                <th style="padding: 1rem; font-weight: 600;">Abbreviation</th>
                                <th style="padding: 1rem; font-weight: 600;">Country</th>
                                <th style="padding: 1rem; font-weight: 600;">Main site</th>
                                <th style="padding: 1rem; font-weight: 600;">Date format</th>
                                <th style="padding: 1rem; width: 50px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sites as $site): ?>
                            <tr style="border-bottom: 1px solid var(--border-color);">
                                <td style="padding: 1rem; font-weight: 500;"><?php echo htmlspecialchars($site['name']); ?></td>
                                <td style="padding: 1rem; color: var(--text-light);"><?php echo htmlspecialchars($site['site_code']); ?></td>
                                <td style="padding: 1rem;"><?php echo htmlspecialchars($site['abbreviation']); ?></td>
                                <td style="padding: 1rem;"><?php echo htmlspecialchars($site['country']); ?></td>
                                <td style="padding: 1rem;">
                                    <?php if ($site['main_site']): ?>
                                        <span class="material-icons-round" style="font-size: 1rem; color: var(--accent-color);">check</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 1rem; color: var(--text-light);"><?php echo htmlspecialchars($site['date_format']); ?></td>
                                <td style="padding: 1rem;">
                                    <span class="material-icons-round" style="color: var(--text-light); cursor: pointer;">more_vert</span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($sites)): ?>
                            <tr>
                                <td colspan="7" style="padding: 3rem; text-align: center; color: var(--text-light);">
                                    <div style="margin-bottom: 0.5rem; display: flex; justify-content: center;">
                                        <span class="material-icons-round" style="font-size: 2rem; opacity: 0.3;">domain</span>
                                    </div>
                                    No additional sites configured.
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            </div>
        </div>
    </main>
</div> <!-- End app-layout -->

<!-- Modal moved OUTSIDE app-layout to prevent stacking context issues -->
<div id="addSiteModal" class="modal-overlay">
    <form method="POST" class="modal-content">
        <div class="modal-header">
            <h3>Create new site</h3>
            <button type="button" class="close-modal" onclick="closeModal()">
                <span class="material-icons-round">close</span>
            </button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label">Site name <span style="color:red">*</span></label>
                <p style="font-size: 0.75rem; color: var(--text-light); margin-bottom: 0.5rem;">You can assign an SDV plan to this new site in the Monitoring section.</p>
                <input type="text" name="name" class="form-input" required autofocus>
            </div>
            
            <div class="form-group">
                <label class="form-label">Site country <span style="color:red">*</span></label>
                <select name="country" class="form-input" required>
                    <option value="">Please select</option>
                    <option value="India">India</option>
                    <option value="United States">United States</option>
                    <option value="United Kingdom">United Kingdom</option>
                    <option value="Germany">Germany</option>
                    <option value="France">France</option>
                    <option value="Canada">Canada</option>
                    <option value="Australia">Australia</option>
                    <option value="China">China</option>
                    <option value="Japan">Japan</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Site code</label>
                <input type="text" name="site_code" class="form-input">
            </div>

            <div class="form-group">
                <label class="form-label">Site abbreviation <span style="color:red">*</span></label>
                <input type="text" name="abbreviation" class="form-input" required>
            </div>

            <div class="form-group">
                <label class="form-label">Date format <span style="color:red">*</span></label>
                <select name="date_format" class="form-input">
                    <option value="YYYY-MM-DD (ISO)">YYYY-MM-DD (ISO)</option>
                    <option value="DD-MM-YYYY">DD-MM-YYYY</option>
                    <option value="MM-DD-YYYY">MM-DD-YYYY</option>
                </select>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeModal()">Cancel</button>
            <button type="submit" class="btn btn-primary">Create</button>
        </div>
    </form>
</div>

<script src="assets/js/app.js"></script>
<script>
function openModal() {
    document.getElementById('addSiteModal').classList.add('active');
}
function closeModal() {
    document.getElementById('addSiteModal').classList.remove('active');
}
</script>
</body>
</html>
