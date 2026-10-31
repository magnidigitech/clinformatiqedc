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
    
    // Set default active context only if no valid active assignment is already set
    if (!empty($assignments)) {
        $active_id = $_SESSION['active_assignment_id'] ?? null;
        $active_role = $_SESSION['active_role_name'] ?? null;
        $active_study = $_SESSION['active_study_id'] ?? null;
        $already_valid = false;

        if ($active_id) {
            foreach ($assignments as $a) {
                if ($a['id'] == $active_id) {
                    $already_valid = true;
                    setActiveContext($a['id']);
                    break;
                }
            }
        }

        // If ID wasn't found (e.g. after bulk role re-assignment), try matching by role name and study
        if (!$already_valid && $active_role && $active_study) {
            $target_norm = strtolower(trim($active_role));
            foreach ($assignments as $a) {
                if ($a['study_id'] == $active_study && strtolower(trim($a['role_name'] ?? '')) === $target_norm) {
                    $already_valid = true;
                    setActiveContext($a['id']);
                    break;
                }
            }
        }

        if (!$already_valid) {
            $default_id = $assignments[0]['id'];
            $has_admin = false;
            foreach ($assignments as $a) {
                if (strtolower(trim($a['role_name'] ?? '')) === 'admin') {
                    $default_id = $a['id'];
                    $has_admin = true;
                    break;
                }
            }
            // If not admin, prioritize Data Coordinator as the default active role
            if (!$has_admin) {
                foreach ($assignments as $a) {
                    if (isCoordinatorRole($a['role_name'] ?? '')) {
                        $default_id = $a['id'];
                        break;
                    }
                }
            }
            setActiveContext($default_id);
        }
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
    
    // If we have an active assignment, verify it still exists.
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
    
    // If active assignment ID wasn't found (e.g. after bulk role re-assignment), try role name & study recovery
    if (!$still_valid && !empty($assignments)) {
        $saved_role_norm = strtolower(trim($_SESSION['active_role_name'] ?? ''));
        $saved_study_id = $_SESSION['active_study_id'] ?? null;

        if ($saved_role_norm !== '' && $saved_study_id) {
            foreach ($assignments as $assign) {
                if ($assign['study_id'] == $saved_study_id && strtolower(trim($assign['role_name'] ?? '')) === $saved_role_norm) {
                    $still_valid = true;
                    setActiveContext($assign['id']);
                    break;
                }
            }
        }

        // Try matching any assignment in the currently active study
        if (!$still_valid && $saved_study_id) {
            foreach ($assignments as $assign) {
                if ($assign['study_id'] == $saved_study_id) {
                    $still_valid = true;
                    setActiveContext($assign['id']);
                    break;
                }
            }
        }

        // If no assignment exists for the active study at all, fall back to first assignment
        if (!$still_valid) {
            setActiveContext($assignments[0]['id']);
        }
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
 * Helper to test if a role name corresponds to Data Coordinator (case-insensitive & spelling-resilient)
 */
function isCoordinatorRole($role_name) {
    if (!$role_name) return false;
    $r = strtolower(trim((string)$role_name));
    return (
        $r === 'data coordinator' ||
        $r === 'coordinator' ||
        $r === 'data cordinator' ||
        $r === 'cordinator' ||
        $r === 'data entry' ||
        $r === 'crc' ||
        $r === 'clinical research coordinator' ||
        $r === 'site coordinator' ||
        $r === 'study coordinator' ||
        strpos($r, 'coordinator') !== false ||
        strpos($r, 'cordinator') !== false ||
        strpos($r, 'entry') !== false ||
        strpos($r, 'crc') !== false
    );
}

/**
 * Check if logged-in user is assigned the Data Coordinator role in the current study
 */
function userHasCoordinatorRole($study_id = null) {
    $active = $_SESSION['active_role_name'] ?? '';
    if (isCoordinatorRole($active)) {
        return true;
    }
    if (!$study_id && isset($_SESSION['active_study_id'])) {
        $study_id = $_SESSION['active_study_id'];
    }
    if ($study_id && !empty($_SESSION['assignments'])) {
        foreach ($_SESSION['assignments'] as $assign) {
            if ($assign['study_id'] == $study_id && isCoordinatorRole($assign['role_name'] ?? '')) {
                return true;
            }
        }
    }
    return false;
}

/**
 * Check if current user has specific permission
 */
function hasPermission($permission_key) {
    $role = $_SESSION['active_role_name'] ?? '';
    $role_lower = strtolower(trim($role));

    // 1. Admin Override (Full access to all EDC actions when in Admin role or no specific role)
    if ($role_lower === 'admin' || (isset($_SESSION['username']) && strtolower(trim($_SESSION['username'])) === 'admin' && ($role_lower === 'admin' || $role_lower === 'none' || $role_lower === ''))) {
        return true;
    }

    // Special permission: adding subjects - Data Coordinator & Admin only
    if ($permission_key === 'add_subject') {
        return isCoordinatorRole($role_lower) || userHasCoordinatorRole();
    }

    // 2. Data Coordinator: The ONLY role (along with Admin) that can add subjects and enter clinical data
    if (isCoordinatorRole($role_lower)) {
        $allowed = ['view', 'add', 'add_subject', 'enter_data', 'edit', 'query'];
        return in_array($permission_key, $allowed);
    }
    
    // 3. Data Monitor / Data Manager: Can Query + Verify. CANNOT add subjects or edit clinical data.
    if (strpos($role_lower, 'monitor') !== false || strpos($role_lower, 'manager') !== false || strpos($role_lower, 'manage') !== false || $role_lower === 'dm' || strpos($role_lower, 'dm ') !== false || strpos($role_lower, 'cra') !== false || strpos($role_lower, 'clinical research associate') !== false) {
        $allowed = ['view', 'query', 'raise_query', 'verify', 'monitor_review', 'manager_review', 'monitor_revoke', 'manager_revoke'];
        return in_array($permission_key, $allowed);
    }

    // 4. Principal Investigator (PI): Can view data, view queries, and perform PI Sign Off ('sign_off'). CANNOT add subjects.
    if (strpos($role_lower, 'investigator') !== false || $role_lower === 'pi') {
        $allowed = ['view', 'query', 'sign_off'];
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

    // In-page Role Switch within Study support (Processed FIRST to avoid interference)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['switch_role_study']) && isset($_POST['new_role_id']) && $_POST['new_role_id'] !== '') {
        $target_id = $_POST['new_role_id'];
        $target_study_id = $_POST['switch_study_id'] ?? $_SESSION['active_study_id'] ?? null;
        $valid_assignment_id = null;
        $target_norm = strtolower(trim((string)$target_id));
        
        $assignments = $_SESSION['assignments'] ?? [];
        if (empty($assignments) && isset($_SESSION['user_id'])) {
            try {
                $pdo = getDB();
                $stmt = $pdo->prepare("
                    SELECT su.*, su.role_name, su.permissions, s.name as study_name, s.study_code, s.created_at as study_created_at 
                    FROM study_users su 
                    JOIN studies s ON su.study_id = s.id 
                    WHERE su.user_id = :uid
                ");
                $stmt->execute(['uid' => $_SESSION['user_id']]);
                $assignments = $stmt->fetchAll();
                $_SESSION['assignments'] = $assignments;
            } catch (\Throwable $e) {}
        }
        
        // 1. Match within target study by assignment id or role name
        foreach ($assignments as $assign) {
            $study_match = (!$target_study_id || $assign['study_id'] == $target_study_id);
            if ($study_match) {
                if ($assign['id'] == $target_id || strtolower(trim($assign['role_name'] ?? '')) === $target_norm) {
                    $valid_assignment_id = $assign['id'];
                    break;
                }
            }
        }
        
        // 2. Fallback: match across all user assignments
        if (!$valid_assignment_id) {
            foreach ($assignments as $assign) {
                if ($assign['id'] == $target_id || strtolower(trim($assign['role_name'] ?? '')) === $target_norm) {
                    $valid_assignment_id = $assign['id'];
                    break;
                }
            }
        }
        
        if ($valid_assignment_id && setActiveContext($valid_assignment_id)) {
            $raw_redirect = $_POST['redirect_to'] ?? $_SERVER['REQUEST_URI'] ?? 'study.php';
            $redirect_to = htmlspecialchars_decode($raw_redirect, ENT_QUOTES);
            redirect($redirect_to);
        }
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
