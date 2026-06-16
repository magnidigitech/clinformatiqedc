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
// Helper to check roles
$current_role = strtolower($_SESSION['active_role_name'] ?? '');
// Loose matching for roles
$is_monitor = (strpos($current_role, 'monitor') !== false);
$is_manager = (strpos($current_role, 'manager') !== false) || (strpos($current_role, 'coordinator') !== false); 
$is_admin = (strpos($current_role, 'admin') !== false);
$can_edit = ($is_admin || $current_role === 'data_entry' || $current_role === 'investigator' || $is_manager);

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
$stmt_status = $pdo->prepare("SELECT form_id, status, progress_percent, is_verified, COALESCE(repeating_instance_id, 0) as repeating_instance_id FROM subject_form_status WHERE subject_id = ?");
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
    $current_visit_name = $structure[$current_visit_id]['name'] ?? '';
    $current_form_name = '';
    foreach ($structure[$current_visit_id]['forms'] ?? [] as $frm) {
        if ($frm['id'] == $current_form_id) {
            $current_form_name = $frm['name'];
            break;
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
if ($current_form_id && $subject_id) {
    // Standardize instance ID for query
    $rep_inst_id = (int)($current_instance_id ?? 0);
    
    // Robust query handling both 0 and NULL (for older data)
    $stmt = $pdo->prepare("SELECT field_id, value FROM subject_data WHERE subject_id = ? AND form_id = ? AND (repeating_instance_id = ? OR (? = 0 AND repeating_instance_id IS NULL))");
    $stmt->execute([$subject_id, $current_form_id, $rep_inst_id, $rep_inst_id]);
    $existing_data = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
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
        .tree-form.active { color: var(--accent-color); font-weight: 500; background: #eff6ff; }
        
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
        .status-icon.completed { color: #10b981; }
        
        .field-label { font-weight: 500; color: var(--text-dark); font-size: 0.95rem; flex: 1; }
        .field-actions { opacity: 0; transition: opacity 0.2s; }
        .crf-field:hover .field-actions { opacity: 1; }
        
        .input-wrapper { margin-left: 2rem; max-width: 400px; }
        .crf-input { width: 100%; padding: 0.5rem; border: 1px solid var(--border-color); border-radius: 4px; font-size: 0.9rem; transition: border-color 0.2s; }
        .crf-input:focus { border-color: var(--accent-color); outline: none; box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.1); }
        
        .field-help { font-size: 0.75rem; color: #64748b; margin-top: 0.25rem; font-style: italic; }
        .field-unit { position: absolute; right: 0.75rem; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 0.85rem; pointer-events: none; }
        
        .progress-bar { height: 4px; background: #e2e8f0; border-radius: 2px; margin-top: 0.5rem; overflow: hidden; }
        .progress-fill { height: 100%; background: #10b981; width: 0%; transition: width 0.3s; }
        
    </style>
</head>
<body>

<div class="app-layout" style="display: block;">
    <!-- Top Header -->
    <header class="top-nav" style="border-bottom: 1px solid var(--border-color); padding: 0 1.5rem; height: 64px; display: flex; align-items: center; background: white; z-index: 10;">
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
<?php
// Determine View Mode
$can_edit = hasPermission('enter_data') || hasPermission('all');
?>
        <div style="margin-left: auto; display: flex; gap: 0.5rem;">
            <?php 
                // Check if current form is verifiable
                $curr_stat_key = $current_form_id . '_' . (int)($current_instance_id ?? 0);
                $curr_stat = $statuses[$curr_stat_key] ?? ['status' => 'empty', 'progress' => 0, 'is_verified' => 0];
                $is_complete = ($curr_stat['status'] === 'complete' || $curr_stat['progress'] == 100);
                $is_verified = ($curr_stat['status'] === 'verified' || !empty($curr_stat['is_verified'])); // Check both for safety
            ?>

            <?php if ($prev_link): ?>
                <a href="<?php echo $prev_link; ?>" class="btn btn-outline">Previous</a>
            <?php endif; ?>
            
            <button class="btn btn-outline" onclick="location.reload()"><?php echo $can_edit ? 'Discard Changes' : 'Refresh'; ?></button>
            
            <?php if (($is_monitor || $is_admin) && $is_complete && !$is_verified): ?>
                <button type="button" class="btn btn-primary" style="background-color: #059669; border-color: #059669;" onclick="verifyForm()">
                    <span class="material-icons-round" style="font-size: 1.1rem; margin-right: 0.25rem;">verified</span>
                    Mark as Verified
                </button>
            <?php endif; ?>

            <?php if ($is_verified): ?>
                 <span style="display: flex; align-items: center; color: #059669; font-weight: 600; padding: 0 1rem; background: #d1fae5; border-radius: 6px; font-size: 0.9rem;">
                    <span class="material-icons-round" style="font-size: 1.1rem; margin-right: 0.25rem;">verified</span> Verified
                 </span>
            <?php endif; ?>
            
            <?php if ($can_edit && !$is_verified): ?>
                <button class="btn btn-primary" onclick="saveData(false)">Save</button>
                <?php if ($next_link): ?>
                    <button class="btn btn-primary" style="background:#2563eb;" onclick="saveData(true)">Save & Next</button>
                <?php endif; ?>
            <?php endif; ?>
            
            <?php if (!$can_edit && $next_link): ?>
                 <a href="<?php echo $next_link; ?>" class="btn btn-primary">Next</a>
            <?php endif; ?>
        </div>
    </header>

    <div class="entry-layout">
        <!-- Sidebar Tree -->
        <aside class="entry-sidebar">
            <div style="padding: 1.5rem; border-bottom: 1px solid var(--border-color); background: white;">
                <div style="font-weight: 600; margin-bottom: 0.5rem; display: flex; justify-content: space-between;">
                    <span>Data collection progress</span>
                    <span id="global-progress-text"><?php echo $subject_global_progress; ?>%</span>
                </div>
                <div class="progress-bar" style="height: 6px; margin-top: 0; background: #e2e8f0;">
                    <div id="global-progress-bar" class="progress-fill" style="width: <?php echo $subject_global_progress; ?>%; background: #10b981;"></div>
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
                                    <div id="visit-progress-bar-<?php echo $vid; ?>" class="progress-fill" style="width: <?php echo $v_prog; ?>%; background: #10b981;"></div>
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
                                ?>
                                <a href="?subject_id=<?php echo $subject_id; ?>&visit_id=<?php echo $vid; ?>&form_id=<?php echo $form['id']; ?>" 
                                   class="tree-form <?php echo ($form['id'] == $current_form_id && !$current_module_id) ? 'active' : ''; ?>">
                                   
                                   <div style="display: flex; align-items: center; gap: 0.75rem;">
                                       <!-- Status Icon -->
                                       <span id="form-icon-<?php echo $form['id']; ?>-0" class="material-icons-round" style="font-size: 1.25rem; <?php 
                                            if ($f_stat['status'] == 'complete' || $f_stat['progress'] == 100) echo 'color: #10b981;';
                                            elseif ($f_stat['status'] == 'in_progress' || $f_stat['progress'] > 0) echo 'color: #3b82f6;';
                                            else echo 'color: #cbd5e1;';
                                       ?>">
                                            <?php 
                                            if ($f_stat['status'] == 'complete' || $f_stat['progress'] == 100) echo 'check_circle';
                                            elseif ($f_stat['status'] == 'in_progress' || $f_stat['progress'] > 0) echo 'hourglass_top';
                                            else echo 'radio_button_unchecked';
                                            ?>
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

            </div>
        </aside>

        <!-- Main Form Area -->
        <main class="entry-main">
            <?php if ($current_module_id && !$current_instance_id): ?>
                <!-- Module Instances List View -->
                <div class="crf-card">
                    <div class="crf-header" style="display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <div style="font-size: 0.85rem; color: var(--text-light); margin-bottom: 0.25rem;">Repeating Data</div>
                            <h1 style="font-size: 1.5rem; margin: 0;"><?php echo htmlspecialchars($current_module['name']); ?></h1>
                        </div>
                        <?php if ($can_edit): ?>
                            <button class="btn btn-primary" onclick="createInstance()">+ Add New Entry</button>
                        <?php endif; ?>
                    </div>
                    
                    <div class="crf-body" style="padding: 0;">
                        <?php if(empty($instances[$current_module_id] ?? [])): ?>
                             <div style="padding: 3rem; text-align: center; color: var(--text-light);">
                                <span class="material-icons-round" style="font-size: 3rem; color: #cbd5e1; margin-bottom: 1rem;">toc</span>
                                <p>No entries found for this module.</p>
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
                                                <?php echo date('d M Y', strtotime($inst['created_at'])); ?>
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
                                $has_open_query = false;
                                $query_count = count($field_queries);
                                foreach ($field_queries as $s) {
                                    if (in_array($s, ['new', 'open', 'unconfirmed'])) {
                                        $has_open_query = true;
                                        break;
                                    }
                                }
                            ?>
                                <div class="crf-field" data-field-id="<?php echo $field['id']; ?>">
                                    <div class="field-label-row">
                                        <!-- Field Status Icon -->
                                        <span class="material-icons-round status-icon" style="color: <?php echo $is_f ? 'var(--accent-color)' : '#94a3b8'; ?>">
                                            <?php echo $is_f ? 'check_circle' : 'radio_button_unchecked'; ?>
                                        </span>
                                        
                                        <!-- Field Label -->
                                        <div class="field-label">
                                            <?php echo ($index + 1) . '. ' . htmlspecialchars($field['label']); ?>
                                            <?php if($field['is_required']) echo '<span style="color:#ef4444">*</span>'; ?>
                                        </div>
                                        
                                        <!-- Query Status Icon (Red ?) -->
                                        <?php if ($query_count > 0): ?>
                                            <div style="cursor: pointer; position: relative;" onclick="handleFieldAction('view_queries', <?php echo $field['id']; ?>, '<?php echo addslashes($field['label']); ?>')">
                                                <span class="material-icons-round" style="color: <?php echo $has_open_query ? '#ef4444' : '#10b981'; ?>;">help_outline</span>
                                                <span style="position: absolute; top: -5px; right: -5px; background: <?php echo $has_open_query ? '#ef4444' : '#10b981'; ?>; color: white; font-size: 0.6rem; padding: 1px 4px; border-radius: 99px;"><?php echo $query_count; ?></span>
                                            </div>
                                        <?php endif; ?>

                                        <!-- Actions Menu (3 dots) -->
                                        <div class="field-actions" style="position: relative;">
                                            <span class="material-icons-round hover-icon" style="font-size: 1.25rem; color: #94a3b8; cursor: pointer;" onclick="toggleMenu('field-menu-<?php echo $field['id']; ?>', event)">more_vert</span>
                                            
                                            <!-- Dropdown -->
                                            <div id="field-menu-<?php echo $field['id']; ?>" class="dropdown-menu">
                                                
                                                <?php if ($is_monitor || $is_admin): ?>
                                                <div class="dropdown-item" onclick="handleFieldAction('add_query', <?php echo $field['id']; ?>, '<?php echo addslashes($field['label']); ?>')">
                                                    <span class="material-icons-round" style="font-size: 1rem; color: #ef4444; margin-right: 0.5rem;">help_outline</span> Add query
                                                </div>
                                                <?php endif; ?>
                                                
                                                <?php if($query_count > 0): ?>
                                                    <div class="dropdown-item" onclick="handleFieldAction('view_queries', <?php echo $field['id']; ?>, '<?php echo addslashes($field['label']); ?>')">
                                                        <span class="material-icons-round" style="font-size: 1rem; color: var(--accent-color); margin-right: 0.5rem;">visibility</span> View queries
                                                    </div>
                                                <?php endif; ?>
                                                
                                                <?php if ($is_manager || $is_admin): ?>
                                                    <div class="dropdown-item" onclick="handleFieldAction('clear_data', <?php echo $field['id']; ?>, '<?php echo addslashes($field['label']); ?>')">
                                                        <span class="material-icons-round" style="font-size: 1rem; color: #f59e0b; margin-right: 0.5rem;">backspace</span> Clear
                                                    </div>
                                                    
                                                    <div class="dropdown-item" onclick="handleFieldAction('mark_missing', <?php echo $field['id']; ?>, '<?php echo addslashes($field['label']); ?>')">
                                                        <span class="material-icons-round" style="font-size: 1rem; color: #64748b; margin-right: 0.5rem;">block</span> Mark field section
                                                    </div>
                                                <?php endif; ?>
                                                
                                                <div class="dropdown-item" onclick="handleFieldAction('comments', <?php echo $field['id']; ?>, '<?php echo addslashes($field['label']); ?>')">
                                                    <span class="material-icons-round" style="font-size: 1rem; color: var(--text-light); margin-right: 0.5rem;">chat_bubble_outline</span> Comments
                                                </div>
                                                
                                                <div class="dropdown-item" onclick="handleFieldAction('history', <?php echo $field['id']; ?>, '<?php echo addslashes($field['label']); ?>')">
                                                    <span class="material-icons-round" style="font-size: 1rem; color: var(--text-light); margin-right: 0.5rem;">history</span> History
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="input-wrapper">
                                        <?php renderFieldInput($field, $existing_data[$field['id']] ?? '', $choices_map); ?>
                                        <?php if(!empty($field['help_text'])): ?>
                                            <div class="field-help"><?php echo htmlspecialchars($field['help_text']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>
</div>
<!-- Custom Modal for Verification -->
<style>
    .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; justify-content: center; align-items: center; }
    .modal-box { background: white; border-radius: 8px; padding: 2rem; max-width: 500px; width: 90%; box-shadow: 0 10px 25px rgba(0,0,0,0.2); text-align: center; }
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

<script>
    function toggleVisit(el) {
        el.classList.toggle('active');
        const next = el.nextElementSibling;
        if(next && next.classList.contains('visit-forms')) {
            next.classList.toggle('active');
        }
    }

    // Instance Management
    async function createInstance() {
        const label = prompt("Enter label for new entry (e.g. 'AE-01' or leave empty for auto):");
        if (label === null) return; 
        
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

    function verifyForm() {
        document.getElementById('verifyModal').style.display = 'flex';
    }

    function closeVerifyModal() {
        document.getElementById('verifyModal').style.display = 'none';
    }

    async function confirmVerify() {
        closeVerifyModal();
        const formData = new FormData();
        formData.append('action', 'verify_form');
        formData.append('subject_id', '<?php echo $subject_id; ?>');
        formData.append('form_id', '<?php echo $current_form_id; ?>');
        formData.append('visit_id', '<?php echo $current_visit_id ?? 0; ?>');
        formData.append('repeating_instance_id', '<?php echo $current_instance_id ?? 0; ?>');
        
        try {
            const res = await fetch('ajax_data.php', { method: 'POST', body: formData });
            const data = await res.json();
            if(data.success) {
                alert("Form verified successfully!"); // This simple alert is ok for success feedback? User said "alert modal" disliked.
                // Let's replace this with a reload, or maybe a toast? 
                // For now, let's just reload.
                location.reload();
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
                            color = '#10b981';
                            icon = 'check_circle';
                        } else if (data.form_status === 'in_progress' || data.form_progress > 0) {
                            color = '#3b82f6';
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
             input.addEventListener('input', debouncedSave);
             input.addEventListener('change', debouncedSave);
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
                <label>Remark <span style="color:red">*</span></label>
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
            <h3>Queries for field <span id="viewQueryFieldName"></span></h3>
            <button class="btn-icon" onclick="closeModal('viewQueryModal')"><span class="material-icons-round">close</span></button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="viewQueryFieldId">
            <div class="form-group">
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
                    <label>Remark <span style="color:red">*</span></label>
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
            <button class="btn btn-outline" onclick="closeModal('viewQueryModal')">Cancel</button>
            <button class="btn btn-primary" onclick="submitUpdateQuery()">Save changes</button>
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
    <div class="modal-content" style="width: 800px;">
        <div class="modal-header">
            <h3>Value change history for '<span id="historyFieldName"></span>'</h3>
            <button class="btn-icon" onclick="closeModal('historyModal')"><span class="material-icons-round">close</span></button>
        </div>
        <div class="modal-body">
            <table style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
                <thead style="background: #f8fafc; border-bottom: 1px solid var(--border-color);">
                    <tr>
                        <th style="text-align: left; padding: 0.75rem;">Updated on</th>
                        <th style="text-align: left; padding: 0.75rem;">Updated by</th>
                        <th style="text-align: left; padding: 0.75rem;">Old value</th>
                        <th style="text-align: left; padding: 0.75rem;">New value</th>
                        <th style="text-align: left; padding: 0.75rem;">Reason / Type</th>
                    </tr>
                </thead>
                <tbody id="historyTableBody">
                    <!-- Filled via JS -->
                </tbody>
            </table>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('historyModal')">Close</button>
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
        if (!text) { alert("Remark is required"); return; }
        
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
                color = '#10b981';
                icon = 'check_circle';
            } else if (data.form_status === 'in_progress' || data.form_progress > 0) {
                color = '#3b82f6';
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
    async function openViewQueryModal(fieldId, fieldName) {
         document.getElementById('viewQueryFieldId').value = fieldId;
         document.getElementById('viewQueryFieldName').innerText = fieldName;
         openModal('viewQueryModal');
         
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
                 select.innerHTML = '<option value="">Select a query</option>';
                 data.queries.forEach((q, idx) => {
                     const opt = document.createElement('option');
                     opt.value = q.id;
                     opt.text = `Query #${q.id} (${q.status}) - ${q.created_at}`;
                     select.add(opt);
                 });
                 if (data.queries.length > 0) {
                     select.value = data.queries[0].id; // Auto select first?
                     loadQueryDetails(data.queries[0].id);
                 }
             }
         } catch(e) { console.error(e); }
    }
    
    async function loadQueryDetails(queryId) {
        if (!queryId) {
            document.getElementById('queryDetailsSection').style.display = 'none';
            return;
        }
        const query = currentQueries.find(q => q.id == queryId);
        if (!query) return;
        
        document.getElementById('queryDetailsSection').style.display = 'block';
        document.getElementById('queryCurrentStatus').innerText = query.status.charAt(0).toUpperCase() + query.status.slice(1);
        
        // Dynamic Status Options based on Role and Current Status
        const statusSelect = document.getElementById('queryNewStatus');
        statusSelect.innerHTML = '<option value="">Please select</option>';
        
        <?php if ($is_monitor || $is_admin): ?>
            // Monitors can: Close, Re-open (if answered), Confirm
            if (['new', 'open', 'answered', 'unconfirmed'].includes(query.status)) {
                 statusSelect.add(new Option('Close Query', 'closed'));
            }
             if (['closed', 'resolved'].includes(query.status)) {
                 statusSelect.add(new Option('Re-open Query', 'open'));
            }
        <?php endif; ?>

        <?php if ($is_manager || $is_admin): ?>
            // Managers can: Answer (mark as Answered)
            // If they are replying, standard flow is to set to Answered
             if (['new', 'open'].includes(query.status)) {
                 statusSelect.add(new Option('Mark as Answered', 'answered'));
            }
        <?php endif; ?>

        // Default open fallback
        if (statusSelect.options.length === 1) {
             statusSelect.add(new Option('Open', 'open'));
             statusSelect.add(new Option('Answered', 'answered'));
             statusSelect.add(new Option('Closed', 'closed'));
        }

        document.getElementById('queryNewStatus').value = '';
        document.getElementById('queryUpdateRemark').value = '';
        
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
                     li.innerHTML = `
                        <div style="font-size: 0.75rem; color: #64748b; margin-bottom: 2px;">
                            <strong>${h.created_by_name || 'User'}</strong> - ${h.created_at}
                        </div>
                        <div style="font-size: 0.85rem; color: #334155; margin-bottom: 4px;">${h.remark}</div>
                        <div style="font-size: 0.75rem; color: #94a3b8; font-style: italic;">
                            Status: ${h.status_from || 'New'} &rarr; ${h.status_to}
                        </div>
                     `;
                     list.appendChild(li);
                 });
             }
        } catch(e) {}
    }

    async function submitUpdateQuery() {
         const queryId = document.getElementById('querySelect').value;
         const status = document.getElementById('queryNewStatus').value;
         const remark = document.getElementById('queryUpdateRemark').value;
         
         if (!queryId) return;
         if (!status) { alert("Please select a new status"); return; }
         if (!remark) { alert("Remark is required"); return; }
         
         const fd = new FormData();
         fd.append('action', 'update_query_status');
         fd.append('query_id', queryId);
         fd.append('status', status);
         fd.append('remark', remark);
         
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
                        <td style="padding: 0.75rem;">${h.action_at}</td>
                        <td style="padding: 0.75rem;">${h.action_by_name}</td>
                        <td style="padding: 0.75rem; color: #ef4444;">${h.old_value || ''}</td>
                        <td style="padding: 0.75rem; color: #10b981;">${h.new_value || ''}</td>
                        <td style="padding: 0.75rem;">${h.reason_for_change || h.change_type}</td>
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
                echo '<div style="margin-top:0.5rem; font-size:0.85rem;"><a href="uploads/'.htmlspecialchars($value).'" target="_blank" style="color:#2563eb;">View Uploaded File</a></div>';
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
