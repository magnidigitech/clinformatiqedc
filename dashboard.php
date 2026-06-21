<?php
require_once 'includes/functions.php';
require_once 'includes/auth.php';

requireLogin();

// Refresh assignments if not set or if we are switching to a potentially new study
if (!isset($_SESSION['assignments'])) {
    initializeUserRoles($_SESSION['user_id']);
}

// Handle Context Switch directly via ID (Role Selection)
if (isset($_GET['switch_context_id'])) {
    $assignment_id = $_GET['switch_context_id'];
    
    // Explicitly find this assignment
    $found = false;
    foreach ($_SESSION['assignments'] ?? [] as $assign) {
         if ($assign['id'] == $assignment_id) {
             setActiveContext($assign['id']);
             redirect('study.php');
             $found = true;
             break;
         }
    }
    
    // If not found, try reload (Just in case stale session)
    if (!$found) {
        initializeUserRoles($_SESSION['user_id']);
        foreach ($_SESSION['assignments'] ?? [] as $assign) {
             if ($assign['id'] == $assignment_id) {
                 setActiveContext($assign['id']);
                 redirect('study.php');
                 break;
             }
        }
    }
    // If still failing, show error (or stay on dashboard)
}

// Handle Click on Card (Legacy / Fallback if needed, but Modal uses switch_context_id)
if (isset($_GET['switch_to'])) {
    $study_id = $_GET['switch_to'];
    
    // Function to find and switch
    $switch = function($study_id) {
        foreach ($_SESSION['assignments'] ?? [] as $assign) {
             if ($assign['study_id'] == $study_id) {
                 setActiveContext($assign['id']);
                 redirect('study.php');
                 return true;
             }
        }
        return false;
    };

    // Try finding in current session
    if (!$switch($study_id)) {
        // Not found? Force Reload
        initializeUserRoles($_SESSION['user_id']);
        // Try again
        if (!$switch($study_id)) {
            // Still not found
            // Could display error or just continue
            echo "<script>alert('Failed to enter study. You may not have access.'); window.location.href='dashboard.php';</script>";
            exit;
        }
    }
}

$assignments = $_SESSION['assignments'] ?? [];
$active_assignment_id = $_SESSION['active_assignment_id'] ?? null;
$username = $_SESSION['username'] ?? 'User';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Clinformatiq EDC</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
</head>
<body>

<div class="app-layout">
    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <img src="edc_small_logo.png" class="sidebar-logo logo-small" alt="Logo">
            <img src="edc_large_logo.png" class="sidebar-logo logo-large" alt="Logo">
        </div>
        <nav style="padding: 1rem 0;">
            <a href="dashboard.php" class="nav-link active">
                <span class="material-icons-round">dashboard</span>
                <span class="nav-link-text">My Studies</span>
            </a>
            <a href="settings.php" class="nav-link">
                <span class="material-icons-round">settings</span>
                <span class="nav-link-text">Settings</span>
            </a>
        </nav>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Top Navigation -->
        <header class="top-nav">
            <h2 style="font-size: 1.25rem;">My Studies</h2>
            
            <div style="display: flex; align-items: center; gap: 1rem;">
                <!-- Role Switcher Removed -->
                <div style="display: flex; align-items: center; gap: 0.5rem;">
                    <span style="font-weight: 500; font-size: 0.875rem;"><?php echo htmlspecialchars($username); ?></span>
                    <a href="logout.php" style="font-size: 0.875rem; color: var(--text-light);">Logout</a>
                </div>
            </div>
        </header>

        <!-- Content Area -->
        <div class="page-content">
            
            <?php
            // Filter and Sort Logic
            $search = $_GET['search'] ?? '';
            $order = $_GET['order'] ?? 'newest';
            
            $filtered_assignments = array_filter($assignments, function($a) use ($search) {
                if (empty($search)) return true;
                return stripos($a['study_name'], $search) !== false || stripos($a['study_code'], $search) !== false;
            });

            usort($filtered_assignments, function($a, $b) use ($order) {
                if ($order == 'az') return strcasecmp($a['study_name'], $b['study_name']);
                if ($order == 'za') return strcasecmp($b['study_name'], $a['study_name']);
                // Handle optional creation date if missing (mock data/old data precaution)
                $time_a = isset($a['study_created_at']) ? strtotime($a['study_created_at']) : 0;
                $time_b = isset($b['study_created_at']) ? strtotime($b['study_created_at']) : 0;
                
                if ($order == 'oldest') return $time_a - $time_b;
                // Default newest
                return $time_b - $time_a;
            });
            ?>

            <!-- Search and Filter Bar -->
            <div style="display: flex; flex-wrap: wrap; gap: 1rem; align-items: center; margin-bottom: 2rem; background: white; padding: 1rem; border-radius: var(--radius-md); border: 1px solid var(--border-color);">
                
                <form method="GET" style="display: flex; flex: 1; gap: 1rem; align-items: center; flex-wrap: wrap;">
                    <div style="position: relative; flex: 1; min-width: 200px;">
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search" class="form-input" style="padding-left: 2.5rem;">
                        <span class="material-icons-round" style="position: absolute; left: 0.75rem; top: 50%; transform: translateY(-50%); font-size: 1.25rem; color: var(--text-light);">search</span>
                    </div>

                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                        <label for="order" style="font-size: 0.875rem; color: var(--text-light); white-space: nowrap;">Order by</label>
                        <select name="order" id="order" class="form-input" style="width: auto;" onchange="this.form.submit()">
                            <option value="newest" <?php echo $order == 'newest' ? 'selected' : ''; ?>>Creation date: Newest first</option>
                            <option value="oldest" <?php echo $order == 'oldest' ? 'selected' : ''; ?>>Creation date: Oldest first</option>
                            <option value="az" <?php echo $order == 'az' ? 'selected' : ''; ?>>Study Name: A - Z</option>
                            <option value="za" <?php echo $order == 'za' ? 'selected' : ''; ?>>Study Name: Z - A</option>
                        </select>
                    </div>
                </form>

                <div style="display: flex; gap: 0.5rem;">
                     <!-- "New Study" available for all users -->
                     <a href="study_create.php" class="btn btn-primary">
                        <span class="material-icons-round" style="font-size: 1.25rem;">add</span>
                        New Study
                    </a>
                    <button class="btn btn-outline">
                         <span class="material-icons-round" style="font-size: 1.25rem;">filter_list</span>
                         Filters
                    </button>
                </div>
            </div>
            
            <?php if (empty($filtered_assignments)): ?>
                <div class="alert alert-danger" style="background: white; border-color: var(--border-color); color: var(--text-main); text-align: center; padding: 3rem;">
                    <span class="material-icons-round" style="font-size: 3rem; color: var(--text-light); display: block; margin-bottom: 1rem;">search_off</span>
                    No studies found matching your criteria.
                </div>
            <?php else: ?>
                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1.5rem;">
                    <?php 
                    // Group assignments by Study ID
                    $unique_studies = [];
                    foreach ($filtered_assignments as $assign) {
                        $sid = $assign['study_id'];
                        if (!isset($unique_studies[$sid])) {
                            $unique_studies[$sid] = $assign;
                            $unique_studies[$sid]['roles_data'] = []; // Store full role data
                        }
                        // Add role data (ID and Name)
                        $unique_studies[$sid]['roles_data'][] = [
                            'id' => $assign['id'], // assessment/row id
                            'name' => $assign['role_name']
                        ];
                    }
                    
                    foreach ($unique_studies as $study): 
                    ?>
                        <div class="card" style="transition: transform 0.2s; cursor: pointer;" onclick="openRoleModal('<?php echo htmlspecialchars($study['study_name']); ?>', <?php echo htmlspecialchars(json_encode($study['roles_data'])); ?>)">
                            <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 1rem;">
                                 <div style="width: 40px; height: 40px; background: #eff6ff; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: var(--accent-color);">
                                    <span class="material-icons-round">science</span>
                                 </div>
                            </div>
                            
                            <h3 style="font-size: 1.125rem; margin-bottom: 0.25rem;">
                                <?php echo htmlspecialchars($study['study_name']); ?>
                            </h3>
                            <p style="color: var(--text-light); font-size: 0.875rem; margin-bottom: 1.5rem;">
                                <?php echo htmlspecialchars($study['study_code']); ?>
                            </p>
                            
                            <div style="border-top: 1px solid var(--border-color); padding-top: 1rem; display: flex; justify-content: space-between; align-items: center;">
                                <span style="font-size: 0.75rem; color: var(--text-light); text-transform: uppercase; letter-spacing: 0.05em;">
                                    <?php echo count($study['roles_data']); ?> Role<?php echo count($study['roles_data']) !== 1 ? 's' : ''; ?>
                                </span>
                                <span style="color: var(--accent-color); font-size: 0.875rem; font-weight: 500;">Select Role &rarr;</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        </div>
    </main>
