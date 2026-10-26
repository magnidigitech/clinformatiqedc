<?php
require_once 'includes/functions.php';
require_once 'includes/auth.php';

requireLogin();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['active_study_id'])) {
    redirect('dashboard.php');
}

$study_id = $_SESSION['active_study_id'];
$pdo = getDB();

// Ensure Common Forms Tables Exist
ensureCommonFormsTables($pdo);

$current_common_form = strtolower(trim($_GET['common_form'] ?? ''));
if (!in_array($current_common_form, ['mh', 'ae', 'cm', 'ta', 'usv'], true)) {
    $current_common_form = '';
}

// --- AUTO-FIX SCHEMA ---
// Ensure the new workflow and status columns exist on the remote server
try {
    $pdo->exec("ALTER TABLE data_queries ADD COLUMN IF NOT EXISTS repeating_instance_id INT DEFAULT 0");
    $pdo->exec("ALTER TABLE data_comments ADD COLUMN IF NOT EXISTS repeating_instance_id INT DEFAULT 0");
    $pdo->exec("ALTER TABLE data_audit_log ADD COLUMN IF NOT EXISTS repeating_instance_id INT DEFAULT 0");
    $pdo->exec("ALTER TABLE subject_form_status ADD COLUMN IF NOT EXISTS sdr_submitted BOOLEAN DEFAULT FALSE");
    $pdo->exec("ALTER TABLE subject_form_status ADD COLUMN IF NOT EXISTS monitor_reviewed BOOLEAN DEFAULT FALSE");
    $pdo->exec("ALTER TABLE subject_form_status ADD COLUMN IF NOT EXISTS manager_reviewed BOOLEAN DEFAULT FALSE");
    $pdo->exec("ALTER TABLE subject_form_status ADD COLUMN IF NOT EXISTS pi_signed_off BOOLEAN DEFAULT FALSE");
    $pdo->exec("ALTER TABLE subject_form_status ADD COLUMN IF NOT EXISTS pi_signed_by INT NULL");
    $pdo->exec("ALTER TABLE subject_form_status ADD COLUMN IF NOT EXISTS pi_signed_at TIMESTAMP NULL");
} catch (Throwable $e) { /* Column likely exists or handled */ }
// -----------------------

// Helper to check roles
$current_role = strtolower($_SESSION['active_role_name'] ?? '');
// Monitor includes data monitor and data manager roles
$is_monitor = (strpos($current_role, 'monitor') !== false) || (strpos($current_role, 'manager') !== false);
// Manager includes coordinator (which represents data coordinator role)
$is_manager = (strpos($current_role, 'coordinator') !== false); 
$is_admin = (strpos($current_role, 'admin') !== false);

// Specific precise roles for workflow
$is_coordinator = (strpos($current_role, 'coordinator') !== false) || (strpos($current_role, 'entry') !== false) || (strpos($current_role, 'admin') !== false);
$is_monitor_role = (strpos($current_role, 'monitor') !== false) || (strpos($current_role, 'cra') !== false) || (strpos($current_role, 'admin') !== false);
$is_manager_role = (strpos($current_role, 'manager') !== false) || (strpos($current_role, 'admin') !== false);
$is_pi_role = (strpos($current_role, 'investigator') !== false) || ($current_role === 'pi') || (strpos($current_role, 'admin') !== false);

// Strict clinical content editor flag: Data Managers, Data Monitors, and Principal Investigators cannot edit clinical content.
$is_data_editor = ($is_coordinator || $is_admin) && (strpos($current_role, 'manager') === false) && (strpos($current_role, 'monitor') === false) && (strpos($current_role, 'investigator') === false) && ($current_role !== 'pi');
$can_edit = $is_data_editor;

// Review status resolver is now defined in includes/functions.php

// Helper to check mandatory fields completion
if (!function_exists('areAllMandatoryFieldsCompletedPHP')) {
function areAllMandatoryFieldsCompletedPHP($pdo, $subject_id, $form_id, $repeating_instance_id) {
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
}

// Mock Subject ID for now if not passed (or handle error)
$subject_id = $_GET['subject_id'] ?? 1;

// Fetch Subject Details
$stmt = $pdo->prepare("SELECT * FROM subjects WHERE id = ? AND study_id = ?");
$stmt->execute([$subject_id, $study_id]);
$subject = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$subject) {
    // If subject doesn't exist, might be testing. fallback.
    $subject = ['subject_code' => 'Unknown Subject', 'id' => $subject_id];
}

// ... (Subject Fetching matches previous)

// Ensure Study Template Visits (Day 2, Day 3) are synchronized
ensureStudyTemplateVisits($pdo, $study_id);

// Fetch Study Visits & Forms for Sidebar Tree
$stmt = $pdo->prepare("SELECT v.id as visit_id, v.name as visit_name, f.id as form_id, f.name as form_name 
                       FROM study_visits v 
                       LEFT JOIN study_forms f ON v.id = f.visit_id 
                       WHERE v.study_id = ? 
                       ORDER BY v.order_index, f.order_index");
$stmt->execute([$study_id]);
$tree_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch ALL Form Statuses for this Subject
try {
    $stmt_status = $pdo->prepare("SELECT form_id, status, progress_percent, is_verified, sdr_submitted, monitor_reviewed, manager_reviewed, pi_signed_off, COALESCE(repeating_instance_id, 0) as repeating_instance_id FROM subject_form_status WHERE subject_id = ?");
    $stmt_status->execute([$subject_id]);
    $raw_statuses = $stmt_status->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Attempt on-the-fly column additions if missing on database
    try {
        $pdo->exec("ALTER TABLE subject_form_status ADD COLUMN IF NOT EXISTS sdr_submitted BOOLEAN DEFAULT FALSE");
        $pdo->exec("ALTER TABLE subject_form_status ADD COLUMN IF NOT EXISTS monitor_reviewed BOOLEAN DEFAULT FALSE");
        $pdo->exec("ALTER TABLE subject_form_status ADD COLUMN IF NOT EXISTS manager_reviewed BOOLEAN DEFAULT FALSE");
        $pdo->exec("ALTER TABLE subject_form_status ADD COLUMN IF NOT EXISTS pi_signed_off BOOLEAN DEFAULT FALSE");
        $pdo->exec("ALTER TABLE subject_form_status ADD COLUMN IF NOT EXISTS pi_signed_by INT NULL");
        $pdo->exec("ALTER TABLE subject_form_status ADD COLUMN IF NOT EXISTS pi_signed_at TIMESTAMP NULL");
        $stmt_status = $pdo->prepare("SELECT form_id, status, progress_percent, is_verified, sdr_submitted, monitor_reviewed, manager_reviewed, pi_signed_off, COALESCE(repeating_instance_id, 0) as repeating_instance_id FROM subject_form_status WHERE subject_id = ?");
        $stmt_status->execute([$subject_id]);
        $raw_statuses = $stmt_status->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e2) {
        // Fallback query without workflow columns if permissions prevent ALTER TABLE
        $stmt_status = $pdo->prepare("SELECT form_id, status, progress_percent, is_verified, COALESCE(repeating_instance_id, 0) as repeating_instance_id FROM subject_form_status WHERE subject_id = ?");
        $stmt_status->execute([$subject_id]);
        $raw_statuses = $stmt_status->fetchAll(PDO::FETCH_ASSOC);
    }
}
$statuses = [];
foreach($raw_statuses as $s) {
    // Key by form_id and instance_id (0 for main visits)
    $inst_id = $s['repeating_instance_id'] ?? 0;
    $statuses[$s['form_id'] . '_' . $inst_id] = [
        'status' => $s['status'], // 'empty', 'in_progress', 'complete', 'verified'
        'progress' => $s['progress_percent'],
        'is_verified' => $s['is_verified'],
        'sdr_submitted' => $s['sdr_submitted'] ?? 0,
        'monitor_reviewed' => $s['monitor_reviewed'] ?? 0,
        'manager_reviewed' => $s['manager_reviewed'] ?? 0,
        'pi_signed_off' => $s['pi_signed_off'] ?? 0,
        'query_count' => 0 // Default
    ];
}

// Fetch Query Counts (New, Open, Unconfirmed, Answered)
$stmt_q = $pdo->prepare("SELECT form_id, COALESCE(repeating_instance_id, 0) as instance_id, COUNT(*) as cnt FROM data_queries WHERE subject_id = ? AND status IN ('new', 'open', 'unconfirmed', 'answered') GROUP BY form_id, instance_id");
$stmt_q->execute([$subject_id]);
while($row = $stmt_q->fetch(PDO::FETCH_ASSOC)){
    $key = $row['form_id'] . '_' . $row['instance_id'];
    if(!isset($statuses[$key])) {
        $statuses[$key] = ['status' => 'empty', 'progress' => 0, 'query_count' => 0];
    }
    $statuses[$key]['query_count'] = $row['cnt'];
}

// Fetch visit_performed status across all visits for this subject
$stmt_vp = $pdo->prepare("
    SELECT sf.visit_id, sd.value 
    FROM subject_data sd
    JOIN form_fields ff ON sd.field_id = ff.id
    JOIN study_forms sf ON ff.form_id = sf.id
    WHERE sd.subject_id = ? AND ff.variable_name = 'visit_performed'
");
$stmt_vp->execute([$subject_id]);
$visit_performed_map = [];
while ($vp_row = $stmt_vp->fetch(PDO::FETCH_ASSOC)) {
    $visit_performed_map[$vp_row['visit_id']] = trim((string)$vp_row['value']);
}

// Fetch Repeating Modules
$stmt_modules = $pdo->prepare("SELECT * FROM study_repeating_modules WHERE study_id = ? ORDER BY order_index");
$stmt_modules->execute([$study_id]);
$modules = $stmt_modules->fetchAll(PDO::FETCH_ASSOC);

// Fetch Repeating Instances for this Subject
$instances = [];
if (!empty($modules)) {
    $stmt_inst = $pdo->prepare("SELECT * FROM subject_repeating_instances WHERE subject_id = ? AND status = 'active' ORDER BY created_at");
    $stmt_inst->execute([$subject_id]);
    $all_instances = $stmt_inst->fetchAll(PDO::FETCH_ASSOC);
    foreach ($all_instances as $inst) {
        $instances[$inst['repeating_module_id']][] = $inst;
    }
}


// Identify Key Visits across study
$screening_visit_id = null;
$day1_visit_id = null;
$day_treatment_visit_ids = [];
$eot_visit_id = null;
$eot_first_form_id = null;

foreach ($tree_data as $row) {
    $v_name_l = strtolower(trim($row['visit_name'] ?? ''));
    if (strpos($v_name_l, 'screening') !== false && !$screening_visit_id) {
        $screening_visit_id = $row['visit_id'];
    } elseif ((strpos($v_name_l, 'day 1') !== false || strpos($v_name_l, 'day-1') !== false || strpos($v_name_l, 'day1') !== false) && !$day1_visit_id) {
        $day1_visit_id = $row['visit_id'];
    } elseif ((strpos($v_name_l, 'end of treatment') !== false || strpos($v_name_l, 'end of study') !== false || strpos($v_name_l, 'eot') !== false || strpos($v_name_l, 'eos') !== false) && !$eot_visit_id) {
        $eot_visit_id = $row['visit_id'];
    }
    if (preg_match('/day\s*[-_]?\s*\d+/i', $v_name_l) && !in_array((int)$row['visit_id'], $day_treatment_visit_ids, true)) {
        $day_treatment_visit_ids[] = (int)$row['visit_id'];
    }
}
if (!$screening_visit_id && !empty($tree_data)) {
    $screening_visit_id = $tree_data[0]['visit_id'];
}

$is_screening_performed = true;
if ($screening_visit_id && isset($visit_performed_map[$screening_visit_id])) {
    $is_screening_performed = (strcasecmp($visit_performed_map[$screening_visit_id], 'No') !== 0);
}

// Organize into Tree + Calculate Visit Progress
$structure = [];
$total_forms_count = 0;
$total_progress_sum = 0;

foreach ($tree_data as $row) {
    $v_id = $row['visit_id'];
    $is_day_treatment_visit = in_array((int)$v_id, $day_treatment_visit_ids, true);
    if (!isset($structure[$v_id])) {
        $structure[$v_id] = [
            'name' => $row['visit_name'],
            'forms' => [],
            'visit_progress_sum' => 0,
            'visit_forms_count' => 0,
            'is_disabled' => (!$is_screening_performed && $is_day_treatment_visit)
        ];
    }
    
    if ($row['form_id']) {
        $f_id = $row['form_id'];
        $f_stat = $statuses[$f_id . '_0'] ?? ['status' => 'empty', 'progress' => 0];
        
        $is_v_performed = ($visit_performed_map[$v_id] ?? '') !== 'No';

        // 1. If this visit itself was not performed, disable other forms in it
        // 2. If Screening visit was not performed, Day 1 / Day 2 / Day 3 Visits are disabled completely!
        $is_f_disabled = (!$is_v_performed && stripos($row['form_name'], 'Visit Details') === false);
        if (!$is_screening_performed && $is_day_treatment_visit) {
            $is_f_disabled = true;
        }

        $structure[$v_id]['forms'][] = [
            'id' => $f_id,
            'name' => $row['form_name'],
            'status' => $f_stat['status'],
            'progress' => $f_stat['progress'],
            'query_count' => $f_stat['query_count'] ?? 0,
            'is_disabled' => $is_f_disabled
        ];
        
        $structure[$v_id]['visit_progress_sum'] += $f_stat['progress'];
        $structure[$v_id]['visit_forms_count']++;
        $structure[$v_id]['visit_query_sum'] = ($structure[$v_id]['visit_query_sum'] ?? 0) + ($f_stat['query_count'] ?? 0);
        
        $total_progress_sum += $f_stat['progress'];
        $total_forms_count++;
    }
}

if ($eot_visit_id && !empty($structure[$eot_visit_id]['forms'])) {
    $eot_first_form_id = $structure[$eot_visit_id]['forms'][0]['id'] ?? null;
}
$eot_jump_url = ($eot_visit_id && $eot_first_form_id) 
    ? "?subject_id={$subject_id}&visit_id={$eot_visit_id}&form_id={$eot_first_form_id}" 
    : null;

// Global Progress (Only for Main Visits for now?)
// Or include repeating data? Let's stick to main visits for global progress sidebar
$subject_global_progress = ($total_forms_count > 0) ? round($total_progress_sum / $total_forms_count) : 0;

// Get Current Context
$current_module_id = $_GET['module_id'] ?? null;
$current_instance_id = $_GET['instance_id'] ?? null;
$current_visit_id = $_GET['visit_id'] ?? null;
$current_form_id = $_GET['form_id'] ?? null;

if ($current_form_id) {
    $stmt_f_chk = $pdo->prepare("SELECT visit_id, repeating_module_id FROM study_forms WHERE id = ?");
    $stmt_f_chk->execute([$current_form_id]);
    $form_parent = $stmt_f_chk->fetch(PDO::FETCH_ASSOC);
    if ($form_parent) {
        if ($form_parent['repeating_module_id']) {
            $current_module_id = $form_parent['repeating_module_id'];
            $current_visit_id = null;
        } else {
            $current_visit_id = $form_parent['visit_id'];
            $current_module_id = null;
        }
    }
}

// Default to first visit if nothing selected
if (!$current_module_id && !$current_visit_id) {
    $current_visit_id = array_key_first($structure);
}

// If in Module Mode
$current_module = null;
$current_instance = null;
$module_forms = [];

if ($current_module_id) {
    // Find Module
    foreach ($modules as $m) {
        if ($m['id'] == $current_module_id) {
            $current_module = $m;
            break;
        }
    }
    
    // Check Instance
    if ($current_instance_id) {
        // Verify Instance belongs to module and subject
        // For security, checking matches fetched instances
        foreach ($instances[$current_module_id] ?? [] as $inst) {
            if ($inst['id'] == $current_instance_id) {
                $current_instance = $inst;
                break;
            }
        }
    }
    
    // Fetch Forms for this Module
    $stmt_mf = $pdo->prepare("SELECT * FROM study_forms WHERE repeating_module_id = ? ORDER BY order_index");
    $stmt_mf->execute([$current_module_id]);
    $module_forms = $stmt_mf->fetchAll(PDO::FETCH_ASSOC);
    
    // Set current form if not set (first form)
    if ($current_instance_id && !$current_form_id && !empty($module_forms)) {
        $current_form_id = $module_forms[0]['id'];
    }
} 
// Else Visits (already handled)
elseif ($current_visit_id && !$current_form_id && !empty($structure[$current_visit_id]['forms'])) {
     $current_form_id = $structure[$current_visit_id]['forms'][0]['id'] ?? null;
}


// Get Current Context Names
if ($current_module_id) {
    $current_visit_name = 'Repeating Data: ' . ($current_module['name'] ?? 'Unknown');
    if ($current_instance) {
         $current_visit_name .= ' > ' . ($current_instance['instance_label'] ?? $current_instance['id']);
    }
    
    $current_form_name = '';
    if ($current_form_id) {
        foreach ($module_forms as $f) {
            if ($f['id'] == $current_form_id) {
                $current_form_name = $f['name'];
                break;
            }
        }
    } else {
        $current_form_name = 'Instance List';
    }
} else {
    $current_visit_name = '';
    if ($current_visit_id && isset($structure[$current_visit_id])) {
        $current_visit_name = $structure[$current_visit_id]['name'] ?? '';
    }
    $current_form_name = '';
    if ($current_visit_id && isset($structure[$current_visit_id]['forms'])) {
        foreach ($structure[$current_visit_id]['forms'] as $frm) {
            if ($frm['id'] == $current_form_id) {
                $current_form_name = $frm['name'];
                break;
            }
        }
    }
}

// Check if current form is disabled due to visit not being performed
$is_current_visit_performed = ($current_visit_id && isset($visit_performed_map[$current_visit_id])) 
    ? ($visit_performed_map[$current_visit_id] !== 'No') 
    : true;
$is_treatment_visit_disabled_by_screening = (!$is_screening_performed && in_array((int)$current_visit_id, $day_treatment_visit_ids, true));
$is_day1_disabled_by_screening = $is_treatment_visit_disabled_by_screening;
$is_current_form_disabled = ((!$is_current_visit_performed && stripos($current_form_name, 'Visit Details') === false) || $is_treatment_visit_disabled_by_screening);

// Fetch Fields for the Current Form
$fields = [];
if ($current_form_id) {
    $stmt = $pdo->prepare("SELECT * FROM form_fields WHERE form_id = ? ORDER BY order_index ASC");
    $stmt->execute([$current_form_id]);
    $fields = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch Option Choices if any fields use Option Groups
$choices_map = [];
// Helper to safely get column even if missing (though schema fix should prevent this)
$option_group_ids = [];
foreach ($fields as $f) {
    if (!empty($f['option_group_id'])) {
        $option_group_ids[] = $f['option_group_id'];
    }
}
$option_group_ids = array_filter($option_group_ids);

if (!empty($option_group_ids)) {
    // Unique IDs only
    $option_group_ids = array_unique($option_group_ids);
    $placeholders = str_repeat('?,', count($option_group_ids) - 1) . '?';
    $stmt_opts = $pdo->prepare("SELECT * FROM option_choices WHERE group_id IN ($placeholders) ORDER BY order_index ASC");
    $stmt_opts->execute(array_values($option_group_ids));
    $all_choices = $stmt_opts->fetchAll(PDO::FETCH_ASSOC);

    foreach ($all_choices as $ch) {
        $choices_map[$ch['group_id']][] = $ch;
    }
}


// Calculate Next/Previous Links
$prev_link = null;
$next_link = null;
$flat_forms = [];

// Flatten structure (VISITS ONLY for linear flow, or include repeating?)
// Standard EDC typically keeps repeating separate. Let's keep it separate for now.
if (!$current_module_id) {
    foreach ($structure as $vid => $visit) {
        foreach ($visit['forms'] as $frm) {
            $flat_forms[] = [
                'visit_id' => $vid, 
                'form_id' => $frm['id'],
                'is_disabled' => !empty($frm['is_disabled'])
            ];
        }
    }

    foreach ($flat_forms as $i => $item) {
        if ($item['form_id'] == $current_form_id) {
            for ($p = $i - 1; $p >= 0; $p--) {
                if (!$flat_forms[$p]['is_disabled']) {
                    $prev = $flat_forms[$p];
                    $prev_link = "?subject_id=$subject_id&visit_id={$prev['visit_id']}&form_id={$prev['form_id']}";
                    break;
                }
            }
            for ($n = $i + 1; $n < count($flat_forms); $n++) {
                if (!$flat_forms[$n]['is_disabled']) {
                    $next = $flat_forms[$n];
                    $next_link = "?subject_id=$subject_id&visit_id={$next['visit_id']}&form_id={$next['form_id']}";
                    break;
                }
            }
            break;
        }
    }
} else {
    // Basic navigation within module instances?
    // If in instance, next form in instance.
    if ($current_instance_id && $current_form_id) {
        // Flatten module forms
        foreach ($module_forms as $mf) {
             $flat_forms[] = ['module_id' => $current_module_id, 'instance_id' => $current_instance_id, 'form_id' => $mf['id']];
        }
         foreach ($flat_forms as $i => $item) {
            if ($item['form_id'] == $current_form_id) {
                if (isset($flat_forms[$i - 1])) {
                    $prev = $flat_forms[$i - 1];
                    $prev_link = "?subject_id=$subject_id&module_id={$prev['module_id']}&instance_id={$prev['instance_id']}&form_id={$prev['form_id']}";
                }
                if (isset($flat_forms[$i + 1])) {
                    $next = $flat_forms[$i + 1];
                    $next_link = "?subject_id=$subject_id&module_id={$next['module_id']}&instance_id={$next['instance_id']}&form_id={$next['form_id']}";
                }
                break;
            }
        }
        // If last form in instance, go back to instance list?
        if (!$next_link) {
           // Maybe? $next_link = "?subject_id=$subject_id&module_id=$current_module_id";
        }
    }
}

// Fetch Existing Subject Data for this Form
$existing_data = [];
$form_audit_trail = [];
if ($current_form_id && $subject_id) {
    // Standardize instance ID for query
    $rep_inst_id = (int)($current_instance_id ?? 0);
    
    // Robust query handling both 0 and NULL (for older data)
    $stmt = $pdo->prepare("SELECT field_id, value FROM subject_data WHERE subject_id = ? AND form_id = ? AND (repeating_instance_id = ? OR (? = 0 AND repeating_instance_id IS NULL))");
    $stmt->execute([$subject_id, $current_form_id, $rep_inst_id, $rep_inst_id]);
    $existing_data = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    // Index existing data and fields by variable name for initial server-side condition evaluation
    $valuesByVar = [];
    $fieldsByVar = [];
    foreach ($fields as $f) {
        $fieldsByVar[$f['variable_name']] = $f;
        if (isset($existing_data[$f['id']])) {
            $valuesByVar[$f['variable_name']] = $existing_data[$f['id']];
        }
    }
    
    // Fetch unified audit trail for this form
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
}


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Entry - <?php echo htmlspecialchars($current_form_name); ?></title>
    <link rel="stylesheet" href="assets/css/style.css?v=20261022">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
    <script src="assets/js/app.js?v=20261025"></script>
    <style>
        body { margin: 0; padding: 0; overflow: hidden; }
        .entry-layout { display: flex; height: calc(100vh - 110px); background: #f8fafc; overflow: hidden; }
        .entry-sidebar { width: 300px; background: white; border-right: 1px solid var(--border-color); overflow-y: auto; flex-shrink: 0; display: flex; flex-direction: column; }
        .entry-main { flex: 1; padding: 1.25rem 1.5rem; overflow-y: auto; }
        
        /* Modern Sleek Scrollbar */
        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        ::-webkit-scrollbar-track {
            background: #f1f5f9;
        }
        ::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 99px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }
        * {
            scrollbar-width: thin;
            scrollbar-color: #cbd5e1 #f1f5f9;
        }

        /* Common Records Table Sticky Actions & Styling */
        #commonRecordsTable {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            font-size: 0.85rem;
        }
        #commonRecordsTable th,
        #commonRecordsTable td {
            padding: 0.75rem 0.85rem !important;
            border-bottom: 1px solid #e2e8f0;
        }
        #commonRecordsTable th {
            background: #ffffff;
            border-bottom: 2px solid #e2e8f0;
            color: #475569;
            font-weight: 600;
        }
        #commonRecordsTable tbody tr {
            transition: background 0.15s ease;
        }
        #commonRecordsTable tbody tr:hover td {
            background: #f8fafc;
        }
        #commonRecordsTable th:last-child {
            position: sticky;
            right: 0;
            background: #ffffff;
            z-index: 3;
            box-shadow: -3px 0 6px -2px rgba(0, 0, 0, 0.06);
        }
        #commonRecordsTable td:last-child {
            position: sticky;
            right: 0;
            background: #ffffff;
            z-index: 2;
            box-shadow: -3px 0 6px -2px rgba(0, 0, 0, 0.06);
        }
        #commonRecordsTable tbody tr:hover td:last-child {
            background: #f8fafc;
        }
        #commonRecordsTable tbody tr.is-voided td {
            background: #fef2f2;
        }
        #commonRecordsTable tbody tr.is-voided:hover td {
            background: #fee2e2;
        }
        
        /* Tree View Styles matching screenshot */
        .tree-visit { margin-bottom: 0.5rem; }
        .visit-header { 
            padding: 0.75rem 1rem; font-weight: 600; color: var(--text-dark); cursor: pointer;
            border-left: 3px solid transparent; 
        }
        .visit-header:hover { background: #f8fafc; }
        /* .visit-header.active { border-left-color: var(--accent-color); background: #f0fdf4; color: #166534; }  removed global active style for header, keep it clean */
        
        .visit-header .chevron { transition: transform 0.2s; }
        .visit-header.active .chevron { transform: rotate(180deg); }
        
        .visit-forms { display: none; padding-bottom: 0.5rem; }
        .visit-forms.active { display: block; }
        
        .tree-form { 
            padding: 0.5rem 1rem 0.5rem 2.5rem; color: var(--text-light); font-size: 0.875rem; display: flex; align-items: center; justify-content: space-between; cursor: pointer; text-decoration: none;
        }
        .tree-form:hover { color: var(--accent-color); background: #f8fafc; }
        .tree-form.active { color: var(--accent-color); font-weight: 500; background: rgba(29, 111, 151, 0.08); }
        .tree-form.form-disabled { opacity: 0.55; background: #f8fafc; }
        .tree-form.form-disabled:hover { background: #f1f5f9; }
        
        /* Data Entry Form Styles */
        .crf-card { background: white; border: 1px solid var(--border-color); border-radius: var(--radius-lg); box-shadow: var(--shadow-sm); margin-bottom: 2rem; max-width: 900px; margin: 0 auto 2rem auto; }
        .crf-header { padding: 1.5rem; border-bottom: 1px solid var(--border-color); }
        .crf-body { padding: 0; }
        
        .crf-field { 
            padding: 1.5rem; 
            border-bottom: 1px solid var(--border-color); 
            display: flex; 
            flex-direction: column; 
            gap: 0.75rem;
            transition: background 0.2s;
        }
        .crf-field:last-child { border-bottom: none; }
        .crf-field:hover { background: #fafafa; }
        
        .field-label-row { display: flex; align-items: flex-start; gap: 0.75rem; }
        .status-icon { color: #cbd5e1; font-size: 1.25rem; margin-top: 0.1rem; }
        .status-icon.completed { color: var(--primary-color); }
        
        .field-label { font-weight: 500; color: var(--text-dark); font-size: 0.95rem; flex: 1; }
        .field-actions { opacity: 0; transition: opacity 0.2s; }
        .crf-field:hover .field-actions { opacity: 1; }
        
        .input-wrapper { margin-left: 2rem; max-width: 400px; }
        .crf-input { width: 100%; padding: 0.5rem; border: 1px solid var(--border-color); border-radius: 4px; font-size: 0.9rem; transition: border-color 0.2s; }
        .crf-input:focus { border-color: var(--accent-color); outline: none; box-shadow: 0 0 0 2px rgba(29, 111, 151, 0.15); }
        
        .field-help { font-size: 0.75rem; color: #64748b; margin-top: 0.25rem; font-style: italic; }
        .field-unit { position: absolute; right: 0.75rem; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 0.85rem; pointer-events: none; }
        
        .progress-bar { height: 4px; background: #e2e8f0; border-radius: 2px; margin-top: 0.5rem; overflow: hidden; }
        .progress-fill { height: 100%; background: var(--primary-color); width: 0%; transition: width 0.3s; }
        
    </style>
</head>
<body>

<div class="app-layout" style="display: block;">
    <!-- Top Header -->
    <header class="top-nav" style="border-bottom: 1px solid var(--border-color); padding: 0 1.5rem; height: 64px; display: flex; align-items: center; justify-content: space-between; background: white; z-index: 10; position: relative;">
        <div style="display: flex; align-items: center; gap: 1rem;">
            <a href="study.php" style="color: var(--text-light);"><span class="material-icons-round">arrow_back</span></a>
            <div>
                <h2 style="font-size: 1rem; margin: 0; color: var(--text-light);">Subject ID: <?php echo htmlspecialchars($subject['subject_code'] ?? $subject_id); ?></h2>
                <div style="font-weight: 600; font-size: 1.125rem; display: flex; align-items: center; gap: 0.5rem;">
                    CRF Data Entry
                    <?php renderRoleSwitcher($study_id); ?>
                </div>
            </div>
        </div>
        
        <!-- Centered Logo -->
        <div style="position: absolute; left: 50%; top: 50%; transform: translate(-50%, -50%); height: 32px; display: flex; align-items: center;">
            <img src="edc_large_logo.png" alt="Logo" style="height: 100%; width: auto;">
        </div>
<?php
$can_edit = ($current_form_id && $is_data_editor) ? (hasPermission('enter_data') || $is_coordinator) : false;

// Check if current form is verifiable
$curr_stat_key = $current_form_id ? ($current_form_id . '_' . (int)($current_instance_id ?? 0)) : '0_0';
$curr_stat = $statuses[$curr_stat_key] ?? [
    'status' => 'empty', 
    'progress' => 0, 
    'is_verified' => 0,
    'sdr_submitted' => 0,
    'monitor_reviewed' => 0,
    'manager_reviewed' => 0,
    'pi_signed_off' => 0
];
$is_complete = ($curr_stat['status'] === 'complete' || $curr_stat['progress'] == 100);
$is_verified = ($curr_stat['status'] === 'verified' || !empty($curr_stat['is_verified'])); // Check both for safety

$sdr_submitted = (bool)($curr_stat['sdr_submitted'] ?? false);
$monitor_reviewed = (bool)($curr_stat['monitor_reviewed'] ?? false);
$manager_reviewed = (bool)($curr_stat['manager_reviewed'] ?? false);
$pi_signed_off = (bool)($curr_stat['pi_signed_off'] ?? false);

if ($sdr_submitted || $monitor_reviewed || $manager_reviewed || $pi_signed_off || $is_current_form_disabled || !$is_data_editor) {
    $can_edit = false; // Lock editing!
}

// Check if all mandatory fields are completed
$all_mandatory_completed = $current_form_id ? areAllMandatoryFieldsCompletedPHP($pdo, $subject_id, $current_form_id, (int)($current_instance_id ?? 0)) : false;
?>
        <div style="margin-left: auto; display: flex; gap: 0.5rem; align-items: center;" id="header-workflow-actions">
            <?php echo renderHeaderActions($prev_link, $next_link, $can_edit, $is_verified); ?>
        </div>
    </header>

    <!-- Subject Level Navigation Tabs -->
    <div style="background: white; border-bottom: 1px solid var(--border-color); padding: 0 1.5rem; display: flex; gap: 1.5rem; align-items: center; height: 46px; z-index: 9; position: relative;">
        <a href="?subject_id=<?php echo $subject_id; ?>" style="display: flex; align-items: center; gap: 0.4rem; font-size: 0.875rem; font-weight: 600; text-decoration: none; padding: 0.75rem 0; color: <?php echo (!$current_common_form) ? 'var(--primary-color)' : '#64748b'; ?>; border-bottom: 2px solid <?php echo (!$current_common_form) ? 'var(--primary-color)' : 'transparent'; ?>;">
            <span class="material-icons-round" style="font-size: 1.1rem;">event_note</span> Visits
        </a>
        <a href="?subject_id=<?php echo $subject_id; ?>&common_form=mh" style="display: flex; align-items: center; gap: 0.4rem; font-size: 0.875rem; font-weight: 600; text-decoration: none; padding: 0.75rem 0; color: <?php echo ($current_common_form === 'mh') ? 'var(--primary-color)' : '#64748b'; ?>; border-bottom: 2px solid <?php echo ($current_common_form === 'mh') ? 'var(--primary-color)' : 'transparent'; ?>;">
            <span class="material-icons-round" style="font-size: 1.1rem; color: #2563eb;">history_edu</span> Medical History (MH)
        </a>
        <a href="?subject_id=<?php echo $subject_id; ?>&common_form=ae" style="display: flex; align-items: center; gap: 0.4rem; font-size: 0.875rem; font-weight: 600; text-decoration: none; padding: 0.75rem 0; color: <?php echo ($current_common_form === 'ae') ? 'var(--primary-color)' : '#64748b'; ?>; border-bottom: 2px solid <?php echo ($current_common_form === 'ae') ? 'var(--primary-color)' : 'transparent'; ?>;">
            <span class="material-icons-round" style="font-size: 1.1rem; color: #d97706;">warning_amber</span> Adverse Events (AE)
        </a>
        <a href="?subject_id=<?php echo $subject_id; ?>&common_form=cm" style="display: flex; align-items: center; gap: 0.4rem; font-size: 0.875rem; font-weight: 600; text-decoration: none; padding: 0.75rem 0; color: <?php echo ($current_common_form === 'cm') ? 'var(--primary-color)' : '#64748b'; ?>; border-bottom: 2px solid <?php echo ($current_common_form === 'cm') ? 'var(--primary-color)' : 'transparent'; ?>;">
            <span class="material-icons-round" style="font-size: 1.1rem; color: #059669;">medication</span> Concomitant Medications (CM)
        </a>
        <a href="?subject_id=<?php echo $subject_id; ?>&common_form=ta" style="display: flex; align-items: center; gap: 0.4rem; font-size: 0.875rem; font-weight: 600; text-decoration: none; padding: 0.75rem 0; color: <?php echo ($current_common_form === 'ta') ? 'var(--primary-color)' : '#64748b'; ?>; border-bottom: 2px solid <?php echo ($current_common_form === 'ta') ? 'var(--primary-color)' : 'transparent'; ?>;">
            <span class="material-icons-round" style="font-size: 1.1rem; color: #8b5cf6;">radar</span> Tumor Assessment (TA)
        </a>
        <a href="?subject_id=<?php echo $subject_id; ?>&common_form=usv" style="display: flex; align-items: center; gap: 0.4rem; font-size: 0.875rem; font-weight: 600; text-decoration: none; padding: 0.75rem 0; color: <?php echo ($current_common_form === 'usv') ? 'var(--primary-color)' : '#64748b'; ?>; border-bottom: 2px solid <?php echo ($current_common_form === 'usv') ? 'var(--primary-color)' : 'transparent'; ?>;">
            <span class="material-icons-round" style="font-size: 1.1rem; color: #0284c7;">more_time</span> Unscheduled Visits (USV)
        </a>
    </div>

    <div class="entry-layout">
        <!-- Sidebar Tree -->
        <aside class="entry-sidebar">
            <div style="padding: 1.5rem; border-bottom: 1px solid var(--border-color); background: white;">
                <div style="font-weight: 600; margin-bottom: 0.5rem; display: flex; justify-content: space-between;">
                    <span>Data collection progress</span>
                    <span id="global-progress-text"><?php echo $subject_global_progress; ?>%</span>
                </div>
                <div class="progress-bar" style="height: 6px; margin-top: 0; background: #e2e8f0;">
                    <div id="global-progress-bar" class="progress-fill" style="width: <?php echo $subject_global_progress; ?>%; background: var(--primary-color);"></div>
                </div>
                <!-- Optional: Reuse Checkbox for repeating data if needed later -->
                <!-- <div style="margin-top: 0.5rem; font-size: 0.8rem; display: flex; gap: 0.5rem;">
                     <input type="checkbox"> Show repeating data instances
                </div> -->
            </div>
            
            <div style="padding: 1rem 0;">
            <div style="padding: 1rem 0;">
                
                <!-- Main Visits -->
                <?php foreach ($structure as $vid => $visit): ?>
                    <?php 
                        // Calculate Visit Progress
                        $v_prog = ($visit['visit_forms_count'] > 0) ? round($visit['visit_progress_sum'] / $visit['visit_forms_count']) : 0;
                        $is_active = ($vid == $current_visit_id); // Only active if visit selected and NOT in module mode
                    ?>
                    <div class="tree-visit">
                        <div class="visit-header <?php echo $is_active ? 'active' : ''; ?>" onclick="toggleVisit(this)" style="display: block; position: relative; padding: 0.75rem 1rem; <?php echo !empty($visit['is_disabled']) ? 'opacity: 0.6;' : ''; ?>">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                                <div style="display: flex; align-items: center; gap: 0.5rem; font-weight: 600; color: #334155;">
                                     <!-- Simple chevron logic -->
                                     <span class="material-icons-round chevron" style="font-size: 1.25rem; color: #94a3b8; transition: transform 0.2s;">expand_more</span>
                                     <?php echo htmlspecialchars($visit['name']); ?>
                                     <?php if (!empty($visit['is_disabled'])): ?>
                                         <span class="badge-disabled day1-disabled-badge" style="font-size: 0.65rem; background: #fee2e2; color: #b91c1c; padding: 2px 6px; border-radius: 4px; font-weight: 600;">Disabled</span>
                                     <?php endif; ?>
                                </div>
                                <span class="material-icons-round" style="font-size: 1.25rem; color: #94a3b8;">more_vert</span>
                            </div>
                            
                            <!-- Visit Progress Bar -->
                             <div style="display: flex; align-items: center; gap: 0.75rem;">
                                <?php if(($visit['visit_query_sum'] ?? 0) > 0): ?>
                                    <span style="background: #ef4444; color: white; font-size: 0.7rem; font-weight: 600; padding: 2px 6px; border-radius: 99px; min-width: 18px; text-align: center;">
                                        <?php echo $visit['visit_query_sum']; ?>
                                    </span>
                                <?php endif; ?>
                                <div class="progress-bar" style="flex: 1; height: 4px; background: #e2e8f0; margin: 0;">
                                    <div id="visit-progress-bar-<?php echo $vid; ?>" class="progress-fill" style="width: <?php echo $v_prog; ?>%; background: var(--primary-color);"></div>
                                </div>
                                <span id="visit-progress-text-<?php echo $vid; ?>" style="font-size: 0.75rem; font-weight: 600; color: #475569; min-width: 35px; text-align: right;"><?php echo $v_prog; ?> %</span>
                             </div>
                        </div>

                        <div class="visit-forms <?php echo $is_active ? 'active' : ''; ?>">
                            <?php foreach ($visit['forms'] as $form): ?>
                                <?php 
                                    // Status lookup using form_id + 0 (main instance)
                                    $f_key = $form['id'] . '_0';
                                    $f_stat = $statuses[$f_key] ?? ['status' => 'empty', 'progress' => 0];
                                    $q_cnt = $form['query_count'] ?? 0;
                                    $rev_status = getFormReviewStatus($f_stat);
                                    $is_f_disabled = !empty($form['is_disabled']);
                                ?>
                                <a href="?subject_id=<?php echo $subject_id; ?>&visit_id=<?php echo $vid; ?>&form_id=<?php echo $form['id']; ?>" 
                                   class="tree-form <?php echo ($form['id'] == $current_form_id && !$current_module_id) ? 'active' : ''; ?> <?php echo $is_f_disabled ? 'form-disabled' : ''; ?>"
                                   data-visit-id="<?php echo $vid; ?>"
                                   data-form-id="<?php echo $form['id']; ?>"
                                   data-form-name="<?php echo htmlspecialchars($form['name']); ?>"
                                   style="<?php echo $is_f_disabled ? 'opacity: 0.55;' : ''; ?>">
                                   
                                   <div style="display: flex; align-items: center; gap: 0.75rem; flex: 1;">
                                       <!-- Status Icon -->
                                       <span id="form-icon-<?php echo $form['id']; ?>-0" class="material-icons-round form-status-icon" style="font-size: 1.25rem; color: <?php echo $is_f_disabled ? '#94a3b8' : $rev_status['color']; ?>;">
                                            <?php echo $is_f_disabled ? 'block' : $rev_status['icon']; ?>
                                       </span>
                                       
                                       <span class="tree-form-name" style="font-weight: 500; color: <?php echo $is_f_disabled ? '#64748b' : 'var(--text-main)'; ?>;"><?php echo htmlspecialchars($form['name']); ?></span>
                                       
                                       <?php if($q_cnt > 0): ?>
                                           <span style="background: #ef4444; color: white; font-size: 0.65rem; font-weight: 600; padding: 1px 5px; border-radius: 99px; min-width: 16px; text-align: center;">
                                                <?php echo $q_cnt; ?>
                                           </span>
                                       <?php endif; ?>

                                       <?php if($is_f_disabled): ?>
                                           <span class="badge-disabled" style="font-size: 0.65rem; background: #e2e8f0; color: #64748b; padding: 1px 5px; border-radius: 4px; font-weight: 600; margin-left: auto;">Disabled</span>
                                       <?php endif; ?>
                                   </div>

                                   <?php if ($is_monitor): ?>
                               <div style="position: relative;" onclick="event.preventDefault(); toggleMenu('menu-<?php echo $form['id']; ?>', event)">
                                   <span class="material-icons-round hover-icon" style="font-size: 1rem; color: #cbd5e1; cursor: pointer;">more_vert</span>
                                   <!-- Dropdown -->
                                   <div id="menu-<?php echo $form['id']; ?>" class="dropdown-menu" style="display: none; position: absolute; right: 0; top: 100%; background: white; border: 1px solid var(--border-color); border-radius: var(--radius-md); box-shadow: var(--shadow-md); z-index: 10; min-width: 150px;">
                                       <div class="dropdown-item" onclick="openQueryModal(<?php echo $subject_id; ?>, <?php echo $vid; ?>, <?php echo $form['id']; ?>, '<?php echo addslashes($form['name']); ?>')">
                                           <span class="material-icons-round" style="font-size: 1rem; color: var(--accent-color); margin-right: 0.5rem;">help_outline</span>
                                           Raise Query
                                       </div>
                                   </div>
                               </div>
                           <?php else: ?>
                               <span class="material-icons-round" style="font-size: 1rem; color: #cbd5e1;">more_vert</span>
                           <?php endif; ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>

                <!-- Repeating Data Section -->
                 <?php if(!empty($modules)): ?>
                    <div style="padding: 1rem 1.5rem 0.5rem 1.5rem; font-size: 0.75rem; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em;">
                        Repeating Data
                    </div>
                    <?php foreach ($modules as $mod): ?>
                        <div class="tree-visit">
                            <a href="?subject_id=<?php echo $subject_id; ?>&module_id=<?php echo $mod['id']; ?>" 
                               class="visit-header <?php echo ($current_module_id == $mod['id']) ? 'active' : ''; ?>" 
                               style="display: block; position: relative; padding: 0.75rem 1rem; text-decoration: none;">
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <div style="display: flex; align-items: center; gap: 0.5rem; font-weight: 600; color: #334155;">
                                         <span class="material-icons-round" style="font-size: 1.25rem; color: #94a3b8;">repeat</span>
                                         <?php echo htmlspecialchars($mod['name']); ?>
                                    </div>
                                    <span style="font-size: 0.75rem; color: #64748b; background: #f1f5f9; padding: 2px 6px; border-radius: 999px;">
                                        <?php echo count($instances[$mod['id']] ?? []); ?>
                                    </span>
                                </div>
                            </a>
                        </div>
                    <?php endforeach; ?>
                 <?php endif; ?>

                 <!-- Common Forms Section -->
                 <div style="padding: 1rem 1.5rem 0.5rem 1.5rem; font-size: 0.75rem; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; border-top: 1px solid #f1f5f9; margin-top: 0.5rem;">
                     Common Forms
                 </div>
                 <div class="tree-visit">
                     <a href="?subject_id=<?php echo $subject_id; ?>&common_form=mh" class="visit-header <?php echo ($current_common_form === 'mh') ? 'active' : ''; ?>" style="display: block; padding: 0.65rem 1rem; text-decoration: none;">
                         <div style="display: flex; justify-content: space-between; align-items: center;">
                             <div style="display: flex; align-items: center; gap: 0.5rem; font-weight: 600; color: #334155;">
                                 <span class="material-icons-round" style="font-size: 1.25rem; color: #2563eb;">history_edu</span> Medical History
                             </div>
                             <span class="badge" id="sidebar-count-mh" style="font-size: 0.75rem; color: #64748b; background: #f1f5f9; padding: 2px 6px; border-radius: 999px;">0</span>
                         </div>
                     </a>
                     <a href="?subject_id=<?php echo $subject_id; ?>&common_form=ae" class="visit-header <?php echo ($current_common_form === 'ae') ? 'active' : ''; ?>" style="display: block; padding: 0.65rem 1rem; text-decoration: none;">
                         <div style="display: flex; justify-content: space-between; align-items: center;">
                             <div style="display: flex; align-items: center; gap: 0.5rem; font-weight: 600; color: #334155;">
                                 <span class="material-icons-round" style="font-size: 1.25rem; color: #d97706;">warning_amber</span> Adverse Events
                             </div>
                             <span class="badge" id="sidebar-count-ae" style="font-size: 0.75rem; color: #64748b; background: #f1f5f9; padding: 2px 6px; border-radius: 999px;">0</span>
                         </div>
                     </a>
                     <a href="?subject_id=<?php echo $subject_id; ?>&common_form=cm" class="visit-header <?php echo ($current_common_form === 'cm') ? 'active' : ''; ?>" style="display: block; padding: 0.65rem 1rem; text-decoration: none;">
                         <div style="display: flex; justify-content: space-between; align-items: center;">
                             <div style="display: flex; align-items: center; gap: 0.5rem; font-weight: 600; color: #334155;">
                                 <span class="material-icons-round" style="font-size: 1.25rem; color: #059669;">medication</span> Concomitant Medications
                             </div>
                             <span class="badge" id="sidebar-count-cm" style="font-size: 0.75rem; color: #64748b; background: #f1f5f9; padding: 2px 6px; border-radius: 999px;">0</span>
                         </div>
                     </a>
                     <a href="?subject_id=<?php echo $subject_id; ?>&common_form=ta" class="visit-header <?php echo ($current_common_form === 'ta') ? 'active' : ''; ?>" style="display: block; padding: 0.65rem 1rem; text-decoration: none;">
                         <div style="display: flex; justify-content: space-between; align-items: center;">
                             <div style="display: flex; align-items: center; gap: 0.5rem; font-weight: 600; color: #334155;">
                                 <span class="material-icons-round" style="font-size: 1.25rem; color: #8b5cf6;">radar</span> Tumor Assessment
                             </div>
                             <span class="badge" id="sidebar-count-ta" style="font-size: 0.75rem; color: #64748b; background: #f1f5f9; padding: 2px 6px; border-radius: 999px;">0</span>
                         </div>
                     </a>
                     <a href="?subject_id=<?php echo $subject_id; ?>&common_form=usv" class="visit-header <?php echo ($current_common_form === 'usv') ? 'active' : ''; ?>" style="display: block; padding: 0.65rem 1rem; text-decoration: none;">
                         <div style="display: flex; justify-content: space-between; align-items: center;">
                             <div style="display: flex; align-items: center; gap: 0.5rem; font-weight: 600; color: #334155;">
                                 <span class="material-icons-round" style="font-size: 1.25rem; color: #0284c7;">more_time</span> Unscheduled Visits
                             </div>
                             <span class="badge" id="sidebar-count-usv" style="font-size: 0.75rem; color: #64748b; background: #f1f5f9; padding: 2px 6px; border-radius: 999px;">0</span>
                         </div>
                     </a>
                 </div>

            </div>
        </aside>

        <!-- Main Form Area -->
        <main class="entry-main">
            <?php if (!empty($current_common_form)): ?>
                <!-- COMMON FORMS VIEW (MH / AE / CM / TA) -->
                <div class="crf-card" style="max-width: 100%; width: 100%; margin: 0 0 2rem 0;">
                    <div class="crf-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
                        <div>
                            <div style="font-size: 0.85rem; color: #64748b; margin-bottom: 0.25rem;">
                                Subject Common Form &bull; Subject <strong style="color: #0f172a;"><?php echo htmlspecialchars($subject['subject_code']); ?></strong>
                            </div>
                            <h1 style="font-size: 1.5rem; margin: 0; display: flex; align-items: center; gap: 0.5rem; color: #0f172a;">
                                <?php 
                                    if ($current_common_form === 'mh') echo '<span class="material-icons-round" style="color: #2563eb;">history_edu</span> Medical History (MH)';
                                    elseif ($current_common_form === 'ae') echo '<span class="material-icons-round" style="color: #d97706;">warning_amber</span> Adverse Events (AE)';
                                    elseif ($current_common_form === 'cm') echo '<span class="material-icons-round" style="color: #059669;">medication</span> Concomitant Medications (CM)';
                                    elseif ($current_common_form === 'ta') echo '<span class="material-icons-round" style="color: #8b5cf6;">radar</span> Tumor Assessment (TA)';
                                    elseif ($current_common_form === 'usv') echo '<span class="material-icons-round" style="color: #0284c7;">more_time</span> Unscheduled Visits (USV)';
                                ?>
                                <span id="commonFormRecordCountBadge" style="background: #e2e8f0; color: #475569; font-size: 0.8rem; padding: 2px 8px; border-radius: 99px; font-weight: 600;">0</span>
                            </h1>
                        </div>
                        
                        <?php if ($is_data_editor): ?>
                            <button class="btn btn-primary" onclick="openCommonFormModal(0, '<?php echo strtoupper($current_common_form); ?>')" style="display: flex; align-items: center; gap: 0.4rem; padding: 0.6rem 1.25rem; font-weight: 600;">
                                <span class="material-icons-round" style="font-size: 1.1rem;">add</span>
                                Add Form
                            </button>
                        <?php endif; ?>
                    </div>

                    <!-- Filter & Search Toolbar -->
                    <div style="padding: 1rem 1.5rem; background: #f8fafc; border-bottom: 1px solid #e2e8f0; display: flex; flex-wrap: wrap; gap: 1rem; align-items: center; justify-content: space-between;">
                        <div style="display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap;">
                            <div style="position: relative; width: 260px;">
                                <span class="material-icons-round" style="position: absolute; left: 0.75rem; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 1.1rem;">search</span>
                                <input type="text" id="commonSearchInput" oninput="debounceCommonSearch()" placeholder="Search record number or term..." class="form-input" style="padding-left: 2.25rem; padding-right: 0.75rem; height: 38px; font-size: 0.85rem; box-sizing: border-box;">
                            </div>

                            <select id="commonStatusFilter" onchange="loadCommonRecords()" class="form-input" style="width: 145px; height: 38px; padding: 0 0.75rem; font-size: 0.85rem; line-height: 38px; box-sizing: border-box; vertical-align: middle;">
                                <option value="">All Statuses</option>
                                <option value="draft">Draft</option>
                                <option value="complete">Complete</option>
                            </select>

                            <select id="commonSdrFilter" onchange="loadCommonRecords()" class="form-input" style="width: 175px; height: 38px; padding: 0 0.75rem; font-size: 0.85rem; line-height: 38px; box-sizing: border-box; vertical-align: middle;">
                                <option value="">All SDR Statuses</option>
                                <option value="pending">Pending SDR</option>
                                <option value="under_review">Under Review</option>
                                <option value="sdved">SDVed</option>
                                <option value="dm_reviewed">DM Reviewed</option>
                                <option value="needs_rereview">Needs Re-review</option>
                            </select>

                            <label style="display: flex; align-items: center; gap: 0.4rem; font-size: 0.85rem; color: #475569; cursor: pointer;">
                                <input type="checkbox" id="commonIncludeVoided" onchange="loadCommonRecords()"> Include Voided
                            </label>
                        </div>
                    </div>

                    <div class="crf-body" style="padding: 0; overflow-x: auto; -webkit-overflow-scrolling: touch;">
                        <table id="commonRecordsTable">
                            <thead style="background: #ffffff; border-bottom: 2px solid #e2e8f0; color: #475569;">
                                <tr id="commonTableHead">
                                    <!-- Populated dynamically based on form type -->
                                </tr>
                            </thead>
                            <tbody id="commonTableBody">
                                <tr>
                                    <td colspan="10" style="text-align: center; padding: 3rem; color: #94a3b8;">
                                        <span class="material-icons-round" style="font-size: 2.5rem; display: block; margin-bottom: 0.5rem;">hourglass_empty</span>
                                        Loading common records...
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

            <?php elseif ($current_module_id && !$current_instance_id): ?>
                <!-- Module Instances List View -->
                <div class="crf-card">
                    <div class="crf-header" style="display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <div style="font-size: 0.85rem; color: var(--text-light); margin-bottom: 0.25rem;">Repeating Data</div>
                            <h1 style="font-size: 1.5rem; margin: 0;"><?php echo htmlspecialchars($current_module['name']); ?></h1>
                        </div>
                        <?php if ($can_edit): ?>
                            <button class="btn btn-primary" onclick="openAddInstanceModal()">+ Add New Entry</button>
                        <?php endif; ?>
                    </div>
                    
                    <div class="crf-body" style="padding: 0;">
                        <?php if(empty($instances[$current_module_id] ?? [])): ?>
                             <div style="padding: 3rem; text-align: center; color: var(--text-light);">
                                <span class="material-icons-round" style="font-size: 3rem; color: #cbd5e1; margin-bottom: 1rem;">toc</span>
                                <p style="margin-bottom: 1.5rem;">No entries found for this module.</p>
                                <?php if ($can_edit): ?>
                                    <button class="btn btn-primary" onclick="openAddInstanceModal()">+ Add New Entry</button>
                                <?php endif; ?>
                             </div>
                        <?php else: ?>
                            <table style="width: 100%; border-collapse: collapse;">
                                <thead style="background: #f8fafc; border-bottom: 1px solid var(--border-color);">
                                    <tr>
                                        <th style="text-align: left; padding: 1rem; color: #64748b; font-weight: 600; font-size: 0.85rem;">Label</th>
                                        <th style="text-align: left; padding: 1rem; color: #64748b; font-weight: 600; font-size: 0.85rem;">Created</th>
                                        <th style="text-align: left; padding: 1rem; color: #64748b; font-weight: 600; font-size: 0.85rem;">Status</th>
                                        <th style="text-align: right; padding: 1rem; color: #64748b; font-weight: 600; font-size: 0.85rem;">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach(($instances[$current_module_id] ?? []) as $inst): ?>
                                        <tr style="border-bottom: 1px solid var(--border-color);">
                                            <td style="padding: 1rem; font-weight: 500;">
                                                <?php echo htmlspecialchars($inst['instance_label'] ?? $inst['id']); ?>
                                            </td>
                                            <td style="padding: 1rem; color: #64748b; font-size: 0.9rem;">
                                                <?php echo date('d-m-Y H:i:s', strtotime($inst['created_at'])); ?>
                                            </td>
                                            <td style="padding: 1rem;">
                                                <span style="background: #ecfccb; color: #365314; padding: 2px 8px; border-radius: 99px; font-size: 0.75rem; font-weight: 600;">Active</span>
                                            </td>
                                            <td style="padding: 1rem; text-align: right;">
                                                <?php if($can_edit): ?>
                                                    <button class="btn-icon" onclick="deleteInstance(<?php echo $inst['id']; ?>)" title="Delete"><span class="material-icons-round" style="color: #ef4444;">delete</span></button>
                                                <?php endif; ?>
                                                <a href="?subject_id=<?php echo $subject_id; ?>&module_id=<?php echo $current_module_id; ?>&instance_id=<?php echo $inst['id']; ?>" class="btn btn-sm btn-outline">Open</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>

            <?php else: ?>
                <!-- CRF Form View -->
                <div class="crf-card">
                    <?php 
                        // Resolve review status details
                        $f_key_curr = $current_form_id . '_' . (int)($current_instance_id ?? 0);
                        $f_stat_curr = $statuses[$f_key_curr] ?? [
                            'status' => 'empty', 
                            'progress' => 0, 
                            'sdr_submitted' => 0, 
                            'monitor_reviewed' => 0, 
                            'manager_reviewed' => 0
                        ];
                        $rev_status_curr = getFormReviewStatus($f_stat_curr);

                        // Fetch latest revocation remark if any
                        $stmt_latest_revoke = $pdo->prepare("
                            SELECT a.reason_for_change, COALESCE(u.name, u.username) as user_name, a.change_type, a.action_at
                            FROM data_audit_log a
                            LEFT JOIN users u ON a.action_by = u.id
                            WHERE a.subject_id = ? AND a.form_id = ? AND (a.repeating_instance_id = ? OR (? = 0 AND a.repeating_instance_id IS NULL)) AND (a.change_type IN ('monitor_revoked', 'manager_revoked') OR (a.change_type = 'sdr_revoked' AND a.reason_for_change LIKE 'SDR Revoked: Query %'))
                            ORDER BY a.action_at DESC, a.id DESC LIMIT 1
                        ");
                        $stmt_latest_revoke->execute([$subject_id, $current_form_id, (int)($current_instance_id ?? 0), (int)($current_instance_id ?? 0)]);
                        $latest_revoke = $stmt_latest_revoke->fetch(PDO::FETCH_ASSOC);
                    ?>
                    <!-- Review Status Banner -->
                    <div class="review-status-banner" style="display: flex; align-items: center; justify-content: space-between; padding: 1rem 1.5rem; background: #f8fafc; border-bottom: 1px solid var(--border-color); border-radius: var(--radius-lg) var(--radius-lg) 0 0;">
                        <div style="display: flex; align-items: center; gap: 0.75rem;">
                            <span style="font-size: 0.85rem; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em;">Review Status:</span>
                            <span class="status-badge" style="background: <?php echo $rev_status_curr['bg']; ?>; color: <?php echo $rev_status_curr['color']; ?>; padding: 0.25rem 0.75rem; border-radius: 99px; font-size: 0.85rem; font-weight: 600; display: inline-flex; align-items: center; gap: 0.25rem;">
                                <span class="material-icons-round" style="font-size: 1rem;"><?php echo $rev_status_curr['icon']; ?></span>
                                <?php echo $rev_status_curr['text']; ?>
                            </span>
                            
                            <?php if ($latest_revoke && !$sdr_submitted && $is_coordinator): ?>
                                <div class="click-tooltip-container" style="position: relative; display: inline-flex; align-items: center; cursor: pointer; margin-left: 0.25rem;" onclick="toggleClickTooltip(this, event)">
                                    <span class="material-icons-round" style="color: #dc2626; font-size: 1.3rem; vertical-align: middle;">warning</span>
                                    <div class="click-tooltip-text" style="visibility: hidden; opacity: 0; pointer-events: none; position: absolute; left: calc(100% + 8px); top: 50%; transform: translateY(-50%); width: 280px; background-color: #1e293b; color: #ffffff; text-align: left; border-radius: var(--radius-md); padding: 10px 14px; font-size: 0.8rem; font-weight: 400; line-height: 1.4; box-shadow: var(--shadow-lg); z-index: 100; transition: opacity 0.2s, visibility 0.2s;">
                                        <span style="font-weight: 600; color: #fca5a5; display: block; margin-bottom: 2px;">Review Revoked by <?php echo htmlspecialchars($latest_revoke['user_name']); ?>:</span>
                                        <span style="font-style: italic; color: #e2e8f0;">"<?php echo htmlspecialchars($latest_revoke['reason_for_change']); ?>"</span>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div style="display: flex; align-items: center; gap: 1rem;">
                            <div style="display: flex; flex-direction: column; align-items: flex-end;">
                                <span style="font-size: 0.75rem; color: #64748b; font-weight: 500;">Review Progress</span>
                                <span style="font-size: 0.95rem; font-weight: 700; color: #1e293b;"><?php echo $rev_status_curr['progress']; ?>%</span>
                            </div>
                            <div style="width: 100px; height: 6px; background: #e2e8f0; border-radius: 3px; overflow: hidden; margin: 0;">
                                <div style="width: <?php echo $rev_status_curr['progress']; ?>%; height: 100%; background: <?php echo $rev_status_curr['bar_color']; ?>; transition: width 0.3s ease;"></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="crf-header">
                        <?php if($current_module_id): ?>
                            <a href="?subject_id=<?php echo $subject_id; ?>&module_id=<?php echo $current_module_id; ?>" style="font-size: 0.85rem; color: var(--accent-color); text-decoration: none; display: inline-flex; align-items: center; gap: 0.25rem; margin-bottom: 0.5rem;">
                                <span class="material-icons-round" style="font-size: 1rem;">arrow_back</span> Back to List
                            </a>
                        <?php endif; ?>
                        <div style="display: flex; justify-content: space-between; align-items: flex-end; gap: 2rem;">
                            <div style="flex: 1;">
                                <div style="font-size: 0.85rem; color: var(--text-light); margin-bottom: 0.25rem;"><?php echo htmlspecialchars($current_visit_name); ?></div>
                                <h1 style="font-size: 1.5rem; margin: 0; color: var(--text-dark);"><?php echo htmlspecialchars($current_form_name ?: 'Select a Form'); ?></h1>
                            </div>
                            
                            <?php 
                                $f_stat = $statuses[$current_form_id . '_' . (int)($current_instance_id ?? 0)] ?? ['progress' => 0];
                                $curr_pct = $f_stat['progress'];
                                if ($current_form_id && !empty($fields)) {
                                    $dyn_applicable = 0;
                                    $dyn_filled = 0;
                                    foreach ($fields as $f) {
                                        if (isFieldApplicable($f, $valuesByVar ?? [], $existing_data ?? [], $fieldsByVar ?? [])) {
                                            $dyn_applicable++;
                                            $val = $existing_data[$f['id']] ?? '';
                                            if ($val !== '' && $val !== null) {
                                                $dyn_filled++;
                                            }
                                        }
                                    }
                                    $curr_pct = ($dyn_applicable > 0) ? round(($dyn_filled / $dyn_applicable) * 100) : 100;
                                    if ($curr_pct > 100) $curr_pct = 100;
                                }
                            ?>
                            <div style="display: flex; align-items: center; gap: 1rem;">
                                <!-- SDR / Review Workflow Buttons -->
                                <div id="workflow-buttons-container" style="display: inline-flex; align-items: center; gap: 1rem;">
                                    <?php if ($is_current_form_disabled): ?>
                                        <span class="badge" style="background: #f1f5f9; color: #64748b; padding: 0.35rem 0.75rem; border-radius: 6px; font-size: 0.8rem; font-weight: 500; display: inline-flex; align-items: center; gap: 0.25rem;">
                                            <span class="material-icons-round" style="font-size: 1rem;">block</span> Form Disabled (Visit Not Performed)
                                        </span>
                                    <?php else: ?>
                                        <?php echo renderWorkflowButtons($is_coordinator, $is_monitor_role, $is_manager_role, $sdr_submitted, $monitor_reviewed, $manager_reviewed, $all_mandatory_completed, $pi_signed_off, $is_pi_role); ?>
                                    <?php endif; ?>
                                </div>

                                <div style="width: 200px;">
                                    <div style="display: flex; justify-content: space-between; font-size: 0.75rem; margin-bottom: 0.25rem;">
                                        <span style="color: var(--text-light); font-weight: 500;">Form Progress</span>
                                        <span style="color: var(--accent-color); font-weight: 600;" class="form-progress-text"><?php echo $curr_pct; ?>%</span>
                                    </div>
                                    <div style="height: 6px; background: #e2e8f0; border-radius: 3px; overflow: hidden;">
                                        <div class="current-form-progress" style="height: 100%; background: var(--accent-color); width: <?php echo $curr_pct; ?>%; transition: width 0.3s ease;"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Toggle Forms if Module Instance has multiple forms? -->
                        <?php if($current_module_id && count($module_forms) > 1): ?>
                            <div style="display: flex; gap: 0.5rem; margin-top: 1rem; border-bottom: 1px solid var(--border-color);">
                                <?php foreach($module_forms as $mf): ?>
                                    <?php 
                                        $inst_id_key = (int)($current_instance_id ?? 0);
                                        $mf_stat = $statuses[$mf['id'] . '_' . $inst_id_key] ?? ['status' => 'empty', 'progress' => 0];
                                        $is_act = ($mf['id'] == $current_form_id);
                                    ?>
                                    <a href="?subject_id=<?php echo $subject_id; ?>&module_id=<?php echo $current_module_id; ?>&instance_id=<?php echo $current_instance_id; ?>&form_id=<?php echo $mf['id']; ?>" 
                                       style="padding: 0.5rem 1rem; text-decoration: none; border-bottom: 2px solid <?php echo $is_act ? 'var(--accent-color)' : 'transparent'; ?>; color: <?php echo $is_act ? 'var(--accent-color)' : 'var(--text-light)'; ?>; font-weight: <?php echo $is_act ? '600' : '500'; ?>;">
                                        <?php echo htmlspecialchars($mf['name']); ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="crf-body">
                        <!-- Common Fields Displayed on Every Form -->
                        <div class="crf-common-meta-header" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 0.85rem 1.25rem; margin-bottom: 1.5rem; display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 1rem; box-shadow: 0 1px 2px rgba(0,0,0,0.03);">
                            <div>
                                <div style="font-size: 0.7rem; color: #64748b; text-transform: uppercase; font-weight: 600; letter-spacing: 0.03em;">Study ID</div>
                                <div style="font-size: 0.95rem; font-weight: 700; color: #1e293b; margin-top: 2px;"><?php echo htmlspecialchars($_SESSION['active_study_code'] ?? 'N/A'); ?></div>
                            </div>
                            <div>
                                <div style="font-size: 0.7rem; color: #64748b; text-transform: uppercase; font-weight: 600; letter-spacing: 0.03em;">Site ID</div>
                                <div style="font-size: 0.95rem; font-weight: 700; color: #1e293b; margin-top: 2px;"><?php echo htmlspecialchars($subject['site_name'] ?? '01'); ?></div>
                            </div>
                            <div>
                                <div style="font-size: 0.7rem; color: #64748b; text-transform: uppercase; font-weight: 600; letter-spacing: 0.03em;">Subject ID</div>
                                <div style="font-size: 0.95rem; font-weight: 700; color: #0284c7; margin-top: 2px;"><?php echo htmlspecialchars($subject['subject_code']); ?></div>
                            </div>
                            <div>
                                <div style="font-size: 0.7rem; color: #64748b; text-transform: uppercase; font-weight: 600; letter-spacing: 0.03em;">Visit Name</div>
                                <div style="font-size: 0.95rem; font-weight: 600; color: #1e293b; margin-top: 2px;"><?php echo htmlspecialchars($current_visit_name ?: 'General Visit'); ?></div>
                            </div>
                            <div>
                                <div style="font-size: 0.7rem; color: #64748b; text-transform: uppercase; font-weight: 600; letter-spacing: 0.03em;">Form Name</div>
                                <div style="font-size: 0.95rem; font-weight: 600; color: #1e293b; margin-top: 2px;"><?php echo htmlspecialchars($current_form_name ?: 'General Form'); ?></div>
                            </div>
                        </div>

                        <?php if ($is_current_form_disabled): ?>
                            <div class="alert alert-warning" style="display: flex; align-items: flex-start; gap: 0.85rem; background: #fffbeb; border: 1px solid #fef3c7; border-left: 4px solid #f59e0b; padding: 1rem 1.25rem; border-radius: 8px; margin-bottom: 1.5rem; color: #92400e;">
                                <span class="material-icons-round" style="font-size: 1.75rem; color: #f59e0b; margin-top: 2px;">block</span>
                                <div style="flex: 1;">
                                    <?php if ($is_treatment_visit_disabled_by_screening): ?>
                                        <strong style="font-size: 0.95rem;"><?php echo htmlspecialchars($current_visit_name); ?> Disabled — Initial Screening Visit Not Performed</strong>
                                        <div style="font-size: 0.85rem; color: #b45309; margin-top: 3px; line-height: 1.4;">
                                            This visit (<strong><?php echo htmlspecialchars($current_visit_name); ?></strong>) is disabled because <strong>Was this Visit Performed</strong> was answered <strong>No</strong> in the initial Screening Visit. Data entry is disabled for treatment day visits. Please directly proceed to <strong>End of Treatment / End of Study</strong>.
                                        </div>
                                        <?php if ($eot_jump_url): ?>
                                        <div style="margin-top: 0.75rem;">
                                            <a href="<?php echo $eot_jump_url; ?>" class="btn btn-primary btn-sm" style="display: inline-flex; align-items: center; gap: 6px; font-weight: 600;">
                                                Directly Jump to End of Treatment / End of Study
                                                <span class="material-icons-round" style="font-size: 16px;">arrow_forward</span>
                                            </a>
                                        </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <strong style="font-size: 0.95rem;">Form Disabled — Visit Not Performed</strong>
                                        <div style="font-size: 0.85rem; color: #b45309; margin-top: 2px;">
                                            This form (<strong><?php echo htmlspecialchars($current_form_name); ?></strong>) is disabled because <strong>Was this Visit Performed</strong> was answered <strong>No</strong> in Visit Details. Data entry is disabled for this visit.
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (empty($fields)): ?>
                            <div style="padding: 3rem; text-align: center; color: var(--text-light);">
                                <?php echo $current_form_id ? 'No fields defined for this form.' : 'Please select a form.'; ?>
                            </div>
                        <?php else: ?>
                            <fieldset <?php if($is_current_form_disabled) echo 'disabled style="border: 0; padding: 0; margin: 0; opacity: 0.65; pointer-events: none;"'; else echo 'style="border: 0; padding: 0; margin: 0;"'; ?>>
                            <?php 
                                // Fetch Query Statuses for this form context
                                $q_map = [];
                                if ($current_form_id) {
                                    $inst_chk = (int)($current_instance_id ?? 0);
                                    $stmt_q = $pdo->prepare("SELECT field_id, status FROM data_queries WHERE subject_id = ? AND form_id = ? AND (repeating_instance_id = ? OR (? = 0 AND repeating_instance_id IS NULL))");
                                    $stmt_q->execute([$subject_id, $current_form_id, $inst_chk, $inst_chk]);
                                    while ($row = $stmt_q->fetch(PDO::FETCH_ASSOC)) {
                                        if (!isset($q_map[$row['field_id']])) $q_map[$row['field_id']] = [];
                                        $q_map[$row['field_id']][] = $row['status'];
                                    }
                                }
                            ?>

                            <?php foreach($fields as $index => $field): 
                                $f_val = $existing_data[$field['id']] ?? '';
                                $is_f = ($f_val !== '' && $f_val !== null);
                                
                                $f_rules = json_decode($field['validation_rules'] ?? '{}', true);
                                $f_condition = !empty($f_rules['condition']) ? json_encode($f_rules['condition']) : '';
                                
                                // Server-side initial visibility check: hide dependent fields if parent condition is not met
                                $is_initially_visible = true;
                                if (!empty($f_rules['condition'])) {
                                    $is_initially_visible = isFieldApplicable($field, $valuesByVar ?? [], $existing_data ?? [], $fieldsByVar ?? []);
                                }

                                 // Query Logic
                                 $field_queries = $q_map[$field['id']] ?? [];
                                 $query_count = count($field_queries);
                                 
                                 $active_queries = [];
                                 foreach ($field_queries as $s) {
                                     if ($s !== 'closed') {
                                         $active_queries[] = $s;
                                     }
                                 }
                                 $active_query_count = count($active_queries);
                                 
                                 $has_open_query = false;
                                 $has_answered_query = false;
                                 foreach ($active_queries as $s) {
                                     if (in_array($s, ['new', 'open', 'unconfirmed'])) {
                                         $has_open_query = true;
                                     }
                                     if ($s === 'answered') {
                                         $has_answered_query = true;
                                     }
                                 }
                            ?>
                                <div class="crf-field <?php echo !$is_initially_visible ? 'skipped-field' : ''; ?>" <?php echo !$is_initially_visible ? 'style="display: none;"' : ''; ?> data-field-id="<?php echo $field['id']; ?>" data-variable-name="<?php echo htmlspecialchars($field['variable_name']); ?>" data-field-type="<?php echo htmlspecialchars($field['type']); ?>" <?php if ($field['is_required']) echo 'data-required="true"'; ?> <?php if ($f_condition) echo 'data-condition="'.htmlspecialchars($f_condition).'"'; ?>>
                                    <div class="field-label-row">
                                        <!-- Field Status Icon -->
                                        <span class="material-icons-round status-icon" style="color: <?php echo $is_f ? 'var(--accent-color)' : '#94a3b8'; ?>">
                                            <?php echo $is_f ? 'check_circle' : 'radio_button_unchecked'; ?>
                                        </span>
                                        
                                        <!-- Field Label -->
                                        <div class="field-label" style="display: flex; align-items: center; gap: 0.25rem; flex-wrap: wrap;">
                                            <span><?php echo htmlspecialchars($field['label']); ?></span>
                                            <?php if($field['is_required']) echo '<span style="color:#ef4444">*</span>'; ?>
                                            <?php if (!empty($field['help_text'])): ?>
                                                <div class="tooltip-container">
                                                    <span class="material-icons-round tooltip-trigger" style="font-size: 1.1rem; color: #94a3b8; cursor: help; vertical-align: middle; margin-left: 2px;">info_outline</span>
                                                    <span class="tooltip-text"><?php echo htmlspecialchars($field['help_text']); ?></span>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        
                                         <!-- Direct Field Action Icons -->
                                         <div class="field-direct-actions" style="display: flex; align-items: center; gap: 0.25rem;">
                                               <?php 
                                                $can_raise_query = hasPermission('raise_query');
                                                $can_view_query = hasPermission('query') || hasPermission('all');
                                                
                                                if ($can_view_query || $can_raise_query): 
                                                    $query_color = '#94a3b8'; // default gray
                                                    $query_tooltip = 'Queries';
                                                    $click_handler = '';
                                                    $is_form_reviewed_for_role = ($is_monitor_role && $monitor_reviewed) || ($is_manager_role && $manager_reviewed) || $pi_signed_off;
                                                    $reviewed_label = $pi_signed_off ? 'PI Signed Off' : (($is_monitor_role && $monitor_reviewed) ? 'SDVed' : 'DM Reviewed');
                                                    
                                                    if ($active_query_count > 0) {
                                                        if ($pi_signed_off) {
                                                            $query_tooltip = "Cannot Raise Query&#10;as the form is Signed Off by Principal Investigator";
                                                        } elseif ($is_form_reviewed_for_role) {
                                                            $query_tooltip = "Cannot Raise Query&#10;as the form is marked as " . $reviewed_label;
                                                        } else {
                                                            $query_tooltip = "Cannot Raise Query&#10;as a query is open";
                                                        }
                                                        if ($has_open_query) {
                                                            $query_color = '#1d6f97'; // Brand color for active query
                                                            $click_handler = "onclick=\"handleFieldAction('view_queries', {$field['id']}, '" . addslashes($field['label']) . "')\"";
                                                        } elseif ($has_answered_query) {
                                                            $query_color = '#ea580c'; // Orange if query is answered
                                                            $click_handler = "onclick=\"handleFieldAction('view_queries', {$field['id']}, '" . addslashes($field['label']) . "')\"";
                                                        }
                                                    } else {
                                                        if ($is_form_reviewed_for_role) {
                                                            $query_tooltip = "Cannot Raise Query&#10;as the form is marked as " . $reviewed_label;
                                                            $query_color = '#cbd5e1';
                                                            $click_handler = 'style="cursor: not-allowed; opacity: 0.5;"';
                                                        } else {
                                                            if ($query_count > 0) {
                                                                // Closed queries exist - allow raising a new query if permitted, otherwise view history
                                                                if ($can_raise_query) {
                                                                    $query_tooltip = 'Add Query';
                                                                    $click_handler = "onclick=\"handleFieldAction('add_query', {$field['id']}, '" . addslashes($field['label']) . "')\"";
                                                                } else {
                                                                    $query_tooltip = 'View Queries (Closed)';
                                                                    $click_handler = "onclick=\"handleFieldAction('view_queries', {$field['id']}, '" . addslashes($field['label']) . "')\"";
                                                                }
                                                            } else {
                                                                // No queries ever raised
                                                                if ($can_raise_query) {
                                                                    $query_tooltip = 'Add Query';
                                                                    $click_handler = "onclick=\"handleFieldAction('add_query', {$field['id']}, '" . addslashes($field['label']) . "')\"";
                                                                } else {
                                                                    // Data coordinator / Admin can only view and cannot raise query. Since no query exists, disabled look
                                                                    $query_tooltip = 'No queries raised';
                                                                    $click_handler = 'style="cursor: default; opacity: 0.4;"';
                                                                }
                                                            }
                                                        }
                                                    }
                                                    ?>
                                                  <button type="button" class="btn-icon-sm" title="<?php echo $query_tooltip; ?>" <?php echo (strpos($click_handler, 'onclick') !== false) ? $click_handler : ''; ?> <?php echo (strpos($click_handler, 'style') !== false) ? $click_handler : ''; ?>>
                                                      <span class="material-icons-round" style="color: <?php echo $query_color; ?>; font-size: 1.15rem;">help_outline</span>
                                                      <?php if ($active_query_count > 0): ?>
                                                          <span class="badge" style="background: <?php echo $query_color; ?>;"><?php echo $active_query_count; ?></span>
                                                      <?php endif; ?>
                                                  </button>
                                              <?php endif; ?>
                                             
                                             <?php if ($can_edit): ?>
                                                 <button type="button" class="btn-icon-sm" title="Clear Data" onclick="handleFieldAction('clear_data', <?php echo $field['id']; ?>, '<?php echo addslashes($field['label']); ?>')">
                                                     <span class="material-icons-round" style="color: #f59e0b; font-size: 1.15rem;">backspace</span>
                                                 </button>
                                                 
                                                 <button type="button" class="btn-icon-sm" title="Mark Missing" onclick="handleFieldAction('mark_missing', <?php echo $field['id']; ?>, '<?php echo addslashes($field['label']); ?>')">
                                                     <span class="material-icons-round" style="color: #64748b; font-size: 1.15rem;">block</span>
                                                 </button>
                                             <?php endif; ?>
                                             
                                             <button type="button" class="btn-icon-sm" title="Comments" onclick="handleFieldAction('comments', <?php echo $field['id']; ?>, '<?php echo addslashes($field['label']); ?>')">
                                                 <span class="material-icons-round" style="color: var(--text-light); font-size: 1.15rem;">chat_bubble_outline</span>
                                             </button>
                                             
                                             <button type="button" class="btn-icon-sm" title="History" onclick="handleFieldAction('history', <?php echo $field['id']; ?>, '<?php echo addslashes($field['label']); ?>')">
                                                 <span class="material-icons-round" style="color: var(--text-light); font-size: 1.15rem;">history</span>
                                             </button>
                                         </div>
                                    </div>
                                    <div class="input-wrapper">
                                        <?php renderFieldInput($field, $existing_data[$field['id']] ?? '', $choices_map); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            </fieldset>
                            <?php if ($current_visit_id == $screening_visit_id && stripos($current_form_name, 'Visit Details') !== false): ?>
                                <div id="screening_not_performed_callout" style="display: <?php echo (!$is_screening_performed ? 'flex' : 'none'); ?>; align-items: center; justify-content: space-between; gap: 1rem; background: #f0fdf4; border: 1px solid #bbf7d0; border-left: 4px solid #16a34a; padding: 0.9rem 1.25rem; border-radius: 8px; margin-top: 1.25rem;">
                                    <div style="display: flex; align-items: center; gap: 0.75rem;">
                                        <span class="material-icons-round" style="color: #16a34a; font-size: 1.5rem;">info</span>
                                        <div>
                                            <strong style="color: #166534; font-size: 0.9rem;">Screening Visit Not Performed</strong>
                                            <div style="color: #15803d; font-size: 0.8rem; margin-top: 2px;">
                                                Day 1 visit is disabled. Saving this form will directly jump to <strong>End of Treatment / End of Study</strong>.
                                            </div>
                                        </div>
                                    </div>
                                    <?php if ($eot_jump_url): ?>
                                    <button type="button" class="btn btn-primary btn-sm" onclick="saveData(true, this)" style="background: #16a34a; border-color: #16a34a; white-space: nowrap; display: inline-flex; align-items: center; gap: 5px;">
                                        Save & Jump to End of Treatment / End of Study
                                        <span class="material-icons-round" style="font-size: 16px;">arrow_forward</span>
                                    </button>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Unified Form History & Audit Trail Card -->
                <?php if ($current_form_id): ?>
                    <div class="crf-card" style="margin-top: 2rem;">
                        <div class="crf-header" style="background: #f8fafc; padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--border-color); cursor: pointer; display: flex; align-items: center; justify-content: space-between;" onclick="toggleAuditTrailAccordion()">
                            <h3 style="font-size: 1.1rem; margin: 0; display: flex; align-items: center; gap: 0.5rem; color: #1e293b; border-bottom: none; user-select: none;">
                                <span class="material-icons-round" style="color: #64748b;">history</span>
                                Form History & Audit Trail
                            </h3>
                            <span id="audit-accordion-icon" class="material-icons-round" style="color: #64748b; transition: transform 0.2s; user-select: none;">expand_more</span>
                        </div>
                        <div id="audit-accordion-body" class="crf-body" style="padding: 0; overflow-x: auto; display: none;">
                            <div id="audit-trail-table-container">
                                <?php echo renderFormAuditTrail($pdo, $study_id, $subject_id, $current_form_id, (int)($current_instance_id ?? 0)); ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </main>
    </div>
</div>
<!-- Custom Modal for Verification -->
<style>
    .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; justify-content: center; align-items: center; }
    .modal-overlay.active { display: flex; }
    .modal-box, .modal-card { background: #ffffff !important; border-radius: 8px; padding: 2rem; max-width: 500px; width: 90%; box-shadow: 0 10px 25px rgba(0,0,0,0.2); text-align: center; }
    .modal-card { text-align: left; padding: 0; }
    #modalCommonFormEditor .modal-card { max-width: 65% !important; width: 65% !important; }
    @media (max-width: 992px) {
        #modalCommonFormEditor .modal-card { max-width: 95% !important; width: 95% !important; }
    }
    .modal-actions { margin-top: 1.5rem; display: flex; justify-content: center; gap: 1rem; }
    .modal-title { font-size: 1.25rem; font-weight: 600; margin-bottom: 0.5rem; color: #1e293b; }
    .modal-msg { color: #64748b; font-size: 0.95rem; line-height: 1.5; }
</style>

<div id="verifyModal" class="modal-overlay" onclick="closeVerifyModal()">
    <div class="modal-box" onclick="event.stopPropagation()">
        <div class="modal-title">Confirm Verification</div>
        <div class="modal-msg">
            Are you sure you want to Source Data Verify (SDV) this form?<br>
            This action confirms you have checked the data against source documents.
        </div>
        <div class="modal-actions">
            <button class="btn btn-outline" onclick="closeVerifyModal()" style="cursor: pointer;">Cancel</button>
            <button class="btn btn-primary" style="background:#059669; cursor: pointer;" onclick="confirmVerify()">Yes, Verify</button>
        </div>
    </div>
</div>

<!-- Custom Modal for Workflow Actions -->
<div id="workflowConfirmModal" class="modal-overlay" onclick="closeWorkflowModal()">
    <div class="modal-box" onclick="event.stopPropagation()">
        <div class="modal-title" id="workflowModalTitle">Confirm Action</div>
        <div class="modal-msg" id="workflowModalMsg">
            Are you sure you want to perform this action?
        </div>
        
        <!-- Revoke Remarks Container -->
        <div id="revokeRemarksContainer" style="display: none; margin-top: 1rem; width: 100%; text-align: left;">
            <label id="revokeRemarksLabel" style="display: block; font-weight: 600; font-size: 0.9rem; color: var(--text-dark); margin-bottom: 0.5rem;">Reason for Revocation <span style="color: red;">*</span></label>
            <textarea id="revokeRemarksText" class="form-input" rows="4" style="width: 100%; border: 1px solid var(--border-color); border-radius: 4px; padding: 0.5rem; resize: vertical; box-sizing: border-box;" placeholder="Enter the reason for revoking this review and mention the changes required." maxlength="500" oninput="validateRevokeRemarks()"></textarea>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 0.25rem;">
                <span id="revokeRemarksValMsg" style="color: #dc2626; font-size: 0.75rem; font-weight: 500;">Please enter a reason before revoking the review.</span>
                <span id="revokeRemarksCharCount" style="color: #64748b; font-size: 0.75rem;">0 / 500</span>
            </div>
        </div>

        <div class="modal-actions" style="margin-top: 1.5rem;">
            <button class="btn btn-outline" onclick="closeWorkflowModal()" style="cursor: pointer;">Cancel</button>
            <button class="btn btn-primary" id="workflowConfirmBtn" style="cursor: pointer;" onclick="executeWorkflowAction()">Confirm</button>
        </div>
    </div>
</div>

<!-- Custom Modal for Adding Repeating Instance -->
<div id="addInstanceModal" class="modal-overlay" onclick="closeAddInstanceModal()">
    <div class="modal-box" onclick="event.stopPropagation()" style="max-width: 400px;">
        <div class="modal-title">Add New Entry</div>
        <div class="modal-msg" style="text-align: left; margin-bottom: 1rem;">
            Please enter a label for this new entry (e.g., 'AE-01' or leave empty for automatic labeling).
        </div>
        <div style="margin-bottom: 1.5rem; text-align: left;">
            <input type="text" id="newInstanceLabel" class="crf-input" placeholder="e.g. AE-01" onkeydown="if(event.key === 'Enter') confirmCreateInstance()" style="width: 100%; border: 1px solid var(--border-color); border-radius: 4px; padding: 0.5rem; box-sizing: border-box;">
        </div>
        <div class="modal-actions">
            <button class="btn btn-outline" onclick="closeAddInstanceModal()" style="cursor: pointer;">Cancel</button>
            <button class="btn btn-primary" onclick="confirmCreateInstance()" style="cursor: pointer;">Add Entry</button>
        </div>
    </div>
</div>

<script>
    function toggleClickTooltip(el, event) {
        event.stopPropagation();
        const tooltip = el.querySelector('.click-tooltip-text');
        const isVisible = tooltip.style.visibility === 'visible';
        
        // Close all open click tooltips first
        document.querySelectorAll('.click-tooltip-text').forEach(t => {
            t.style.visibility = 'hidden';
            t.style.opacity = '0';
            t.style.pointerEvents = 'none';
        });
        
        if (!isVisible) {
            tooltip.style.visibility = 'visible';
            tooltip.style.opacity = '1';
            tooltip.style.pointerEvents = 'auto';
        }
    }

    // Global click listener to close tooltips when clicking outside
    document.addEventListener('click', function() {
        document.querySelectorAll('.click-tooltip-text').forEach(t => {
            t.style.visibility = 'hidden';
            t.style.opacity = '0';
            t.style.pointerEvents = 'none';
        });
    });

    function toggleVisit(el) {
        el.classList.toggle('active');
        const next = el.nextElementSibling;
        if(next && next.classList.contains('visit-forms')) {
            next.classList.toggle('active');
        }
    }

    // Instance Management
    function openAddInstanceModal() {
        const input = document.getElementById('newInstanceLabel');
        if (input) {
            input.value = '';
        }
        document.getElementById('addInstanceModal').classList.add('active');
        setTimeout(() => { if (input) input.focus(); }, 100);
    }

    function closeAddInstanceModal() {
        document.getElementById('addInstanceModal').classList.remove('active');
    }

    async function confirmCreateInstance() {
        const label = document.getElementById('newInstanceLabel').value;
        closeAddInstanceModal();
        
        const formData = new FormData();
        formData.append('action', 'create_instance');
        formData.append('subject_id', '<?php echo $subject_id; ?>');
        formData.append('module_id', '<?php echo $current_module_id; ?>');
        formData.append('label', label);
        
        try {
            const res = await fetch('ajax_data.php', { method: 'POST', body: formData });
            const data = await res.json();
            if(data.success) {
                location.reload();
            } else {
                alert("Error: " + (data.message || 'Unknown'));
            }
        } catch(e) { console.error(e); alert("Network Error"); }
    }

    window.monitorReviewed = <?php echo $monitor_reviewed ? 'true' : 'false'; ?>;
    window.managerReviewed = <?php echo $manager_reviewed ? 'true' : 'false'; ?>;
    window.piSignedOff = <?php echo $pi_signed_off ? 'true' : 'false'; ?>;
    const isMonitorRole = <?php echo $is_monitor_role ? 'true' : 'false'; ?>;
    const isManagerRole = <?php echo $is_manager_role ? 'true' : 'false'; ?>;
    const isPiRole = <?php echo $is_pi_role ? 'true' : 'false'; ?>;
    let pendingWorkflowAction = '';

    function closeWorkflowModal() {
        document.getElementById('workflowConfirmModal').classList.remove('active');
    }

    function toggleAuditTrailAccordion() {
        const body = document.getElementById('audit-accordion-body');
        const icon = document.getElementById('audit-accordion-icon');
        if (body.style.display === 'none') {
            body.style.display = 'block';
            icon.style.transform = 'rotate(180deg)';
        } else {
            body.style.display = 'none';
            icon.style.transform = 'rotate(0deg)';
        }
    }

    function showCenterToast(message) {
        let toast = document.getElementById('center-toast');
        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'center-toast';
            toast.style.cssText = 'position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: #1e293b; color: #ffffff; padding: 1rem 2rem; border-radius: 8px; font-size: 0.95rem; font-weight: 500; box-shadow: 0 10px 25px rgba(0, 0, 0, 0.3); z-index: 10001; display: flex; align-items: center; gap: 0.75rem; opacity: 0; transition: opacity 0.3s ease; pointer-events: none;';
            document.body.appendChild(toast);
        }
        toast.innerHTML = '<span class="material-icons-round" style="color: #ef4444; font-size: 1.25rem;">error_outline</span> <span>' + message + '</span>';
        toast.style.opacity = '1';
        setTimeout(() => { toast.style.opacity = '0'; }, 4000);
    }

    function isFieldEmpty(fieldEl) {
        const radios = fieldEl.querySelectorAll('input[type="radio"]');
        if (radios.length > 0) return !Array.from(radios).some(r => r.checked);
        const checkboxes = fieldEl.querySelectorAll('input[type="checkbox"]');
        if (checkboxes.length > 0) return !Array.from(checkboxes).some(c => c.checked);
        const select = fieldEl.querySelector('select');
        if (select) return select.value === '';
        const inputs = fieldEl.querySelectorAll('input:not([type="radio"]):not([type="checkbox"]), textarea');
        for (const input of inputs) {
            if (input.value.trim() === '') return true;
        }
        return false;
    }

    function validateRevokeRemarks() {
        const text = document.getElementById('revokeRemarksText').value;
        const valMsg = document.getElementById('revokeRemarksValMsg');
        const charCount = document.getElementById('revokeRemarksCharCount');
        const btn = document.getElementById('workflowConfirmBtn');
        const trimmed = text.trim();
        charCount.textContent = text.length + ' / 500';
        if (trimmed.length === 0) {
            valMsg.textContent = "Please enter a reason before revoking the review.";
            valMsg.style.display = 'block';
            btn.disabled = true;
            btn.style.opacity = '0.6';
            btn.style.cursor = 'not-allowed';
        } else if (trimmed.length < 10) {
            valMsg.textContent = "Remarks must be at least 10 characters.";
            valMsg.style.display = 'block';
            btn.disabled = true;
            btn.style.opacity = '0.6';
            btn.style.cursor = 'not-allowed';
        } else {
            valMsg.style.display = 'none';
            btn.disabled = false;
            btn.style.opacity = '1';
            btn.style.cursor = 'pointer';
        }
    }

    function updateReviewStatus(workflowAction) {
        pendingWorkflowAction = workflowAction;
        
        let title = '';
        let message = '';
        let btnText = '';
        let btnBg = '';
        
        const confirmBtn = document.getElementById('workflowConfirmBtn');
        
        if (workflowAction === 'mark_sdr') {
            // Validate required fields that are applicable and visible
            const requiredFields = document.querySelectorAll('.crf-field[data-required="true"]');
            let firstMissingField = null;
            for (const field of requiredFields) {
                if (field.style.display === 'none' || field.offsetParent === null) {
                    continue; // Skip fields hidden by skip-logic
                }
                if (isFieldEmpty(field)) {
                    firstMissingField = field;
                    break;
                }
            }
            if (firstMissingField) {
                showCenterToast("Please complete all required fields before marking this record as SDR.");
                firstMissingField.style.transition = 'border 0.3s ease';
                firstMissingField.style.border = '2px solid #ef4444';
                firstMissingField.style.borderRadius = '6px';
                firstMissingField.style.padding = '0.5rem';
                firstMissingField.scrollIntoView({ behavior: 'smooth', block: 'center' });
                const firstInput = firstMissingField.querySelector('input, select, textarea');
                if (firstInput) firstInput.focus();
                setTimeout(() => {
                    firstMissingField.style.border = '';
                    firstMissingField.style.padding = '';
                }, 3000);
                return;
            }
            title = 'Mark as SDR';
            message = 'Are you sure you want to mark this form as Source Data Review (SDR)? The form will show as Under Review and will be locked for editing.';
            btnText = 'Yes, Mark as SDR';
            btnBg = '#1d6f97';
        } else if (workflowAction === 'revoke_sdr') {
            title = 'Revoke SDR';
            message = 'Are you sure you want to revoke the SDR submission? This will return the form to Draft mode and make it editable again.';
            btnText = 'Yes, Revoke SDR';
            btnBg = '#dc2626';
        } else if (workflowAction === 'monitor_review') {
            title = 'Mark as SDVed';
            message = 'Are you sure you want to verify (SDV) this form against source documents? The form will be marked as SDVed.';
            btnText = 'Yes, Mark as SDVed';
            btnBg = '#059669';
        } else if (workflowAction === 'monitor_revoke') {
            title = 'Revoke SDVed';
            message = 'Are you sure you want to revoke this SDVed status? The SDR status will be reset, and the Data Coordinator will be able to update and resubmit the record.';
            btnText = 'Confirm Revoke SDVed';
            btnBg = '#dc2626';
        } else if (workflowAction === 'manager_review') {
            title = 'Mark as DM Reviewed';
            message = 'Are you sure you want to mark this form as DM Reviewed?';
            btnText = 'Yes, Mark as DM Reviewed';
            btnBg = '#6d28d9';
        } else if (workflowAction === 'manager_revoke') {
            title = 'Revoke DM Reviewed';
            message = 'Are you sure you want to revoke this DM Reviewed status? The SDR status will be reset, and the Data Coordinator will be able to update and resubmit the record.';
            btnText = 'Confirm Revoke DM Reviewed';
            btnBg = '#dc2626';
        } else if (workflowAction === 'pi_sign_off') {
            title = 'Principal Investigator Sign Off';
            message = 'Are you sure you want to perform PI Sign Off on this form? Once signed off, this form will be completely locked. No queries, field changes, or revocations will be permitted for any role unless the PI revokes the sign off.';
            btnText = 'Yes, PI Sign Off';
            btnBg = '#4338ca';
        } else if (workflowAction === 'pi_revoke') {
            title = 'Revoke PI Sign Off';
            message = 'Are you sure you want to revoke the Principal Investigator Sign Off? The form will return to the previous stage of SDVed & DM Reviewed.';
            btnText = 'Confirm Revoke PI Sign Off';
            btnBg = '#dc2626';
        }

        document.getElementById('workflowModalTitle').textContent = title;
        document.getElementById('workflowModalMsg').textContent = message;
        
        confirmBtn.textContent = btnText;
        confirmBtn.style.backgroundColor = btnBg;
        confirmBtn.style.borderColor = btnBg;
        
        const isRevoke = (workflowAction === 'monitor_revoke' || workflowAction === 'manager_revoke' || workflowAction === 'pi_revoke');
        const remarksContainer = document.getElementById('revokeRemarksContainer');
        const remarksText = document.getElementById('revokeRemarksText');
        const remarksLabel = document.getElementById('revokeRemarksLabel');
        
        if (remarksContainer && remarksText) {
            if (isRevoke) {
                remarksContainer.style.display = 'block';
                remarksText.value = '';
                if (remarksLabel) {
                    if (workflowAction === 'monitor_revoke') {
                        remarksLabel.innerHTML = 'Reason for Revoking SDVed <span style="color: red;">*</span>';
                    } else if (workflowAction === 'manager_revoke') {
                        remarksLabel.innerHTML = 'Reason for Revoking DM Reviewed <span style="color: red;">*</span>';
                    } else if (workflowAction === 'pi_revoke') {
                        remarksLabel.innerHTML = 'Reason for Revoking PI Sign Off <span style="color: red;">*</span>';
                    }
                }
                validateRevokeRemarks(); // disables by default
            } else {
                remarksContainer.style.display = 'none';
                confirmBtn.disabled = false;
                confirmBtn.style.opacity = '1';
                confirmBtn.style.cursor = 'pointer';
            }
        }
        
        document.getElementById('workflowConfirmModal').classList.add('active');
    }

    function updateFormStatusUI(data) {
        // 1. Review Status Banner
        const statusBadge = document.querySelector('.review-status-banner .status-badge');
        if (statusBadge) {
            statusBadge.style.backgroundColor = data.review_bg;
            statusBadge.style.color = data.review_color;
            statusBadge.innerHTML = `<span class="material-icons-round" style="font-size: 1rem;">${data.review_icon}</span> ${data.review_text}`;
        }
        
        const reviewTextEl = document.querySelector('.review-status-banner div[style*="align-items: flex-end"] span[style*="font-weight: 700"]');
        if (reviewTextEl) {
            reviewTextEl.textContent = data.review_progress + '%';
        }
        
        const reviewBarFill = document.querySelector('.review-status-banner div[style*="width: 100px"] div');
        if (reviewBarFill) {
            reviewBarFill.style.width = data.review_progress + '%';
            reviewBarFill.style.backgroundColor = data.review_bar_color;
        }

        // 2. Action buttons
        const btnContainer = document.getElementById('workflow-buttons-container');
        if (btnContainer && data.buttons_html !== undefined) {
            btnContainer.innerHTML = data.buttons_html;
        }

        // 3. Header Actions
        const headerActions = document.getElementById('header-workflow-actions');
        if (headerActions && data.header_html !== undefined) {
            headerActions.innerHTML = data.header_html;
        }

        // 4. Input field editability (Disable/enable inputs and textareas)
        const inputs = document.querySelectorAll('.crf-card input, .crf-card select, .crf-card textarea');
        inputs.forEach(input => {
            if (data.can_edit) {
                input.disabled = false;
                input.removeAttribute('readonly');
                input.style.background = '';
                input.style.cursor = '';
            } else {
                input.disabled = true;
                input.style.background = '#f1f5f9';
                input.style.cursor = 'not-allowed';
            }
        });
        
        // Hide direct field action buttons if read-only
        const fieldDirectActions = document.querySelectorAll('.field-direct-actions');
        fieldDirectActions.forEach(container => {
            const clearBtn = container.querySelector('[title="Clear Data"]');
            const missingBtn = container.querySelector('[title="Mark Missing"]');
            if (clearBtn) clearBtn.style.display = data.can_edit ? '' : 'none';
            if (missingBtn) missingBtn.style.display = data.can_edit ? '' : 'none';
        });

        // 5. Form History & Audit Trail
        const auditContainer = document.getElementById('audit-trail-table-container');
        if (auditContainer && data.audit_trail_html !== undefined) {
            auditContainer.innerHTML = data.audit_trail_html;
        }

        // 6. Global progress and Sidebar Progress
        if (data.subject_progress !== undefined) {
            const gpBar = document.getElementById('global-progress-bar');
            const gpText = document.getElementById('global-progress-text');
            if (gpBar) gpBar.style.width = data.subject_progress + '%';
            if (gpText) gpText.innerText = data.subject_progress + '%';
        }
        
        if (data.visit_progress !== undefined) {
            const vpBar = document.getElementById('visit-progress-bar-<?php echo $current_visit_id; ?>');
            const vpText = document.getElementById('visit-progress-text-<?php echo $current_visit_id; ?>');
            if (vpBar) vpBar.style.width = data.visit_progress + '%';
            if (vpText) vpText.innerText = data.visit_progress + '%';
        }
        
        // 7. Sidebar form icon updates
        const iconId = 'form-icon-<?php echo $current_form_id; ?>-<?php echo $current_instance_id ?: 0; ?>';
        const iconEl = document.getElementById(iconId);
        if (iconEl) {
            iconEl.style.color = data.review_color;
            iconEl.innerText = data.review_icon;
        }

        // 8. Update query buttons dynamically
        if (data.monitor_reviewed !== undefined) window.monitorReviewed = data.monitor_reviewed;
        if (data.manager_reviewed !== undefined) window.managerReviewed = data.manager_reviewed;
        if (data.pi_signed_off !== undefined) window.piSignedOff = data.pi_signed_off;

        if (window.piSignedOff) {
            const queryButtons = document.querySelectorAll('.field-direct-actions button[title*="Query"], .field-direct-actions button[title*="query"], .field-direct-actions button[title*="add"], .field-direct-actions button[title*="Add"]');
            queryButtons.forEach(btn => {
                btn.title = "Cannot Raise Query\nas the form is Signed Off by Principal Investigator";
                const onclickStr = btn.getAttribute('onclick') || '';
                if (onclickStr.includes('add_query')) {
                    btn.removeAttribute('onclick');
                    btn.style.cursor = 'not-allowed';
                    btn.style.opacity = '0.5';
                    const iconEl = btn.querySelector('.material-icons-round');
                    if (iconEl) iconEl.style.color = '#cbd5e1';
                }
            });
        } else {
            const isFormReviewedForRole = (window.monitorReviewed && isMonitorRole) || (window.managerReviewed && isManagerRole);
            if (isFormReviewedForRole) {
                const queryButtons = document.querySelectorAll('.field-direct-actions button[title*="Query"], .field-direct-actions button[title*="query"], .field-direct-actions button[title*="add"], .field-direct-actions button[title*="Add"]');
                queryButtons.forEach(btn => {
                    btn.title = "Cannot Raise Query\nas the form is marked as review";
                    const onclickStr = btn.getAttribute('onclick') || '';
                    if (onclickStr.includes('add_query')) {
                        btn.removeAttribute('onclick');
                        btn.style.cursor = 'not-allowed';
                        btn.style.opacity = '0.5';
                        const iconEl = btn.querySelector('.material-icons-round');
                        if (iconEl) iconEl.style.color = '#cbd5e1';
                    }
                });
            } else {
                // If the review is revoked, reload to restore all original click handlers and tooltips easily
                if (pendingWorkflowAction && pendingWorkflowAction.includes('revoke')) {
                    location.reload();
                }
            }
        }
        
        if (pendingWorkflowAction === 'mark_sdr') {
            const alertBtn = document.querySelector('.click-tooltip-container');
            if (alertBtn) {
                alertBtn.style.display = 'none';
            }
        }
    }

    async function executeWorkflowAction() {
        closeWorkflowModal();
        if (!pendingWorkflowAction) return;

        const formData = new FormData();
        formData.append('action', 'update_review_status');
        formData.append('workflow_action', pendingWorkflowAction);
        formData.append('subject_id', '<?php echo $subject_id; ?>');
        formData.append('form_id', '<?php echo $current_form_id; ?>');
        formData.append('visit_id', '<?php echo $current_visit_id ?? 0; ?>');
        formData.append('repeating_instance_id', '<?php echo $current_instance_id ?? 0; ?>');
        formData.append('prev_link', '<?php echo $prev_link; ?>');
        formData.append('next_link', '<?php echo $next_link; ?>');
        
        if (pendingWorkflowAction === 'monitor_revoke' || pendingWorkflowAction === 'manager_revoke') {
            const remarks = document.getElementById('revokeRemarksText').value;
            formData.append('remarks', remarks);
        }
        
        try {
            const res = await fetch('ajax_data.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                updateFormStatusUI(data);
            } else {
                alert("Error: " + (data.message || data.error || 'Unknown'));
            }
        } catch(e) {
            console.error(e);
            alert("Network Error");
        }
    }

    function verifyForm() {
        document.getElementById('verifyModal').classList.add('active');
    }

    function closeVerifyModal() {
        document.getElementById('verifyModal').classList.remove('active');
    }

    async function confirmVerify() {
        closeVerifyModal();
        const formData = new FormData();
        formData.append('action', 'verify_form');
        formData.append('subject_id', '<?php echo $subject_id; ?>');
        formData.append('form_id', '<?php echo $current_form_id; ?>');
        formData.append('visit_id', '<?php echo $current_visit_id ?? 0; ?>');
        formData.append('repeating_instance_id', '<?php echo $current_instance_id ?? 0; ?>');
        formData.append('prev_link', '<?php echo $prev_link; ?>');
        formData.append('next_link', '<?php echo $next_link; ?>');
        
        try {
            const res = await fetch('ajax_data.php', { method: 'POST', body: formData });
            const data = await res.json();
            if(data.success) {
                alert("Form verified successfully!"); 
                updateFormStatusUI(data);
            } else {
                alert("Error: " + (data.message || data.error || 'Unknown'));
            }
        } catch(e) { console.error(e); alert("Network Error"); }
    }

    async function deleteInstance(id) {
        if(!confirm("Are you sure you want to delete this entry? All data will be lost.")) return;
         
        const formData = new FormData();
        formData.append('action', 'delete_instance');
        formData.append('instance_id', id);
         
        try {
            const res = await fetch('ajax_data.php', { method: 'POST', body: formData });
            const data = await res.json();
             if(data.success) {
                location.reload();
            } else {
                alert("Error: " + (data.message || 'Unknown'));
            }
        } catch(e) { console.error(e); alert("Network Error"); }
    }



    let hasUnsavedChanges = false;
    let isSaving = false;
    let saveTimeout = null;

    const SCREENING_VISIT_ID = '<?php echo (int)($screening_visit_id ?? 0); ?>';
    const DAY1_VISIT_ID = '<?php echo (int)($day1_visit_id ?? 0); ?>';
    const DAY_TREATMENT_VISIT_IDS = <?php echo json_encode(array_values($day_treatment_visit_ids)); ?>;
    const EOT_VISIT_ID = '<?php echo (int)($eot_visit_id ?? 0); ?>';
    const EOT_JUMP_URL = '<?php echo $eot_jump_url ? addslashes($eot_jump_url) : ""; ?>';
    let CURRENT_NEXT_LINK = '<?php echo $next_link ? addslashes($next_link) : ""; ?>';

    function saveData(goNext = false, callerBtn = null) {
        clearTimeout(saveTimeout);
        saveTimeout = null;
        
        // If called from button, give feedback (MUST be an actual button, never input or select!)
        let btn = null;
        if (callerBtn && (callerBtn.tagName === 'BUTTON' || (callerBtn.tagName === 'A' && callerBtn.classList.contains('btn')))) {
            btn = callerBtn;
        } else {
            try {
                if (typeof window !== 'undefined' && window.event && window.event.target) {
                    const candidate = window.event.target.closest('button, a.btn');
                    if (candidate && (candidate.tagName === 'BUTTON' || candidate.tagName === 'A')) {
                        btn = candidate;
                    }
                }
            } catch(e) {}
        }

        let originalText = '';
        if (btn) {
            originalText = btn.innerText;
            btn.innerText = 'Saving...';
            document.querySelectorAll('.btn-primary').forEach(b => b.disabled = true);
        }

        const data = {};
        const fieldEls = document.querySelectorAll('.crf-field');
        
        fieldEls.forEach(fieldEl => {
            const fieldId = fieldEl.getAttribute('data-field-id');
            if (!fieldId) return;

            // If field is skipped or hidden, send empty string to clear any existing DB value
            if (fieldEl.classList.contains('skipped-field') || fieldEl.style.display === 'none') {
                data[fieldId] = '';
                return;
            }

            const inputs = fieldEl.querySelectorAll('input, select, textarea');
            inputs.forEach(input => {
                const name = input.name;
                if (!name) return;
                
                if (input.type === 'radio') {
                    if (input.checked) {
                        data[fieldId] = input.value;
                    } else if (data[fieldId] === undefined) {
                        data[fieldId] = '';
                    }
                } else if (input.type === 'checkbox') {
                    if (!data[fieldId]) data[fieldId] = [];
                    if (input.checked) {
                        if (Array.isArray(data[fieldId])) data[fieldId].push(input.value);
                        else data[fieldId] = [input.value];
                    }
                } else {
                    data[fieldId] = input.value;
                }
            });

            // Ensure field has at least empty string if no radio was checked
            if (data[fieldId] === undefined) {
                data[fieldId] = '';
            }
        });

        // Also check any standalone input outside crf-field
        document.querySelectorAll('[name^="field_"]').forEach(input => {
            const match = input.name.match(/field_(\d+)/);
            if (!match) return;
            const id = match[1];
            if (data[id] === undefined) {
                if (input.type === 'radio') {
                    if (input.checked) data[id] = input.value;
                } else if (input.type === 'checkbox') {
                    if (input.checked) data[id] = input.value;
                } else {
                    data[id] = input.value;
                }
            }
        });

        // Convert array data to string
        for (const [key, val] of Object.entries(data)) {
            if (Array.isArray(val)) data[key] = val.join(',');
        }
        
        const formData = new FormData();
        formData.append('action', 'save_data');
        formData.append('subject_id', '<?php echo $subject_id; ?>');
        formData.append('visit_id', '<?php echo $current_visit_id ?: 0; ?>');
        formData.append('form_id', '<?php echo $current_form_id; ?>');
        formData.append('repeating_instance_id', '<?php echo (int)($current_instance_id ?? 0); ?>');
        
        // Append fields as data[id]=val
        for (const [id, val] of Object.entries(data)) {
            formData.append(`data[${id}]`, val);
        }
        
        isSaving = true;
        return fetch('ajax_data.php', { method: 'POST', body: formData, keepalive: true })
        .then(response => response.json())
        .then(res => {
            isSaving = false;
            if (res.success) {
                hasUnsavedChanges = false;
                if (callerBtn && res.redirect_url) {
                    window.location.href = res.redirect_url;
                    return res;
                }
                if (goNext) {
                    const dest = res.redirect_url || CURRENT_NEXT_LINK || '<?php echo $next_link; ?>';
                    if (dest) {
                        window.location.href = dest;
                        return res;
                    }
                } else {
                    // Update UI Elements if triggered by button
                    if (btn) {
                        btn.innerText = originalText;
                        document.querySelectorAll('.btn-primary').forEach(b => b.disabled = false);
                    }
                    
                    // Update current form progress inside CRF card dynamically
                    if (res.form_progress !== undefined) {
                        const fText = document.querySelector('.form-progress-text');
                        const fBar = document.querySelector('.current-form-progress');
                        if (fText) fText.innerText = res.form_progress + '%';
                        if (fBar) fBar.style.width = res.form_progress + '%';
                    }

                    // Global Progress
                    if (res.subject_progress !== undefined) {
                        const gpBar = document.getElementById('global-progress-bar');
                        const gpText = document.getElementById('global-progress-text');
                        if (gpBar) gpBar.style.width = res.subject_progress + '%';
                        if (gpText) gpText.innerText = res.subject_progress + '%';
                    }
                    
                    // Visit Progress (for current visit)
                    if (res.visit_progress !== undefined) {
                        const vpBar = document.getElementById('visit-progress-bar-<?php echo $current_visit_id; ?>');
                        const vpText = document.getElementById('visit-progress-text-<?php echo $current_visit_id; ?>');
                        if (vpBar) vpBar.style.width = res.visit_progress + '%';
                        if (vpText) vpText.innerText = res.visit_progress + '%';
                    }
                    
                    // Form Status Icon
                    const iconId = 'form-icon-<?php echo $current_form_id; ?>-<?php echo $current_instance_id ?: 0; ?>';
                    const iconEl = document.getElementById(iconId);
                    if (iconEl) {
                        let color = '#cbd5e1';
                        let icon = 'radio_button_unchecked';
                        
                        if (res.form_status === 'complete' || res.form_progress === 100) {
                            color = '#0d8e6f';
                            icon = 'check_circle';
                        } else if (res.form_status === 'in_progress' || res.form_progress > 0) {
                            color = '#1d6f97';
                            icon = 'hourglass_top';
                        }
                        
                        iconEl.style.color = color;
                        iconEl.innerText = icon;
                    }
                    return res;
                }
            } else {
                console.error("Save error:", res);
                if (btn) {
                    alert("Error saving: " + (res.error || 'Unknown'));
                    btn.innerText = originalText;
                    document.querySelectorAll('.btn-primary').forEach(b => b.disabled = false);
                }
                throw new Error(res.error || 'Save failed');
            }
        })
        .catch(err => {
            isSaving = false;
            console.error(err);
            if (btn) {
                alert("Network Error");
                btn.innerText = originalText;
                document.querySelectorAll('.btn-primary').forEach(b => b.disabled = false);
            }
            throw err;
        });
    }

    // Real-time Progress Calculation (Visual)
    function getFieldId(name) {
        const match = name.match(/field_(\d+)/);
        return match ? match[1] : null;
    }

    // Real-time Dynamic Progress Calculation (Visible/applicable fields only)
    function checkProgress() {
        const fieldEls = document.querySelectorAll('.crf-field');
        const applicableFieldIds = new Set();
        const filledFieldIds = new Set();
        
        fieldEls.forEach(fieldEl => {
            // Ignore conditionally hidden or skipped fields
            if (fieldEl.classList.contains('skipped-field') || fieldEl.style.display === 'none') {
                return;
            }

            const fieldId = fieldEl.getAttribute('data-field-id');
            if (!fieldId) return;

            applicableFieldIds.add(fieldId);
            
            const inputs = fieldEl.querySelectorAll('input, select, textarea');
            let isFilled = false;
            inputs.forEach(input => {
                if (input.type === 'radio' || input.type === 'checkbox') {
                    if (input.checked) isFilled = true;
                } else {
                    if (input.value && input.value.trim() !== '') isFilled = true;
                }
            });
            
            if (isFilled) filledFieldIds.add(fieldId);
        });
        
        const total = applicableFieldIds.size;
        const filled = filledFieldIds.size;
        const percent = total > 0 ? Math.round((filled / total) * 100) : 100;
        
        // Update Local Progress Bar & Text
        const progressBar = document.querySelector('.current-form-progress');
        const progressText = document.querySelector('.form-progress-text');
        if (progressBar) {
            progressBar.style.width = percent + '%';
        }
        if (progressText) {
            progressText.innerText = percent + '%';
        }

        // Update Field Icons for applicable fields
        fieldEls.forEach(fieldEl => {
            const fieldId = fieldEl.getAttribute('data-field-id');
            const icon = fieldEl.querySelector('.status-icon');
            if (icon) {
                if (filledFieldIds.has(fieldId)) {
                    icon.innerText = 'check_circle';
                    icon.style.color = 'var(--accent-color)';
                } else {
                    icon.innerText = 'radio_button_unchecked';
                    icon.style.color = '#94a3b8';
                }
            }
        });

        return { total, filled, percent };
    }
    
    function debouncedSave(immediate = false) {
        checkProgress();
        clearTimeout(saveTimeout);
        if (immediate) {
            saveTimeout = null;
            saveData(false);
            return;
        }
        saveTimeout = setTimeout(() => {
            saveTimeout = null;
            saveData(false);
        }, 500);
    }
    
    // Skip Logic & Conditional Questions Engine
    function getFieldValueByVar(varName) {
        // 1. Radio buttons
        const radios = document.querySelectorAll(`input[type="radio"][data-var="${varName}"]`);
        if (radios.length > 0) {
            for (const r of radios) {
                if (r.checked) return r.value;
            }
            return '';
        }
        // 2. Checkboxes
        const checkboxes = document.querySelectorAll(`input[type="checkbox"][data-var="${varName}"]`);
        if (checkboxes.length > 0) {
            const vals = [];
            for (const cb of checkboxes) {
                if (cb.checked) vals.push(cb.value);
            }
            return vals;
        }
        // 3. Select / Text / Number / Date / Time
        const el = document.querySelector(`[data-var="${varName}"]`);
        if (el) return el.value;
        return '';
    }

    function evaluateSkipLogic() {
        const fields = document.querySelectorAll('.crf-field');
        
        // Run 2 passes to ensure deep multi-level nested conditional logic (e.g. Yes/No -> Reason dropdown -> Other text) cascades cleanly
        for (let pass = 0; pass < 2; pass++) {
            fields.forEach(fieldEl => {
                const condAttr = fieldEl.getAttribute('data-condition');
                if (!condAttr) return;

                try {
                    const cond = JSON.parse(condAttr);
                    if (!cond || !cond.field) return;

                    const parentVal = getFieldValueByVar(cond.field);
                    let isVisible = false;
                    const op = cond.operator || '==';
                    const targetVal = cond.value;

                    if (op === '==' || op === 'equals') {
                        isVisible = (String(parentVal).trim() === String(targetVal).trim());
                    } else if (op === '!=' || op === 'not_equals') {
                        isVisible = (String(parentVal).trim() !== String(targetVal).trim() && parentVal !== '');
                    } else if (op === 'in') {
                        const arr = Array.isArray(targetVal) ? targetVal : [targetVal];
                        isVisible = arr.map(v => String(v).trim()).includes(String(parentVal).trim());
                    }

                    // If parent field itself is currently skipped/hidden, cascade hide child
                    const parentFieldEl = document.querySelector(`.crf-field[data-variable-name="${cond.field}"]`);
                    if (parentFieldEl && (parentFieldEl.classList.contains('skipped-field') || parentFieldEl.style.display === 'none')) {
                        isVisible = false;
                    }

                    const inputs = fieldEl.querySelectorAll('input, select, textarea');
                    if (isVisible) {
                        fieldEl.style.display = 'block';
                        fieldEl.classList.remove('skipped-field');
                        if (fieldEl.getAttribute('data-required') === 'true') {
                            inputs.forEach(inp => {
                                if (inp.type !== 'radio' && inp.type !== 'checkbox') {
                                    inp.required = true;
                                }
                            });
                        }
                    } else {
                        fieldEl.style.display = 'none';
                        fieldEl.classList.add('skipped-field');
                        inputs.forEach(inp => {
                            inp.required = false;
                            if (inp.type === 'radio' || inp.type === 'checkbox') {
                                inp.checked = false;
                            } else {
                                inp.value = '';
                            }
                        });
                    }
                } catch(e) {
                    console.error('Error evaluating condition:', e);
                }
            });
        }

        handleCalculationsAndFlags();
        handleVisitDisabledFormsRealtime();
        checkProgress();
    }

    function handleVisitDisabledFormsRealtime() {
        const vpVal = getFieldValueByVar('visit_performed');
        if (!vpVal) return;

        const visitId = '<?php echo (int)($current_visit_id ?? 0); ?>';
        if (!visitId || visitId === '0') return;

        const isScreening = (visitId === SCREENING_VISIT_ID);

        // 1. Current visit other forms
        const formLinks = document.querySelectorAll(`.tree-form[data-visit-id="${visitId}"]`);
        formLinks.forEach(link => {
            const formName = link.getAttribute('data-form-name') || '';
            if (formName.toLowerCase().includes('visit details')) return;
            setFormDisabledVisual(link, vpVal === 'No');
        });

        // 2. If Screening visit, handle Day 1, Day 2, Day 3 forms and visit headers
        if (isScreening && typeof DAY_TREATMENT_VISIT_IDS !== 'undefined' && Array.isArray(DAY_TREATMENT_VISIT_IDS)) {
            DAY_TREATMENT_VISIT_IDS.forEach(tVid => {
                const dayForms = document.querySelectorAll(`.tree-form[data-visit-id="${tVid}"]`);
                dayForms.forEach(link => {
                    setFormDisabledVisual(link, vpVal === 'No');
                });
            });

            // Treatment Day Visit Headers
            const dayHeaders = document.querySelectorAll(`.tree-visit .visit-header`);
            dayHeaders.forEach(vh => {
                const titleText = (vh.innerText || '').toLowerCase();
                if (/day\s*[-_]?\s*\d+/i.test(titleText)) {
                    let dayBadge = vh.querySelector('.day1-disabled-badge');
                    if (vpVal === 'No') {
                        vh.style.opacity = '0.6';
                        if (!dayBadge) {
                            dayBadge = document.createElement('span');
                            dayBadge.className = 'badge-disabled day1-disabled-badge';
                            dayBadge.style.cssText = 'font-size: 0.65rem; background: #fee2e2; color: #b91c1c; padding: 2px 6px; border-radius: 4px; font-weight: 600; margin-left: 0.5rem;';
                            dayBadge.innerText = 'Disabled';
                            const titleDiv = vh.querySelector('div > div');
                            if (titleDiv) titleDiv.appendChild(dayBadge);
                        }
                    } else {
                        vh.style.opacity = '1';
                        if (dayBadge) dayBadge.remove();
                    }
                }
            });

            // Toggle screening callout banner
            const callout = document.getElementById('screening_not_performed_callout');
            if (callout) {
                callout.style.display = (vpVal === 'No') ? 'flex' : 'none';
            }

            // Update Next link in real-time
            if (vpVal === 'No' && EOT_JUMP_URL) {
                CURRENT_NEXT_LINK = EOT_JUMP_URL;
                document.querySelectorAll('a.btn-primary:not([onclick])').forEach(a => {
                    if (a.innerText.trim() === 'Next') a.href = EOT_JUMP_URL;
                });
            } else if (vpVal === 'Yes') {
                CURRENT_NEXT_LINK = '<?php echo $next_link ? addslashes($next_link) : ""; ?>';
                document.querySelectorAll('a.btn-primary:not([onclick])').forEach(a => {
                    if (a.innerText.trim() === 'Next') a.href = CURRENT_NEXT_LINK;
                });
            }
        }
    }

    function setFormDisabledVisual(link, disabled) {
        const iconEl = link.querySelector('.form-status-icon');
        const nameEl = link.querySelector('.tree-form-name');
        let badge = link.querySelector('.badge-disabled');

        if (disabled) {
            if (iconEl && !link.getAttribute('data-orig-icon')) {
                link.setAttribute('data-orig-icon', iconEl.innerText);
                link.setAttribute('data-orig-color', iconEl.style.color || '#94a3b8');
            }
            link.classList.add('form-disabled');
            link.style.opacity = '0.55';
            link.style.pointerEvents = 'none';
            link.style.cursor = 'not-allowed';
            if (nameEl) nameEl.style.color = '#64748b';
            if (iconEl) {
                iconEl.innerText = 'block';
                iconEl.style.color = '#94a3b8';
            }
            if (!badge) {
                badge = document.createElement('span');
                badge.className = 'badge-disabled';
                badge.style.cssText = 'font-size: 0.65rem; background: #e2e8f0; color: #64748b; padding: 1px 5px; border-radius: 4px; font-weight: 600; margin-left: auto;';
                badge.innerText = 'Disabled';
                const flexDiv = link.querySelector('div');
                if (flexDiv) flexDiv.appendChild(badge);
            }
        } else {
            link.classList.remove('form-disabled');
            link.style.opacity = '1';
            link.style.pointerEvents = '';
            link.style.cursor = '';
            if (nameEl) nameEl.style.color = 'var(--text-main)';
            if (badge) badge.remove();
            if (iconEl) {
                const origIcon = link.getAttribute('data-orig-icon') || 'radio_button_unchecked';
                const origColor = link.getAttribute('data-orig-color') || '#94a3b8';
                iconEl.innerText = origIcon;
                iconEl.style.color = origColor;
            }
        }
    }

    function handleCalculationsAndFlags() {
        // 1. Age Calculation from DOB
        const dobEl = document.querySelector('input[data-var="date_of_birth"]');
        const ageEl = document.querySelector('input[data-var="age_at_screening"]');
        if (dobEl && ageEl && dobEl.value) {
            try {
                const dob = new Date(dobEl.value);
                const today = new Date();
                let age = today.getFullYear() - dob.getFullYear();
                const m = today.getMonth() - dob.getMonth();
                if (m < 0 || (m === 0 && today.getDate() < dob.getDate())) {
                    age--;
                }
                if (age >= 0 && (!ageEl.value || ageEl.getAttribute('data-auto') === 'true')) {
                    ageEl.value = age;
                    ageEl.setAttribute('data-auto', 'true');
                }
            } catch(e) {}
        }

        // 2. RECIST Target Lesions Sums
        let baseSum = 0;
        let currSum = 0;
        let hasBase = false;
        let hasCurr = false;

        for (let i = 1; i <= 5; i++) {
            const bEl = document.querySelector(`input[data-var="tl${i}_baseline_diam"]`);
            const cEl = document.querySelector(`input[data-var="tl${i}_current_diam"]`);
            if (bEl && bEl.value !== '' && !isNaN(parseFloat(bEl.value))) {
                baseSum += parseFloat(bEl.value);
                hasBase = true;
            }
            if (cEl && cEl.value !== '' && !isNaN(parseFloat(cEl.value))) {
                currSum += parseFloat(cEl.value);
                hasCurr = true;
            }
        }

        const baseSumEl = document.querySelector('input[data-var="tl_baseline_sum"]');
        if (baseSumEl && hasBase) {
            baseSumEl.value = baseSum.toFixed(1);
        }
        const currSumEl = document.querySelector('input[data-var="tl_current_sum"]');
        if (currSumEl && hasCurr) {
            currSumEl.value = currSum.toFixed(1);
        }
        const pctChangeEl = document.querySelector('input[data-var="tl_percent_change"]');
        if (pctChangeEl && hasBase && baseSum > 0 && hasCurr) {
            const pct = ((currSum - baseSum) / baseSum) * 100;
            pctChangeEl.value = (pct >= 0 ? '+' : '') + pct.toFixed(1) + '%';
        }

        // 3. Vital Signs Plausibility soft alerts
        checkPlausibility('sbp_val', 70, 200, 'mmHg');
        checkPlausibility('dbp_val', 40, 130, 'mmHg');
        checkPlausibility('hr_val', 40, 180, 'bpm');
        checkPlausibility('rr_val', 8, 40, 'breaths/min');
        checkPlausibility('temp_val', 35.0, 41.0, '°C');
    }

    function checkPlausibility(varName, min, max, unit) {
        const el = document.querySelector(`input[data-var="${varName}"]`);
        if (!el || el.value === '') return;
        const val = parseFloat(el.value);
        let noteEl = document.getElementById('warn_' + varName);

        if (!isNaN(val) && (val < min || val > max)) {
            if (!noteEl) {
                noteEl = document.createElement('div');
                noteEl.id = 'warn_' + varName;
                noteEl.style.cssText = 'color: #b45309; background: #fffbeb; border: 1px solid #fef3c7; border-radius: 4px; padding: 4px 8px; font-size: 0.75rem; margin-top: 4px; display: flex; align-items: center; gap: 4px;';
                noteEl.innerHTML = '<span class="material-icons-round" style="font-size: 14px;">warning</span> Value outside typical range (' + min + '–' + max + ' ' + unit + '). Flagged for protocol review.';
                el.parentNode.appendChild(noteEl);
            }
            el.style.borderColor = '#f59e0b';
        } else {
            if (noteEl) noteEl.remove();
            el.style.borderColor = '';
        }
    }
    
    // Intercept navigation to ensure changes are saved before navigating
    document.addEventListener('click', async (e) => {
        const link = e.target.closest('a');
        if (!link) return;
        
        const href = link.getAttribute('href');
        if (!href || href.startsWith('#') || href.startsWith('javascript:') || link.target === '_blank') {
            return;
        }

        if (link.getAttribute('onclick')) return;

        if (hasUnsavedChanges || saveTimeout) {
            e.preventDefault();
            clearTimeout(saveTimeout);
            saveTimeout = null;
            
            link.style.pointerEvents = 'none';
            link.style.opacity = '0.7';

            try {
                await saveData(false);
            } catch(err) {
                console.error("Auto-save before navigation failed:", err);
            }
            
            window.location.href = href;
        }
    });

    // Attach Listeners
    document.addEventListener('DOMContentLoaded', () => {
         evaluateSkipLogic();
         checkProgress();
         
         const inputs = document.querySelectorAll('[name^="field_"]');
         inputs.forEach(input => {
             const type = input.type || '';
             const tag = input.tagName.toLowerCase();
             
             input.addEventListener('input', () => {
                 hasUnsavedChanges = true;
                 evaluateSkipLogic();
                 checkProgress();
             });

             input.addEventListener('change', () => {
                 hasUnsavedChanges = true;
                 evaluateSkipLogic();
                 checkProgress();
                 const isClickSelect = (type === 'radio' || type === 'checkbox' || tag === 'select' || type === 'date');
                 debouncedSave(isClickSelect);
             });
         });
    });
</script>

<!-- Modals for Data Monitor -->

<!-- 1. Add Query Modal -->
<div id="addQueryModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Add query for field <span id="addQueryFieldName" style="font-weight: 700;"></span></h3>
            <button class="btn-icon" onclick="closeModal('addQueryModal')"><span class="material-icons-round">close</span></button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="addQueryFieldId">
            <input type="hidden" id="addQueryVisitId">
            <input type="hidden" id="addQueryFormId">
            <input type="hidden" id="addQueryInstanceId">
            
            <div style="margin-bottom: 1rem;">
                <label style="display: block; font-size: 0.85rem; color: #64748b; margin-bottom: 0.25rem;">Current query status</label>
                <div style="display: flex; align-items: center; gap: 0.5rem; color: #ef4444; font-weight: 600;">
                     <span class="material-icons-round" style="font-size: 1.25rem;">help_outline</span> New
                </div>
            </div>
            
            <div class="form-group">
                <label>Remark</label>
                <textarea id="addQueryText" class="form-input" rows="4" style="font-family: inherit;"></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('addQueryModal')">Cancel</button>
            <button class="btn btn-primary" onclick="submitAddQuery()">Add query</button>
        </div>
    </div>
</div>

<!-- 2. View/Update Queries Modal -->
<div id="viewQueryModal" class="modal-overlay">
    <div class="modal-content" style="width: 600px;">
        <div class="modal-header">
            <h3>Query #<span id="viewQueryHeaderId"></span> - <span id="viewQueryFieldName"></span></h3>
            <button class="btn-icon" onclick="closeModal('viewQueryModal')"><span class="material-icons-round">close</span></button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="viewQueryFieldId">
            <div class="form-group" style="display: none;">
                <label>Select a query</label>
                <select id="querySelect" class="form-input" onchange="loadQueryDetails(this.value)">
                    <option value="">Loading...</option>
                </select>
            </div>
            
            <div id="queryDetailsSection" style="display: none;">
                <hr style="border: 0; border-top: 1px solid var(--border-color); margin: 1.5rem 0;">
                
                <div style="margin-bottom: 1rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem;">
                    <div>
                        <label style="font-size: 0.85rem; color: #64748b;">Current status: <span id="queryCurrentStatus" style="font-weight: 600; color: var(--text-dark);"></span></label>
                    </div>
                    <div id="queryRaisedByContainer" style="font-size: 0.825rem; color: #475569;">
                        <!-- JS fills: Raised by: User (Role) -->
                    </div>
                </div>

                <div id="queryRoleRestrictionNotice" style="display: none; font-size: 0.8rem; padding: 0.6rem 0.85rem; border-radius: 6px; background: #fef2f2; border: 1px solid #fee2e2; color: #991b1b; margin-bottom: 1rem;">
                </div>
                
                <div class="form-group">
                    <label style="font-weight: 600; font-size: 0.875rem; color: var(--text-main); margin-bottom: 0.5rem; display: block;">Field Value</label>
                    <div id="queryPreviousValueText" style="font-size: 0.85rem; color: var(--text-light); margin-bottom: 0.5rem;">Previous Value: </div>
                    <div id="queryFieldEditContainer">
                        <!-- Cloned field input will be placed here by JS -->
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Change status <span style="color:red">*</span></label>
                    <select id="queryNewStatus" class="form-input">
                        <option value="">Please select</option>
                        <option value="open">Open</option>
                        <option value="unconfirmed">Unconfirmed</option>
                        <option value="confirmed">Confirmed</option>
                        <option value="resolved">Resolved</option>
                        <option value="closed">Closed</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Remark</label>
                    <textarea id="queryUpdateRemark" class="form-input" rows="3"></textarea>
                </div>
                
                <!-- History Table -->
                <div style="margin-top: 2rem;">
                    <h4 style="font-size: 0.9rem; margin-bottom: 0.5rem;">History</h4>
                    <div style="background: #f8fafc; border: 1px solid var(--border-color); border-radius: 4px; padding: 1rem; max-height: 200px; overflow-y: auto;">
                        <ul id="queryHistoryList" style="list-style: none; padding: 0; margin: 0;">
                            <!-- Filled via JS -->
                        </ul>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <?php if (hasPermission('raise_query')): ?>
                <button class="btn btn-outline" id="btnRaiseNewQueryFromModal" style="margin-right: auto; border-color: var(--primary-color); color: var(--primary-color);" onclick="raiseNewQueryFromModal()">Raise New Query</button>
            <?php endif; ?>
            <button class="btn btn-outline" onclick="closeModal('viewQueryModal')">Cancel</button>
            <button class="btn btn-primary" id="btnSaveQueryUpdate" onclick="submitUpdateQuery()">Save changes</button>
        </div>
    </div>
</div>

<!-- 3. Mark Missing Modal -->
<div id="missingModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Mark field as missing value</h3>
            <button class="btn-icon" onclick="closeModal('missingModal')"><span class="material-icons-round">close</span></button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="missingFieldId">
            <input type="hidden" id="missingVisitId">
            <input type="hidden" id="missingFormId">
            <input type="hidden" id="missingInstanceId">
            
            <p style="margin-bottom: 1rem;">Please select a reason for missing the value on field "<span id="missingFieldName"></span>".</p>
            
            <div class="form-group">
                <label>Reason <span style="color:red">*</span></label>
                <div style="display: flex; flex-direction: column; gap: 0.5rem; margin-top: 0.5rem;">
                    <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;"><input type="radio" name="missingReason" value="Measurement failed"> Measurement failed</label>
                    <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;"><input type="radio" name="missingReason" value="Not applicable"> Not applicable</label>
                    <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;"><input type="radio" name="missingReason" value="Not asked"> Not asked</label>
                    <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;"><input type="radio" name="missingReason" value="Asked but unknown"> Asked but unknown</label>
                    <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;"><input type="radio" name="missingReason" value="Not done"> Not done</label>
                </div>
            </div>
            
            <div class="form-group">
                <label>Comment</label>
                <textarea id="missingComment" class="form-input" rows="3"></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('missingModal')">Cancel</button>
            <button class="btn btn-primary" onclick="submitMissing()">Mark as missing</button>
        </div>
    </div>
</div>

<!-- 4. Clear Data Modal -->
<div id="clearModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Provide a reason for changing this data</h3>
            <button class="btn-icon" onclick="closeModal('clearModal')"><span class="material-icons-round">close</span></button>
        </div>
        <div class="modal-body">
             <input type="hidden" id="clearFieldId">
            <input type="hidden" id="clearVisitId">
            <input type="hidden" id="clearFormId">
            <input type="hidden" id="clearInstanceId">
            
            <div style="background: #fffbeb; border: 1px solid #fcd34d; padding: 1rem; border-radius: 4px; margin-bottom: 1.5rem;">
                <div style="font-weight: 500; color: #92400e;">You are making changes to a field with collected data</div>
            </div>
            
            <div class="form-group">
                <label>Reason for change <span style="color:red">*</span></label>
                <textarea id="clearReason" class="form-input" rows="4"></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('clearModal')">Cancel</button>
            <button class="btn btn-primary" onclick="submitClear()">Continue</button>
        </div>
    </div>
</div>

<!-- 5. Comments Modal -->
<div id="commentsModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Comments for '<span id="commentsFieldName"></span>'</h3>
            <button class="btn-icon" onclick="closeModal('commentsModal')"><span class="material-icons-round">close</span></button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="commentsFieldId">
            
            <div id="cameraList" style="margin-bottom: 2rem; max-height: 200px; overflow-y: auto;">
                 <div id="commentsList" style="display: flex; flex-direction: column; gap: 1rem;">
                     <!-- Filled via JS -->
                 </div>
            </div>
            
            <div class="form-group">
                <label>Comment <span style="color:red">*</span></label>
                <textarea id="newCommentText" class="form-input" rows="3"></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('commentsModal')">Close</button>
            <button class="btn btn-primary" onclick="submitComment()">Add comment</button>
        </div>
    </div>
</div>

<!-- 6. History Modal -->
<div id="historyModal" class="modal-overlay">
    <div class="modal-content" style="width: 90%; max-width: 950px;">
        <div class="modal-header">
            <h3>Value change history for '<span id="historyFieldName"></span>'</h3>
            <button class="btn-icon" onclick="closeModal('historyModal')"><span class="material-icons-round">close</span></button>
        </div>
        <div class="modal-body" style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; font-size: 0.85rem; table-layout: fixed; min-width: 750px;">
                <thead style="background: #f8fafc; border-bottom: 1px solid var(--border-color);">
                    <tr>
                        <th style="text-align: left; padding: 0.75rem; width: 170px;">Updated on</th>
                        <th style="text-align: left; padding: 0.75rem; width: 120px;">Updated by</th>
                        <th style="text-align: left; padding: 0.75rem; width: 120px;">Old value</th>
                        <th style="text-align: left; padding: 0.75rem; width: 120px;">New value</th>
                        <th style="text-align: left; padding: 0.75rem;">Reason / Type</th>
                    </tr>
                </thead>
                <tbody id="historyTableBody">
                    <!-- Filled via JS -->
                </tbody>
            </table>
        </div>
    </div>
</div>


<style>
/* Dropdown Styles */
.dropdown-menu { display: none; position: absolute; right: 0; top: 100%; z-index: 50; background: white; border: 1px solid var(--border-color); box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06); border-radius: 0.375rem; min-width: 180px; }
.dropdown-menu.active { display: block; }
.dropdown-item {
    padding: 0.75rem 1rem;
    cursor: pointer;
    display: flex;
    align-items: center;
    font-size: 0.875rem;
    color: var(--text-main);
    transition: background 0.1s;
}
.dropdown-item:hover { background: #f8fafc; color: var(--accent-color); }
.hover-icon:hover { color: var(--text-main) !important; }
</style>

<script>
    // --- Modal Helpers ---
    function openModal(id) {
        const el = document.getElementById(id);
        if (el) {
            el.style.display = 'flex';
            el.classList.add('active');
        }
    }
    function closeModal(id) {
        const el = document.getElementById(id);
        if (el) {
            el.classList.remove('active');
            el.style.display = 'none';
        }
    }

    // --- Dropdown Handler ---
    function toggleMenu(menuId, event) {
        event.stopPropagation();
        event.preventDefault();
        const menu = document.getElementById(menuId);
        document.querySelectorAll('.dropdown-menu').forEach(m => {
            if(m.id !== menuId) m.style.display = 'none';
        });
        menu.style.display = (menu.style.display === 'block') ? 'none' : 'block';
    }
    // Close menus when clicking outside
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.dropdown-menu') && !e.target.closest('.field-actions')) {
            document.querySelectorAll('.dropdown-menu').forEach(m => m.style.display = 'none');
        }
    });

    // --- Action Button Handler ---
    function handleFieldAction(action, fieldId, fieldName) {
        // Find context
        const subjectId = '<?php echo $subject_id; ?>';
        const visitId = '<?php echo $current_visit_id ?: 0; ?>';
        const formId = '<?php echo $current_form_id; ?>';
        const instId = '<?php echo (int)($current_instance_id ?? 0); ?>';
        
        // Hide Menus
        document.querySelectorAll('.dropdown-menu').forEach(m => m.style.display = 'none');

        if (action === 'add_query') {
            if (window.piSignedOff) {
                alert("Cannot Raise Query\nas the form is Signed Off by Principal Investigator");
                return;
            }
            const isFormReviewedForRole = (window.monitorReviewed && isMonitorRole) || (window.managerReviewed && isManagerRole);
            if (isFormReviewedForRole) {
                alert("Cannot Raise Query\nas the form is marked as review");
                return;
            }
            document.getElementById('addQueryFieldId').value = fieldId;
            document.getElementById('addQueryVisitId').value = visitId;
            document.getElementById('addQueryFormId').value = formId;
            document.getElementById('addQueryInstanceId').value = instId;
            document.getElementById('addQueryFieldName').innerText = fieldName;
            document.getElementById('addQueryText').value = '';
            openModal('addQueryModal');
        } else if (action === 'view_queries') {
             openViewQueryModal(fieldId, fieldName);
        } else if (action === 'mark_missing') {
            document.getElementById('missingFieldId').value = fieldId;
            document.getElementById('missingVisitId').value = visitId;
            document.getElementById('missingFormId').value = formId;
            document.getElementById('missingInstanceId').value = instId;
            document.getElementById('missingFieldName').innerText = fieldName;
             document.getElementById('missingComment').value = '';
             document.querySelectorAll('input[name="missingReason"]').forEach(r => r.checked = false);
            openModal('missingModal');
        } else if (action === 'clear_data') {
            document.getElementById('clearFieldId').value = fieldId;
            document.getElementById('clearVisitId').value = visitId;
            document.getElementById('clearFormId').value = formId;
            document.getElementById('clearInstanceId').value = instId;
            document.getElementById('clearReason').value = '';
            openModal('clearModal');
        } else if (action === 'comments') {
            openCommentsModal(fieldId, fieldName);
        } else if (action === 'history') {
            openHistoryModal(fieldId, fieldName);
        }
    }

    // --- Add Query ---
    async function submitAddQuery() {
        const text = document.getElementById('addQueryText').value;
        
        const fd = new FormData();
        fd.append('action', 'add_query');
        fd.append('subject_id', document.getElementById('addQueryFieldId').getAttribute('data-sub') || '<?php echo $subject_id; ?>'); // Fallback
        fd.append('visit_id', document.getElementById('addQueryVisitId').value);
        fd.append('form_id', document.getElementById('addQueryFormId').value);
        fd.append('field_id', document.getElementById('addQueryFieldId').value);
        fd.append('repeating_instance_id', document.getElementById('addQueryInstanceId').value);
        fd.append('query_text', text);
        
        try {
            const res = await fetch('ajax_data.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                location.reload();
            } else {
                alert("Error: " + data.error);
            }
        } catch(e) { console.error(e); alert("Network Error"); }
    }

    // Helper to update progress UI
    function updateProgressUI(data) {
         // Global Progress
         if (data.subject_progress !== undefined) {
            const gpBar = document.getElementById('global-progress-bar');
            const gpText = document.getElementById('global-progress-text');
            if (gpBar) gpBar.style.width = data.subject_progress + '%';
            if (gpText) gpText.innerText = data.subject_progress + '%';
         }
         
         // Visit Progress
         if (data.visit_progress !== undefined) {
            const vpBar = document.getElementById('visit-progress-bar-<?php echo $current_visit_id; ?>');
            const vpText = document.getElementById('visit-progress-text-<?php echo $current_visit_id; ?>');
            if (vpBar) vpBar.style.width = data.visit_progress + '%';
            if (vpText) vpText.innerText = data.visit_progress + '%';
         }
         
         // Form Status Icon
         const iconId = 'form-icon-<?php echo $current_form_id; ?>-<?php echo $current_instance_id ?: 0; ?>';
         const iconEl = document.getElementById(iconId);
         if (iconEl) {
            let color = '#cbd5e1';
            let icon = 'radio_button_unchecked';
            
            if (data.form_status === 'complete' || data.form_progress === 100) {
                color = '#0d8e6f';
                icon = 'check_circle';
            } else if (data.form_status === 'in_progress' || data.form_progress > 0) {
                color = '#1d6f97';
                icon = 'hourglass_top';
            }
            
            iconEl.style.color = color;
            iconEl.innerText = icon;
         }

        // Local Form Progress Bar
        if (data.form_progress !== undefined) {
             const progressBar = document.querySelector('.current-form-progress');
            const progressText = document.querySelector('.form-progress-text');
            if (progressBar) progressBar.style.width = data.form_progress + '%';
            if (progressText) progressText.innerText = data.form_progress + '%';
        }
    }

    // --- View/Update Query ---
    let currentQueries = [];
    let fieldAuditHistory = [];
    async function openViewQueryModal(fieldId, fieldName) {
         document.getElementById('viewQueryFieldId').value = fieldId;
         document.getElementById('viewQueryFieldName').innerText = fieldName;
         
         const btnRaise = document.getElementById('btnRaiseNewQueryFromModal');
         if (btnRaise) {
             btnRaise.style.display = 'inline-flex';
         }

         openModal('viewQueryModal');
         
         // 1. Clone the field input from the page and add to query field edit container
         const container = document.getElementById('queryFieldEditContainer');
         container.innerHTML = '';
         const originalWrapper = document.querySelector(`.crf-field[data-field-id="${fieldId}"] .input-wrapper`);
         if (originalWrapper) {
             const prevVal = getFieldWrapperValueText(originalWrapper);
             document.getElementById('queryPreviousValueText').innerHTML = `Previous Value: <span style="font-weight: 600; color: var(--text-dark);">${prevVal}</span>`;
             
             const clonedWrapper = originalWrapper.cloneNode(true);
             clonedWrapper.style.marginLeft = '0';
             clonedWrapper.style.maxWidth = '100%';
             
             // To prevent name collision, prefix names and ids in the clone
             clonedWrapper.querySelectorAll('input, select, textarea').forEach(el => {
                 if (el.name) el.name = 'modal_' + el.name;
                 if (el.id) el.id = 'modal_' + el.id;
             });
             
             // Keep cloned inputs empty/blank initially for Coordinator, copy for Monitor
             const isMonitor = <?php echo $is_monitor ? 'true' : 'false'; ?>;
             const clonedInputs = clonedWrapper.querySelectorAll('input, select, textarea');
             if (!isMonitor) {
                 clonedInputs.forEach(clone => {
                     if (clone.type === 'radio' || clone.type === 'checkbox') {
                         clone.checked = false;
                     } else if (clone.tagName === 'SELECT') {
                         clone.value = '';
                     } else {
                         clone.value = '';
                     }
                 });
             } else {
                 // Explicitly copy values for Monitor
                 const originalInputs = originalWrapper.querySelectorAll('input, select, textarea');
                 originalInputs.forEach((orig, idx) => {
                     const clone = clonedInputs[idx];
                     if (!clone) return;
                     if (orig.type === 'radio' || orig.type === 'checkbox') {
                         clone.checked = orig.checked;
                     } else {
                         clone.value = orig.value;
                     }
                 });
             }
             
             // Disable clone elements if user cannot edit
             const canEdit = <?php echo $can_edit ? 'true' : 'false'; ?>;
             if (!canEdit) {
                 clonedInputs.forEach(el => { el.disabled = true; });
             }
             
             container.appendChild(clonedWrapper);
         }
         
         const fd = new FormData();
         fd.append('action', 'get_field_details');
         fd.append('subject_id', '<?php echo $subject_id; ?>');
         fd.append('form_id', '<?php echo $current_form_id; ?>');
         fd.append('field_id', fieldId);
         fd.append('repeating_instance_id', '<?php echo (int)($current_instance_id ?? 0); ?>');
         
         const select = document.getElementById('querySelect');
         select.innerHTML = '<option>Loading...</option>';
         
         try {
             const res = await fetch('ajax_data.php', { method: 'POST', body: fd });
             const data = await res.json();
             if (data.success) {
                  currentQueries = data.queries;
                  fieldAuditHistory = data.history; // Cache field audit logs
                  
                  if (btnRaise) {
                      const isFormReviewed = (window.monitorReviewed && isMonitorRole) || (window.managerReviewed && isManagerRole) || window.piSignedOff;
                      const hasUnresolvedQuery = data.queries.some(q => q.status !== 'closed');
                      if (isFormReviewed || hasUnresolvedQuery || window.piSignedOff) {
                          btnRaise.style.display = 'none';
                      }
                  }
                 select.innerHTML = '<option value="">Select a query</option>';
                 data.queries.forEach((q, idx) => {
                     const opt = document.createElement('option');
                     opt.value = q.id;
                     const statusCapitalized = q.status.charAt(0).toUpperCase() + q.status.slice(1);
                     opt.text = `Query #${q.id} (${statusCapitalized})`;
                     select.add(opt);
                 });
                 if (data.queries.length > 0) {
                     select.value = data.queries[0].id; // Auto select first?
                     loadQueryDetails(data.queries[0].id);
                 }
             }
         } catch(e) { console.error(e); }
    }
    
    function setClonedFieldValue(value) {
        const container = document.getElementById('queryFieldEditContainer');
        if (!container) return;
        
        const strVal = (value !== null && value !== undefined) ? String(value) : '';
        
        const radios = container.querySelectorAll('input[type="radio"]');
        if (radios.length > 0) {
            radios.forEach(r => { r.checked = (r.value === strVal); });
            return;
        }
        
        const checkboxes = container.querySelectorAll('input[type="checkbox"]');
        if (checkboxes.length > 0) {
            const vals = strVal.split(',').map(s => s.trim());
            checkboxes.forEach(c => { c.checked = vals.includes(c.value); });
            return;
        }
        
        const select = container.querySelector('select');
        if (select) {
            select.value = strVal;
            return;
        }
        
        const textarea = container.querySelector('textarea');
        if (textarea) {
            textarea.value = strVal;
            return;
        }
        
        const input = container.querySelector('input');
        if (input) {
            if (input.type === 'date' && strVal) {
                if (/^\d{2}[\/\-]\d{2}[\/\-]\d{4}$/.test(strVal)) {
                    const parts = strVal.split(/[\/\-]/);
                    input.value = `${parts[2]}-${parts[1]}-${parts[0]}`;
                } else {
                    input.value = strVal;
                }
            } else {
                input.value = strVal;
            }
            return;
        }
    }

    function getClonedFieldValue() {
        const container = document.getElementById('queryFieldEditContainer');
        if (!container) return '';
        
        const radios = container.querySelectorAll('input[type="radio"]');
        if (radios.length > 0) {
            const checked = Array.from(radios).find(r => r.checked);
            return checked ? checked.value : '';
        }
        
        const checkboxes = container.querySelectorAll('input[type="checkbox"]');
        if (checkboxes.length > 0) {
            return Array.from(checkboxes).filter(c => c.checked).map(c => c.value).join(',');
        }
        
        const select = container.querySelector('select');
        if (select) {
            return select.value;
        }
        
        const textarea = container.querySelector('textarea');
        if (textarea) {
            return textarea.value;
        }
        
        const input = container.querySelector('input');
        if (input) {
            return input.value;
        }
        
        return '';
    }
    
    async function loadQueryDetails(queryId) {
        if (!queryId) {
            document.getElementById('queryDetailsSection').style.display = 'none';
            return;
        }
        const query = currentQueries.find(q => q.id == queryId);
        if (!query) return;
        
        document.getElementById('queryDetailsSection').style.display = 'block';
        document.getElementById('viewQueryHeaderId').innerText = query.id;
        
        // Dynamically style current status
        const statusSpan = document.getElementById('queryCurrentStatus');
        statusSpan.innerText = query.status.charAt(0).toUpperCase() + query.status.slice(1);
        if (query.status === 'new') {
            statusSpan.style.color = '#1d6f97'; // Brand Blue
        } else if (query.status === 'open') {
            statusSpan.style.color = '#0284c7'; // Sky Blue
        } else if (query.status === 'answered') {
            statusSpan.style.color = '#ea580c'; // Orange
        } else if (query.status === 'closed') {
            statusSpan.style.color = '#0d8e6f'; // Brand Green
        } else {
            statusSpan.style.color = 'var(--text-dark)';
        }
        
        // Display raised by information
        const raisedByEl = document.getElementById('queryRaisedByContainer');
        if (raisedByEl) {
            const raisedRole = query.raised_by_role || 'Data Monitor';
            const raisedName = query.created_by_name || 'Reviewer';
            raisedByEl.innerHTML = `<span style="color: #64748b;">Raised by:</span> <strong>${escapeHtml(raisedName)}</strong> <span style="background: #e2e8f0; color: #334155; padding: 2px 7px; border-radius: 99px; font-size: 0.72rem; font-weight: 600; margin-left: 4px;">${escapeHtml(raisedRole)}</span>`;
        }
        
        // Check permissions: query raised by Data Monitor can only be closed by Data Monitor;
        // query raised by Data Manager can only be closed by Data Manager.
        const qRoleLower = (query.raised_by_role || '').toLowerCase();
        const isQMonitor = qRoleLower.includes('monitor');
        const isQManager = qRoleLower.includes('manager');

        const currentUserIsMonitor = <?php echo (strpos($current_role, 'monitor') !== false) ? 'true' : 'false'; ?>;
        const currentUserIsManager = <?php echo (strpos($current_role, 'manager') !== false) ? 'true' : 'false'; ?>;
        const currentUserIsAdmin = <?php echo $is_admin ? 'true' : 'false'; ?>;

        let canManageQuery = currentUserIsAdmin;
        if (currentUserIsMonitor && isQMonitor) {
            canManageQuery = true;
        } else if (currentUserIsManager && isQManager) {
            canManageQuery = true;
        } else if (!isQMonitor && !isQManager && (currentUserIsMonitor || currentUserIsManager)) {
            // Fallback for legacy queries with unknown role
            canManageQuery = true;
        }

        if (window.piSignedOff) {
            canManageQuery = false;
        }

        // Restriction notice if user is reviewer but doesn't match the query creator's role
        const noticeEl = document.getElementById('queryRoleRestrictionNotice');
        if (noticeEl) {
            if (window.piSignedOff) {
                noticeEl.style.display = 'block';
                noticeEl.innerHTML = `<span class="material-icons-round" style="font-size: 15px; vertical-align: middle;">lock</span> Form is Signed Off by <strong>Principal Investigator</strong>. All queries, field changes, and revocations are completely locked.`;
            } else if ((currentUserIsMonitor || currentUserIsManager) && !canManageQuery && query.status !== 'closed') {
                const targetRoleText = isQMonitor ? 'Data Monitor' : 'Data Manager';
                noticeEl.style.display = 'block';
                noticeEl.innerHTML = `<span class="material-icons-round" style="font-size: 15px; vertical-align: middle;">lock</span> This query was raised by <strong>${escapeHtml(query.raised_by_role || targetRoleText)}</strong>. Only a <strong>${targetRoleText}</strong> can close or re-query it.`;
            } else {
                noticeEl.style.display = 'none';
            }
        }

        // Dynamic Status Options based on Role and Current Status
        const statusSelect = document.getElementById('queryNewStatus');
        statusSelect.innerHTML = '<option value="">Please select</option>';
        
        // Reviewer can select Close Query (if new, open, or answered) and Re Query (if answered) ONLY if permitted
        if (canManageQuery) {
            if (['new', 'open', 'answered', 'unconfirmed'].includes(query.status)) {
                 statusSelect.add(new Option('Close Query', 'closed'));
            }
            if (query.status === 'answered') {
                 statusSelect.add(new Option('Re Query', 'open'));
            }
        }

        <?php if ($is_coordinator || $current_role === 'data_entry' || strpos($current_role, 'entry') !== false): ?>
            // Coordinator can only select Answered Query if status is new or open
             if (['new', 'open'].includes(query.status) && !window.piSignedOff) {
                  statusSelect.add(new Option('Answered Query', 'answered'));
            }
        <?php endif; ?>

        document.getElementById('queryNewStatus').value = '';
        document.getElementById('queryUpdateRemark').value = '';

        const hasOptions = (statusSelect.options.length > 1);
        document.getElementById('queryNewStatus').disabled = !hasOptions;
        document.getElementById('queryUpdateRemark').disabled = !hasOptions;
        const btnSave = document.getElementById('btnSaveQueryUpdate');
        if (btnSave) btnSave.style.display = hasOptions ? 'block' : 'none';
        
        // Show Field Value & Previous Value on answered/closed queries or new/open queries
        const isMonitor = <?php echo $is_monitor ? 'true' : 'false'; ?>;
        const prevTextEl = document.getElementById('queryPreviousValueText');
        const originalWrapper = document.querySelector(`.crf-field[data-field-id="${query.field_id}"] .input-wrapper`);
        const currentVal = getFieldWrapperValueText(originalWrapper);
        
        if (['answered', 'closed'].includes(query.status)) {
            let newVal = query.query_new_value;
            let oldVal = query.query_old_value;
            
            if (newVal !== undefined && newVal !== null && newVal !== '') {
                setClonedFieldValue(newVal);
                prevTextEl.innerHTML = `Previous Value: <span style="font-weight: 600; color: var(--text-dark);">${oldVal || currentVal}</span>`;
            } else {
                const resolutionAudit = fieldAuditHistory.find(h => 
                    h.change_type === 'update' && 
                    h.reason_for_change && 
                    (h.reason_for_change.includes('Updated via query resolution') || h.reason_for_change.includes('Updated query'))
                );
                if (resolutionAudit && resolutionAudit.new_value) {
                    setClonedFieldValue(resolutionAudit.new_value);
                    prevTextEl.innerHTML = `Previous Value: <span style="font-weight: 600; color: var(--text-dark);">${resolutionAudit.old_value || 'Empty'}</span>`;
                } else {
                    setClonedFieldValue(currentVal);
                    prevTextEl.innerHTML = `Previous Value: <span style="font-weight: 600; color: var(--text-dark);">${currentVal}</span>`;
                }
            }
        } else {
            // Coordinator view or new/open query
            if (!isMonitor) {
                setClonedFieldValue(''); // Coordinator starts empty so they can enter new value if desired
            } else {
                setClonedFieldValue(currentVal);
            }
            prevTextEl.innerHTML = `Previous Value: <span style="font-weight: 600; color: var(--text-dark);">${currentVal}</span>`;
        }

        const clonedInputs = document.getElementById('queryFieldEditContainer').querySelectorAll('input, select, textarea');
        clonedInputs.forEach(el => {
            // Keep disabled for Monitors since they only verify, otherwise based on hasOptions
            if (isMonitor) {
                el.disabled = true;
            } else {
                el.disabled = !hasOptions;
            }
        });
        
        // Fetch History
        const fd = new FormData();
        fd.append('action', 'get_query_history');
        fd.append('query_id', queryId);
        
        const list = document.getElementById('queryHistoryList');
        list.innerHTML = '<li>Loading history...</li>';
        
        try {
            const res = await fetch('ajax_data.php', { method: 'POST', body: fd });
            const data = await res.json();
             if (data.success) {
                  list.innerHTML = '';
                  
                  // Also check if history has an answered/closed step with new_value
                  const answeredStep = data.history.find(h => h.new_value !== null && h.new_value !== undefined && h.new_value !== '');
                  if (answeredStep && ['answered', 'closed'].includes(query.status)) {
                      setClonedFieldValue(answeredStep.new_value);
                      if (answeredStep.old_value) {
                          prevTextEl.innerHTML = `Previous Value: <span style="font-weight: 600; color: var(--text-dark);">${answeredStep.old_value}</span>`;
                      }
                  }
                  
                  data.history.forEach(h => {
                      const li = document.createElement('li');
                      li.style.cssText = "border-bottom: 1px solid #e2e8f0; padding: 0.5rem 0;";
                      
                      let statusText = '';
                      if (!h.status_from) {
                          statusText = 'New Query Raised';
                      } else if (h.status_to === 'closed') {
                          statusText = 'Query Resolved';
                      } else {
                          statusText = `Status: ${h.status_from} &rarr; ${h.status_to}`;
                      }

                      let valueChangeHtml = '';
                      if (h.old_value !== null && h.new_value !== null) {
                          valueChangeHtml = `
                             <div style="font-size: 0.75rem; color: #64748b; margin-bottom: 4px;">
                                 Value change: <span style="text-decoration: line-through; color: #ef4444;">${h.old_value || 'Empty'}</span> &rarr; <span style="font-weight: 600; color: #0d8e6f;">${h.new_value || 'Empty'}</span>
                             </div>
                          `;
                      }

                      li.innerHTML = `
                         <div style="font-size: 0.75rem; color: #64748b; margin-bottom: 2px;">
                             <strong>${h.created_by_name || 'User'}</strong> - ${h.created_at}
                         </div>
                         <div style="font-size: 0.85rem; color: #334155; margin-bottom: 4px;">${h.remark}</div>
                         ${valueChangeHtml}
                         <div style="font-size: 0.75rem; color: #94a3b8; font-style: italic;">
                             ${statusText}
                         </div>
                      `;
                      list.appendChild(li);
                  });
              }
         } catch(e) {}
     }

    function getFieldWrapperValueText(wrapper) {
        if (!wrapper) return 'Empty';
        
        const radios = wrapper.querySelectorAll('input[type="radio"]');
        if (radios.length > 0) {
            const checked = Array.from(radios).find(r => r.checked);
            return checked ? checked.value : 'Empty';
        }
        
        const checkboxes = wrapper.querySelectorAll('input[type="checkbox"]');
        if (checkboxes.length > 0) {
            const checkedVals = Array.from(checkboxes).filter(c => c.checked).map(c => c.value);
            return checkedVals.length > 0 ? checkedVals.join(', ') : 'Empty';
        }
        
        const select = wrapper.querySelector('select');
        if (select) {
            const selectedOpt = select.options[select.selectedIndex];
            return selectedOpt ? (selectedOpt.text || selectedOpt.value) : 'Empty';
        }
        
        const textarea = wrapper.querySelector('textarea');
        if (textarea) {
            return textarea.value.trim() || 'Empty';
        }
        
        const input = wrapper.querySelector('input');
        if (input) {
            return input.value.trim() || 'Empty';
        }
        
        return 'Empty';
    }

    function raiseNewQueryFromModal() {
        const fieldId = document.getElementById('viewQueryFieldId').value;
        const fieldName = document.getElementById('viewQueryFieldName').innerText;
        closeModal('viewQueryModal');
        handleFieldAction('add_query', fieldId, fieldName);
    }

    async function submitUpdateQuery() {
         if (window.piSignedOff) {
             alert("Cannot update query: This form is Signed Off by Principal Investigator.");
             return;
         }
         const queryId = document.getElementById('querySelect').value;
         const status = document.getElementById('queryNewStatus').value;
         const remark = document.getElementById('queryUpdateRemark').value;
         
         if (!queryId) return;
         if (!status) {
             const statusSelect = document.getElementById('queryNewStatus');
             statusSelect.style.outline = '2px solid #ef4444';
             statusSelect.scrollIntoView({ behavior: 'smooth', block: 'center' });
             setTimeout(() => {
                 statusSelect.style.outline = '';
             }, 3000);
             return;
         }
         
         const fd = new FormData();
         fd.append('action', 'update_query_status');
         fd.append('query_id', queryId);
         fd.append('status', status);
         fd.append('remark', remark);
         
         // Collect field_value if coordinator answering query or user can edit
         const isCoordinator = <?php echo ($is_coordinator || hasPermission('enter_data')) ? 'true' : 'false'; ?>;
         const canEdit = <?php echo $can_edit ? 'true' : 'false'; ?>;
         if (status === 'answered' || canEdit || isCoordinator) {
             const fValue = getClonedFieldValue();
             if (fValue !== '' && fValue !== null) {
                 fd.append('field_value', fValue);
             }
         }
         
         try {
            const res = await fetch('ajax_data.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                location.reload();
            } else {
                alert("Error: " + data.error);
            }
        } catch(e) { console.error(e); alert("Network Error"); }
    }

    // --- Mark Missing ---
    async function submitMissing() {
        const code = document.querySelector('input[name="missingReason"]:checked')?.value;
        if (!code) { alert("Please select a reason"); return; }
        
        const fd = new FormData();
        fd.append('action', 'mark_missing');
        fd.append('subject_id', document.getElementById('missingFieldId').getAttribute('data-sub') || '<?php echo $subject_id; ?>');
        fd.append('visit_id', document.getElementById('missingVisitId').value);
        fd.append('form_id', document.getElementById('missingFormId').value);
        fd.append('field_id', document.getElementById('missingFieldId').value);
        fd.append('repeating_instance_id', document.getElementById('missingInstanceId').value);
        fd.append('code', code);
        fd.append('comment', document.getElementById('missingComment').value);
        
        try {
            const res = await fetch('ajax_data.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                // Parse and update progress
                 updateProgressUI(data);
                 closeModal('missingModal');
                 // Reload field value visually? Reloading page is safest for now to show "Missing" state correctly
                 location.reload(); 
            } else {
                alert("Error: " + data.error);
            }
        } catch(e) { console.error(e); alert("Network Error"); }
    }

    // --- Clear Data ---
    async function submitClear() {
        const reason = document.getElementById('clearReason').value;
        if (!reason) { alert("Reason is required"); return; }
        
        const fd = new FormData();
        fd.append('action', 'clear_data');
        fd.append('subject_id', '<?php echo $subject_id; ?>');
        fd.append('visit_id', document.getElementById('clearVisitId').value);
        fd.append('form_id', document.getElementById('clearFormId').value);
        fd.append('field_id', document.getElementById('clearFieldId').value);
        fd.append('repeating_instance_id', document.getElementById('clearInstanceId').value);
        fd.append('reason', reason);
        
        try {
            const res = await fetch('ajax_data.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                // Parse and update progress
                 updateProgressUI(data);
                 closeModal('clearModal');
                 location.reload(); // Reload to clear the field input visual
            } else {
                alert("Error: " + data.error);
            }
        } catch(e) { console.error(e); alert("Network Error"); }
    }
    
    // --- Comments & History ---
    async function openCommentsModal(fieldId, fieldName) {
        document.getElementById('commentsFieldId').value = fieldId;
        document.getElementById('commentsFieldName').innerText = fieldName;
        document.getElementById('newCommentText').value = '';
        openModal('commentsModal');
        
        // Load Comments
        const fd = new FormData();
         fd.append('action', 'get_field_details');
         fd.append('subject_id', '<?php echo $subject_id; ?>');
         fd.append('form_id', '<?php echo $current_form_id; ?>');
         fd.append('field_id', fieldId);
         fd.append('repeating_instance_id', '<?php echo (int)($current_instance_id ?? 0); ?>');
         
         const list = document.getElementById('commentsList');
         list.innerHTML = 'Loading...';
         
          try {
             const res = await fetch('ajax_data.php', { method: 'POST', body: fd });
             const data = await res.json();
             if (data.success) {
                 if (data.comments.length === 0) list.innerHTML = '<div style="color:#94a3b8; font-style:italic;">No comments yet</div>';
                 else {
                     list.innerHTML = '';
                     data.comments.forEach(c => {
                         const div = document.createElement('div');
                         div.style.cssText = 'background: #f8fafc; padding: 0.75rem; border-radius: 6px; border: 1px solid var(--border-color);';
                         div.innerHTML = `
                            <div style="font-size: 0.85rem; color: #334155; margin-bottom: 0.25rem;">${c.comment_text}</div>
                            <div style="font-size: 0.75rem; color: #94a3b8;">${c.created_by_name} • ${c.created_at}</div>
                         `;
                         list.appendChild(div);
                     });
                 }
             }
        } catch(e) {}
    }
    
    async function submitComment() {
         const text = document.getElementById('newCommentText').value;
         if (!text) return;
         
         const fd = new FormData();
         fd.append('action', 'add_comment');
         fd.append('subject_id', '<?php echo $subject_id; ?>');
         fd.append('visit_id', '<?php echo $current_visit_id ?: 0; ?>');
         fd.append('form_id', '<?php echo $current_form_id; ?>');
         fd.append('field_id', document.getElementById('commentsFieldId').value);
         fd.append('repeating_instance_id', '<?php echo (int)($current_instance_id ?? 0); ?>');
         fd.append('comment', text);
         
         try {
            const res = await fetch('ajax_data.php', { method: 'POST', body: fd });
            if ((await res.json()).success) {
                openCommentsModal(document.getElementById('commentsFieldId').value, document.getElementById('commentsFieldName').innerText); // Reload
            }
        } catch(e) {}
    }
    
    async function openHistoryModal(fieldId, fieldName) {
         document.getElementById('historyFieldName').innerText = fieldName;
         openModal('historyModal');
         
         const tbody = document.getElementById('historyTableBody');
         tbody.innerHTML = '<tr><td colspan="5">Loading...</td></tr>';
         
         const fd = new FormData();
         fd.append('action', 'get_field_details');
         fd.append('subject_id', '<?php echo $subject_id; ?>');
         fd.append('form_id', '<?php echo $current_form_id; ?>');
         fd.append('field_id', fieldId);
         fd.append('repeating_instance_id', '<?php echo (int)($current_instance_id ?? 0); ?>');
         
          try {
             const res = await fetch('ajax_data.php', { method: 'POST', body: fd });
             const data = await res.json();
             if (data.success) {
                 tbody.innerHTML = '';
                 data.history.forEach(h => {
                     const tr = document.createElement('tr');
                     tr.style.borderBottom = '1px solid var(--border-color)';
                     tr.innerHTML = `
                        <td style="padding: 0.75rem; white-space: nowrap;">${h.action_at}</td>
                        <td style="padding: 0.75rem; white-space: normal; word-break: break-word;">${h.action_by_name}</td>
                        <td style="padding: 0.75rem; color: #ef4444; white-space: normal; word-break: break-word;">${h.old_value || ''}</td>
                        <td style="padding: 0.75rem; color: #0d8e6f; white-space: normal; word-break: break-word;">${h.new_value || ''}</td>
                        <td style="padding: 0.75rem; white-space: normal; word-break: break-word;">${h.reason_for_change || h.change_type}</td>
                     `;
                     tbody.appendChild(tr);
                 });
             }
         } catch(e) {}
    }
</script>

</body>
</html>



<!-- ========================================================================= -->
<!-- COMMON FORM MODALS (MH, AE, CM) -->
<!-- ========================================================================= -->

<!-- 1. COMMON FORM EDITOR MODAL -->
<div class="modal-overlay" id="modalCommonFormEditor" style="z-index: 10050;">
    <div class="modal-card" style="max-width: 65%; width: 65%; background: #ffffff; border-radius: 12px; overflow: hidden; padding: 0; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.15);">
        <div class="modal-header" style="background: #f8fafc; padding: 1.25rem 1.5rem; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center;">
            <div>
                <div style="font-size: 0.75rem; color: #64748b; text-transform: uppercase; font-weight: 700; letter-spacing: 0.05em;" id="cfEditorSubTitle">Subject Common Form</div>
                <h2 style="font-size: 1.25rem; margin: 0.2rem 0 0 0; color: #0f172a; display: flex; align-items: center; gap: 0.5rem;" id="cfEditorTitle">
                    Record Details
                </h2>
            </div>
            <div style="display: flex; align-items: center; gap: 0.75rem;">
                <div id="cfEditorBadges" style="display: flex; gap: 0.4rem;"></div>
                <button type="button" onclick="closeModal('modalCommonFormEditor')" style="background: none; border: none; color: #64748b; cursor: pointer; padding: 4px; display: flex; align-items: center;">
                    <span class="material-icons-round" style="font-size: 1.5rem;">close</span>
                </button>
            </div>
        </div>

        <div style="padding: 1.5rem; max-height: calc(85vh - 140px); overflow-y: auto; background: #ffffff;" id="cfEditorBody">
            <input type="hidden" id="cfRecordId" value="0">
            <input type="hidden" id="cfFormType" value="MH">
            
            <div id="cfValidationAlert" style="display: none; padding: 0.85rem 1rem; background: #fef2f2; border: 1px solid #fca5a5; color: #991b1b; border-radius: 8px; font-size: 0.875rem; margin-bottom: 1.25rem;"></div>

            <form id="commonFormElement" onsubmit="event.preventDefault();">
                <div id="cfDynamicFields">
                    <!-- Fields dynamically injected by JS -->
                </div>
            </form>
        </div>

        <div class="modal-footer" style="background: #f8fafc; padding: 1rem 1.5rem; border-top: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.75rem;">
            <div style="display: flex; gap: 0.5rem;">
                <button type="button" id="btnCfAudit" onclick="openCommonAuditModalFromEditor()" class="btn btn-sm btn-outline" style="display: none; align-items: center; gap: 0.3rem;">
                    <span class="material-icons-round" style="font-size: 1rem;">history</span> Audit Trail
                </button>
                <button type="button" id="btnCfQuery" onclick="openCommonQueryModalFromEditor()" class="btn btn-sm btn-outline" style="display: none; align-items: center; gap: 0.3rem;">
                    <span class="material-icons-round" style="font-size: 1rem; color: #ef4444;">help_outline</span> Queries
                </button>
            </div>

            <div style="display: flex; gap: 0.6rem; align-items: center;">
                <button type="button" onclick="closeModal('modalCommonFormEditor')" class="btn btn-sm" style="background: white; border: 1px solid #cbd5e1; color: #475569; padding: 0.5rem 1.1rem;">
                    Cancel
                </button>
                
                <?php if ($is_data_editor): ?>
                    <button type="button" id="btnCfSaveDraft" onclick="saveCommonRecord('draft')" class="btn btn-sm" style="background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; font-weight: 600; padding: 0.5rem 1.2rem;">
                        Save Draft
                    </button>
                    <button type="button" id="btnCfMarkComplete" onclick="saveCommonRecord('complete')" class="btn btn-sm btn-primary" style="font-weight: 600; padding: 0.5rem 1.4rem;">
                        Mark Complete
                    </button>
                <?php endif; ?>

                <?php if ($is_manager_role || $is_admin || $is_monitor_role || $is_coordinator): ?>
                    <div id="cfModalWorkflowBtns" style="display: inline-flex; gap: 0.5rem; align-items: center;">
                        <button type="button" id="btnCfSdr" onclick="markCommonSDRFromEditor()" class="btn btn-sm" style="display: none; background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; font-weight: 600; padding: 0.5rem 1.2rem; align-items: center; gap: 4px;">
                            <span class="material-icons-round" style="font-size: 1rem;">verified</span> Mark SDR
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- 2. COMMON FORM QUERY MODAL -->
<div class="modal-overlay" id="modalCommonQuery" style="z-index: 10060;">
    <div class="modal-card" style="max-width: 720px; width: 94%; background: #ffffff; border-radius: 12px; overflow: hidden; padding: 0; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.15);">
        <div class="modal-header" style="background: #f8fafc; padding: 1.25rem 1.5rem; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center;">
            <div>
                <div style="font-size: 0.75rem; color: #64748b; text-transform: uppercase; font-weight: 700; letter-spacing: 0.05em;">Data Review & Queries</div>
                <h2 style="font-size: 1.15rem; margin: 0.2rem 0 0 0; color: #0f172a;" id="cfQueryRecordTitle">
                    Queries for Record
                </h2>
            </div>
            <button type="button" onclick="closeModal('modalCommonQuery')" style="background: none; border: none; color: #64748b; cursor: pointer;">
                <span class="material-icons-round" style="font-size: 1.5rem;">close</span>
            </button>
        </div>

        <div style="padding: 1.5rem; max-height: 65vh; overflow-y: auto; background: #ffffff;" id="cfQueryBody">
            <!-- Rendered by JS -->
        </div>

        <div class="modal-footer" style="background: #f8fafc; padding: 1rem 1.5rem; border-top: 1px solid #e2e8f0; text-align: right;">
            <button type="button" onclick="closeModal('modalCommonQuery')" class="btn btn-sm btn-outline">Close</button>
        </div>
    </div>
</div>

<!-- 3. COMMON FORM AUDIT & SDR HISTORY MODAL -->
<div class="modal-overlay" id="modalCommonAudit" style="z-index: 10060;">
    <div class="modal-card" style="max-width: 900px; width: 95%; background: #ffffff; border-radius: 12px; overflow: hidden; padding: 0; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.15);">
        <div class="modal-header" style="background: #f8fafc; padding: 1.25rem 1.5rem; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center;">
            <div>
                <div style="font-size: 0.75rem; color: #64748b; text-transform: uppercase; font-weight: 700; letter-spacing: 0.05em;">Clinical History & Audit Log</div>
                <h2 style="font-size: 1.15rem; margin: 0.2rem 0 0 0; color: #0f172a;" id="cfAuditRecordTitle">
                    Audit Trail
                </h2>
            </div>
            <button type="button" onclick="closeModal('modalCommonAudit')" style="background: none; border: none; color: #64748b; cursor: pointer;">
                <span class="material-icons-round" style="font-size: 1.5rem;">close</span>
            </button>
        </div>

        <div style="padding: 1.5rem; max-height: 70vh; overflow-y: auto; background: #ffffff;" id="cfAuditBody">
            <!-- Rendered by JS -->
        </div>

        <div class="modal-footer" style="background: #f8fafc; padding: 1rem 1.5rem; border-top: 1px solid #e2e8f0; text-align: right;">
            <button type="button" onclick="closeModal('modalCommonAudit')" class="btn btn-sm btn-outline">Close</button>
        </div>
    </div>
</div>

<!-- 4. COMMON FORM VOID MODAL -->
<div class="modal-overlay" id="modalCommonVoid" style="z-index: 10070;">
    <div class="modal-card" style="max-width: 440px; width: 90%; background: #ffffff; border-radius: 12px; overflow: hidden; padding: 0; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.2);">
        <div style="padding: 1.5rem; text-align: center; background: #ffffff;">
            <div style="width: 50px; height: 50px; border-radius: 50%; background: #fef2f2; color: #dc2626; display: flex; align-items: center; justify-content: center; margin: 0 auto 1rem auto;">
                <span class="material-icons-round" style="font-size: 28px;">remove_circle_outline</span>
            </div>
            <h3 style="margin: 0 0 0.5rem 0; font-size: 1.15rem; color: #0f172a;" id="cfVoidTitle">Void Clinical Record</h3>
            <p style="font-size: 0.875rem; color: #64748b; margin-bottom: 1rem;" id="cfVoidSubtitle">
                Voided records will be excluded from active selections but preserved for regulatory audit compliance.
            </p>
            <input type="hidden" id="cfVoidRecordId" value="0">
            <textarea id="cfVoidReasonInput" class="form-input" rows="3" placeholder="Enter reason for voiding this record..." style="width: 100%; font-size: 0.875rem; resize: vertical;"></textarea>
        </div>
        <div class="modal-footer" style="background: #f8fafc; padding: 0.85rem 1.5rem; display: flex; gap: 0.75rem; justify-content: flex-end; border-top: 1px solid #e2e8f0;">
            <button type="button" onclick="closeModal('modalCommonVoid')" class="btn btn-sm btn-outline">Cancel</button>
            <button type="button" onclick="submitCommonVoid()" class="btn btn-sm" style="background: #dc2626; color: white; border: none; font-weight: 600; padding: 0.45rem 1.2rem;">
                Void Record
            </button>
        </div>
    </div>
</div>

<!-- 5. REUSABLE CONFIRMATION MODAL (FOR SDR & RECORD ACTIONS) -->
<div class="modal-overlay" id="modalConfirmAction" style="z-index: 10080;" onclick="closeConfirmModal()">
    <div class="modal-card" onclick="event.stopPropagation()" style="max-width: 460px; width: 90%; background: #ffffff; border-radius: 12px; overflow: hidden; padding: 0; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.2);">
        <div style="padding: 1.75rem 1.5rem; text-align: center; background: #ffffff;">
            <div id="confirmIconContainer" style="width: 56px; height: 56px; border-radius: 50%; background: #ecfdf5; color: #047857; display: flex; align-items: center; justify-content: center; margin: 0 auto 1rem auto;">
                <span class="material-icons-round" id="confirmIcon" style="font-size: 30px;">verified</span>
            </div>
            <h3 id="confirmTitle" style="margin: 0 0 0.5rem 0; font-size: 1.15rem; color: #0f172a; font-weight: 700;">Confirm Action</h3>
            <div id="confirmMessage" style="font-size: 0.875rem; color: #475569; line-height: 1.5;">
                Are you sure you want to proceed with this action?
            </div>
            <div id="confirmRemarksContainer" style="display: none; margin-top: 1rem; text-align: left;">
                <label id="confirmRemarksLabel" style="font-weight: 600; font-size: 0.825rem; color: #1e293b; display: block; margin-bottom: 0.35rem;">
                    Remarks / Reason <span style="color: #dc2626;">*</span>
                </label>
                <textarea id="confirmRemarksInput" class="form-input" rows="3" placeholder="Enter reason (minimum 10 characters)..." style="width: 100%; font-size: 0.85rem; resize: vertical;"></textarea>
                <div style="font-size: 0.72rem; color: #64748b; margin-top: 0.25rem;">Minimum 10 characters required.</div>
            </div>
        </div>
        <div class="modal-footer" style="background: #f8fafc; padding: 0.85rem 1.5rem; display: flex; gap: 0.75rem; justify-content: flex-end; border-top: 1px solid #e2e8f0;">
            <button type="button" onclick="closeConfirmModal()" class="btn btn-sm btn-outline" style="background: #ffffff; border: 1px solid #cbd5e1; color: #475569; padding: 0.45rem 1rem;">
                Cancel
            </button>
            <button type="button" id="confirmSubmitBtn" class="btn btn-sm btn-primary" style="padding: 0.45rem 1.25rem;">
                Confirm
            </button>
        </div>
    </div>
</div>

<script>
function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

let pendingConfirmCallback = null;

function closeConfirmModal() {
    pendingConfirmCallback = null;
    closeModal('modalConfirmAction');
}

function showConfirmModal(options) {
    const title = options.title || 'Confirm Action';
    const message = options.message || 'Are you sure you want to proceed?';
    const icon = options.icon || 'warning';
    const iconBg = options.iconBg || '#fef3c7';
    const iconColor = options.iconColor || '#d97706';
    const btnText = options.btnText || 'Confirm';
    const btnClass = options.btnClass || 'btn-primary';
    const btnStyle = options.btnStyle || '';
    const requireRemarks = !!options.requireRemarks;

    const titleEl = document.getElementById('confirmTitle');
    if (titleEl) titleEl.textContent = title;
    const msgEl = document.getElementById('confirmMessage');
    if (msgEl) msgEl.innerHTML = message;
    
    const iconEl = document.getElementById('confirmIcon');
    if (iconEl) iconEl.textContent = icon;
    
    const iconBox = document.getElementById('confirmIconContainer');
    if (iconBox) {
        iconBox.style.background = iconBg;
        iconBox.style.color = iconColor;
    }

    const remarksContainer = document.getElementById('confirmRemarksContainer');
    const remarksInput = document.getElementById('confirmRemarksInput');
    const remarksLabel = document.getElementById('confirmRemarksLabel');
    if (remarksContainer) {
        if (requireRemarks) {
            remarksContainer.style.display = 'block';
            if (remarksInput) remarksInput.value = '';
            if (remarksLabel && options.remarksLabel) {
                remarksLabel.innerHTML = options.remarksLabel + ' <span style="color: #dc2626;">*</span>';
            }
        } else {
            remarksContainer.style.display = 'none';
        }
    }

    const confirmBtn = document.getElementById('confirmSubmitBtn');
    if (confirmBtn) {
        confirmBtn.textContent = btnText;
        confirmBtn.className = 'btn btn-sm ' + btnClass;
        confirmBtn.style.cssText = btnStyle || '';
        confirmBtn.onclick = function() {
            let remarks = '';
            if (requireRemarks) {
                remarks = remarksInput ? remarksInput.value.trim() : '';
                if (remarks.length < 10) {
                    showToast('Remarks must be at least 10 characters.', 'warning');
                    if (remarksInput) remarksInput.focus();
                    return;
                }
                if (remarks.length > 500) {
                    showToast('Remarks cannot exceed 500 characters.', 'warning');
                    return;
                }
            }
            closeModal('modalConfirmAction');
            if (typeof pendingConfirmCallback === 'function') {
                const cb = pendingConfirmCallback;
                pendingConfirmCallback = null;
                cb(remarks);
            }
        };
    }

    pendingConfirmCallback = options.onConfirm || null;
    openModal('modalConfirmAction');
}

function showToast(message, type = 'success', duration = 3500) {
    let container = document.getElementById('toastContainer');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toastContainer';
        container.style.cssText = 'position: fixed; bottom: 20px; right: 20px; z-index: 99999; display: flex; flex-direction: column; gap: 10px; pointer-events: none;';
        document.body.appendChild(container);
    }
    
    const toast = document.createElement('div');
    toast.style.cssText = `
        pointer-events: auto;
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 12px 18px;
        background: #0f172a;
        color: #ffffff;
        border-radius: 10px;
        box-shadow: 0 10px 15px -3px rgba(0,0,0,0.2), 0 4px 6px -2px rgba(0,0,0,0.1);
        font-size: 0.875rem;
        font-weight: 500;
        opacity: 0;
        transform: translateY(10px);
        transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        max-width: 380px;
    `;
    
    let icon = 'info';
    let iconColor = '#3b82f6';
    if (type === 'success') { icon = 'check_circle'; iconColor = '#10b981'; }
    else if (type === 'error') { icon = 'error'; iconColor = '#ef4444'; }
    else if (type === 'warning') { icon = 'warning'; iconColor = '#f59e0b'; }

    toast.innerHTML = `
        <span class="material-icons-round" style="color: ${iconColor}; font-size: 20px;">${icon}</span>
        <span style="flex: 1; line-height: 1.4;">${escapeHtml(message)}</span>
    `;

    container.appendChild(toast);
    
    requestAnimationFrame(() => {
        toast.style.opacity = '1';
        toast.style.transform = 'translateY(0)';
    });

    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(10px)';
        setTimeout(() => toast.remove(), 300);
    }, duration);
}

// =========================================================================
// SUBJECT COMMON FORMS JS ENGINE (MH, AE, CM)
// =========================================================================
const COMMON_FORM_TYPE = '<?php echo strtoupper($current_common_form); ?>';
const CURRENT_SUBJECT_ID = <?php echo (int)$subject_id; ?>;
const CURRENT_SUBJECT_CODE = '<?php echo htmlspecialchars($subject['subject_code']); ?>';
const USER_IS_COORDINATOR = <?php echo $is_coordinator ? 'true' : 'false'; ?>;
const USER_IS_MANAGER = <?php echo $is_manager_role ? 'true' : 'false'; ?>;
const USER_IS_ADMIN = <?php echo $is_admin ? 'true' : 'false'; ?>;
const USER_IS_MONITOR = <?php echo $is_monitor_role ? 'true' : 'false'; ?>;
const USER_IS_PI = <?php echo $is_pi_role ? 'true' : 'false'; ?>;
const USER_CAN_EDIT_CONTENT = <?php echo $is_data_editor ? 'true' : 'false'; ?>;

let commonSearchTimer = null;
let currentEditingRecordData = null;
let currentLinkableRecords = [];

document.addEventListener('DOMContentLoaded', function() {
    ['MH', 'AE', 'CM', 'TA', 'USV'].forEach(ft => {
        loadCommonRecords(ft);
    });
});

function debounceCommonSearch() {
    clearTimeout(commonSearchTimer);
    commonSearchTimer = setTimeout(() => {
        loadCommonRecords();
    }, 300);
}

function loadCommonRecords(targetFormType = null) {
    const typeToLoad = (targetFormType || COMMON_FORM_TYPE || '').toUpperCase();
    if (!typeToLoad) return;

    const search = document.getElementById('commonSearchInput') ? document.getElementById('commonSearchInput').value : '';
    const status = document.getElementById('commonStatusFilter') ? document.getElementById('commonStatusFilter').value : '';
    const sdrStatus = document.getElementById('commonSdrFilter') ? document.getElementById('commonSdrFilter').value : '';
    const includeVoided = document.getElementById('commonIncludeVoided') ? document.getElementById('commonIncludeVoided').checked : false;

    const fd = new FormData();
    fd.append('action', 'get_records');
    fd.append('subject_id', CURRENT_SUBJECT_ID);
    fd.append('form_type', typeToLoad);
    fd.append('search', search);
    fd.append('status', status);
    fd.append('sdr_status', sdrStatus);
    fd.append('include_voided', includeVoided ? 'true' : 'false');

    fetch('ajax_common_forms.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
        if (!res.success) {
            showToast(res.message || 'Error loading records', 'error');
            return;
        }

        // Update Record Count Badges
        const cntBadge = document.getElementById('commonFormRecordCountBadge');
        if (cntBadge && (typeToLoad === COMMON_FORM_TYPE)) cntBadge.textContent = res.records.length;

        const sbBadge = document.getElementById('sidebar-count-' + typeToLoad.toLowerCase());
        if (sbBadge && res.summary) sbBadge.textContent = res.summary.total;



        if (typeToLoad === COMMON_FORM_TYPE) {
            renderCommonTable(res.records, typeToLoad);
        }
    })
    .catch(err => {
        console.error(err);
        showToast('Network error loading common records', 'error');
    });
}

function renderCommonTable(records, formType) {
    const thead = document.getElementById('commonTableHead');
    const tbody = document.getElementById('commonTableBody');
    if (!thead || !tbody) return;

    if (formType === 'MH') {
        thead.innerHTML = `
            <th style="padding: 0.85rem 1rem; text-align: left; white-space: nowrap;">MH Number</th>
            <th style="padding: 0.85rem 1rem; text-align: left;">Condition / Term</th>
            <th style="padding: 0.85rem 1rem; text-align: center; white-space: nowrap;">Ongoing</th>
            <th style="padding: 0.85rem 1rem; text-align: left; white-space: nowrap;">Start Date</th>
            <th style="padding: 0.85rem 1rem; text-align: left; white-space: nowrap;">End Date</th>
            <th style="padding: 0.85rem 1rem; text-align: center; white-space: nowrap;">Medication Given</th>
            <th style="padding: 0.85rem 1rem; text-align: center; white-space: nowrap;">Status</th>
            <th style="padding: 0.85rem 1rem; text-align: center; white-space: nowrap;">SDR</th>
            <th style="padding: 0.85rem 1rem; text-align: center; white-space: nowrap;">Queries</th>
            <th style="padding: 0.85rem 1rem; text-align: right; white-space: nowrap;">Actions</th>
        `;
    } else if (formType === 'AE') {
        thead.innerHTML = `
            <th style="padding: 0.85rem 1rem; text-align: left; white-space: nowrap;">AE Number</th>
            <th style="padding: 0.85rem 1rem; text-align: left;">AE Term / Verbatim</th>
            <th style="padding: 0.85rem 1rem; text-align: left; white-space: nowrap;">Start Date</th>
            <th style="padding: 0.85rem 1rem; text-align: left; white-space: nowrap;">End Date</th>
            <th style="padding: 0.85rem 1rem; text-align: center; white-space: nowrap;">Severity</th>
            <th style="padding: 0.85rem 1rem; text-align: center; white-space: nowrap;">Seriousness</th>
            <th style="padding: 0.85rem 1rem; text-align: left; white-space: nowrap;">Outcome</th>
            <th style="padding: 0.85rem 1rem; text-align: center; white-space: nowrap;">Status</th>
            <th style="padding: 0.85rem 1rem; text-align: center; white-space: nowrap;">SDR</th>
            <th style="padding: 0.85rem 1rem; text-align: center; white-space: nowrap;">Queries</th>
            <th style="padding: 0.85rem 1rem; text-align: right; white-space: nowrap;">Actions</th>
        `;
    } else if (formType === 'CM') {
        thead.innerHTML = `
            <th style="padding: 0.85rem 1rem; text-align: left; white-space: nowrap;">CM Number</th>
            <th style="padding: 0.85rem 1rem; text-align: left;">Medication Name</th>
            <th style="padding: 0.85rem 1rem; text-align: left; white-space: nowrap;">Start Date</th>
            <th style="padding: 0.85rem 1rem; text-align: left; white-space: nowrap;">Stop Date</th>
            <th style="padding: 0.85rem 1rem; text-align: center; white-space: nowrap;">Taken For</th>
            <th style="padding: 0.85rem 1rem; text-align: left;">Linked Conditions / Events</th>
            <th style="padding: 0.85rem 1rem; text-align: center; white-space: nowrap;">Status</th>
            <th style="padding: 0.85rem 1rem; text-align: center; white-space: nowrap;">SDR</th>
            <th style="padding: 0.85rem 1rem; text-align: center; white-space: nowrap;">Queries</th>
            <th style="padding: 0.85rem 1rem; text-align: right; white-space: nowrap;">Actions</th>
        `;
    } else if (formType === 'TA') {
        thead.innerHTML = `
            <th style="padding: 0.85rem 1rem; text-align: left; white-space: nowrap;">TA Number</th>
            <th style="padding: 0.85rem 1rem; text-align: left; white-space: nowrap;">Time Point</th>
            <th style="padding: 0.85rem 1rem; text-align: left; white-space: nowrap;">Imaging Date</th>
            <th style="padding: 0.85rem 1rem; text-align: left; white-space: nowrap;">Modality</th>
            <th style="padding: 0.85rem 1rem; text-align: center; white-space: nowrap;">Target Lesions</th>
            <th style="padding: 0.85rem 1rem; text-align: center; white-space: nowrap;">Sum LD (mm)</th>
            <th style="padding: 0.85rem 1rem; text-align: center; white-space: nowrap;">Overall Response</th>
            <th style="padding: 0.85rem 1rem; text-align: center; white-space: nowrap;">Status</th>
            <th style="padding: 0.85rem 1rem; text-align: center; white-space: nowrap;">SDR</th>
            <th style="padding: 0.85rem 1rem; text-align: center; white-space: nowrap;">Queries</th>
            <th style="padding: 0.85rem 1rem; text-align: right; white-space: nowrap;">Actions</th>
        `;
    } else if (formType === 'USV') {
        thead.innerHTML = `
            <th style="padding: 0.85rem 1rem; text-align: left; white-space: nowrap;">USV Number</th>
            <th style="padding: 0.85rem 1rem; text-align: center; white-space: nowrap;">Visit Performed</th>
            <th style="padding: 0.85rem 1rem; text-align: left; white-space: nowrap;">Date of Visit</th>
            <th style="padding: 0.85rem 1rem; text-align: left;">Reason for Unscheduled Visit</th>
            <th style="padding: 0.85rem 1rem; text-align: center; white-space: nowrap;">Vitals</th>
            <th style="padding: 0.85rem 1rem; text-align: center; white-space: nowrap;">Labs</th>
            <th style="padding: 0.85rem 1rem; text-align: center; white-space: nowrap;">Drug Admin</th>
            <th style="padding: 0.85rem 1rem; text-align: center; white-space: nowrap;">Status</th>
            <th style="padding: 0.85rem 1rem; text-align: center; white-space: nowrap;">SDR</th>
            <th style="padding: 0.85rem 1rem; text-align: center; white-space: nowrap;">Queries</th>
            <th style="padding: 0.85rem 1rem; text-align: right; white-space: nowrap;">Actions</th>
        `;
    }

    if (records.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="11" style="text-align: center; padding: 3.5rem; color: #94a3b8;">
                    <span class="material-icons-round" style="font-size: 3rem; color: #cbd5e1; display: block; margin-bottom: 0.75rem;">playlist_remove</span>
                    <p style="font-size: 1rem; font-weight: 500; color: #475569; margin: 0 0 0.5rem 0;">No ${formType} records found for this subject.</p>
                    ${USER_CAN_EDIT_CONTENT ? `<button class="btn btn-sm btn-primary" onclick="openCommonFormModal(0, '${formType}')" style="margin-top: 0.5rem;">+ Add ${formType} Form</button>` : ''}
                </td>
            </tr>
        `;
        return;
    }

    let html = '';
    records.forEach(r => {
        const d = r.data || {};
        const isVoided = r.is_voided;
        const rowStyle = isVoided ? 'background: #fef2f2; opacity: 0.65;' : 'border-bottom: 1px solid #e2e8f0;';

        // Status Badge
        let statusBadge = `<span style="background: #eff6ff; color: #1d4ed8; padding: 2px 8px; border-radius: 99px; font-size: 0.75rem; font-weight: 600; white-space: nowrap;">Draft</span>`;
        if (r.status === 'complete') {
            statusBadge = `<span style="background: #ecfdf5; color: #047857; padding: 2px 8px; border-radius: 99px; font-size: 0.75rem; font-weight: 600; white-space: nowrap;">Complete</span>`;
        }

        // SDR & Review Badge
        const monRev = (r.monitor_reviewed === true || r.monitor_reviewed === 't' || r.monitor_reviewed === 1 || r.monitor_reviewed === '1');
        const mgrRev = (r.manager_reviewed === true || r.manager_reviewed === 't' || r.manager_reviewed === 1 || r.manager_reviewed === '1');
        const sdrSub = (r.sdr_submitted === true || r.sdr_submitted === 't' || r.sdr_submitted === 1 || r.sdr_submitted === '1');
        const piSigned = (r.pi_signed_off === true || r.pi_signed_off === 't' || r.pi_signed_off === 1 || r.pi_signed_off === '1');

        let sdrBadge = `<span style="background: #f1f5f9; color: #64748b; padding: 2px 8px; border-radius: 99px; font-size: 0.75rem; font-weight: 500; white-space: nowrap;">Pending SDR</span>`;
        if (piSigned || r.sdr_status === 'pi_signed_off') {
            sdrBadge = `<span style="background: #e0e7ff; color: #4338ca; padding: 2px 8px; border-radius: 99px; font-size: 0.75rem; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; white-space: nowrap;"><span class="material-icons-round" style="font-size: 14px;">verified_user</span> PI Signed Off</span>`;
        } else if ((monRev && mgrRev) || r.sdr_status === 'sdved_and_dm_reviewed') {
            sdrBadge = `<span style="background: #f0fdf4; color: #0d8e6f; padding: 2px 8px; border-radius: 99px; font-size: 0.75rem; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; white-space: nowrap;"><span class="material-icons-round" style="font-size: 14px;">verified</span> SDVed & DM Reviewed</span>`;
        } else if (mgrRev && !monRev) {
            sdrBadge = `<span style="background: #f5f3ff; color: #6d28d9; padding: 2px 8px; border-radius: 99px; font-size: 0.75rem; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; white-space: nowrap;"><span class="material-icons-round" style="font-size: 14px;">verified</span> DM Reviewed</span>`;
        } else if (monRev && !mgrRev) {
            sdrBadge = `<span style="background: #ecfdf5; color: #047857; padding: 2px 8px; border-radius: 99px; font-size: 0.75rem; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; white-space: nowrap;"><span class="material-icons-round" style="font-size: 14px;">check_circle</span> SDVed</span>`;
        } else if (r.sdr_status === 'dm_reviewed') {
            sdrBadge = `<span style="background: #f5f3ff; color: #6d28d9; padding: 2px 8px; border-radius: 99px; font-size: 0.75rem; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; white-space: nowrap;"><span class="material-icons-round" style="font-size: 14px;">verified</span> DM Reviewed</span>`;
        } else if (r.sdr_status === 'sdved' || r.sdr_status === 'reviewed') {
            sdrBadge = `<span style="background: #ecfdf5; color: #047857; padding: 2px 8px; border-radius: 99px; font-size: 0.75rem; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; white-space: nowrap;"><span class="material-icons-round" style="font-size: 14px;">check_circle</span> SDVed</span>`;
        } else if (r.sdr_status === 'under_review' || sdrSub) {
            sdrBadge = `<span style="background: #fef3c7; color: #b45309; padding: 2px 8px; border-radius: 99px; font-size: 0.75rem; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; white-space: nowrap;"><span class="material-icons-round" style="font-size: 13px;">pending_actions</span> Under Review</span>`;
        } else if (r.sdr_status === 'needs_rereview') {
            sdrBadge = `<span style="background: #fff7ed; color: #c2410c; padding: 2px 8px; border-radius: 99px; font-size: 0.75rem; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; white-space: nowrap;"><span class="material-icons-round" style="font-size: 13px;">published_with_changes</span> Re-review</span>`;
        }

        // Query Badge
        let queryBadge = `<span style="color: #94a3b8;">-</span>`;
        if (r.open_queries > 0) {
            queryBadge = `<span style="background: #ef4444; color: white; padding: 2px 7px; border-radius: 99px; font-size: 0.75rem; font-weight: 700; white-space: nowrap;">${r.open_queries}</span>`;
        }

        if (formType === 'MH') {
            let relCmHtml = '';
            if (r.related_cms && r.related_cms.length > 0) {
                relCmHtml = `<div style="margin-top: 4px; display: flex; gap: 4px; flex-wrap: wrap;">`;
                r.related_cms.forEach(cm => {
                    relCmHtml += `<span onclick="event.stopPropagation(); openCommonFormModal(${cm.id}, 'CM')" class="badge" style="background: #ecfdf5; color: #047857; cursor: pointer; font-size: 0.7rem; border: 1px solid #a7f3d0; white-space: nowrap;" title="Linked CM: ${escapeHtml(cm.medication_name)}"><span class="material-icons-round" style="font-size: 11px;">medication</span> ${escapeHtml(cm.record_number)}</span>`;
                });
                relCmHtml += `</div>`;
            }

            html += `
                <tr style="${rowStyle}">
                    <td style="padding: 0.85rem 1rem; font-weight: 700; color: #1e293b; vertical-align: middle; white-space: nowrap;">
                        ${escapeHtml(r.record_number)}
                        ${isVoided ? '<span style="background: #dc2626; color: white; font-size: 0.65rem; padding: 1px 5px; border-radius: 4px; margin-left: 4px;">VOIDED</span>' : ''}
                    </td>
                    <td style="padding: 0.85rem 1rem; color: #0f172a; font-weight: 600; vertical-align: middle;">
                        ${escapeHtml(d.mh_term || 'Untitled')}
                        ${relCmHtml}
                    </td>
                    <td style="padding: 0.85rem 1rem; text-align: center; vertical-align: middle; white-space: nowrap;">
                        <span style="font-weight: 600; color: ${d.ongoing === 'Yes' ? '#2563eb' : '#64748b'};">${escapeHtml(d.ongoing || 'No')}</span>
                    </td>
                    <td style="padding: 0.85rem 1rem; color: #475569; vertical-align: middle; white-space: nowrap;">${escapeHtml(d.start_date || '-')}</td>
                    <td style="padding: 0.85rem 1rem; color: #475569; vertical-align: middle; white-space: nowrap;">${escapeHtml(d.end_date || '-')}</td>
                    <td style="padding: 0.85rem 1rem; text-align: center; vertical-align: middle; white-space: nowrap;">${escapeHtml(d.medication_given || 'No')}</td>
                    <td style="padding: 0.85rem 1rem; text-align: center; vertical-align: middle; white-space: nowrap;">${statusBadge}</td>
                    <td style="padding: 0.85rem 1rem; text-align: center; vertical-align: middle; white-space: nowrap;">${sdrBadge}</td>
                    <td style="padding: 0.85rem 1rem; text-align: center; vertical-align: middle; white-space: nowrap;">${queryBadge}</td>
                    <td style="padding: 0.85rem 1rem; text-align: right; vertical-align: middle; white-space: nowrap;">
                        <div style="display: inline-flex; align-items: center; justify-content: flex-end; gap: 4px; white-space: nowrap;">
                            ${renderRecordActionButtons(r, 'MH')}
                        </div>
                    </td>
                </tr>
            `;
        } else if (formType === 'AE') {
            let relCmHtml = '';
            if (r.related_cms && r.related_cms.length > 0) {
                relCmHtml = `<div style="margin-top: 4px; display: flex; gap: 4px; flex-wrap: wrap;">`;
                r.related_cms.forEach(cm => {
                    relCmHtml += `<span onclick="event.stopPropagation(); openCommonFormModal(${cm.id}, 'CM')" class="badge" style="background: #ecfdf5; color: #047857; cursor: pointer; font-size: 0.7rem; border: 1px solid #a7f3d0; white-space: nowrap;" title="Linked CM: ${escapeHtml(cm.medication_name)}"><span class="material-icons-round" style="font-size: 11px;">medication</span> ${escapeHtml(cm.record_number)}</span>`;
                });
                relCmHtml += `</div>`;
            }

            let sevBg = '#f1f5f9'; let sevColor = '#475569';
            if (d.severity === 'Severe') { sevBg = '#fef2f2'; sevColor = '#dc2626'; }
            else if (d.severity === 'Moderate') { sevBg = '#fff7ed'; sevColor = '#c2410c'; }

            html += `
                <tr style="${rowStyle}">
                    <td style="padding: 0.85rem 1rem; font-weight: 700; color: #1e293b; vertical-align: middle; white-space: nowrap;">
                        ${escapeHtml(r.record_number)}
                        ${isVoided ? '<span style="background: #dc2626; color: white; font-size: 0.65rem; padding: 1px 5px; border-radius: 4px; margin-left: 4px;">VOIDED</span>' : ''}
                    </td>
                    <td style="padding: 0.85rem 1rem; color: #0f172a; font-weight: 600; vertical-align: middle;">
                        ${escapeHtml(d.ae_term || 'Untitled')}
                        ${relCmHtml}
                    </td>
                    <td style="padding: 0.85rem 1rem; color: #475569; vertical-align: middle; white-space: nowrap;">${escapeHtml(d.start_date || '-')}</td>
                    <td style="padding: 0.85rem 1rem; color: #475569; vertical-align: middle; white-space: nowrap;">${escapeHtml(d.end_date || '-')}</td>
                    <td style="padding: 0.85rem 1rem; text-align: center; vertical-align: middle; white-space: nowrap;">
                        <span style="background: ${sevBg}; color: ${sevColor}; padding: 2px 8px; border-radius: 99px; font-weight: 600; font-size: 0.75rem; white-space: nowrap;">${escapeHtml(d.severity || '-')}</span>
                    </td>
                    <td style="padding: 0.85rem 1rem; text-align: center; vertical-align: middle; white-space: nowrap;">
                        <span style="font-weight: 700; color: ${d.seriousness === 'Yes' ? '#dc2626' : '#64748b'}; white-space: nowrap;">${escapeHtml(d.seriousness || 'No')}</span>
                    </td>
                    <td style="padding: 0.85rem 1rem; color: #475569; vertical-align: middle;">${escapeHtml(d.outcome || '-')}</td>
                    <td style="padding: 0.85rem 1rem; text-align: center; vertical-align: middle; white-space: nowrap;">${statusBadge}</td>
                    <td style="padding: 0.85rem 1rem; text-align: center; vertical-align: middle; white-space: nowrap;">${sdrBadge}</td>
                    <td style="padding: 0.85rem 1rem; text-align: center; vertical-align: middle; white-space: nowrap;">${queryBadge}</td>
                    <td style="padding: 0.85rem 1rem; text-align: right; vertical-align: middle; white-space: nowrap;">
                        <div style="display: inline-flex; align-items: center; justify-content: flex-end; gap: 4px; white-space: nowrap;">
                            ${renderRecordActionButtons(r, 'AE')}
                        </div>
                    </td>
                </tr>
            `;
        } else if (formType === 'CM') {
            let linksHtml = '<span style="color: #94a3b8;">None</span>';
            if (d.indication_type === 'Other' && d.specify_other) {
                linksHtml = `<span style="color: #475569; font-style: italic;">Other: ${escapeHtml(d.specify_other)}</span>`;
            } else if (r.linked_records && r.linked_records.length > 0) {
                linksHtml = '<div style="display: flex; gap: 4px; flex-wrap: wrap;">';
                r.linked_records.forEach(lk => {
                    const icon = lk.form_type === 'MH' ? 'history_edu' : 'warning_amber';
                    const color = lk.form_type === 'MH' ? '#2563eb' : '#d97706';
                    const bg = lk.form_type === 'MH' ? '#eff6ff' : '#fff7ed';
                    const warningTag = lk.is_voided ? ' <strong style="color: #dc2626;">(Voided)</strong>' : '';
                    linksHtml += `
                        <span onclick="event.stopPropagation(); openCommonFormModal(${lk.id}, '${lk.form_type}')" class="badge" style="background: ${bg}; color: ${color}; cursor: pointer; border: 1px solid #e2e8f0; font-size: 0.725rem; white-space: nowrap;" title="${escapeHtml(lk.term)}">
                            <span class="material-icons-round" style="font-size: 11px;">${icon}</span> ${escapeHtml(lk.record_number)} ${escapeHtml(lk.term)}${warningTag}
                        </span>
                    `;
                });
                linksHtml += '</div>';
            }

            html += `
                <tr style="${rowStyle}">
                    <td style="padding: 0.85rem 1rem; font-weight: 700; color: #1e293b; vertical-align: middle; white-space: nowrap;">
                        ${escapeHtml(r.record_number)}
                        ${isVoided ? '<span style="background: #dc2626; color: white; font-size: 0.65rem; padding: 1px 5px; border-radius: 4px; margin-left: 4px;">VOIDED</span>' : ''}
                    </td>
                    <td style="padding: 0.85rem 1rem; color: #0f172a; font-weight: 600; vertical-align: middle;">${escapeHtml(d.medication_name || 'Untitled')}</td>
                    <td style="padding: 0.85rem 1rem; color: #475569; vertical-align: middle; white-space: nowrap;">${escapeHtml(d.start_date || '-')}</td>
                    <td style="padding: 0.85rem 1rem; color: #475569; vertical-align: middle; white-space: nowrap;">${escapeHtml(d.stop_date || '-')}</td>
                    <td style="padding: 0.85rem 1rem; text-align: center; vertical-align: middle; white-space: nowrap;">
                        <span style="background: #f1f5f9; color: #334155; padding: 2px 8px; border-radius: 6px; font-weight: 600; font-size: 0.75rem; white-space: nowrap;">${escapeHtml(d.indication_type || '-')}</span>
                    </td>
                    <td style="padding: 0.85rem 1rem; vertical-align: middle;">${linksHtml}</td>
                    <td style="padding: 0.85rem 1rem; text-align: center; vertical-align: middle; white-space: nowrap;">${statusBadge}</td>
                    <td style="padding: 0.85rem 1rem; text-align: center; vertical-align: middle; white-space: nowrap;">${sdrBadge}</td>
                    <td style="padding: 0.85rem 1rem; text-align: center; vertical-align: middle; white-space: nowrap;">${queryBadge}</td>
                    <td style="padding: 0.85rem 1rem; text-align: right; vertical-align: middle; white-space: nowrap;">
                        <div style="display: inline-flex; align-items: center; justify-content: flex-end; gap: 4px; white-space: nowrap;">
                            ${renderRecordActionButtons(r, 'CM')}
                        </div>
                    </td>
                </tr>
            `;
        } else if (formType === 'TA') {
            let respBg = '#f1f5f9'; let respColor = '#475569';
            if (d.recist_overall_response && d.recist_overall_response.startsWith('CR')) { respBg = '#ecfdf5'; respColor = '#047857'; }
            else if (d.recist_overall_response && d.recist_overall_response.startsWith('PR')) { respBg = '#eff6ff'; respColor = '#1d4ed8'; }
            else if (d.recist_overall_response && d.recist_overall_response.startsWith('PD')) { respBg = '#fef2f2'; respColor = '#dc2626'; }
            else if (d.recist_overall_response && d.recist_overall_response.startsWith('SD')) { respBg = '#fff7ed'; respColor = '#c2410c'; }

            html += `
                <tr style="${rowStyle}">
                    <td style="padding: 0.85rem 1rem; font-weight: 700; color: #1e293b; vertical-align: middle; white-space: nowrap;">
                        ${escapeHtml(r.record_number)}
                        ${isVoided ? '<span style="background: #dc2626; color: white; font-size: 0.65rem; padding: 1px 5px; border-radius: 4px; margin-left: 4px;">VOIDED</span>' : ''}
                    </td>
                    <td style="padding: 0.85rem 1rem; color: #0f172a; font-weight: 600; vertical-align: middle;">${escapeHtml(d.tumor_timepoint || '-')}</td>
                    <td style="padding: 0.85rem 1rem; color: #475569; vertical-align: middle; white-space: nowrap;">${escapeHtml(d.imaging_date || '-')}</td>
                    <td style="padding: 0.85rem 1rem; color: #475569; vertical-align: middle; white-space: nowrap;">${escapeHtml(d.imaging_modality === 'Other' ? (d.imaging_modality_other || 'Other') : (d.imaging_modality || '-'))}</td>
                    <td style="padding: 0.85rem 1rem; text-align: center; vertical-align: middle; white-space: nowrap;">
                        <span style="font-weight: 600; color: ${d.target_lesions_present === 'Yes' ? '#0f172a' : '#64748b'}; white-space: nowrap;">${escapeHtml(d.target_lesions_present || '-')}</span>
                    </td>
                    <td style="padding: 0.85rem 1rem; text-align: center; font-weight: 600; color: #334155; vertical-align: middle; white-space: nowrap;">${escapeHtml(d.sum_longest_dia || '-')}</td>
                    <td style="padding: 0.85rem 1rem; text-align: center; vertical-align: middle; white-space: nowrap;">
                        <span style="background: ${respBg}; color: ${respColor}; padding: 2px 8px; border-radius: 99px; font-weight: 600; font-size: 0.75rem; white-space: nowrap;">${escapeHtml(d.recist_overall_response || '-')}</span>
                    </td>
                    <td style="padding: 0.85rem 1rem; text-align: center; vertical-align: middle; white-space: nowrap;">${statusBadge}</td>
                    <td style="padding: 0.85rem 1rem; text-align: center; vertical-align: middle; white-space: nowrap;">${sdrBadge}</td>
                    <td style="padding: 0.85rem 1rem; text-align: center; vertical-align: middle; white-space: nowrap;">${queryBadge}</td>
                    <td style="padding: 0.85rem 1rem; text-align: right; vertical-align: middle; white-space: nowrap;">
                        <div style="display: inline-flex; align-items: center; justify-content: flex-end; gap: 4px; white-space: nowrap;">
                            ${renderRecordActionButtons(r, 'TA')}
                        </div>
                    </td>
                </tr>
            `;
        } else if (formType === 'USV') {
            const isVisitYes = (d.visit_performed === 'Yes');
            const perfBadge = isVisitYes 
                ? '<span style="background: #ecfdf5; color: #047857; padding: 2px 8px; border-radius: 99px; font-weight: 600; font-size: 0.75rem;">Yes</span>'
                : '<span style="background: #fef2f2; color: #dc2626; padding: 2px 8px; border-radius: 99px; font-weight: 600; font-size: 0.75rem;">No</span>';
            const reasonStr = d.visit_reason_unscheduled ? (d.visit_reason_unscheduled === 'Other' ? (d.unscheduled_reason_other || 'Other') : d.visit_reason_unscheduled) : (d.visit_not_performed_reason || '-');
            const vitalsStr = d.vitals_assessed || '-';
            const labsStr = (d.hema_panel_collected === 'Yes' || d.chem_panel_collected === 'Yes') ? 'Yes' : (d.hema_panel_collected === 'No' && d.chem_panel_collected === 'No' ? 'No' : '-');
            const drugStr = d.drug_administered || '-';

            html += `
                <tr style="${rowStyle}">
                    <td style="padding: 0.85rem 1rem; font-weight: 700; color: #1e293b; vertical-align: middle; white-space: nowrap;">
                        ${escapeHtml(r.record_number)}
                        ${isVoided ? '<span style="background: #dc2626; color: white; font-size: 0.65rem; padding: 1px 5px; border-radius: 4px; margin-left: 4px;">VOIDED</span>' : ''}
                    </td>
                    <td style="padding: 0.85rem 1rem; text-align: center; vertical-align: middle; white-space: nowrap;">${perfBadge}</td>
                    <td style="padding: 0.85rem 1rem; color: #0f172a; font-weight: 600; vertical-align: middle; white-space: nowrap;">${escapeHtml(d.date_of_visit || '-')}</td>
                    <td style="padding: 0.85rem 1rem; color: #475569; vertical-align: middle;">${escapeHtml(reasonStr)}</td>
                    <td style="padding: 0.85rem 1rem; text-align: center; vertical-align: middle; white-space: nowrap;">
                        <span style="font-weight: 600; color: ${vitalsStr === 'Yes' ? '#047857' : '#64748b'};">${escapeHtml(vitalsStr)}</span>
                    </td>
                    <td style="padding: 0.85rem 1rem; text-align: center; vertical-align: middle; white-space: nowrap;">
                        <span style="font-weight: 600; color: ${labsStr === 'Yes' ? '#047857' : '#64748b'};">${escapeHtml(labsStr)}</span>
                    </td>
                    <td style="padding: 0.85rem 1rem; text-align: center; vertical-align: middle; white-space: nowrap;">
                        <span style="font-weight: 600; color: ${drugStr === 'Yes' ? '#047857' : '#64748b'};">${escapeHtml(drugStr)}</span>
                    </td>
                    <td style="padding: 0.85rem 1rem; text-align: center; vertical-align: middle; white-space: nowrap;">${statusBadge}</td>
                    <td style="padding: 0.85rem 1rem; text-align: center; vertical-align: middle; white-space: nowrap;">${sdrBadge}</td>
                    <td style="padding: 0.85rem 1rem; text-align: center; vertical-align: middle; white-space: nowrap;">${queryBadge}</td>
                    <td style="padding: 0.85rem 1rem; text-align: right; vertical-align: middle; white-space: nowrap;">
                        <div style="display: inline-flex; align-items: center; justify-content: flex-end; gap: 4px; white-space: nowrap;">
                            ${renderRecordActionButtons(r, 'USV')}
                        </div>
                    </td>
                </tr>
            `;
        }
    });

    tbody.innerHTML = html;
}

function renderRecordActionButtons(r, formType) {
    let btns = '';

    const sdrSub = (r.sdr_submitted === true || r.sdr_submitted === 't' || r.sdr_submitted === 1 || r.sdr_submitted === '1');
    const monRev = (r.monitor_reviewed === true || r.monitor_reviewed === 't' || r.monitor_reviewed === 1 || r.monitor_reviewed === '1');
    const mgrRev = (r.manager_reviewed === true || r.manager_reviewed === 't' || r.manager_reviewed === 1 || r.manager_reviewed === '1');
    const piSigned = (r.pi_signed_off === true || r.pi_signed_off === 't' || r.pi_signed_off === 1 || r.pi_signed_off === '1');
    const isLocked = (sdrSub && (monRev || mgrRev)) || piSigned;

    // Open/Edit/View
    if (USER_CAN_EDIT_CONTENT && !sdrSub && !piSigned) {
        btns += `<button class="btn btn-sm btn-outline" onclick="openCommonFormModal(${r.id}, '${formType}')" title="Edit Record" style="padding: 0.25rem 0.6rem; font-size: 0.75rem; display: inline-flex; align-items: center; gap: 3px;"><span class="material-icons-round" style="font-size: 13px;">edit</span> Edit</button>`;
    } else {
        btns += `<button class="btn btn-sm btn-outline" onclick="openCommonFormModal(${r.id}, '${formType}')" title="${isLocked ? 'Record is locked' : 'View Record'}" style="padding: 0.25rem 0.6rem; font-size: 0.75rem; display: inline-flex; align-items: center; gap: 3px;"><span class="material-icons-round" style="font-size: 13px;">visibility</span> View</button>`;
    }

    // Queries
    const queryColor = (r.open_queries && r.open_queries > 0) ? '#dc2626' : '#475569';
    const queryBadge = (r.open_queries && r.open_queries > 0) ? `<span style="background: #ef4444; color: white; border-radius: 99px; padding: 1px 5px; font-size: 0.65rem; margin-left: 3px; font-weight: 700;">${r.open_queries}</span>` : '';
    btns += `<button class="btn btn-sm btn-outline" onclick="openCommonQueryModal(${r.id})" title="Queries" style="padding: 0.25rem 0.5rem; font-size: 0.75rem; color: ${queryColor}; display: inline-flex; align-items: center;"><span class="material-icons-round" style="font-size: 14px;">help_outline</span>${queryBadge}</button>`;

    // Audit Trail
    btns += `<button class="btn btn-sm btn-outline" onclick="openCommonAuditModal(${r.id})" title="Audit Trail" style="padding: 0.25rem 0.5rem; font-size: 0.75rem; color: #475569; display: inline-flex; align-items: center;"><span class="material-icons-round" style="font-size: 14px;">history</span></button>`;

    // SDR & Review Workflow Actions (Strict 3-Role Governance)
    if (!r.is_voided) {
        if (piSigned) {
            // Once PI Signed Off, NO ONE can revoke except PI or Admin revoking PI Sign Off!
            if (USER_IS_PI || USER_IS_ADMIN) {
                btns += `<button class="btn btn-sm btn-outline" onclick="triggerCommonReviewAction(${r.id}, 'pi_revoke', '${formType}')" title="Revoke PI Sign Off" style="padding: 0.25rem 0.55rem; font-size: 0.75rem; color: #dc2626; border: 1px solid #dc2626; font-weight: 600; display: inline-flex; align-items: center; gap: 3px;"><span class="material-icons-round" style="font-size: 14px;">undo</span> Revoke PI Sign Off</button>`;
            }
        } else {
            // Data Coordinator Actions
            if (USER_IS_COORDINATOR) {
                if (!sdrSub) {
                    if (r.status === 'complete') {
                        btns += `<button class="btn btn-sm" onclick="triggerCommonReviewAction(${r.id}, 'mark_sdr', '${formType}')" title="Mark as SDR (Submit for Review)" style="padding: 0.25rem 0.55rem; font-size: 0.75rem; background: #1d6f97; color: #fff; border: 1px solid #1d6f97; font-weight: 600; display: inline-flex; align-items: center; gap: 3px;"><span class="material-icons-round" style="font-size: 14px;">lock</span> Mark SDR</button>`;
                    } else {
                        btns += `<button class="btn btn-sm" onclick="showToast('Cannot mark as SDR: Record must be marked complete first.', 'warning')" title="Record must be complete before SDR submission" style="padding: 0.25rem 0.55rem; font-size: 0.75rem; background: #f1f5f9; color: #94a3b8; border: 1px solid #cbd5e1; cursor: not-allowed; display: inline-flex; align-items: center; gap: 3px;"><span class="material-icons-round" style="font-size: 14px;">lock</span> Mark SDR</button>`;
                    }
                } else if (sdrSub && !monRev && !mgrRev) {
                    btns += `<button class="btn btn-sm btn-outline" onclick="triggerCommonReviewAction(${r.id}, 'revoke_sdr', '${formType}')" title="Revoke SDR Submission" style="padding: 0.25rem 0.55rem; font-size: 0.75rem; color: #dc2626; border: 1px solid #dc2626; font-weight: 600; display: inline-flex; align-items: center; gap: 3px;"><span class="material-icons-round" style="font-size: 14px;">lock_open</span> Revoke SDR</button>`;
                }
            }

            // Data Monitor Actions
            if ((USER_IS_MONITOR || USER_IS_ADMIN) && sdrSub) {
                if (!monRev) {
                    btns += `<button class="btn btn-sm" onclick="triggerCommonReviewAction(${r.id}, 'monitor_review', '${formType}')" title="Mark as SDVed" style="padding: 0.25rem 0.55rem; font-size: 0.75rem; background: #059669; color: #fff; border: 1px solid #059669; font-weight: 600; display: inline-flex; align-items: center; gap: 3px;"><span class="material-icons-round" style="font-size: 14px;">verified</span> SDVed</button>`;
                } else {
                    btns += `<button class="btn btn-sm btn-outline" onclick="triggerCommonReviewAction(${r.id}, 'monitor_revoke', '${formType}')" title="Revoke SDVed" style="padding: 0.25rem 0.55rem; font-size: 0.75rem; color: #dc2626; border: 1px solid #dc2626; font-weight: 600; display: inline-flex; align-items: center; gap: 3px;"><span class="material-icons-round" style="font-size: 14px;">undo</span> Revoke SDV</button>`;
                }
            }

            // Data Manager Actions
            if ((USER_IS_MANAGER || USER_IS_ADMIN) && sdrSub) {
                if (!mgrRev) {
                    btns += `<button class="btn btn-sm" onclick="triggerCommonReviewAction(${r.id}, 'manager_review', '${formType}')" title="Mark as DM Reviewed" style="padding: 0.25rem 0.55rem; font-size: 0.75rem; background: #6d28d9; color: #fff; border: 1px solid #6d28d9; font-weight: 600; display: inline-flex; align-items: center; gap: 3px;"><span class="material-icons-round" style="font-size: 14px;">verified</span> DM Review</button>`;
                } else {
                    btns += `<button class="btn btn-sm btn-outline" onclick="triggerCommonReviewAction(${r.id}, 'manager_revoke', '${formType}')" title="Revoke DM Reviewed" style="padding: 0.25rem 0.55rem; font-size: 0.75rem; color: #dc2626; border: 1px solid #dc2626; font-weight: 600; display: inline-flex; align-items: center; gap: 3px;"><span class="material-icons-round" style="font-size: 14px;">undo</span> Revoke DM</button>`;
                }
            }

            // Principal Investigator Actions (Requires BOTH SDVed & DM Reviewed)
            if ((USER_IS_PI || USER_IS_ADMIN) && monRev && mgrRev) {
                btns += `<button class="btn btn-sm" onclick="triggerCommonReviewAction(${r.id}, 'pi_sign_off', '${formType}')" title="PI Sign Off" style="padding: 0.25rem 0.55rem; font-size: 0.75rem; background: #4338ca; color: #fff; border: 1px solid #4338ca; font-weight: 600; display: inline-flex; align-items: center; gap: 3px;"><span class="material-icons-round" style="font-size: 14px;">verified_user</span> PI Sign Off</button>`;
            }
        }
    }

    // Void (Coordinator / Admin - only if record is active and not locked)
    if ((USER_IS_COORDINATOR || USER_IS_ADMIN) && !r.is_voided && !isLocked && !piSigned) {
        btns += `<button class="btn btn-sm" onclick="openCommonVoidModal(${r.id})" title="Void Record" style="padding: 0.25rem 0.5rem; font-size: 0.75rem; background: #fef2f2; color: #dc2626; border: 1px solid #fca5a5; display: inline-flex; align-items: center;"><span class="material-icons-round" style="font-size: 14px;">remove_circle_outline</span></button>`;
    }

    return btns;
}

// =========================================================================
// FORM EDITOR MODAL & DYNAMIC FIELDS
// =========================================================================
function openCommonFormModal(recordId, formType, defaultData = null) {
    if (recordId === 0 && !USER_CAN_EDIT_CONTENT) {
        showToast('Data Managers and Data Monitors cannot create new clinical records.', 'warning');
        return;
    }

    if (!formType && typeof COMMON_FORM_TYPE !== 'undefined') {
        formType = COMMON_FORM_TYPE;
    }
    if (formType) {
        formType = String(formType).toUpperCase();
    }

    document.getElementById('cfRecordId').value = recordId;
    document.getElementById('cfFormType').value = formType;
    document.getElementById('cfValidationAlert').style.display = 'none';

    const formNames = {
        'MH': 'Medical History (MH)',
        'AE': 'Adverse Events (AE)',
        'CM': 'Concomitant Medications (CM)',
        'TA': 'Tumor Assessment (TA)',
        'USV': 'Unscheduled Visits (USV)'
    };
    const formNameStr = formNames[formType] || (formType + ' Form');
    const subTitleEl = document.getElementById('cfEditorSubTitle');
    if (subTitleEl) {
        subTitleEl.innerHTML = `SUBJECT <strong>${escapeHtml(CURRENT_SUBJECT_CODE)}</strong> &bull; ${escapeHtml(formNameStr.toUpperCase())}`;
    }

    if (recordId > 0) {
        document.getElementById('cfEditorTitle').innerHTML = `Loading Record...`;
        
        const fd = new FormData();
        fd.append('action', 'get_record');
        fd.append('record_id', recordId);

        fetch('ajax_common_forms.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (!res.success) {
                showToast(res.message || 'Failed to load record', 'error');
                return;
            }
            currentEditingRecordData = res.record;
            setupEditorForm(res.record, formType);
            openModal('modalCommonFormEditor');
        })
        .catch(err => {
            console.error(err);
            showToast('Error fetching record details', 'error');
        });
    } else {
        // New Record
        currentEditingRecordData = {
            id: 0,
            record_number: `New ${formType} Record`,
            status: 'draft',
            sdr_status: 'pending',
            revision: 1,
            data: defaultData || {}
        };
        setupEditorForm(currentEditingRecordData, formType);
        openModal('modalCommonFormEditor');
    }
}

function renderCommonFieldHeader(rec, fieldKey, fieldLabel, isRequired = false) {
    if (!rec || !rec.id || rec.id === 0) {
        if (!fieldLabel) return '';
        return `
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.35rem;">
                <label style="font-weight: 600; font-size: 0.875rem; color: #1e293b; margin: 0;">
                    ${fieldLabel} ${isRequired ? '<span style="color: #dc2626;">*</span>' : ''}
                </label>
            </div>
        `;
    }

    const queries = (rec.queries || []).filter(q => q.field_name === fieldKey);
    const openQueries = queries.filter(q => q.status === 'open');
    const answeredQueries = queries.filter(q => q.status === 'answered');
    const closedQueries = queries.filter(q => q.status === 'closed');
    const activeCount = openQueries.length + answeredQueries.length;

    let iconColor = '#94a3b8';
    let borderColor = '#e2e8f0';
    let bgHover = '#f8fafc';
    let tooltip = `Raise / View query on ${fieldLabel || fieldKey}`;
    let badgeHtml = '';

    if (openQueries.length > 0) {
        iconColor = '#dc2626';
        borderColor = '#fca5a5';
        bgHover = '#fef2f2';
        tooltip = `${openQueries.length} Open Query on ${fieldLabel || fieldKey}`;
        badgeHtml = `<span style="background: #dc2626; color: white; padding: 0px 5px; border-radius: 99px; font-size: 0.65rem; font-weight: 700; margin-left: 2px;">${activeCount}</span>`;
    } else if (answeredQueries.length > 0) {
        iconColor = '#ea580c';
        borderColor = '#fdba74';
        bgHover = '#fff7ed';
        tooltip = `${answeredQueries.length} Answered Query on ${fieldLabel || fieldKey}`;
        badgeHtml = `<span style="background: #ea580c; color: white; padding: 0px 5px; border-radius: 99px; font-size: 0.65rem; font-weight: 700; margin-left: 2px;">${activeCount}</span>`;
    } else if (closedQueries.length > 0) {
        iconColor = '#059669';
        borderColor = '#a7f3d0';
        bgHover = '#ecfdf5';
        tooltip = `View Closed Queries for ${fieldLabel || fieldKey}`;
    }

    const recPiSigned = (rec && (rec.pi_signed_off === true || rec.pi_signed_off === 't' || rec.pi_signed_off === 1 || rec.pi_signed_off === '1'));
    if (recPiSigned && activeCount === 0 && closedQueries.length === 0) {
        tooltip = `Cannot Raise Query as the record is Signed Off by Principal Investigator`;
    }

    const escapedLabel = (fieldLabel || fieldKey).replace(/<[^>]*>?/gm, '').trim();
    const cleanEscapedLabel = escapeHtml(escapedLabel).replace(/'/g, "\\'");

    const labelHtml = fieldLabel ? `
        <label style="font-weight: 600; font-size: 0.875rem; color: #1e293b; margin: 0;">
            ${fieldLabel} ${isRequired ? '<span style="color: #dc2626;">*</span>' : ''}
        </label>
    ` : '';

    return `
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.35rem;">
            ${labelHtml}
            <button type="button" class="btn-icon-sm btn-cf-field-query" title="${tooltip}" onclick="event.stopPropagation(); openCommonQueryModal(${rec.id}, '${fieldKey}', '${cleanEscapedLabel}')" style="background: white; border: 1px solid ${borderColor}; border-radius: 4px; padding: 1px 6px; display: inline-flex; align-items: center; gap: 3px; cursor: pointer; transition: all 0.15s ease;" onmouseover="this.style.background='${bgHover}'" onmouseout="this.style.background='white'">
                <span class="material-icons-round" style="color: ${iconColor}; font-size: 1rem; vertical-align: middle;">help_outline</span>
                ${badgeHtml}
            </button>
        </div>
    `;
}

function setupEditorForm(rec, formType) {
    const isNew = rec.id === 0;

    let iconHtml = '';
    if (formType === 'MH') iconHtml = '<span class="material-icons-round" style="color: #2563eb; font-size: 1.35rem;">history_edu</span>';
    else if (formType === 'AE') iconHtml = '<span class="material-icons-round" style="color: #d97706; font-size: 1.35rem;">warning_amber</span>';
    else if (formType === 'CM') iconHtml = '<span class="material-icons-round" style="color: #059669; font-size: 1.35rem;">medication</span>';
    else if (formType === 'TA') iconHtml = '<span class="material-icons-round" style="color: #8b5cf6; font-size: 1.35rem;">radar</span>';
    else if (formType === 'USV') iconHtml = '<span class="material-icons-round" style="color: #0284c7; font-size: 1.35rem;">more_time</span>';

    document.getElementById('cfEditorTitle').innerHTML = `${iconHtml} <span>${escapeHtml(rec.record_number)}</span>`;

    // Badges
    const bBox = document.getElementById('cfEditorBadges');
    let stBadge = rec.status === 'complete' ? '<span style="background: #ecfdf5; color: #047857; padding: 2px 8px; border-radius: 99px; font-size: 0.75rem; font-weight: 600; white-space: nowrap;">Complete</span>' : '<span style="background: #eff6ff; color: #1d4ed8; padding: 2px 8px; border-radius: 99px; font-size: 0.75rem; font-weight: 600; white-space: nowrap;">Draft</span>';

    const recMonRev = (rec.monitor_reviewed === true || rec.monitor_reviewed === 't' || rec.monitor_reviewed === 1 || rec.monitor_reviewed === '1');
    const recMgrRev = (rec.manager_reviewed === true || rec.manager_reviewed === 't' || rec.manager_reviewed === 1 || rec.manager_reviewed === '1');
    const recSdrSub = (rec.sdr_submitted === true || rec.sdr_submitted === 't' || rec.sdr_submitted === 1 || rec.sdr_submitted === '1');
    const recPiSigned = (rec.pi_signed_off === true || rec.pi_signed_off === 't' || rec.pi_signed_off === 1 || rec.pi_signed_off === '1');

    let sdrBadge = '<span style="background: #f1f5f9; color: #64748b; padding: 2px 8px; border-radius: 99px; font-size: 0.75rem; font-weight: 500; white-space: nowrap;">Pending SDR</span>';
    if (recPiSigned || rec.sdr_status === 'pi_signed_off') {
        sdrBadge = '<span style="background: #e0e7ff; color: #4338ca; padding: 2px 8px; border-radius: 99px; font-size: 0.75rem; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; white-space: nowrap;"><span class="material-icons-round" style="font-size: 14px;">verified_user</span> PI Signed Off</span>';
    } else if ((recMonRev && recMgrRev) || rec.sdr_status === 'sdved_and_dm_reviewed') {
        sdrBadge = '<span style="background: #f0fdf4; color: #0d8e6f; padding: 2px 8px; border-radius: 99px; font-size: 0.75rem; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; white-space: nowrap;"><span class="material-icons-round" style="font-size: 14px;">verified</span> SDVed & DM Reviewed</span>';
    } else if (recMgrRev && !recMonRev) {
        sdrBadge = '<span style="background: #f5f3ff; color: #6d28d9; padding: 2px 8px; border-radius: 99px; font-size: 0.75rem; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; white-space: nowrap;"><span class="material-icons-round" style="font-size: 14px;">verified</span> DM Reviewed</span>';
    } else if (recMonRev && !recMgrRev) {
        sdrBadge = '<span style="background: #ecfdf5; color: #047857; padding: 2px 8px; border-radius: 99px; font-size: 0.75rem; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; white-space: nowrap;"><span class="material-icons-round" style="font-size: 14px;">check_circle</span> SDVed</span>';
    } else if (rec.sdr_status === 'dm_reviewed') {
        sdrBadge = '<span style="background: #f5f3ff; color: #6d28d9; padding: 2px 8px; border-radius: 99px; font-size: 0.75rem; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; white-space: nowrap;"><span class="material-icons-round" style="font-size: 14px;">verified</span> DM Reviewed</span>';
    } else if (rec.sdr_status === 'sdved' || rec.sdr_status === 'reviewed') {
        sdrBadge = '<span style="background: #ecfdf5; color: #047857; padding: 2px 8px; border-radius: 99px; font-size: 0.75rem; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; white-space: nowrap;"><span class="material-icons-round" style="font-size: 14px;">check_circle</span> SDVed</span>';
    } else if (rec.sdr_status === 'under_review' || recSdrSub) {
        sdrBadge = '<span style="background: #fef3c7; color: #b45309; padding: 2px 8px; border-radius: 99px; font-size: 0.75rem; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; white-space: nowrap;"><span class="material-icons-round" style="font-size: 13px;">pending_actions</span> Under Review</span>';
    } else if (rec.sdr_status === 'needs_rereview') {
        sdrBadge = '<span style="background: #fff7ed; color: #c2410c; padding: 2px 8px; border-radius: 99px; font-size: 0.75rem; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; white-space: nowrap;"><span class="material-icons-round" style="font-size: 13px;">published_with_changes</span> Re-review</span>';
    }
    bBox.innerHTML = `${stBadge} ${sdrBadge} <span style="background: #f8fafc; color: #64748b; border: 1px solid #cbd5e1; padding: 2px 8px; border-radius: 99px; font-size: 0.75rem; white-space: nowrap;">v${rec.revision || 1}</span>`;

    // Audit / Query / SDR buttons
    document.getElementById('btnCfAudit').style.display = isNew ? 'none' : 'inline-flex';
    document.getElementById('btnCfQuery').style.display = isNew ? 'none' : 'inline-flex';

    // 3-Role Review Workflow Actions in Modal Footer
    const wfContainer = document.getElementById('cfModalWorkflowBtns');
    if (wfContainer) {
        if (isNew || rec.is_voided) {
            wfContainer.innerHTML = '';
        } else {
            let mBtns = '';
            const sdrSub = !!rec.sdr_submitted;
            const monRev = !!rec.monitor_reviewed;
            const mgrRev = !!rec.manager_reviewed;
            const piSigned = (rec.pi_signed_off === true || rec.pi_signed_off === 't' || rec.pi_signed_off === 1 || rec.pi_signed_off === '1');

            if (piSigned) {
                // If PI Signed Off, only PI or Admin can revoke
                if (USER_IS_PI || USER_IS_ADMIN) {
                    mBtns += `<button type="button" class="btn btn-sm btn-outline" onclick="closeModal('modalCommonFormEditor'); triggerCommonReviewAction(${rec.id}, 'pi_revoke', '${formType}')" style="color: #dc2626; border: 1px solid #dc2626; font-weight: 600; padding: 0.5rem 1.1rem; display: inline-flex; align-items: center; gap: 4px;"><span class="material-icons-round" style="font-size: 1rem;">undo</span> Revoke PI Sign Off</button>`;
                } else {
                    mBtns += `<span class="badge" style="background: #e0e7ff; color: #4338ca; padding: 0.5rem 0.8rem; border-radius: 6px; font-size: 0.8rem; display: inline-flex; align-items: center; gap: 4px;"><span class="material-icons-round" style="font-size: 1rem;">verified_user</span> PI Signed Off (Locked)</span>`;
                }
            } else {
                // Coordinator
                if (USER_IS_COORDINATOR) {
                    if (!sdrSub) {
                        if (rec.status === 'complete') {
                            mBtns += `<button type="button" class="btn btn-sm" onclick="closeModal('modalCommonFormEditor'); triggerCommonReviewAction(${rec.id}, 'mark_sdr', '${formType}')" style="background: #1d6f97; color: #fff; border: 1px solid #1d6f97; font-weight: 600; padding: 0.5rem 1.1rem; display: inline-flex; align-items: center; gap: 4px;"><span class="material-icons-round" style="font-size: 1rem;">lock</span> Mark as SDR</button>`;
                        } else {
                            mBtns += `<button type="button" class="btn btn-sm" onclick="showToast('Cannot mark as SDR: Record must be marked complete first.', 'warning')" style="background: #f1f5f9; color: #94a3b8; border: 1px solid #cbd5e1; cursor: not-allowed; padding: 0.5rem 1.1rem; display: inline-flex; align-items: center; gap: 4px;"><span class="material-icons-round" style="font-size: 1rem;">lock</span> Mark as SDR</button>`;
                        }
                    } else if (sdrSub && !monRev && !mgrRev) {
                        mBtns += `<button type="button" class="btn btn-sm btn-outline" onclick="closeModal('modalCommonFormEditor'); triggerCommonReviewAction(${rec.id}, 'revoke_sdr', '${formType}')" style="color: #dc2626; border: 1px solid #dc2626; font-weight: 600; padding: 0.5rem 1.1rem; display: inline-flex; align-items: center; gap: 4px;"><span class="material-icons-round" style="font-size: 1rem;">lock_open</span> Revoke SDR</button>`;
                    } else if (sdrSub && (monRev || mgrRev) && !USER_IS_MONITOR && !USER_IS_MANAGER && !USER_IS_ADMIN) {
                        mBtns += `<span class="badge" style="background: #f1f5f9; color: #64748b; padding: 0.5rem 0.8rem; border-radius: 6px; font-size: 0.8rem; display: inline-flex; align-items: center; gap: 4px;"><span class="material-icons-round" style="font-size: 1rem;">lock</span> Form Locked</span>`;
                    }
                }

                // Monitor
                if ((USER_IS_MONITOR || USER_IS_ADMIN) && sdrSub) {
                    if (!monRev) {
                        mBtns += `<button type="button" class="btn btn-sm" onclick="closeModal('modalCommonFormEditor'); triggerCommonReviewAction(${rec.id}, 'monitor_review', '${formType}')" style="background: #059669; color: #fff; border: 1px solid #059669; font-weight: 600; padding: 0.5rem 1.1rem; display: inline-flex; align-items: center; gap: 4px;"><span class="material-icons-round" style="font-size: 1rem;">verified</span> Mark as SDVed</button>`;
                    } else {
                        mBtns += `<button type="button" class="btn btn-sm btn-outline" onclick="closeModal('modalCommonFormEditor'); triggerCommonReviewAction(${rec.id}, 'monitor_revoke', '${formType}')" style="color: #dc2626; border: 1px solid #dc2626; font-weight: 600; padding: 0.5rem 1.1rem; display: inline-flex; align-items: center; gap: 4px;"><span class="material-icons-round" style="font-size: 1rem;">undo</span> Revoke SDVed</button>`;
                    }
                }

                // Manager
                if ((USER_IS_MANAGER || USER_IS_ADMIN) && sdrSub) {
                    if (!mgrRev) {
                        mBtns += `<button type="button" class="btn btn-sm" onclick="closeModal('modalCommonFormEditor'); triggerCommonReviewAction(${rec.id}, 'manager_review', '${formType}')" style="background: #6d28d9; color: #fff; border: 1px solid #6d28d9; font-weight: 600; padding: 0.5rem 1.1rem; display: inline-flex; align-items: center; gap: 4px;"><span class="material-icons-round" style="font-size: 1rem;">verified</span> Mark as DM Reviewed</button>`;
                    } else {
                        mBtns += `<button type="button" class="btn btn-sm btn-outline" onclick="closeModal('modalCommonFormEditor'); triggerCommonReviewAction(${rec.id}, 'manager_revoke', '${formType}')" style="color: #dc2626; border: 1px solid #dc2626; font-weight: 600; padding: 0.5rem 1.1rem; display: inline-flex; align-items: center; gap: 4px;"><span class="material-icons-round" style="font-size: 1rem;">undo</span> Revoke DM Reviewed</button>`;
                    }
                }

                // Principal Investigator
                if ((USER_IS_PI || USER_IS_ADMIN) && monRev && mgrRev) {
                    mBtns += `<button type="button" class="btn btn-sm" onclick="closeModal('modalCommonFormEditor'); triggerCommonReviewAction(${rec.id}, 'pi_sign_off', '${formType}')" style="background: #4338ca; color: #fff; border: 1px solid #4338ca; font-weight: 600; padding: 0.5rem 1.1rem; display: inline-flex; align-items: center; gap: 4px;"><span class="material-icons-round" style="font-size: 1rem;">verified_user</span> PI Sign Off</button>`;
                }
            }

            wfContainer.innerHTML = mBtns;
        }
    }

    const fieldsBox = document.getElementById('cfDynamicFields');
    const d = rec.data || {};

    if (formType === 'MH') {
        fieldsBox.innerHTML = `
            <div style="display: grid; grid-template-columns: 1fr; gap: 1.25rem;">
                <div>
                    ${renderCommonFieldHeader(rec, 'mh_term', '1. MH Term / Condition', true)}
                    <input type="text" id="inp_mh_term" class="form-input" value="${escapeHtml(d.mh_term || '')}" placeholder="e.g. Type 2 Diabetes Mellitus" style="width: 100%;">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem;">
                    <div>
                        ${renderCommonFieldHeader(rec, 'ongoing', '2. Ongoing / Continuing', true)}
                        <select id="inp_mh_ongoing" onchange="onMHOngoingChange()" class="form-input" style="width: 100%;">
                            <option value="">-- Select --</option>
                            <option value="Yes" ${d.ongoing === 'Yes' ? 'selected' : ''}>Yes</option>
                            <option value="No" ${d.ongoing === 'No' ? 'selected' : ''}>No</option>
                        </select>
                    </div>

                    <div>
                        ${renderCommonFieldHeader(rec, 'start_date', '3. Start Date', false)}
                        <input type="date" id="inp_mh_start_date" onchange="onMHDateChange()" class="form-input" max="${getTodayIST()}" value="${escapeHtml(d.start_date || '')}" style="width: 100%;">
                    </div>

                    <div>
                        ${renderCommonFieldHeader(rec, 'end_date', '4. End Date', false)}
                        <input type="date" id="inp_mh_end_date" class="form-input" max="${getTodayIST()}" value="${escapeHtml(d.end_date || '')}" style="width: 100%;">
                    </div>
                </div>

                <div>
                    ${renderCommonFieldHeader(rec, 'comments', '5. Comments / Additional Details', false)}
                    <textarea id="inp_mh_comments" class="form-input" rows="3" placeholder="Enter additional details..." style="width: 100%; resize: vertical;">${escapeHtml(d.comments || '')}</textarea>
                </div>

                <div>
                    ${renderCommonFieldHeader(rec, 'medication_given', '6. Medication Given', true)}
                    <select id="inp_mh_medication_given" onchange="onMHMedGivenChange()" class="form-input" style="width: 200px;">
                        <option value="">-- Select --</option>
                        <option value="Yes" ${d.medication_given === 'Yes' ? 'selected' : ''}>Yes</option>
                        <option value="No" ${d.medication_given === 'No' ? 'selected' : ''}>No</option>
                    </select>

                    <div id="mhCmShortcutBox" style="display: ${USER_CAN_EDIT_CONTENT && d.medication_given === 'Yes' && rec.id > 0 ? 'block' : 'none'}; margin-top: 0.75rem;">
                        <button type="button" onclick="shortcutAddCMFromMH(${rec.id})" class="btn btn-sm" style="background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; font-weight: 600;">
                            <span class="material-icons-round" style="font-size: 1rem; margin-right: 4px;">add</span> Add Concomitant Medication for ${escapeHtml(rec.record_number)}
                        </button>
                    </div>
                </div>
            </div>
        `;
        onMHOngoingChange();

    } else if (formType === 'AE') {
        const critList = Array.isArray(d.seriousness_criteria) ? d.seriousness_criteria : (typeof d.seriousness_criteria === 'string' ? d.seriousness_criteria.split(',') : []);
        const isSerious = d.seriousness === 'Yes';

        fieldsBox.innerHTML = `
            <div style="display: grid; grid-template-columns: 1fr; gap: 1.25rem;">
                <div>
                    ${renderCommonFieldHeader(rec, 'ae_term', '1. AE Term / Verbatim', true)}
                    <input type="text" id="inp_ae_term" class="form-input" value="${escapeHtml(d.ae_term || '')}" placeholder="e.g. Severe Headache" style="width: 100%;">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 1rem;">
                    <div>
                        ${renderCommonFieldHeader(rec, 'start_date', '2. Start Date', false)}
                        <input type="date" id="inp_ae_start_date" class="form-input" max="${getTodayIST()}" value="${escapeHtml(d.start_date || '')}" style="width: 100%;">
                    </div>

                    <div>
                        ${renderCommonFieldHeader(rec, 'end_date', '3. End Date', false)}
                        <input type="date" id="inp_ae_end_date" class="form-input" max="${getTodayIST()}" value="${escapeHtml(d.end_date || '')}" style="width: 100%;">
                    </div>

                    <div>
                        ${renderCommonFieldHeader(rec, 'severity', '4. Severity / Intensity', true)}
                        <select id="inp_ae_severity" class="form-input" style="width: 100%;">
                            <option value="">-- Select --</option>
                            <option value="Mild" ${d.severity === 'Mild' ? 'selected' : ''}>Mild</option>
                            <option value="Moderate" ${d.severity === 'Moderate' ? 'selected' : ''}>Moderate</option>
                            <option value="Severe" ${d.severity === 'Severe' ? 'selected' : ''}>Severe</option>
                        </select>
                    </div>

                    <div>
                        ${renderCommonFieldHeader(rec, 'seriousness', '5. Seriousness', true)}
                        <select id="inp_ae_seriousness" onchange="onAESeriousnessChange()" class="form-input" style="width: 100%;">
                            <option value="">-- Select --</option>
                            <option value="Yes" ${isSerious ? 'selected' : ''}>Yes</option>
                            <option value="No" ${!isSerious && d.seriousness === 'No' ? 'selected' : ''}>No</option>
                        </select>
                    </div>
                </div>

                <!-- 6. Seriousness Criteria Multi-select -->
                <div id="aeSeriousnessCriteriaBox" style="display: ${isSerious ? 'block' : 'none'}; background: #fff7ed; border: 1px solid #fed7aa; padding: 1rem; border-radius: 8px;">
                    ${renderCommonFieldHeader(rec, 'seriousness_criteria', '6. Seriousness Criteria (Select all that apply)', true)}
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem;">
                        ${renderAeCritCb('Death', 'Death', critList)}
                        ${renderAeCritCb('Life-threatening', 'Life-threatening', critList)}
                        ${renderAeCritCb('Hospitalization / prolongation of hospitalization', 'Hospitalization / prolongation', critList)}
                        ${renderAeCritCb('Disability / incapacity', 'Disability / incapacity', critList)}
                        ${renderAeCritCb('Congenital anomaly', 'Congenital anomaly', critList)}
                        ${renderAeCritCb('Other medically important event', 'Other medically important event', critList)}
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div>
                        ${renderCommonFieldHeader(rec, 'causality', '7. Causality / Relationship to Study Drug', true)}
                        <select id="inp_ae_causality" class="form-input" style="width: 100%;">
                            <option value="">-- Select --</option>
                            <option value="Unrelated" ${d.causality === 'Unrelated' ? 'selected' : ''}>Unrelated</option>
                            <option value="Unlikely" ${d.causality === 'Unlikely' ? 'selected' : ''}>Unlikely</option>
                            <option value="Possible" ${d.causality === 'Possible' ? 'selected' : ''}>Possible</option>
                            <option value="Probable" ${d.causality === 'Probable' ? 'selected' : ''}>Probable</option>
                            <option value="Definite" ${d.causality === 'Definite' ? 'selected' : ''}>Definite</option>
                        </select>
                    </div>

                    <div>
                        ${renderCommonFieldHeader(rec, 'action_taken', '8. Action Taken with Study Treatment', true)}
                        <select id="inp_ae_action_taken" class="form-input" style="width: 100%;">
                            <option value="">-- Select --</option>
                            <option value="None" ${d.action_taken === 'None' ? 'selected' : ''}>None</option>
                            <option value="Dose Reduced" ${d.action_taken === 'Dose Reduced' ? 'selected' : ''}>Dose Reduced</option>
                            <option value="Dose Interrupted" ${d.action_taken === 'Dose Interrupted' ? 'selected' : ''}>Dose Interrupted</option>
                            <option value="Drug Withdrawn" ${d.action_taken === 'Drug Withdrawn' ? 'selected' : ''}>Drug Withdrawn</option>
                            <option value="Not Applicable" ${d.action_taken === 'Not Applicable' ? 'selected' : ''}>Not Applicable</option>
                        </select>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div>
                        ${renderCommonFieldHeader(rec, 'outcome', '9. Outcome', true)}
                        <select id="inp_ae_outcome" onchange="onAEOutcomeChange()" class="form-input" style="width: 100%;">
                            <option value="">-- Select --</option>
                            <option value="Recovered / Resolved" ${d.outcome === 'Recovered / Resolved' ? 'selected' : ''}>Recovered / Resolved</option>
                            <option value="Recovering / Resolving" ${d.outcome === 'Recovering / Resolving' ? 'selected' : ''}>Recovering / Resolving</option>
                            <option value="Not Recovered / Not Resolved" ${d.outcome === 'Not Recovered / Not Resolved' ? 'selected' : ''}>Not Recovered / Not Resolved</option>
                            <option value="Recovered with Sequelae" ${d.outcome === 'Recovered with Sequelae' ? 'selected' : ''}>Recovered with Sequelae</option>
                            <option value="Fatal" ${d.outcome === 'Fatal' ? 'selected' : ''}>Fatal</option>
                            <option value="Unknown" ${d.outcome === 'Unknown' ? 'selected' : ''}>Unknown</option>
                        </select>
                    </div>

                    <div>
                        ${renderCommonFieldHeader(rec, 'concomitant_treatment', '10. Concomitant Treatment Given', true)}
                        <select id="inp_ae_concomitant_treatment" onchange="onAEConcomitantTreatmentChange()" class="form-input" style="width: 100%;">
                            <option value="">-- Select --</option>
                            <option value="Yes" ${d.concomitant_treatment === 'Yes' ? 'selected' : ''}>Yes</option>
                            <option value="No" ${d.concomitant_treatment === 'No' ? 'selected' : ''}>No</option>
                        </select>

                        <div id="aeCmShortcutBox" style="display: ${USER_CAN_EDIT_CONTENT && d.concomitant_treatment === 'Yes' && rec.id > 0 ? 'block' : 'none'}; margin-top: 0.75rem;">
                            <button type="button" onclick="shortcutAddCMFromAE(${rec.id})" class="btn btn-sm" style="background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; font-weight: 600;">
                                <span class="material-icons-round" style="font-size: 1rem; margin-right: 4px;">add</span> Add Concomitant Medication for ${escapeHtml(rec.record_number)}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;
        onAEOutcomeChange();

    } else if (formType === 'CM') {
        const indType = d.indication_type || '';
        const existingLinks = rec.linked_records || [];

        fieldsBox.innerHTML = `
            <div style="display: grid; grid-template-columns: 1fr; gap: 1.25rem;">
                <div>
                    ${renderCommonFieldHeader(rec, 'medication_name', '1. Medication Name', true)}
                    <input type="text" id="inp_cm_name" class="form-input" value="${escapeHtml(d.medication_name || '')}" placeholder="e.g. Metformin 500mg" style="width: 100%;">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div>
                        ${renderCommonFieldHeader(rec, 'start_date', '2. Start Date', false)}
                        <input type="date" id="inp_cm_start_date" class="form-input" max="${getTodayIST()}" value="${escapeHtml(d.start_date || '')}" style="width: 100%;">
                    </div>

                    <div>
                        ${renderCommonFieldHeader(rec, 'stop_date', '3. Stop Date', false)}
                        <input type="date" id="inp_cm_stop_date" class="form-input" max="${getTodayIST()}" value="${escapeHtml(d.stop_date || '')}" style="width: 100%;">
                    </div>
                </div>

                <div>
                    ${renderCommonFieldHeader(rec, 'indication_type', '4. Medication Taken For (Indication Type)', true)}
                    <select id="inp_cm_indication_type" onchange="onCMIndicationChange()" class="form-input" style="width: 250px;">
                        <option value="">-- Select --</option>
                        <option value="MH" ${indType === 'MH' ? 'selected' : ''}>Medical History (MH)</option>
                        <option value="AE" ${indType === 'AE' ? 'selected' : ''}>Adverse Event (AE)</option>
                        <option value="Other" ${indType === 'Other' ? 'selected' : ''}>Other Indication</option>
                    </select>
                </div>

                <!-- 5. Linked Condition / Event Selection -->
                <div id="cmLinkedContainer" style="display: ${indType === 'MH' || indType === 'AE' ? 'block' : 'none'}; background: #f8fafc; border: 1px solid #e2e8f0; padding: 1.25rem; border-radius: 8px;">
                    ${renderCommonFieldHeader(rec, 'linked_records', '5. Linked Condition / Event', true)}
                    <div id="cmLinkedRecordsList" style="display: flex; flex-direction: column; gap: 0.5rem; max-height: 200px; overflow-y: auto;">
                        <span style="color: #94a3b8; font-size: 0.875rem;">Loading active records...</span>
                    </div>
                </div>

                <!-- 6. Specify Other -->
                <div id="cmSpecifyOtherContainer" style="display: ${indType === 'Other' ? 'block' : 'none'};">
                    ${renderCommonFieldHeader(rec, 'specify_other', '6. Specify Other Indication', true)}
                    <input type="text" id="inp_cm_specify_other" class="form-input" value="${escapeHtml(d.specify_other || '')}" placeholder="Enter indication..." style="width: 100%;">
                </div>
            </div>
        `;

        if (indType === 'MH' || indType === 'AE') {
            loadCMLinkableRecords(indType, existingLinks);
        }
    } else if (formType === 'TA') {
        let existingTlCount = 1;
        for (let i = 5; i >= 1; i--) {
            if (
                (d[`tl${i}_organ`] && d[`tl${i}_organ`].trim() !== '') ||
                (d[`tl${i}_site`] && d[`tl${i}_site`].trim() !== '') ||
                (d[`tl${i}_longest_dia`] !== undefined && d[`tl${i}_longest_dia`] !== null && d[`tl${i}_longest_dia`].toString().trim() !== '')
            ) {
                existingTlCount = i;
                break;
            }
        }
        if (existingTlCount < 1) existingTlCount = 1;

        let tlRowsHtml = '';
        for (let i = 1; i <= existingTlCount; i++) {
            const org = d[`tl${i}_organ`] || '';
            const site = d[`tl${i}_site`] || '';
            const axis = d[`tl${i}_axis`] || 'Long';
            const dia = d[`tl${i}_longest_dia`] !== undefined && d[`tl${i}_longest_dia`] !== null ? d[`tl${i}_longest_dia`] : '';
            const st = d[`tl${i}_status`] || 'Measured';
            tlRowsHtml += renderTaTlRowHtml(i, { organ: org, site: site, axis: axis, dia: dia, status: st }, existingTlCount > 1);
        }

        let existingNtlCount = 1;
        for (let i = 5; i >= 1; i--) {
            if (
                (d[`ntl${i}_organ`] && d[`ntl${i}_organ`].trim() !== '') ||
                (d[`ntl${i}_site`] && d[`ntl${i}_site`].trim() !== '')
            ) {
                existingNtlCount = i;
                break;
            }
        }
        if (existingNtlCount < 1) existingNtlCount = 1;

        let ntlRowsHtml = '';
        for (let i = 1; i <= existingNtlCount; i++) {
            const norg = d[`ntl${i}_organ`] || '';
            const nsite = d[`ntl${i}_site`] || '';
            const nst = d[`ntl${i}_status`] || 'Present';
            ntlRowsHtml += renderTaNtlRowHtml(i, { organ: norg, site: nsite, status: nst }, existingNtlCount > 1);
        }

        fieldsBox.innerHTML = `
            <div style="display: flex; flex-direction: column; gap: 1.25rem;">
                <!-- General Assessment Info -->
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 1.25rem;">
                    <div style="font-weight: 700; color: #1e293b; margin-bottom: 0.75rem; font-size: 0.95rem; display: flex; align-items: center; gap: 0.5rem;">
                        <span class="material-icons-round" style="color: #8b5cf6;">tune</span> Assessment Parameters (RECIST 1.1)
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                        <div>
                            ${renderCommonFieldHeader(rec, 'recist_method', 'Assessment Method', false)}
                            <input type="text" id="inp_ta_recist_method" class="form-input" value="${escapeHtml(d.recist_method || 'RECIST 1.1')}" readonly style="background: #f1f5f9; width: 100%;">
                        </div>
                        <div>
                            ${renderCommonFieldHeader(rec, 'tumor_assessed', 'Was Tumor Assessment Performed?', true)}
                            <select id="inp_ta_tumor_assessed" onchange="onTaAssessedChange()" class="form-input" style="width: 100%;">
                                <option value="Yes" ${d.tumor_assessed !== 'No' ? 'selected' : ''}>Yes</option>
                                <option value="No" ${d.tumor_assessed === 'No' ? 'selected' : ''}>No</option>
                            </select>
                        </div>
                    </div>

                    <div id="taNotAssessedReasonContainer" style="display: ${d.tumor_assessed === 'No' ? 'block' : 'none'}; margin-bottom: 1rem;">
                        ${renderCommonFieldHeader(rec, 'tumor_not_assessed_reason', 'Reason Tumor Assessment Not Performed', true)}
                        <input type="text" id="inp_ta_not_assessed_reason" class="form-input" value="${escapeHtml(d.tumor_not_assessed_reason || '')}" placeholder="Enter clinical reason..." style="width: 100%;">
                    </div>

                    <div id="taAssessmentDetailsContainer" style="display: ${d.tumor_assessed === 'No' ? 'none' : 'block'};">
                        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem;">
                            <div>
                                ${renderCommonFieldHeader(rec, 'tumor_timepoint', 'Assessment Time Point', true)}
                                <input type="text" id="inp_ta_timepoint" class="form-input" value="${escapeHtml(d.tumor_timepoint || '')}" placeholder="e.g. Screening, Cycle 2 Day 1, Week 6" style="width: 100%;">
                            </div>
                            <div>
                                ${renderCommonFieldHeader(rec, 'imaging_date', 'Imaging Assessment Date', true)}
                                <input type="date" id="inp_ta_imaging_date" class="form-input" max="${getTodayIST()}" value="${escapeHtml(d.imaging_date || '')}" style="width: 100%;">
                            </div>
                            <div>
                                ${renderCommonFieldHeader(rec, 'imaging_modality', 'Imaging Modality', true)}
                                <select id="inp_ta_imaging_modality" onchange="onTaModalityChange()" class="form-input" style="width: 100%;">
                                    <option value="">-- Select --</option>
                                    <option value="CT" ${d.imaging_modality === 'CT' ? 'selected' : ''}>CT</option>
                                    <option value="MRI" ${d.imaging_modality === 'MRI' ? 'selected' : ''}>MRI</option>
                                    <option value="CT and MRI" ${d.imaging_modality === 'CT and MRI' ? 'selected' : ''}>CT and MRI</option>
                                    <option value="PET-CT" ${d.imaging_modality === 'PET-CT' ? 'selected' : ''}>PET-CT</option>
                                    <option value="Other" ${d.imaging_modality === 'Other' ? 'selected' : ''}>Other</option>
                                </select>
                            </div>
                        </div>
                        <div id="taModalityOtherContainer" style="display: ${d.imaging_modality === 'Other' ? 'block' : 'none'}; margin-top: 0.75rem;">
                            ${renderCommonFieldHeader(rec, 'imaging_modality_other', 'Other Modality — Specify', true)}
                            <input type="text" id="inp_ta_imaging_modality_other" class="form-input" value="${escapeHtml(d.imaging_modality_other || '')}" placeholder="Specify imaging technique..." style="width: 100%;">
                        </div>
                    </div>
                </div>

                <!-- Target Lesions Section -->
                <div id="taTargetLesionsContainer" style="display: ${d.tumor_assessed === 'No' ? 'none' : 'block'}; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 1.25rem;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
                        <div style="font-weight: 700; color: #1e293b; font-size: 0.95rem; display: flex; align-items: center; gap: 0.5rem;">
                            <span class="material-icons-round" style="color: #2563eb;">center_focus_strong</span> Target Lesions (Max 5 total, max 2 per organ)
                        </div>
                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                            <label style="font-weight: 600; font-size: 0.85rem; color: #334155;">Target Lesions Present?</label>
                            ${renderCommonFieldHeader(rec, 'target_lesions_present', '', false)}
                            <select id="inp_ta_target_present" onchange="onTaTargetPresentChange()" class="form-input" style="width: 100px; font-size: 0.85rem;">
                                <option value="Yes" ${d.target_lesions_present !== 'No' ? 'selected' : ''}>Yes</option>
                                <option value="No" ${d.target_lesions_present === 'No' ? 'selected' : ''}>No</option>
                            </select>
                        </div>
                    </div>

                    <div id="taTargetTableContainer" style="display: ${d.target_lesions_present === 'No' ? 'none' : 'block'};">
                        <table style="width: 100%; border-collapse: collapse; background: white; border: 1px solid #cbd5e1; border-radius: 6px; overflow: hidden;">
                            <thead style="background: #f1f5f9; font-size: 0.8rem; color: #475569;">
                                <tr>
                                    <th style="padding: 0.6rem 0.5rem; text-align: left; width: 60px;">Lesion</th>
                                    <th style="padding: 0.6rem 0.5rem; text-align: left;">Organ / Tissue</th>
                                    <th style="padding: 0.6rem 0.5rem; text-align: left;">Site / Location</th>
                                    <th style="padding: 0.6rem 0.5rem; text-align: left; width: 160px;">Axis</th>
                                    <th style="padding: 0.6rem 0.5rem; text-align: left; width: 110px;">Diameter (mm)</th>
                                    <th style="padding: 0.6rem 0.5rem; text-align: left; width: 160px;">Status</th>
                                    <th style="padding: 0.6rem 0.5rem; text-align: center; width: 45px;">Action</th>
                                </tr>
                            </thead>
                            <tbody id="taTlTbody">
                                ${tlRowsHtml}
                            </tbody>
                        </table>
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 0.85rem; flex-wrap: wrap; gap: 0.75rem;">
                            <div style="display: flex; align-items: center; gap: 0.6rem;">
                                <button type="button" id="btnAddTaTlRow" onclick="addTaTlRow()" style="display: inline-flex; align-items: center; gap: 0.35rem; background: #eff6ff; color: #2563eb; border: 1px dashed #3b82f6; padding: 0.45rem 0.9rem; border-radius: 6px; font-weight: 600; font-size: 0.825rem; cursor: pointer; transition: all 0.2s;" onmouseover="this.style.background='#dbeafe'" onmouseout="this.style.background='#eff6ff'">
                                    <span class="material-icons-round" style="font-size: 1.15rem; font-weight: bold;">add</span> Add Lesion
                                </button>
                                <span id="taTlCountBadge" style="font-size: 0.75rem; color: #64748b; font-weight: 500;">
                                    (${existingTlCount} of max 5 lesions)
                                </span>
                            </div>
                            <div style="display: flex; align-items: center; gap: 0.75rem;">
                                <label style="font-weight: 700; font-size: 0.875rem; color: #1e293b;">Sum of Longest Diameters (mm):</label>
                                ${renderCommonFieldHeader(rec, 'sum_longest_dia', '', false)}
                                <input type="number" step="0.1" id="inp_ta_sum_longest_dia" class="form-input" value="${escapeHtml(d.sum_longest_dia || '')}" placeholder="0.0" style="width: 110px; font-weight: 700; text-align: right; background: #f8fafc;" readonly>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Non-Target Lesions Section -->
                <div id="taNonTargetContainer" style="display: ${d.tumor_assessed === 'No' ? 'none' : 'block'}; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 1.25rem;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
                        <div style="font-weight: 700; color: #1e293b; font-size: 0.95rem; display: flex; align-items: center; gap: 0.5rem;">
                            <span class="material-icons-round" style="color: #059669;">bubble_chart</span> Non-Target Lesions
                        </div>
                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                            <label style="font-weight: 600; font-size: 0.85rem; color: #334155;">Non-Target Present?</label>
                            ${renderCommonFieldHeader(rec, 'nontarget_lesions_present', '', false)}
                            <select id="inp_ta_nontarget_present" onchange="onTaNonTargetChange()" class="form-input" style="width: 100px; font-size: 0.85rem;">
                                <option value="Yes" ${d.nontarget_lesions_present !== 'No' ? 'selected' : ''}>Yes</option>
                                <option value="No" ${d.nontarget_lesions_present === 'No' ? 'selected' : ''}>No</option>
                            </select>
                        </div>
                    </div>

                    <div id="taNonTargetTableContainer" style="display: ${d.nontarget_lesions_present === 'No' ? 'none' : 'block'};">
                        <table style="width: 100%; border-collapse: collapse; background: white; border: 1px solid #cbd5e1; border-radius: 6px; overflow: hidden;">
                            <thead style="background: #f1f5f9; font-size: 0.8rem; color: #475569;">
                                <tr>
                                    <th style="padding: 0.6rem 0.5rem; text-align: left; width: 60px;">Lesion</th>
                                    <th style="padding: 0.6rem 0.5rem; text-align: left;">Organ / Tissue</th>
                                    <th style="padding: 0.6rem 0.5rem; text-align: left;">Site / Location</th>
                                    <th style="padding: 0.6rem 0.5rem; text-align: left; width: 200px;">Status</th>
                                    <th style="padding: 0.6rem 0.5rem; text-align: center; width: 45px;">Action</th>
                                </tr>
                            </thead>
                            <tbody id="taNtlTbody">
                                ${ntlRowsHtml}
                            </tbody>
                        </table>
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 0.85rem; flex-wrap: wrap; gap: 0.75rem;">
                            <div style="display: flex; align-items: center; gap: 0.6rem;">
                                <button type="button" id="btnAddTaNtlRow" onclick="addTaNtlRow()" style="display: inline-flex; align-items: center; gap: 0.35rem; background: #ecfdf5; color: #059669; border: 1px dashed #10b981; padding: 0.45rem 0.9rem; border-radius: 6px; font-weight: 600; font-size: 0.825rem; cursor: pointer; transition: all 0.2s;" onmouseover="this.style.background='#d1fae5'" onmouseout="this.style.background='#ecfdf5'">
                                    <span class="material-icons-round" style="font-size: 1.15rem; font-weight: bold;">add</span> Add Non-Target Lesion
                                </button>
                                <span id="taNtlCountBadge" style="font-size: 0.75rem; color: #64748b; font-weight: 500;">
                                    (${existingNtlCount} of max 5 lesions)
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- RECIST Overall Response & Evaluation -->
                <div id="taResponseContainer" style="display: ${d.tumor_assessed === 'No' ? 'none' : 'block'}; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 1.25rem;">
                    <div style="font-weight: 700; color: #1e293b; margin-bottom: 0.75rem; font-size: 0.95rem; display: flex; align-items: center; gap: 0.5rem;">
                        <span class="material-icons-round" style="color: #d97706;">assessment</span> RECIST 1.1 Overall Evaluation
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                        <div>
                            ${renderCommonFieldHeader(rec, 'new_lesions_present', 'New Lesions Identified?', true)}
                            <select id="inp_ta_new_lesions" onchange="onTaNewLesionsChange()" class="form-input" style="width: 100%;">
                                <option value="No" ${d.new_lesions_present === 'No' ? 'selected' : ''}>No</option>
                                <option value="Yes" ${d.new_lesions_present === 'Yes' ? 'selected' : ''}>Yes</option>
                                <option value="Indeterminate" ${d.new_lesions_present === 'Indeterminate' ? 'selected' : ''}>Indeterminate</option>
                            </select>
                        </div>
                        <div>
                            ${renderCommonFieldHeader(rec, 'recist_overall_response', 'RECIST 1.1 Overall Response', true)}
                            <select id="inp_ta_overall_response" class="form-input" style="width: 100%;">
                                <option value="">-- Select Response --</option>
                                <option value="CR — Complete Response" ${d.recist_overall_response === 'CR — Complete Response' ? 'selected' : ''}>CR — Complete Response</option>
                                <option value="PR — Partial Response" ${d.recist_overall_response === 'PR — Partial Response' ? 'selected' : ''}>PR — Partial Response</option>
                                <option value="SD — Stable Disease" ${d.recist_overall_response === 'SD — Stable Disease' ? 'selected' : ''}>SD — Stable Disease</option>
                                <option value="PD — Progressive Disease" ${d.recist_overall_response === 'PD — Progressive Disease' ? 'selected' : ''}>PD — Progressive Disease</option>
                                <option value="NE — Not Evaluable" ${d.recist_overall_response === 'NE — Not Evaluable' ? 'selected' : ''}>NE — Not Evaluable</option>
                            </select>
                        </div>
                    </div>
                    <div id="taNewLesionsDetailsContainer" style="display: ${d.new_lesions_present === 'Yes' ? 'block' : 'none'}; margin-bottom: 1rem;">
                        ${renderCommonFieldHeader(rec, 'new_lesions_details', 'New Lesion Details / Anatomical Location', true)}
                        <input type="text" id="inp_ta_new_lesions_details" class="form-input" value="${escapeHtml(d.new_lesions_details || '')}" placeholder="Specify organ and location of new lesion..." style="width: 100%;">
                    </div>
                    <div>
                        ${renderCommonFieldHeader(rec, 'comments', 'Clinical Comments / Radiology Notes', false)}
                        <textarea id="inp_ta_comments" class="form-input" rows="3" placeholder="Enter additional radiological remarks..." style="width: 100%; resize: vertical;">${escapeHtml(d.comments || '')}</textarea>
                    </div>
                </div>
            </div>
        `;
        if (formType === 'TA') {
            reindexTaTlRows();
            reindexTaNtlRows();
        }
    } else if (formType === 'USV') {
        fieldsBox.innerHTML = renderUsvFormHtml(rec, d);
    }

    // Strict Enforcement of Read-Only / Non-editable state for Data Managers & Monitors, or SDR Submitted forms, or PI Signed Off forms
    const piSigned = (rec.pi_signed_off === true || rec.pi_signed_off === 't' || rec.pi_signed_off === 1 || rec.pi_signed_off === '1');
    const isReadOnly = !USER_CAN_EDIT_CONTENT || !!rec.sdr_submitted || piSigned;
    const existingNotice = document.getElementById('cfReadOnlyNotice');
    if (existingNotice) existingNotice.remove();

    const formBody = document.getElementById('cfEditorBody');
    const btnDraft = document.getElementById('btnCfSaveDraft');
    const btnComplete = document.getElementById('btnCfMarkComplete');

    if (isReadOnly && formBody) {
        let bannerMsg = '';
        let bannerBg = '#eff6ff';
        let bannerBorder = '#bfdbfe';
        let bannerColor = '#1e40af';
        let bannerIcon = 'info';

        if (piSigned) {
            bannerMsg = '<strong>Record Locked:</strong> This record has been Signed Off by Principal Investigator. All fields, queries, and features are completely locked.';
            bannerBg = '#e0e7ff';
            bannerBorder = '#c7d2fe';
            bannerColor = '#3730a3';
            bannerIcon = 'verified_user';
        } else if (!USER_CAN_EDIT_CONTENT) {
            bannerMsg = '<strong>Read-Only Mode:</strong> Users with this role cannot edit clinical content directly. Any required data corrections must be requested via Queries.';
        } else {
            bannerMsg = '<strong>Form Locked:</strong> This record has been submitted for SDR and is locked from direct edits. Revoke SDR or manage queries to adjust content.';
        }

        const banner = document.createElement('div');
        banner.id = 'cfReadOnlyNotice';
        banner.style.cssText = `background: ${bannerBg}; border: 1px solid ${bannerBorder}; color: ${bannerColor}; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1.25rem; font-size: 0.85rem; display: flex; align-items: center; gap: 0.6rem;`;
        banner.innerHTML = `<span class="material-icons-round" style="color: ${bannerColor}; font-size: 1.25rem;">${bannerIcon}</span><span>${bannerMsg}</span>`;
        formBody.insertBefore(banner, formBody.firstChild);

        // Disable all inputs, selects, textareas inside cfEditorBody
        const inputs = formBody.querySelectorAll('input, select, textarea');
        inputs.forEach(el => {
            el.disabled = true;
            el.readOnly = true;
            el.style.backgroundColor = '#f8fafc';
            el.style.cursor = 'not-allowed';
        });

        // Hide mutation buttons inside formBody (e.g. + Add Target Lesion, Remove, + Add CM), but keep field query and navigation tab buttons active
        const subBtns = formBody.querySelectorAll('button');
        subBtns.forEach(btn => {
            if (btn.id !== 'btnCfAudit' && btn.id !== 'btnCfQuery' && !btn.classList.contains('btn-cf-field-query') && !btn.classList.contains('btn-usv-nav')) {
                btn.style.display = 'none';
            }
        });

        if (btnDraft) btnDraft.style.display = 'none';
        if (btnComplete) btnComplete.style.display = 'none';
    } else {
        if (btnDraft) btnDraft.style.display = '';
        if (btnComplete) btnComplete.style.display = '';
    }
}

function onTaAssessedChange() {
    const val = document.getElementById('inp_ta_tumor_assessed') ? document.getElementById('inp_ta_tumor_assessed').value : 'Yes';
    const notAssBox = document.getElementById('taNotAssessedReasonContainer');
    const detailsBox = document.getElementById('taAssessmentDetailsContainer');
    const tlBox = document.getElementById('taTargetLesionsContainer');
    const ntlBox = document.getElementById('taNonTargetContainer');
    const respBox = document.getElementById('taResponseContainer');

    if (val === 'No') {
        if (notAssBox) notAssBox.style.display = 'block';
        if (detailsBox) detailsBox.style.display = 'none';
        if (tlBox) tlBox.style.display = 'none';
        if (ntlBox) ntlBox.style.display = 'none';
        if (respBox) respBox.style.display = 'none';
    } else {
        if (notAssBox) notAssBox.style.display = 'none';
        if (detailsBox) detailsBox.style.display = 'block';
        if (tlBox) tlBox.style.display = 'block';
        if (ntlBox) ntlBox.style.display = 'block';
        if (respBox) respBox.style.display = 'block';
    }
}

function onTaModalityChange() {
    const val = document.getElementById('inp_ta_imaging_modality') ? document.getElementById('inp_ta_imaging_modality').value : '';
    const otherBox = document.getElementById('taModalityOtherContainer');
    if (otherBox) otherBox.style.display = (val === 'Other') ? 'block' : 'none';
}

function onTaTargetPresentChange() {
    const val = document.getElementById('inp_ta_target_present') ? document.getElementById('inp_ta_target_present').value : 'Yes';
    const box = document.getElementById('taTargetTableContainer');
    if (box) box.style.display = (val === 'Yes') ? 'block' : 'none';
}

function onTaNonTargetChange() {
    const val = document.getElementById('inp_ta_nontarget_present') ? document.getElementById('inp_ta_nontarget_present').value : 'Yes';
    const box = document.getElementById('taNonTargetTableContainer');
    if (box) box.style.display = (val === 'Yes') ? 'block' : 'none';
}

function onTaNewLesionsChange() {
    const val = document.getElementById('inp_ta_new_lesions') ? document.getElementById('inp_ta_new_lesions').value : 'No';
    const box = document.getElementById('taNewLesionsDetailsContainer');
    if (box) box.style.display = (val === 'Yes') ? 'block' : 'none';
}

function renderTaTlRowHtml(i, data = {}, canDelete = false) {
    const org = data.organ || '';
    const site = data.site || '';
    const axis = data.axis || 'Long';
    const dia = data.dia !== undefined && data.dia !== null ? data.dia : '';
    const st = data.status || 'Measured';

    return `
        <tr class="ta-tl-row" data-index="${i}" style="border-bottom: 1px solid #e2e8f0; transition: background 0.15s;">
            <td class="ta-tl-label" style="padding: 0.6rem 0.5rem; font-weight: 700; font-size: 0.85rem; color: #334155; white-space: nowrap;">TL ${i}</td>
            <td style="padding: 0.5rem;">
                <input type="text" id="inp_ta_tl${i}_organ" class="form-input ta-tl-organ" style="width: 100%; font-size: 0.85rem;" value="${escapeHtml(org)}" placeholder="e.g. Lung, Liver, Lymph Node">
            </td>
            <td style="padding: 0.5rem;">
                <input type="text" id="inp_ta_tl${i}_site" class="form-input ta-tl-site" style="width: 100%; font-size: 0.85rem;" value="${escapeHtml(site)}" placeholder="Anatomical location">
            </td>
            <td style="padding: 0.5rem; width: 160px;">
                <select id="inp_ta_tl${i}_axis" class="form-input ta-tl-axis" style="width: 100%; font-size: 0.85rem;">
                    <option value="Long" ${axis === 'Long' ? 'selected' : ''}>Long (Non-nodal)</option>
                    <option value="Short" ${axis === 'Short' ? 'selected' : ''}>Short (Nodal)</option>
                </select>
            </td>
            <td style="padding: 0.5rem; width: 110px;">
                <input type="number" step="0.1" id="inp_ta_tl${i}_longest_dia" class="form-input tl-dia-input ta-tl-dia" oninput="calcTaSumDia()" style="width: 100%; font-size: 0.85rem;" value="${escapeHtml(dia)}" placeholder="mm">
            </td>
            <td style="padding: 0.5rem; width: 160px;">
                <select id="inp_ta_tl${i}_status" class="form-input ta-tl-status" style="width: 100%; font-size: 0.85rem;">
                    <option value="Measured" ${st === 'Measured' ? 'selected' : ''}>Measured</option>
                    <option value="Absent" ${st === 'Absent' ? 'selected' : ''}>Absent</option>
                    <option value="Too small to measure" ${st === 'Too small to measure' ? 'selected' : ''}>Too small to measure</option>
                    <option value="Not assessed" ${st === 'Not assessed' ? 'selected' : ''}>Not assessed</option>
                </select>
            </td>
            <td style="padding: 0.5rem; width: 45px; text-align: center;">
                <button type="button" class="ta-tl-del-btn" onclick="removeTaTlRow(this)" title="Remove lesion" style="background: none; border: none; color: #ef4444; cursor: pointer; padding: 4px; border-radius: 4px; display: ${canDelete ? 'inline-flex' : 'none'}; align-items: center; justify-content: center; transition: all 0.2s;" onmouseover="this.style.background='#fee2e2'" onmouseout="this.style.background='none'">
                    <span class="material-icons-round" style="font-size: 1.2rem;">delete_outline</span>
                </button>
            </td>
        </tr>
    `;
}

function addTaTlRow() {
    const tbody = document.getElementById('taTlTbody');
    if (!tbody) return;
    const currentRows = tbody.querySelectorAll('tr.ta-tl-row');
    if (currentRows.length >= 5) {
        if (typeof showToast === 'function') {
            showToast('Maximum 5 target lesions allowed per RECIST 1.1', 'warning');
        } else {
            alert('Maximum 5 target lesions allowed per RECIST 1.1');
        }
        return;
    }
    const nextIndex = currentRows.length + 1;
    const tempDiv = document.createElement('tbody');
    tempDiv.innerHTML = renderTaTlRowHtml(nextIndex, {}, true);
    const newRow = tempDiv.firstElementChild;
    tbody.appendChild(newRow);

    reindexTaTlRows();
    calcTaSumDia();
}

function removeTaTlRow(btn) {
    const row = btn.closest('tr.ta-tl-row');
    const tbody = document.getElementById('taTlTbody');
    if (!row || !tbody) return;
    const currentRows = tbody.querySelectorAll('tr.ta-tl-row');
    if (currentRows.length <= 1) {
        if (typeof showToast === 'function') {
            showToast('At least 1 target lesion row is required', 'warning');
        }
        return;
    }
    row.remove();
    reindexTaTlRows();
    calcTaSumDia();
}

function reindexTaTlRows() {
    const tbody = document.getElementById('taTlTbody');
    if (!tbody) return;
    const rows = tbody.querySelectorAll('tr.ta-tl-row');
    const total = rows.length;

    rows.forEach((row, idx) => {
        const num = idx + 1;
        row.setAttribute('data-index', num);

        const lbl = row.querySelector('.ta-tl-label');
        if (lbl) lbl.textContent = `TL ${num}`;

        const inpOrgan = row.querySelector('.ta-tl-organ');
        if (inpOrgan) inpOrgan.id = `inp_ta_tl${num}_organ`;

        const inpSite = row.querySelector('.ta-tl-site');
        if (inpSite) inpSite.id = `inp_ta_tl${num}_site`;

        const inpAxis = row.querySelector('.ta-tl-axis');
        if (inpAxis) inpAxis.id = `inp_ta_tl${num}_axis`;

        const inpDia = row.querySelector('.ta-tl-dia');
        if (inpDia) inpDia.id = `inp_ta_tl${num}_longest_dia`;

        const inpStatus = row.querySelector('.ta-tl-status');
        if (inpStatus) inpStatus.id = `inp_ta_tl${num}_status`;

        const delBtn = row.querySelector('.ta-tl-del-btn');
        if (delBtn) {
            delBtn.style.display = total > 1 ? 'inline-flex' : 'none';
        }
    });

    const addBtn = document.getElementById('btnAddTaTlRow');
    const countBadge = document.getElementById('taTlCountBadge');
    if (addBtn) {
        if (total >= 5) {
            addBtn.disabled = true;
            addBtn.style.opacity = '0.5';
            addBtn.style.cursor = 'not-allowed';
            addBtn.title = 'Maximum 5 lesions reached';
        } else {
            addBtn.disabled = false;
            addBtn.style.opacity = '1';
            addBtn.style.cursor = 'pointer';
            addBtn.title = 'Add target lesion';
        }
    }
    if (countBadge) {
        countBadge.textContent = `(${total} of max 5 lesions)`;
    }
}

function calcTaSumDia() {
    let sum = 0;
    let any = false;
    document.querySelectorAll('.tl-dia-input').forEach(inp => {
        const v = parseFloat(inp.value);
        if (!isNaN(v) && v >= 0) {
            sum += v;
            any = true;
        }
    });
    const sumEl = document.getElementById('inp_ta_sum_longest_dia');
    if (sumEl) {
        sumEl.value = any ? sum.toFixed(1) : '0.0';
    }
}

function renderTaNtlRowHtml(i, data = {}, canDelete = false) {
    const org = data.organ || '';
    const site = data.site || '';
    const st = data.status || 'Present';

    return `
        <tr class="ta-ntl-row" data-index="${i}" style="border-bottom: 1px solid #e2e8f0; transition: background 0.15s;">
            <td class="ta-ntl-label" style="padding: 0.6rem 0.5rem; font-weight: 700; font-size: 0.85rem; color: #334155; white-space: nowrap;">NTL ${i}</td>
            <td style="padding: 0.5rem;">
                <input type="text" id="inp_ta_ntl${i}_organ" class="form-input ta-ntl-organ" style="width: 100%; font-size: 0.85rem;" value="${escapeHtml(org)}" placeholder="e.g. Bone, Peritoneum">
            </td>
            <td style="padding: 0.5rem;">
                <input type="text" id="inp_ta_ntl${i}_site" class="form-input ta-ntl-site" style="width: 100%; font-size: 0.85rem;" value="${escapeHtml(site)}" placeholder="Anatomical location">
            </td>
            <td style="padding: 0.5rem; width: 200px;">
                <select id="inp_ta_ntl${i}_status" class="form-input ta-ntl-status" style="width: 100%; font-size: 0.85rem;">
                    <option value="Present" ${st === 'Present' ? 'selected' : ''}>Present</option>
                    <option value="Absent" ${st === 'Absent' ? 'selected' : ''}>Absent</option>
                    <option value="Unequivocal progression" ${st === 'Unequivocal progression' ? 'selected' : ''}>Unequivocal progression</option>
                    <option value="Not assessed" ${st === 'Not assessed' ? 'selected' : ''}>Not assessed</option>
                </select>
            </td>
            <td style="padding: 0.5rem; width: 45px; text-align: center;">
                <button type="button" class="ta-ntl-del-btn" onclick="removeTaNtlRow(this)" title="Remove lesion" style="background: none; border: none; color: #ef4444; cursor: pointer; padding: 4px; border-radius: 4px; display: ${canDelete ? 'inline-flex' : 'none'}; align-items: center; justify-content: center; transition: all 0.2s;" onmouseover="this.style.background='#fee2e2'" onmouseout="this.style.background='none'">
                    <span class="material-icons-round" style="font-size: 1.2rem;">delete_outline</span>
                </button>
            </td>
        </tr>
    `;
}

function addTaNtlRow() {
    const tbody = document.getElementById('taNtlTbody');
    if (!tbody) return;
    const currentRows = tbody.querySelectorAll('tr.ta-ntl-row');
    if (currentRows.length >= 5) {
        if (typeof showToast === 'function') {
            showToast('Maximum 5 non-target lesions allowed', 'warning');
        } else {
            alert('Maximum 5 non-target lesions allowed');
        }
        return;
    }
    const nextIndex = currentRows.length + 1;
    const tempDiv = document.createElement('tbody');
    tempDiv.innerHTML = renderTaNtlRowHtml(nextIndex, {}, true);
    const newRow = tempDiv.firstElementChild;
    tbody.appendChild(newRow);

    reindexTaNtlRows();
}

function removeTaNtlRow(btn) {
    const row = btn.closest('tr.ta-ntl-row');
    const tbody = document.getElementById('taNtlTbody');
    if (!row || !tbody) return;
    const currentRows = tbody.querySelectorAll('tr.ta-ntl-row');
    if (currentRows.length <= 1) {
        if (typeof showToast === 'function') {
            showToast('At least 1 non-target lesion row is required', 'warning');
        }
        return;
    }
    row.remove();
    reindexTaNtlRows();
}

function reindexTaNtlRows() {
    const tbody = document.getElementById('taNtlTbody');
    if (!tbody) return;
    const rows = tbody.querySelectorAll('tr.ta-ntl-row');
    const total = rows.length;

    rows.forEach((row, idx) => {
        const num = idx + 1;
        row.setAttribute('data-index', num);

        const lbl = row.querySelector('.ta-ntl-label');
        if (lbl) lbl.textContent = `NTL ${num}`;

        const inpOrgan = row.querySelector('.ta-ntl-organ');
        if (inpOrgan) inpOrgan.id = `inp_ta_ntl${num}_organ`;

        const inpSite = row.querySelector('.ta-ntl-site');
        if (inpSite) inpSite.id = `inp_ta_ntl${num}_site`;

        const inpStatus = row.querySelector('.ta-ntl-status');
        if (inpStatus) inpStatus.id = `inp_ta_ntl${num}_status`;

        const delBtn = row.querySelector('.ta-ntl-del-btn');
        if (delBtn) {
            delBtn.style.display = total > 1 ? 'inline-flex' : 'none';
        }
    });

    const addBtn = document.getElementById('btnAddTaNtlRow');
    const countBadge = document.getElementById('taNtlCountBadge');
    if (addBtn) {
        if (total >= 5) {
            addBtn.disabled = true;
            addBtn.style.opacity = '0.5';
            addBtn.style.cursor = 'not-allowed';
            addBtn.title = 'Maximum 5 lesions reached';
        } else {
            addBtn.disabled = false;
            addBtn.style.opacity = '1';
            addBtn.style.cursor = 'pointer';
            addBtn.title = 'Add non-target lesion';
        }
    }
    if (countBadge) {
        countBadge.textContent = `(${total} of max 5 lesions)`;
    }
}

// =========================================================================
// USV (UNSCHEDULED VISITS) - FORM BUILDER & EVENT HANDLERS
// =========================================================================
function switchUsvSection(secNum, btn) {
    document.querySelectorAll('.usv-section-card').forEach(el => el.style.display = 'none');
    const target = document.getElementById('usv_sec_' + secNum);
    if (target) target.style.display = 'block';

    document.querySelectorAll('.btn-usv-nav').forEach(b => {
        b.style.borderColor = '#cbd5e1';
        b.style.background = '#fff';
        b.style.color = '#475569';
        b.classList.remove('active');
    });

    if (btn) {
        btn.style.borderColor = '#0284c7';
        btn.style.background = '#e0f2fe';
        btn.style.color = '#0369a1';
        btn.classList.add('active');
    }
}

function onUsvVisitPerfChange() {
    const val = document.getElementById('inp_usv_visit_performed')?.value || 'Yes';
    const dateBox = document.getElementById('usvDateBox');
    const reasonBox = document.getElementById('usvReasonBox');
    const notPerfBox = document.getElementById('usvNotPerfBox');
    if (val === 'Yes') {
        if (dateBox) dateBox.style.display = 'block';
        if (reasonBox) reasonBox.style.display = 'block';
        if (notPerfBox) notPerfBox.style.display = 'none';
    } else {
        if (dateBox) dateBox.style.display = 'none';
        if (reasonBox) reasonBox.style.display = 'none';
        if (notPerfBox) notPerfBox.style.display = 'block';
    }
}

function onUsvReasonChange() {
    const val = document.getElementById('inp_usv_visit_reason_unscheduled')?.value || '';
    const otherBox = document.getElementById('usvReasonOtherBox');
    if (otherBox) otherBox.style.display = (val === 'Other') ? 'block' : 'none';
}

function onUsvNotPerfReasonChange() {
    const val = document.getElementById('inp_usv_visit_not_performed_reason')?.value || '';
    const deathBox = document.getElementById('usvDeathDateBox');
    if (deathBox) deathBox.style.display = (val === 'Death') ? 'block' : 'none';
}

function onUsvVitalsAssChange() {
    const val = document.getElementById('inp_usv_vitals_assessed')?.value || 'Yes';
    const notAssBox = document.getElementById('usvVitalsNotAssBox');
    const contentBox = document.getElementById('usvVitalsContentBox');
    if (val === 'Yes') {
        if (notAssBox) notAssBox.style.display = 'none';
        if (contentBox) contentBox.style.display = 'block';
    } else {
        if (notAssBox) notAssBox.style.display = 'block';
        if (contentBox) contentBox.style.display = 'none';
    }
}

function onUsvHemaCollChange() {
    const val = document.getElementById('inp_usv_hema_panel_collected')?.value || 'Yes';
    const notCollBox = document.getElementById('usvHemaNotCollBox');
    const contentBox = document.getElementById('usvHemaContentBox');
    if (val === 'Yes') {
        if (notCollBox) notCollBox.style.display = 'none';
        if (contentBox) contentBox.style.display = 'block';
    } else {
        if (notCollBox) notCollBox.style.display = 'block';
        if (contentBox) contentBox.style.display = 'none';
    }
}

function onUsvChemCollChange() {
    const val = document.getElementById('inp_usv_chem_panel_collected')?.value || 'Yes';
    const notCollBox = document.getElementById('usvChemNotCollBox');
    const contentBox = document.getElementById('usvChemContentBox');
    if (val === 'Yes') {
        if (notCollBox) notCollBox.style.display = 'none';
        if (contentBox) contentBox.style.display = 'block';
    } else {
        if (notCollBox) notCollBox.style.display = 'block';
        if (contentBox) contentBox.style.display = 'none';
    }
}

function onUsvDrugAdminChange() {
    const val = document.getElementById('inp_usv_drug_administered')?.value || 'Yes';
    const notAdminBox = document.getElementById('usvDrugNotAdminBox');
    const adminBox = document.getElementById('usvDrugAdminBox');
    if (val === 'Yes') {
        if (notAdminBox) notAdminBox.style.display = 'none';
        if (adminBox) adminBox.style.display = 'block';
    } else {
        if (notAdminBox) notAdminBox.style.display = 'block';
        if (adminBox) adminBox.style.display = 'none';
    }
}

function onUsvDrugRouteChange() {
    const val = document.getElementById('inp_usv_drug_route')?.value || '';
    const ivBox = document.getElementById('usvIvInfusionBox');
    if (ivBox) ivBox.style.display = (val === 'IV infusion') ? 'block' : 'none';
}

function onUsvFlagChange(prefix, code) {
    const flagVal = document.getElementById(`inp_usv_${prefix}_${code}_flag`)?.value || 'Normal';
    const clinSigBox = document.getElementById(`box_usv_${prefix}_${code}_clin_sig`);
    const dashBox = document.getElementById(`dash_usv_${prefix}_${code}_clin_sig`);
    const isAb = (flagVal === 'Low' || flagVal === 'High');
    if (clinSigBox) clinSigBox.style.display = isAb ? 'block' : 'none';
    if (dashBox) dashBox.style.display = isAb ? 'none' : 'block';
}

function renderUsvFormHtml(rec, d) {
    const today = getTodayIST();
    const nowTime = (new Date()).toTimeString().slice(0, 5);

    const visitPerf = d.visit_performed || 'Yes';
    const vitalsAss = d.vitals_assessed || 'Yes';
    const hemaColl = d.hema_panel_collected || 'Yes';
    const chemColl = d.chem_panel_collected || 'Yes';
    const drugAdmin = d.drug_administered || 'Yes';

    function labRow(prefix, code, name, unit, refLow, refHigh) {
        const val = d[`${prefix}_${code}_val`] || '';
        const flag = d[`${prefix}_${code}_flag`] || 'Normal';
        const clinSig = d[`${prefix}_${code}_clin_sig`] || 'No';
        const action = d[`${prefix}_${code}_action`] || '';
        const isAb = (flag === 'Low' || flag === 'High');

        return `
            <tr style="border-bottom: 1px solid #f1f5f9; font-size: 0.85rem;">
                <td style="padding: 0.6rem 0.75rem; font-weight: 600; color: #1e293b;">
                    ${name}
                    <div style="font-size: 0.7rem; color: #64748b; font-weight: normal;">Ref: ${refLow} – ${refHigh} ${unit}</div>
                </td>
                <td style="padding: 0.5rem 0.75rem; width: 140px;">
                    <div style="display: flex; align-items: center; gap: 4px;">
                        <input type="text" id="inp_usv_${prefix}_${code}_val" class="form-input" value="${escapeHtml(val)}" placeholder="${unit}" style="width: 100%; font-size: 0.85rem; padding: 0.35rem 0.5rem;">
                        ${renderCommonFieldHeader(rec, `${prefix}_${code}_val`, '', false)}
                    </div>
                </td>
                <td style="padding: 0.5rem 0.75rem; width: 130px;">
                    <select id="inp_usv_${prefix}_${code}_flag" onchange="onUsvFlagChange('${prefix}', '${code}')" class="form-input" style="width: 100%; font-size: 0.85rem; padding: 0.35rem 0.5rem;">
                        <option value="Normal" ${flag === 'Normal' ? 'selected' : ''}>Normal</option>
                        <option value="Low" ${flag === 'Low' ? 'selected' : ''}>Low</option>
                        <option value="High" ${flag === 'High' ? 'selected' : ''}>High</option>
                        <option value="Not provided" ${flag === 'Not provided' ? 'selected' : ''}>Not provided</option>
                    </select>
                </td>
                <td style="padding: 0.5rem 0.75rem; width: 110px;">
                    <div id="box_usv_${prefix}_${code}_clin_sig" style="display: ${isAb ? 'block' : 'none'};">
                        <select id="inp_usv_${prefix}_${code}_clin_sig" class="form-input" style="width: 100%; font-size: 0.85rem; padding: 0.35rem 0.5rem;">
                            <option value="No" ${clinSig === 'No' ? 'selected' : ''}>No</option>
                            <option value="Yes" ${clinSig === 'Yes' ? 'selected' : ''}>Yes (CS)</option>
                        </select>
                    </div>
                    <div id="dash_usv_${prefix}_${code}_clin_sig" style="display: ${isAb ? 'none' : 'block'}; color: #94a3b8; text-align: center;">—</div>
                </td>
                <td style="padding: 0.5rem 0.75rem;">
                    <input type="text" id="inp_usv_${prefix}_${code}_action" class="form-input" value="${escapeHtml(action)}" placeholder="Clinical comments / AE ref..." style="width: 100%; font-size: 0.85rem; padding: 0.35rem 0.5rem;">
                </td>
            </tr>
        `;
    }

    return `
        <div style="display: flex; flex-direction: column; gap: 1.25rem;">
            <!-- USV Section Tabs Navigation -->
            <div style="display: flex; gap: 0.5rem; overflow-x: auto; padding: 0.35rem 0.5rem; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;">
                <button type="button" class="btn-usv-nav active" onclick="switchUsvSection(1, this)" style="padding: 0.45rem 0.85rem; font-size: 0.8rem; font-weight: 600; border-radius: 6px; border: 1px solid #0284c7; background: #e0f2fe; color: #0369a1; cursor: pointer; display: flex; align-items: center; gap: 5px; white-space: nowrap;">
                    <span class="material-icons-round" style="font-size: 1rem;">event_available</span> 1. Visit Details
                </button>
                <button type="button" class="btn-usv-nav" onclick="switchUsvSection(2, this)" style="padding: 0.45rem 0.85rem; font-size: 0.8rem; font-weight: 600; border-radius: 6px; border: 1px solid #cbd5e1; background: #fff; color: #475569; cursor: pointer; display: flex; align-items: center; gap: 5px; white-space: nowrap;">
                    <span class="material-icons-round" style="font-size: 1rem;">favorite_border</span> 2. Vital Signs
                </button>
                <button type="button" class="btn-usv-nav" onclick="switchUsvSection(3, this)" style="padding: 0.45rem 0.85rem; font-size: 0.8rem; font-weight: 600; border-radius: 6px; border: 1px solid #cbd5e1; background: #fff; color: #475569; cursor: pointer; display: flex; align-items: center; gap: 5px; white-space: nowrap;">
                    <span class="material-icons-round" style="font-size: 1rem;">bloodtype</span> 3. Hematology
                </button>
                <button type="button" class="btn-usv-nav" onclick="switchUsvSection(4, this)" style="padding: 0.45rem 0.85rem; font-size: 0.8rem; font-weight: 600; border-radius: 6px; border: 1px solid #cbd5e1; background: #fff; color: #475569; cursor: pointer; display: flex; align-items: center; gap: 5px; white-space: nowrap;">
                    <span class="material-icons-round" style="font-size: 1rem;">science</span> 4. Chemistry
                </button>
                <button type="button" class="btn-usv-nav" onclick="switchUsvSection(5, this)" style="padding: 0.45rem 0.85rem; font-size: 0.8rem; font-weight: 600; border-radius: 6px; border: 1px solid #cbd5e1; background: #fff; color: #475569; cursor: pointer; display: flex; align-items: center; gap: 5px; white-space: nowrap;">
                    <span class="material-icons-round" style="font-size: 1rem;">medication</span> 5. Study Drug
                </button>
            </div>

            <!-- SECTION 1: VISIT DETAILS -->
            <div id="usv_sec_1" class="usv-section-card" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 1.25rem;">
                <div style="font-weight: 700; color: #1e293b; font-size: 1rem; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem; border-bottom: 1px solid #f1f5f9; padding-bottom: 0.75rem;">
                    <span class="material-icons-round" style="color: #0284c7;">event_available</span> Form 1: Visit Details
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                    <div>
                        ${renderCommonFieldHeader(rec, 'visit_performed', 'Was this Visit Performed?', true)}
                        <select id="inp_usv_visit_performed" onchange="onUsvVisitPerfChange()" class="form-input" style="width: 100%;">
                            <option value="Yes" ${visitPerf === 'Yes' ? 'selected' : ''}>Yes</option>
                            <option value="No" ${visitPerf === 'No' ? 'selected' : ''}>No</option>
                        </select>
                    </div>
                    <div id="usvDateBox" style="display: ${visitPerf === 'Yes' ? 'block' : 'none'};">
                        ${renderCommonFieldHeader(rec, 'date_of_visit', 'Date of Visit', true)}
                        <input type="date" id="inp_usv_date_of_visit" max="${today}" class="form-input" value="${escapeHtml(d.date_of_visit || '')}" style="width: 100%;">
                    </div>
                </div>

                <div id="usvReasonBox" style="display: ${visitPerf === 'Yes' ? 'block' : 'none'}; margin-bottom: 1rem;">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div>
                            ${renderCommonFieldHeader(rec, 'visit_reason_unscheduled', 'Reason for Unscheduled Visit', true)}
                            <select id="inp_usv_visit_reason_unscheduled" onchange="onUsvReasonChange()" class="form-input" style="width: 100%;">
                                <option value="">-- Select Reason --</option>
                                <option value="Adverse Event" ${d.visit_reason_unscheduled === 'Adverse Event' ? 'selected' : ''}>Adverse Event</option>
                                <option value="Abnormal Laboratory Result" ${d.visit_reason_unscheduled === 'Abnormal Laboratory Result' ? 'selected' : ''}>Abnormal Laboratory Result</option>
                                <option value="Disease Symptoms / Progression" ${d.visit_reason_unscheduled === 'Disease Symptoms / Progression' ? 'selected' : ''}>Disease Symptoms / Progression</option>
                                <option value="Subject Request / Concern" ${d.visit_reason_unscheduled === 'Subject Request / Concern' ? 'selected' : ''}>Subject Request / Concern</option>
                                <option value="Treatment Assessment" ${d.visit_reason_unscheduled === 'Treatment Assessment' ? 'selected' : ''}>Treatment Assessment</option>
                                <option value="Safety Follow-up" ${d.visit_reason_unscheduled === 'Safety Follow-up' ? 'selected' : ''}>Safety Follow-up</option>
                                <option value="Protocol Requirement" ${d.visit_reason_unscheduled === 'Protocol Requirement' ? 'selected' : ''}>Protocol Requirement</option>
                                <option value="Other" ${d.visit_reason_unscheduled === 'Other' ? 'selected' : ''}>Other</option>
                            </select>
                        </div>
                        <div id="usvReasonOtherBox" style="display: ${d.visit_reason_unscheduled === 'Other' ? 'block' : 'none'};">
                            ${renderCommonFieldHeader(rec, 'unscheduled_reason_other', 'Specify Other Reason', true)}
                            <input type="text" id="inp_usv_unscheduled_reason_other" class="form-input" value="${escapeHtml(d.unscheduled_reason_other || '')}" placeholder="Specify reason..." style="width: 100%;">
                        </div>
                    </div>
                </div>

                <div id="usvNotPerfBox" style="display: ${visitPerf === 'No' ? 'block' : 'none'}; margin-bottom: 1rem;">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div>
                            ${renderCommonFieldHeader(rec, 'visit_not_performed_reason', 'Primary reason visit was not performed', true)}
                            <select id="inp_usv_visit_not_performed_reason" onchange="onUsvNotPerfReasonChange()" class="form-input" style="width: 100%;">
                                <option value="">-- Select Reason --</option>
                                <option value="Subject missed visit" ${d.visit_not_performed_reason === 'Subject missed visit' ? 'selected' : ''}>Subject missed visit</option>
                                <option value="Subject refused / withdrew consent" ${d.visit_not_performed_reason === 'Subject refused / withdrew consent' ? 'selected' : ''}>Subject refused / withdrew consent</option>
                                <option value="Investigator decision" ${d.visit_not_performed_reason === 'Investigator decision' ? 'selected' : ''}>Investigator decision</option>
                                <option value="Technical / logistical failure" ${d.visit_not_performed_reason === 'Technical / logistical failure' ? 'selected' : ''}>Technical / logistical failure</option>
                                <option value="Death" ${d.visit_not_performed_reason === 'Death' ? 'selected' : ''}>Death</option>
                                <option value="Other" ${d.visit_not_performed_reason === 'Other' ? 'selected' : ''}>Other</option>
                            </select>
                        </div>
                        <div id="usvDeathDateBox" style="display: ${d.visit_not_performed_reason === 'Death' ? 'block' : 'none'};">
                            ${renderCommonFieldHeader(rec, 'date_of_death', 'Date of Death', true)}
                            <input type="date" id="inp_usv_date_of_death" max="${today}" class="form-input" value="${escapeHtml(d.date_of_death || '')}" style="width: 100%;">
                        </div>
                    </div>
                </div>

                <div>
                    ${renderCommonFieldHeader(rec, 'visit_comments', 'Visit Comments / Notes', false)}
                    <textarea id="inp_usv_visit_comments" class="form-input" rows="2" placeholder="Clinical observations / notes regarding this visit..." style="width: 100%; resize: vertical;">${escapeHtml(d.visit_comments || '')}</textarea>
                </div>
            </div>

            <!-- SECTION 2: VITAL SIGNS -->
            <div id="usv_sec_2" class="usv-section-card" style="display: none; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 1.25rem;">
                <div style="font-weight: 700; color: #1e293b; font-size: 1rem; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem; border-bottom: 1px solid #f1f5f9; padding-bottom: 0.75rem;">
                    <span class="material-icons-round" style="color: #059669;">favorite_border</span> Form 2: Pre-dose Vital Signs
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                    <div>
                        ${renderCommonFieldHeader(rec, 'vitals_assessed', 'Were vital signs assessed?', true)}
                        <select id="inp_usv_vitals_assessed" onchange="onUsvVitalsAssChange()" class="form-input" style="width: 100%;">
                            <option value="Yes" ${vitalsAss === 'Yes' ? 'selected' : ''}>Yes</option>
                            <option value="No" ${vitalsAss === 'No' ? 'selected' : ''}>No</option>
                        </select>
                    </div>
                    <div id="usvVitalsNotAssBox" style="display: ${vitalsAss === 'No' ? 'block' : 'none'};">
                        ${renderCommonFieldHeader(rec, 'vitals_not_assessed_reason', 'Reason vital signs not assessed', true)}
                        <select id="inp_usv_vitals_not_assessed_reason" class="form-input" style="width: 100%;">
                            <option value="">-- Select Reason --</option>
                            <option value="Subject declined" ${d.vitals_not_assessed_reason === 'Subject declined' ? 'selected' : ''}>Subject declined</option>
                            <option value="Equipment unavailable" ${d.vitals_not_assessed_reason === 'Equipment unavailable' ? 'selected' : ''}>Equipment unavailable</option>
                            <option value="Subject unable to undergo assessment" ${d.vitals_not_assessed_reason === 'Subject unable to undergo assessment' ? 'selected' : ''}>Subject unable to undergo assessment</option>
                            <option value="Other" ${d.vitals_not_assessed_reason === 'Other' ? 'selected' : ''}>Other</option>
                        </select>
                    </div>
                </div>

                <div id="usvVitalsContentBox" style="display: ${vitalsAss === 'Yes' ? 'block' : 'none'};">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.25rem;">
                        <div>
                            ${renderCommonFieldHeader(rec, 'vitals_assessment_date', 'Assessment Date', true)}
                            <input type="date" id="inp_usv_vitals_assessment_date" max="${today}" class="form-input" value="${escapeHtml(d.vitals_assessment_date || d.date_of_visit || '')}" style="width: 100%;">
                        </div>
                        <div>
                            ${renderCommonFieldHeader(rec, 'vitals_assessment_time', 'Assessment Time', true)}
                            <input type="time" id="inp_usv_vitals_assessment_time" class="form-input" value="${escapeHtml(d.vitals_assessment_time || nowTime)}" style="width: 100%;">
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 1rem;">
                        <div style="background: white; border: 1px solid #cbd5e1; border-radius: 6px; padding: 0.75rem;">
                            ${renderCommonFieldHeader(rec, 'sbp_val', 'Systolic BP (mmHg)', true)}
                            <input type="number" id="inp_usv_sbp_val" class="form-input" value="${escapeHtml(d.sbp_val || '')}" placeholder="e.g. 120" min="70" max="220" style="width: 100%;">
                            <div style="font-size: 0.7rem; color: #64748b; margin-top: 3px;">Range: 70 – 200 mmHg</div>
                        </div>

                        <div style="background: white; border: 1px solid #cbd5e1; border-radius: 6px; padding: 0.75rem;">
                            ${renderCommonFieldHeader(rec, 'dbp_val', 'Diastolic BP (mmHg)', true)}
                            <input type="number" id="inp_usv_dbp_val" class="form-input" value="${escapeHtml(d.dbp_val || '')}" placeholder="e.g. 80" min="40" max="140" style="width: 100%;">
                            <div style="font-size: 0.7rem; color: #64748b; margin-top: 3px;">Range: 40 – 130 mmHg</div>
                        </div>

                        <div style="background: white; border: 1px solid #cbd5e1; border-radius: 6px; padding: 0.75rem;">
                            ${renderCommonFieldHeader(rec, 'hr_val', 'Heart Rate (bpm)', true)}
                            <input type="number" id="inp_usv_hr_val" class="form-input" value="${escapeHtml(d.hr_val || '')}" placeholder="e.g. 72" min="40" max="200" style="width: 100%;">
                            <div style="font-size: 0.7rem; color: #64748b; margin-top: 3px;">Range: 40 – 180 bpm</div>
                        </div>

                        <div style="background: white; border: 1px solid #cbd5e1; border-radius: 6px; padding: 0.75rem;">
                            ${renderCommonFieldHeader(rec, 'rr_val', 'Resp. Rate (breaths/min)', true)}
                            <input type="number" id="inp_usv_rr_val" class="form-input" value="${escapeHtml(d.rr_val || '')}" placeholder="e.g. 16" min="8" max="50" style="width: 100%;">
                            <div style="font-size: 0.7rem; color: #64748b; margin-top: 3px;">Range: 8 – 40 /min</div>
                        </div>

                        <div style="background: white; border: 1px solid #cbd5e1; border-radius: 6px; padding: 0.75rem;">
                            ${renderCommonFieldHeader(rec, 'temp_val', 'Body Temp (°C)', true)}
                            <input type="number" step="0.1" id="inp_usv_temp_val" class="form-input" value="${escapeHtml(d.temp_val || '')}" placeholder="e.g. 36.8" min="34" max="43" style="width: 100%;">
                            <div style="font-size: 0.7rem; color: #64748b; margin-top: 3px;">Range: 35.0 – 42.0 °C</div>
                        </div>

                        <div style="background: white; border: 1px solid #cbd5e1; border-radius: 6px; padding: 0.75rem;">
                            ${renderCommonFieldHeader(rec, 'weight_val', 'Body Weight (kg)', false)}
                            <input type="number" step="0.1" id="inp_usv_weight_val" class="form-input" value="${escapeHtml(d.weight_val || '')}" placeholder="e.g. 70.5" style="width: 100%;">
                        </div>

                        <div style="background: white; border: 1px solid #cbd5e1; border-radius: 6px; padding: 0.75rem;">
                            ${renderCommonFieldHeader(rec, 'height_val', 'Height (cm)', false)}
                            <input type="number" step="0.1" id="inp_usv_height_val" class="form-input" value="${escapeHtml(d.height_val || '')}" placeholder="e.g. 172.0" style="width: 100%;">
                        </div>
                    </div>
                </div>
            </div>

            <!-- SECTION 3: HEMATOLOGY -->
            <div id="usv_sec_3" class="usv-section-card" style="display: none; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 1.25rem;">
                <div style="font-weight: 700; color: #1e293b; font-size: 1rem; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem; border-bottom: 1px solid #f1f5f9; padding-bottom: 0.75rem;">
                    <span class="material-icons-round" style="color: #dc2626;">bloodtype</span> Form 3: Laboratory Assessment — Hematology
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                    <div>
                        ${renderCommonFieldHeader(rec, 'hema_panel_collected', 'Was Hematology sample collected?', true)}
                        <select id="inp_usv_hema_panel_collected" onchange="onUsvHemaCollChange()" class="form-input" style="width: 100%;">
                            <option value="Yes" ${hemaColl === 'Yes' ? 'selected' : ''}>Yes</option>
                            <option value="No" ${hemaColl === 'No' ? 'selected' : ''}>No</option>
                        </select>
                    </div>
                    <div id="usvHemaNotCollBox" style="display: ${hemaColl === 'No' ? 'block' : 'none'};">
                        ${renderCommonFieldHeader(rec, 'hema_not_collected_reason', 'Reason sample not collected', true)}
                        <select id="inp_usv_hema_not_collected_reason" class="form-input" style="width: 100%;">
                            <option value="">-- Select Reason --</option>
                            <option value="Subject unavailable" ${d.hema_not_collected_reason === 'Subject unavailable' ? 'selected' : ''}>Subject unavailable</option>
                            <option value="Subject declined" ${d.hema_not_collected_reason === 'Subject declined' ? 'selected' : ''}>Subject declined</option>
                            <option value="Vein access failed" ${d.hema_not_collected_reason === 'Vein access failed' ? 'selected' : ''}>Vein access failed</option>
                            <option value="Equipment unavailable" ${d.hema_not_collected_reason === 'Equipment unavailable' ? 'selected' : ''}>Equipment unavailable</option>
                            <option value="Other" ${d.hema_not_collected_reason === 'Other' ? 'selected' : ''}>Other</option>
                        </select>
                    </div>
                </div>

                <div id="usvHemaContentBox" style="display: ${hemaColl === 'Yes' ? 'block' : 'none'};">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                        <div>
                            ${renderCommonFieldHeader(rec, 'hema_collection_date', 'Sample Collection Date', true)}
                            <input type="date" id="inp_usv_hema_collection_date" max="${today}" class="form-input" value="${escapeHtml(d.hema_collection_date || d.date_of_visit || '')}" style="width: 100%;">
                        </div>
                        <div>
                            ${renderCommonFieldHeader(rec, 'hema_collection_time', 'Sample Collection Time', true)}
                            <input type="time" id="inp_usv_hema_collection_time" class="form-input" value="${escapeHtml(d.hema_collection_time || nowTime)}" style="width: 100%;">
                        </div>
                    </div>

                    <table style="width: 100%; border-collapse: collapse; background: white; border: 1px solid #cbd5e1; border-radius: 6px; overflow: hidden;">
                        <thead style="background: #f8fafc; font-size: 0.8rem; color: #475569; border-bottom: 1px solid #cbd5e1;">
                            <tr>
                                <th style="padding: 0.6rem 0.75rem; text-align: left;">Hematology Test</th>
                                <th style="padding: 0.6rem 0.75rem; text-align: left; width: 140px;">Result</th>
                                <th style="padding: 0.6rem 0.75rem; text-align: left; width: 130px;">Flag</th>
                                <th style="padding: 0.6rem 0.75rem; text-align: center; width: 110px;">Clin. Sig.?</th>
                                <th style="padding: 0.6rem 0.75rem; text-align: left;">Comment / Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${labRow('hema', 'hgb', 'Hemoglobin (HGB)', 'g/dL', '12.0', '17.5')}
                            ${labRow('hema', 'hct', 'Hematocrit (HCT)', '%', '36.0', '50.0')}
                            ${labRow('hema', 'rbc', 'Red Blood Cells (RBC)', '10^6/µL', '4.2', '5.9')}
                            ${labRow('hema', 'wbc', 'White Blood Cells (WBC)', '10^3/µL', '4.5', '11.0')}
                            ${labRow('hema', 'anc', 'Absolute Neutrophils (ANC)', '10^3/µL', '1.5', '8.0')}
                            ${labRow('hema', 'plt', 'Platelet Count (PLT)', '10^3/µL', '150', '450')}
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- SECTION 4: CHEMISTRY -->
            <div id="usv_sec_4" class="usv-section-card" style="display: none; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 1.25rem;">
                <div style="font-weight: 700; color: #1e293b; font-size: 1rem; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem; border-bottom: 1px solid #f1f5f9; padding-bottom: 0.75rem;">
                    <span class="material-icons-round" style="color: #8b5cf6;">science</span> Form 4: Laboratory Assessment — Chemistry
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                    <div>
                        ${renderCommonFieldHeader(rec, 'chem_panel_collected', 'Was Chemistry sample collected?', true)}
                        <select id="inp_usv_chem_panel_collected" onchange="onUsvChemCollChange()" class="form-input" style="width: 100%;">
                            <option value="Yes" ${chemColl === 'Yes' ? 'selected' : ''}>Yes</option>
                            <option value="No" ${chemColl === 'No' ? 'selected' : ''}>No</option>
                        </select>
                    </div>
                    <div id="usvChemNotCollBox" style="display: ${chemColl === 'No' ? 'block' : 'none'};">
                        ${renderCommonFieldHeader(rec, 'chem_not_collected_reason', 'Reason sample not collected', true)}
                        <select id="inp_usv_chem_not_collected_reason" class="form-input" style="width: 100%;">
                            <option value="">-- Select Reason --</option>
                            <option value="Subject unavailable" ${d.chem_not_collected_reason === 'Subject unavailable' ? 'selected' : ''}>Subject unavailable</option>
                            <option value="Subject declined" ${d.chem_not_collected_reason === 'Subject declined' ? 'selected' : ''}>Subject declined</option>
                            <option value="Vein access failed" ${d.chem_not_collected_reason === 'Vein access failed' ? 'selected' : ''}>Vein access failed</option>
                            <option value="Equipment unavailable" ${d.chem_not_collected_reason === 'Equipment unavailable' ? 'selected' : ''}>Equipment unavailable</option>
                            <option value="Other" ${d.chem_not_collected_reason === 'Other' ? 'selected' : ''}>Other</option>
                        </select>
                    </div>
                </div>

                <div id="usvChemContentBox" style="display: ${chemColl === 'Yes' ? 'block' : 'none'};">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                        <div>
                            ${renderCommonFieldHeader(rec, 'chem_collection_date', 'Sample Collection Date', true)}
                            <input type="date" id="inp_usv_chem_collection_date" max="${today}" class="form-input" value="${escapeHtml(d.chem_collection_date || d.date_of_visit || '')}" style="width: 100%;">
                        </div>
                        <div>
                            ${renderCommonFieldHeader(rec, 'chem_collection_time', 'Sample Collection Time', true)}
                            <input type="time" id="inp_usv_chem_collection_time" class="form-input" value="${escapeHtml(d.chem_collection_time || nowTime)}" style="width: 100%;">
                        </div>
                    </div>

                    <table style="width: 100%; border-collapse: collapse; background: white; border: 1px solid #cbd5e1; border-radius: 6px; overflow: hidden;">
                        <thead style="background: #f8fafc; font-size: 0.8rem; color: #475569; border-bottom: 1px solid #cbd5e1;">
                            <tr>
                                <th style="padding: 0.6rem 0.75rem; text-align: left;">Chemistry Test</th>
                                <th style="padding: 0.6rem 0.75rem; text-align: left; width: 140px;">Result</th>
                                <th style="padding: 0.6rem 0.75rem; text-align: left; width: 130px;">Flag</th>
                                <th style="padding: 0.6rem 0.75rem; text-align: center; width: 110px;">Clin. Sig.?</th>
                                <th style="padding: 0.6rem 0.75rem; text-align: left;">Comment / Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${labRow('chem', 'sod', 'Sodium (Na)', 'mmol/L', '135', '145')}
                            ${labRow('chem', 'pot', 'Potassium (K)', 'mmol/L', '3.5', '5.0')}
                            ${labRow('chem', 'glu', 'Glucose', 'mg/dL', '70', '100')}
                            ${labRow('chem', 'cal', 'Calcium', 'mg/dL', '8.5', '10.5')}
                            ${labRow('chem', 'alb', 'Albumin', 'g/dL', '3.5', '5.5')}
                            ${labRow('chem', 'tp', 'Total Protein', 'g/dL', '6.0', '8.3')}
                            ${labRow('chem', 'alt', 'Alanine Aminotransferase (ALT / SGPT)', 'U/L', '7', '56')}
                            ${labRow('chem', 'ast', 'Aspartate Aminotransferase (AST / SGOT)', 'U/L', '10', '40')}
                            ${labRow('chem', 'tbil', 'Total Bilirubin', 'mg/dL', '0.1', '1.2')}
                            ${labRow('chem', 'creat', 'Serum Creatinine', 'mg/dL', '0.6', '1.2')}
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- SECTION 5: STUDY DRUG ADMINISTRATION -->
            <div id="usv_sec_5" class="usv-section-card" style="display: none; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 1.25rem;">
                <div style="font-weight: 700; color: #1e293b; font-size: 1rem; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem; border-bottom: 1px solid #f1f5f9; padding-bottom: 0.75rem;">
                    <span class="material-icons-round" style="color: #6366f1;">medication</span> Form 5: Study Drug Administration
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                    <div>
                        ${renderCommonFieldHeader(rec, 'drug_administered', 'Was study treatment administered?', true)}
                        <select id="inp_usv_drug_administered" onchange="onUsvDrugAdminChange()" class="form-input" style="width: 100%;">
                            <option value="Yes" ${drugAdmin === 'Yes' ? 'selected' : ''}>Yes</option>
                            <option value="No" ${drugAdmin === 'No' ? 'selected' : ''}>No</option>
                        </select>
                    </div>
                    <div id="usvDrugNotAdminBox" style="display: ${drugAdmin === 'No' ? 'block' : 'none'};">
                        ${renderCommonFieldHeader(rec, 'drug_not_administered_reason', 'Reason treatment not administered', true)}
                        <select id="inp_usv_drug_not_administered_reason" class="form-input" style="width: 100%;">
                            <option value="">-- Select Reason --</option>
                            <option value="Adverse event" ${d.drug_not_administered_reason === 'Adverse event' ? 'selected' : ''}>Adverse event</option>
                            <option value="Laboratory finding" ${d.drug_not_administered_reason === 'Laboratory finding' ? 'selected' : ''}>Laboratory finding</option>
                            <option value="Subject declined" ${d.drug_not_administered_reason === 'Subject declined' ? 'selected' : ''}>Subject declined</option>
                            <option value="Treatment unavailable" ${d.drug_not_administered_reason === 'Treatment unavailable' ? 'selected' : ''}>Treatment unavailable</option>
                            <option value="Investigator decision" ${d.drug_not_administered_reason === 'Investigator decision' ? 'selected' : ''}>Investigator decision</option>
                            <option value="Other" ${d.drug_not_administered_reason === 'Other' ? 'selected' : ''}>Other</option>
                        </select>
                    </div>
                </div>

                <div id="usvDrugAdminBox" style="display: ${drugAdmin === 'Yes' ? 'block' : 'none'};">
                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                        <div>
                            ${renderCommonFieldHeader(rec, 'drug_name', 'Study Treatment Name / Code', true)}
                            <input type="text" id="inp_usv_drug_name" class="form-input" value="${escapeHtml(d.drug_name || 'IMP-2026')}" style="width: 100%;">
                        </div>
                        <div>
                            ${renderCommonFieldHeader(rec, 'drug_admin_date', 'Administration Date', true)}
                            <input type="date" id="inp_usv_drug_admin_date" max="${today}" class="form-input" value="${escapeHtml(d.drug_admin_date || d.date_of_visit || '')}" style="width: 100%;">
                        </div>
                        <div>
                            ${renderCommonFieldHeader(rec, 'drug_admin_start_time', 'Start Time', true)}
                            <input type="time" id="inp_usv_drug_admin_start_time" class="form-input" value="${escapeHtml(d.drug_admin_start_time || nowTime)}" style="width: 100%;">
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                        <div>
                            ${renderCommonFieldHeader(rec, 'drug_actual_dose', 'Actual Dose Administered', true)}
                            <input type="text" id="inp_usv_drug_actual_dose" class="form-input" value="${escapeHtml(d.drug_actual_dose || '100.0')}" style="width: 100%;">
                        </div>
                        <div>
                            ${renderCommonFieldHeader(rec, 'drug_dose_unit', 'Dose Unit', true)}
                            <select id="inp_usv_drug_dose_unit" class="form-input" style="width: 100%;">
                                <option value="mg" ${d.drug_dose_unit === 'mg' || !d.drug_dose_unit ? 'selected' : ''}>mg</option>
                                <option value="mg/kg" ${d.drug_dose_unit === 'mg/kg' ? 'selected' : ''}>mg/kg</option>
                                <option value="mg/m²" ${d.drug_dose_unit === 'mg/m²' ? 'selected' : ''}>mg/m²</option>
                                <option value="mL" ${d.drug_dose_unit === 'mL' ? 'selected' : ''}>mL</option>
                                <option value="mcg" ${d.drug_dose_unit === 'mcg' ? 'selected' : ''}>mcg</option>
                            </select>
                        </div>
                        <div>
                            ${renderCommonFieldHeader(rec, 'drug_route', 'Route of Administration', true)}
                            <select id="inp_usv_drug_route" onchange="onUsvDrugRouteChange()" class="form-input" style="width: 100%;">
                                <option value="Oral" ${d.drug_route === 'Oral' ? 'selected' : ''}>Oral</option>
                                <option value="IV infusion" ${d.drug_route === 'IV infusion' ? 'selected' : ''}>IV infusion</option>
                                <option value="Subcutaneous" ${d.drug_route === 'Subcutaneous' ? 'selected' : ''}>Subcutaneous</option>
                                <option value="Other" ${d.drug_route === 'Other' ? 'selected' : ''}>Other</option>
                            </select>
                        </div>
                    </div>

                    <div id="usvIvInfusionBox" style="display: ${d.drug_route === 'IV infusion' ? 'block' : 'none'}; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 0.85rem; margin-bottom: 1rem;">
                        <div style="font-weight: 600; font-size: 0.85rem; color: #334155; margin-bottom: 0.5rem;">IV Infusion Details</div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                            <div>
                                ${renderCommonFieldHeader(rec, 'iv_end_datetime', 'Infusion End Date / Time', false)}
                                <input type="datetime-local" id="inp_usv_iv_end_datetime" class="form-input" value="${escapeHtml(d.iv_end_datetime || '')}" style="width: 100%;">
                            </div>
                            <div>
                                ${renderCommonFieldHeader(rec, 'iv_interrupted', 'Was infusion interrupted?', false)}
                                <select id="inp_usv_iv_interrupted" class="form-input" style="width: 100%;">
                                    <option value="No" ${d.iv_interrupted === 'No' || !d.iv_interrupted ? 'selected' : ''}>No</option>
                                    <option value="Yes" ${d.iv_interrupted === 'Yes' ? 'selected' : ''}>Yes</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div>
                        ${renderCommonFieldHeader(rec, 'drug_comments', 'Dose Comments / Notes', false)}
                        <textarea id="inp_usv_drug_comments" class="form-input" rows="2" placeholder="Administration notes..." style="width: 100%; resize: vertical;">${escapeHtml(d.drug_comments || '')}</textarea>
                    </div>
                </div>
            </div>
        </div>
    `;
}

function renderAeCritCb(val, label, selectedList) {

    const isChecked = selectedList.includes(val) ? 'checked' : '';
    return `
        <label style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.85rem; color: #431407; font-weight: 500; cursor: pointer;">
            <input type="checkbox" class="cb_ae_crit" value="${escapeHtml(val)}" ${isChecked}>
            ${escapeHtml(label)}
        </label>
    `;
}

// MH Dynamic Handlers
function onMHOngoingChange() {
    const ongoing = document.getElementById('inp_mh_ongoing') ? document.getElementById('inp_mh_ongoing').value : '';
    const endDateInp = document.getElementById('inp_mh_end_date');
    const lblEndDate = document.getElementById('lbl_mh_end_date');

    if (!endDateInp) return;

    if (ongoing === 'Yes') {
        endDateInp.value = '';
        endDateInp.disabled = true;
        if (lblEndDate) lblEndDate.innerHTML = `4. End Date <span style="font-size:0.75rem; color:#94a3b8;">(Disabled when Ongoing)</span>`;
    } else {
        endDateInp.disabled = false;
        if (lblEndDate) lblEndDate.innerHTML = `4. End Date <span style="color: #dc2626;">*</span>`;
    }
}

function onMHDateChange() {
    const st = document.getElementById('inp_mh_start_date') ? document.getElementById('inp_mh_start_date').value : '';
    const et = document.getElementById('inp_mh_end_date') ? document.getElementById('inp_mh_end_date').value : '';
    if (st && et && et < st) {
        showToast('End Date cannot precede Start Date.', 'warning');
    }
}

function onMHMedGivenChange() {
    const mg = document.getElementById('inp_mh_medication_given') ? document.getElementById('inp_mh_medication_given').value : '';
    const box = document.getElementById('mhCmShortcutBox');
    if (box) box.style.display = (mg === 'Yes' && currentEditingRecordData && currentEditingRecordData.id > 0) ? 'block' : 'none';
}

// AE Dynamic Handlers
function onAESeriousnessChange() {
    const ser = document.getElementById('inp_ae_seriousness') ? document.getElementById('inp_ae_seriousness').value : '';
    const box = document.getElementById('aeSeriousnessCriteriaBox');
    if (box) box.style.display = (ser === 'Yes') ? 'block' : 'none';
}

function onAEOutcomeChange() {
    const out = document.getElementById('inp_ae_outcome') ? document.getElementById('inp_ae_outcome').value : '';
    const endDateInp = document.getElementById('inp_ae_end_date');

    if (!endDateInp) return;

    if (out === 'Recovered / Resolved') {
        endDateInp.disabled = false;
    } else if (out === 'Recovering / Resolving' || out === 'Not Recovered / Not Resolved') {
        endDateInp.value = '';
        endDateInp.disabled = true;
    } else {
        endDateInp.disabled = false;
    }
}

function onAEConcomitantTreatmentChange() {
    const ct = document.getElementById('inp_ae_concomitant_treatment') ? document.getElementById('inp_ae_concomitant_treatment').value : '';
    const box = document.getElementById('aeCmShortcutBox');
    if (box) box.style.display = (ct === 'Yes' && currentEditingRecordData && currentEditingRecordData.id > 0) ? 'block' : 'none';
}

// CM Dynamic Handlers
let previousCmIndicationType = '';
function onCMIndicationChange() {
    const selType = document.getElementById('inp_cm_indication_type') ? document.getElementById('inp_cm_indication_type').value : '';
    const linkedBox = document.getElementById('cmLinkedContainer');
    const otherBox = document.getElementById('cmSpecifyOtherContainer');

    if (previousCmIndicationType && previousCmIndicationType !== selType) {
        const checkedCbs = document.querySelectorAll('.cb_cm_linked:checked');
        if (checkedCbs.length > 0) {
            if (!confirm(`Changing Medication Taken For from ${previousCmIndicationType} to ${selType || 'Other'} will clear existing linked record selections. Continue?`)) {
                document.getElementById('inp_cm_indication_type').value = previousCmIndicationType;
                return;
            }
        }
    }
    previousCmIndicationType = selType;

    if (selType === 'Other') {
        if (linkedBox) linkedBox.style.display = 'none';
        if (otherBox) otherBox.style.display = 'block';
    } else if (selType === 'MH' || selType === 'AE') {
        if (otherBox) otherBox.style.display = 'none';
        if (linkedBox) linkedBox.style.display = 'block';
        loadCMLinkableRecords(selType, []);
    } else {
        if (linkedBox) linkedBox.style.display = 'none';
        if (otherBox) otherBox.style.display = 'none';
    }
}

function loadCMLinkableRecords(targetType, existingLinks = []) {
    const listEl = document.getElementById('cmLinkedRecordsList');
    const lblEl = document.getElementById('lblCmLinkedTarget');
    if (!listEl) return;

    if (lblEl) lblEl.innerHTML = `5. Select Active ${targetType} Records to Link <span style="color: #dc2626;">*</span>`;

    const fd = new FormData();
    fd.append('action', 'get_linkable_records');
    fd.append('subject_id', CURRENT_SUBJECT_ID);
    fd.append('target_type', targetType);

    fetch('ajax_common_forms.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
        if (!res.success) {
            listEl.innerHTML = `<span style="color: #ef4444;">Error loading linkable records</span>`;
            return;
        }

        currentLinkableRecords = res.linkable || [];
        if (currentLinkableRecords.length === 0) {
            listEl.innerHTML = `
                <div style="padding: 1rem; background: #fff; border: 1px dashed #cbd5e1; border-radius: 6px; text-align: center; color: #64748b;">
                    <p style="margin: 0 0 0.5rem 0; font-size: 0.875rem;">No active ${targetType} records found for Subject ${CURRENT_SUBJECT_ID}.</p>
                    ${USER_IS_COORDINATOR || USER_IS_ADMIN ? `<button type="button" class="btn btn-sm btn-outline" onclick="shortcutAddSourceRecord('${targetType}')">+ Add ${targetType} Record Now</button>` : ''}
                </div>
            `;
            return;
        }

        const selectedIds = existingLinks.map(l => l.id);

        let html = '';
        currentLinkableRecords.forEach(rec => {
            const isChecked = selectedIds.includes(rec.id) ? 'checked' : '';
            html += `
                <label style="display: flex; align-items: center; gap: 0.6rem; padding: 0.5rem 0.75rem; background: white; border: 1px solid #e2e8f0; border-radius: 6px; cursor: pointer;">
                    <input type="checkbox" class="cb_cm_linked" value="${rec.id}" ${isChecked}>
                    <span style="font-weight: 700; color: #1e293b;">${escapeHtml(rec.record_number)}</span>
                    <span style="color: #475569;">${escapeHtml(rec.term)}</span>
                </label>
            `;
        });
        listEl.innerHTML = html;
    })
    .catch(err => {
        console.error(err);
        listEl.innerHTML = `<span style="color: #ef4444;">Error fetching linkable records</span>`;
    });
}

function shortcutAddSourceRecord(targetType) {
    closeModal('modalCommonFormEditor');
    setTimeout(() => {
        window.location.href = `subject_data_entry.php?subject_id=${CURRENT_SUBJECT_ID}&common_form=${targetType.toLowerCase()}`;
    }, 200);
}

function shortcutAddCMFromMH(mhId) {
    closeModal('modalCommonFormEditor');
    setTimeout(() => {
        openCommonFormModal(0, 'CM', { indication_type: 'MH' });
    }, 200);
}

function shortcutAddCMFromAE(aeId) {
    closeModal('modalCommonFormEditor');
    setTimeout(() => {
        openCommonFormModal(0, 'CM', { indication_type: 'AE' });
    }, 200);
}

// =========================================================================
// SAVE RECORD ACTION (SAVE DRAFT / MARK COMPLETE)
// =========================================================================
function saveCommonRecord(mode) {
    if (!USER_CAN_EDIT_CONTENT) {
        showToast('Unauthorized: Data Managers and Data Monitors cannot edit clinical content.', 'error');
        return;
    }
    const recordId = parseInt(document.getElementById('cfRecordId').value) || 0;
    const formType = (document.getElementById('cfFormType').value || '').toUpperCase();
    const alertBox = document.getElementById('cfValidationAlert');
    if (alertBox) alertBox.style.display = 'none';

    const data = {};
    const linkedIds = [];

    if (formType === 'MH') {
        const inpTerm = document.getElementById('inp_mh_term');
        const inpOngoing = document.getElementById('inp_mh_ongoing');
        const inpStart = document.getElementById('inp_mh_start_date');
        const inpEnd = document.getElementById('inp_mh_end_date');
        const inpComments = document.getElementById('inp_mh_comments');
        const inpMed = document.getElementById('inp_mh_medication_given');

        data.mh_term = inpTerm ? inpTerm.value.trim() : '';
        data.ongoing = inpOngoing ? inpOngoing.value : '';
        data.start_date = inpStart ? inpStart.value : '';
        data.end_date = inpEnd ? inpEnd.value : '';
        data.comments = inpComments ? inpComments.value.trim() : '';
        data.medication_given = inpMed ? inpMed.value : '';
    } else if (formType === 'AE') {
        const inpTerm = document.getElementById('inp_ae_term');
        const inpStart = document.getElementById('inp_ae_start_date');
        const inpEnd = document.getElementById('inp_ae_end_date');
        const inpSeverity = document.getElementById('inp_ae_severity');
        const inpSeriousness = document.getElementById('inp_ae_seriousness');
        const inpCausality = document.getElementById('inp_ae_causality');
        const inpAction = document.getElementById('inp_ae_action_taken');
        const inpOutcome = document.getElementById('inp_ae_outcome');
        const inpConcomitant = document.getElementById('inp_ae_concomitant_treatment');

        data.ae_term = inpTerm ? inpTerm.value.trim() : '';
        data.start_date = inpStart ? inpStart.value : '';
        data.end_date = inpEnd ? inpEnd.value : '';
        data.severity = inpSeverity ? inpSeverity.value : '';
        data.seriousness = inpSeriousness ? inpSeriousness.value : '';
        
        const critCbs = document.querySelectorAll('.cb_ae_crit:checked');
        data.seriousness_criteria = Array.from(critCbs).map(cb => cb.value);

        data.causality = inpCausality ? inpCausality.value : '';
        data.action_taken = inpAction ? inpAction.value : '';
        data.outcome = inpOutcome ? inpOutcome.value : '';
        data.concomitant_treatment = inpConcomitant ? inpConcomitant.value : '';
    } else if (formType === 'CM') {
        const inpName = document.getElementById('inp_cm_name');
        const inpStart = document.getElementById('inp_cm_start_date');
        const inpStop = document.getElementById('inp_cm_stop_date');
        const inpIndication = document.getElementById('inp_cm_indication_type');
        const inpSpecify = document.getElementById('inp_cm_specify_other');

        data.medication_name = inpName ? inpName.value.trim() : '';
        data.start_date = inpStart ? inpStart.value : '';
        data.stop_date = inpStop ? inpStop.value : '';
        data.indication_type = inpIndication ? inpIndication.value : '';
        data.specify_other = inpSpecify ? inpSpecify.value.trim() : '';

        const linkedCbs = document.querySelectorAll('.cb_cm_linked:checked');
        linkedCbs.forEach(cb => linkedIds.push(parseInt(cb.value)));
    } else if (formType === 'TA') {
        const inpMethod = document.getElementById('inp_ta_recist_method');
        const inpAssessed = document.getElementById('inp_ta_tumor_assessed');
        const inpReason = document.getElementById('inp_ta_not_assessed_reason');
        const inpTimepoint = document.getElementById('inp_ta_timepoint');
        const inpDate = document.getElementById('inp_ta_imaging_date');
        const inpMod = document.getElementById('inp_ta_imaging_modality');
        const inpModOther = document.getElementById('inp_ta_imaging_modality_other');
        const inpTargetPres = document.getElementById('inp_ta_target_present');
        const inpSumDia = document.getElementById('inp_ta_sum_longest_dia');
        const inpNonTargetPres = document.getElementById('inp_ta_nontarget_present');
        const inpNewLes = document.getElementById('inp_ta_new_lesions');
        const inpNewLesDet = document.getElementById('inp_ta_new_lesions_details');
        const inpResp = document.getElementById('inp_ta_overall_response');
        const inpComments = document.getElementById('inp_ta_comments');

        data.recist_method = inpMethod ? inpMethod.value : 'RECIST 1.1';
        data.tumor_assessed = inpAssessed ? inpAssessed.value : 'Yes';
        data.tumor_not_assessed_reason = inpReason ? inpReason.value.trim() : '';
        data.tumor_timepoint = inpTimepoint ? inpTimepoint.value.trim() : '';
        data.imaging_date = inpDate ? inpDate.value : '';
        data.imaging_modality = inpMod ? inpMod.value : '';
        data.imaging_modality_other = inpModOther ? inpModOther.value.trim() : '';
        data.target_lesions_present = inpTargetPres ? inpTargetPres.value : 'Yes';
        data.sum_longest_dia = inpSumDia ? inpSumDia.value : '';
        data.nontarget_lesions_present = inpNonTargetPres ? inpNonTargetPres.value : 'Yes';
        data.new_lesions_present = inpNewLes ? inpNewLes.value : 'No';
        data.new_lesions_details = inpNewLesDet ? inpNewLesDet.value.trim() : '';
        data.recist_overall_response = inpResp ? inpResp.value : '';
        data.comments = inpComments ? inpComments.value.trim() : '';

        for (let i = 1; i <= 5; i++) {
            const org = document.getElementById(`inp_ta_tl${i}_organ`);
            const site = document.getElementById(`inp_ta_tl${i}_site`);
            const axis = document.getElementById(`inp_ta_tl${i}_axis`);
            const dia = document.getElementById(`inp_ta_tl${i}_longest_dia`);
            const st = document.getElementById(`inp_ta_tl${i}_status`);
            if (org) {
                data[`tl${i}_organ`] = org.value.trim();
                data[`tl${i}_site`] = site ? site.value.trim() : '';
                data[`tl${i}_axis`] = axis ? axis.value : 'Long';
                data[`tl${i}_longest_dia`] = dia ? dia.value : '';
                data[`tl${i}_status`] = st ? st.value : 'Measured';
            } else {
                data[`tl${i}_organ`] = '';
                data[`tl${i}_site`] = '';
                data[`tl${i}_axis`] = '';
                data[`tl${i}_longest_dia`] = '';
                data[`tl${i}_status`] = '';
            }
        }

        for (let i = 1; i <= 5; i++) {
            const norg = document.getElementById(`inp_ta_ntl${i}_organ`);
            const nsite = document.getElementById(`inp_ta_ntl${i}_site`);
            const nst = document.getElementById(`inp_ta_ntl${i}_status`);
            if (norg) {
                data[`ntl${i}_organ`] = norg.value.trim();
                data[`ntl${i}_site`] = nsite ? nsite.value.trim() : '';
                data[`ntl${i}_status`] = nst ? nst.value : 'Present';
            } else {
                data[`ntl${i}_organ`] = '';
                data[`ntl${i}_site`] = '';
                data[`ntl${i}_status`] = '';
            }
        }
    } else if (formType === 'USV') {
        const usvFieldKeys = [
            'visit_performed', 'date_of_visit', 'visit_reason_unscheduled', 'unscheduled_reason_other',
            'visit_not_performed_reason', 'date_of_death', 'visit_comments',
            'vitals_assessed', 'vitals_not_assessed_reason',
            'vitals_assessment_date', 'vitals_assessment_time',
            'sbp_val', 'dbp_val', 'hr_val', 'rr_val', 'temp_val', 'weight_val', 'height_val',
            'hema_panel_collected', 'hema_not_collected_reason',
            'hema_collection_date', 'hema_collection_time',
            'hema_hgb_val', 'hema_hgb_flag', 'hema_hgb_clin_sig', 'hema_hgb_action',
            'hema_hct_val', 'hema_hct_flag', 'hema_hct_clin_sig', 'hema_hct_action',
            'hema_rbc_val', 'hema_rbc_flag', 'hema_rbc_clin_sig', 'hema_rbc_action',
            'hema_wbc_val', 'hema_wbc_flag', 'hema_wbc_clin_sig', 'hema_wbc_action',
            'hema_anc_val', 'hema_anc_flag', 'hema_anc_clin_sig', 'hema_anc_action',
            'hema_plt_val', 'hema_plt_flag', 'hema_plt_clin_sig', 'hema_plt_action',
            'chem_panel_collected', 'chem_not_collected_reason',
            'chem_collection_date', 'chem_collection_time',
            'chem_sod_val', 'chem_sod_flag', 'chem_sod_clin_sig', 'chem_sod_action',
            'chem_pot_val', 'chem_pot_flag', 'chem_pot_clin_sig', 'chem_pot_action',
            'chem_glu_val', 'chem_glu_flag', 'chem_glu_clin_sig', 'chem_glu_action',
            'chem_cal_val', 'chem_cal_flag', 'chem_cal_clin_sig', 'chem_cal_action',
            'chem_alb_val', 'chem_alb_flag', 'chem_alb_clin_sig', 'chem_alb_action',
            'chem_tp_val', 'chem_tp_flag', 'chem_tp_clin_sig', 'chem_tp_action',
            'chem_alt_val', 'chem_alt_flag', 'chem_alt_clin_sig', 'chem_alt_action',
            'chem_ast_val', 'chem_ast_flag', 'chem_ast_clin_sig', 'chem_ast_action',
            'chem_tbil_val', 'chem_tbil_flag', 'chem_tbil_clin_sig', 'chem_tbil_action',
            'chem_creat_val', 'chem_creat_flag', 'chem_creat_clin_sig', 'chem_creat_action',
            'drug_administered', 'drug_not_administered_reason',
            'drug_name', 'drug_admin_date', 'drug_admin_start_time', 'drug_route',
            'drug_actual_dose', 'drug_dose_unit', 'drug_comments',
            'iv_end_datetime', 'iv_interrupted'
        ];
        usvFieldKeys.forEach(k => {
            const el = document.getElementById('inp_usv_' + k);
            if (el) {
                data[k] = el.value.trim();
            } else {
                data[k] = '';
            }
        });
    }

    const fd = new FormData();
    fd.append('action', 'save_record');
    fd.append('record_id', recordId);
    fd.append('subject_id', CURRENT_SUBJECT_ID);
    fd.append('form_type', formType);
    fd.append('mode', mode);
    fd.append('data', JSON.stringify(data));

    linkedIds.forEach(id => fd.append('linked_ids[]', id));

    fetch('ajax_common_forms.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
        if (!res.success) {
            if (alertBox) {
                alertBox.innerHTML = `<span class="material-icons-round" style="font-size: 1.1rem; vertical-align: middle; margin-right: 4px;">error_outline</span> ${escapeHtml(res.message || 'Validation Error')}`;
                alertBox.style.display = 'block';
                const bodyEl = document.getElementById('cfEditorBody');
                if (bodyEl) bodyEl.scrollTop = 0;
            }
            return;
        }

        // 1. Immediately Dismiss Modal
        closeModal('modalCommonFormEditor');

        // 2. Show Toast Notification
        showToast(res.message || 'Record saved successfully!', 'success');

        // 3. Dynamic Roster & Badge Update
        loadCommonRecords(formType);
        if (typeof COMMON_FORM_TYPE !== 'undefined' && COMMON_FORM_TYPE && COMMON_FORM_TYPE.toUpperCase() !== formType) {
            loadCommonRecords(COMMON_FORM_TYPE);
        }
    })
    .catch(err => {
        console.error(err);
        showToast('Network error saving record', 'error');
    });
}

// =========================================================================
// SDR, SDV, QUERY, AUDIT & VOID HANDLERS
// =========================================================================
function triggerCommonReviewAction(recordId, workflowAction, formType = null) {
    const targetType = (formType || (typeof COMMON_FORM_TYPE !== 'undefined' ? COMMON_FORM_TYPE : '') || '').toUpperCase();

    let modalTitle = 'Confirm Action';
    let modalMsg = 'Are you sure you want to proceed?';
    let icon = 'help';
    let iconBg = '#eff6ff';
    let iconColor = '#1d4ed8';
    let btnText = 'Confirm';
    let btnClass = 'btn-primary';
    let btnStyle = 'background: #1d4ed8; border: none; color: #fff; font-weight: 600;';
    let requireRemarks = false;
    let remarksLabel = '';

    if (workflowAction === 'mark_sdr') {
        modalTitle = 'Mark as SDR';
        modalMsg = 'Are you sure you want to mark this record as Source Data Review (SDR)? The record will be submitted as <strong>UNDER REVIEW</strong> for Data Monitor / Data Manager verification and locked for direct editing.';
        icon = 'lock';
        iconBg = '#eff6ff';
        iconColor = '#1d6f97';
        btnText = 'Yes, Mark as SDR';
        btnStyle = 'background: #1d6f97; border: none; color: #fff; font-weight: 600;';
    } else if (workflowAction === 'revoke_sdr') {
        modalTitle = 'Revoke SDR';
        modalMsg = 'Are you sure you want to revoke the SDR submission? This will return the record to Draft / Pending mode and make it editable again.';
        icon = 'lock_open';
        iconBg = '#fef2f2';
        iconColor = '#dc2626';
        btnText = 'Yes, Revoke SDR';
        btnStyle = 'background: #dc2626; border: none; color: #fff; font-weight: 600;';
    } else if (workflowAction === 'monitor_review') {
        modalTitle = 'Mark as SDVed';
        modalMsg = 'Are you sure you want to verify (SDV) this record against source documents? The record will be marked as <strong>SDVed</strong>.';
        icon = 'verified';
        iconBg = '#ecfdf5';
        iconColor = '#047857';
        btnText = 'Yes, Mark as SDVed';
        btnStyle = 'background: #059669; border: none; color: #fff; font-weight: 600;';
    } else if (workflowAction === 'monitor_revoke') {
        modalTitle = 'Revoke SDVed';
        modalMsg = 'Are you sure you want to revoke this SDVed status? The SDR status will be reset to Pending, and the Data Coordinator will be able to update and resubmit the record.';
        icon = 'undo';
        iconBg = '#fef2f2';
        iconColor = '#dc2626';
        btnText = 'Confirm Revoke SDVed';
        btnStyle = 'background: #dc2626; border: none; color: #fff; font-weight: 600;';
        requireRemarks = true;
        remarksLabel = 'Reason for Revoking SDVed';
    } else if (workflowAction === 'manager_review') {
        modalTitle = 'Mark as DM Reviewed';
        modalMsg = 'Are you sure you want to mark this record as <strong>DM Reviewed</strong>?';
        icon = 'verified';
        iconBg = '#ecfdf5';
        iconColor = '#047857';
        btnText = 'Yes, Mark as DM Reviewed';
        btnStyle = 'background: #059669; border: none; color: #fff; font-weight: 600;';
    } else if (workflowAction === 'manager_revoke') {
        modalTitle = 'Revoke DM Reviewed';
        modalMsg = 'Are you sure you want to revoke this DM Reviewed status? The SDR status will be reset to Pending, and the Data Coordinator will be able to update and resubmit the record.';
        icon = 'undo';
        iconBg = '#fef2f2';
        iconColor = '#dc2626';
        btnText = 'Confirm Revoke DM Reviewed';
        btnStyle = 'background: #dc2626; border: none; color: #fff; font-weight: 600;';
        requireRemarks = true;
        remarksLabel = 'Reason for Revoking DM Reviewed';
    } else if (workflowAction === 'pi_sign_off') {
        modalTitle = 'Principal Investigator Sign Off';
        modalMsg = 'Are you sure you want to perform <strong>PI Sign Off</strong> on this record? Once signed off, this record will be completely locked. No queries, field changes, or revocations will be permitted for any role unless the PI revokes the sign off.';
        icon = 'verified_user';
        iconBg = '#e0e7ff';
        iconColor = '#4338ca';
        btnText = 'Yes, PI Sign Off';
        btnStyle = 'background: #4338ca; border: none; color: #fff; font-weight: 600;';
    } else if (workflowAction === 'pi_revoke') {
        modalTitle = 'Revoke PI Sign Off';
        modalMsg = 'Are you sure you want to revoke the <strong>Principal Investigator Sign Off</strong>? The record will return to the previous stage of <strong>SDVed & DM Reviewed</strong>.';
        icon = 'undo';
        iconBg = '#fef2f2';
        iconColor = '#dc2626';
        btnText = 'Confirm Revoke PI Sign Off';
        btnStyle = 'background: #dc2626; border: none; color: #fff; font-weight: 600;';
        requireRemarks = true;
        remarksLabel = 'Reason for Revoking PI Sign Off';
    }

    showConfirmModal({
        title: modalTitle,
        message: modalMsg,
        icon: icon,
        iconBg: iconBg,
        iconColor: iconColor,
        btnText: btnText,
        btnStyle: btnStyle,
        requireRemarks: requireRemarks,
        remarksLabel: remarksLabel,
        onConfirm: function(remarks) {
            const fd = new FormData();
            fd.append('action', 'update_review_status');
            fd.append('record_id', recordId);
            fd.append('workflow_action', workflowAction);
            if (remarks) fd.append('remarks', remarks);

            fetch('ajax_common_forms.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                if (!res.success) {
                    showToast(res.message || 'Error updating review status', 'error');
                    return;
                }
                showToast(res.message, 'success');
                const reloadType = res.form_type || targetType;
                loadCommonRecords(reloadType);
                if (typeof COMMON_FORM_TYPE !== 'undefined' && COMMON_FORM_TYPE && COMMON_FORM_TYPE.toUpperCase() !== reloadType.toUpperCase()) {
                    loadCommonRecords(COMMON_FORM_TYPE);
                }
            })
            .catch(err => {
                console.error(err);
                showToast('Network error updating review status', 'error');
            });
        }
    });
}

function triggerSDVAction(recordId, targetAction, formType = null) {
    let wfAction = targetAction;
    if (targetAction === 'under_review') wfAction = 'mark_sdr';
    else if (targetAction === 'pending') wfAction = 'revoke_sdr';
    else if (targetAction === 'sdved') {
        if (USER_IS_MANAGER && !USER_IS_MONITOR) wfAction = 'manager_review';
        else wfAction = 'monitor_review';
    }
    triggerCommonReviewAction(recordId, wfAction, formType);
}

function toggleCommonSDR(recordId, currentStatus, formType = null) {
    const isManager = USER_IS_MANAGER && !USER_IS_MONITOR;
    const isMonitor = USER_IS_MONITOR;
    const isAdmin = USER_IS_ADMIN;

    if (currentStatus === 'under_review') {
        if (isManager) {
            triggerCommonReviewAction(recordId, 'manager_review', formType);
        } else if (isMonitor || isAdmin) {
            triggerCommonReviewAction(recordId, 'monitor_review', formType);
        } else {
            triggerCommonReviewAction(recordId, 'revoke_sdr', formType);
        }
    } else if (currentStatus === 'dm_reviewed') {
        if (isManager || isAdmin) {
            triggerCommonReviewAction(recordId, 'manager_revoke', formType);
        }
    } else if (currentStatus === 'sdved' || currentStatus === 'reviewed') {
        if (isMonitor || isAdmin) {
            triggerCommonReviewAction(recordId, 'monitor_revoke', formType);
        } else if (isManager) {
            triggerCommonReviewAction(recordId, 'manager_revoke', formType);
        }
    } else if (currentStatus === 'sdved_and_dm_reviewed') {
        if (isManager) {
            triggerCommonReviewAction(recordId, 'manager_revoke', formType);
        } else {
            triggerCommonReviewAction(recordId, 'monitor_revoke', formType);
        }
    } else {
        if (isManager) {
            triggerCommonReviewAction(recordId, 'manager_review', formType);
        } else if (isMonitor || (isAdmin && !USER_IS_COORDINATOR)) {
            triggerCommonReviewAction(recordId, 'monitor_review', formType);
        } else {
            triggerCommonReviewAction(recordId, 'mark_sdr', formType);
        }
    }
}

function markCommonSDRFromEditor() {
    const recId = parseInt(document.getElementById('cfRecordId').value) || 0;
    const formType = (document.getElementById('cfFormType').value || '').toUpperCase();
    if (recId > 0 && currentEditingRecordData) {
        closeModal('modalCommonFormEditor');
        triggerCommonReviewAction(recId, 'mark_sdr', formType);
    } else {
        showToast('Please save the record first before marking SDR.', 'warning');
    }
}

function openCommonVoidModal(recordId) {
    document.getElementById('cfVoidRecordId').value = recordId;
    document.getElementById('cfVoidReasonInput').value = '';
    openModal('modalCommonVoid');
}

function submitCommonVoid() {
    const recId = parseInt(document.getElementById('cfVoidRecordId').value);
    const reason = document.getElementById('cfVoidReasonInput').value.trim();

    if (reason === '') {
        showToast('Please enter a reason for voiding this record.', 'warning');
        return;
    }

    const fd = new FormData();
    fd.append('action', 'void_record');
    fd.append('record_id', recId);
    fd.append('reason', reason);

    fetch('ajax_common_forms.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
        if (!res.success) {
            showToast(res.message || 'Error voiding record', 'error');
            return;
        }
        showToast(res.message, 'success');
        closeModal('modalCommonVoid');
        loadCommonRecords();
    });
}

// Field maps for common forms across all fields
let currentCommonQueryRecordId = null;
let currentCommonQueryTargetField = null;
let currentCommonQueryTargetLabel = null;

const COMMON_FORM_FIELD_MAP = {
    'MH': [
        { key: 'mh_term', label: '1. MH Term / Condition', type: 'text', placeholder: 'e.g. Type 2 Diabetes Mellitus' },
        { key: 'ongoing', label: '2. Ongoing / Continuing', type: 'select', options: ['Yes', 'No'] },
        { key: 'start_date', label: '3. Start Date', type: 'date' },
        { key: 'end_date', label: '4. End Date', type: 'date' },
        { key: 'comments', label: '5. Comments / Additional Details', type: 'textarea', placeholder: 'Enter additional details...' },
        { key: 'medication_given', label: '6. Medication Given', type: 'select', options: ['Yes', 'No'] }
    ],
    'AE': [
        { key: 'ae_term', label: '1. AE Term / Verbatim', type: 'text', placeholder: 'e.g. Severe Headache' },
        { key: 'start_date', label: '2. Start Date', type: 'date' },
        { key: 'end_date', label: '3. End Date', type: 'date' },
        { key: 'severity', label: '4. Severity / Intensity', type: 'select', options: ['Mild', 'Moderate', 'Severe'] },
        { key: 'seriousness', label: '5. Serious Adverse Event (SAE)', type: 'select', options: ['Yes', 'No'] },
        { key: 'seriousness_criteria', label: '6. Seriousness Criteria', type: 'checkbox_group', options: ['Death', 'Life-threatening', 'Hospitalization / prolongation of hospitalization', 'Disability / incapacity', 'Congenital anomaly', 'Other medically important event'] },
        { key: 'causality', label: '7. Causality / Relationship to Study Drug', type: 'select', options: ['Unrelated', 'Unlikely', 'Possible', 'Probable', 'Definite'] },
        { key: 'action_taken', label: '8. Action Taken with Study Treatment', type: 'select', options: ['None', 'Dose Reduced', 'Dose Interrupted', 'Drug Withdrawn', 'Not Applicable'] },
        { key: 'outcome', label: '9. Outcome', type: 'select', options: ['Recovered / Resolved', 'Recovering / Resolving', 'Not Recovered / Not Resolved', 'Recovered with Sequelae', 'Fatal', 'Unknown'] },
        { key: 'concomitant_treatment', label: '10. Concomitant Treatment Given', type: 'select', options: ['Yes', 'No'] }
    ],
    'CM': [
        { key: 'medication_name', label: '1. Medication Name', type: 'text', placeholder: 'e.g. Metformin 500mg' },
        { key: 'start_date', label: '2. Start Date', type: 'date' },
        { key: 'stop_date', label: '3. Stop Date', type: 'date' },
        { key: 'indication_type', label: '4. Medication Taken For (Indication Type)', type: 'select', options: [ { value: 'MH', label: 'Medical History (MH)' }, { value: 'AE', label: 'Adverse Event (AE)' }, { value: 'Other', label: 'Other Indication' } ] },
        { key: 'linked_records', label: '5. Linked Condition / Event', type: 'text', placeholder: 'Linked Condition / Event' },
        { key: 'specify_other', label: '6. Specify Other Indication', type: 'text', placeholder: 'Enter indication...' }
    ],
    'TA': [
        { key: 'recist_method', label: 'Assessment Method', type: 'text', placeholder: 'RECIST 1.1' },
        { key: 'tumor_assessed', label: 'Was Tumor Assessment Performed?', type: 'select', options: ['Yes', 'No'] },
        { key: 'tumor_not_assessed_reason', label: 'Reason Tumor Assessment Not Performed', type: 'text', placeholder: 'Enter clinical reason...' },
        { key: 'tumor_timepoint', label: 'Assessment Time Point', type: 'text', placeholder: 'e.g. Screening, Cycle 2 Day 1, Week 6' },
        { key: 'imaging_date', label: 'Imaging Assessment Date', type: 'date' },
        { key: 'imaging_modality', label: 'Imaging Modality', type: 'select', options: ['CT', 'MRI', 'CT and MRI', 'PET-CT', 'Other'] },
        { key: 'imaging_modality_other', label: 'Other Modality — Specify', type: 'text', placeholder: 'Specify imaging technique...' },
        { key: 'target_lesions_present', label: 'Target Lesions Present', type: 'select', options: ['Yes', 'No'] },
        { key: 'sum_longest_dia', label: 'Sum of Longest Diameters (mm)', type: 'number', step: '0.1', placeholder: '0.0' },
        { key: 'nontarget_lesions_present', label: 'Non-Target Present', type: 'select', options: ['Yes', 'No'] },
        { key: 'new_lesions_present', label: 'New Lesions Identified?', type: 'select', options: ['No', 'Yes', 'Indeterminate'] },
        { key: 'new_lesions_details', label: 'New Lesion Details / Anatomical Location', type: 'text', placeholder: 'Specify organ and location of new lesion...' },
        { key: 'recist_overall_response', label: 'RECIST 1.1 Overall Response', type: 'select', options: ['CR — Complete Response', 'PR — Partial Response', 'SD — Stable Disease', 'PD — Progressive Disease', 'NE — Not Evaluable'] },
        { key: 'comments', label: 'Clinical Comments / Radiology Notes', type: 'textarea', placeholder: 'Enter additional radiological remarks...' }
    ],
    'USV': [
        { key: 'visit_performed', label: 'Was Visit Performed?', type: 'select', options: ['Yes', 'No'] },
        { key: 'date_of_visit', label: 'Date of Visit', type: 'date' },
        { key: 'visit_reason_unscheduled', label: 'Reason for Unscheduled Visit', type: 'select', options: ['Adverse Event (AE) Assessment / Follow-up', 'Concomitant Medication Assessment', 'Abnormal Laboratory Result Follow-up', 'Patient Reported Symptoms / Deterioration', 'Dose Modification / Toxicity Re-evaluation', 'Protocol Compliance / Safety Re-check', 'Other'] },
        { key: 'unscheduled_reason_other', label: 'Specify Other Reason', type: 'text', placeholder: 'Specify reason...' },
        { key: 'visit_not_performed_reason', label: 'Reason Visit Not Performed', type: 'select', options: ['Subject Missed / Failed to Attend', 'Subject Withdrew Consent', 'Subject Lost to Follow-up', 'Subject Hospitalized', 'Subject Deceased', 'Adverse Event', 'Site Logistical Issue / Weather / Transport', 'Other'] },
        { key: 'date_of_death', label: 'Date of Death', type: 'date' },
        { key: 'visit_comments', label: 'Visit Comments / Remarks', type: 'textarea', placeholder: 'Enter clinical comments...' },
        { key: 'vitals_assessed', label: 'Vital Signs Assessed?', type: 'select', options: ['Yes', 'No'] },
        { key: 'vitals_not_assessed_reason', label: 'Reason Vitals Not Assessed', type: 'text', placeholder: 'Specify reason...' },
        { key: 'vitals_assessment_date', label: 'Vitals Assessment Date', type: 'date' },
        { key: 'vitals_assessment_time', label: 'Vitals Assessment Time', type: 'text', placeholder: 'HH:MM' },
        { key: 'sbp_val', label: 'Systolic Blood Pressure (mmHg)', type: 'number', placeholder: '120' },
        { key: 'dbp_val', label: 'Diastolic Blood Pressure (mmHg)', type: 'number', placeholder: '80' },
        { key: 'hr_val', label: 'Heart Rate (bpm)', type: 'number', placeholder: '72' },
        { key: 'rr_val', label: 'Respiratory Rate (breaths/min)', type: 'number', placeholder: '16' },
        { key: 'temp_val', label: 'Body Temperature (°C)', type: 'number', step: '0.1', placeholder: '36.8' },
        { key: 'weight_val', label: 'Weight (kg)', type: 'number', step: '0.1', placeholder: '70.0' },
        { key: 'height_val', label: 'Height (cm)', type: 'number', step: '0.5', placeholder: '172.0' },
        { key: 'hema_panel_collected', label: 'Hematology Collected?', type: 'select', options: ['Yes', 'No'] },
        { key: 'hema_not_collected_reason', label: 'Reason Hematology Not Collected', type: 'text', placeholder: 'Specify reason...' },
        { key: 'hema_collection_date', label: 'Hematology Collection Date', type: 'date' },
        { key: 'hema_hgb_val', label: 'Hemoglobin (g/dL)', type: 'number', step: '0.1', placeholder: '14.2' },
        { key: 'hema_hgb_flag', label: 'Hemoglobin Normality', type: 'select', options: ['Normal', 'Low', 'High', 'Abnormal'] },
        { key: 'hema_hct_val', label: 'Hematocrit (%)', type: 'number', step: '0.1', placeholder: '42.0' },
        { key: 'hema_hct_flag', label: 'Hematocrit Normality', type: 'select', options: ['Normal', 'Low', 'High', 'Abnormal'] },
        { key: 'hema_rbc_val', label: 'RBC (10^12/L)', type: 'number', step: '0.01', placeholder: '4.8' },
        { key: 'hema_rbc_flag', label: 'RBC Normality', type: 'select', options: ['Normal', 'Low', 'High', 'Abnormal'] },
        { key: 'hema_wbc_val', label: 'WBC (10^9/L)', type: 'number', step: '0.01', placeholder: '6.5' },
        { key: 'hema_wbc_flag', label: 'WBC Normality', type: 'select', options: ['Normal', 'Low', 'High', 'Abnormal'] },
        { key: 'hema_anc_val', label: 'ANC (10^9/L)', type: 'number', step: '0.01', placeholder: '3.8' },
        { key: 'hema_anc_flag', label: 'ANC Normality', type: 'select', options: ['Normal', 'Low', 'High', 'Abnormal'] },
        { key: 'hema_plt_val', label: 'Platelet Count (10^9/L)', type: 'number', step: '1', placeholder: '240' },
        { key: 'hema_plt_flag', label: 'Platelet Normality', type: 'select', options: ['Normal', 'Low', 'High', 'Abnormal'] },
        { key: 'chem_panel_collected', label: 'Chemistry Collected?', type: 'select', options: ['Yes', 'No'] },
        { key: 'chem_not_collected_reason', label: 'Reason Chemistry Not Collected', type: 'text', placeholder: 'Specify reason...' },
        { key: 'chem_collection_date', label: 'Chemistry Collection Date', type: 'date' },
        { key: 'chem_sod_val', label: 'Sodium (mmol/L)', type: 'number', step: '0.1', placeholder: '140' },
        { key: 'chem_pot_val', label: 'Potassium (mmol/L)', type: 'number', step: '0.1', placeholder: '4.2' },
        { key: 'chem_glu_val', label: 'Glucose (mg/dL)', type: 'number', step: '1', placeholder: '95' },
        { key: 'chem_cal_val', label: 'Calcium (mg/dL)', type: 'number', step: '0.1', placeholder: '9.2' },
        { key: 'chem_alb_val', label: 'Albumin (g/dL)', type: 'number', step: '0.1', placeholder: '4.1' },
        { key: 'chem_tp_val', label: 'Total Protein (g/dL)', type: 'number', step: '0.1', placeholder: '7.2' },
        { key: 'chem_alt_val', label: 'ALT / SGPT (U/L)', type: 'number', step: '1', placeholder: '28' },
        { key: 'chem_alt_flag', label: 'ALT Normality', type: 'select', options: ['Normal', 'Low', 'High', 'Abnormal'] },
        { key: 'chem_ast_val', label: 'AST / SGOT (U/L)', type: 'number', step: '1', placeholder: '24' },
        { key: 'chem_ast_flag', label: 'AST Normality', type: 'select', options: ['Normal', 'Low', 'High', 'Abnormal'] },
        { key: 'chem_tbil_val', label: 'Total Bilirubin (mg/dL)', type: 'number', step: '0.1', placeholder: '0.8' },
        { key: 'chem_tbil_flag', label: 'Total Bilirubin Normality', type: 'select', options: ['Normal', 'Low', 'High', 'Abnormal'] },
        { key: 'chem_creat_val', label: 'Serum Creatinine (mg/dL)', type: 'number', step: '0.01', placeholder: '0.9' },
        { key: 'chem_creat_flag', label: 'Creatinine Normality', type: 'select', options: ['Normal', 'Low', 'High', 'Abnormal'] },
        { key: 'drug_administered', label: 'Study Drug Administered?', type: 'select', options: ['Yes', 'No'] },
        { key: 'drug_not_administered_reason', label: 'Reason Drug Not Administered', type: 'text', placeholder: 'Specify reason...' },
        { key: 'drug_name', label: 'Study Drug Name', type: 'text', placeholder: 'e.g. Investigational Product X' },
        { key: 'drug_admin_date', label: 'Administration Date', type: 'date' },
        { key: 'drug_actual_dose', label: 'Actual Dose Administered', type: 'number', step: '0.1', placeholder: '100' },
        { key: 'drug_dose_unit', label: 'Dose Unit', type: 'select', options: ['mg', 'g', 'mL', 'mg/kg', 'mg/m2', 'IU'] },
        { key: 'drug_route', label: 'Route of Administration', type: 'select', options: ['Oral (PO)', 'Intravenous (IV) Infusion', 'Subcutaneous (SC)', 'Intramuscular (IM)', 'Topical', 'Inhalation'] },
        { key: 'drug_comments', label: 'Drug Administration Comments', type: 'textarea', placeholder: 'Enter comments...' }
    ]
};

// Generates appropriate input control matching the queried field type
function renderCommonQueryProposedInput(formType, fieldName, queryId, currentVal) {
    const fMap = (COMMON_FORM_FIELD_MAP[formType] || []);
    let fieldDef = fMap.find(f => f.key === fieldName);
    
    // Fallback heuristic if not explicitly found in map
    if (!fieldDef) {
        let fallbackType = 'text';
        if (fieldName && (fieldName.endsWith('_date') || fieldName.includes('date'))) fallbackType = 'date';
        else if (fieldName && (fieldName.includes('comment') || fieldName.includes('note') || fieldName.includes('desc'))) fallbackType = 'textarea';
        else if (fieldName && (fieldName.includes('dia') || fieldName.includes('count') || fieldName.includes('num'))) fallbackType = 'number';
        fieldDef = { key: fieldName, label: fieldName, type: fallbackType };
    }

    const fType = fieldDef.type || 'text';
    const strCurr = (currentVal !== null && currentVal !== undefined) ? (Array.isArray(currentVal) ? currentVal.join(', ') : String(currentVal)).trim() : '';

    if (fType === 'select') {
        const options = fieldDef.options || [];
        let optsHtml = `<option value="">-- Keep Current Value / Select Proposed Value --</option>`;
        options.forEach(opt => {
            const val = (typeof opt === 'object' && opt !== null) ? opt.value : opt;
            const lbl = (typeof opt === 'object' && opt !== null) ? opt.label : opt;
            const isCurrent = (val === strCurr || (strCurr !== '' && (val.startsWith(strCurr + ' ') || val.startsWith(strCurr + '—') || val.startsWith(strCurr + ' -'))));
            const currentTag = isCurrent ? ' (Current Value)' : '';
            optsHtml += `<option value="${escapeHtml(val)}">${escapeHtml(lbl)}${currentTag}</option>`;
        });
        return `
            <select id="inp_ans_val_${queryId}" class="form-input" style="width: 100%; font-size: 0.85rem; padding: 0.45rem 0.6rem; border-color: #cbd5e1; background: #fff;">
                ${optsHtml}
            </select>
            <div style="font-size: 0.7rem; color: #64748b; margin-top: 3px;">Leave unselected if field value remains unchanged. Selected option will be proposed to reviewer.</div>
        `;
    }

    if (fType === 'date') {
        return `
            <input type="date" id="inp_ans_val_${queryId}" class="form-input" max="${getTodayIST()}" style="width: 100%; font-size: 0.85rem; padding: 0.45rem 0.6rem; border-color: #cbd5e1; background: #fff;">
            <div style="font-size: 0.7rem; color: #64748b; margin-top: 3px;">Select proposed date (leave blank if date remains unchanged).</div>
        `;
    }

    if (fType === 'textarea') {
        return `
            <textarea id="inp_ans_val_${queryId}" class="form-input" rows="2" placeholder="${escapeHtml(fieldDef.placeholder || 'Proposed New Value (Leave blank if unchanged)...')}" style="width: 100%; font-size: 0.85rem; padding: 0.45rem 0.6rem; resize: vertical;"></textarea>
            <div style="font-size: 0.7rem; color: #64748b; margin-top: 3px;">Leave blank if comments remain unchanged. Proposed text will be applied upon closure.</div>
        `;
    }

    if (fType === 'number') {
        return `
            <input type="number" step="${fieldDef.step || 'any'}" id="inp_ans_val_${queryId}" class="form-input" placeholder="${escapeHtml(fieldDef.placeholder || 'Proposed New Value (Leave blank if unchanged)')}" style="width: 100%; font-size: 0.85rem; padding: 0.45rem 0.6rem;">
            <div style="font-size: 0.7rem; color: #64748b; margin-top: 3px;">Enter proposed numeric value (leave blank if unchanged).</div>
        `;
    }

    if (fType === 'checkbox_group') {
        const options = fieldDef.options || [];
        const currList = Array.isArray(currentVal) ? currentVal : (typeof currentVal === 'string' ? currentVal.split(',').map(s => s.trim()) : []);
        let cbsHtml = '';
        options.forEach(opt => {
            const isCurrent = currList.includes(opt);
            cbsHtml += `
                <label style="display: flex; align-items: flex-start; gap: 6px; font-size: 0.78rem; color: #334155; margin: 0; cursor: pointer; line-height: 1.3;">
                    <input type="checkbox" class="inp_crit_cb_${queryId}" value="${escapeHtml(opt)}" onchange="syncCommonQueryCritCheckboxes(${queryId})" style="accent-color: #2563eb; margin-top: 2px;">
                    <span>${escapeHtml(opt)}${isCurrent ? ' <em style="color: #047857; font-size: 0.7rem;">(Current)</em>' : ''}</span>
                </label>
            `;
        });
        return `
            <div style="background: #fff; border: 1px solid #cbd5e1; border-radius: 6px; padding: 0.6rem 0.75rem;">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.45rem;">
                    ${cbsHtml}
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 0.5rem; padding-top: 0.4rem; border-top: 1px dashed #e2e8f0; font-size: 0.72rem;">
                    <span style="color: #64748b;">Select all proposed criteria (leave all unchecked if unchanged).</span>
                    <button type="button" onclick="clearCommonQueryCritCheckboxes(${queryId})" style="background: none; border: none; color: #dc2626; font-size: 0.72rem; cursor: pointer; padding: 0; text-decoration: underline;">Clear Selection</button>
                </div>
            </div>
            <input type="hidden" id="inp_ans_val_${queryId}" value="">
        `;
    }

    // Default / text input
    return `
        <input type="text" id="inp_ans_val_${queryId}" class="form-input" placeholder="${escapeHtml(fieldDef.placeholder || 'Proposed New Value (Leave blank if field value remains unchanged)')}" style="width: 100%; font-size: 0.85rem; padding: 0.45rem 0.6rem;">
        <div style="font-size: 0.7rem; color: #64748b; margin-top: 3px;">Leave blank if field value remains unchanged. Value will be applied when reviewer closes the query.</div>
    `;
}

function syncCommonQueryCritCheckboxes(queryId) {
    const cbs = document.querySelectorAll('.inp_crit_cb_' + queryId + ':checked');
    const vals = Array.from(cbs).map(cb => cb.value);
    const hiddenInp = document.getElementById('inp_ans_val_' + queryId);
    if (hiddenInp) {
        hiddenInp.value = vals.join(', ');
    }
}

function clearCommonQueryCritCheckboxes(queryId) {
    document.querySelectorAll('.inp_crit_cb_' + queryId).forEach(cb => cb.checked = false);
    const hiddenInp = document.getElementById('inp_ans_val_' + queryId);
    if (hiddenInp) {
        hiddenInp.value = '';
    }
}

// Helper to refresh editor field query counters in real time
function refreshEditorRecordData(recordId) {
    if (document.getElementById('modalCommonFormEditor')?.classList.contains('active')) {
        const edId = parseInt(document.getElementById('cfRecordId')?.value || '0');
        if (edId === recordId) {
            const fd = new FormData();
            fd.append('action', 'get_record');
            fd.append('record_id', recordId);
            fetch('ajax_common_forms.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                if (res.success && res.record) {
                    currentEditingRecordData = res.record;
                    setupEditorForm(res.record, res.record.form_type);
                }
            })
            .catch(console.error);
        }
    }
}

// Queries Modal - supports field-level query view & preselection
function openCommonQueryModal(recordId, targetFieldKey = null, targetFieldLabel = null) {
    currentCommonQueryRecordId = recordId;
    currentCommonQueryTargetField = targetFieldKey || null;
    currentCommonQueryTargetLabel = targetFieldLabel || null;

    const fd = new FormData();
    fd.append('action', 'get_record');
    fd.append('record_id', recordId);

    fetch('ajax_common_forms.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
        if (!res.success) {
            showToast('Error loading queries', 'error');
            return;
        }

        const rec = res.record;
        const formType = (rec.form_type || '').toUpperCase();
        const fKey = currentCommonQueryTargetField;
        const fLabel = currentCommonQueryTargetLabel || ((COMMON_FORM_FIELD_MAP[formType] || []).find(f => f.key === fKey)?.label || fKey);

        const titleEl = document.getElementById('cfQueryRecordTitle');
        if (fKey) {
            titleEl.innerHTML = `<span class="material-icons-round" style="color: #2563eb; vertical-align: middle; font-size: 1.25rem;">help_outline</span> Field Query: <span style="color: #1e40af;">${escapeHtml(fLabel)}</span> &bull; <span style="font-size: 0.825rem; font-weight: 500; color: #64748b;">${escapeHtml(rec.record_number)}</span>`;
        } else {
            titleEl.innerHTML = `<span class="material-icons-round" style="color: #2563eb; vertical-align: middle; font-size: 1.25rem;">help_outline</span> Queries for <span>${escapeHtml(rec.record_number)}</span>`;
        }

        const qBody = document.getElementById('cfQueryBody');
        let html = '';

        const isMonitorOrManager = USER_IS_MANAGER || USER_IS_MONITOR || USER_IS_ADMIN;
        const isMonitor = USER_IS_MONITOR || USER_IS_ADMIN;
        const isManager = USER_IS_MANAGER || USER_IS_ADMIN;

        // Query Raising Box (Monitor / Manager / Admin)
        if (isMonitorOrManager) {
            const isMonReviewed = !!rec.monitor_reviewed;
            const isMgrReviewed = !!rec.manager_reviewed;
            const isPiSigned = (rec.pi_signed_off === true || rec.pi_signed_off === 't' || rec.pi_signed_off === 1 || rec.pi_signed_off === '1');

            if (isPiSigned) {
                html += `
                    <div style="background: #e0e7ff; border: 1px solid #c7d2fe; color: #3730a3; padding: 0.85rem; border-radius: 8px; margin-bottom: 1.25rem; font-size: 0.85rem; display: flex; align-items: center; gap: 8px;">
                        <span class="material-icons-round" style="font-size: 1.2rem;">lock</span>
                        <div>Cannot Raise Query as the record is Signed Off by <strong>Principal Investigator</strong>. All queries, field changes, and revocations are completely locked.</div>
                    </div>
                `;
            } else if (isMonReviewed && !isManager) {
                html += `
                    <div style="background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 0.85rem; border-radius: 8px; margin-bottom: 1.25rem; font-size: 0.85rem; display: flex; align-items: center; gap: 8px;">
                        <span class="material-icons-round" style="font-size: 1.2rem;">lock</span>
                        <div>Cannot Raise Query as the form is marked as <strong>SDVed</strong>. Please revoke SDV first to raise queries.</div>
                    </div>
                `;
            } else if (isMgrReviewed && !isMonitor) {
                html += `
                    <div style="background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 0.85rem; border-radius: 8px; margin-bottom: 1.25rem; font-size: 0.85rem; display: flex; align-items: center; gap: 8px;">
                        <span class="material-icons-round" style="font-size: 1.2rem;">lock</span>
                        <div>Cannot Raise Query as the form is marked as <strong>DM Reviewed</strong>. Please revoke DM Review first to raise queries.</div>
                    </div>
                `;
            } else {
                const fieldOptions = (COMMON_FORM_FIELD_MAP[formType] || []).map(f => {
                    const isSelected = (fKey && f.key === fKey) ? 'selected' : '';
                    const currVal = (rec.data && rec.data[f.key] !== undefined) ? rec.data[f.key] : '';
                    const dispVal = currVal !== '' ? ` (Current: "${escapeHtml(String(currVal))}")` : '';
                    return `<option value="${f.key}" ${isSelected}>${f.label}${dispVal}</option>`;
                }).join('');

                const queryBoxTitle = fKey 
                    ? `Raise New Query on Field: <strong style="color: #1e3a8a;">${escapeHtml(fLabel)}</strong>` 
                    : `Raise New Query on ${rec.record_number}`;

                html += `
                    <div style="background: #eff6ff; border: 1px solid #bfdbfe; padding: 1.1rem; border-radius: 8px; margin-bottom: 1.25rem;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.6rem;">
                            <label style="font-weight: 700; font-size: 0.875rem; color: #1e40af; margin: 0;">
                                ${queryBoxTitle}
                            </label>
                            ${fKey ? `<span style="background: #dbeafe; color: #1d4ed8; font-size: 0.725rem; font-weight: 600; padding: 2px 8px; border-radius: 4px;">Targeting Field: ${escapeHtml(fKey)}</span>` : ''}
                        </div>
                        <div style="margin-bottom: 0.6rem;">
                            <label style="font-size: 0.775rem; font-weight: 600; color: #3b82f6; display: block; margin-bottom: 0.25rem;">Target Field</label>
                            <select id="inp_new_query_field" class="form-input" style="width: 100%; font-size: 0.85rem;">
                                <option value="" ${!fKey ? 'selected' : ''}>-- Entire Record (${rec.record_number}) --</option>
                                ${fieldOptions}
                            </select>
                        </div>
                        <div style="margin-bottom: 0.6rem;">
                            <label style="font-size: 0.775rem; font-weight: 600; color: #3b82f6; display: block; margin-bottom: 0.25rem;">Query Details / Description <span style="color: #dc2626;">*</span></label>
                            <textarea id="inp_new_query_text" class="form-input" rows="2" placeholder="Enter query details for investigator / coordinator..." style="width: 100%; font-size: 0.875rem; resize: vertical;"></textarea>
                        </div>
                        <div style="text-align: right;">
                            <button type="button" onclick="submitNewCommonQuery(${rec.id})" class="btn btn-sm btn-primary" style="font-weight: 600; display: inline-flex; align-items: center; gap: 4px;">
                                <span class="material-icons-round" style="font-size: 1rem;">send</span> Submit Query
                            </button>
                        </div>
                    </div>
                `;
            }
        }

        const allQueries = rec.queries || [];
        const queriesToShow = fKey ? allQueries.filter(q => q.field_name === fKey) : allQueries;

        // Filter status banner
        if (fKey) {
            html += `
                <div style="display: flex; justify-content: space-between; align-items: center; background: #f0fdf4; border: 1px solid #bbf7d0; padding: 0.55rem 0.85rem; border-radius: 6px; margin-bottom: 1rem; font-size: 0.825rem;">
                    <div style="color: #166534; font-weight: 600; display: flex; align-items: center; gap: 6px;">
                        <span class="material-icons-round" style="font-size: 1.15rem; color: #16a34a;">filter_alt</span>
                        <span>Showing queries for field: <strong>${escapeHtml(fLabel)}</strong> (${queriesToShow.length})</span>
                    </div>
                    <button type="button" class="btn btn-sm" onclick="openCommonQueryModal(${rec.id}, null, null)" style="background: white; border: 1px solid #cbd5e1; color: #334155; font-size: 0.75rem; padding: 2px 8px; font-weight: 600; cursor: pointer;">
                        Show All (${allQueries.length}) Queries
                    </button>
                </div>
            `;
        }

        if (queriesToShow.length === 0) {
            if (fKey) {
                html += `
                    <div style="text-align: center; padding: 2rem 1rem; background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 8px; margin-bottom: 1rem;">
                        <span class="material-icons-round" style="font-size: 2.2rem; color: #94a3b8; display: block; margin-bottom: 0.5rem;">help_outline</span>
                        <div style="color: #334155; font-weight: 600; font-size: 0.9rem;">No queries raised yet for ${escapeHtml(fLabel)}</div>
                        <div style="color: #64748b; font-size: 0.8rem; margin-top: 4px;">You can raise a query on this field using the box above.</div>
                        <button type="button" class="btn btn-sm btn-outline" onclick="openCommonQueryModal(${rec.id}, null, null)" style="margin-top: 0.85rem; font-size: 0.775rem;">
                            View All (${allQueries.length}) Record Queries
                        </button>
                    </div>
                `;
            } else {
                html += `<p style="text-align: center; color: #94a3b8; padding: 2rem;">No queries raised for this record.</p>`;
            }
        } else {
            queriesToShow.forEach(q => {
                const isClosed = q.status === 'closed';
                const statusColor = isClosed ? '#64748b' : (q.status === 'answered' ? '#047857' : '#dc2626');
                const statusBg = isClosed ? '#f1f5f9' : (q.status === 'answered' ? '#ecfdf5' : '#fef2f2');

                // Determine field label
                let fieldDisplay = 'Record Level';
                if (q.field_name) {
                    const fMatch = (COMMON_FORM_FIELD_MAP[formType] || []).find(f => f.key === q.field_name);
                    fieldDisplay = fMatch ? fMatch.label : q.field_name;
                }

                // Check if query thread has a proposed value
                let latestProposedValue = null;
                if (q.history && q.history.length > 0) {
                    for (let i = q.history.length - 1; i >= 0; i--) {
                        if (q.history[i].new_value !== null && q.history[i].new_value !== undefined) {
                            latestProposedValue = q.history[i].new_value;
                            break;
                        }
                    }
                }

                const isCurrentFilteredField = fKey && q.field_name === fKey;
                const fieldBadgeStyle = isCurrentFilteredField 
                    ? 'background: #dbeafe; color: #1d4ed8; border: 1px solid #93c5fd;' 
                    : 'background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; cursor: pointer;';

                const fieldBadgeClick = q.field_name 
                    ? `onclick="openCommonQueryModal(${rec.id}, '${q.field_name}', '${escapeHtml(fieldDisplay)}');"` 
                    : '';

                html += `
                    <div style="border: 1px solid ${isCurrentFilteredField ? '#bfdbfe' : '#e2e8f0'}; border-radius: 8px; padding: 1rem; margin-bottom: 1rem; background: white; box-shadow: 0 1px 2px rgba(0,0,0,0.03);">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                            <div>
                                <span style="font-weight: 700; color: #1e293b; font-size: 0.9rem;">Query #${q.id}</span>
                                <span style="${fieldBadgeStyle} padding: 2px 7px; border-radius: 4px; font-size: 0.75rem; margin-left: 6px; font-weight: 600;" title="Click to filter by this field" ${fieldBadgeClick}>
                                    <span class="material-icons-round" style="font-size: 11px; vertical-align: middle;">label</span> ${escapeHtml(fieldDisplay)}
                                </span>
                            </div>
                            <span style="background: ${statusBg}; color: ${statusColor}; padding: 2px 8px; border-radius: 99px; font-weight: 700; font-size: 0.75rem;">${q.status.toUpperCase()}</span>
                        </div>
                        <p style="margin: 0 0 0.5rem 0; color: #334155; font-size: 0.875rem;">${escapeHtml(q.query_text)}</p>
                        <div style="font-size: 0.75rem; color: #64748b; margin-bottom: 0.75rem;">
                            Raised by <strong>${escapeHtml(q.created_by)}</strong> 
                            <span style="background: #e2e8f0; color: #334155; padding: 1px 7px; border-radius: 99px; font-weight: 600; font-size: 0.7rem; margin-left: 4px;">${escapeHtml(q.raised_by_role || 'Data Monitor')}</span> 
                            on ${q.created_at}
                        </div>

                        <!-- Thread history -->
                        <div style="padding-left: 0.85rem; border-left: 3px solid #e2e8f0; display: flex; flex-direction: column; gap: 0.5rem; margin-bottom: 0.75rem;">
                `;

                (q.history || []).forEach(h => {
                    const isAns = h.action_type === 'answered';
                    const hasValChange = (h.new_value !== null && h.new_value !== undefined && h.new_value !== '');

                    html += `
                        <div style="font-size: 0.8rem; background: #f8fafc; padding: 0.6rem 0.75rem; border-radius: 6px;">
                            <div style="display: flex; justify-content: space-between; margin-bottom: 2px;">
                                <strong>${escapeHtml(h.user || 'System')}</strong>
                                <span style="color: #94a3b8; font-size: 0.7rem;">${h.created_at}</span>
                            </div>
                            <div style="color: #334155;"><span style="font-weight: 600; text-transform: uppercase; font-size: 0.7rem; color: #64748b;">[${h.action_type}]:</span> ${escapeHtml(h.remark)}</div>
                            ${hasValChange ? `
                                <div style="margin-top: 5px; padding: 4px 8px; background: #fffbeb; border: 1px solid #fef3c7; border-radius: 4px; font-size: 0.75rem; color: #92400e;">
                                    <strong>Proposed Field Value:</strong> <span style="text-decoration: line-through; color: #dc2626;">"${escapeHtml(h.old_value || '(empty)')}"</span> &rarr; <span style="font-weight: 700; color: #047857;">"${escapeHtml(h.new_value)}"</span>
                                </div>
                            ` : ''}
                        </div>
                    `;
                });

                html += `</div>`;

                const isPiSigned = (rec.pi_signed_off === true || rec.pi_signed_off === 't' || rec.pi_signed_off === 1 || rec.pi_signed_off === '1');

                // Coordinator Answer Form
                if (q.status !== 'closed' && (USER_IS_COORDINATOR || USER_IS_ADMIN) && !isPiSigned) {
                    const rawVal = (q.field_name && rec.data) ? (rec.data[q.field_name] !== undefined ? rec.data[q.field_name] : '') : '';
                    const currentValDisplay = Array.isArray(rawVal) ? rawVal.join(', ') : String(rawVal || '');
                    html += `
                        <div style="margin-top: 0.75rem; padding: 0.85rem; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px;">
                            <label style="font-weight: 600; font-size: 0.8rem; color: #1e293b; display: block; margin-bottom: 0.35rem;">
                                Respond to Query #${q.id}
                            </label>
                            ${q.field_name ? `
                                <div style="margin-bottom: 0.6rem;">
                                    <div style="font-size: 0.75rem; color: #64748b; margin-bottom: 4px;">
                                        Current Value for <strong>${escapeHtml(fieldDisplay)}</strong>: 
                                        <code style="background: #e2e8f0; padding: 1px 6px; border-radius: 4px; color: #0f172a; font-weight: 600;">${escapeHtml(currentValDisplay || '(empty)')}</code>
                                        ${latestProposedValue !== null ? ` &bull; Latest Proposed: <code style="background: #fef3c7; color: #92400e; padding: 1px 6px; border-radius: 4px; font-weight: 600;">${escapeHtml(latestProposedValue)}</code>` : ''}
                                    </div>
                                    <div style="margin-bottom: 2px;">
                                        <label style="font-size: 0.72rem; font-weight: 600; color: #475569; display: block; margin-bottom: 2px;">
                                            Proposed New Value:
                                        </label>
                                        ${renderCommonQueryProposedInput(formType, q.field_name, q.id, rawVal)}
                                    </div>
                                </div>
                            ` : ''}
                            <div style="display: flex; gap: 0.5rem; align-items: center; margin-top: 0.5rem;">
                                <input type="text" id="inp_ans_query_${q.id}" class="form-input" placeholder="Enter response remark..." style="flex: 1; font-size: 0.8rem;">
                                <button type="button" onclick="submitCommonQueryAnswer(${q.id}, ${rec.id})" class="btn btn-sm btn-primary" style="white-space: nowrap; font-weight: 600;">Submit Response</button>
                            </div>
                        </div>
                    `;
                }

                // Reviewer Close / Re-open Controls
                if (isMonitorOrManager && !isPiSigned) {
                    const qRoleLower = (q.raised_by_role || '').toLowerCase();
                    const isQMon = qRoleLower.includes('monitor');
                    const isQMgr = qRoleLower.includes('manager');

                    let canCloseThisQuery = USER_IS_ADMIN;
                    if (USER_IS_MONITOR && isQMon) {
                        canCloseThisQuery = true;
                    } else if (USER_IS_MANAGER && isQMgr) {
                        canCloseThisQuery = true;
                    } else if (!isQMon && !isQMgr) {
                        canCloseThisQuery = isMonitorOrManager;
                    }

                    html += `<div style="margin-top: 0.75rem;">`;
                    if (canCloseThisQuery) {
                        if (q.status !== 'closed') {
                            if (latestProposedValue !== null && q.field_name) {
                                html += `
                                    <div style="margin-bottom: 0.5rem; font-size: 0.75rem; color: #92400e; background: #fffbeb; border: 1px solid #fde68a; padding: 0.5rem; border-radius: 6px;">
                                        <span class="material-icons-round" style="font-size: 13px; vertical-align: middle;">info</span> 
                                        Closing this query will update <strong>${escapeHtml(fieldDisplay)}</strong> to <strong>"${escapeHtml(latestProposedValue)}"</strong> and will automatically <strong>revoke SDR state</strong>, requiring re-submission.
                                    </div>
                                `;
                            }
                            html += `
                                <div style="display: flex; gap: 0.5rem; align-items: center;">
                                    <input type="text" id="inp_close_remark_${q.id}" class="form-input" placeholder="Optional closure remark..." style="flex: 1; font-size: 0.8rem;">
                                    <button type="button" onclick="updateCommonQueryStatus(${q.id}, 'close', ${rec.id})" class="btn btn-sm" style="background: #059669; color: #fff; border: 1px solid #059669; font-weight: 600; font-size: 0.75rem; display: inline-flex; align-items: center; gap: 3px;">
                                        <span class="material-icons-round" style="font-size: 14px;">check_circle</span> Close Query
                                    </button>
                                    <button type="button" onclick="updateCommonQueryStatus(${q.id}, 'reopen', ${rec.id})" class="btn btn-sm btn-outline" style="font-size: 0.75rem; display: inline-flex; align-items: center; gap: 3px;">
                                        <span class="material-icons-round" style="font-size: 14px;">refresh</span> Re-query
                                    </button>
                                </div>
                            `;
                        } else {
                            html += `
                                <div style="display: flex; gap: 0.5rem; align-items: center;">
                                    <input type="text" id="inp_close_remark_${q.id}" class="form-input" placeholder="Reason for re-opening query..." style="flex: 1; font-size: 0.8rem;">
                                    <button type="button" onclick="updateCommonQueryStatus(${q.id}, 'reopen', ${rec.id})" class="btn btn-sm btn-outline" style="font-size: 0.75rem; display: inline-flex; align-items: center; gap: 3px;">
                                        <span class="material-icons-round" style="font-size: 14px;">refresh</span> Re-open Query
                                    </button>
                                </div>
                            `;
                        }
                    } else if (q.status !== 'closed') {
                        const targetRoleName = isQMon ? 'Data Monitor' : 'Data Manager';
                        html += `
                            <div style="font-size: 0.75rem; color: #64748b; background: #f8fafc; border: 1px solid #e2e8f0; padding: 0.4rem 0.65rem; border-radius: 6px; display: inline-flex; align-items: center; gap: 5px;">
                                <span class="material-icons-round" style="font-size: 14px; color: #94a3b8;">lock</span> 
                                Raised by <strong>${escapeHtml(q.raised_by_role || targetRoleName)}</strong> &mdash; can only be closed or re-queried by <strong>${targetRoleName}</strong>.
                            </div>
                        `;
                    }
                    html += `</div>`;
                }

                if (isPiSigned) {
                    html += `
                        <div style="margin-top: 0.75rem; padding: 0.5rem 0.75rem; background: #e0e7ff; color: #3730a3; border-radius: 6px; font-size: 0.75rem; display: flex; align-items: center; gap: 6px;">
                            <span class="material-icons-round" style="font-size: 14px;">lock</span> Record is Signed Off by Principal Investigator. Queries cannot be answered or modified.
                        </div>
                    `;
                }

                html += `</div>`;
            });
        }

        qBody.innerHTML = html;
        openModal('modalCommonQuery');
    });
}

function openCommonQueryModalFromEditor() {
    const recId = parseInt(document.getElementById('cfRecordId').value);
    if (recId > 0) {
        openCommonQueryModal(recId, null, null);
    }
}

function submitNewCommonQuery(recordId) {
    const textEl = document.getElementById('inp_new_query_text');
    const fieldEl = document.getElementById('inp_new_query_field');
    const text = textEl ? textEl.value.trim() : '';
    const fieldName = fieldEl ? fieldEl.value.trim() : '';

    if (text === '') {
        showToast('Query text cannot be empty', 'warning');
        return;
    }

    const fd = new FormData();
    fd.append('action', 'raise_query');
    fd.append('record_id', recordId);
    fd.append('query_text', text);
    if (fieldName !== '') {
        fd.append('field_name', fieldName);
    }

    fetch('ajax_common_forms.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
        if (!res.success) {
            showToast(res.message || 'Error raising query', 'error');
            return;
        }
        showToast(res.message, 'success');
        openCommonQueryModal(recordId, currentCommonQueryTargetField, currentCommonQueryTargetLabel);
        loadCommonRecords();
        refreshEditorRecordData(recordId);
    })
    .catch(err => {
        console.error(err);
        showToast('Network error raising query', 'error');
    });
}

function submitCommonQueryAnswer(queryId, recordId) {
    const remarkEl = document.getElementById('inp_ans_query_' + queryId);
    const valEl = document.getElementById('inp_ans_val_' + queryId);
    const remark = remarkEl ? remarkEl.value.trim() : '';
    const proposedVal = valEl ? valEl.value.trim() : null;

    if (remark === '') {
        showToast('Please enter a response remark', 'warning');
        return;
    }

    const fd = new FormData();
    fd.append('action', 'answer_query');
    fd.append('query_id', queryId);
    fd.append('remark', remark);
    if (proposedVal !== null && proposedVal !== '') {
        fd.append('field_value', proposedVal);
    }

    fetch('ajax_common_forms.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
        if (!res.success) {
            showToast(res.message || 'Error answering query', 'error');
            return;
        }
        showToast(res.message, 'success');
        openCommonQueryModal(recordId, currentCommonQueryTargetField, currentCommonQueryTargetLabel);
        loadCommonRecords();
        refreshEditorRecordData(recordId);
    })
    .catch(err => {
        console.error(err);
        showToast('Network error submitting response', 'error');
    });
}

function updateCommonQueryStatus(queryId, action, recordId) {
    const remarkEl = document.getElementById('inp_close_remark_' + queryId);
    const remark = remarkEl ? remarkEl.value.trim() : '';

    const fd = new FormData();
    fd.append('action', 'close_query');
    fd.append('query_id', queryId);
    fd.append('query_action', action);
    if (remark !== '') {
        fd.append('remark', remark);
    }

    fetch('ajax_common_forms.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
        if (!res.success) {
            showToast(res.message || 'Error updating query status', 'error');
            return;
        }
        showToast(res.message, 'success');
        openCommonQueryModal(recordId, currentCommonQueryTargetField, currentCommonQueryTargetLabel);
        loadCommonRecords();
        refreshEditorRecordData(recordId);
    })
    .catch(err => {
        console.error(err);
        showToast('Network error updating query status', 'error');
    });
}

// Audit Trail Modal
function openCommonAuditModal(recordId) {
    const fd = new FormData();
    fd.append('action', 'get_record');
    fd.append('record_id', recordId);

    fetch('ajax_common_forms.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
        if (!res.success) {
            showToast('Error loading audit trail', 'error');
            return;
        }

        const rec = res.record;
        document.getElementById('cfAuditRecordTitle').textContent = `Clinical Audit Trail: ${rec.record_number}`;
        const aBody = document.getElementById('cfAuditBody');

        // Header Summary Card
        let sdrSubText = rec.sdr_submitted ? `<span style="color: #1d4ed8; font-weight: 600;">Yes (${escapeHtml(rec.sdr_by_name || 'Coordinator')} on ${rec.sdr_at || '-'})</span>` : '<span style="color: #64748b;">Pending</span>';
        let monRevText = rec.monitor_reviewed ? `<span style="color: #047857; font-weight: 600;">SDVed (${escapeHtml(rec.monitor_by_name || 'Monitor')} on ${rec.monitor_at || '-'})</span>` : '<span style="color: #64748b;">Not SDVed</span>';
        let mgrRevText = rec.manager_reviewed ? `<span style="color: #6d28d9; font-weight: 600;">DM Reviewed (${escapeHtml(rec.manager_by_name || 'Manager')} on ${rec.manager_at || '-'})</span>` : '<span style="color: #64748b;">Not Reviewed</span>';

        let html = `
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 1rem; margin-bottom: 1.25rem;">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.75rem; font-size: 0.825rem;">
                    <div><span style="color: #64748b;">Record Status:</span> <strong style="text-transform: uppercase;">${escapeHtml(rec.status)} (v${rec.revision || 1})</strong></div>
                    <div><span style="color: #64748b;">SDR Submitted:</span> ${sdrSubText}</div>
                    <div><span style="color: #64748b;">Monitor Review:</span> ${monRevText}</div>
                    <div><span style="color: #64748b;">DM Review:</span> ${mgrRevText}</div>
                </div>
            </div>
        `;

        // Audit Trail Table
        if (!rec.audit_trail || rec.audit_trail.length === 0) {
            html += `<p style="text-align: center; color: #94a3b8; padding: 2rem;">No audit logs recorded for this record.</p>`;
        } else {
            const badgeMap = {
                'create': { bg: '#ecfdf5', color: '#047857', label: 'Record Created' },
                'update': { bg: '#eff6ff', color: '#1d4ed8', label: 'Data Update' },
                'sdr_submitted': { bg: '#ecfeff', color: '#0e7490', label: 'SDR Submitted' },
                'sdr_revoked': { bg: '#fff7ed', color: '#c2410c', label: 'SDR Revoked' },
                'monitor_reviewed': { bg: '#ecfdf5', color: '#047857', label: 'SDVed' },
                'monitor_revoked': { bg: '#fef2f2', color: '#dc2626', label: 'SDV Revoked' },
                'manager_reviewed': { bg: '#f5f3ff', color: '#6d28d9', label: 'DM Reviewed' },
                'manager_revoked': { bg: '#fef2f2', color: '#dc2626', label: 'DM Revoked' },
                'query_raised': { bg: '#fffbeb', color: '#b45309', label: 'Query Raised' },
                'query_answered': { bg: '#eff6ff', color: '#1d4ed8', label: 'Query Answered' },
                'query_closed': { bg: '#ecfdf5', color: '#047857', label: 'Query Closed' },
                'query_reopened': { bg: '#fffbeb', color: '#b45309', label: 'Query Re-opened' },
                'void': { bg: '#fef2f2', color: '#dc2626', label: 'Record Voided' }
            };

            html += `
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse; font-size: 0.8rem;">
                        <thead style="background: #f1f5f9; border-bottom: 2px solid #cbd5e1; color: #334155;">
                            <tr>
                                <th style="padding: 0.65rem 0.75rem; text-align: left; white-space: nowrap;">Timestamp</th>
                                <th style="padding: 0.65rem 0.75rem; text-align: left; white-space: nowrap;">Action / Type</th>
                                <th style="padding: 0.65rem 0.75rem; text-align: left; white-space: nowrap;">Field</th>
                                <th style="padding: 0.65rem 0.75rem; text-align: left;">Previous Value</th>
                                <th style="padding: 0.65rem 0.75rem; text-align: left;">New Value</th>
                                <th style="padding: 0.65rem 0.75rem; text-align: left;">Reason / Remarks</th>
                                <th style="padding: 0.65rem 0.75rem; text-align: left; white-space: nowrap;">Performed By</th>
                            </tr>
                        </thead>
                        <tbody>
            `;

            rec.audit_trail.forEach(aud => {
                const badgeInfo = badgeMap[aud.change_type] || { bg: '#f1f5f9', color: '#475569', label: aud.change_type || 'Action' };

                html += `
                    <tr style="border-bottom: 1px solid #f1f5f9;">
                        <td style="padding: 0.65rem 0.75rem; color: #64748b; white-space: nowrap;">${aud.action_at}</td>
                        <td style="padding: 0.65rem 0.75rem; white-space: nowrap;">
                            <span style="background: ${badgeInfo.bg}; color: ${badgeInfo.color}; padding: 2px 7px; border-radius: 99px; font-weight: 700; font-size: 0.725rem;">
                                ${escapeHtml(badgeInfo.label)}
                            </span>
                        </td>
                        <td style="padding: 0.65rem 0.75rem; font-weight: 600; color: #0f172a; white-space: nowrap;">${escapeHtml(aud.field_name)}</td>
                        <td style="padding: 0.65rem 0.75rem; color: #ef4444; max-width: 140px; word-break: break-word;">${escapeHtml(aud.old_value || '(empty)')}</td>
                        <td style="padding: 0.65rem 0.75rem; color: #047857; max-width: 140px; word-break: break-word;">${escapeHtml(aud.new_value || '(empty)')}</td>
                        <td style="padding: 0.65rem 0.75rem; color: #334155; font-size: 0.775rem;">${escapeHtml(aud.reason || '-')}</td>
                        <td style="padding: 0.65rem 0.75rem; color: #475569; white-space: nowrap; font-weight: 500;">${escapeHtml(aud.user || '-')}</td>
                    </tr>
                `;
            });

            html += `</tbody></table></div>`;
        }

        aBody.innerHTML = html;
        openModal('modalCommonAudit');
    })
    .catch(err => {
        console.error(err);
        showToast('Network error loading audit trail', 'error');
    });
}

function openCommonAuditModalFromEditor() {
    const recId = parseInt(document.getElementById('cfRecordId').value);
    if (recId > 0) {
        openCommonAuditModal(recId);
    }
}
</script>

