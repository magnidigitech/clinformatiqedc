<?php
/**
 * General Helper Functions
 */

function sanitizeInput($data) {
    return htmlspecialchars(strip_tags(trim($data)));
}

function redirect($url) {
    header("Location: " . $url);
    exit();
}

/**
 * Format date consistent with clinical standards
 */
function formatDate($date_string) {
    if (!$date_string) return '';
    return date('d-M-Y', strtotime($date_string));
}

/**
 * Check if the request is an API request (JSON)
 */
function isApiRequest() {
    return  (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') ||
            (strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
}

/**
 * Send JSON response
 */
function jsonResponse($success, $message, $data = []) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ]);
    exit();
}

/**
 * Render Role Switcher in Study Header
 */
function renderRoleSwitcher($study_id) {
    $assignments = $_SESSION['assignments'] ?? [];
    $my_roles = [];
    foreach ($assignments as $a) {
        if ($a['study_id'] == $study_id) {
            $my_roles[] = $a;
        }
    }
    
    // If only 1 role, just show text
    if (count($my_roles) <= 1) {
        echo '<span class="role-badge" style="font-size: 0.75rem;">'.htmlspecialchars($_SESSION['active_role_name']).'</span>';
        return;
    }
    
    // Dropdown for switching
    echo '<form method="POST" action="study.php" style="display:inline-block;">';
    echo '<input type="hidden" name="switch_role_study" value="1">';
    echo '<select name="new_role_id" onchange="this.form.submit()" style="padding: 0.25rem; font-size: 0.75rem; border-radius: 4px; border: 1px solid var(--border-color);">';
    foreach ($my_roles as $role) {
        $sel = ($role['id'] == $_SESSION['active_assignment_id']) ? 'selected' : '';
        echo '<option value="'.$role['id'].'" '.$sel.'>'.htmlspecialchars($role['role_name']).'</option>';
    }
    echo '</select>';
    echo '</form>';
}

/**
 * Generate Country Code (2 or 3 letters)
 */
function getCountryCode($country_name, $length = 2) {
    // Map common countries to standard ISO codes or abbreviations
    $map = [
        'India' => ($length == 2 ? 'IN' : 'IND'),
        'United States' => ($length == 2 ? 'US' : 'USA'),
        'United Kingdom' => ($length == 2 ? 'UK' : 'GBR'), // UK often used, ISO is GB
        'Germany' => ($length == 2 ? 'DE' : 'DEU'),
        // Add more as needed or use substr fallback
    ];
    
    if (isset($map[$country_name])) return $map[$country_name];
    
    return strtoupper(substr($country_name, 0, $length));
}