</div>

<!-- Role Selection Modal -->
<div id="roleModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div style="background: white; padding: 2rem; border-radius: 12px; width: 90%; max-width: 400px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);">
        <h3 id="modalStudyName" style="margin-top: 0; font-size: 1.25rem; margin-bottom: 0.5rem;">Select Role</h3>
        <p style="color: var(--text-light); margin-bottom: 1.5rem; font-size: 0.875rem;">Choose a role to enter this study.</p>
        
        <div id="modalRoleList" style="display: flex; flex-direction: column; gap: 0.75rem;">
            <!-- Roles will be injected here -->
        </div>

        <button onclick="closeRoleModal()" style="margin-top: 1.5rem; width: 100%; padding: 0.75rem; background: transparent; border: 1px solid var(--border-color); color: var(--text-main); border-radius: 6px; cursor: pointer;">Cancel</button>
    </div>
</div>

<script src="assets/js/app.js"></script>
<script>
function openRoleModal(studyName, roles) {
    document.getElementById('roleModal').style.display = 'flex';
    document.getElementById('modalStudyName').textContent = studyName;
    
    const list = document.getElementById('modalRoleList');
    list.innerHTML = '';
    
    roles.forEach(role => {
        const btn = document.createElement('a');
        btn.href = 'dashboard.php?switch_context_id=' + role.id; // Direct switch to assignment ID
        btn.style.cssText = 'display: flex; align-items: center; justify-content: space-between; padding: 1rem; background: #f8fafc; border: 1px solid var(--border-color); border-radius: 8px; text-decoration: none; color: var(--text-main); font-weight: 500; transition: all 0.2s;';
        btn.innerHTML = `<span>${role.name}</span> <span class="material-icons-round" style="font-size: 1.25rem; color: var(--accent-color);">arrow_forward</span>`;
        btn.onmouseover = function() { this.style.borderColor = 'var(--accent-color)'; this.style.background = '#eff6ff'; };
        btn.onmouseout = function() { this.style.borderColor = 'var(--border-color)'; this.style.background = '#f8fafc'; };
        list.appendChild(btn);
    });
}

function closeRoleModal() {
    document.getElementById('roleModal').style.display = 'none';
}

// Close on outside click
document.getElementById('roleModal').addEventListener('click', function(e) {
    if (e.target === this) closeRoleModal();
});
</script>
</body>
</html>
