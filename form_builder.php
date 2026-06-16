<?php
require_once 'includes/functions.php';
require_once 'includes/auth.php';

requireLogin();

if (!isset($_SESSION['active_study_id'])) {
    redirect('dashboard.php');
}

if (!hasPermission('all') && !hasPermission('manage_structure')) {
    die("Unauthorized access. You do not have permission to edit the study structure.");
}

$study_id = $_SESSION['active_study_id'];
$study_name = $_SESSION['active_study_name'];
$pdo = getDB();

// Fetch Visits
$visits_stmt = $pdo->prepare("SELECT * FROM study_visits WHERE study_id = ? ORDER BY order_index ASC");
$visits_stmt->execute([$study_id]);
$visits = $visits_stmt->fetchAll();

// Fetch Repeating Modules
$modules_stmt = $pdo->prepare("SELECT * FROM study_repeating_modules WHERE study_id = ? ORDER BY order_index ASC");
$modules_stmt->execute([$study_id]);
$modules = $modules_stmt->fetchAll();

// Determine Context
$current_module_id = $_GET['module_id'] ?? null;
$current_visit_id = $_GET['visit_id'] ?? null;
$current_form_id = $_GET['form_id'] ?? null;

// Default to Visit context if nothing selected, unless no visits then module.
if (!$current_visit_id && !$current_module_id) {
    if (!empty($visits)) {
        $current_visit_id = $visits[0]['id'];
    } elseif (!empty($modules)) {
        $current_module_id = $modules[0]['id'];
    }
}

$forms = [];
$context = 'visit'; // or 'module'

if ($current_module_id) {
    $context = 'module';
    $current_visit_id = null; // Reset visit if module selected
    
    $forms_stmt = $pdo->prepare("SELECT * FROM study_forms WHERE repeating_module_id = ? ORDER BY order_index ASC");
    $forms_stmt->execute([$current_module_id]);
    $forms = $forms_stmt->fetchAll();
    
    if (!$current_form_id && !empty($forms)) {
        $current_form_id = $forms[0]['id'];
    }
} elseif ($current_visit_id) {
    $context = 'visit';
    $forms_stmt = $pdo->prepare("SELECT * FROM study_forms WHERE visit_id = ? ORDER BY order_index ASC");
    $forms_stmt->execute([$current_visit_id]);
    $forms = $forms_stmt->fetchAll();
    
    if (!$current_form_id && !empty($forms)) {
        $current_form_id = $forms[0]['id'];
    }
}

// Fetch Fields
$fields = [];
if ($current_form_id) {
    $fields_stmt = $pdo->prepare("SELECT * FROM form_fields WHERE form_id = ? ORDER BY order_index ASC");
    $fields_stmt->execute([$current_form_id]);
    $fields = $fields_stmt->fetchAll();
}

// Fetch Option Groups for Dropdowns (Filtered by Study)
$opt_groups_stmt = $pdo->prepare("SELECT id, name FROM option_groups WHERE study_id = ? ORDER BY name");
$opt_groups_stmt->execute([$study_id]);
$option_groups = $opt_groups_stmt->fetchAll();
?>
<script>
    const optionGroups = <?php echo json_encode($option_groups); ?>;
