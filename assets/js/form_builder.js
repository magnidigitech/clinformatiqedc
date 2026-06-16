/**
 * Form Builder Logic - Professional Edition (v3.0)
 * Fixes: Data Loss on Edit, Footer Sorting, Toast Notifications
 */

document.addEventListener('DOMContentLoaded', function () {

    // Initialize Sidebar Draggables
    const sidebarSources = document.querySelectorAll('.field-toolbox');
    sidebarSources.forEach(source => {
        new Sortable(source, {
            group: {
                name: 'shared',
                pull: 'clone',
                put: false
            },
            sort: false,
            animation: 150,
            ghostClass: 'sortable-ghost',
            fallbackOnBody: true, // Critical for dragging out of sidebar
            swapThreshold: 0.65,
            onStart: function (evt) {
                document.body.classList.add('dragging-active');
            },
            onEnd: function (evt) {
                document.body.classList.remove('dragging-active');
            }
        });
    });

    // Initialize Canvas
    const canvas = document.getElementById('form-canvas');
    if (canvas) {
        new Sortable(canvas, {
            group: 'shared',
            animation: 150,
            ghostClass: 'sortable-ghost',
            handle: '.field-card-handle',
            filter: '.drop-zone-footer', // Ignore the footer
            onAdd: function (evt) {
                const itemEl = evt.item;

                // If the user dropped it AFTER the footer, move it before
                const footer = canvas.querySelector('.drop-zone-footer');
                if (footer && (itemEl.nextElementSibling === null || itemEl.nextElementSibling === footer.nextElementSibling)) {
                    canvas.insertBefore(itemEl, footer);
                }

                const type = itemEl.getAttribute('data-type');
                const label = itemEl.innerText.trim();

                // Create minimal field data for saving
                const randId = Math.floor(Math.random() * 1000);
                const tempField = {
                    type: type,
                    label: label,
                    variable_name: '', // User requested blank by default
                    is_required: 0,
                    help_text: '',
                    validation_rules: {}
                };

                const newFieldHTML = createFieldCardHTML(tempField, true);
                itemEl.outerHTML = newFieldHTML;

                // Trigger auto-save
                saveFieldStructure();
            },
            onUpdate: function (evt) {
                saveFieldStructure();
            },
            onMove: function (evt) {
                // Prevent dragging below the footer
                if (evt.related && evt.related.classList.contains('drop-zone-footer')) {
                    return false;
                }
            }
        });
    }

    // Modal Handling
    window.openVisitModal = function () { document.getElementById('visitModal').classList.add('active'); };
    window.openFormModal = function () { document.getElementById('formModal').classList.add('active'); };
    window.closeModals = function () { document.querySelectorAll('.modal-overlay:not(#confirmModal)').forEach(el => el.classList.remove('active')); };

    // Form Submissions (AJAX) - REMOVED: Handled inline in form_builder.php to preventing double-binding and "undefined" alerts.
    // handleAjaxForm('createVisitForm', 'create_visit');
    // handleAjaxForm('createFormForm', 'create_form');

    // Edit Form Saving - Critical Fix for Data Loss
    const editForm = document.getElementById('editFieldForm');
    if (editForm) {
        editForm.addEventListener('submit', function (e) {
            e.preventDefault();

            // Strict Variable Name Check
            const varNameInput = document.getElementById('fieldVarName');
            if (varNameInput && !varNameInput.value.trim()) {
                alert("Variable Name is required.");
                varNameInput.focus();
                return;
            }

            const formData = new FormData(editForm);

            // Collect Validations manually to JSON
            const validationRules = {
                min: formData.get('min_value') || '',
                max: formData.get('max_value') || ''
            };
            formData.append('validation_rules', JSON.stringify(validationRules));

            // ACTION is required
            formData.append('action', 'update_field_details');

            fetch('ajax_structure.php', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        showToast('Field updated successfully');
                        setTimeout(() => location.reload(), 500);
                    } else {
                        alert("Error saving: " + (data.message || data.error || 'Unknown error'));
                    }
                })
                .catch(err => alert("Network Error: " + err));
        });
    }

    // Inject Toast Container if not exists
    if (!document.getElementById('toast-container')) {
        const toastContainer = document.createElement('div');
        toastContainer.id = 'toast-container';
        toastContainer.style.cssText = 'position: fixed; bottom: 20px; right: 20px; z-index: 10000; display: flex; flex-direction: column; gap: 10px; pointer-events: none;';
        document.body.appendChild(toastContainer);
    }
});

