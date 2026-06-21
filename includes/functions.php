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
    
    // Get current URL
    $current_url = $_SERVER['REQUEST_URI'] ?? 'study.php';
    
    // Dropdown for switching
    echo '<form method="POST" action="study.php" style="display:inline-block;">';
    echo '<input type="hidden" name="switch_role_study" value="1">';
    echo '<input type="hidden" name="redirect_to" value="'.htmlspecialchars($current_url).'">';
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

/**
 * Review status resolver based on Status Matrix
 */
function getFormReviewStatus($f_stat) {
    $sdr = (bool)($f_stat['sdr_submitted'] ?? false);
    $monitor = (bool)($f_stat['monitor_reviewed'] ?? false);
    $manager = (bool)($f_stat['manager_reviewed'] ?? false);
    $progress = (int)($f_stat['progress'] ?? 0);
    $status_str = $f_stat['status'] ?? 'empty';
    
    if (!$sdr) {
        if ($status_str === 'complete' || $progress == 100) {
            return ['text' => 'Draft (100% Complete)', 'color' => '#0d8e6f', 'bg' => '#f0fdf4', 'icon' => 'check_circle', 'progress' => 0, 'bar_color' => '#cbd5e1'];
        } elseif ($status_str === 'in_progress' || $progress > 0) {
            return ['text' => 'Draft (In Progress)', 'color' => '#1d6f97', 'bg' => '#eef7fa', 'icon' => 'hourglass_top', 'progress' => 0, 'bar_color' => '#cbd5e1'];
        } else {
            return ['text' => 'Draft (Empty)', 'color' => '#94a3b8', 'bg' => '#f1f5f9', 'icon' => 'radio_button_unchecked', 'progress' => 0, 'bar_color' => '#cbd5e1'];
        }
    } else {
        if ($monitor && $manager) {
            return ['text' => 'SRVed', 'color' => '#0d8e6f', 'bg' => '#f0fdf4', 'icon' => 'verified', 'progress' => 100, 'bar_color' => '#0d8e6f'];
        } elseif ($monitor || $manager) {
            $reviewer = $monitor ? 'Monitor' : 'Manager';
            return ['text' => $reviewer . ' Reviewed', 'color' => '#ea580c', 'bg' => '#fff7ed', 'icon' => 'rate_review', 'progress' => 50, 'bar_color' => '#f97316'];
        } else {
            return ['text' => 'SDR Submitted', 'color' => '#1d6f97', 'bg' => '#eef7fa', 'icon' => 'send', 'progress' => 0, 'bar_color' => '#1d6f97'];
        }
    }
}

/**
 * Render SDR / Review Workflow buttons.
 */
function renderWorkflowButtons($is_coordinator, $is_monitor_role, $is_manager_role, $sdr_submitted, $monitor_reviewed, $manager_reviewed, $all_mandatory_completed) {
    $html = '';
    if ($is_coordinator) {
        if (!$sdr_submitted) {
            $html .= '<button type="button" class="btn btn-primary" style="background-color: #1d6f97; border-color: #1d6f97; display: inline-flex; align-items: center; gap: 0.25rem;" onclick="updateReviewStatus(\'mark_sdr\')">
                        <span class="material-icons-round" style="font-size: 1.1rem;">lock</span>
                        Mark as SDR
                      </button>';
        } elseif ($sdr_submitted && !($monitor_reviewed && $manager_reviewed)) {
            $html .= '<button type="button" class="btn btn-outline" style="color: #dc2626; border-color: #dc2626; display: inline-flex; align-items: center; gap: 0.25rem;" onclick="updateReviewStatus(\'revoke_sdr\')">
                        <span class="material-icons-round" style="font-size: 1.1rem;">lock_open</span>
                        Revoke SDR
                      </button>';
        }
    }
    if ($is_monitor_role && $sdr_submitted) {
        if (!$monitor_reviewed) {
            $html .= '<button type="button" class="btn btn-primary" style="background-color: #ea580c; border-color: #ea580c; display: inline-flex; align-items: center; gap: 0.25rem;" onclick="updateReviewStatus(\'monitor_review\')">
                        <span class="material-icons-round" style="font-size: 1.1rem;">rate_review</span>
                        Mark as Reviewed
                      </button>';
        } else {
            $html .= '<button type="button" class="btn btn-outline" style="color: #ea580c; border-color: #ea580c; display: inline-flex; align-items: center; gap: 0.25rem;" onclick="updateReviewStatus(\'monitor_revoke\')">
                        <span class="material-icons-round" style="font-size: 1.1rem;">undo</span>
                        Revoke Review
                      </button>';
        }
    }
    if ($is_manager_role && $sdr_submitted) {
        if (!$manager_reviewed) {
            $html .= '<button type="button" class="btn btn-primary" style="background-color: #0d8e6f; border-color: #0d8e6f; display: inline-flex; align-items: center; gap: 0.25rem;" onclick="updateReviewStatus(\'manager_review\')">
                        <span class="material-icons-round" style="font-size: 1.1rem;">verified</span>
                        Mark as Reviewed
                      </button>';
        } else {
            $html .= '<button type="button" class="btn btn-outline" style="color: #0d8e6f; border-color: #0d8e6f; display: inline-flex; align-items: center; gap: 0.25rem;" onclick="updateReviewStatus(\'manager_revoke\')">
                        <span class="material-icons-round" style="font-size: 1.1rem;">undo</span>
                        Revoke Review
                      </button>';
        }
    }
    return $html;
}