</script>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Form Builder - <?php echo htmlspecialchars($study_name); ?></title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.14.0/Sortable.min.js"></script>
    <style>
        /* Study Design Layout Styles (Merged) */
        .design-layout { display: flex; height: calc(100vh - 64px); }
        .design-sidebar { width: 250px; background: #f8fafc; border-right: 1px solid var(--border-color); padding: 1rem 0; flex-shrink: 0; }
        
        /* Builder Specifics */
        .builder-wrapper { flex: 1; display: flex; overflow: hidden; } /* Replaces builder-layout context */
        .builder-sidebar { width: 280px; background: white; border-right: 1px solid var(--border-color); overflow-y: auto; padding: 1rem; flex-shrink: 0; }
        .builder-canvas-wrapper { flex: 1; background: #f1f5f9; display: flex; flex-direction: column; overflow: hidden; }
        .builder-header { padding: 1rem 2rem; background: white; border-bottom: 1px solid var(--border-color); display: flex; align-items: center; gap: 1rem; }
        .builder-canvas { flex: 1; padding: 2rem; overflow-y: auto; max-width: 100%; margin: 0; width: 100%; padding-bottom: 5rem; }
        .empty-canvas-state { border: 2px dashed #cbd5e1; border-radius: var(--radius-lg); padding: 3rem; text-align: center; color: var(--text-light); background: rgba(255,255,255,0.5); margin-top: 1rem; }
        
        .nav-group-label {
            padding: 0.5rem 1.5rem;
            font-size: 0.75rem;
            text-transform: uppercase;
            color: var(--text-light);
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .nav-item {
            display: block;
            padding: 0.75rem 1.5rem;
            color: var(--text-dark);
            text-decoration: none;
            font-size: 0.9rem;
        }
        .nav-item:hover { background: #f1f5f9; color: var(--accent-color); }
        .nav-item.active { background: #eff6ff; color: var(--accent-color); font-weight: 500; border-right: 3px solid var(--accent-color); }

        .toolbox-section-title {
            font-size: 0.75rem; color: #94a3b8; margin: 1.5rem 0 0.5rem 0; font-weight: 600; letter-spacing: 0.05em;
        }
        
        /* Modal Tabs */
        .modal-tabs { display: flex; border-bottom: 1px solid var(--border-color); margin: -1.5rem -1.5rem 1.5rem -1.5rem; padding: 0 1.5rem; background: #f8fafc; }
        .modal-tab { padding: 1rem; cursor: pointer; border-bottom: 2px solid transparent; font-size: 0.875rem; font-weight: 500; color: var(--text-light); }
        .modal-tab.active { border-bottom-color: var(--accent-color); color: var(--accent-color); }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        
        /* Custom Confirm Modal */
        .confirm-modal-content { max-width: 400px; text-align: center; padding: 2rem !important; }
        .confirm-icon { font-size: 3rem; color: #ef4444; margin-bottom: 1rem; display: block; }
        .confirm-title { font-size: 1.125rem; font-weight: 600; margin-bottom: 0.5rem; }
        .confirm-text { color: var(--text-light); margin-bottom: 1.5rem; }
        
        /* Field Icons */
        .toolbox-item .material-icons-round { font-size: 1.25rem; color: var(--text-light); min-width: 24px; }
        
        /* Drop Zone Footer */
        .drop-zone-footer {
            border: 2px dashed #cbd5e1; border-radius: var(--radius-lg); padding: 1.5rem; text-align: center; color: var(--text-light); background: rgba(255,255,255,0.5); margin-top: 1rem; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 0.5rem; user-select: none; transition: background 0.2s, border-color 0.2s;
        }
        .drop-zone-footer span { font-size: 2rem; color: #94a3b8; }
    </style>
</head>
<body>

<div class="app-layout" style="display: block;">
    
    <!-- Top Nav matching Option Groups -->
    <header class="top-nav" style="height: 64px; border-bottom: 1px solid var(--border-color); padding: 0 1.5rem; display: flex; align-items: center; justify-content: space-between; background: white; position: relative; z-index: 20;">
        <div style="display: flex; align-items: center; gap: 1rem;">
             <a href="study_structure.php" style="text-decoration: none; font-weight: 600; font-size: 1.25rem; color: var(--primary-color);">Clinformatiq</a>
             <span style="color: var(--border-color);">|</span>
             <span style="font-weight: 500; display: flex; align-items: center; gap: 0.5rem;">
                <?php echo htmlspecialchars($_SESSION['active_study_code']); ?> - Form Builder
                <?php renderRoleSwitcher($study_id); ?>
             </span>
        </div>
        <div style="display: flex; gap: 0.5rem;">
            <button class="btn btn-outline" onclick="location.href='study_structure.php'">Close</button>
            <button class="btn btn-primary" onclick="showToast('Changes saved successfully')">Save</button>
        </div>
    </header>

    <div class="design-layout">
        <!-- New Design Sidebar -->
        <aside class="design-sidebar">
            <div class="nav-group-label">
                <span style="display: flex; align-items: center; gap: 0.5rem;">
                    <span class="material-icons-round" style="font-size: 1.25rem;">account_tree</span>
                    Study design
                </span>
                <span class="material-icons-round" style="font-size: 1rem;">expand_less</span>
            </div>
            <nav>
                <a href="study_structure.php" class="nav-item">Structure</a>
                <a href="form_builder.php" class="nav-item active">Forms</a>
                <a href="option_groups.php" class="nav-item">Option groups</a>
            </nav>
        </aside>

        <!-- Builder Content (Nested) -->
        <div class="builder-wrapper">
            <aside class="builder-sidebar">
                <!-- Sidebar Context Removed (Moved to Header) -->
                <!-- 
                <div style="background: #f8fafc; padding: 1rem; border-radius: 0.5rem; border: 1px solid var(--border-color); margin-bottom: 1.5rem;">
                    <div style="font-size: 0.75rem; font-weight: 600; color: var(--text-light); margin-bottom: 0.5rem;">CONTEXT</div>
                    <div style="margin-bottom: 0.75rem;">
                        <label style="font-size: 0.75rem; display: block; margin-bottom: 0.25rem;">Visit</label>
                        <select class="form-input" style="padding: 0.35rem; font-size: 0.85rem;" onchange="window.location.href='?visit_id='+this.value">
                            <?php foreach ($visits as $v): ?>
                                <option value="<?php echo $v['id']; ?>" <?php echo $v['id'] == $current_visit_id ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($v['name']); ?>
                                </option>
                            <?php endforeach; ?>
                            <?php if (empty($visits)): ?><option value="">- No Visits -</option><?php endif; ?>
                        </select>
                </div>
                <div style="display: flex; gap: 0.5rem;">
                    <button class="btn btn-sm btn-outline" style="flex:1;" onclick="openVisitModal()">+ Visit</button>
                </div>
                
                 <div style="margin-top: 0.75rem;">
                    <label style="font-size: 0.75rem; display: block; margin-bottom: 0.25rem;">Form</label>
                    <select class="form-input" style="padding: 0.35rem; font-size: 0.85rem;" onchange="window.location.href='?visit_id=<?php echo $current_visit_id; ?>&form_id='+this.value">
                        <?php foreach ($forms as $f): ?>
                            <option value="<?php echo $f['id']; ?>" <?php echo $f['id'] == $current_form_id ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($f['name']); ?>
                            </option>
                        <?php endforeach; ?>
                        <?php if (empty($forms)): ?><option value="">- No Forms -</option><?php endif; ?>
                    </select>
                </div>
                 <div style="margin-top: 0.5rem;">
                     <button class="btn btn-sm btn-outline" style="width:100%;" onclick="openFormModal()">+ Form</button>
                 </div>
            </div>
            -->

            <div class="field-toolbox">
                <div class="toolbox-section-title">DATA COLLECTION</div>
                <div class="toolbox-item" draggable="true" data-type="checkbox"><span class="material-icons-round">check_box</span> Checkboxes</div>
                <div class="toolbox-item" draggable="true" data-type="date"><span class="material-icons-round">event</span> Date</div>
                <div class="toolbox-item" draggable="true" data-type="datetime"><span class="material-icons-round">edit_calendar</span> Date & Time</div>
                <div class="toolbox-item" draggable="true" data-type="dropdown"><span class="material-icons-round">arrow_drop_down_circle</span> Dropdown</div>
                <div class="toolbox-item" draggable="true" data-type="number"><span class="material-icons-round">looks_one</span> Number</div>
                <div class="toolbox-item" draggable="true" data-type="number_date"><span class="material-icons-round">perm_contact_calendar</span> Number & Date</div>
                <div class="toolbox-item" draggable="true" data-type="radio"><span class="material-icons-round">radio_button_checked</span> Radio buttons</div>
                <div class="toolbox-item" draggable="true" data-type="slider"><span class="material-icons-round">linear_scale</span> Slider</div>
                <div class="toolbox-item" draggable="true" data-type="text"><span class="material-icons-round">short_text</span> Text</div>
                <div class="toolbox-item" draggable="true" data-type="textarea"><span class="material-icons-round">notes</span> Text (multiline)</div>
                <div class="toolbox-item" draggable="true" data-type="time"><span class="material-icons-round">schedule</span> Time</div>
                <div class="toolbox-item" draggable="true" data-type="year"><span class="material-icons-round">calendar_today</span> Year</div>
                
                <div class="toolbox-section-title">DYNAMIC</div>
                <div class="toolbox-item" draggable="true" data-type="calculation"><span class="material-icons-round">calculate</span> Calculation</div>
                <div class="toolbox-item" draggable="true" data-type="link"><span class="material-icons-round">link</span> Link</div>
                <div class="toolbox-item" draggable="true" data-type="qrcode"><span class="material-icons-round">qr_code</span> QR Code</div>
                <div class="toolbox-item" draggable="true" data-type="summary"><span class="material-icons-round">list_alt</span> Summary</div>

                <div class="toolbox-section-title">STRUCTURAL</div>
                <div class="toolbox-item" draggable="true" data-type="grid"><span class="material-icons-round">grid_on</span> Grid</div>
                <div class="toolbox-item" draggable="true" data-type="image"><span class="material-icons-round">image</span> Image</div>
                <div class="toolbox-item" draggable="true" data-type="remark"><span class="material-icons-round">comment</span> Remark</div>
                <div class="toolbox-item" draggable="true" data-type="upload"><span class="material-icons-round">cloud_upload</span> Upload file</div>
            </div>
        </aside>

        <!-- Main Canvas -->
        <div class="builder-canvas-wrapper">
            <!-- New Header with Dropdowns -->
            <!-- New Header with Dropdowns -->
            <div class="builder-header" style="flex-direction: column; align-items: flex-start; gap: 0; padding: 0.5rem 2rem; border-bottom: none; background: white;">
               
               <!-- Context Tabs -->
               <div class="structure-tabs" style="border-bottom: 2px solid transparent; width: 100%; margin-bottom: 1rem; display: flex; gap: 2rem;">
                    <div class="st-tab <?php echo $context === 'visit' ? 'active' : ''; ?>" 
                         style="padding: 0.5rem 0; font-weight: 600; font-size: 1.1rem; color: <?php echo $context === 'visit' ? '#1e293b' : '#64748b'; ?>; border-bottom: 2px solid <?php echo $context === 'visit' ? '#1e293b' : 'transparent'; ?>; cursor: pointer;"
                         onclick="window.location.href='?visit_id=<?php echo $visits[0]['id'] ?? ''; ?>'">Visits</div>
                    
                    <div class="st-tab <?php echo $context === 'module' ? 'active' : ''; ?>" 
                         style="padding: 0.5rem 0; font-weight: 500; font-size: 1.1rem; color: <?php echo $context === 'module' ? '#1e293b' : '#64748b'; ?>; border-bottom: 2px solid <?php echo $context === 'module' ? '#1e293b' : 'transparent'; ?>; cursor: pointer;"
                         onclick="window.location.href='?module_id=<?php echo $modules[0]['id'] ?? ''; ?>'">Repeating data</div>
               </div>

               <!-- Filters Row -->
               <div style="display: flex; gap: 1rem; width: 100%; align-items: flex-end;">
                   
                   <!-- Visit/Module Selector -->
                   <?php if ($context === 'visit'): ?>
                   <div style="flex: 0 0 300px;">
                       <label style="font-size: 0.85rem; color: #64748b; display: block; margin-bottom: 0.5rem;">Visit</label>
                       <div style="display: flex; gap: 0.5rem;">
                           <select class="form-input" style="background: white; border: 1px solid #e2e8f0; border-radius: 6px; padding: 0.6rem; font-size: 1rem; height: 45px; width: 100%;" onchange="window.location.href='?visit_id='+this.value">
                                <?php foreach ($visits as $v): ?>
                                    <option value="<?php echo $v['id']; ?>" <?php echo $v['id'] == $current_visit_id ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($v['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                                <?php if (empty($visits)): ?><option value="">- No Visits -</option><?php endif; ?>
                           </select>
                           <button class="btn btn-outline" style="height: 45px; width: 45px; display: flex; align-items: center; justify-content: center; border: 1px solid #e2e8f0; border-radius: 6px;" onclick="openVisitModal()"><span class="material-icons-round" style="font-size: 1.5rem; color: #1e293b;">add</span></button>
                       </div>
                   </div>
                   <?php else: ?>
                   <div style="flex: 0 0 300px;">
                       <label style="font-size: 0.85rem; color: #64748b; display: block; margin-bottom: 0.5rem;">Module</label>
                       <div style="display: flex; gap: 0.5rem;">
                           <select class="form-input" style="background: white; border: 1px solid #e2e8f0; border-radius: 6px; padding: 0.6rem; font-size: 1rem; height: 45px; width: 100%;" onchange="window.location.href='?module_id='+this.value">
                                <?php foreach ($modules as $m): ?>
                                    <option value="<?php echo $m['id']; ?>" <?php echo $m['id'] == $current_module_id ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($m['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                                <?php if (empty($modules)): ?><option value="">- No Modules -</option><?php endif; ?>
                           </select>
                           <button class="btn btn-outline" style="height: 45px; width: 45px; display: flex; align-items: center; justify-content: center; border: 1px solid #e2e8f0; border-radius: 6px;" onclick="openModuleModal()"><span class="material-icons-round" style="font-size: 1.5rem; color: #1e293b;">add</span></button>
                       </div>
                   </div>
                   <?php endif; ?>

                   <!-- Form Selector -->
                   <div style="flex: 0 0 300px;">
                       <label style="font-size: 0.85rem; color: #64748b; display: block; margin-bottom: 0.5rem;">Form</label>
                       <div style="display: flex; gap: 0.5rem;">
                           <select class="form-input" style="background: white; border: 1px solid #e2e8f0; border-radius: 6px; padding: 0.6rem; font-size: 1rem; height: 45px; width: 100%;" 
                                   onchange="window.location.href='?<?php echo $context === 'visit' ? 'visit_id='.$current_visit_id : 'module_id='.$current_module_id; ?>&form_id='+this.value">
                                <?php foreach ($forms as $f): ?>
                                    <option value="<?php echo $f['id']; ?>" <?php echo $f['id'] == $current_form_id ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($f['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                                <?php if (empty($forms)): ?><option value="">- No Forms -</option><?php endif; ?>
                           </select>
                           <button class="btn btn-outline" style="height: 45px; width: 45px; display: flex; align-items: center; justify-content: center; border: 1px solid #e2e8f0; border-radius: 6px;" onclick="openFormModal()"><span class="material-icons-round" style="font-size: 1.5rem; color: #1e293b;">add</span></button>
                           <!-- Edit Form Name Button -->
                           <?php if($current_form_id): ?>
                            <button class="btn btn-outline" style="height: 45px; width: 45px; display: flex; align-items: center; justify-content: center; border: 1px solid #e2e8f0; border-radius: 6px;" onclick="openFormModal(true)" title="Rename Form"><span class="material-icons-round" style="font-size: 1.25rem; color: #1e293b;">edit</span></button>
                           <?php endif; ?>
                       </div>
                   </div>
                   
               </div>
            </div>

            <div id="form-canvas" class="builder-canvas" data-form-id="<?php echo $current_form_id; ?>">
                <?php if (empty($visits)): ?>
                    <div class="empty-canvas-state">
                        <span class="material-icons-round" style="font-size: 3rem; color: var(--accent-color); margin-bottom: 1rem;">account_tree</span>
                        <h3 style="margin-bottom: 0.5rem; color: var(--primary-color);">Let's set up your study</h3>
                        <p style="margin-bottom: 1.5rem; max-width: 400px; margin-left: auto; margin-right: auto;">You need to create at least one Visit (e.g., "Screening") before you can build forms.</p>
                        <button class="btn btn-primary" onclick="openVisitModal()">Create First Visit</button>
                    </div>
                <?php elseif (!$current_form_id): ?>
                    <div class="empty-canvas-state">
                        <span class="material-icons-round" style="font-size: 3rem; color: var(--accent-color); margin-bottom: 1rem;">description</span>
                        <h3 style="margin-bottom: 0.5rem; color: var(--primary-color);">Select or Create a Form</h3>
                        <p style="margin-bottom: 1.5rem;">There are no forms selected. Choose one from the sidebar or create a new one to start building.</p>
                        <button class="btn btn-primary" onclick="openFormModal()">+ Create Form in <?php echo htmlspecialchars($curr_visit_name); ?></button>
                    </div>
                <?php else: ?>
                    <?php if (empty($fields)): ?>
                        <!-- Empty list but handled by footer below -->
                    <?php else: ?>
                        <?php foreach ($fields as $field): ?>
                            <div class="field-card" data-id="<?php echo $field['id']; ?>" data-type="<?php echo $field['type']; ?>" data-field-data="<?php echo base64_encode(json_encode($field)); ?>">
                                <div class="field-card-handle"><span class="material-icons-round">drag_indicator</span></div>
                                <div class="field-card-content">
                                    <div class="field-label"><?php echo htmlspecialchars($field['label']); ?> <?php if($field['is_required']) echo '<span style="color:red">*</span>'; ?></div>
                                    <div class="field-meta">Type: <?php echo htmlspecialchars($field['type']); ?> | Var: <?php echo $field['variable_name'] ? htmlspecialchars($field['variable_name']) : '<span style="color:red; font-weight:bold;">MISSING</span>'; ?></div>
                                </div>
                                <div class="field-card-actions">
                                    <button class="btn-icon" onclick="editFieldInternal(this)"><span class="material-icons-round">edit</span></button>
                                    <button class="btn-icon delete" onclick="deleteField(this)"><span class="material-icons-round">delete</span></button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    
                    <div class="drop-zone-footer">
                        <span class="material-icons-round">add_circle_outline</span>
                        Drag fields here from the sidebar
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Visit Modal -->
<div id="visitModal" class="modal-overlay">
    <form id="createVisitForm" class="modal-content">
        <div class="modal-header">
            <h3>Add New Visit</h3>
            <button type="button" class="close-modal" onclick="closeModals()"><span class="material-icons-round">close</span></button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label">Visit Name</label>
                <input type="text" name="name" class="form-input" required placeholder="e.g., Screening, Week 1">
            </div>
            <input type="hidden" name="study_id" value="<?php echo $study_id; ?>">
            <input type="hidden" name="action" value="create_visit">
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeModals()">Cancel</button>
            <button type="submit" class="btn btn-primary">Create Visit</button>
        </div>
    </form>
</div>

<!-- Module Modal -->
<div id="moduleModal" class="modal-overlay">
    <form id="createModuleForm" class="modal-content">
        <div class="modal-header">
            <h3>Add Repeating Module</h3>
            <button type="button" class="close-modal" onclick="closeModals()"><span class="material-icons-round">close</span></button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label">Module Name</label>
                <input type="text" name="name" class="form-input" required placeholder="e.g., Adverse Events">
            </div>
            <input type="hidden" name="study_id" value="<?php echo $study_id; ?>">
            <input type="hidden" name="action" value="create_module">
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeModals()">Cancel</button>
            <button type="submit" class="btn btn-primary">Create Module</button>
        </div>
    </form>
</div>

<!-- Form Modal -->
<div id="formModal" class="modal-overlay">
    <form id="createFormForm" class="modal-content">
        <div class="modal-header">
            <h3>Add New Form</h3>
            <button type="button" class="close-modal" onclick="closeModals()"><span class="material-icons-round">close</span></button>
        </div>
        <div class="modal-body">
            <?php if ($context === 'visit'): ?>
                <?php if(empty($visits)): ?>
                    <div class="alert alert-danger">Please create a Visit first.</div>
                <?php else: ?>
                    <div class="form-group">
                        <label class="form-label">Visit</label>
                        <select name="visit_id" class="form-input">
                            <?php foreach($visits as $v): ?>
                                <option value="<?php echo $v['id']; ?>" <?php echo $v['id'] == $current_visit_id ? 'selected' : ''; ?>><?php echo htmlspecialchars($v['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                 <?php if(empty($modules)): ?>
                    <div class="alert alert-danger">Please create a Module first.</div>
                <?php else: ?>
                    <div class="form-group">
                        <label class="form-label">Module</label>
                        <select name="repeating_module_id" class="form-input">
                            <?php foreach($modules as $m): ?>
                                <option value="<?php echo $m['id']; ?>" <?php echo $m['id'] == $current_module_id ? 'selected' : ''; ?>><?php echo htmlspecialchars($m['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
            
            <div class="form-group">
                <label class="form-label">Form Name</label>
                <input type="text" name="name" class="form-input" required placeholder="e.g., Demographics, Vitals">
            </div>
            <input type="hidden" name="action" value="create_form">
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeModals()">Cancel</button>
            <?php if (($context === 'visit' && !empty($visits)) || ($context === 'module' && !empty($modules))): ?>
                <button type="submit" class="btn btn-primary">Create Form</button>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Professional Edit Field Modal - REFACTORED STRUCTURE -->
<div id="editFieldModal" class="modal-overlay">
    <!-- Form wraps the entire content for correct FormData submission -->
    <form id="editFieldForm" class="modal-content" style="max-width: 650px;">
        <input type="hidden" name="id" id="fieldId">
        
        <div class="modal-header">
            <h3 id="editFieldTitle">Edit field</h3>
            <button type="button" class="close-modal" onclick="closeModals()"><span class="material-icons-round">close</span></button>
        </div>
        
        <div class="modal-tabs">
            <div class="modal-tab active" data-tab="general">General</div>
            <div class="modal-tab" data-tab="validations">Validations</div>
            <div class="modal-tab" data-tab="dependency">Dependency</div>
            <div class="modal-tab" data-tab="data-std">Data standardization</div>
            <div class="modal-tab" data-tab="advanced">Advanced</div>
        </div>
        
        <div class="modal-body" style="padding-top: 0;">
            <!-- General Tab -->
            <div class="tab-content active" id="tab-general">
                <div class="form-group">
                    <label class="form-label">Field type</label>
                    <select name="type" id="fieldType" class="form-input" style="background: #f8fafc;">
                        <optgroup label="Data Collection">
                            <option value="checkbox">Checkboxes</option>
                            <option value="date">Date</option>
                            <option value="datetime">Date & Time</option>
                            <option value="dropdown">Dropdown</option>
                            <option value="number">Number</option>
                            <option value="number_date">Number & Date</option>
                            <option value="radio">Radio buttons</option>
                            <option value="slider">Slider</option>
                            <option value="text">Text</option>
                            <option value="textarea">Text (multiline)</option>
                            <option value="time">Time</option>
                            <option value="year">Year</option>
                        </optgroup>
                        <optgroup label="Dynamic">
                            <option value="calculation">Calculation</option>
                            <option value="link">Link</option>
                            <option value="qrcode">QR Code</option>
                            <option value="summary">Summary</option>
                        </optgroup>
                        <optgroup label="Structural">
                            <option value="grid">Grid</option>
                            <option value="image">Image</option>
                            <option value="remark">Remark</option>
                            <option value="upload">Upload file</option>
                        </optgroup>
                        <optgroup label="Specialised">
                            <option value="survey_btn">Add Survey Button</option>
                            <option value="randomization">Randomization</option>
                            <option value="repeated">Repeated Measure</option>
                            <option value="repeating_btn">Repeating Data Button</option>
                        </optgroup>
                    </select>
                </div>

                <!-- Added Option Group Selector -->
                <div class="form-group" id="optGroupContainer" style="display: none; background: #fffbe6; padding: 10px; border: 1px solid #ffe58f; border-radius: 6px;">
                    <label class="form-label" style="color: #d48806;">Option Group (Required for Lists)</label>
                    <select name="option_group_id" id="fieldOptGroup" class="form-input">
                        <option value="">-- Select an Option Group --</option>
                        <option value="__NEW__" style="font-weight: 700; color: var(--primary-color);">+ Create New Option Group</option>
                        <?php foreach($option_groups as $og): ?>
                            <option value="<?php echo $og['id']; ?>"><?php echo htmlspecialchars($og['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small style="color: #8c8c8c;">Select the list of choices (e.g. Yes/No, Country) for this field.</small>
                </div>

                <div class="form-group">
                    <label class="form-label">Label</label>
                    <input type="text" name="label" id="fieldLabel" class="form-input" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Variable Name <span class="material-icons-round" style="font-size: 14px; color: var(--text-light);">info</span></label>
                    <div style="display: flex; gap: 0.5rem;">
                        <input type="text" name="variable_name" id="fieldVarName" class="form-input" required>
                        <button type="button" class="btn btn-outline" style="white-space: nowrap;" onclick="generateVarName()">Generate name</button>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Required</label>
                    <div style="display: flex; gap: 1.5rem; margin-top: 0.5rem;">
                        <label style="display: flex; align-items: center; gap: 0.5rem;">
                            <input type="radio" name="is_required" value="1" id="req_yes"> Yes
                        </label>
                        <label style="display: flex; align-items: center; gap: 0.5rem;">
                            <input type="radio" name="is_required" value="0" id="req_no"> No
                        </label>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Help text <span class="material-icons-round" style="font-size: 14px; color: var(--text-light);">info</span></label>
                    <textarea name="help_text" id="fieldHelpText" class="form-input" rows="2"></textarea>
                </div>
            </div>

            <!-- Validations Tab -->
            <div class="tab-content" id="tab-validations">
                <div class="form-group">
                    <label class="form-label">Lower limit</label>
                    <input type="text" name="min_value" id="fieldMin" class="form-input">
                </div>
                <div class="form-group">
                    <label class="form-label">Upper limit</label>
                    <input type="text" name="max_value" id="fieldMax" class="form-input">
                </div>
            </div>

             <!-- Other Tabs Placeholders -->
            <div class="tab-content" id="tab-dependency">
                <p style="color: var(--text-light); text-align: center; padding: 2rem;">Dependency logic coming soon.</p>
            </div>
            <div class="tab-content" id="tab-data-std">
                 <p style="color: var(--text-light); text-align: center; padding: 2rem;">CDASH Mapping coming soon.</p>
            </div>
            <div class="tab-content" id="tab-advanced">
                 <p style="color: var(--text-light); text-align: center; padding: 2rem;">Advanced settings coming soon.</p>
            </div>
        </div>

        <div class="modal-footer" style="justify-content: space-between;">
            <button type="button" class="btn btn-outline"><span class="material-icons-round" style="font-size: 16px; margin-right: 5px;">visibility</span> Preview</button>
            <div style="display: flex; gap: 0.5rem;">
                <button type="button" class="btn btn-outline" onclick="closeModals()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save</button>
            </div>
        </div>
    </form>
</div>

<!-- Add Option Group Modal -->
<div id="optionGroupModal" class="modal-overlay" style="z-index: 10001;">
    <form id="createOptionGroupForm" class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h3>Add option group</h3>
            <button type="button" class="close-modal" onclick="closeOptionGroupModal()"><span class="material-icons-round">close</span></button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label">Name <span class="material-icons-round" style="font-size: 14px; color: var(--text-light);">info</span></label>
                <input type="text" name="name" class="form-input" required placeholder="e.g. Yes/No, Countries">
            </div>
            
            <div class="form-group">
                <label class="form-label">Description</label>
                <textarea name="description" class="form-input" rows="2"></textarea>
            </div>
            
            <div class="form-group">
                <label class="form-label">Layout <span class="material-icons-round" style="font-size: 14px; color: var(--text-light);">info</span></label>
                <div style="display: flex; gap: 1.5rem; margin-top: 0.5rem;">
                    <label style="display: flex; align-items: center; gap: 0.5rem;">
                        <input type="radio" name="layout" value="vertical" checked> Vertical
                    </label>
                    <label style="display: flex; align-items: center; gap: 0.5rem;">
                        <input type="radio" name="layout" value="horizontal"> Horizontal
                    </label>
                </div>
            </div>

            <hr style="border: 0; border-top: 1px solid var(--border-color); margin: 1.5rem 0;">

            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                <h4 style="margin: 0;">Options</h4>
            </div>

            <div id="optionsList">
                <!-- Dynamic Rows -->
            </div>
            
            <div style="text-align: right; margin-top: 1rem;">
                <button type="button" class="btn btn-primary" onclick="addOptionRow()">+ Add Option</button>
            </div>

        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeOptionGroupModal()">Cancel</button>
            <button type="submit" class="btn btn-primary">Save</button>
        </div>
    </form>
</div>

<!-- Confirm Modal (Required for Delete) -->
<div id="confirmModal" class="modal-overlay" style="z-index: 20000; display: none;">
    <div class="modal-content confirm-modal-content" style="background: white; border-radius: 12px; padding: 2rem; text-align: center; max-width: 450px; position: relative;">
        <span class="material-icons-round confirm-icon" style="font-size: 3rem; color: #ef4444; margin-bottom: 1rem; display: block;">warning</span>
        <h3 class="confirm-title" id="confirmTitle" style="font-size: 1.25rem; font-weight: 600; margin-bottom: 0.5rem;">Are you sure?</h3>
        <p class="confirm-text" id="confirmMessage" style="color: #64748b; margin-bottom: 1.5rem;">This action cannot be undone.</p>
        
        <div id="confirmInputWrapper" style="display:none; margin-bottom: 1.5rem; text-align: left;">
            <p style="font-size: 0.9rem; color: var(--text-light); margin-bottom: 0.5rem;">To confirm, type: <strong id="confirmMatchText" style="color: var(--text-main);"></strong></p>
            <input type="text" id="confirmInput" class="form-input" placeholder="Type name here" autocomplete="off">
        </div>

        <div style="display: flex; gap: 0.5rem; justify-content: center;">
            <button class="btn btn-outline" onclick="closeConfirm()">Cancel</button>
            <button class="btn btn-primary" style="background: #ef4444; border-color: #ef4444;" id="confirmBtn">Yes, Delete</button>
        </div>
    </div>
</div>

<script src="assets/js/form_builder.js?v=<?php echo time(); ?>"></script>
<script>
    // Tab Switching Logic
    document.querySelectorAll('.modal-tab').forEach(tab => {
        tab.addEventListener('click', () => {
            document.querySelectorAll('.modal-tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            tab.classList.add('active');
            document.getElementById('tab-' + tab.dataset.tab).classList.add('active');
        });
    });

    function generateVarName() {
        // User requesting BLANK variable name by default. 
        // Only generate if explicitly clicked, but standard behavior is empty.
        const label = document.getElementById('fieldLabel').value;
        if(label) {
            let varName = label.toLowerCase().replace(/[^a-z0-9]/g, '_').substring(0, 30);
            // Ensure strict format
            varName = varName.replace(/^[^a-z]+/, ''); // Must start with letter? optional
            document.getElementById('fieldVarName').value = varName;
        }
    }
    
    function openVisitModal() {
        document.getElementById('visitModal').classList.add('active');
    }
    function openModuleModal() {
        document.getElementById('moduleModal').classList.add('active');
    }
    function openFormModal() {
        document.getElementById('formModal').classList.add('active');
    }
    function closeModals() {
        document.querySelectorAll('.modal-overlay').forEach(el => el.classList.remove('active'));
    }
    // Simple inline handlers for creation
    document.getElementById('createModuleForm')?.addEventListener('submit', async function(e){
        e.preventDefault();
        const btn = this.querySelector('button[type="submit"]');
        if(btn) { btn.disabled = true; btn.innerText = "Creating..."; }
        
        const formData = new FormData(this);
        // Action is hidden field
        try {
            const res = await fetch('ajax_structure.php', { method: 'POST', body: formData });
            const data = await res.json();
            if(data.success) {
                location.reload();
            } else {
                alert("Error: " + (data.message || 'Unknown'));
                if(btn) { btn.disabled = false; btn.innerText = "Create Module"; }
            }
        } catch(e) { 
            console.error(e); 
            alert("Network Error"); 
            if(btn) { btn.disabled = false; btn.innerText = "Create Module"; }
        }
    });
    
    document.getElementById('createVisitForm')?.addEventListener('submit', async function(e){
        e.preventDefault();
        const btn = this.querySelector('button[type="submit"]');
        if(btn) { btn.disabled = true; btn.innerText = "Creating..."; }
        
        const formData = new FormData(this);
        if(!formData.get('action')) formData.append('action', 'create_visit'); // If not in HTML
        
        try {
            const res = await fetch('ajax_structure.php', { method: 'POST', body: formData });
            const data = await res.json();
            if(data.success) {
                location.reload();
            } else {
                alert("Error: " + (data.message || 'Unknown'));
                if(btn) { btn.disabled = false; btn.innerText = "Create Visit"; }
            }
        } catch(e) { 
            console.error(e); 
            alert("Network Error"); 
            if(btn) { btn.disabled = false; btn.innerText = "Create Visit"; }
        }
    });
    
    document.getElementById('createFormForm')?.addEventListener('submit', async function(e){
        e.preventDefault();
        const btn = this.querySelector('button[type="submit"]');
        if(btn) { btn.disabled = true; btn.innerText = "Creating..."; }
        
        const formData = new FormData(this);
        try {
            const res = await fetch('ajax_structure.php', { method: 'POST', body: formData });
            const data = await res.json();
            if(data.success) {
               // Redirect to new form
               const params = new URLSearchParams(window.location.search);
               if(formData.get('visit_id')) params.set('visit_id', formData.get('visit_id'));
               if(formData.get('repeating_module_id')) params.set('module_id', formData.get('repeating_module_id'));
               params.set('form_id', data.form_id);
               window.location.search = params.toString();
            } else {
                alert("Error: " + (data.message || 'Unknown'));
                if(btn) { btn.disabled = false; btn.innerText = "Create Form"; }
            }
        } catch(e) { 
            console.error(e); 
            alert("Network Error"); 
            if(btn) { btn.disabled = false; btn.innerText = "Create Form"; }
        }
    });
</script>
</body>
</html>
