<?php
require_once 'includes/functions.php';
require_once 'includes/auth.php';

requireLogin();

$pdo = getDB();
$user_id = $_SESSION['user_id'];
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_pass = $_POST['new_password'] ?? '';
    $confirm_pass = $_POST['confirm_password'] ?? '';
    
    if (!empty($new_pass)) {
        if ($new_pass !== $confirm_pass) {
            $error = "Passwords do not match.";
        } elseif (strlen($new_pass) < 6) {
            $error = "Password must be at least 6 characters.";
        } else {
            $hash = password_hash($new_pass, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
            if ($stmt->execute([$hash, $user_id])) {
                $success = "Password updated successfully.";
            } else {
                $error = "Failed to update password.";
            }
        }
    }
}

// Fetch user details
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - Clinformatiq</title>
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
            <a href="dashboard.php" class="nav-link">
                <span class="material-icons-round">dashboard</span>
                <span class="nav-link-text">My Studies</span>
            </a>
            <a href="settings.php" class="nav-link active">
                <span class="material-icons-round">settings</span>
                <span class="nav-link-text">Settings</span>
            </a>
        </nav>
        
        <div style="margin-top: auto; padding: 1rem 0;">
             <a href="logout.php" class="nav-link">
                <span class="material-icons-round">logout</span>
                <span class="nav-link-text">Logout</span>
            </a>
        </div>
    </aside>

    <main class="main-content">
        <header class="top-nav">
            <h2 style="font-size: 1.125rem;">Account Settings</h2>
        </header>

        <div class="page-content">
            <div class="container" style="max-width: 600px; margin: 0 auto;">
                
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

                <div class="card">
                    <h3 style="margin-bottom: 1.5rem; font-size: 1.1rem; border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem;">Profile Information</h3>
                    
                    <div class="form-group">
                        <label class="form-label">Username</label>
                        <input type="text" class="form-input" value="<?php echo htmlspecialchars($user['username']); ?>" disabled style="background: #f1f5f9;">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Email Address</label>
                        <input type="email" class="form-input" value="<?php echo htmlspecialchars($user['email']); ?>" disabled style="background: #f1f5f9;">
                        <p style="font-size: 0.75rem; color: var(--text-light); margin-top: 0.25rem;">Contact administrator to change email.</p>
                    </div>

                    <h3 style="margin-top: 2rem; margin-bottom: 1.5rem; font-size: 1.1rem; border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem;">Change Password</h3>
                    
                    <form method="POST">
                        <div class="form-group">
                            <label class="form-label">New Password</label>
                            <input type="password" name="new_password" class="form-input" placeholder="Min 6 characters">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Confirm New Password</label>
                            <input type="password" name="confirm_password" class="form-input" placeholder="Re-type new password">
                        </div>
                        
                        <div style="text-align: right; margin-top: 1.5rem;">
                            <button type="submit" class="btn btn-primary">Update Password</button>
                        </div>
                    </form>
                </div>

            </div>
        </div>
    </main>
</div>
</body>
</html>
