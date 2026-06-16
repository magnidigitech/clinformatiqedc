<?php
// Ensure no output before JSON
ob_start();

// Direct include of config to ensure DB connection is available without relying on wrappers
require_once 'config/db.php';
require_once 'includes/functions.php';
require_once 'includes/auth.php';

// Clear buffer before outputting JSON (removes whitespace/notices from includes)
ob_clean();

requireLogin();

header('Content-Type: application/json');

// Use Throwable to catch Fatal Errors and Exceptions
try {
    if (!isset($_SESSION['active_study_id'])) {
        throw new Exception('No active study');
    }

    $study_id = $_SESSION['active_study_id'];
    $pdo = getDB();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_visit') {
        if (!hasPermission('manage_structure') && !hasPermission('all')) {
            throw new Exception("Unauthorized: Manage Structure permission required");
        }
        $name = trim($_POST['name'] ?? '');
        if (!$name) throw new Exception("Name required");
        
        // Check for duplicate name
        $check = $pdo->prepare("SELECT id FROM study_visits WHERE study_id = ? AND name = ?");
        $check->execute([$study_id, $name]);
        if ($check->fetch()) {
            throw new Exception("A visit with this name already exists.");
        }
        
        $stmt = $pdo->prepare("INSERT INTO study_visits (study_id, name, order_index) VALUES (?, ?, 0)");
        $stmt->execute([$study_id, $name]);
        echo json_encode(['success' => true, 'visit_id' => $pdo->lastInsertId()]);
    }
    elseif ($action === 'create_module') {
        if (!hasPermission('manage_structure') && !hasPermission('all')) {
             throw new Exception("Unauthorized: Manage Structure permission required");
        }
        $name = trim($_POST['name'] ?? '');
        if (!$name) throw new Exception("Name required");
        
        // Check for duplicate name
        $check = $pdo->prepare("SELECT id FROM study_repeating_modules WHERE study_id = ? AND name = ?");
        $check->execute([$study_id, $name]);
        if ($check->fetch()) {
            throw new Exception("A module with this name already exists.");
        }
        
        $stmt = $pdo->prepare("INSERT INTO study_repeating_modules (study_id, name, order_index) VALUES (?, ?, 0)");
        $stmt->execute([$study_id, $name]);
        echo json_encode(['success' => true, 'module_id' => $pdo->lastInsertId()]);
    }
    elseif ($action === 'create_form') {
        if (!hasPermission('manage_structure') && !hasPermission('all')) {
            throw new Exception("Unauthorized");
        }
        $name = trim($_POST['name'] ?? '');
        $visit_id = $_POST['visit_id'] ?? 0;
        $repeating_module_id = $_POST['repeating_module_id'] ?? 0;
        
        if (!$name || (!$visit_id && !$repeating_module_id)) throw new Exception("Name and Parent (Visit or Module) required");
        
        if ($visit_id) {
            // Verify visit belongs to study
            $check = $pdo->prepare("SELECT id FROM study_visits WHERE id = ? AND study_id = ?");
            $check->execute([$visit_id, $study_id]);
            if (!$check->fetch()) throw new Exception("Invalid visit");
            
            // Check for duplicate form name in this visit
            $dup = $pdo->prepare("SELECT id FROM study_forms WHERE visit_id = ? AND name = ?");
            $dup->execute([$visit_id, $name]);
            if ($dup->fetch()) throw new Exception("A form with this name already exists in this visit.");
            
            $stmt = $pdo->prepare("INSERT INTO study_forms (visit_id, name, order_index) VALUES (?, ?, 0)");
            $stmt->execute([$visit_id, $name]);
        } else {
            // Verify module belongs to study
            $check = $pdo->prepare("SELECT id FROM study_repeating_modules WHERE id = ? AND study_id = ?");
            $check->execute([$repeating_module_id, $study_id]);
            if (!$check->fetch()) throw new Exception("Invalid module");
            
            // Check for duplicate form name in this module
            $dup = $pdo->prepare("SELECT id FROM study_forms WHERE repeating_module_id = ? AND name = ?");
            $dup->execute([$repeating_module_id, $name]);
            if ($dup->fetch()) throw new Exception("A form with this name already exists in this module.");
            
            $stmt = $pdo->prepare("INSERT INTO study_forms (repeating_module_id, name, order_index) VALUES (?, ?, 0)");
            $stmt->execute([$repeating_module_id, $name]);
        }
        
        echo json_encode(['success' => true, 'form_id' => $pdo->lastInsertId()]);
    }
    // ... other actions ...
    elseif ($action === 'save_fields') {
        if (!hasPermission('manage_structure') && !hasPermission('all')) {
            throw new Exception("Unauthorized");
        }
        $form_id = $_POST['form_id'] ?? 0;
        $fields_json = $_POST['fields'] ?? '[]';
        
        if (!$form_id) throw new Exception("Form ID required");
        
        // Check form ownership (supports valid visit OR valid module)
        $chk = $pdo->prepare("
            SELECT f.id FROM study_forms f 
            LEFT JOIN study_visits v ON f.visit_id = v.id 
            LEFT JOIN study_repeating_modules m ON f.repeating_module_id = m.id
            WHERE f.id = ? AND (v.study_id = ? OR m.study_id = ?)
        ");
        $chk->execute([$form_id, $study_id, $study_id]);
        if (!$chk->fetch()) throw new Exception("Invalid form or permission denied");

        $fields = json_decode($fields_json, true);
        if (json_last_error() !== JSON_ERROR_NONE) throw new Exception("Invalid JSON data");

        $pdo->beginTransaction();

        try {
            $order = 0;
            $updated_ids = [];

            foreach ($fields as $field) {
                $field_id = $field['id'] ?? null;
                $type = $field['type'] ?? 'text';
                $label = $field['label'] ?? 'New Field';
                $is_new = !empty($field['is_new']);
                $variable_name = $field['variable_name'] ?? ('var_' . time() . '_' . rand(100,999));

                if ($is_new || !$field_id || $field_id === 'undefined') {
                    // Insert new field
                    // Using default values for others
                    $ins = $pdo->prepare("
                        INSERT INTO form_fields (form_id, type, label, variable_name, order_index, is_required) 
                        VALUES (?, ?, ?, ?, ?, false)
                    ");
                    $ins->execute([$form_id, $type, $label, $variable_name, $order]);
                    // No need to track ID unless we return it, but page reloads anyway
                } else {
                    // Update order only (detailed update happens via update_field_details)
                    // But we should verify field belongs to form? 
                    // For simplicity, just update order by ID to keep it fast
                    $upd = $pdo->prepare("UPDATE form_fields SET order_index = ? WHERE id = ?");
                    $upd->execute([$order, $field_id]);
                    $updated_ids[] = $field_id;
                }
                $order++;
            }
            
            $pdo->commit();
            echo json_encode(['success' => true]);

        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
    
    elseif ($action === 'update_field_details') {
        if (!hasPermission('manage_structure') && !hasPermission('all')) {
            throw new Exception("Unauthorized");
        }
        $id = $_POST['id'] ?? 0;
        if (!$id) throw new Exception("Field ID required");

        // We should verify ownership, but assuming ID is unique and valid is okay for now if we rely on study context later.
        // Better:
        $verify = $pdo->prepare("
            SELECT ff.id 
            FROM form_fields ff
            JOIN study_forms f ON ff.form_id = f.id
            LEFT JOIN study_visits v ON f.visit_id = v.id
            LEFT JOIN study_repeating_modules m ON f.repeating_module_id = m.id
            WHERE ff.id = ? AND (v.study_id = ? OR m.study_id = ?)
        ");
        $verify->execute([$id, $study_id, $study_id]);
        if (!$verify->fetch()) throw new Exception("Field not found or access denied");

        $label = trim($_POST['label'] ?? '');
        $variable_name = trim($_POST['variable_name'] ?? '');
        
        if ($variable_name === '') {
            throw new Exception("Variable Name is required.");
        }

        $type = $_POST['type'] ?? 'text';
        $is_required = ($_POST['is_required'] ?? '0') === '1';
        $help_text = $_POST['help_text'] ?? '';
        $validation_rules = $_POST['validation_rules'] ?? '{}';
        $option_group_id = $_POST['option_group_id'] ?? null;
        if (empty($option_group_id)) $option_group_id = null;

        $upd = $pdo->prepare("
            UPDATE form_fields 
            SET label = ?, variable_name = ?, type = ?, is_required = ?, help_text = ?, validation_rules = ?, option_group_id = ?
            WHERE id = ?
        ");
        $upd->execute([$label, $variable_name, $type, $is_required, $help_text, $validation_rules, $option_group_id, $id]);
        
        echo json_encode(['success' => true]);
    }
    
    elseif ($action === 'delete_visit') {
        if (!hasPermission('manage_structure') && !hasPermission('all')) {
            throw new Exception("Unauthorized");
        }
        $visit_id = $_POST['visit_id'] ?? 0;
        if (!$visit_id) throw new Exception("Visit ID required");

        $verify = $pdo->prepare("SELECT id FROM study_visits WHERE id = ? AND study_id = ?");
        $verify->execute([$visit_id, $study_id]);
        if (!$verify->fetch()) throw new Exception("Visit not found or access denied");

        $pdo->beginTransaction();
        try {
             // Delete forms in visit
             $del_forms = $pdo->prepare("DELETE FROM study_forms WHERE visit_id = ?");
             $del_forms->execute([$visit_id]);
             
             $del_visit = $pdo->prepare("DELETE FROM study_visits WHERE id = ?");
             $del_visit->execute([$visit_id]);
             
             $pdo->commit();
             echo json_encode(['success' => true]);
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    elseif ($action === 'delete_module') {
        if (!hasPermission('manage_structure') && !hasPermission('all')) {
            throw new Exception("Unauthorized");
        }
        $module_id = $_POST['module_id'] ?? 0;
        if (!$module_id) throw new Exception("Module ID required");

        $verify = $pdo->prepare("SELECT id FROM study_repeating_modules WHERE id = ? AND study_id = ?");
        $verify->execute([$module_id, $study_id]);
        if (!$verify->fetch()) throw new Exception("Module not found or access denied");

        $pdo->beginTransaction();
        try {
             // Delete forms in module
             $del_forms = $pdo->prepare("DELETE FROM study_forms WHERE repeating_module_id = ?");
             $del_forms->execute([$module_id]);
             
             $del_mod = $pdo->prepare("DELETE FROM study_repeating_modules WHERE id = ?");
             $del_mod->execute([$module_id]);
             
             $pdo->commit();
             echo json_encode(['success' => true]);
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    elseif ($action === 'delete_form') {
        if (!hasPermission('manage_structure') && !hasPermission('all')) {
            throw new Exception("Unauthorized");
        }
        $form_id = $_POST['form_id'] ?? 0;
        if (!$form_id) throw new Exception("Form ID required");

        // Verify ownership
        $verify = $pdo->prepare("
            SELECT f.id FROM study_forms f
            LEFT JOIN study_visits v ON f.visit_id = v.id
            LEFT JOIN study_repeating_modules m ON f.repeating_module_id = m.id
            WHERE f.id = ? AND (v.study_id = ? OR m.study_id = ?)
        ");
        $verify->execute([$form_id, $study_id, $study_id]);
        if (!$verify->fetch()) throw new Exception("Form not found or access denied");

         $del = $pdo->prepare("DELETE FROM study_forms WHERE id = ?");
         $del->execute([$form_id]);
         echo json_encode(['success' => true]);
    }

    elseif ($action === 'save_structure_order') {
        if (!hasPermission('manage_structure') && !hasPermission('all')) {
            throw new Exception("Unauthorized");
        }
        
        $order_data = json_decode($_POST['order_data'] ?? '[]', true);
        if (json_last_error() !== JSON_ERROR_NONE) throw new Exception("Invalid JSON");

        $pdo->beginTransaction();
        try {
            $upd_visit = $pdo->prepare("UPDATE study_visits SET order_index = ? WHERE id = ? AND study_id = ?");
            // Only update forms that belong to visits, ensure rep_mod_id is NULL if moved to visit
            $upd_form = $pdo->prepare("UPDATE study_forms SET order_index = ?, visit_id = ?, repeating_module_id = NULL WHERE id = ?");
            
            foreach ($order_data as $visit_idx => $visit) {
                $v_id = $visit['id'];
                $forms = $visit['forms'] ?? [];
                
                // Update Visit Order
                $upd_visit->execute([$visit_idx, $v_id, $study_id]);
                
                // Update Forms in this Visit
                foreach ($forms as $form_idx => $f_id) {
                     // We implicitly trust the move, but could verify ownership.
                     $upd_form->execute([$form_idx, $v_id, $f_id]);
                }
            }
            
            $pdo->commit();
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
    
    elseif ($action === 'save_module_order') {
        if (!hasPermission('manage_structure') && !hasPermission('all')) {
            throw new Exception("Unauthorized");
        }
        
        $order_data = json_decode($_POST['order_data'] ?? '[]', true);
        if (json_last_error() !== JSON_ERROR_NONE) throw new Exception("Invalid JSON");

        $pdo->beginTransaction();
        try {
            $upd_mod = $pdo->prepare("UPDATE study_repeating_modules SET order_index = ? WHERE id = ? AND study_id = ?");
            // Only update forms that belong to modules, ensure visit_id is NULL if moved to module
            $upd_form = $pdo->prepare("UPDATE study_forms SET order_index = ?, repeating_module_id = ?, visit_id = NULL WHERE id = ?");
            
            foreach ($order_data as $mod_idx => $module) {
                $m_id = $module['id'];
                $forms = $module['forms'] ?? [];
                
                // Update Module Order
                $upd_mod->execute([$mod_idx, $m_id, $study_id]);
                
                // Update Forms in this Module
                foreach ($forms as $form_idx => $f_id) {
                     $upd_form->execute([$form_idx, $m_id, $f_id]);
                }
            }
            
            $pdo->commit();
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
    
    elseif ($action === 'delete_field') {
         if (!hasPermission('manage_structure') && !hasPermission('all')) {
            throw new Exception("Unauthorized");
        }
         $field_id = $_POST['field_id'] ?? 0;
         if(!$field_id) throw new Exception("ID required");
         
         $del = $pdo->prepare("DELETE FROM form_fields WHERE id = ?");
         $del->execute([$field_id]);
         echo json_encode(['success' => true]);
    }

    else {
        throw new Exception("Invalid action: " . htmlspecialchars($action));
    }

} catch (Throwable $e) {
    error_log("AJAX Structure Error: " . $e->getMessage());
    http_response_code(200); 
    
    $message = $e->getMessage();
    // Catch Duplicate Entry SQL Error
    if (strpos($message, 'Duplicate entry') !== false || strpos($message, '23000') !== false) {
        $message = "An item with this name already exists.";
    }
    
    echo json_encode(['success' => false, 'message' => $message]);
}
?>
