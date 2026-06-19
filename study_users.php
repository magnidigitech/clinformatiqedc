<?php
require_once 'includes/functions.php';
require_once 'includes/auth.php';

requireLogin();

if (!isset($_SESSION['active_study_id'])) {
    redirect('dashboard.php');
}

// Only Admin can manage users
if (!hasPermission('all')) {
    die("Unauthorized access: User Management is restricted to Administrators.");
}

$study_id = $_SESSION['active_study_id'];
$study_name = $_SESSION['active_study_name'];
$error = '';
$success = '';

$pdo = getDB();

// Handle Add User / Update Roles
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $selected_roles = $_POST['roles'] ?? []; // Array of role names
    $selected_sites = $_POST['sites'] ?? []; // Array of site IDs
    $temp_pass = $_POST['temp_password'] ?? 'ChangeMe123!'; 

    if (empty($email)) {
        $error = "Email is required.";
    } elseif (empty($name)) {
        $error = "Full Name is required.";
    } elseif (empty($selected_roles)) {
        $error = "Please select at least one role.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format.";
    } else {
        try {
            $pdo->beginTransaction();

            // 1. Check if user exists
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $existing_user_id = $stmt->fetchColumn();
            
            $target_user_id = $existing_user_id;
            $is_new_user = false;

            if (!$existing_user_id) {
                // Create New User
                $is_new_user = true;
                $username = strstr($email, '@', true);
                $hash = password_hash($temp_pass, PASSWORD_DEFAULT);
                
                $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash, name) VALUES (:user, :email, :pass, :name)");
                $stmt->execute([
                    'user' => $username,
                    'email' => $email,
                    'pass' => $hash,
                    'name' => $name
                ]);
                $target_user_id = $pdo->lastInsertId();
            } else {
                // Update Name for existing user
                $stmt = $pdo->prepare("UPDATE users SET name = :name WHERE id = :id");
                $stmt->execute(['name' => $name, 'id' => $existing_user_id]);
            }

            // 2. Clear existing roles for this user in this study (to handle updates/re-adds cleanly)
            $stmt = $pdo->prepare("DELETE FROM study_users WHERE user_id = :uid AND study_id = :sid");
            $stmt->execute(['uid' => $target_user_id, 'sid' => $study_id]);

            // 3. Assign Selected Roles
            $stmt = $pdo->prepare("INSERT INTO study_users (user_id, study_id, role_name, permissions) VALUES (:uid, :sid, :role, :perms)");
            
            foreach ($selected_roles as $role_name) {
                $perms = '';
                if ($role_name === 'Admin') $perms = 'all';
                elseif ($role_name === 'Data Coordinator') $perms = '{"view": true, "add": true, "add_subject": true, "enter_data": true, "edit": true}';
                elseif ($role_name === 'Data Entry') $perms = '{"view": true, "add_subject": true, "enter_data": true, "edit": true}';
                elseif ($role_name === 'Data Monitor') $perms = '{"view": true, "query": true, "raise_query": true, "verify": true}';
                elseif ($role_name === 'Data Manager') $perms = '{"view": true, "query": true, "raise_query": true, "verify": true}';
                
                $stmt->execute([
                    'uid' => $target_user_id,
                    'sid' => $study_id,
                    'role' => $role_name,
                    'perms' => $perms
                ]);
            }

            // 4. Assign Selected Sites
            // First clear existing site assignments
            $stmt = $pdo->prepare("DELETE FROM study_user_sites WHERE user_id = :uid AND study_id = :sid");
            $stmt->execute(['uid' => $target_user_id, 'sid' => $study_id]);

            if (!empty($selected_sites)) {
                $stmt = $pdo->prepare("INSERT INTO study_user_sites (user_id, study_id, site_id) VALUES (:uid, :sid, :site_id)");
                foreach ($selected_sites as $site_id) {
                    $stmt->execute([
                        'uid' => $target_user_id,
                        'sid' => $study_id,
                        'site_id' => $site_id
                    ]);
                }
            }
            
            $pdo->commit();
            
            if ($is_new_user) {
                $success = "New account created for <strong>" . htmlspecialchars($email) . "</strong> and assigned successfully.";
            } else {
                $success = "User <strong>" . htmlspecialchars($email) . "</strong> roles updated.";
            }

        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Database Error: " . $e->getMessage();
        }
    }
}

