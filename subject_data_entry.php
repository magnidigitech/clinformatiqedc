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
if (!in_array($current_common_form, ['mh', 'ae', 'cm'], true)) {
    $current_common_form = '';
}

// --- AUTO-FIX SCHEMA (Temporary Migration) ---
// Ensure the new columns exist on the remote server
try {
    $pdo->exec("ALTER TABLE data_queries ADD COLUMN repeating_instance_id INT DEFAULT 0 AFTER field_id");
} catch (PDOException $e) { /* Column likely exists */ }
try {
    $pdo->exec("ALTER TABLE data_comments ADD COLUMN repeating_instance_id INT DEFAULT 0 AFTER field_id");
} catch (PDOException $e) { /* Column likely exists */ }
try {
    $pdo->exec("ALTER TABLE data_audit_log ADD COLUMN repeating_instance_id INT DEFAULT 0 AFTER field_id");
} catch (PDOException $e) { /* Column likely exists */ }
// ---------------------------------------------

// Helper to check roles
$current_role = strtolower($_SESSION['active_role_name'] ?? '');
// Monitor includes data monitor and data manager roles
$is_monitor = (strpos($current_role, 'monitor') !== false) || (strpos($current_role, 'manager') !== false);
// Manager includes coordinator (which represents data coordinator role)
$is_manager = (strpos($current_role, 'coordinator') !== false); 
$is_admin = (strpos($current_role, 'admin') !== false);
$can_edit = ($current_role === 'data_entry' || strpos($current_role, 'entry') !== false || $current_role === 'investigator' || $is_manager || (strpos($current_role, 'manager') !== false));

// Specific precise roles for workflow
$is_coordinator = (strpos($current_role, 'coordinator') !== false) || (strpos($current_role, 'admin') !== false);
$is_monitor_role = (strpos($current_role, 'monitor') !== false) || (strpos($current_role, 'admin') !== false);
$is_manager_role = (strpos($current_role, 'manager') !== false) || (strpos($current_role, 'admin') !== false);

// Review status resolver is now defined in includes/functions.php

// Helper to check mandatory fields completion
function areAllMandatoryFieldsCompletedPHP($pdo, $subject_id, $form_id, $repeating_instance_id) {
    // Get all mandatory field IDs for this form
    $stmt = $pdo->prepare("SELECT id FROM form_fields WHERE form_id = ? AND is_required = TRUE");
    $stmt->execute([$form_id]);
    $mandatory_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($mandatory_ids)) {
        return true;
    }
    
    // Check how many of these mandatory fields have a filled value in subject_data
    $placeholders = implode(',', array_fill(0, count($mandatory_ids), '?'));
    $sql = "SELECT COUNT(DISTINCT field_id) FROM subject_data 
            WHERE subject_id = ? AND form_id = ? AND field_id IN ($placeholders) 
            AND (repeating_instance_id = ? OR (? = 0 AND repeating_instance_id IS NULL)) 
            AND value IS NOT NULL AND CHAR_LENGTH(value) > 0";
    
    $params = array_merge([$subject_id, $form_id], $mandatory_ids, [$repeating_instance_id, $repeating_instance_id]);
    $stmt_filled = $pdo->prepare($sql);
    $stmt_filled->execute($params);
    $filled_count = (int)$stmt_filled->fetchColumn();
    
    return $filled_count === count($mandatory_ids);
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