// --- Toast Notification ---
window.showToast = function (message, type = 'success') {
    const container = document.getElementById('toast-container');
    const toast = document.createElement('div');
    const bg = type === 'success' ? '#10b981' : '#ef4444';
    toast.style.cssText = `
        background: ${bg}; color: white; padding: 12px 24px; border-radius: 8px; 
        box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); font-weight: 500; font-size: 0.875rem;
        display: flex; align-items: center; gap: 8px; transform: translateX(100%); transition: transform 0.3s ease;
        pointer-events: auto; min-width: 250px;
    `;
    toast.innerHTML = `<span class="material-icons-round" style="font-size: 18px;">${type === 'success' ? 'check_circle' : 'error'}</span> ${message}`;
    container.appendChild(toast);
    setTimeout(() => { toast.style.transform = 'translateX(0)'; }, 10);
    setTimeout(() => {
        toast.style.transform = 'translateX(100%)';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
};

function createFieldCardHTML(fieldData, isNew = false) {
    // Robust escaping using Base64
    const dataJson = JSON.stringify(fieldData);
    const dataB64 = btoa(dataJson);

    // Only add data-new if explicitly told (for auto-reload trigger)
    const newAttr = isNew ? 'data-new="true"' : '';

    // Check for missing variable name
    const varNameDisplay = fieldData.variable_name ? fieldData.variable_name : '<span style="color:red; font-weight:bold;">MISSING</span>';

    return `
        <div class="field-card" data-id="${fieldData.id || ''}" data-type="${fieldData.type}" ${newAttr} data-field-data="${dataB64}">
            <div class="field-card-handle"><span class="material-icons-round">drag_indicator</span></div>
            <div class="field-card-content">
                <div class="field-label">${fieldData.label} ${fieldData.is_required == 1 ? '<span style="color:red">*</span>' : ''}</div>
                <div class="field-meta">Type: ${fieldData.type} | Var: ${varNameDisplay}</div>
            </div>
            <div class="field-card-actions">
                <button class="btn-icon" onclick="editFieldInternal(this)"><span class="material-icons-round">edit</span></button>
                <button class="btn-icon delete" onclick="deleteField(this)"><span class="material-icons-round">delete</span></button>
            </div>
        </div>
    `;
}

function handleAjaxForm(formId, action) {
    const form = document.getElementById(formId);
    if (!form) return;
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        const formData = new FormData(form);
        formData.append('action', action);
        fetch('ajax_structure.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.success) location.reload(); else alert(data.message || data.error || 'Unknown error');
            });
    });
}

function saveFieldStructure() {
    const cards = document.querySelectorAll('#form-canvas .field-card');
    const fields = [];

    // Fix: Get ID from canvas attribute, not just URL (handles default load)
    const canvas = document.getElementById('form-canvas');
    const formId = canvas ? canvas.getAttribute('data-form-id') : null;

    if (!formId) {
        console.error("No Form ID found, cannot save.");
        return;
    }

    cards.forEach((card, index) => {
        let label = card.querySelector('.field-label').innerText.replace('*', '').trim();
        fields.push({
            id: card.getAttribute('data-id'),
            type: card.getAttribute('data-type'),
            label: label,
            is_new: card.hasAttribute('data-new')
        });
    });

    const formData = new FormData();
    formData.append('action', 'save_fields');
    formData.append('form_id', formId);
    formData.append('fields', JSON.stringify(fields));

    fetch('ajax_structure.php', { method: 'POST', body: formData })
        .then(res => {
            if (!res.ok) throw new Error('Server error: ' + res.status);
            return res.json();
        })
        .then(data => {
            if (data.success) {
                if (fields.some(f => f.is_new)) {
                    showToast('Field added');
                    setTimeout(() => location.reload(), 500);
                } else {
                    showToast('Order saved');
                }
            } else {
                showToast(data.message || 'Error saving field', 'error');
                console.error("Save error:", data.message);
                setTimeout(() => location.reload(), 1500);
            }
        })
        .catch(err => {
            showToast('Network error or server failed', 'error');
            console.error("Fetch error:", err);
            setTimeout(() => location.reload(), 1500);
        });
}

