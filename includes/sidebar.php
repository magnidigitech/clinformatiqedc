<aside class="sidebar">
    <div class="sidebar-header">
        <img src="edc_small_logo.png" class="sidebar-logo logo-small" alt="Logo">
        <img src="EDC.png" class="sidebar-logo logo-large" alt="Logo">
    </div>
    <nav style="padding: 1rem 0;">
        <a href="study.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'study.php' ? 'active' : ''; ?>">
            <span class="material-icons-round">home</span>
            <span class="nav-link-text">Overview</span>
        </a>
        
        <?php if (hasPermission('view')): ?>
        <a href="subjects_list.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'subjects_list.php' ? 'active' : ''; ?>">
            <span class="material-icons-round">people</span>
            <span class="nav-link-text">Subjects</span>
        </a>
        <?php endif; ?>

        <?php if (hasPermission('query') || hasPermission('all')): 
            // Count open queries
            $q_count = 0;
            if (isset($_SESSION['active_study_id'])) {
                $stmt_q = getDB()->prepare("SELECT COUNT(*) FROM data_queries WHERE study_id = ? AND status IN ('new', 'open', 'unconfirmed', 'answered')");
                $stmt_q->execute([$_SESSION['active_study_id']]);
                $q_count = $stmt_q->fetchColumn();
            }
        ?>
            <a href="queries.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'queries.php' ? 'active' : ''; ?>">
            <span class="material-icons-round">analytics</span>
            <span class="nav-link-text">Queries</span>
            <?php if($q_count > 0): ?>
                <span style="background: #ef4444; color: white; font-size: 0.75rem; font-weight: 600; padding: 0.1rem 0.4rem; border-radius: 99px; margin-left: auto;"><?php echo $q_count; ?></span>
            <?php endif; ?>
        </a>
        <?php endif; ?>

        <?php if (hasPermission('all') || hasPermission('verify')): ?>
        <a href="data_verification.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'data_verification.php' ? 'active' : ''; ?>">
            <span class="material-icons-round">verified</span>
            <span class="nav-link-text">Data Verification</span>
        </a>
        <?php endif; ?>

        <?php if (hasPermission('all')): ?>
        <div class="sidebar-divider"></div>
        
        <a href="study_structure.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'study_structure.php' ? 'active' : ''; ?>">
            <span class="material-icons-round">design_services</span>
            <span class="nav-link-text">Structure</span>
        </a>
        <a href="study_users.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'study_users.php' ? 'active' : ''; ?>">
            <span class="material-icons-round">group_add</span>
            <span class="nav-link-text">Users & Roles</span>
        </a>
        <a href="study_config.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'study_config.php' ? 'active' : ''; ?>">
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