// Fetch Study Visits & Forms for Sidebar Tree
$stmt = $pdo->prepare("SELECT v.id as visit_id, v.name as visit_name, f.id as form_id, f.name as form_name 
                       FROM study_visits v 
                       LEFT JOIN study_forms f ON v.id = f.visit_id 
                       WHERE v.study_id = ? 
                       ORDER BY v.order_index, f.order_index");
$stmt->execute([$study_id]);
$tree_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch ALL Form Statuses for this Subject
$stmt_status = $pdo->prepare("SELECT form_id, status, progress_percent, is_verified, sdr_submitted, monitor_reviewed, manager_reviewed, COALESCE(repeating_instance_id, 0) as repeating_instance_id FROM subject_form_status WHERE subject_id = ?");
$stmt_status->execute([$subject_id]);
$raw_statuses = $stmt_status->fetchAll(PDO::FETCH_ASSOC);
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


// Organize into Tree + Calculate Visit Progress
$structure = [];
$total_forms_count = 0;
$total_progress_sum = 0;

foreach ($tree_data as $row) {
    $v_id = $row['visit_id'];
    if (!isset($structure[$v_id])) {
        $structure[$v_id] = [
            'name' => $row['visit_name'],
            'forms' => [],
            'visit_progress_sum' => 0,
            'visit_forms_count' => 0
        ];
    }
    
    if ($row['form_id']) {
        $f_id = $row['form_id'];
        $f_stat = $statuses[$f_id . '_0'] ?? ['status' => 'empty', 'progress' => 0];
        
        $structure[$v_id]['forms'][] = [
            'id' => $f_id,
            'name' => $row['form_name'],
            'status' => $f_stat['status'],
            'progress' => $f_stat['progress'],
            'query_count' => $f_stat['query_count'] ?? 0
        ];
        
        $structure[$v_id]['visit_progress_sum'] += $f_stat['progress'];
        $structure[$v_id]['visit_forms_count']++;
        $structure[$v_id]['visit_query_sum'] = ($structure[$v_id]['visit_query_sum'] ?? 0) + ($f_stat['query_count'] ?? 0);
        
        $total_progress_sum += $f_stat['progress'];
        $total_forms_count++;
    }
}

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
            $flat_forms[] = ['visit_id' => $vid, 'form_id' => $frm['id']];
        }
    }

    foreach ($flat_forms as $i => $item) {
        if ($item['form_id'] == $current_form_id) {
            if (isset($flat_forms[$i - 1])) {
                $prev = $flat_forms[$i - 1];
                $prev_link = "?subject_id=$subject_id&visit_id={$prev['visit_id']}&form_id={$prev['form_id']}";
            }
            if (isset($flat_forms[$i + 1])) {
                $next = $flat_forms[$i + 1];
                $next_link = "?subject_id=$subject_id&visit_id={$next['visit_id']}&form_id={$next['form_id']}";
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
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
    <style>
        .entry-layout { display: flex; height: calc(100vh - 64px); background: #f8fafc; }
        .entry-sidebar { width: 300px; background: white; border-right: 1px solid var(--border-color); overflow-y: auto; flex-shrink: 0; display: flex; flex-direction: column; }
        .entry-main { flex: 1; padding: 2rem; overflow-y: auto; }
        
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
// Determine View Mode
$can_edit = $current_form_id ? hasPermission('enter_data') : false;

// Check if current form is verifiable
$curr_stat_key = $current_form_id ? ($current_form_id . '_' . (int)($current_instance_id ?? 0)) : '0_0';
$curr_stat = $statuses[$curr_stat_key] ?? [
    'status' => 'empty', 
    'progress' => 0, 
    'is_verified' => 0,
    'sdr_submitted' => 0,
    'monitor_reviewed' => 0,
    'manager_reviewed' => 0
];
$is_complete = ($curr_stat['status'] === 'complete' || $curr_stat['progress'] == 100);
$is_verified = ($curr_stat['status'] === 'verified' || !empty($curr_stat['is_verified'])); // Check both for safety

$sdr_submitted = (bool)($curr_stat['sdr_submitted'] ?? false);
$monitor_reviewed = (bool)($curr_stat['monitor_reviewed'] ?? false);
$manager_reviewed = (bool)($curr_stat['manager_reviewed'] ?? false);

if ($sdr_submitted) {
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
                        <div class="visit-header <?php echo $is_active ? 'active' : ''; ?>" onclick="toggleVisit(this)" style="display: block; position: relative; padding: 0.75rem 1rem;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                                <div style="display: flex; align-items: center; gap: 0.5rem; font-weight: 600; color: #334155;">
                                     <!-- Simple chevron logic -->
                                     <span class="material-icons-round chevron" style="font-size: 1.25rem; color: #94a3b8; transition: transform 0.2s;">expand_more</span>
                                     <?php echo htmlspecialchars($visit['name']); ?>
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
                                ?>
                                <a href="?subject_id=<?php echo $subject_id; ?>&visit_id=<?php echo $vid; ?>&form_id=<?php echo $form['id']; ?>" 
                                   class="tree-form <?php echo ($form['id'] == $current_form_id && !$current_module_id) ? 'active' : ''; ?>">
                                   
                                   <div style="display: flex; align-items: center; gap: 0.75rem;">
                                       <!-- Status Icon -->
                                       <span id="form-icon-<?php echo $form['id']; ?>-0" class="material-icons-round" style="font-size: 1.25rem; color: <?php echo $rev_status['color']; ?>;">
                                            <?php echo $rev_status['icon']; ?>
                                       </span>
                                       
                                       <span style="font-weight: 500; color: var(--text-main);"><?php echo htmlspecialchars($form['name']); ?></span>
                                       
                                       <?php if($q_cnt > 0): ?>
                                           <span style="background: #ef4444; color: white; font-size: 0.65rem; font-weight: 600; padding: 1px 5px; border-radius: 99px; min-width: 16px; text-align: center;">
                                                <?php echo $q_cnt; ?>
                                           </span>
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
                 </div>

            </div>
        </aside>

        <!-- Main Form Area -->
        <main class="entry-main">
            <?php if (!empty($current_common_form)): ?>
                <!-- COMMON FORMS VIEW (MH / AE / CM) -->
                <div class="crf-card" style="max-width: 1200px;">
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
                                ?>
                                <span id="commonFormRecordCountBadge" style="background: #e2e8f0; color: #475569; font-size: 0.8rem; padding: 2px 8px; border-radius: 99px; font-weight: 600;">0</span>
                            </h1>
                        </div>
                        
                        <?php if ($is_coordinator || $is_admin): ?>
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

                            <select id="commonSdrFilter" onchange="loadCommonRecords()" class="form-input" style="width: 165px; height: 38px; padding: 0 0.75rem; font-size: 0.85rem; line-height: 38px; box-sizing: border-box; vertical-align: middle;">
                                <option value="">All SDR Statuses</option>
                                <option value="pending">Pending SDR</option>
                                <option value="reviewed">Reviewed</option>
                                <option value="needs_rereview">Needs Re-review</option>
                            </select>

                            <label style="display: flex; align-items: center; gap: 0.4rem; font-size: 0.85rem; color: #475569; cursor: pointer;">
                                <input type="checkbox" id="commonIncludeVoided" onchange="loadCommonRecords()"> Include Voided
                            </label>
                        </div>

                        <!-- Summary KPI Badges -->
                        <div id="commonSummaryKpis" style="display: flex; gap: 0.5rem; font-size: 0.75rem; font-weight: 600;">
                            <!-- Populated dynamically via JS -->
                        </div>
                    </div>

                    <div class="crf-body" style="padding: 0; overflow-x: auto;">
                        <table style="width: 100%; border-collapse: collapse; font-size: 0.875rem;" id="commonRecordsTable">
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
                        <?php if (hasPermission('enter_data')): ?>
                            <button class="btn btn-primary" onclick="openAddInstanceModal()">+ Add New Entry</button>
                        <?php endif; ?>
                    </div>
                    
                    <div class="crf-body" style="padding: 0;">
                        <?php if(empty($instances[$current_module_id] ?? [])): ?>
                             <div style="padding: 3rem; text-align: center; color: var(--text-light);">
                                <span class="material-icons-round" style="font-size: 3rem; color: #cbd5e1; margin-bottom: 1rem;">toc</span>
                                <p style="margin-bottom: 1.5rem;">No entries found for this module.</p>
                                <?php if (hasPermission('enter_data')): ?>
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
                            WHERE a.subject_id = ? AND a.form_id = ? AND (a.repeating_instance_id = ? OR (? = 0 AND a.repeating_instance_id IS NULL)) AND a.change_type IN ('monitor_revoked', 'manager_revoked')
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
                            ?>
                            <div style="display: flex; align-items: center; gap: 1rem;">
                                <!-- SDR / Review Workflow Buttons -->
                                <div id="workflow-buttons-container" style="display: inline-flex; align-items: center; gap: 1rem;">
                                    <?php echo renderWorkflowButtons($is_coordinator, $is_monitor_role, $is_manager_role, $sdr_submitted, $monitor_reviewed, $manager_reviewed, $all_mandatory_completed); ?>
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
                        <?php if (empty($fields)): ?>
                            <div style="padding: 3rem; text-align: center; color: var(--text-light);">
                                <?php echo $current_form_id ? 'No fields defined for this form.' : 'Please select a form.'; ?>
                            </div>
                        <?php else: ?>
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
                                <div class="crf-field" data-field-id="<?php echo $field['id']; ?>" <?php if ($field['is_required']) echo 'data-required="true"'; ?>>
                                    <div class="field-label-row">
                                        <!-- Field Status Icon -->
                                        <span class="material-icons-round status-icon" style="color: <?php echo $is_f ? 'var(--accent-color)' : '#94a3b8'; ?>">
                                            <?php echo $is_f ? 'check_circle' : 'radio_button_unchecked'; ?>
                                        </span>
                                        
                                        <!-- Field Label -->
                                        <div class="field-label" style="display: flex; align-items: center; gap: 0.25rem; flex-wrap: wrap;">
                                            <span><?php echo ($index + 1) . '. ' . htmlspecialchars($field['label']); ?></span>
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
                                                    $is_form_reviewed_for_role = ($is_monitor_role && $monitor_reviewed) || ($is_manager_role && $manager_reviewed);
                                                    
                                                    if ($active_query_count > 0) {
                                                        if ($is_form_reviewed_for_role) {
                                                            $query_tooltip = "Cannot Raise Query&#10;as the form is marked as review";
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
                                                            $query_tooltip = "Cannot Raise Query&#10;as the form is marked as review";
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
            <label style="display: block; font-weight: 600; font-size: 0.9rem; color: var(--text-dark); margin-bottom: 0.5rem;">Reason for Revoking Review <span style="color: red;">*</span></label>
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
    const isMonitorRole = <?php echo $is_monitor_role ? 'true' : 'false'; ?>;
    const isManagerRole = <?php echo $is_manager_role ? 'true' : 'false'; ?>;
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
            // Validate required fields
            const requiredFields = document.querySelectorAll('.crf-field[data-required="true"]');
            let firstMissingField = null;
            for (const field of requiredFields) {
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
            message = 'Are you sure you want to mark this form as Source Data Verification (SDR) ready? The form will become read-only and locked for editing.';
            btnText = 'Yes, Mark as SDR';
            btnBg = '#1d6f97';
        } else if (workflowAction === 'revoke_sdr') {
            title = 'Revoke SDR';
            message = 'Are you sure you want to revoke the SDR submission? This will return the form to Draft mode and make it editable again.';
            btnText = 'Yes, Revoke SDR';
            btnBg = '#dc2626';
        } else if (workflowAction === 'monitor_review') {
            title = 'Complete Monitor Review';
            message = 'Are you sure you want to complete the Monitor review for this form? The review progress will increase to 50%.';
            btnText = 'Yes, Mark as Reviewed';
            btnBg = '#ea580c';
        } else if (workflowAction === 'monitor_revoke') {
            title = 'Reason for Revoking Review';
            message = 'Are you sure you want to revoke this review? The SDR status will be reset, and the Data Coordinator will be able to update and resubmit the record.';
            btnText = 'Confirm Revoke';
            btnBg = '#ea580c';
        } else if (workflowAction === 'manager_review') {
            title = 'Complete Manager Review';
            message = 'Are you sure you want to complete the Manager review for this form? The review progress will increase to 100% and the form will be SRVed.';
            btnText = 'Yes, Mark as Reviewed';
            btnBg = '#0d8e6f';
        } else if (workflowAction === 'manager_revoke') {
            title = 'Reason for Revoking Review';
            message = 'Are you sure you want to revoke this review? The SDR status will be reset, and the Data Coordinator will be able to update and resubmit the record.';
            btnText = 'Confirm Revoke';
            btnBg = '#0d8e6f';
        }

        document.getElementById('workflowModalTitle').textContent = title;
        document.getElementById('workflowModalMsg').textContent = message;
        
        confirmBtn.textContent = btnText;
        confirmBtn.style.backgroundColor = btnBg;
        confirmBtn.style.borderColor = btnBg;
        
        const isRevoke = (workflowAction === 'monitor_revoke' || workflowAction === 'manager_revoke');
        const remarksContainer = document.getElementById('revokeRemarksContainer');
        const remarksText = document.getElementById('revokeRemarksText');
        
        if (remarksContainer && remarksText) {
            if (isRevoke) {
                remarksContainer.style.display = 'block';
                remarksText.value = '';
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



    function saveData(goNext = false) {
        // Cancel any pending auto-saves to prevent race condition/duplicate saves
        clearTimeout(saveTimeout);
        
        // If called from button, give feedback
        const btn = event instanceof PointerEvent ? event.target : null;
        let originalText = '';
        if (btn) {
            originalText = btn.innerText;
            btn.innerText = 'Saving...';
            document.querySelectorAll('.btn-primary').forEach(b => b.disabled = true);
        }

        const data = {};
        const inputs = document.querySelectorAll('[name^="field_"]');
        
        inputs.forEach(input => {
            const name = input.name; 
            if(!name) return;
            // Name format is field_{id} or field_{id}[]
            // We need to extract digits only
            const match = name.match(/field_(\d+)/);
            if (!match) return;
            
            const id = match[1];
            
            if (input.type === 'radio') {
                if(input.checked) data[id] = input.value;
            } else if (input.type === 'checkbox') {
                 if (!data[id]) data[id] = [];
                 if (input.checked) {
                     if (Array.isArray(data[id])) data[id].push(input.value);
                     else data[id] = [input.value]; 
                 }
             } else {
                data[id] = input.value;
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
        
        fetch('ajax_data.php', { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            if(data.success) {
                if (goNext && '<?php echo $next_link; ?>') {
                    window.location.href = '<?php echo $next_link; ?>';
                } else {
                     // Update UI Elements if triggered by button
                     if (btn) {
                         btn.innerText = originalText;
                         document.querySelectorAll('.btn-primary').forEach(b => b.disabled = false);
                     }
                     
                     // Update current form progress inside CRF card dynamically
                     if (data.form_progress !== undefined) {
                        const fText = document.querySelector('.form-progress-text');
                        const fBar = document.querySelector('.current-form-progress');
                        if (fText) fText.innerText = data.form_progress + '%';
                        if (fBar) fBar.style.width = data.form_progress + '%';
                     }

                     
                     // Global Progress
                     if (data.subject_progress !== undefined) {
                        const gpBar = document.getElementById('global-progress-bar');
                        const gpText = document.getElementById('global-progress-text');
                        if (gpBar) gpBar.style.width = data.subject_progress + '%';
                        if (gpText) gpText.innerText = data.subject_progress + '%';
                     }
                     
                     // Visit Progress (for current visit)
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
                }
            } else {
                console.error("Save error:", data);
                if (btn) {
                    alert("Error saving: " + (data.error || 'Unknown'));
                    btn.innerText = originalText;
                    document.querySelectorAll('.btn-primary').forEach(b => b.disabled = false);
                }
            }
        })
        .catch(err => {
            console.error(err);
            if (btn) {
                alert("Network Error");
                btn.innerText = originalText;
                document.querySelectorAll('.btn-primary').forEach(b => b.disabled = false);
            }
        });
    }

    // Real-time Progress Calculation (Visual)
    function getFieldId(name) {
        const match = name.match(/field_(\d+)/);
        return match ? match[1] : null;
    }

    // Real-time Progress Calculation (Visual)
    function checkProgress() {
        const inputs = document.querySelectorAll('[name^="field_"]');
        const fieldMap = new Set();
        const filledMap = new Set();
        
        inputs.forEach(input => {
             const name = input.name;
             const id = getFieldId(name);
             if(!id) return;
             fieldMap.add(id);
             
             let isFilled = false;
             if (input.type === 'radio' || input.type === 'checkbox') {
                 if (input.checked) isFilled = true;
             } else {
                 if (input.value.trim() !== '') isFilled = true;
             }
             
             if (isFilled) filledMap.add(id);
        });
        
        const total = fieldMap.size;
        const filled = filledMap.size;
        const percent = total > 0 ? Math.round((filled / total) * 100) : 0;
        
        // Update Local Progress Bar & Text
        const progressBar = document.querySelector('.current-form-progress');
        const progressText = document.querySelector('.form-progress-text');
        if (progressBar) {
            progressBar.style.width = percent + '%';
        }
        if (progressText) {
            progressText.innerText = percent + '%';
        }

        // Update Field Icons
        fieldMap.forEach(id => {
            const fieldWrapper = document.querySelector(`.crf-field[data-field-id="${id}"]`);
            if (fieldWrapper) {
                const icon = fieldWrapper.querySelector('.status-icon');
                if (icon) {
                    if (filledMap.has(id)) {
                        icon.innerText = 'check_circle';
                        icon.style.color = 'var(--accent-color)';
                    } else {
                        icon.innerText = 'radio_button_unchecked';
                        icon.style.color = '#94a3b8';
                    }
                }
            }
        });
    }
    
    let saveTimeout;
    function debouncedSave() {
        checkProgress(); // Calculate local
        
        clearTimeout(saveTimeout);
        saveTimeout = setTimeout(() => {
             // Trigger save without button context (silent save)
             saveData(false); 
        }, 800); // 1.5 second delay after last input
    }
    
    // Attach Listeners
    document.addEventListener('DOMContentLoaded', () => {
         const inputs = document.querySelectorAll('[name^="field_"]');
         inputs.forEach(input => {
             const type = input.type || '';
             const tag = input.tagName.toLowerCase();
             
             // For text/numeric fields, calculate progress on keypress (input) but only save on focus loss (change)
             if (tag === 'textarea' || type === 'text' || type === 'number' || type === 'email' || type === 'tel' || type === 'url' || type === 'password') {
                 input.addEventListener('input', checkProgress);
                 input.addEventListener('change', debouncedSave);
             } else {
                 input.addEventListener('change', debouncedSave);
             }
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
                
                <div style="margin-bottom: 1rem;">
                    <label style="font-size: 0.85rem; color: #64748b;">Current status: <span id="queryCurrentStatus" style="font-weight: 600; color: var(--text-dark);"></span></label>
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
                    <label><input type="radio" name="missingReason" value="-95"> Measurement failed (-95)</label>
                    <label><input type="radio" name="missingReason" value="-96"> Not applicable (-96)</label>
                    <label><input type="radio" name="missingReason" value="-97"> Not asked (-97)</label>
                    <label><input type="radio" name="missingReason" value="-98"> Asked but unknown (-98)</label>
                    <label><input type="radio" name="missingReason" value="-99"> Not done (-99)</label>
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
    function openModal(id) { document.getElementById(id).classList.add('active'); }
    function closeModal(id) { document.getElementById(id).classList.remove('active'); }

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
                      const isFormReviewed = (window.monitorReviewed && isMonitorRole) || (window.managerReviewed && isManagerRole);
                      const hasUnresolvedQuery = data.queries.some(q => q.status !== 'closed');
                      if (isFormReviewed || hasUnresolvedQuery) {
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
        
        // Dynamic Status Options based on Role and Current Status
        const statusSelect = document.getElementById('queryNewStatus');
        statusSelect.innerHTML = '<option value="">Please select</option>';
        
        <?php if ($is_monitor): ?>
            // Monitor can select Close Query (if new, open, or answered) and Re Query (if answered)
            if (['new', 'open', 'answered', 'unconfirmed'].includes(query.status)) {
                 statusSelect.add(new Option('Close Query', 'closed'));
            }
            if (query.status === 'answered') {
                 statusSelect.add(new Option('Re Query', 'open'));
            }
        <?php endif; ?>

        <?php if ($is_manager || $current_role === 'data_entry' || strpos($current_role, 'entry') !== false): ?>
            // Coordinator can only select Answered Query if status is new or open
             if (['new', 'open'].includes(query.status)) {
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
        
        // Show Previous Value for Monitors on answered/closed queries
        const isMonitor = <?php echo $is_monitor ? 'true' : 'false'; ?>;
        const prevTextEl = document.getElementById('queryPreviousValueText');
        
        if (isMonitor && ['answered', 'closed'].includes(query.status)) {
            // Find the audit log entry where the query was resolved (coordinator saved new value)
            const resolutionAudit = fieldAuditHistory.find(h => 
                h.change_type === 'update' && 
                h.reason_for_change && 
                (h.reason_for_change.includes('Updated via query resolution') || h.reason_for_change.includes('Updated query'))
            );
            
            let prevVal = 'Empty';
            if (resolutionAudit) {
                prevVal = resolutionAudit.old_value || 'Empty';
            } else {
                const originalWrapper = document.querySelector(`.crf-field[data-field-id="${query.field_id}"] .input-wrapper`);
                prevVal = getFieldWrapperValueText(originalWrapper);
            }
            
            prevTextEl.innerHTML = `Previous Value: <span style="font-weight: 600; color: var(--text-dark);">${prevVal}</span>`;
        } else {
            // Coordinator view or new/open query: show the current page value as Previous Value
            const originalWrapper = document.querySelector(`.crf-field[data-field-id="${query.field_id}"] .input-wrapper`);
            const currentVal = getFieldWrapperValueText(originalWrapper);
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
         
         // Only collect field_value if user can edit
         const canEdit = <?php echo $can_edit ? 'true' : 'false'; ?>;
         if (canEdit) {
             const fValue = getClonedFieldValue();
             fd.append('field_value', fValue);
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

<?php
function renderFieldInput($field, $saved_value = '', $choices_map = []) {
    $type = $field['type'];
    $name = "field_" . $field['id'];
    $val_rules = json_decode($field['validation_rules'] ?? '{}', true);
    $value = htmlspecialchars($saved_value); 
    
    // Check Global Edit Permission
    global $can_edit;
    $disabled = $can_edit ? '' : 'disabled style="background:#f1f5f9; cursor:not-allowed;"';

    // Helper for Option Groups
    $gid = $field['option_group_id'] ?? null;
    $options = ($gid && isset($choices_map[$gid])) ? $choices_map[$gid] : [];

    switch ($type) {
        case 'text':
        case 'email':
        case 'calculation': // Read-only usually, but input for now
        case 'link':
            echo '<input type="text" name="'.$name.'" class="crf-input" value="'.$value.'" '.$disabled.'>';
            break;

        case 'number':
        case 'year':
            echo '<input type="number" name="'.$name.'" class="crf-input" value="'.$value.'" '.$disabled.'>';
            break;

        case 'date':
            echo '<input type="date" name="'.$name.'" class="crf-input" value="'.$value.'" '.$disabled.'>';
            break;

        case 'datetime':
            echo '<input type="datetime-local" name="'.$name.'" class="crf-input" value="'.$value.'" '.$disabled.'>';
            break;

        case 'time':
            echo '<input type="time" name="'.$name.'" class="crf-input" value="'.$value.'" '.$disabled.'>';
            break;
            
        case 'textarea':
        case 'remark': // Treat remark as text area for now, or read-only
            echo '<textarea name="'.$name.'" class="crf-input" rows="3" '.$disabled.'>'.$value.'</textarea>';
            break;

        case 'slider':
            // Basic range input
            $min = $val_rules['min'] ?? 0;
            $max = $val_rules['max'] ?? 100;
            echo '<div style="display:flex; align-items:center; gap:1rem;">';
            echo '<input type="range" name="'.$name.'" min="'.$min.'" max="'.$max.'" value="'.($value !== '' ? $value : $min).'" oninput="this.nextElementSibling.innerText = this.value" '.$disabled.' style="flex:1;">';
            echo '<span style="font-weight:600; min-width:30px; text-align:right;">'.($value !== '' ? $value : $min).'</span>';
            echo '</div>';
            break;
            
        case 'dropdown':
             echo '<select name="'.$name.'" class="crf-input" '.$disabled.'>';
             echo '<option value="">Select option</option>';
             if (!empty($options)) {
                 foreach ($options as $opt) {
                     $sel = ($value == $opt['value']) ? 'selected' : '';
                     echo '<option value="'.htmlspecialchars($opt['value']).'" '.$sel.'>'.htmlspecialchars($opt['label']).'</option>';
                 }
             } else {
                 // Fallback if no options defined
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
                    echo '<input type="radio" name="'.$name.'" value="'.htmlspecialchars($opt['value']).'" '.$checked.' '.$disabled.'> ';
                    echo htmlspecialchars($opt['label']);
                    echo '</label>';
                }
            } else {
                // Default Yes/No fallback
                $checkedYes = ($value == '1') ? 'checked' : '';
                $checkedNo = ($value == '0') ? 'checked' : '';
                echo '<label><input type="radio" name="'.$name.'" value="1" '.$checkedYes.' '.$disabled.'> Yes</label>';
                echo '<label><input type="radio" name="'.$name.'" value="0" '.$checkedNo.' '.$disabled.'> No</label>';
            }
            echo '</div>';
            break;

        case 'checkbox':
            // Handle array values for Checkboxes
            // Saved value might be "A,B" or JSON ["A","B"]
            $saved_vals = [];
            // Try detecting JSON or comma
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
                    echo '<input type="checkbox" name="'.$name.'[]" value="'.htmlspecialchars($opt['value']).'" '.$checked_str.' '.$disabled.'> ';
                    echo htmlspecialchars($opt['label']);
                    echo '</label>';
                }
            } else {
                // Default fallback
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
             // Structural image
             echo '<div style="padding:1rem; border:1px dashed #cbd5e1; text-align:center; color:#94a3b8;">Image Placeholder</div>';
             break;
             
        default:
            echo '<input type="text" name="'.$name.'" class="crf-input" placeholder="Unsupported type: '.$type.'" disabled>';
    }
}
?>

<!-- ========================================================================= -->
<!-- COMMON FORM MODALS (MH, AE, CM) -->
<!-- ========================================================================= -->

<!-- 1. COMMON FORM EDITOR MODAL -->
<div class="modal-overlay" id="modalCommonFormEditor" style="z-index: 10050;">
    <div class="modal-card" style="max-width: 780px; width: 92%; background: #ffffff; border-radius: 12px; overflow: hidden; padding: 0; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.15);">
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
                
                <?php if ($is_coordinator || $is_admin): ?>
                    <button type="button" onclick="saveCommonRecord('draft')" class="btn btn-sm" style="background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; font-weight: 600; padding: 0.5rem 1.2rem;">
                        Save Draft
                    </button>
                    <button type="button" onclick="saveCommonRecord('complete')" class="btn btn-sm btn-primary" style="font-weight: 600; padding: 0.5rem 1.4rem;">
                        Mark Complete
                    </button>
                <?php endif; ?>

                <?php if ($is_manager_role || $is_admin): ?>
                    <button type="button" id="btnCfSdr" onclick="markCommonSDRFromEditor()" class="btn btn-sm" style="background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; font-weight: 600; padding: 0.5rem 1.2rem;">
                        Mark SDR
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- 2. COMMON FORM QUERY MODAL -->
<div class="modal-overlay" id="modalCommonQuery" style="z-index: 10060;">
    <div class="modal-card" style="max-width: 600px; width: 92%; background: #ffffff; border-radius: 12px; overflow: hidden; padding: 0; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.15);">
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

        <div style="padding: 1.5rem; max-height: 60vh; overflow-y: auto; background: #ffffff;" id="cfQueryBody">
            <!-- Rendered by JS -->
        </div>

        <div class="modal-footer" style="background: #f8fafc; padding: 1rem 1.5rem; border-top: 1px solid #e2e8f0; text-align: right;">
            <button type="button" onclick="closeModal('modalCommonQuery')" class="btn btn-sm btn-outline">Close</button>
        </div>
    </div>
</div>

<!-- 3. COMMON FORM AUDIT & SDR HISTORY MODAL -->
<div class="modal-overlay" id="modalCommonAudit" style="z-index: 10060;">
    <div class="modal-card" style="max-width: 680px; width: 92%; background: #ffffff; border-radius: 12px; overflow: hidden; padding: 0; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.15);">
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

        <div style="padding: 1.5rem; max-height: 65vh; overflow-y: auto; background: #ffffff;" id="cfAuditBody">
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

let commonSearchTimer = null;
let currentEditingRecordData = null;
let currentLinkableRecords = [];

document.addEventListener('DOMContentLoaded', function() {
    if (COMMON_FORM_TYPE && ['MH', 'AE', 'CM'].includes(COMMON_FORM_TYPE)) {
        loadCommonRecords();
    }
});

function debounceCommonSearch() {
    clearTimeout(commonSearchTimer);
    commonSearchTimer = setTimeout(() => {
        loadCommonRecords();
    }, 300);
}

function loadCommonRecords() {
    if (!COMMON_FORM_TYPE) return;

    const search = document.getElementById('commonSearchInput') ? document.getElementById('commonSearchInput').value : '';
    const status = document.getElementById('commonStatusFilter') ? document.getElementById('commonStatusFilter').value : '';
    const sdrStatus = document.getElementById('commonSdrFilter') ? document.getElementById('commonSdrFilter').value : '';
    const includeVoided = document.getElementById('commonIncludeVoided') ? document.getElementById('commonIncludeVoided').checked : false;

    const fd = new FormData();
    fd.append('action', 'get_records');
    fd.append('subject_id', CURRENT_SUBJECT_ID);
    fd.append('form_type', COMMON_FORM_TYPE);
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
        if (cntBadge) cntBadge.textContent = res.records.length;

        const sbBadge = document.getElementById('sidebar-count-' + COMMON_FORM_TYPE.toLowerCase());
        if (sbBadge && res.summary) sbBadge.textContent = res.summary.total;

        // Render KPI Badges
        const kpiBox = document.getElementById('commonSummaryKpis');
        if (kpiBox && res.summary) {
            kpiBox.innerHTML = `
                <span style="background: #f1f5f9; color: #475569; padding: 3px 8px; border-radius: 6px;">Total: ${res.summary.total}</span>
                <span style="background: #ecfdf5; color: #047857; padding: 3px 8px; border-radius: 6px;">Complete: ${res.summary.complete}</span>
                <span style="background: #eff6ff; color: #1d4ed8; padding: 3px 8px; border-radius: 6px;">Draft: ${res.summary.draft}</span>
                <span style="background: #fef3c7; color: #b45309; padding: 3px 8px; border-radius: 6px;">Pending SDR: ${res.summary.sdr_pending}</span>
            `;
        }

        renderCommonTable(res.records, COMMON_FORM_TYPE);
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
            <th style="padding: 0.85rem 1rem; text-align: left;">MH Number</th>
            <th style="padding: 0.85rem 1rem; text-align: left;">Condition / Term</th>
            <th style="padding: 0.85rem 1rem; text-align: center;">Ongoing</th>
            <th style="padding: 0.85rem 1rem; text-align: left;">Start Date</th>
            <th style="padding: 0.85rem 1rem; text-align: left;">End Date</th>
            <th style="padding: 0.85rem 1rem; text-align: center;">Medication Given</th>
            <th style="padding: 0.85rem 1rem; text-align: center;">Status</th>
            <th style="padding: 0.85rem 1rem; text-align: center;">SDR</th>
            <th style="padding: 0.85rem 1rem; text-align: center;">Queries</th>
            <th style="padding: 0.85rem 1rem; text-align: right;">Actions</th>
        `;
    } else if (formType === 'AE') {
        thead.innerHTML = `
            <th style="padding: 0.85rem 1rem; text-align: left;">AE Number</th>
            <th style="padding: 0.85rem 1rem; text-align: left;">AE Term / Verbatim</th>
            <th style="padding: 0.85rem 1rem; text-align: left;">Start Date</th>
            <th style="padding: 0.85rem 1rem; text-align: left;">End Date</th>
            <th style="padding: 0.85rem 1rem; text-align: center;">Severity</th>
            <th style="padding: 0.85rem 1rem; text-align: center;">Seriousness</th>
            <th style="padding: 0.85rem 1rem; text-align: left;">Outcome</th>
            <th style="padding: 0.85rem 1rem; text-align: center;">Status</th>
            <th style="padding: 0.85rem 1rem; text-align: center;">SDR</th>
            <th style="padding: 0.85rem 1rem; text-align: center;">Queries</th>
            <th style="padding: 0.85rem 1rem; text-align: right;">Actions</th>
        `;
    } else if (formType === 'CM') {
        thead.innerHTML = `
            <th style="padding: 0.85rem 1rem; text-align: left;">CM Number</th>
            <th style="padding: 0.85rem 1rem; text-align: left;">Medication Name</th>
            <th style="padding: 0.85rem 1rem; text-align: left;">Start Date</th>
            <th style="padding: 0.85rem 1rem; text-align: left;">Stop Date</th>
            <th style="padding: 0.85rem 1rem; text-align: center;">Taken For</th>
            <th style="padding: 0.85rem 1rem; text-align: left;">Linked Conditions / Events</th>
            <th style="padding: 0.85rem 1rem; text-align: center;">Status</th>
            <th style="padding: 0.85rem 1rem; text-align: center;">SDR</th>
            <th style="padding: 0.85rem 1rem; text-align: center;">Queries</th>
            <th style="padding: 0.85rem 1rem; text-align: right;">Actions</th>
        `;
    }

    if (records.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="11" style="text-align: center; padding: 3.5rem; color: #94a3b8;">
                    <span class="material-icons-round" style="font-size: 3rem; color: #cbd5e1; display: block; margin-bottom: 0.75rem;">playlist_remove</span>
                    <p style="font-size: 1rem; font-weight: 500; color: #475569; margin: 0 0 0.5rem 0;">No ${formType} records found for this subject.</p>
                    ${USER_IS_COORDINATOR || USER_IS_ADMIN ? `<button class="btn btn-sm btn-primary" onclick="openCommonFormModal(0, '${formType}')" style="margin-top: 0.5rem;">+ Add ${formType} Form</button>` : ''}
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
        let statusBadge = `<span style="background: #eff6ff; color: #1d4ed8; padding: 2px 8px; border-radius: 99px; font-size: 0.75rem; font-weight: 600;">Draft</span>`;
        if (r.status === 'complete') {
            statusBadge = `<span style="background: #ecfdf5; color: #047857; padding: 2px 8px; border-radius: 99px; font-size: 0.75rem; font-weight: 600;">Complete</span>`;
        }

        // SDR Badge
        let sdrBadge = `<span style="background: #f1f5f9; color: #64748b; padding: 2px 8px; border-radius: 99px; font-size: 0.75rem; font-weight: 500;">Pending</span>`;
        if (r.sdr_status === 'reviewed') {
            sdrBadge = `<span style="background: #ecfdf5; color: #047857; padding: 2px 8px; border-radius: 99px; font-size: 0.75rem; font-weight: 600; display: inline-flex; align-items: center; gap: 2px;"><span class="material-icons-round" style="font-size: 14px;">check_circle</span> Reviewed</span>`;
        } else if (r.sdr_status === 'needs_rereview') {
            sdrBadge = `<span style="background: #fff7ed; color: #c2410c; padding: 2px 8px; border-radius: 99px; font-size: 0.75rem; font-weight: 600; display: inline-flex; align-items: center; gap: 2px;"><span class="material-icons-round" style="font-size: 14px;">published_with_changes</span> Re-review</span>`;
        }

        // Query Badge
        let queryBadge = `<span style="color: #94a3b8;">-</span>`;
        if (r.open_queries > 0) {
            queryBadge = `<span style="background: #ef4444; color: white; padding: 2px 7px; border-radius: 99px; font-size: 0.75rem; font-weight: 700;">${r.open_queries}</span>`;
        }

        if (formType === 'MH') {
            let relCmHtml = '';
            if (r.related_cms && r.related_cms.length > 0) {
                relCmHtml = `<div style="margin-top: 4px; display: flex; gap: 4px; flex-wrap: wrap;">`;
                r.related_cms.forEach(cm => {
                    relCmHtml += `<span onclick="event.stopPropagation(); openCommonFormModal(${cm.id}, 'CM')" class="badge" style="background: #ecfdf5; color: #047857; cursor: pointer; font-size: 0.7rem; border: 1px solid #a7f3d0;" title="Linked CM: ${escapeHtml(cm.medication_name)}"><span class="material-icons-round" style="font-size: 11px;">medication</span> ${escapeHtml(cm.record_number)}</span>`;
                });
                relCmHtml += `</div>`;
            }

            html += `
                <tr style="${rowStyle}">
                    <td style="padding: 0.85rem 1rem; font-weight: 700; color: #1e293b;">
                        ${escapeHtml(r.record_number)}
                        ${isVoided ? '<span style="background: #dc2626; color: white; font-size: 0.65rem; padding: 1px 5px; border-radius: 4px; margin-left: 4px;">VOIDED</span>' : ''}
                    </td>
                    <td style="padding: 0.85rem 1rem; color: #0f172a; font-weight: 600;">
                        ${escapeHtml(d.mh_term || 'Untitled')}
                        ${relCmHtml}
                    </td>
                    <td style="padding: 0.85rem 1rem; text-align: center;">
                        <span style="font-weight: 600; color: ${d.ongoing === 'Yes' ? '#2563eb' : '#64748b'};">${escapeHtml(d.ongoing || 'No')}</span>
                    </td>
                    <td style="padding: 0.85rem 1rem; color: #475569;">${escapeHtml(d.start_date || '-')}</td>
                    <td style="padding: 0.85rem 1rem; color: #475569;">${escapeHtml(d.end_date || '-')}</td>
                    <td style="padding: 0.85rem 1rem; text-align: center;">${escapeHtml(d.medication_given || 'No')}</td>
                    <td style="padding: 0.85rem 1rem; text-align: center;">${statusBadge}</td>
                    <td style="padding: 0.85rem 1rem; text-align: center;">${sdrBadge}</td>
                    <td style="padding: 0.85rem 1rem; text-align: center;">${queryBadge}</td>
                    <td style="padding: 0.85rem 1rem; text-align: right;">
                        ${renderRecordActionButtons(r, 'MH')}
                    </td>
                </tr>
            `;
        } else if (formType === 'AE') {
            let relCmHtml = '';
            if (r.related_cms && r.related_cms.length > 0) {
                relCmHtml = `<div style="margin-top: 4px; display: flex; gap: 4px; flex-wrap: wrap;">`;
                r.related_cms.forEach(cm => {
                    relCmHtml += `<span onclick="event.stopPropagation(); openCommonFormModal(${cm.id}, 'CM')" class="badge" style="background: #ecfdf5; color: #047857; cursor: pointer; font-size: 0.7rem; border: 1px solid #a7f3d0;" title="Linked CM: ${escapeHtml(cm.medication_name)}"><span class="material-icons-round" style="font-size: 11px;">medication</span> ${escapeHtml(cm.record_number)}</span>`;
                });
                relCmHtml += `</div>`;
            }

            let sevBg = '#f1f5f9'; let sevColor = '#475569';
            if (d.severity === 'Severe') { sevBg = '#fef2f2'; sevColor = '#dc2626'; }
            else if (d.severity === 'Moderate') { sevBg = '#fff7ed'; sevColor = '#c2410c'; }

            html += `
                <tr style="${rowStyle}">
                    <td style="padding: 0.85rem 1rem; font-weight: 700; color: #1e293b;">
                        ${escapeHtml(r.record_number)}
                        ${isVoided ? '<span style="background: #dc2626; color: white; font-size: 0.65rem; padding: 1px 5px; border-radius: 4px; margin-left: 4px;">VOIDED</span>' : ''}
                    </td>
                    <td style="padding: 0.85rem 1rem; color: #0f172a; font-weight: 600;">
                        ${escapeHtml(d.ae_term || 'Untitled')}
                        ${relCmHtml}
                    </td>
                    <td style="padding: 0.85rem 1rem; color: #475569;">${escapeHtml(d.start_date || '-')}</td>
                    <td style="padding: 0.85rem 1rem; color: #475569;">${escapeHtml(d.end_date || '-')}</td>
                    <td style="padding: 0.85rem 1rem; text-align: center;">
                        <span style="background: ${sevBg}; color: ${sevColor}; padding: 2px 8px; border-radius: 99px; font-weight: 600; font-size: 0.75rem;">${escapeHtml(d.severity || '-')}</span>
                    </td>
                    <td style="padding: 0.85rem 1rem; text-align: center;">
                        <span style="font-weight: 700; color: ${d.seriousness === 'Yes' ? '#dc2626' : '#64748b'};">${escapeHtml(d.seriousness || 'No')}</span>
                    </td>
                    <td style="padding: 0.85rem 1rem; color: #475569;">${escapeHtml(d.outcome || '-')}</td>
                    <td style="padding: 0.85rem 1rem; text-align: center;">${statusBadge}</td>
                    <td style="padding: 0.85rem 1rem; text-align: center;">${sdrBadge}</td>
                    <td style="padding: 0.85rem 1rem; text-align: center;">${queryBadge}</td>
                    <td style="padding: 0.85rem 1rem; text-align: right;">
                        ${renderRecordActionButtons(r, 'AE')}
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
                        <span onclick="event.stopPropagation(); openCommonFormModal(${lk.id}, '${lk.form_type}')" class="badge" style="background: ${bg}; color: ${color}; cursor: pointer; border: 1px solid #e2e8f0; font-size: 0.725rem;" title="${escapeHtml(lk.term)}">
                            <span class="material-icons-round" style="font-size: 11px;">${icon}</span> ${escapeHtml(lk.record_number)} ${escapeHtml(lk.term)}${warningTag}
                        </span>
                    `;
                });
                linksHtml += '</div>';
            }

            html += `
                <tr style="${rowStyle}">
                    <td style="padding: 0.85rem 1rem; font-weight: 700; color: #1e293b;">
                        ${escapeHtml(r.record_number)}
                        ${isVoided ? '<span style="background: #dc2626; color: white; font-size: 0.65rem; padding: 1px 5px; border-radius: 4px; margin-left: 4px;">VOIDED</span>' : ''}
                    </td>
                    <td style="padding: 0.85rem 1rem; color: #0f172a; font-weight: 600;">${escapeHtml(d.medication_name || 'Untitled')}</td>
                    <td style="padding: 0.85rem 1rem; color: #475569;">${escapeHtml(d.start_date || '-')}</td>
                    <td style="padding: 0.85rem 1rem; color: #475569;">${escapeHtml(d.stop_date || '-')}</td>
                    <td style="padding: 0.85rem 1rem; text-align: center;">
                        <span style="background: #f1f5f9; color: #334155; padding: 2px 8px; border-radius: 6px; font-weight: 600; font-size: 0.75rem;">${escapeHtml(d.indication_type || '-')}</span>
                    </td>
                    <td style="padding: 0.85rem 1rem;">${linksHtml}</td>
                    <td style="padding: 0.85rem 1rem; text-align: center;">${statusBadge}</td>
                    <td style="padding: 0.85rem 1rem; text-align: center;">${sdrBadge}</td>
                    <td style="padding: 0.85rem 1rem; text-align: center;">${queryBadge}</td>
                    <td style="padding: 0.85rem 1rem; text-align: right;">
                        ${renderRecordActionButtons(r, 'CM')}
                    </td>
                </tr>
            `;
        }
    });

    tbody.innerHTML = html;
}

function renderRecordActionButtons(r, formType) {
    let btns = '';

    // Open/Edit
    btns += `<button class="btn btn-sm btn-outline" onclick="openCommonFormModal(${r.id}, '${formType}')" style="padding: 0.25rem 0.6rem; font-size: 0.75rem; margin-right: 4px;">Edit</button>`;

    // Queries
    btns += `<button class="btn btn-sm btn-outline" onclick="openCommonQueryModal(${r.id})" title="Queries" style="padding: 0.25rem 0.5rem; font-size: 0.75rem; margin-right: 4px; color: ${r.open_queries > 0 ? '#dc2626' : '#475569'};"><span class="material-icons-round" style="font-size: 14px;">help_outline</span></button>`;

    // SDR (Manager / Admin)
    if (USER_IS_MANAGER || USER_IS_ADMIN) {
        if (!r.is_voided) {
            const sdrTitle = r.sdr_status === 'reviewed' ? 'Revoke SDR' : 'Mark SDR Reviewed';
            btns += `<button class="btn btn-sm" onclick="toggleCommonSDR(${r.id}, '${r.sdr_status}')" title="${sdrTitle}" style="padding: 0.25rem 0.5rem; font-size: 0.75rem; margin-right: 4px; background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0;"><span class="material-icons-round" style="font-size: 14px;">verified</span></button>`;
        }
    }

    // Void (Coordinator / Admin)
    if ((USER_IS_COORDINATOR || USER_IS_ADMIN) && !r.is_voided) {
        btns += `<button class="btn btn-sm" onclick="openCommonVoidModal(${r.id})" title="Void Record" style="padding: 0.25rem 0.5rem; font-size: 0.75rem; background: #fef2f2; color: #dc2626; border: 1px solid #fca5a5;"><span class="material-icons-round" style="font-size: 14px;">remove_circle_outline</span></button>`;
    }

    return btns;
}

// =========================================================================
// FORM EDITOR MODAL & DYNAMIC FIELDS
// =========================================================================
function openCommonFormModal(recordId, formType, defaultData = null) {
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
        'CM': 'Concomitant Medications (CM)'
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

function setupEditorForm(rec, formType) {
    const isNew = rec.id === 0;

    let iconHtml = '';
    if (formType === 'MH') iconHtml = '<span class="material-icons-round" style="color: #2563eb; font-size: 1.35rem;">history_edu</span>';
    else if (formType === 'AE') iconHtml = '<span class="material-icons-round" style="color: #d97706; font-size: 1.35rem;">warning_amber</span>';
    else if (formType === 'CM') iconHtml = '<span class="material-icons-round" style="color: #059669; font-size: 1.35rem;">medication</span>';

    document.getElementById('cfEditorTitle').innerHTML = `${iconHtml} <span>${escapeHtml(rec.record_number)}</span>`;

    // Badges
    const bBox = document.getElementById('cfEditorBadges');
    let stBadge = rec.status === 'complete' ? '<span style="background: #ecfdf5; color: #047857; padding: 2px 8px; border-radius: 99px; font-size: 0.75rem; font-weight: 600;">Complete</span>' : '<span style="background: #eff6ff; color: #1d4ed8; padding: 2px 8px; border-radius: 99px; font-size: 0.75rem; font-weight: 600;">Draft</span>';
    let sdrBadge = rec.sdr_status === 'reviewed' ? '<span style="background: #ecfdf5; color: #047857; padding: 2px 8px; border-radius: 99px; font-size: 0.75rem; font-weight: 600;">Reviewed</span>' : '<span style="background: #f1f5f9; color: #64748b; padding: 2px 8px; border-radius: 99px; font-size: 0.75rem; font-weight: 500;">Pending SDR</span>';
    bBox.innerHTML = `${stBadge} ${sdrBadge} <span style="background: #f8fafc; color: #64748b; border: 1px solid #cbd5e1; padding: 2px 8px; border-radius: 99px; font-size: 0.75rem;">v${rec.revision || 1}</span>`;

    // Audit / Query buttons
    document.getElementById('btnCfAudit').style.display = isNew ? 'none' : 'inline-flex';
    document.getElementById('btnCfQuery').style.display = isNew ? 'none' : 'inline-flex';

    const fieldsBox = document.getElementById('cfDynamicFields');
    const d = rec.data || {};

    if (formType === 'MH') {
        fieldsBox.innerHTML = `
            <div style="display: grid; grid-template-columns: 1fr; gap: 1.25rem;">
                <div>
                    <label style="font-weight: 600; font-size: 0.875rem; color: #1e293b; display: block; margin-bottom: 0.35rem;">
                        1. MH Term / Condition <span style="color: #dc2626;">*</span>
                    </label>
                    <input type="text" id="inp_mh_term" class="form-input" value="${escapeHtml(d.mh_term || '')}" placeholder="e.g. Type 2 Diabetes Mellitus" style="width: 100%;">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem;">
                    <div>
                        <label style="font-weight: 600; font-size: 0.875rem; color: #1e293b; display: block; margin-bottom: 0.35rem;">
                            2. Ongoing / Continuing <span style="color: #dc2626;">*</span>
                        </label>
                        <select id="inp_mh_ongoing" onchange="onMHOngoingChange()" class="form-input" style="width: 100%;">
                            <option value="">-- Select --</option>
                            <option value="Yes" ${d.ongoing === 'Yes' ? 'selected' : ''}>Yes</option>
                            <option value="No" ${d.ongoing === 'No' ? 'selected' : ''}>No</option>
                        </select>
                    </div>

                    <div>
                        <label style="font-weight: 600; font-size: 0.875rem; color: #1e293b; display: block; margin-bottom: 0.35rem;">
                            3. Start Date
                        </label>
                        <input type="date" id="inp_mh_start_date" onchange="onMHDateChange()" class="form-input" value="${escapeHtml(d.start_date || '')}" style="width: 100%;">
                    </div>

                    <div>
                        <label style="font-weight: 600; font-size: 0.875rem; color: #1e293b; display: block; margin-bottom: 0.35rem;" id="lbl_mh_end_date">
                            4. End Date
                        </label>
                        <input type="date" id="inp_mh_end_date" class="form-input" value="${escapeHtml(d.end_date || '')}" style="width: 100%;">
                    </div>
                </div>

                <div>
                    <label style="font-weight: 600; font-size: 0.875rem; color: #1e293b; display: block; margin-bottom: 0.35rem;">
                        5. Comments / Additional Details
                    </label>
                    <textarea id="inp_mh_comments" class="form-input" rows="3" placeholder="Enter additional details..." style="width: 100%; resize: vertical;">${escapeHtml(d.comments || '')}</textarea>
                </div>

                <div>
                    <label style="font-weight: 600; font-size: 0.875rem; color: #1e293b; display: block; margin-bottom: 0.35rem;">
                        6. Medication Given <span style="color: #dc2626;">*</span>
                    </label>
                    <select id="inp_mh_medication_given" onchange="onMHMedGivenChange()" class="form-input" style="width: 200px;">
                        <option value="">-- Select --</option>
                        <option value="Yes" ${d.medication_given === 'Yes' ? 'selected' : ''}>Yes</option>
                        <option value="No" ${d.medication_given === 'No' ? 'selected' : ''}>No</option>
                    </select>

                    <div id="mhCmShortcutBox" style="display: ${d.medication_given === 'Yes' && rec.id > 0 ? 'block' : 'none'}; margin-top: 0.75rem;">
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
                    <label style="font-weight: 600; font-size: 0.875rem; color: #1e293b; display: block; margin-bottom: 0.35rem;">
                        1. AE Term / Verbatim <span style="color: #dc2626;">*</span>
                    </label>
                    <input type="text" id="inp_ae_term" class="form-input" value="${escapeHtml(d.ae_term || '')}" placeholder="e.g. Severe Headache" style="width: 100%;">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 1rem;">
                    <div>
                        <label style="font-weight: 600; font-size: 0.875rem; color: #1e293b; display: block; margin-bottom: 0.35rem;">
                            2. Start Date
                        </label>
                        <input type="date" id="inp_ae_start_date" class="form-input" value="${escapeHtml(d.start_date || '')}" style="width: 100%;">
                    </div>

                    <div>
                        <label style="font-weight: 600; font-size: 0.875rem; color: #1e293b; display: block; margin-bottom: 0.35rem;">
                            3. End Date
                        </label>
                        <input type="date" id="inp_ae_end_date" class="form-input" value="${escapeHtml(d.end_date || '')}" style="width: 100%;">
                    </div>

                    <div>
                        <label style="font-weight: 600; font-size: 0.875rem; color: #1e293b; display: block; margin-bottom: 0.35rem;">
                            4. Severity / Intensity <span style="color: #dc2626;">*</span>
                        </label>
                        <select id="inp_ae_severity" class="form-input" style="width: 100%;">
                            <option value="">-- Select --</option>
                            <option value="Mild" ${d.severity === 'Mild' ? 'selected' : ''}>Mild</option>
                            <option value="Moderate" ${d.severity === 'Moderate' ? 'selected' : ''}>Moderate</option>
                            <option value="Severe" ${d.severity === 'Severe' ? 'selected' : ''}>Severe</option>
                        </select>
                    </div>

                    <div>
                        <label style="font-weight: 600; font-size: 0.875rem; color: #1e293b; display: block; margin-bottom: 0.35rem;">
                            5. Seriousness <span style="color: #dc2626;">*</span>
                        </label>
                        <select id="inp_ae_seriousness" onchange="onAESeriousnessChange()" class="form-input" style="width: 100%;">
                            <option value="">-- Select --</option>
                            <option value="Yes" ${isSerious ? 'selected' : ''}>Yes</option>
                            <option value="No" ${!isSerious && d.seriousness === 'No' ? 'selected' : ''}>No</option>
                        </select>
                    </div>
                </div>

                <!-- 6. Seriousness Criteria Multi-select -->
                <div id="aeSeriousnessCriteriaBox" style="display: ${isSerious ? 'block' : 'none'}; background: #fff7ed; border: 1px solid #fed7aa; padding: 1rem; border-radius: 8px;">
                    <label style="font-weight: 600; font-size: 0.875rem; color: #9a3412; display: block; margin-bottom: 0.5rem;">
                        6. Seriousness Criteria (Select all that apply) <span style="color: #dc2626;">*</span>
                    </label>
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
                        <label style="font-weight: 600; font-size: 0.875rem; color: #1e293b; display: block; margin-bottom: 0.35rem;">
                            7. Causality / Relationship to Study Drug <span style="color: #dc2626;">*</span>
                        </label>
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
                        <label style="font-weight: 600; font-size: 0.875rem; color: #1e293b; display: block; margin-bottom: 0.35rem;">
                            8. Action Taken with Study Treatment <span style="color: #dc2626;">*</span>
                        </label>
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
                        <label style="font-weight: 600; font-size: 0.875rem; color: #1e293b; display: block; margin-bottom: 0.35rem;">
                            9. Outcome <span style="color: #dc2626;">*</span>
                        </label>
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
                        <label style="font-weight: 600; font-size: 0.875rem; color: #1e293b; display: block; margin-bottom: 0.35rem;">
                            10. Concomitant Treatment Given <span style="color: #dc2626;">*</span>
                        </label>
                        <select id="inp_ae_concomitant_treatment" onchange="onAEConcomitantTreatmentChange()" class="form-input" style="width: 100%;">
                            <option value="">-- Select --</option>
                            <option value="Yes" ${d.concomitant_treatment === 'Yes' ? 'selected' : ''}>Yes</option>
                            <option value="No" ${d.concomitant_treatment === 'No' ? 'selected' : ''}>No</option>
                        </select>

                        <div id="aeCmShortcutBox" style="display: ${d.concomitant_treatment === 'Yes' && rec.id > 0 ? 'block' : 'none'}; margin-top: 0.75rem;">
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
                    <label style="font-weight: 600; font-size: 0.875rem; color: #1e293b; display: block; margin-bottom: 0.35rem;">
                        1. Medication Name <span style="color: #dc2626;">*</span>
                    </label>
                    <input type="text" id="inp_cm_name" class="form-input" value="${escapeHtml(d.medication_name || '')}" placeholder="e.g. Metformin 500mg" style="width: 100%;">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div>
                        <label style="font-weight: 600; font-size: 0.875rem; color: #1e293b; display: block; margin-bottom: 0.35rem;">
                            2. Start Date
                        </label>
                        <input type="date" id="inp_cm_start_date" class="form-input" value="${escapeHtml(d.start_date || '')}" style="width: 100%;">
                    </div>

                    <div>
                        <label style="font-weight: 600; font-size: 0.875rem; color: #1e293b; display: block; margin-bottom: 0.35rem;">
                            3. Stop Date
                        </label>
                        <input type="date" id="inp_cm_stop_date" class="form-input" value="${escapeHtml(d.stop_date || '')}" style="width: 100%;">
                    </div>
                </div>

                <div>
                    <label style="font-weight: 600; font-size: 0.875rem; color: #1e293b; display: block; margin-bottom: 0.35rem;">
                        4. Medication Taken For (Indication Type) <span style="color: #dc2626;">*</span>
                    </label>
                    <select id="inp_cm_indication_type" onchange="onCMIndicationChange()" class="form-input" style="width: 250px;">
                        <option value="">-- Select --</option>
                        <option value="MH" ${indType === 'MH' ? 'selected' : ''}>Medical History (MH)</option>
                        <option value="AE" ${indType === 'AE' ? 'selected' : ''}>Adverse Event (AE)</option>
                        <option value="Other" ${indType === 'Other' ? 'selected' : ''}>Other Indication</option>
                    </select>
                </div>

                <!-- 5. Linked Condition / Event Selection -->
                <div id="cmLinkedContainer" style="display: ${indType === 'MH' || indType === 'AE' ? 'block' : 'none'}; background: #f8fafc; border: 1px solid #e2e8f0; padding: 1.25rem; border-radius: 8px;">
                    <label style="font-weight: 600; font-size: 0.875rem; color: #0f172a; display: block; margin-bottom: 0.5rem;" id="lblCmLinkedTarget">
                        5. Linked Condition / Event <span style="color: #dc2626;">*</span>
                    </label>
                    <div id="cmLinkedRecordsList" style="display: flex; flex-direction: column; gap: 0.5rem; max-height: 200px; overflow-y: auto;">
                        <span style="color: #94a3b8; font-size: 0.875rem;">Loading active records...</span>
                    </div>
                </div>

                <!-- 6. Specify Other -->
                <div id="cmSpecifyOtherContainer" style="display: ${indType === 'Other' ? 'block' : 'none'};">
                    <label style="font-weight: 600; font-size: 0.875rem; color: #1e293b; display: block; margin-bottom: 0.35rem;">
                        6. Specify Other Indication <span style="color: #dc2626;">*</span>
                    </label>
                    <input type="text" id="inp_cm_specify_other" class="form-input" value="${escapeHtml(d.specify_other || '')}" placeholder="Enter indication..." style="width: 100%;">
                </div>
            </div>
        `;

        if (indType === 'MH' || indType === 'AE') {
            loadCMLinkableRecords(indType, existingLinks);
        }
    }
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
    const recordId = parseInt(document.getElementById('cfRecordId').value) || 0;
    const formType = document.getElementById('cfFormType').value;
    const alertBox = document.getElementById('cfValidationAlert');
    alertBox.style.display = 'none';

    const data = {};
    const linkedIds = [];

    if (formType === 'MH') {
        data.mh_term = document.getElementById('inp_mh_term').value.trim();
        data.ongoing = document.getElementById('inp_mh_ongoing').value;
        data.start_date = document.getElementById('inp_mh_start_date').value;
        data.end_date = document.getElementById('inp_mh_end_date').value;
        data.comments = document.getElementById('inp_mh_comments').value.trim();
        data.medication_given = document.getElementById('inp_mh_medication_given').value;
    } else if (formType === 'AE') {
        data.ae_term = document.getElementById('inp_ae_term').value.trim();
        data.start_date = document.getElementById('inp_ae_start_date').value;
        data.end_date = document.getElementById('inp_ae_end_date').value;
        data.severity = document.getElementById('inp_ae_severity').value;
        data.seriousness = document.getElementById('inp_ae_seriousness').value;
        
        const critCbs = document.querySelectorAll('.cb_ae_crit:checked');
        data.seriousness_criteria = Array.from(critCbs).map(cb => cb.value);

        data.causality = document.getElementById('inp_ae_causality').value;
        data.action_taken = document.getElementById('inp_ae_action_taken').value;
        data.outcome = document.getElementById('inp_ae_outcome').value;
        data.concomitant_treatment = document.getElementById('inp_ae_concomitant_treatment').value;
    } else if (formType === 'CM') {
        data.medication_name = document.getElementById('inp_cm_name').value.trim();
        data.start_date = document.getElementById('inp_cm_start_date').value;
        data.stop_date = document.getElementById('inp_cm_stop_date').value;
        data.indication_type = document.getElementById('inp_cm_indication_type').value;
        data.specify_other = document.getElementById('inp_cm_specify_other') ? document.getElementById('inp_cm_specify_other').value.trim() : '';

        const linkedCbs = document.querySelectorAll('.cb_cm_linked:checked');
        linkedCbs.forEach(cb => linkedIds.push(parseInt(cb.value)));
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
            alertBox.innerHTML = `<span class="material-icons-round" style="font-size: 1.1rem; vertical-align: middle; margin-right: 4px;">error_outline</span> ${escapeHtml(res.message || 'Validation Error')}`;
            alertBox.style.display = 'block';
            document.getElementById('cfEditorBody').scrollTop = 0;
            return;
        }

        showToast(res.message || 'Record saved successfully!', 'success');
        closeModal('modalCommonFormEditor');
        loadCommonRecords();
    })
    .catch(err => {
        console.error(err);
        showToast('Network error saving record', 'error');
    });
}

// =========================================================================
// SDR, QUERY, AUDIT & VOID HANDLERS
// =========================================================================
function toggleCommonSDR(recordId, currentStatus) {
    const newStatus = (currentStatus === 'reviewed') ? 'needs_rereview' : 'reviewed';
    
    showConfirmModal({
        title: newStatus === 'reviewed' ? 'Mark SDR Reviewed' : 'Request SDR Re-review',
        message: `Are you sure you want to update SDR status to <strong>${newStatus.toUpperCase()}</strong> for this record?`,
        icon: 'verified',
        iconBg: '#ecfdf5',
        iconColor: '#047857',
        btnText: 'Confirm SDR Status',
        onConfirm: function() {
            const fd = new FormData();
            fd.append('action', 'mark_sdr');
            fd.append('record_id', recordId);
            fd.append('sdr_action', newStatus);

            fetch('ajax_common_forms.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                if (!res.success) {
                    showToast(res.message || 'Error updating SDR', 'error');
                    return;
                }
                showToast(res.message, 'success');
                loadCommonRecords();
            });
        }
    });
}

function markCommonSDRFromEditor() {
    const recId = parseInt(document.getElementById('cfRecordId').value);
    if (recId > 0 && currentEditingRecordData) {
        toggleCommonSDR(recId, currentEditingRecordData.sdr_status);
        closeModal('modalCommonFormEditor');
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

// Queries Modal
function openCommonQueryModal(recordId) {
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
        document.getElementById('cfQueryRecordTitle').textContent = `Queries for ${rec.record_number}`;
        const qBody = document.getElementById('cfQueryBody');

        let html = '';

        if (USER_IS_MANAGER || USER_IS_ADMIN) {
            html += `
                <div style="background: #eff6ff; border: 1px solid #bfdbfe; padding: 1rem; border-radius: 8px; margin-bottom: 1.25rem;">
                    <label style="font-weight: 600; font-size: 0.875rem; color: #1e40af; display: block; margin-bottom: 0.35rem;">
                        Raise New Query on ${rec.record_number}
                    </label>
                    <textarea id="inp_new_query_text" class="form-input" rows="2" placeholder="Enter query details for investigator/coordinator..." style="width: 100%; font-size: 0.875rem; resize: vertical; margin-bottom: 0.5rem;"></textarea>
                    <button type="button" onclick="submitNewCommonQuery(${rec.id})" class="btn btn-sm btn-primary" style="font-weight: 600;">Submit Query</button>
                </div>
            `;
        }

        if (rec.queries.length === 0) {
            html += `<p style="text-align: center; color: #94a3b8; padding: 1.5rem;">No queries raised for this record.</p>`;
        } else {
            rec.queries.forEach(q => {
                const isClosed = q.status === 'closed';
                const statusColor = isClosed ? '#64748b' : (q.status === 'answered' ? '#047857' : '#dc2626');
                const statusBg = isClosed ? '#f1f5f9' : (q.status === 'answered' ? '#ecfdf5' : '#fef2f2');

                html += `
                    <div style="border: 1px solid #e2e8f0; border-radius: 8px; padding: 1rem; margin-bottom: 1rem; background: white;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                            <span style="font-weight: 600; color: #1e293b; font-size: 0.875rem;">Query #${q.id}</span>
                            <span style="background: ${statusBg}; color: ${statusColor}; padding: 2px 8px; border-radius: 99px; font-weight: 600; font-size: 0.75rem;">${q.status.toUpperCase()}</span>
                        </div>
                        <p style="margin: 0 0 0.5rem 0; color: #334155; font-size: 0.9rem;">${escapeHtml(q.query_text)}</p>
                        <div style="font-size: 0.75rem; color: #94a3b8; margin-bottom: 0.75rem;">Raised by ${escapeHtml(q.created_by)} on ${q.created_at}</div>

                        <!-- Thread history -->
                        <div style="padding-left: 1rem; border-left: 2px solid #e2e8f0; display: flex; flex-direction: column; gap: 0.5rem;">
                `;

                q.history.forEach(h => {
                    html += `
                        <div style="font-size: 0.8rem; background: #f8fafc; padding: 0.5rem; border-radius: 6px;">
                            <strong>${escapeHtml(h.user)}</strong> [${h.action_type.toUpperCase()}]: ${escapeHtml(h.remark)} <span style="color: #94a3b8; font-size: 0.7rem;">(${h.created_at})</span>
                        </div>
                    `;
                });

                html += `</div>`;

                // Response / Close controls
                if (q.status !== 'closed' && (USER_IS_COORDINATOR || USER_IS_ADMIN)) {
                    html += `
                        <div style="margin-top: 0.75rem; display: flex; gap: 0.5rem;">
                            <input type="text" id="inp_ans_query_${q.id}" class="form-input" placeholder="Type response remark..." style="flex: 1; font-size: 0.8rem;">
                            <button type="button" onclick="submitCommonQueryAnswer(${q.id}, ${rec.id})" class="btn btn-sm btn-primary">Respond</button>
                        </div>
                    `;
                }

                if (USER_IS_MANAGER || USER_IS_ADMIN) {
                    html += `<div style="margin-top: 0.75rem; display: flex; gap: 0.5rem;">`;
                    if (q.status !== 'closed') {
                        html += `<button type="button" onclick="updateCommonQueryStatus(${q.id}, 'close', ${rec.id})" class="btn btn-sm btn-outline" style="font-size: 0.75rem;">Close Query</button>`;
                    } else {
                        html += `<button type="button" onclick="updateCommonQueryStatus(${q.id}, 'reopen', ${rec.id})" class="btn btn-sm btn-outline" style="font-size: 0.75rem;">Re-open Query</button>`;
                    }
                    html += `</div>`;
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
        openCommonQueryModal(recId);
    }
}

function submitNewCommonQuery(recordId) {
    const text = document.getElementById('inp_new_query_text') ? document.getElementById('inp_new_query_text').value.trim() : '';
    if (text === '') {
        showToast('Query text cannot be empty', 'warning');
        return;
    }

    const fd = new FormData();
    fd.append('action', 'raise_query');
    fd.append('record_id', recordId);
    fd.append('query_text', text);

    fetch('ajax_common_forms.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
        if (!res.success) {
            showToast(res.message || 'Error raising query', 'error');
            return;
        }
        showToast(res.message, 'success');
        openCommonQueryModal(recordId);
        loadCommonRecords();
    });
}

function submitCommonQueryAnswer(queryId, recordId) {
    const remark = document.getElementById('inp_ans_query_' + queryId) ? document.getElementById('inp_ans_query_' + queryId).value.trim() : '';
    if (remark === '') {
        showToast('Please enter a response remark', 'warning');
        return;
    }

    const fd = new FormData();
    fd.append('action', 'answer_query');
    fd.append('query_id', queryId);
    fd.append('remark', remark);

    fetch('ajax_common_forms.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
        if (!res.success) {
            showToast(res.message || 'Error answering query', 'error');
            return;
        }
        showToast(res.message, 'success');
        openCommonQueryModal(recordId);
        loadCommonRecords();
    });
}

function updateCommonQueryStatus(queryId, action, recordId) {
    const fd = new FormData();
    fd.append('action', 'close_query');
    fd.append('query_id', queryId);
    fd.append('query_action', action);

    fetch('ajax_common_forms.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
        if (!res.success) {
            showToast(res.message || 'Error updating query status', 'error');
            return;
        }
        showToast(res.message, 'success');
        openCommonQueryModal(recordId);
        loadCommonRecords();
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
        document.getElementById('cfAuditRecordTitle').textContent = `Audit Trail for ${rec.record_number}`;
        const aBody = document.getElementById('cfAuditBody');

        let html = '';

        if (rec.audit_trail.length === 0) {
            html += `<p style="text-align: center; color: #94a3b8; padding: 1.5rem;">No audit records found.</p>`;
        } else {
            html += `<table style="width: 100%; border-collapse: collapse; font-size: 0.825rem;">
                <thead style="background: #f8fafc; border-bottom: 1px solid #e2e8f0; color: #475569;">
                    <tr>
                        <th style="padding: 0.6rem; text-align: left;">Timestamp</th>
                        <th style="padding: 0.6rem; text-align: left;">Field</th>
                        <th style="padding: 0.6rem; text-align: left;">Previous Value</th>
                        <th style="padding: 0.6rem; text-align: left;">New Value</th>
                        <th style="padding: 0.6rem; text-align: left;">Actor</th>
                    </tr>
                </thead>
                <tbody>
            `;

            rec.audit_trail.forEach(aud => {
                html += `
                    <tr style="border-bottom: 1px solid #f1f5f9;">
                        <td style="padding: 0.6rem; color: #64748b;">${aud.action_at}</td>
                        <td style="padding: 0.6rem; font-weight: 600; color: #0f172a;">${escapeHtml(aud.field_name)}</td>
                        <td style="padding: 0.6rem; color: #ef4444; max-width: 140px; word-break: break-word;">${escapeHtml(aud.old_value || '(empty)')}</td>
                        <td style="padding: 0.6rem; color: #047857; max-width: 140px; word-break: break-word;">${escapeHtml(aud.new_value || '(empty)')}</td>
                        <td style="padding: 0.6rem; color: #475569;">${escapeHtml(aud.user)}</td>
                    </tr>
                `;
            });

            html += `</tbody></table>`;
        }

        aBody.innerHTML = html;
        openModal('modalCommonAudit');
    });
}

function openCommonAuditModalFromEditor() {
    const recId = parseInt(document.getElementById('cfRecordId').value);
    if (recId > 0) {
        openCommonAuditModal(recId);
    }
}
</script>

