<?php
require_once 'includes/functions.php';
require_once 'includes/auth.php';

requireLogin();

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = :id");
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!password_verify($current_password, $user['password_hash'])) {
        $error = "Current password is incorrect.";
    } elseif ($new_password !== $confirm_password) {
        $error = "New passwords do not match.";
    } elseif (strlen($new_password) < 8) {
        $error = "New password must be at least 8 characters long.";
    } else {
        $new_hash = password_hash($new_password, PASSWORD_BCRYPT);
        $update = $pdo->prepare("UPDATE users SET password_hash = :hash WHERE id = :id");
        $update->execute(['hash' => $new_hash, 'id' => $_SESSION['user_id']]);
        $message = "Password updated successfully.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile - Clinformatiq EDC</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
</head>
<body>

<div class="app-layout">
    <aside class="sidebar">
        <div class="sidebar-header">
            <span>Clinformatiq</span>
        </div>
        <nav style="padding: 1.5rem;">
            <a href="dashboard.php" class="btn btn-outline" style="width: 100%; justify-content: flex-start; color: rgba(255,255,255,0.7); border-color: transparent; margin-bottom: 0.5rem;">
                <span class="material-icons-round" style="margin-right: 0.75rem;">dashboard</span>
                My Studies
            </a>
            <div style="width: 100%; height: 1px; background: rgba(255,255,255,0.1); margin: 0.5rem 0;"></div>
             <a href="profile.php" class="btn btn-outline" style="width: 100%; justify-content: flex-start; color: white; border-color: transparent; background: rgba(255,255,255,0.1);">
                <span class="material-icons-round" style="margin-right: 0.75rem;">person</span>
                Profile
            </a>
        </nav>
    </aside>

    <main class="main-content">
        <header class="top-nav">
             <h2 style="font-size: 1.25rem;">My Profile</h2>
             <a href="dashboard.php" class="btn btn-outline">Back to Dashboard</a>
        </header>

        <div class="page-content">
            <div class="container" style="max-width: 600px; margin: 0;">
                <div class="card">
                    <h3 style="margin-bottom: 1.5rem;">Change Password</h3>
                    
                    <?php if ($message): ?>
                        <div style="background: #dcfce7; color: #166534; padding: 0.75rem; border-radius: 0.5rem; margin-bottom: 1rem;">
                            <?php echo sanitizeInput($message); ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($error): ?>
                        <div class="alert alert-danger">
                            <?php echo sanitizeInput($error); ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="profile.php">
                        <div class="form-group">
                            <label class="form-label">Current Password</label>
                            <input type="password" name="current_password" class="form-input" required>
                        </div>
                         <div class="form-group">
                            <label class="form-label">New Password</label>
                            <input type="password" name="new_password" class="form-input" required>
                        </div>
                         <div class="form-group">
                            <label class="form-label">Confirm New Password</label>
                            <input type="password" name="confirm_password" class="form-input" required>
                        </div>
                        <button type="submit" class="btn btn-primary">Update Password</button>
                    </form>
                </div>
            </div>
        </div>
    </main>
</div>
<script src="assets/js/app.js"></script>
</body>
</html>
