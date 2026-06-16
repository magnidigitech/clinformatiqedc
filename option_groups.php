<?php
require_once 'includes/functions.php';
require_once 'includes/auth.php';

requireLogin();

// Ensure active study
if (!isset($_SESSION['active_study_id'])) {
    redirect('dashboard.php');
}

$study_id = $_SESSION['active_study_id'];
$pdo = getDB();

// Fetch existing groups
$stmt = $pdo->prepare("
    SELECT og.*, 
    (SELECT string_agg(label, ', ') FROM (SELECT label FROM option_choices oc WHERE oc.group_id = og.id ORDER BY order_index LIMIT 3) sub) as preview
    FROM option_groups og 
    ORDER BY name ASC
");
$stmt->execute();
$groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Option Groups - Study Design</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
    <style>
        /* Study Design Layout Styles */
        .design-layout { display: flex; height: calc(100vh - 64px); }
        .design-sidebar { width: 250px; background: #f8fafc; border-right: 1px solid var(--border-color); padding: 1rem 0; }
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
        
        /* Options Table */
        .opt-table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
        .opt-table th { text-align: left; padding: 0.75rem; border-bottom: 2px solid var(--border-color); font-size: 0.85rem; color: var(--text-light); }
        .opt-table td { padding: 1rem 0.75rem; border-bottom: 1px solid var(--border-color); font-size: 0.9rem; }
        .opt-table tr:hover { background: #fdfdfd; }
        
        .tag { background: #f1f5f9; padding: 2px 6px; border-radius: 4px; font-size: 0.8rem; border: 1px solid #e2e8f0; margin-right: 0.25rem; }
        
        /* Editor Modal */
        .choice-row { display: flex; gap: 0.5rem; margin-bottom: 0.5rem; align-items: center; }
        .choice-handle { cursor: move; color: var(--text-light); }
    </style>
</head>
<body>

<div class="app-layout" style="display: block;">
    <!-- Shared Top Nav (Simplified for Design Context) -->
    <header class="top-nav" style="height: 64px; border-bottom: 1px solid var(--border-color); padding: 0 1.5rem; display: flex; align-items: center; justify-content: space-between; background: white;">
        <div style="display: flex; align-items: center; gap: 1rem;">
             <a href="dashboard.php" style="text-decoration: none; font-weight: 600; font-size: 1.25rem; color: var(--primary-color);">Clinformatiq</a>
             <span style="color: var(--border-color);">|</span>
             <span style="font-weight: 500;"><?php echo htmlspecialchars($_SESSION['active_study_name']); ?> - Design</span>
        </div>
        <div>
             <a href="study.php" class="btn btn-outline">Exit Design</a>
        </div>
    </header>

    <div class="design-layout">
        <!-- New Sidebar -->
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
                <a href="form_builder.php" class="nav-item">Forms</a>
                <a href="option_groups.php" class="nav-item active">Option groups</a>
            </nav>
        </aside>

        <main class="design-content">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
                <h1 style="font-size: 1.5rem; margin: 0;">Option groups</h1>
                <button class="btn btn-primary" onclick="openCreateModal()">
                    <span class="material-icons-round">add</span> Add Group
                </button>
            </div>

            <table class="opt-table">
                <thead>
                    <tr>
                        <th style="width: 30%;">Name</th>
                        <th>Options Preview</th>
                        <th style="width: 50px;"></th>
                    </tr>
                </thead>
                <tbody id="groupListBody">
                    <?php if (empty($groups)): ?>
                        <tr><td colspan="3" style="text-align: center; color: var(--text-light); padding: 3rem;">No option groups defined. Add one to get started.</td></tr>
                    <?php else: ?>
                        <?php foreach($groups as $g): ?>
                            <tr>
                                <td style="font-weight: 500;"><?php echo htmlspecialchars($g['name']); ?></td>
                                <td>
                                    <?php 
                                        if ($g['preview']) {
                                            $opts = explode(', ', $g['preview']);
                                            foreach($opts as $o) echo "<span class='tag'>".htmlspecialchars($o)."</span>";
                                            echo "<span style='font-size: 0.8rem; color: var(--text-light);'>...</span>";
                                        } else {
                                            echo "<em style='color:var(--text-light)'>No options</em>";
                                        }
                                    ?>
                                </td>
                                <td>
                                    <div style="display: flex; gap: 0.5rem; justify-content: flex-end;">
                                        <button class="btn-icon" onclick="editGroup(<?php echo $g['id']; ?>, '<?php echo addslashes($g['name']); ?>')"><span class="material-icons-round">edit</span></button>
                                        <button class="btn-icon" onclick="deleteGroup(<?php echo $g['id']; ?>, '<?php echo addslashes($g['name']); ?>')"><span class="material-icons-round" style="color: #ef4444;">delete</span></button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </main>
    </div>
</div>

<!-- Create/Edit Modal -->
<div class="modal-overlay" id="optModal">
    <div class="modal-content" style="width: 600px; max-width: 90vw;">
        <div class="modal-header">
            <h3 id="modalTitle">Edit Option Group</h3>
            <button class="btn-icon" onclick="closeModal()"><span class="material-icons-round">close</span></button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="editGroupId">
            
            <div class="form-group">
                <label>Group Name</label>
                <input type="text" id="groupNameInput" class="form-input" placeholder="e.g. Yes/No, Countries">
            </div>
            
            <div id="choicesSection" style="display:none; margin-top: 1.5rem; border-top: 1px solid var(--border-color); padding-top: 1rem;">
                <label style="display:block; margin-bottom: 0.5rem; font-weight: 500;">Choices</label>
                <div style="display: grid; grid-template-columns: 1fr 1fr 40px; gap: 0.5rem; margin-bottom: 0.5rem; font-size: 0.75rem; color: var(--text-light);">
                    <span>Label (User reads this)</span>
                    <span>Value (Stored in DB)</span>
                    <span></span>
                </div>
                
                <div id="choicesContainer">
                    <!-- Rows injected via JS -->
                </div>
                
                <button class="btn btn-outline" style="width: 100%; margin-top: 0.5rem; border-style: dashed;" onclick="addChoiceRow()">
                    <span class="material-icons-round" style="font-size: 1rem;">add</span> Add Choice
                </button>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal()">Cancel</button>
            <button class="btn btn-primary" onclick="saveGroup()" id="saveBtn">Create Group</button>
        </div>
    </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal-overlay" id="deleteGroupModal" style="z-index: 20002;">
    <div class="modal-content" style="max-width: 450px;">
        <div class="modal-header" style="border-bottom: none; padding-bottom: 0;">
            <h3 style="color: #ef4444; display: flex; align-items: center; gap: 0.5rem;">
                <span class="material-icons-round">warning</span> Delete Option Group
            </h3>
            <button class="btn-icon" onclick="closeDeleteModal()"><span class="material-icons-round">close</span></button>
        </div>
        <div class="modal-body">
            <p style="margin-bottom: 1rem; color: var(--text-dark);">
                Are you sure you want to delete the group <strong><span id="delTargetName"></span></strong>?
            </p>
            <p style="margin-bottom: 1rem; font-size: 0.9rem; color: var(--text-light);">
                This action cannot be undone. To confirm, please type the group name below:
            </p>
            
            <input type="hidden" id="delGroupId">
            <input type="hidden" id="delGroupNameRaw">
            
            <input type="text" id="delNameInput" class="form-input" placeholder="Type group name here" oninput="checkDeleteInput()">
            
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeDeleteModal()">Cancel</button>
            <button class="btn btn-primary" id="finalDeleteBtn" onclick="confirmDeleteGroup()" disabled style="background: #ef4444; border-color: #ef4444; opacity: 0.5; cursor: not-allowed;">Delete</button>
        </div>
    </div>
</div>

<script>
const modal = document.getElementById('optModal');

function openCreateModal() {
    document.getElementById('modalTitle').innerText = "Create Option Group";
    document.getElementById('editGroupId').value = "";
    document.getElementById('groupNameInput').value = "";
    document.getElementById('choicesSection').style.display = 'block'; // Show choices immediately for CREATE too (Unified)
    document.getElementById('choicesContainer').innerHTML = '';
    addChoiceRow(); // Add empty rows
    addChoiceRow();
    document.getElementById('saveBtn').innerText = "Save Group";
    modal.classList.add('active');
}

function closeModal() {
    modal.classList.remove('active');
}

async function saveGroup() {
    const id = document.getElementById('editGroupId').value;
    const name = document.getElementById('groupNameInput').value;
    
    if (!name) return alert("Please enter a name");
    
    // Collect Choices
    const rows = document.querySelectorAll('.choice-row');
    const choices = [];
    rows.forEach(r => {
        const label = r.querySelector('.choice-label').value.trim();
        const value = r.querySelector('.choice-value').value.trim();
        if(label) {
            choices.push({ label: label, value: value });
        }
    });

    if (choices.length === 0) {
        if(!confirm("No choices added. Continue?")) return;
    }

    const formData = new FormData();
    formData.append('action', 'save_group_full'); // Unified Action
    formData.append('id', id); // Empty if new
    formData.append('name', name);
    formData.append('choices', JSON.stringify(choices));
    
    try {
        const res = await fetch('ajax_options.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            location.reload();
        } else {
            alert(data.message || "Error saving group");
        }
    } catch (e) { 
        console.error(e);
        alert("Network Error");
    }
}

async function editGroup(id, name) {
    document.getElementById('modalTitle').innerText = "Edit " + name;
    document.getElementById('editGroupId').value = id;
    document.getElementById('groupNameInput').value = name;
    document.getElementById('saveBtn').innerText = "Save Changes";
    document.getElementById('choicesSection').style.display = 'block';
    
    const container = document.getElementById('choicesContainer');
    container.innerHTML = 'Loading...';
    modal.classList.add('active');
    
    // Fetch choices
    const formData = new FormData();
    formData.append('action', 'get_group');
    formData.append('group_id', id);
    
    const res = await fetch('ajax_options.php', { method: 'POST', body: formData });
    const data = await res.json();
    
    container.innerHTML = '';
    if (data.success && data.choices) {
        data.choices.forEach(c => addChoiceRow(c.label, c.value));
    }
    // Add one empty row if none
    if (container.children.length === 0) addChoiceRow();
}

function addChoiceRow(label='', value='') {
    const div = document.createElement('div');
    div.className = 'choice-row';
    div.innerHTML = `
        <input type="text" class="form-input choice-label" placeholder="Label" value="${label.replace(/"/g, '&quot;')}" style="flex:1;">
        <input type="text" class="form-input choice-value" placeholder="Value" value="${value.replace(/"/g, '&quot;')}" style="flex:1;">
        <button class="btn-icon" onclick="this.parentElement.remove()" tabindex="-1"><span class="material-icons-round">close</span></button>
    `;
    document.getElementById('choicesContainer').appendChild(div);
}

const deleteModal = document.getElementById('deleteGroupModal');

function deleteGroup(id, name) {
    document.getElementById('delTargetName').innerText = name;
    document.getElementById('delGroupId').value = id;
    document.getElementById('delGroupNameRaw').value = name; // Hidden truth
    document.getElementById('delNameInput').value = '';
    
    // Reset Button
    const btn = document.getElementById('finalDeleteBtn');
    btn.disabled = true;
    btn.style.opacity = '0.5';
    btn.style.cursor = 'not-allowed';
    
    deleteModal.classList.add('active');
    setTimeout(() => document.getElementById('delNameInput').focus(), 100);
}

function closeDeleteModal() {
    deleteModal.classList.remove('active');
}

function checkDeleteInput() {
    const input = document.getElementById('delNameInput').value;
    const correct = document.getElementById('delGroupNameRaw').value;
    const btn = document.getElementById('finalDeleteBtn');
    
    if (input === correct) {
        btn.disabled = false;
        btn.style.opacity = '1';
        btn.style.cursor = 'pointer';
    } else {
        btn.disabled = true;
        btn.style.opacity = '0.5';
        btn.style.cursor = 'not-allowed';
    }
}

async function confirmDeleteGroup() {
    const id = document.getElementById('delGroupId').value;
    const btn = document.getElementById('finalDeleteBtn');
    
    // Double check (though button is disabled)
    if (document.getElementById('delNameInput').value !== document.getElementById('delGroupNameRaw').value) return;
    
    btn.innerText = "Deleting...";
    
    const formData = new FormData();
    formData.append('action', 'delete_group');
    formData.append('group_id', id);
    
    try {
        await fetch('ajax_options.php', { method: 'POST', body: formData });
        location.reload();
    } catch(e) {
        alert("Network Error");
        btn.innerText = "Delete";
    }
}

</script>
</body>
</html>
