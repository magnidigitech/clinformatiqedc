<?php
require_once 'includes/functions.php';
require_once 'includes/auth.php';

// Redirect if already logged in
if (isLoggedIn()) {
    redirect('dashboard.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identity = $_POST['identity'] ?? '';
    $password = $_POST['password'] ?? '';

    if (empty($identity) || empty($password)) {
        $error = "Please enter both username and password.";
    } else {
        $user = loginUser($identity, $password);
        if ($user) {
            redirect('dashboard.php');
        } else {
            $error = "Invalid credentials. Please try again.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Clinformatiq EDC</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
</head>
<body>

<div class="login-wrapper">
    <div class="login-card">
        <div class="login-header">
            <h2>Clinformatiq EDC</h2>
            <p style="color: var(--text-light); margin-top: 0.5rem;">Secure Access Portal</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <?php echo sanitizeInput($error); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="index.php">
            <div class="form-group">
                <label for="identity" class="form-label">Username or Email</label>
                <input type="text" id="identity" name="identity" class="form-input" required autofocus>
            </div>
            
            <div class="form-group">
                <label for="password" class="form-label">Password</label>
                <input type="password" id="password" name="password" class="form-input" required>
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 0.75rem;">Sign In</button>
        </form>
        
        <div style="margin-top: 2rem; font-size: 0.875rem; color: var(--text-light);">
            <p>Access is restricted to authorized personnel only.</p>
        </div>
    </div>
</div>

</body>
</html>
