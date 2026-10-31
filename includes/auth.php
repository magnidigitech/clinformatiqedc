<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';

/**
 * Attempt to login a user
 * @param string $card_identity (username or email)
 * @param string $password
 * @return array|bool User array on success, false on failure
 */
function loginUser($identity, $password) {
    $pdo = getDB();
    
    // Determine if identity is email or username
    $field = filter_var($identity, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE LOWER($field) = LOWER(:identity) LIMIT 1");
    $stmt->execute(['identity' => $identity]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        if (isset($user['status']) && $user['status'] === 'inactive') {
            return false; // Account is inactive
        }
        // regenerate session id to prevent fixation
        session_regenerate_id(true);
        
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['logged_in'] = true;
        
        // initialize roles
        initializeUserRoles($user['id']);
        
        return $user;
    }
    
    return false;
}

/**
 * Load user roles and set initial active role/study
 */
function initializeUserRoles($user_id) {
    $pdo = getDB();
    
    // Ensure Admin users and study creators also have Principal Investigator role assigned for their studies
    try {
        $pdo->exec("
            INSERT INTO study_users (user_id, study_id, role_name, permissions)
            SELECT DISTINCT su.user_id, su.study_id, 'Principal Investigator', '{\"view\": true, \"query\": true, \"sign_off\": true}'
            FROM study_users su
            WHERE (LOWER(su.role_name) = 'admin' OR su.study_id IN (SELECT id FROM studies WHERE created_by = " . (int)$user_id . "))
            AND su.user_id = " . (int)$user_id . "
            AND NOT EXISTS (
                SELECT 1 FROM study_users su2 
                WHERE su2.user_id = su.user_id 
                AND su2.study_id = su.study_id 
                AND (LOWER(su2.role_name) = 'principal investigator' OR LOWER(su2.role_name) = 'pi')
            )
        ");
    } catch (Throwable $e) { /* Ignore */ }

    // Get all assignments for this user
    // Updated to use 'study_users' table (New Schema)
    $stmt = $pdo->prepare("
        SELECT su.*, su.role_name, su.permissions, s.name as study_name, s.study_code, s.created_at as study_created_at 
        FROM study_users su 
        JOIN studies s ON su.study_id = s.id 
        WHERE su.user_id = :uid
    ");
    $stmt->execute(['uid' => $user_id]);
    $assignments = $stmt->fetchAll();
    
    $_SESSION['assignments'] = $assignments;
    
    // Set default active context (first assignment)
    if (!empty($assignments)) {
        $default_id = $assignments[0]['id'];
        foreach ($assignments as $a) {
            if (strtolower($a['role_name'] ?? '') === 'admin') {
                $default_id = $a['id'];
                break;
            }
        }
        setActiveContext($default_id);
    } else {
        // User has no studies assigned
        $_SESSION['active_assignment_id'] = null;
        $_SESSION['active_role_id'] = null;
        $_SESSION['active_study_id'] = null;
        $_SESSION['active_role_name'] = 'None';
        $_SESSION['active_study_name'] = 'None';
        $_SESSION['active_study_code'] = 'None';
        $_SESSION['active_permissions'] = [];
    }
}

/**
 * Refresh user assignments from DB to stay in sync with database changes
 */
function refreshUserAssignments($user_id) {
    $pdo = getDB();
    
    // Get all assignments for this user
    $stmt = $pdo->prepare("
        SELECT su.*, su.role_name, su.permissions, s.name as study_name, s.study_code, s.created_at as study_created_at 
        FROM study_users su 
        JOIN studies s ON su.study_id = s.id 
        WHERE su.user_id = :uid
    ");
    $stmt->execute(['uid' => $user_id]);
    $assignments = $stmt->fetchAll();
    
    $_SESSION['assignments'] = $assignments;
    
    // If we have an active assignment, verify it still exists. If not, reset/set to first.
    $active_id = $_SESSION['active_assignment_id'] ?? null;
    $still_valid = false;
    if ($active_id) {
        foreach ($assignments as $assign) {
            if ($assign['id'] == $active_id) {
                $still_valid = true;
                // Update active context session values in case permissions/names changed
                $_SESSION['active_study_id'] = $assign['study_id'];
                $_SESSION['active_role_name'] = $assign['role_name'];
                $_SESSION['active_study_name'] = $assign['study_name'];
                $_SESSION['active_study_code'] = $assign['study_code'] ?? 'None';
                $raw_perms = $assign['permissions'];
                if ($raw_perms === 'all') {
                    $_SESSION['active_permissions'] = ['all' => true];
                } else {
                    $perms = json_decode($raw_perms, true);
                    $_SESSION['active_permissions'] = $perms ?? [];
                }
                break;
            }
        }
    }
    
    if (!$still_valid && !empty($assignments)) {
        setActiveContext($assignments[0]['id']);
    } elseif (empty($assignments)) {
        $_SESSION['active_assignment_id'] = null;
        $_SESSION['active_role_id'] = null;
        $_SESSION['active_study_id'] = null;
        $_SESSION['active_role_name'] = 'None';
        $_SESSION['active_study_name'] = 'None';
        $_SESSION['active_study_code'] = 'None';
        $_SESSION['active_permissions'] = [];
    }
}

/**
 * Switch the active context (Role/Study pair)
 */
function setActiveContext($assignment_id) {
    // Verify this assignment belongs to the logged in user
    foreach ($_SESSION['assignments'] as $assign) {
        if ($assign['id'] == $assignment_id) {
            $_SESSION['active_assignment_id'] = $assign['id'];
            // $_SESSION['active_role_id'] = $assign['role_id']; // Deprecated with new schema
            $_SESSION['active_study_id'] = $assign['study_id'];
            $_SESSION['active_role_name'] = $assign['role_name'];
            $_SESSION['active_study_name'] = $assign['study_name'];
            $_SESSION['active_study_code'] = $assign['study_code'] ?? 'None';
            
            // Decode permissions JSON or handle 'all'
            $raw_perms = $assign['permissions'];
            if ($raw_perms === 'all') {
                $_SESSION['active_permissions'] = ['all' => true];
            } else {
                $perms = json_decode($raw_perms, true);
                $_SESSION['active_permissions'] = $perms ?? [];
            }
            
            return true;
        }
    }
    return false;
}

/**
 * Check if current user has specific permission
 */
function hasPermission($permission_key) {
    // 1. Adding subjects is permitted across all roles
    if ($permission_key === 'add_subject' || $permission_key === 'add') {
        return true;
    }

    // 2. Admin Override (Full access to all EDC actions)
    $role = $_SESSION['active_role_name'] ?? '';
    // Normalize mainly for comparison
    $role_lower = strtolower($role);

    if ($role_lower === 'admin' || (isset($_SESSION['username']) && strtolower($_SESSION['username']) === 'admin')) {
        return true;
    }

    // 3. Strict Role Definitions (Admin, Data Coordinator, Data Monitor, Data Manager)
    
    // Data Coordinator (Data Entry maps to Data Coordinator): Can add subjects, enter data, edit, view queries.
    if ($role_lower === 'data coordinator' || $role_lower === 'data entry') {
        $allowed = ['view', 'add', 'add_subject', 'enter_data', 'edit', 'query'];
        return in_array($permission_key, $allowed);
    }
    
    // Data Monitor / Data Manager: Can Query + Verify.
    if ($role_lower === 'data monitor' || $role_lower === 'data manager') {
        // Added 'verify' for SDV (Source Data Verification)
        $allowed = ['view', 'add', 'add_subject', 'query', 'raise_query', 'verify'];
        return in_array($permission_key, $allowed);
    }

    // Principal Investigator (PI): Can view data, view queries, and perform PI Sign Off ('sign_off').
    if ($role_lower === 'principal investigator' || $role_lower === 'pi' || strpos($role_lower, 'investigator') !== false) {
        $allowed = ['view', 'add', 'add_subject', 'query', 'sign_off'];
        return in_array($permission_key, $allowed);
    }

    // Fallback to DB permissions if any (legacy), or default deny
    $perms = $_SESSION['active_permissions'] ?? [];
    return isset($perms['all']) || isset($perms[$permission_key]);
}

/**
 * Check if user is logged in
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && $_SESSION['user_id'];
}

/**
 * Require login or redirect
 */
function requireLogin() {
    if (!isLoggedIn()) {
        header("Location: index.php");
        exit();
    }
    
    // Check if account has been deactivated
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT status FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user_status = $stmt->fetchColumn();
        if ($user_status === 'inactive') {
            logoutUser();
        }
    } catch (Exception $e) {
        // Ignore DB connection hiccups
    }

    // Refresh assignments dynamically to pull in any database updates
    try {
        refreshUserAssignments($_SESSION['user_id']);
    } catch (\Throwable $e) {
        error_log("Failed to refresh assignments: " . $e->getMessage());
    }
    
    // Prevent caching of protected pages
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Cache-Control: post-check=0, pre-check=0", false);
    header("Pragma: no-cache");
}

function logoutUser() {
    session_unset();
    session_destroy();
    header("Location: index.php");
    exit();
}