/**
 * Render Header save/next actions.
 */
function renderHeaderActions($prev_link, $next_link, $can_edit, $is_verified) {
    $html = '';
    if ($prev_link) {
        $html .= '<a href="' . $prev_link . '" class="btn btn-outline">Previous</a> ';
    }
    $html .= '<button class="btn btn-outline" onclick="location.reload()">' . ($can_edit ? 'Discard Changes' : 'Refresh') . '</button> ';
    
    if ($can_edit && !$is_verified) {
        $html .= '<button class="btn btn-primary" onclick="saveData(false)">Save</button> ';
        if ($next_link) {
            $html .= '<button class="btn btn-primary" style="background:#0d8e6f;" onclick="saveData(true)">Save & Next</button> ';
        }
    }
    if (!$can_edit && $next_link) {
        $html .= '<a href="' . $next_link . '" class="btn btn-primary">Next</a>';
    }
    return $html;
}

/**
 * Render Form Audit Trail Table.
 */
function renderFormAuditTrail($pdo, $study_id, $subject_id, $current_form_id, $repeating_instance_id) {
    $rep_inst_id = (int)$repeating_instance_id;
    $stmt_audit = $pdo->prepare("
        SELECT a.*, 
               COALESCE(u.name, u.username) as action_by_name, 
               u.username as action_by_username,
               ff.label as field_label,
               (SELECT role_name FROM study_users WHERE user_id = a.action_by AND study_id = a.study_id LIMIT 1) as action_role
        FROM data_audit_log a
        LEFT JOIN users u ON a.action_by = u.id
        LEFT JOIN form_fields ff ON a.field_id = ff.id
        WHERE a.subject_id = ? 
          AND a.form_id = ? 
          AND (a.repeating_instance_id = ? OR (? = 0 AND a.repeating_instance_id IS NULL))
        ORDER BY a.action_at DESC, a.id DESC
    ");
    $stmt_audit->execute([$subject_id, $current_form_id, $rep_inst_id, $rep_inst_id]);
    $form_audit_trail = $stmt_audit->fetchAll(PDO::FETCH_ASSOC);

    if (empty($form_audit_trail)) {
        return '<div style="padding: 2.5rem; text-align: center; color: #94a3b8;">
                    <span class="material-icons-round" style="font-size: 2.5rem; margin-bottom: 0.5rem; display: block;">info_outline</span>
                    No audit history recorded for this form yet.
                </div>';
    }

    $html = '<table style="width: 100%; border-collapse: collapse; font-size: 0.875rem;">
                <thead>
                    <tr style="background: #f8fafc; border-bottom: 1px solid var(--border-color); text-align: left;">
                        <th style="padding: 0.75rem 1rem; color: #64748b; font-weight: 600;">Timestamp</th>
                        <th style="padding: 0.75rem 1rem; color: #64748b; font-weight: 600;">User</th>
                        <th style="padding: 0.75rem 1rem; color: #64748b; font-weight: 600;">Role</th>
                        <th style="padding: 0.75rem 1rem; color: #64748b; font-weight: 600;">Action / Details</th>
                    </tr>
                </thead>
                <tbody>';

    foreach ($form_audit_trail as $audit) {
        $formatted_time = date('d-M-Y H:i:s', strtotime($audit['action_at']));
        $role_display = htmlspecialchars($audit['action_role'] ?: 'Coordinator');
        
        $action_desc = '';
        $change_type = $audit['change_type'];
        
        if ($change_type === 'sdr_submitted') {
            $action_desc = '<span style="color: #1d6f97; font-weight: 600;">Form Marked as SDR</span>';
        } elseif ($change_type === 'sdr_revoked') {
            $action_desc = '<span style="color: #dc2626; font-weight: 600;">SDR Revoked</span>';
            if (!empty($audit['reason_for_change'])) {
                $action_desc .= '<br/><span style="font-size: 0.825rem; color: #475569;">' . htmlspecialchars($audit['reason_for_change']) . '</span>';
            }
        } elseif ($change_type === 'monitor_reviewed') {
            $action_desc = '<span style="color: #ea580c; font-weight: 600;">Monitor Review Completed</span>';
        } elseif ($change_type === 'monitor_revoked') {
            $action_desc = '<span style="color: #ea580c; font-weight: 600;">Monitor Review Revoked</span><br/><span style="font-size: 0.825rem; color: #475569; font-style: italic;">Remarks: ' . htmlspecialchars($audit['reason_for_change']) . '</span>';
        } elseif ($change_type === 'manager_reviewed') {
            $action_desc = '<span style="color: #0d8e6f; font-weight: 600;">Manager Review Completed</span>';
        } elseif ($change_type === 'manager_revoked') {
            $action_desc = '<span style="color: #0d8e6f; font-weight: 600;">Manager Review Revoked</span><br/><span style="font-size: 0.825rem; color: #475569; font-style: italic;">Remarks: ' . htmlspecialchars($audit['reason_for_change']) . '</span>';
        } elseif ($change_type === 'form_srved') {
            $action_desc = '<span style="color: #0d8e6f; font-weight: 600;">Form SRVed</span>';
        } elseif ($change_type === 'verify') {
            $action_desc = '<span style="color: #0d8e6f; font-weight: 600;">Source Data Verification</span>';
        } else {
            $field_lbl = htmlspecialchars($audit['field_label'] ?: 'Unknown Field');
            $old_val = htmlspecialchars($audit['old_value'] ?? '');
            $new_val = htmlspecialchars($audit['new_value'] ?? '');
            
            if ($change_type === 'insert') {
                $action_desc = "Field <strong>\"{$field_lbl}\"</strong> initialized to value: <code>\"{$new_val}\"</code>";
            } elseif ($change_type === 'update') {
                $action_desc = "Field <strong>\"{$field_lbl}\"</strong> updated: <code>\"{$old_val}\"</code> &rarr; <code>\"{$new_val}\"</code>";
            } elseif ($change_type === 'clear') {
                $action_desc = "Field <strong>\"{$field_lbl}\"</strong> cleared. Reason: <em>\"" . htmlspecialchars($audit['reason_for_change']) . "\"</em>";
            } elseif ($change_type === 'missing_code') {
                $action_desc = "Field <strong>\"{$field_lbl}\"</strong> marked as missing (code: <code>\"{$new_val}\"</code>). Reason: <em>\"" . htmlspecialchars($audit['reason_for_change']) . "\"</em>";
            } else {
                $action_desc = htmlspecialchars($audit['reason_for_change']);
            }
        }

        $html .= '<tr style="border-bottom: 1px solid #f1f5f9;">
                    <td style="padding: 0.75rem 1rem; color: #64748b; white-space: nowrap;">' . $formatted_time . '</td>
                    <td style="padding: 0.75rem 1rem; font-weight: 500; color: #334155;">' . htmlspecialchars($audit['action_by_name']) . '</td>
                    <td style="padding: 0.75rem 1rem; color: #64748b;">' . $role_display . '</td>
                    <td style="padding: 0.75rem 1rem; color: #334155;">' . $action_desc . '</td>
                  </tr>';
    }

    $html .= '</tbody></table>';
    return $html;
}

/**
 * Calculate the overall Review Status of a Subject
 */
function getSubjectReviewStatus($pdo, $subject_id) {
    // Fetch all form statuses for this subject
    $stmt = $pdo->prepare("SELECT * FROM subject_form_status WHERE subject_id = ?");
    $stmt->execute([$subject_id]);
    $statuses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($statuses)) {
        return ['text' => 'Draft', 'color' => '#64748b', 'bg' => '#f1f5f9'];
    }
    
    $any_completed = false;
    $all_sdr = true;
    $all_monitor = true;
    $all_manager = true;
    
    foreach ($statuses as $s) {
        $is_complete = (bool)($s['is_complete'] ?? false) || ($s['status'] === 'complete');
        if ($is_complete) {
            $any_completed = true;
            if (empty($s['sdr_submitted'])) {
                $all_sdr = false;
            }
            if (empty($s['monitor_reviewed'])) {
                $all_monitor = false;
            }
            if (empty($s['manager_reviewed'])) {
                $all_manager = false;
            }
        }
    }
    
    if (!$any_completed) {
        return ['text' => 'Draft', 'color' => '#64748b', 'bg' => '#f1f5f9'];
    }
    
    if ($all_monitor && $all_manager && $all_sdr) {
        return ['text' => 'SRVed', 'color' => '#0d8e6f', 'bg' => '#f0fdf4'];
    }
    
    if ($all_manager && $all_sdr) {
        return ['text' => 'Manager Reviewed', 'color' => '#0d8e6f', 'bg' => '#f0fdf4'];
    }
    
    if ($all_monitor && $all_sdr) {
        return ['text' => 'Monitor Reviewed', 'color' => '#ea580c', 'bg' => '#fff7ed'];
    }
    
    if ($all_sdr) {
        return ['text' => 'SDR Submitted', 'color' => '#1d6f97', 'bg' => '#eef7fa'];
    }
    
    return ['text' => 'Draft', 'color' => '#64748b', 'bg' => '#f1f5f9'];
}