// Fetch Current Users & Aggregate Roles
$stmt = $pdo->prepare("
    SELECT u.username, u.email, u.name, su.role_name, su.created_at as joined_at 
    FROM study_users su 
    JOIN users u ON su.user_id = u.id 
    WHERE su.study_id = :sid
    ORDER BY su.created_at DESC
");
$stmt->execute(['sid' => $study_id]);
$raw_users = $stmt->fetchAll();

// Fetch Sites for Assignment
$sites_stmt = $pdo->prepare("SELECT id, name FROM sites WHERE study_id = ? ORDER BY name");
$sites_stmt->execute([$study_id]);
$available_sites = $sites_stmt->fetchAll();

// Aggregate by User
$study_users = [];
foreach ($raw_users as $row) {
    $email = $row['email'];
    if (!isset($study_users[$email])) {
        $study_users[$email] = [
            'username' => $row['username'],
            'email' => $row['email'],
            'name' => $row['name'],
            'joined_at' => $row['joined_at'],
            'roles' => []
        ];
    }
    $study_users[$email]['roles'][] = $row['role_name'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Users - <?php echo htmlspecialchars($study_name); ?></title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
</head>
<body>

<div class="app-layout">
    <?php include 'includes/sidebar.php'; ?>

    <main class="main-content">
        <header class="top-nav">
            <div>
                <h2 style="font-size: 1.125rem;">User Management</h2>
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
        <div class="page-content">
            <div class="container" style="max-width: 1400px; margin: 0;">
                
                <?php if ($success): ?>
                <div class="alert" style="background: #dcfce7; color: #166534; border: 1px solid #bbf7d0;">
                    <?php echo $success; ?>
                </div>
                <?php endif; ?>

                <?php if ($error): ?>
                <div class="alert alert-danger">
                    <?php echo sanitizeInput($error); ?>
                </div>
                <?php endif; ?>

                <div style="display: grid; grid-template-columns: 1fr 300px; gap: 2rem;">
                    
                    <!-- Left: User List -->
                    <div class="card" style="padding: 0; overflow: hidden;">
                        <div style="padding: 1rem 1.5rem; border-bottom: 1px solid var(--border-color); background: #f8fafc;">
                            <h3 style="margin: 0; font-size: 1rem;">Study Team Members</h3>
                        </div>
                        <div style="overflow-x: auto;">
                            <table style="width: 100%; border-collapse: collapse; font-size: 0.875rem;">
                                <thead>
                                    <tr style="text-align: left; border-bottom: 1px solid var(--border-color);">
                                        <th style="padding: 1rem;">User</th>
                                        <th style="padding: 1rem;">Roles</th>
                                        <th style="padding: 1rem;">Email</th>
                                        <th style="padding: 1rem;">Added</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($study_users as $u): ?>
                                    <tr style="border-bottom: 1px solid var(--border-color);">
                                        <td style="padding: 1rem; font-weight: 500;">
                                            <div style="display: flex; align-items: center; gap: 0.5rem;">
                                                <div style="width: 24px; height: 24px; background: #e2e8f0; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.7rem; color: #64748b;">
                                                    <?php echo strtoupper(substr($u['name'] ?: $u['username'], 0, 1)); ?>
                                                </div>
                                                <div>
                                                    <div style="font-weight: 600; color: var(--text-main);"><?php echo htmlspecialchars($u['name'] ?: $u['username']); ?></div>
                                                    <div style="font-size: 0.75rem; color: var(--text-light);"><?php echo htmlspecialchars($u['username']); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td style="padding: 1rem;">
                                            <?php foreach ($u['roles'] as $role): ?>
                                            <span class="role-badge" style="margin-right: 0.25rem; margin-bottom: 0.25rem; display: inline-block;"><?php echo htmlspecialchars($role); ?></span>
                                            <?php endforeach; ?>
                                        </td>
                                        <td style="padding: 1rem; color: var(--text-light);"><?php echo htmlspecialchars($u['email']); ?></td>
                                        <td style="padding: 1rem; color: var(--text-light);"><?php echo formatDate($u['joined_at']); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Right: Add User Form -->
                    <div>
                        <div class="card">
                            <h3 style="margin-bottom: 1rem; font-size: 1rem;">Assign / Update User</h3>
                            <form method="POST">
                                <div class="form-group">
                                    <label class="form-label">Full Name</label>
                                    <input type="text" name="name" class="form-input" placeholder="John Doe" required>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Email Address</label>
                                    <input type="email" name="email" class="form-input" placeholder="colleague@example.com" required>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Roles</label>
                                    <div style="display: flex; flex-direction: column; gap: 0.5rem; margin-top: 0.5rem;">
                                         <label style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.875rem;">
                                             <input type="checkbox" name="roles[]" value="Admin"> 
                                             <span style="font-weight: 500;">Admin</span>
                                             <span style="color: var(--text-light); font-size: 0.75rem;">(Full Config Access)</span>
                                         </label>
                                         <label style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.875rem;">
                                             <input type="checkbox" name="roles[]" value="Data Coordinator"> 
                                             <span style="font-weight: 500;">Data Coordinator</span>
                                             <span style="color: var(--text-light); font-size: 0.75rem;">(Entry, Edit & Manage)</span>
                                         </label>
                                         <label style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.875rem;">
                                             <input type="checkbox" name="roles[]" value="Data Entry"> 
                                             <span style="font-weight: 500;">Data Entry</span>
                                             <span style="color: var(--text-light); font-size: 0.75rem;">(Enter & Edit Data)</span>
                                         </label>
                                         <label style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.875rem;">
                                             <input type="checkbox" name="roles[]" value="Data Monitor"> 
                                             <span style="font-weight: 500;">Data Monitor</span>
                                             <span style="color: var(--text-light); font-size: 0.75rem;">(Queries & Verify)</span>
                                         </label>
                                         <label style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.875rem;">
                                             <input type="checkbox" name="roles[]" value="Data Manager"> 
                                             <span style="font-weight: 500;">Data Manager</span>
                                             <span style="color: var(--text-light); font-size: 0.75rem;">(Queries & Verify, identical to Monitor)</span>
                                         </label>
                                     </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Assign Sites</label>
                                    <div style="font-size: 0.75rem; color: var(--text-light); margin-bottom: 0.5rem;">User will only see subjects from selected sites. Leave empty for access to ALL sites (Admin/Monitor).</div>
                                    <div style="max-height: 150px; overflow-y: auto; border: 1px solid var(--border-color); padding: 0.5rem; border-radius: 4px;">
                                        <?php foreach ($available_sites as $site): ?>
                                        <label style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.875rem; margin-bottom: 0.25rem;">
                                            <input type="checkbox" name="sites[]" value="<?php echo $site['id']; ?>"> 
                                            <?php echo htmlspecialchars($site['name']); ?>
                                        </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Temporary Password</label>
                                    <input type="text" name="temp_password" class="form-input" value="Welcome<?php echo date('Y'); ?>!" required>
                                    <p style="font-size: 0.75rem; color: var(--text-light); margin-top: 0.25rem;">
                                        Only used if a new account is created.
                                    </p>
                                </div>

                                <button type="submit" class="btn btn-primary" style="width: 100%;">Assign Roles</button>
                            </form>
                        </div>

                        <div style="margin-top: 1rem; padding: 1rem; background: #fffbeb; border-radius: var(--radius-md); font-size: 0.8rem; color: #92400e; border: 1px solid #fcd34d;">
                            <strong>Note:</strong> Re-entering an existing user's email will update their roles.
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </main>
</div>
<script src="assets/js/app.js"></script>
</body>
</html>
