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
    
    if ($status_str === 'skipped' || $status_str === 'disabled') {
        return ['text' => 'Not Applicable', 'color' => '#94a3b8', 'bg' => '#f1f5f9', 'icon' => 'block', 'progress' => 100, 'bar_color' => '#cbd5e1'];
    }
    
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
            return ['text' => 'SDVed & DM Reviewed', 'color' => '#0d8e6f', 'bg' => '#f0fdf4', 'icon' => 'verified', 'progress' => 100, 'bar_color' => '#0d8e6f'];
        } elseif ($monitor) {
            return ['text' => 'SDVed', 'color' => '#059669', 'bg' => '#ecfdf5', 'icon' => 'verified', 'progress' => 50, 'bar_color' => '#059669'];
        } elseif ($manager) {
            return ['text' => 'DM Reviewed', 'color' => '#059669', 'bg' => '#ecfdf5', 'icon' => 'verified', 'progress' => 50, 'bar_color' => '#059669'];
        } else {
            return ['text' => 'Under Review', 'color' => '#b45309', 'bg' => '#fef3c7', 'icon' => 'pending_actions', 'progress' => 0, 'bar_color' => '#f59e0b'];
        }
    }
}

/**
 * Render SDR / Review Workflow buttons.
 */
function renderWorkflowButtons($is_coordinator, $is_monitor_role, $is_manager_role, $sdr_submitted, $monitor_reviewed, $manager_reviewed, $all_mandatory_completed) {
    $html = '';
    
    // Data Coordinator actions
    if ($is_coordinator) {
        if (!$sdr_submitted) {
            $html .= '<button type="button" class="btn btn-primary" style="background-color: #1d6f97; border-color: #1d6f97; display: inline-flex; align-items: center; gap: 0.25rem;" onclick="updateReviewStatus(\'mark_sdr\')">
                        <span class="material-icons-round" style="font-size: 1.1rem;">lock</span>
                        Mark as SDR
                      </button>';
        } elseif ($sdr_submitted && !$monitor_reviewed && !$manager_reviewed) {
            // Coordinator can only revoke SDR if neither Monitor SDVed nor Manager DM Reviewed
            $html .= '<button type="button" class="btn btn-outline" style="color: #dc2626; border-color: #dc2626; display: inline-flex; align-items: center; gap: 0.25rem;" onclick="updateReviewStatus(\'revoke_sdr\')">
                        <span class="material-icons-round" style="font-size: 1.1rem;">lock_open</span>
                        Revoke SDR
                      </button>';
        }
    }
    
    // Data Monitor actions
    if ($is_monitor_role && $sdr_submitted) {
        if (!$monitor_reviewed) {
            $html .= '<button type="button" class="btn btn-primary" style="background-color: #059669; border-color: #059669; display: inline-flex; align-items: center; gap: 0.25rem;" onclick="updateReviewStatus(\'monitor_review\')">
                        <span class="material-icons-round" style="font-size: 1.1rem;">verified</span>
                        Mark as SDVed
                      </button>';
        } else {
            $html .= '<button type="button" class="btn btn-outline" style="color: #dc2626; border-color: #dc2626; display: inline-flex; align-items: center; gap: 0.25rem;" onclick="updateReviewStatus(\'monitor_revoke\')">
                        <span class="material-icons-round" style="font-size: 1.1rem;">undo</span>
                        Revoke SDVed
                      </button>';
        }
    }
    
    // Data Manager actions
    if ($is_manager_role && $sdr_submitted) {
        if (!$manager_reviewed) {
            $html .= '<button type="button" class="btn btn-primary" style="background-color: #059669; border-color: #059669; display: inline-flex; align-items: center; gap: 0.25rem;" onclick="updateReviewStatus(\'manager_review\')">
                        <span class="material-icons-round" style="font-size: 1.1rem;">verified</span>
                        Mark as DM Reviewed
                      </button>';
        } else {
            $html .= '<button type="button" class="btn btn-outline" style="color: #dc2626; border-color: #dc2626; display: inline-flex; align-items: center; gap: 0.25rem;" onclick="updateReviewStatus(\'manager_revoke\')">
                        <span class="material-icons-round" style="font-size: 1.1rem;">undo</span>
                        Revoke DM Reviewed
                      </button>';
        }
    }

    // Display Form Locked badge when Coordinator views a form where Monitor SDVed or Manager DM Reviewed
    if ($is_coordinator && !$is_monitor_role && !$is_manager_role && $sdr_submitted && ($monitor_reviewed || $manager_reviewed)) {
        $html .= '<span class="badge" style="background: #f1f5f9; color: #64748b; padding: 0.35rem 0.75rem; border-radius: 6px; font-size: 0.8rem; font-weight: 500; display: inline-flex; align-items: center; gap: 0.25rem;">
                    <span class="material-icons-round" style="font-size: 1rem;">lock</span> Form Locked
                  </span>';
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
        $html .= '<button class="btn btn-primary" onclick="saveData(false, this)">Save</button> ';
        if ($next_link) {
            $html .= '<button class="btn btn-primary" style="background:#0d8e6f;" onclick="saveData(true, this)">Save & Next</button> ';
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
            $action_desc = '<span style="color: #059669; font-weight: 600;">Marked as SDVed (Data Monitor)</span>';
        } elseif ($change_type === 'monitor_revoked') {
            $action_desc = '<span style="color: #dc2626; font-weight: 600;">Revoked SDVed</span><br/><span style="font-size: 0.825rem; color: #475569; font-style: italic;">Remarks: ' . htmlspecialchars($audit['reason_for_change']) . '</span>';
        } elseif ($change_type === 'manager_reviewed') {
            $action_desc = '<span style="color: #059669; font-weight: 600;">Marked as DM Reviewed (Data Manager)</span>';
        } elseif ($change_type === 'manager_revoked') {
            $action_desc = '<span style="color: #dc2626; font-weight: 600;">Revoked DM Reviewed</span><br/><span style="font-size: 0.825rem; color: #475569; font-style: italic;">Remarks: ' . htmlspecialchars($audit['reason_for_change']) . '</span>';
        } elseif ($change_type === 'form_srved') {
            $action_desc = '<span style="color: #0d8e6f; font-weight: 600;">SDVed & DM Reviewed</span>';
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
    // 1. Get study ID for this subject
    $stmt_sub = $pdo->prepare("SELECT study_id FROM subjects WHERE id = ?");
    $stmt_sub->execute([$subject_id]);
    $study_id = $stmt_sub->fetchColumn();
    
    if (!$study_id) {
        return ['text' => 'Draft', 'color' => '#64748b', 'bg' => '#f1f5f9'];
    }

    // 2. Fetch all main forms (linked to visits) for this study
    $stmt_main_forms = $pdo->prepare("
        SELECT f.id FROM study_forms f
        JOIN study_visits v ON f.visit_id = v.id
        WHERE v.study_id = ?
    ");
    $stmt_main_forms->execute([$study_id]);
    $main_form_ids = $stmt_main_forms->fetchAll(PDO::FETCH_COLUMN);

    // 3. Fetch all active repeating instances for the subject
    $stmt_rep_instances = $pdo->prepare("
        SELECT id, repeating_module_id FROM subject_repeating_instances
        WHERE subject_id = ? AND status = 'active'
    ");
    $stmt_rep_instances->execute([$subject_id]);
    $rep_instances = $stmt_rep_instances->fetchAll(PDO::FETCH_ASSOC);

    // 4. Fetch all repeating module forms
    $stmt_rep_forms = $pdo->prepare("
        SELECT id, repeating_module_id FROM study_forms
        WHERE repeating_module_id IS NOT NULL
    ");
    $stmt_rep_forms->execute();
    $rep_forms_raw = $stmt_rep_forms->fetchAll(PDO::FETCH_ASSOC);
    
    $rep_module_forms = [];
    foreach ($rep_forms_raw as $rf) {
        $rep_module_forms[$rf['repeating_module_id']][] = $rf['id'];
    }

    // 5. Query all status entries for this subject in subject_form_status
    $stmt_status = $pdo->prepare("SELECT * FROM subject_form_status WHERE subject_id = ?");
    $stmt_status->execute([$subject_id]);
    $status_rows = $stmt_status->fetchAll(PDO::FETCH_ASSOC);
    
    // Index status entries by [form_id][repeating_instance_id]
    $status_map = [];
    foreach ($status_rows as $row) {
        $inst_id = (int)($row['repeating_instance_id'] ?? 0);
        $status_map[$row['form_id']][$inst_id] = $row;
    }

    // 6. Check if ALL main forms and repeating instance forms are completed and reviewed
    $all_completed_and_reviewed = true;
    $any_form_started = false;

    if (empty($main_form_ids)) {
        $all_completed_and_reviewed = false;
    } else {
        foreach ($main_form_ids as $fid) {
            $s = $status_map[$fid][0] ?? null;
            $is_complete = $s ? ((bool)($s['is_complete'] ?? false) || ($s['status'] === 'complete') || ((int)($s['progress_percent'] ?? 0) === 100)) : false;
            $is_reviewed = $s ? ((bool)($s['monitor_reviewed'] ?? false) && (bool)($s['manager_reviewed'] ?? false)) : false;
            
            if ($s) {
                $any_form_started = true;
            }
            if (!$is_complete || !$is_reviewed) {
                $all_completed_and_reviewed = false;
            }
        }
    }

    // Check repeating instances
    foreach ($rep_instances as $inst) {
        $inst_id = $inst['id'];
        $mod_id = $inst['repeating_module_id'];
        $forms = $rep_module_forms[$mod_id] ?? [];
        
        foreach ($forms as $fid) {
            $s = $status_map[$fid][$inst_id] ?? null;
            $is_complete = $s ? ((bool)($s['is_complete'] ?? false) || ($s['status'] === 'complete') || ((int)($s['progress_percent'] ?? 0) === 100)) : false;
            $is_reviewed = $s ? ((bool)($s['monitor_reviewed'] ?? false) && (bool)($s['manager_reviewed'] ?? false)) : false;
            
            if ($s) {
                $any_form_started = true;
            }
            if (!$is_complete || !$is_reviewed) {
                $all_completed_and_reviewed = false;
            }
        }
    }

    // If completely done and reviewed, return Completed
    if ($all_completed_and_reviewed) {
        return ['text' => 'Completed', 'color' => '#0d8e6f', 'bg' => '#f0fdf4'];
    }

    // 7. Fallback to calculating status of currently completed/started forms
    if (empty($status_rows) || !$any_form_started) {
        return ['text' => 'Draft', 'color' => '#64748b', 'bg' => '#f1f5f9'];
    }
    
    $any_completed = false;
    $all_sdr = true;
    $all_monitor = true;
    $all_manager = true;
    
    foreach ($status_rows as $s) {
        $is_complete = (bool)($s['is_complete'] ?? false) || ($s['status'] === 'complete') || ((int)($s['progress_percent'] ?? 0) === 100);
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
        return ['text' => 'SDVed & Approved', 'color' => '#0d8e6f', 'bg' => '#f0fdf4'];
    }
    
    if ($all_monitor || $all_manager) {
        return ['text' => 'SDVed', 'color' => '#059669', 'bg' => '#ecfdf5'];
    }
    
    if ($all_sdr) {
        return ['text' => 'Under Review', 'color' => '#b45309', 'bg' => '#fef3c7'];
    }
    
    return ['text' => 'Draft', 'color' => '#64748b', 'bg' => '#f1f5f9'];
}

/**
 * Ensure Common Forms DDL Tables Exist (Medical History, Adverse Events, Concomitant Medications)
 */
function ensureCommonFormsTables($pdo) {
    static $executed = false;
    if ($executed) return;
    $executed = true;

    $sql = "
    CREATE TABLE IF NOT EXISTS subject_common_records (
        id SERIAL PRIMARY KEY,
        study_id INT NOT NULL REFERENCES studies(id) ON DELETE CASCADE,
        subject_id INT NOT NULL REFERENCES subjects(id) ON DELETE CASCADE,
        form_type VARCHAR(10) NOT NULL,
        seq_number INT NOT NULL,
        record_number VARCHAR(50) NOT NULL,
        status VARCHAR(20) DEFAULT 'draft',
        sdr_status VARCHAR(20) DEFAULT 'pending',
        sdr_by INT NULL REFERENCES users(id) ON DELETE SET NULL,
        sdr_at TIMESTAMP NULL,
        sdr_revision INT DEFAULT 0,
        revision INT DEFAULT 1,
        is_voided BOOLEAN DEFAULT FALSE,
        void_reason TEXT NULL,
        voided_by INT NULL REFERENCES users(id) ON DELETE SET NULL,
        voided_at TIMESTAMP NULL,
        data_json TEXT NULL,
        created_by INT NULL REFERENCES users(id) ON DELETE SET NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT unq_subj_form_seq UNIQUE(subject_id, form_type, seq_number)
    );

    CREATE TABLE IF NOT EXISTS cm_record_links (
        id SERIAL PRIMARY KEY,
        cm_record_id INT NOT NULL REFERENCES subject_common_records(id) ON DELETE CASCADE,
        target_record_id INT NOT NULL REFERENCES subject_common_records(id) ON DELETE CASCADE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT unq_cm_target UNIQUE(cm_record_id, target_record_id)
    );

    CREATE TABLE IF NOT EXISTS common_form_queries (
        id SERIAL PRIMARY KEY,
        study_id INT NOT NULL REFERENCES studies(id) ON DELETE CASCADE,
        subject_id INT NOT NULL REFERENCES subjects(id) ON DELETE CASCADE,
        record_id INT NOT NULL REFERENCES subject_common_records(id) ON DELETE CASCADE,
        field_name VARCHAR(100) NULL,
        query_text TEXT NOT NULL,
        status VARCHAR(20) DEFAULT 'open',
        created_by INT NOT NULL REFERENCES users(id) ON DELETE SET NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS common_form_query_history (
        id SERIAL PRIMARY KEY,
        query_id INT NOT NULL REFERENCES common_form_queries(id) ON DELETE CASCADE,
        action_type VARCHAR(50) NOT NULL,
        remark TEXT NOT NULL,
        created_by INT NOT NULL REFERENCES users(id) ON DELETE SET NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS common_form_sdr_history (
        id SERIAL PRIMARY KEY,
        record_id INT NOT NULL REFERENCES subject_common_records(id) ON DELETE CASCADE,
        action VARCHAR(50) NOT NULL,
        reviewed_revision INT NOT NULL,
        action_by INT NOT NULL REFERENCES users(id) ON DELETE SET NULL,
        action_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS common_form_audit_log (
        id SERIAL PRIMARY KEY,
        study_id INT NOT NULL REFERENCES studies(id) ON DELETE CASCADE,
        subject_id INT NOT NULL REFERENCES subjects(id) ON DELETE CASCADE,
        record_id INT NOT NULL REFERENCES subject_common_records(id) ON DELETE CASCADE,
        field_name VARCHAR(100) NOT NULL,
        old_value TEXT NULL,
        new_value TEXT NULL,
        reason_for_change TEXT NULL,
        action_by INT NOT NULL REFERENCES users(id) ON DELETE SET NULL,
        action_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );
    ";

    try {
        $pdo->exec($sql);
    } catch (Exception $e) {
        // Ignore if existing
    }
}

/**
 * Evaluates whether a CRF field is applicable based on skip-logic conditions
 * and current form responses, handling cascading dependencies.
 */
function isFieldApplicable($field, $valuesByVar = [], $valuesById = [], $fieldsByVar = []) {
    $val_rules = json_decode($field['validation_rules'] ?? '{}', true);
    if (empty($val_rules['condition'])) {
        return true;
    }

    $cond = $val_rules['condition'];
    $parentField = $cond['field'] ?? '';
    $operator = $cond['operator'] ?? '==';
    $targetValue = $cond['value'] ?? '';

    // If parent field itself is defined and has conditions, check if parent is applicable
    if (!empty($fieldsByVar[$parentField])) {
        if (!isFieldApplicable($fieldsByVar[$parentField], $valuesByVar, $valuesById, $fieldsByVar)) {
            return false;
        }
    }

    // Look up parent value by variable name or field id
    $parentVal = $valuesByVar[$parentField] ?? ($valuesById[$parentField] ?? null);
    if ($parentVal === null) {
        return false;
    }

    $parentValArray = is_array($parentVal) ? $parentVal : explode(',', (string)$parentVal);
    $parentValArray = array_map('trim', $parentValArray);

    if (is_array($targetValue) || $operator === 'in') {
        $targets = is_array($targetValue) ? $targetValue : [$targetValue];
        foreach ($targets as $t) {
            if (in_array((string)$t, $parentValArray)) {
                return true;
            }
        }
        return false;
    }

    if ($operator === '!=') {
        return (string)$parentVal !== (string)$targetValue && !in_array((string)$targetValue, $parentValArray);
    }

    return (string)$parentVal === (string)$targetValue || in_array((string)$targetValue, $parentValArray);
}

/**
 * Checks whether all applicable mandatory fields for a form have been completed.
 * Uses isFieldApplicable() so conditionally skipped fields do not block SDR or progress.
 */
function areAllMandatoryFieldsCompleted($pdo, $subject_id, $form_id, $repeating_instance_id) {
    $stmt = $pdo->prepare("SELECT id, variable_name, is_required, validation_rules FROM form_fields WHERE form_id = ? ORDER BY order_index");
    $stmt->execute([$form_id]);
    $fields = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($fields)) return true;

    // Fetch existing values
    $stmt_vals = $pdo->prepare("SELECT field_id, value FROM subject_data WHERE subject_id = ? AND form_id = ? AND (repeating_instance_id = ? OR (? = 0 AND repeating_instance_id IS NULL))");
    $stmt_vals->execute([$subject_id, $form_id, $repeating_instance_id, $repeating_instance_id]);
    $saved_rows = $stmt_vals->fetchAll(PDO::FETCH_ASSOC);
    $valuesById = [];
    foreach ($saved_rows as $sr) {
        $valuesById[$sr['field_id']] = $sr['value'];
    }
    $valuesByVar = [];
    $fieldsByVar = [];
    foreach ($fields as $f) {
        $fieldsByVar[$f['variable_name']] = $f;
        if (isset($valuesById[$f['id']])) {
            $valuesByVar[$f['variable_name']] = $valuesById[$f['id']];
        }
    }

    foreach ($fields as $f) {
        if ($f['is_required']) {
            if (isFieldApplicable($f, $valuesByVar, $valuesById, $fieldsByVar)) {
                $val = $valuesById[$f['id']] ?? '';
                if ($val === '' || $val === null) {
                    return false;
                }
            }
        }
    }
    return true;
}

/**
 * Standard CRF Field Input Renderer
 * Renders HTML input elements matching field data types and option groups
 */
function renderFieldInput($field, $saved_value = '', $choices_map = []) {
    $type = $field['type'] ?? 'text';
    $var = $field['variable_name'] ?? ('var_' . ($field['id'] ?? '0'));
    $name = "field_" . ($field['id'] ?? '0');
    $val_rules = json_decode($field['validation_rules'] ?? '{}', true);
    
    // Auto-populate default values if empty
    if (($saved_value === '' || $saved_value === null) && isset($val_rules['default'])) {
        $saved_value = $val_rules['default'];
    }
    
    // RECIST method is read-only RECIST 1.1 per protocol specification
    if ($var === 'recist_method') {
        $saved_value = 'RECIST 1.1';
    }

    $value = htmlspecialchars((string)$saved_value); 
    
    // Check Global Edit Permission
    global $can_edit;
    $disabled = (isset($can_edit) && !$can_edit) ? 'disabled style="background:#f1f5f9; cursor:not-allowed;"' : '';

    // Helper for Option Groups
    $gid = $field['option_group_id'] ?? null;
    $options = ($gid && isset($choices_map[$gid])) ? $choices_map[$gid] : [];

    // Custom Readonly for RECIST method
    if ($var === 'recist_method') {
        echo '<input type="text" name="'.$name.'" data-var="'.$var.'" class="crf-input" value="RECIST 1.1" readonly style="background:#f8fafc; font-weight:600; color:#1e293b; cursor:default;">';
        return;
    }

    switch ($type) {
        case 'text':
        case 'email':
        case 'calculation':
        case 'link':
            echo '<input type="text" name="'.$name.'" data-var="'.$var.'" class="crf-input" value="'.$value.'" '.$disabled.'>';
            break;

        case 'number':
        case 'year':
            echo '<input type="number" name="'.$name.'" data-var="'.$var.'" class="crf-input" value="'.$value.'" '.$disabled.'>';
            break;

        case 'date':
            echo '<input type="date" name="'.$name.'" data-var="'.$var.'" class="crf-input" value="'.$value.'" placeholder="MM/DD/YYYY" '.$disabled.'>';
            break;

        case 'datetime':
            echo '<input type="datetime-local" name="'.$name.'" data-var="'.$var.'" class="crf-input" value="'.$value.'" '.$disabled.'>';
            break;

        case 'time':
            echo '<input type="time" name="'.$name.'" data-var="'.$var.'" class="crf-input" value="'.$value.'" placeholder="HH:MM" '.$disabled.'>';
            break;
            
        case 'textarea':
        case 'remark':
            echo '<textarea name="'.$name.'" data-var="'.$var.'" class="crf-input" rows="3" '.$disabled.'>'.$value.'</textarea>';
            break;

        case 'slider':
            $min = $val_rules['min'] ?? 0;
            $max = $val_rules['max'] ?? 100;
            echo '<div style="display:flex; align-items:center; gap:1rem;">';
            echo '<input type="range" name="'.$name.'" data-var="'.$var.'" min="'.$min.'" max="'.$max.'" value="'.($value !== '' ? $value : $min).'" oninput="this.nextElementSibling.innerText = this.value" '.$disabled.' style="flex:1;">';
            echo '<span style="font-weight:600; min-width:30px; text-align:right;">'.($value !== '' ? $value : $min).'</span>';
            echo '</div>';
            break;
            
        case 'dropdown':
             echo '<select name="'.$name.'" data-var="'.$var.'" class="crf-input" '.$disabled.'>';
             echo '<option value="">Select option</option>';
             if (!empty($options)) {
                 foreach ($options as $opt) {
                     $sel = ($value == $opt['value']) ? 'selected' : '';
                     echo '<option value="'.htmlspecialchars($opt['value']).'" '.$sel.'>'.htmlspecialchars($opt['label']).'</option>';
                 }
             } else {
                 if ($value !== '' && $value !== null) echo '<option value="'.$value.'" selected>'.$value.'</option>'; 
             }
             echo '</select>';
             break;
             
        case 'radio':
            echo '<div style="display:flex; flex-direction:column; gap:0.5rem;">';
            if (!empty($options)) {
                foreach ($options as $opt) {
                    $checked = (trim((string)$value) === trim((string)$opt['value'])) ? 'checked' : '';
                    echo '<label style="display:flex; align-items:center; gap:0.5rem; font-weight:normal;">';
                    echo '<input type="radio" name="'.$name.'" data-var="'.$var.'" value="'.htmlspecialchars($opt['value']).'" '.$checked.' '.$disabled.'> ';
                    echo htmlspecialchars($opt['label']);
                    echo '</label>';
                }
            } else {
                $checkedYes = ($value == 'Yes' || $value == '1') ? 'checked' : '';
                $checkedNo = ($value == 'No' || $value == '0') ? 'checked' : '';
                echo '<label><input type="radio" name="'.$name.'" data-var="'.$var.'" value="Yes" '.$checkedYes.' '.$disabled.'> Yes</label>';
                echo '<label><input type="radio" name="'.$name.'" data-var="'.$var.'" value="No" '.$checkedNo.' '.$disabled.'> No</label>';
            }
            echo '</div>';
            break;

        case 'checkbox':
            $saved_vals = [];
            if (($value !== '' && $value !== null) && strpos($value, '[') === 0) {
                 $saved_vals = json_decode(htmlspecialchars_decode($value), true) ?? [];
            } elseif ($value !== '' && $value !== null) {
                 $saved_vals = explode(',', $value);
            }

            echo '<div style="display:flex; flex-direction:column; gap:0.5rem;">';
            if (!empty($options)) {
                foreach ($options as $opt) {
                    $opt_val = trim((string)$opt['value']);
                    $checked = false;
                    foreach($saved_vals as $sv) {
                        if (trim((string)$sv) === $opt_val) {
                            $checked = true;
                            break;
                        }
                    }
                    $checked_str = $checked ? 'checked' : '';
                    echo '<label style="display:flex; align-items:center; gap:0.5rem; font-weight:normal;">';
                    echo '<input type="checkbox" name="'.$name.'[]" data-var="'.$var.'" value="'.htmlspecialchars($opt['value']).'" '.$checked_str.' '.$disabled.'> ';
                    echo htmlspecialchars($opt['label']);
                    echo '</label>';
                }
            } else {
                $checked = ($value == '1') ? 'checked' : '';
                echo '<label><input type="checkbox" name="'.$name.'[]" value="1" '.$checked.' '.$disabled.'> Checked</label>';
            }
            echo '</div>';
            break;

        case 'upload':
            echo '<input type="file" name="'.$name.'_file" class="crf-input" '.$disabled.'>';
            if ($value) {
                echo '<div style="margin-top:0.5rem; font-size:0.85rem;"><a href="uploads/'.htmlspecialchars($value).'" target="_blank" style="color:#1d6f97;">View Uploaded File</a></div>';
            }
            break;

        case 'image':
             echo '<div style="padding:1rem; border:1px dashed #cbd5e1; text-align:center; color:#94a3b8;">Image Placeholder</div>';
             break;
             
        default:
            echo '<input type="text" name="'.$name.'" data-var="'.$var.'" class="crf-input" value="'.$value.'" '.$disabled.'>';
    }
}