// --- Internal Edit ---
window.editFieldInternal = function (btn) {
    const card = btn.closest('.field-card');
    const dataStr = card.getAttribute('data-field-data');
    if (!dataStr) return;

    // Safety: If the card is new and hasn't saved/reloaded yet, it won't have an ID in the DOM.
    // Prevent editing ghost fields.
    const cardId = card.getAttribute('data-id');
    if (!cardId || cardId === 'undefined' || cardId === '') {
        showToast('Saving field... please wait', 'error');
        return;
    }

    try {
        // Decode Base64 for robustness
        const field = JSON.parse(atob(dataStr));
        // Force the ID from the attribute if missing in JSON (for safety)
        if (!field.id) field.id = cardId;


        // Populate Form
        document.getElementById('fieldId').value = field.id;
        document.getElementById('editFieldTitle').innerText = 'Edit field: ' + field.label;
        document.getElementById('fieldType').value = field.type;
        document.getElementById('fieldLabel').value = field.label;
        document.getElementById('fieldVarName').value = field.variable_name;
        document.getElementById('fieldHelpText').value = field.help_text || '';

        // Option Group Logic
        const optContainer = document.getElementById('optGroupContainer');
        const optSelect = document.getElementById('fieldOptGroup');

        // Reset listener to avoid duplicates (cleaner way: named function, but anonymous is fine if we re-assign)
        optSelect.onchange = function () {
            if (this.value === '__NEW__') {
                openOptionGroupModal();
                this.value = ''; // Reset to empty until saved
            }
        };

        if (['dropdown', 'radio', 'checkbox'].includes(field.type)) {
            optContainer.style.display = 'block';
            optSelect.value = field.option_group_id || '';
        } else {
            optContainer.style.display = 'none';
        }

        // Radios
        if (field.is_required == 1) document.getElementById('req_yes').checked = true;
        else document.getElementById('req_no').checked = true;

        // Validations
        if (field.validation_rules) {
            let rules = field.validation_rules;
            if (typeof rules === 'string') {
                try { rules = JSON.parse(rules); } catch (e) { }
            }
            if (rules) {
                document.getElementById('fieldMin').value = rules.min || '';
                document.getElementById('fieldMax').value = rules.max || '';
            }
        } else {
            document.getElementById('fieldMin').value = '';
            document.getElementById('fieldMax').value = '';
        }

        document.getElementById('editFieldModal').classList.add('active');

        // Type Change Listener
        document.getElementById('fieldType').onchange = function () {
            if (['dropdown', 'radio', 'checkbox'].includes(this.value)) {
                optContainer.style.display = 'block';
            } else {
                optContainer.style.display = 'none';
            }
        };

    } catch (e) {
        console.error("JSON Parse Error", e);
        showToast('Error loading field data', 'error');
    }
};

// --- Option Group Modal Logic ---
window.openOptionGroupModal = function () {
    document.getElementById('createOptionGroupForm').reset();
    document.getElementById('optionsList').innerHTML = '';
    addOptionRow(); // Add first row
    addOptionRow(); // Add second row
    document.getElementById('optionGroupModal').classList.add('active');
};

window.closeOptionGroupModal = function () {
    document.getElementById('optionGroupModal').classList.remove('active');
};

window.addOptionRow = function (label = '', value = '') {
    const list = document.getElementById('optionsList');
    const div = document.createElement('div');
    div.className = 'option-row';
    div.style.cssText = 'display: flex; gap: 0.5rem; margin-bottom: 0.5rem; align-items: center;';

    div.innerHTML = `
        <span class="material-icons-round" style="color:#cbd5e1; cursor:move;">drag_indicator</span>
        <div style="flex:1">
            <input type="text" class="form-input opt-label" placeholder="Label (e.g. Yes)" value="${label}" required>
            <div style="font-size:10px; color:#94a3b8; margin-top:2px;">Label</div>
        </div>
        <div style="flex:1">
            <input type="text" class="form-input opt-value" placeholder="Value (e.g. 1)" value="${value}">
             <div style="font-size:10px; color:#94a3b8; margin-top:2px;">Value (Stored in DB)</div>
        </div>
        <button type="button" class="btn-icon delete" onclick="this.parentElement.remove()" tabindex="-1"><span class="material-icons-round">close</span></button>
    `;
    list.appendChild(div);
};

