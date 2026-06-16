<?php
require_once 'includes/functions.php';
require_once 'includes/auth.php';

requireLogin();

if (!isset($_SESSION['active_study_id'])) {
    redirect('dashboard.php');
}

$study_id = $_SESSION['active_study_id'];
$pdo = getDB();

// Fetch Visits and Forms hierarchy
$stmt = $pdo->prepare("
    SELECT v.id as visit_id, v.name as visit_name, v.order_index as visit_order,
           f.id as form_id, f.name as form_name, f.order_index as form_order
    FROM study_visits v
    LEFT JOIN study_forms f ON v.id = f.visit_id
    WHERE v.study_id = ?
    ORDER BY v.order_index, f.order_index
");
$stmt->execute([$study_id]);
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Structure data for easier looping
$structure = [];
foreach ($data as $row) {
    if (!$row['visit_id']) continue;
    
    if (!isset($structure[$row['visit_id']])) {
        $structure[$row['visit_id']] = [
            'name' => $row['visit_name'],
            'forms' => []
        ];
    }
    
    if ($row['form_id']) {
        $structure[$row['visit_id']]['forms'][] = [
            'id' => $row['form_id'],
            'name' => $row['form_name']
        ];
    }
}

// Fetch Repeating Modules
$rep_stmt = $pdo->prepare("
    SELECT m.id as module_id, m.name as module_name, m.order_index as module_order,
           f.id as form_id, f.name as form_name, f.order_index as form_order
    FROM study_repeating_modules m
    LEFT JOIN study_forms f ON m.id = f.repeating_module_id
    WHERE m.study_id = ?
    ORDER BY m.order_index, f.order_index
");
$rep_stmt->execute([$study_id]);
$rep_data = $rep_stmt->fetchAll(PDO::FETCH_ASSOC);

$repeating_structure = [];
foreach ($rep_data as $row) {
    if (!$row['module_id']) continue;
    
    if (!isset($repeating_structure[$row['module_id']])) {
        $repeating_structure[$row['module_id']] = [
            'name' => $row['module_name'],
            'forms' => []
        ];
    }
    if ($row['form_id']) {
        $repeating_structure[$row['module_id']]['forms'][] = [
            'id' => $row['form_id'],
            'name' => $row['form_name']
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Structure - <?php echo htmlspecialchars($_SESSION['active_study_name']); ?></title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.14.0/Sortable.min.js"></script>
    <style>
        .design-layout { display: flex; height: calc(100vh - 64px); }
        .design-sidebar { width: 250px; background: #f8fafc; border-right: 1px solid var(--border-color); padding: 1rem 0; flex-shrink: 0; }
        .design-content { flex: 1; padding: 2rem; overflow-y: auto; background: white; }
        
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
        
        /* Accordion Styles */
        .visit-item { border-bottom: 1px solid var(--border-color); }
        .visit-header { 
            padding: 1rem; 
            display: flex; 
            align-items: center; 
            cursor: pointer; 
            background: white;
            transition: background 0.2s;
        }
        .visit-header:hover { background: #f8fafc; }
        .visit-header i { margin-right: 0.75rem; color: var(--text-light); transition: transform 0.2s; }
        .visit-header.collapsed i { transform: rotate(-90deg); }
        .visit-title { font-weight: 500; flex: 1; }
        
        .form-list { padding-left: 3rem; display: block; }
        .form-list.hidden { display: none; }
        
        .form-item { 
            padding: 0.75rem 1rem; 
            border-bottom: 1px solid var(--border-color); 
            display: flex; 
            align-items: center;
            color: var(--text-main);
            text-decoration: none;
        }
        .form-item:last-child { border-bottom: none; }
        .form-item:hover { background: #eff6ff; }
        .form-order { width: 30px; color: var(--text-light); font-size: 0.8rem; text-align: center; margin-right: 0.5rem; }
        
        .structure-tabs { display: flex; gap: 2rem; border-bottom: 1px solid var(--border-color); margin-bottom: 1.5rem; }
        .st-tab { padding-bottom: 1rem; font-weight: 500; color: var(--text-light); cursor: pointer; border-bottom: 2px solid transparent; }
        .st-tab.active { color: var(--primary-color); border-bottom-color: var(--primary-color); }
        .st-tab:hover { color: var(--primary-color); }
    </style>
</head>
<body>

<div class="app-layout" style="display: block;">
    
    <header class="top-nav" style="height: 64px; border-bottom: 1px solid var(--border-color); padding: 0 1.5rem; display: flex; align-items: center; justify-content: space-between; background: white; position: relative; z-index: 20;">
        <div style="display: flex; align-items: center; gap: 1rem;">
             <a href="dashboard.php" style="text-decoration: none; font-weight: 600; font-size: 1.25rem; color: var(--primary-color);">Clinformatiq</a>
             <span style="color: var(--border-color);">|</span>
             <span style="font-weight: 500; display: flex; align-items: center; gap: 0.5rem;">
                <?php echo htmlspecialchars($_SESSION['active_study_code']); ?> - Design
                <?php renderRoleSwitcher($study_id); ?>
             </span>
        </div>
        <div>
             <a href="study.php" class="btn btn-outline">Exit Design</a>
        </div>
    </header>

    <div class="design-layout">
        <aside class="design-sidebar">
            <div class="nav-group-label">
                <span style="display: flex; align-items: center; gap: 0.5rem;">
                    <span class="material-icons-round" style="font-size: 1.25rem;">account_tree</span>
                    Study design
                </span>
                <span class="material-icons-round" style="font-size: 1rem;">expand_less</span>
            </div>
            <nav>
                <a href="study_structure.php" class="nav-item active">Structure</a>
                <a href="form_builder.php" class="nav-item">Forms</a>
                <a href="option_groups.php" class="nav-item">Option groups</a>
            </nav>
        </aside>

        <main class="design-content">
            
            <div class="structure-tabs">
                <div class="st-tab active" onclick="switchTab('visits')">Visits</div>
                <div class="st-tab" onclick="switchTab('repeating')">Repeating data</div>
            </div>

            <!-- Visits Tab Content -->
            <div id="tab-visits" class="tab-content">
                <div style="display: flex; justify-content: flex-end; gap: 0.5rem; margin-bottom: 1rem;">
                     <button class="btn btn-outline" onclick="toggleAll(false)">Collapse all</button>
                     <button class="btn btn-outline" onclick="toggleAll(true)">Expand all</button>
                     <button class="btn btn-primary" onclick="openVisitModal()">Add Visit</button>
                </div>

                <div class="card" style="padding: 0; overflow: hidden;">
                    <?php if (empty($structure)): ?>
                        <div style="padding: 3rem; text-align: center; color: var(--text-light);">
                            No visits defined. Click "Add Visit" to start.
                        </div>
                    <?php else: ?>
                        <div id="visit-list">
                        <?php foreach ($structure as $vid => $visit): ?>
                        <div class="visit-item" data-id="<?php echo $vid; ?>">
                            <div class="visit-header expanded">
                                <i class="material-icons-round drag-handle" style="cursor: move; margin-right: 0.5rem; color: #cbd5e1;">drag_indicator</i>
                                <i class="material-icons-round toggle-icon" onclick="toggleVisit(this.parentElement)">expand_more</i>
                                <span class="visit-title" onclick="toggleVisit(this.parentElement)"><?php echo htmlspecialchars($visit['name']); ?></span>
                                
                                <div class="actions" style="display: flex; gap: 0.25rem; align-items: center;">
                                    <button class="btn-icon primary" onclick="event.stopPropagation(); addFormTo(<?php echo $vid; ?>, '<?php echo addslashes($visit['name']); ?>', 'visit')" title="Add Form"><span class="material-icons-round">add_circle_outline</span></button>
                                    <button class="btn-icon delete" onclick="event.stopPropagation(); confirmDelete('visit', <?php echo $vid; ?>)" title="Delete Visit"><span class="material-icons-round">delete_outline</span></button>
                                </div>
                            </div>
                            <div class="form-list" id="forms-visit-<?php echo $vid; ?>" data-visit-id="<?php echo $vid; ?>">
                                <?php if (!empty($visit['forms'])): ?>
                                    <?php foreach ($visit['forms'] as $idx => $form): ?>
                                        <div class="form-item" data-id="<?php echo $form['id']; ?>">
                                            <div class="form-drag-handle" style="cursor: move; color: #cbd5e1; margin-right: 0.5rem;"><span class="material-icons-round" style="font-size: 1rem;">drag_indicator</span></div>
                                            
                                            <a href="form_builder.php?visit_id=<?php echo $vid; ?>&form_id=<?php echo $form['id']; ?>" style="flex: 1; text-decoration: none; color: inherit; display: flex; align-items: center;">
                                                <div style="flex: 1;"><?php echo htmlspecialchars($form['name']); ?></div>
                                                <span class="material-icons-round" style="font-size: 1rem; color: var(--text-light); margin-right: 0.5rem;">chevron_right</span>
                                            </a>
                                            
                                            <button class="btn-icon delete" onclick="event.stopPropagation(); confirmDelete('form', <?php echo $form['id']; ?>)" title="Delete Form"><span class="material-icons-round">delete_outline</span></button>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Repeating Data Tab Content -->
            <div id="tab-repeating" class="tab-content" style="display: none;">
                <div style="display: flex; justify-content: flex-end; gap: 0.5rem; margin-bottom: 1rem;">
                     <button class="btn btn-outline" onclick="toggleAllGeneric('module', false)">Collapse all</button>
                     <button class="btn btn-outline" onclick="toggleAllGeneric('module', true)">Expand all</button>
                     <button class="btn btn-primary" onclick="openModuleModal()">Add Module</button>
                </div>

                <div class="card" style="padding: 0; overflow: hidden;">
                    <?php if (empty($repeating_structure)): ?>
                        <div style="padding: 3rem; text-align: center; color: var(--text-light);">
                            No repeating data modules defined. e.g. "Adverse Events", "Concomitant Medications".
                        </div>
                    <?php else: ?>
                        <div id="module-list">
                        <?php foreach ($repeating_structure as $mid => $module): ?>
                        <div class="visit-item module-item" data-id="<?php echo $mid; ?>">
                            <div class="visit-header expanded">
                                <i class="material-icons-round drag-handle-mod" style="cursor: move; margin-right: 0.5rem; color: #cbd5e1;">drag_indicator</i>
                                <i class="material-icons-round toggle-icon" onclick="toggleVisit(this.parentElement)">expand_more</i>
                                <span class="visit-title" onclick="toggleVisit(this.parentElement)"><?php echo htmlspecialchars($module['name']); ?></span>
                                <span style="font-size: 0.7rem; background: #e0e7ff; color: #3730a3; padding: 2px 6px; border-radius: 4px; margin-right: 0.5rem;">Repeating</span>
                                
                                <div class="actions" style="display: flex; gap: 0.25rem; align-items: center;">
                                    <button class="btn-icon primary" onclick="event.stopPropagation(); addFormTo(<?php echo $mid; ?>, '<?php echo addslashes($module['name']); ?>', 'module')" title="Add Form"><span class="material-icons-round">add_circle_outline</span></button>
                                    <button class="btn-icon delete" onclick="event.stopPropagation(); confirmDelete('module', <?php echo $mid; ?>)" title="Delete Module"><span class="material-icons-round">delete_outline</span></button>
                                </div>
                            </div>
                            <div class="form-list" id="forms-module-<?php echo $mid; ?>" data-module-id="<?php echo $mid; ?>">
                                <?php if (!empty($module['forms'])): ?>
                                    <?php foreach ($module['forms'] as $idx => $form): ?>
                                        <div class="form-item" data-id="<?php echo $form['id']; ?>">
                                            <div class="form-drag-handle-mod" style="cursor: move; color: #cbd5e1; margin-right: 0.5rem;"><span class="material-icons-round" style="font-size: 1rem;">drag_indicator</span></div>
                                            
                                            <a href="form_builder.php?module_id=<?php echo $mid; ?>&form_id=<?php echo $form['id']; ?>" style="flex: 1; text-decoration: none; color: inherit; display: flex; align-items: center;">
                                                <div style="flex: 1;"><?php echo htmlspecialchars($form['name']); ?></div>
                                                <span class="material-icons-round" style="font-size: 1rem; color: var(--text-light); margin-right: 0.5rem;">chevron_right</span>
                                            </a>
                                            
                                            <button class="btn-icon delete" onclick="event.stopPropagation(); confirmDelete('form', <?php echo $form['id']; ?>)" title="Delete Form"><span class="material-icons-round">delete_outline</span></button>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </main>
    </div>
</div>

<!-- Module Modal -->
<div id="moduleModal" class="modal-overlay">
    <div class="modal-content" style="width: 400px;">
        <div class="modal-header">
            <h3>Add Repeating Module</h3>
            <button class="btn-icon" onclick="document.getElementById('moduleModal').classList.remove('active')"><span class="material-icons-round">close</span></button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label>Module Name</label>
                <input type="text" id="moduleName" class="form-input" placeholder="e.g. Adverse Events">
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="document.getElementById('moduleModal').classList.remove('active')">Cancel</button>
            <button class="btn btn-primary" onclick="createModule()">Create</button>
        </div>
    </div>
</div>

<!-- Visit Modal -->
<div id="visitModal" class="modal-overlay">
    <div class="modal-content" style="width: 400px;">
        <div class="modal-header">
            <h3>Add New Visit</h3>
            <button class="btn-icon" onclick="document.getElementById('visitModal').classList.remove('active')"><span class="material-icons-round">close</span></button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label>Visit Name</label>
                <input type="text" id="visitName" class="form-input" placeholder="e.g. Screening">
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="document.getElementById('visitModal').classList.remove('active')">Cancel</button>
            <button class="btn btn-primary" onclick="createVisit()">Create</button>
        </div>
    </div>
</div>

<!-- Form Modal -->
<div id="formModal" class="modal-overlay">
    <div class="modal-content" style="width: 400px;">
        <div class="modal-header">
            <h3>Add Form to <span id="targetName"></span></h3>
            <button class="btn-icon" onclick="document.getElementById('formModal').classList.remove('active')"><span class="material-icons-round">close</span></button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="targetId">
            <input type="hidden" id="targetType">
            <div class="form-group">
                <label>Form Name</label>
                <input type="text" id="formName" class="form-input" placeholder="e.g. Demographics">
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="document.getElementById('formModal').classList.remove('active')">Cancel</button>
            <button class="btn btn-primary" onclick="createForm()">Create</button>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" class="modal-overlay" style="z-index: 20000; display: none;">
    <div class="modal-content" style="width: 450px; text-align: center;">
        <div class="modal-header" style="border-bottom: none; padding-bottom: 0;">
             <h3 style="color: #ef4444; display: flex; align-items: center; justify-content: center; gap: 0.5rem; width: 100%;">
                <span class="material-icons-round">warning</span>
                <span id="deleteTitle">Delete Item?</span>
            </h3>
            <button class="btn-icon" onclick="closeDeleteModal()" style="position: absolute; right: 1rem; top: 1rem;"><span class="material-icons-round">close</span></button>
        </div>
        <div class="modal-body">
            <p id="deleteMessage" style="color: var(--text-main); margin-bottom: 1rem;">Are you sure?</p>
            
            <div id="deleteInputWrapper" style="display:none; margin-bottom: 1.5rem; text-align: left; background: #fef2f2; padding: 1rem; border-radius: 8px; border: 1px solid #fecaca;">
                <p style="font-size: 0.9rem; color: #b91c1c; margin-bottom: 0.5rem;">To confirm, type: <strong id="deleteMatchText" style="user-select: all;"></strong></p>
                <input type="text" id="deleteInput" class="form-input" placeholder="Type name here" autocomplete="off" style="border-color: #fca5a5;">
            </div>

            <input type="hidden" id="deleteType">
            <input type="hidden" id="deleteId">
            <input type="hidden" id="deleteNameRaw">
        </div>
        <div class="modal-footer" style="justify-content: center; background: transparent; border-top: none; padding-top: 0;">
            <button class="btn btn-outline" onclick="closeDeleteModal()">Cancel</button>
            <button class="btn btn-primary" id="finalDeleteBtn" onclick="executeDelete()" disabled style="background-color: #ef4444; border-color: #ef4444; opacity: 0.5; cursor: not-allowed;">Delete</button>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    initSortables();
});

function switchTab(tab) {
    document.querySelectorAll('.st-tab').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(el => el.style.display = 'none');
    
    if (tab === 'visits') {
        document.querySelector('.st-tab:nth-child(1)').classList.add('active');
        document.getElementById('tab-visits').style.display = 'block';
    } else {
        document.querySelector('.st-tab:nth-child(2)').classList.add('active');
        document.getElementById('tab-repeating').style.display = 'block';
    }
}

function initSortables() {
    // 1. Sort Visits
    const visitList = document.getElementById('visit-list');
    if (visitList) {
        new Sortable(visitList, {
            animation: 150,
            handle: '.drag-handle',
            onEnd: saveOrder
        });
    }

    // Sort Modules
    const moduleList = document.getElementById('module-list');
    if (moduleList) {
         new Sortable(moduleList, {
            animation: 150,
            handle: '.drag-handle-mod',
            onEnd: saveModuleOrder
        });
    }

    // 2. Sort Forms (Nested)
    const formLists = document.querySelectorAll('.form-list');
    formLists.forEach(list => {
        new Sortable(list, {
            group: 'forms', // Allow dragging between visits/modules? Maybe restrict.
            // For now, allow generic drag, but backend needs to handle visit_id vs module_id change.
            animation: 150,
            handle: list.dataset.moduleId ? '.form-drag-handle-mod' : '.form-drag-handle',
            onEnd: function(evt) {
                // If dragged between visit/module, we might need special handling.
                // For simplicity, let's assume saveOrder handles visits and saveModuleOrder handles modules.
                // We'll call BOTH just in case? Or detect context.
                if (evt.to.dataset.visitId) saveOrder();
                if (evt.to.dataset.moduleId) saveModuleOrder();
            }
        });
    });
}


function saveOrder() {
    const visits = [];
    const visitEls = document.querySelectorAll('#visit-list .visit-item');
    
    visitEls.forEach((vEl, vIdx) => {
        const visitId = vEl.dataset.id;
        const formList = vEl.querySelector('.form-list');
        const formEls = formList.querySelectorAll('.form-item');
        const forms = [];
        
        formEls.forEach((fEl, fIdx) => {
            forms.push(fEl.dataset.id);
        });
        
        visits.push({
            id: visitId,
            forms: forms
        });
    });

    const formData = new FormData();
    formData.append('action', 'save_structure_order');
    formData.append('order_data', JSON.stringify(visits)); // "visits" implied
    
    fetch('ajax_structure.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .catch(e => console.error("Sort Error", e));
}

function saveModuleOrder() {
    const modules = [];
    const modEls = document.querySelectorAll('#module-list .module-item');
    
    modEls.forEach((mEl, mIdx) => {
        const modId = mEl.dataset.id;
        const formList = mEl.querySelector('.form-list');
        const formEls = formList.querySelectorAll('.form-item');
        const forms = [];
        
        formEls.forEach((fEl, fIdx) => {
            forms.push(fEl.dataset.id);
        });
        
        modules.push({
            id: modId,
            forms: forms
        });
    });

    const formData = new FormData();
    formData.append('action', 'save_module_order');
    formData.append('order_data', JSON.stringify(modules));
    
    fetch('ajax_structure.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .catch(e => console.error("Sort Module Error", e));
}


function confirmDelete(type, id) {
    // Find name
    let name = '';
    if (type === 'visit') {
        const el = document.querySelector(`.visit-item[data-id="${id}"] .visit-title`);
        if (el) name = el.innerText.trim();
    } else if (type === 'module') {
        const el = document.querySelector(`.module-item[data-id="${id}"] .visit-title`);
        if (el) name = el.innerText.trim();
    } else {
        const el = document.querySelector(`.form-item[data-id="${id}"] div[style*="flex: 1"]`);
        if (el) name = el.innerText.trim();
    }

    document.getElementById('deleteType').value = type;
    document.getElementById('deleteId').value = id;
    document.getElementById('deleteNameRaw').value = name;
    
    let title = 'Delete Item?';
    let msg = 'Are you sure?';

    if (type === 'visit') {
        title = 'Delete Visit?';
        msg = `Are you sure you want to delete the visit <strong>${name}</strong>? This will delete ALL FORMS inside it.`;
    } else if (type === 'module') {
        title = 'Delete Module?';
        msg = `Are you sure you want to delete the repeating module <strong>${name}</strong>? This will delete ALL FORMS inside it.`;
    } else {
        title = 'Delete Form?';
        msg = `Are you sure you want to delete the form <strong>${name}</strong>? This will delete all fields inside it.`;
    }
        
    document.getElementById('deleteTitle').innerText = title;
    document.getElementById('deleteMessage').innerHTML = msg;
    
    // Setup Input
    const inputWrapper = document.getElementById('deleteInputWrapper');
    const inputFn = document.getElementById('deleteInput');
    const matchSpan = document.getElementById('deleteMatchText');
    const btn = document.getElementById('finalDeleteBtn');
    
    inputWrapper.style.display = 'block';
    matchSpan.innerText = name;
    inputFn.value = '';
    
    btn.disabled = true;
    btn.style.opacity = '0.5';
    btn.style.cursor = 'not-allowed';
    
    inputFn.oninput = function() {
        if (this.value === name) {
            btn.disabled = false;
            btn.style.opacity = '1';
            btn.style.cursor = 'pointer';
        } else {
            btn.disabled = true;
            btn.style.opacity = '0.5';
            btn.style.cursor = 'not-allowed';
        }
    };

    const modal = document.getElementById('deleteModal');
    modal.style.display = 'flex';
    requestAnimationFrame(() => {
        modal.classList.add('active');
        inputFn.focus();
    });
}

function closeDeleteModal() {
    const modal = document.getElementById('deleteModal');
    modal.classList.remove('active');
    setTimeout(() => modal.style.display = 'none', 200);
}

function executeDelete() {
    const type = document.getElementById('deleteType').value;
    const id = document.getElementById('deleteId').value;
    const nameRaw = document.getElementById('deleteNameRaw').value;
    const inputVal = document.getElementById('deleteInput').value;
    const btn = document.getElementById('finalDeleteBtn');
    
    if (inputVal !== nameRaw) return;

    btn.disabled = true;
    btn.innerText = "Deleting...";
    
    let action = 'delete_form';
    if(type === 'visit') action = 'delete_visit';
    if(type === 'module') action = 'delete_module';

    const formData = new FormData();
    formData.append('action', action);
    formData.append(type === 'visit' ? 'visit_id' : (type === 'module' ? 'module_id' : 'form_id'), id);
    
    fetch('ajax_structure.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if(data.success) {
                location.reload();
            } else {
                alert("Error: " + (data.message || 'Unknown error'));
                btn.disabled = false;
                btn.innerText = "Delete";
            }
        })
        .catch(e => {
            console.error(e);
            alert("Network Error");
            btn.disabled = false;
            btn.innerText = "Delete";
        });
}


function toggleVisit(header) {
    if(header.classList.contains('visit-header')) {
        // passed the header div directly
    } else {
         header = header.querySelector('.visit-header');
    }
    
    const icon = header.querySelector('.toggle-icon');
    if(icon) {
        if (header.classList.contains('collapsed')) {
             icon.innerText = 'expand_more';
             header.classList.remove('collapsed');
             header.nextElementSibling.classList.remove('hidden');
        } else {
             icon.innerText = 'chevron_right';
             header.classList.add('collapsed');
             header.nextElementSibling.classList.add('hidden');
        }
    }
}

function toggleAll(expand) {
    const items = document.querySelectorAll('#visit-list .visit-item');
    items.forEach(item => {
        const header = item.querySelector('.visit-header');
        const list = item.querySelector('.form-list');
        const icon = header.querySelector('.toggle-icon');
        
        if(expand) {
            header.classList.remove('collapsed');
            list.classList.remove('hidden');
            if(icon) icon.innerText = 'expand_more';
        } else {
            header.classList.add('collapsed');
            list.classList.add('hidden');
            if(icon) icon.innerText = 'chevron_right';
        }
    });
}

function toggleAllGeneric(type, expand) {
    const selector = type === 'module' ? '#module-list .module-item' : '#visit-list .visit-item';
    const items = document.querySelectorAll(selector);
    items.forEach(item => {
        const header = item.querySelector('.visit-header');
        const list = item.querySelector('.form-list');
        const icon = header.querySelector('.toggle-icon');
        
        if(expand) {
            header.classList.remove('collapsed');
            list.classList.remove('hidden');
            if(icon) icon.innerText = 'expand_more';
        } else {
            header.classList.add('collapsed');
            list.classList.add('hidden');
            if(icon) icon.innerText = 'chevron_right';
        }
    });
}

function openVisitModal() {
    document.getElementById('visitName').value = '';
    document.getElementById('visitModal').classList.add('active');
}

function openModuleModal() {
    document.getElementById('moduleName').value = '';
    document.getElementById('moduleModal').classList.add('active');
}

function addFormTo(id, name, type) {
    document.getElementById('targetId').value = id;
    document.getElementById('targetType').value = type; // visit or module
    document.getElementById('targetName').innerText = name;
    document.getElementById('formName').value = '';
    document.getElementById('formModal').classList.add('active');
}

async function createVisit() {
    const name = document.getElementById('visitName').value;
    if(!name) { alert("Please enter a name"); return; }
    
    document.querySelector('#visitModal .btn-primary').disabled = true;
    document.querySelector('#visitModal .btn-primary').innerText = "Creating...";

    const formData = new FormData();
    formData.append('action', 'create_visit');
    formData.append('name', name);
    formData.append('study_id', <?php echo $study_id; ?>);
    
    try {
        const res = await fetch('ajax_structure.php', { method: 'POST', body: formData });
        const data = await res.json();
            if(data.success) {
                location.reload();
            } else {
                alert('Error creating visit: ' + (data.message || 'Unknown error'));
                document.querySelector('#visitModal .btn-primary').disabled = false;
                document.querySelector('#visitModal .btn-primary').innerText = "Create";
            }
    } catch(e) { 
        console.error("Fetch Error:", e);
        alert("Network error");
        document.querySelector('#visitModal .btn-primary').disabled = false;
        document.querySelector('#visitModal .btn-primary').innerText = "Create";
    }
}

async function createModule() {
    const name = document.getElementById('moduleName').value;
    if(!name) { alert("Please enter a name"); return; }
    
    const btn = document.querySelector('#moduleModal .btn-primary');
    btn.disabled = true;
    btn.innerText = "Creating...";

    const formData = new FormData();
    formData.append('action', 'create_module');
    formData.append('name', name);
    // study_id is in session/backend usually but passing it is fine too if needed, but ajax uses session.
    
    try {
        const res = await fetch('ajax_structure.php', { method: 'POST', body: formData });
        const data = await res.json();
        if(data.success) {
            location.reload();
        } else {
            alert('Error creating module: ' + (data.message || 'Unknown error'));
            btn.disabled = false;
            btn.innerText = "Create";
        }
    } catch(e) { 
        console.error("Fetch Error:", e);
        alert("Network error");
        btn.disabled = false;
        btn.innerText = "Create";
    }
}

async function createForm() {
    const name = document.getElementById('formName').value;
    const targetId = document.getElementById('targetId').value;
    const targetType = document.getElementById('targetType').value;
    if(!name) return;
    
    // Disable button to prevent double-click
    const btn = document.querySelector('#formModal .btn-primary');
    btn.disabled = true;
    btn.innerText = "Creating...";
    
    const formData = new FormData();
    formData.append('action', 'create_form');
    formData.append('name', name);
    
    if (targetType === 'visit') {
        formData.append('visit_id', targetId);
    } else {
        formData.append('repeating_module_id', targetId);
    }
    
    try {
        const res = await fetch('ajax_structure.php', { method: 'POST', body: formData });
        const data = await res.json();
        if(data.success && data.form_id) {
            // Redirect to Form Builder immediately
            if(targetType === 'visit') {
                window.location.href = `form_builder.php?visit_id=${targetId}&form_id=${data.form_id}`;
            } else {
                window.location.href = `form_builder.php?module_id=${targetId}&form_id=${data.form_id}`;
            }
        } else {
            alert('Error creating form: ' + (data.message || 'Unknown error'));
            btn.disabled = false;
            btn.innerText = "Create";
        }
    } catch(e) { 
        console.error(e);
        alert('Network or Server Error');
        btn.disabled = false;
        btn.innerText = "Create";
    }
}
</script>

</body>
</html>
