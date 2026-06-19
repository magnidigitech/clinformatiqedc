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
        setActiveContext($assignments[0]['id']);
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
    // 1. Admin Override (Restricted to read-only for clinical data/queries)
    $role = $_SESSION['active_role_name'] ?? '';
    // Normalize mainly for comparison
    $role_lower = strtolower($role);

    if ($role_lower === 'admin') {
        $clinical_writes = ['add', 'add_subject', 'enter_data', 'edit', 'raise_query', 'verify'];
        if (in_array($permission_key, $clinical_writes)) {
            return false;
        }
        return true;
    }

    // 2. Strict Role Definitions
    
    // Data Coordinator (previously Data Manager): Can add subjects, enter data, view queries.
    if ($role_lower === 'data coordinator') {
        $allowed = ['view', 'add', 'add_subject', 'enter_data', 'edit', 'query'];
        return in_array($permission_key, $allowed);
    }
    
    // Data Entry: Can view/add subjects, enter/edit data.
    if ($role_lower === 'data entry') {
        $allowed = ['view', 'add_subject', 'enter_data', 'edit'];
        return in_array($permission_key, $allowed);
    }
    
    // Data Monitor / Data Manager: Can Query + Verify. NO add/edit subjects/data.
    if ($role_lower === 'data monitor' || $role_lower === 'data manager') {
        // Added 'verify' for SDV (Source Data Verification)
        $allowed = ['view', 'query', 'raise_query', 'verify'];
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
    // Refresh assignments dynamically to pull in any database updates (e.g. Data Manager role)
    refreshUserAssignments($_SESSION['user_id']);
    
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