// Initialize Option Group Form Submission
document.addEventListener('DOMContentLoaded', function () {
    const ogForm = document.getElementById('createOptionGroupForm');
    if (ogForm) {
        ogForm.addEventListener('submit', function (e) {
            e.preventDefault();
            const formData = new FormData(ogForm);

            // Collect Options
            const options = [];
            const rows = document.querySelectorAll('#optionsList .option-row');
            rows.forEach(row => {
                const label = row.querySelector('.opt-label').value.trim();
                const value = row.querySelector('.opt-value').value.trim();
                if (label) {
                    options.push({ label: label, value: value });
                }
            });

            if (options.length === 0) {
                alert("Please add at least one option.");
                return;
            }

            formData.append('action', 'save_group_full');
            formData.append('choices', JSON.stringify(options));

            // Add submit button loading state?
            const btn = ogForm.querySelector('button[type="submit"]');
            const oldText = btn.innerText;
            btn.disabled = true; btn.innerText = "Saving...";

            fetch('ajax_options.php', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    btn.disabled = false; btn.innerText = oldText;
                    if (data.success) {
                        showToast('Option Group created!');
                        closeOptionGroupModal();

                        // Add to Dropdown and Select
                        const select = document.getElementById('fieldOptGroup');
                        const opt = document.createElement('option');
                        opt.value = data.id;
                        opt.text = formData.get('name');
                        select.add(opt, select.options[select.length]); // Add at end? Or before Create New?
                        // Actually standard is append.

                        select.value = data.id;
                    } else {
                        alert("Error: " + data.message);
                    }
                })
                .catch(e => {
                    console.error(e);
                    alert("Network Error");
                    btn.disabled = false; btn.innerText = oldText;
                });
        });
    }

    // Make options sortable
    const optList = document.getElementById('optionsList');
    if (optList) {
        new Sortable(optList, {
            handle: '.material-icons-round',
            animation: 150
        });
    }
});

// --- Custom Confirm ---
window.deleteField = function (btn) {
    const card = btn.closest('.field-card');
    const id = card.getAttribute('data-id');

    // Get label safely
    let label = "Field";
    const labelEl = card.querySelector('.field-label');
    if (labelEl) {
        // Text might include * for required, remove it
        label = labelEl.innerText.replace(/\*$/, '').trim();
    }

    window.showCustomConfirm("Delete field?", "This action cannot be undone.", function () {
        if (id) {
            const formData = new FormData();
            formData.append('action', 'delete_field');
            formData.append('field_id', id);
            fetch('ajax_structure.php', { method: 'POST', body: formData }).then(() => location.reload());
        } else {
            card.remove();
        }
    }, label); // Pass label for strict confirm
};

window.showCustomConfirm = function (title, message, onCmd, matchText = null) {
    const modal = document.getElementById('confirmModal');
    document.getElementById('confirmTitle').innerText = title;
    document.getElementById('confirmMessage').innerText = message;

    const inputWrapper = document.getElementById('confirmInputWrapper');
    const inputFn = document.getElementById('confirmInput');
    const matchSpan = document.getElementById('confirmMatchText');
    const okBtn = document.getElementById('confirmBtn');

    // Clone to remove old listeners
    const newBtn = okBtn.cloneNode(true);
    okBtn.parentNode.replaceChild(newBtn, okBtn);

    if (matchText) {
        inputWrapper.style.display = 'block';
        matchSpan.innerText = matchText;
        inputFn.value = '';

        // Disable by default
        newBtn.disabled = true;
        newBtn.style.opacity = '0.5';
        newBtn.style.cursor = 'not-allowed';

        inputFn.oninput = function () {
            if (this.value === matchText) {
                newBtn.disabled = false;
                newBtn.style.opacity = '1';
                newBtn.style.cursor = 'pointer';
            } else {
                newBtn.disabled = true;
                newBtn.style.opacity = '0.5';
                newBtn.style.cursor = 'not-allowed';
            }
        };
        // Auto focus
        setTimeout(() => inputFn.focus(), 100);
    } else {
        inputWrapper.style.display = 'none';
        newBtn.disabled = false;
        newBtn.style.opacity = '1';
        newBtn.style.cursor = 'pointer';
        inputFn.oninput = null;
    }

    newBtn.addEventListener('click', function () {
        modal.classList.remove('active');
        modal.style.display = 'none'; // Force hide
        onCmd();
    });

    modal.style.display = 'flex'; // Force show
    requestAnimationFrame(() => modal.classList.add('active'));
};
window.closeConfirm = function () {
    const modal = document.getElementById('confirmModal');
    modal.classList.remove('active');
    setTimeout(() => modal.style.display = 'none', 200);
};
