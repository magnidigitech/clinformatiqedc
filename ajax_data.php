<?php
// ajax_data.php - Handle Data Entry Logic
require_once 'includes/functions.php';
require_once 'includes/auth.php';

// Ensure no output before JSON
ob_start();

header('Content-Type: application/json');

try {
    requireLogin();
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Invalid request method");
    }

    $action = $_POST['action'] ?? '';
    $pdo = getDB();
    $study_id = $_SESSION['active_study_id'];

    $role_lower = strtolower($_SESSION['active_role_name'] ?? '');
    $is_coordinator = (strpos($role_lower, 'coordinator') !== false) || (strpos($role_lower, 'admin') !== false);
    $is_monitor = (strpos($role_lower, 'monitor') !== false) || (strpos($role_lower, 'admin') !== false);
    $is_manager = (strpos($role_lower, 'manager') !== false) || (strpos($role_lower, 'admin') !== false);

    if ($action === 'save_data') {
        if (!hasPermission('enter_data') && !hasPermission('edit')) {
            throw new Exception("Unauthorized: Enter Data permission required");
        }
        // 1. Ensure Tables Exist (Lazy Migration)
        ensureTablesExist($pdo);
        ensureSubjectColumn($pdo); // Ensure progress column exists

        $raw_data = $_POST['data'] ?? [];
        if (is_string($raw_data)) {
            $data = json_decode($raw_data, true) ?? [];
        } else {
            $data = $raw_data;
        }

        $subject_id = (int)($_POST['subject_id'] ?? 0);
        $visit_id = (int)($_POST['visit_id'] ?? 0);
        $form_id = (int)($_POST['form_id'] ?? 0);
        $repeating_instance_id = (int)($_POST['repeating_instance_id'] ?? 0);

        // Security check: Ensure subject_id is valid integer
        if ($subject_id <= 0) {
            // Check if it's a string code? (Wait, ajax_data is designed for IDs)
            // For now, just ensure it's > 0
            throw new Exception("Invalid Subject ID");
        }

        // If data index is empty, try looking for individual data[ID] in POST
        if (empty($data)) {
            foreach ($_POST as $k => $v) {
                if (strpos($k, 'data_') === 0) {
                    $fid = str_replace(['data_', '[]'], '', $k);
                    $data[$fid] = $v;
                }
            }
        }
        if (!$subject_id || !$form_id) {
            throw new Exception("Missing required context IDs");
        }

        // Verify Subject belongs to Study
        $chk = $pdo->prepare("SELECT id FROM subjects WHERE id = ? AND study_id = ?");
        $chk->execute([$subject_id, $study_id]);
        if (!$chk->fetch()) throw new Exception("Invalid subject");

        // Enforce read-only lock if SDR is submitted
        $stmt_lock = $pdo->prepare("SELECT sdr_submitted FROM subject_form_status WHERE subject_id = ? AND form_id = ? AND (repeating_instance_id = ? OR (? = 0 AND repeating_instance_id IS NULL))");
        $stmt_lock->execute([$subject_id, $form_id, $repeating_instance_id, $repeating_instance_id]);
        $lock_row = $stmt_lock->fetch();
        if ($lock_row && (bool)$lock_row['sdr_submitted']) {
            throw new Exception("This form is locked (SDR submitted) and cannot be edited.");
        }

        $pdo->beginTransaction();

        try {
            // Prepared statements with instance_id
            // Check existing using instance_id
            // Standardize repeating_instance_id: Use 0 for non-repeating
            $repeating_instance_id = (int)$repeating_instance_id;

            // Prepared statements
            // Robust delete: handles both NULL and 0 when instance is 0
            $stmt_delete = $pdo->prepare("DELETE FROM subject_data WHERE subject_id = ? AND form_id = ? AND field_id = ? AND (repeating_instance_id = ? OR (? = 0 AND repeating_instance_id IS NULL))");
            $stmt_insert = $pdo->prepare("INSERT INTO subject_data (study_id, subject_id, visit_id, form_id, repeating_instance_id, field_id, value, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt_old = $pdo->prepare("SELECT value FROM subject_data WHERE subject_id = ? AND form_id = ? AND field_id = ? AND (repeating_instance_id = ? OR (? = 0 AND repeating_instance_id IS NULL))");
            $stmt_audit = $pdo->prepare("INSERT INTO data_audit_log (study_id, subject_id, visit_id, form_id, field_id, repeating_instance_id, old_value, new_value, reason_for_change, change_type, action_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

            foreach ($data as $field_id => $value) {
                // Ensure value is a string for storage (collapses checkbox arrays)
                $val_to_save = is_array($value) ? implode(',', $value) : $value;
                
                // Get old value for audit logging
                $stmt_old->execute([$subject_id, $form_id, $field_id, $repeating_instance_id, $repeating_instance_id]);
                $old_val_raw = $stmt_old->fetch();
                
                if ($old_val_raw === false) {
                    // Initial insert
                    if ($val_to_save !== '') {
                        $stmt_audit->execute([
                            $study_id, $subject_id, $visit_id, $form_id, $field_id, $repeating_instance_id,
                            null, $val_to_save, 'Initial Value Entry', 'insert', $_SESSION['user_id']
                        ]);
                    }
                } else {
                    $old_val = $old_val_raw['value'];
                    if ($old_val !== $val_to_save) {
                        // Value updated
                        $stmt_audit->execute([
                            $study_id, $subject_id, $visit_id, $form_id, $field_id, $repeating_instance_id,
                            $old_val, $val_to_save, 'Value Updated', 'update', $_SESSION['user_id']
                        ]);
                    }
                }
                
                // 1. Delete any existing rows for this field (robustly matches 0 and NULL)
                $stmt_delete->execute([$subject_id, $form_id, $field_id, $repeating_instance_id, $repeating_instance_id]);
                
                // 2. Insert new value (standardized 0 if main)
                $stmt_insert->execute([
                    $study_id, 
                    $subject_id, 
                    $visit_id, 
                    $form_id, 
                    $repeating_instance_id, 
                    $field_id, 
                    $val_to_save, 
                    $_SESSION['user_id']
                ]);
            }

            // --- 2. Calculate Form Status & Progress ---
            $progress_stats = calculateAndSaveProgress($pdo, $study_id, $subject_id, $visit_id, $form_id, $repeating_instance_id);

            $pdo->commit();
            
            // Clean output buffer and return success
            ob_clean();
            echo json_encode(array_merge(['success' => true], $progress_stats));

        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    } 
    
    elseif ($action === 'create_instance') {
        if (!hasPermission('enter_data')) throw new Exception("Unauthorized");
        
        $subject_id = $_POST['subject_id'];
        $module_id = $_POST['module_id'];
        $label = trim($_POST['label'] ?? '');
        
        // Auto label if empty
        if (empty($label)) {
            $stmt_c = $pdo->prepare("SELECT COUNT(*) FROM subject_repeating_instances WHERE subject_id = ? AND repeating_module_id = ?");
            $stmt_c->execute([$subject_id, $module_id]);
            $count = $stmt_c->fetchColumn();
            $label = ($count + 1);
        }
        
        $stmt = $pdo->prepare("INSERT INTO subject_repeating_instances (study_id, subject_id, repeating_module_id, instance_label, created_by) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$study_id, $subject_id, $module_id, $label, $_SESSION['user_id']]);
        
        echo json_encode(['success' => true]);
    }
    
    elseif ($action === 'delete_instance') {
        if (!hasPermission('enter_data')) throw new Exception("Unauthorized");
        
        $instance_id = $_POST['instance_id'];
        
        // Soft delete
        $stmt = $pdo->prepare("UPDATE subject_repeating_instances SET status = 'deleted' WHERE id = ?");
        $stmt->execute([$instance_id]);
        
        echo json_encode(['success' => true]);
    } 
    
    elseif ($action === 'get_field_details') {
        $subject_id = $_POST['subject_id'];
        $form_id = $_POST['form_id'];
        $field_id = $_POST['field_id'];
        $repeating_instance_id = (int)($_POST['repeating_instance_id'] ?? 0);
        
        // 1. Queries
        $stmt_q = $pdo->prepare("SELECT q.*, COALESCE(u.name, u.username) as created_by_name FROM data_queries q LEFT JOIN users u ON q.created_by = u.id WHERE subject_id = ? AND form_id = ? AND field_id = ? AND (repeating_instance_id = ? OR (? = 0 AND repeating_instance_id IS NULL)) ORDER BY created_at DESC");
        $stmt_q->execute([$subject_id, $form_id, $field_id, $repeating_instance_id, $repeating_instance_id]);
        $queries = $stmt_q->fetchAll(PDO::FETCH_ASSOC);
        
        // 2. Comments
        $stmt_c = $pdo->prepare("SELECT c.*, COALESCE(u.name, u.username) as created_by_name FROM data_comments c LEFT JOIN users u ON c.created_by = u.id WHERE subject_id = ? AND form_id = ? AND field_id = ? AND (repeating_instance_id = ? OR (? = 0 AND repeating_instance_id IS NULL)) ORDER BY created_at DESC");
        $stmt_c->execute([$subject_id, $form_id, $field_id, $repeating_instance_id, $repeating_instance_id]);
        $comments = $stmt_c->fetchAll(PDO::FETCH_ASSOC);
        
        // 3. History (Audit Log)
        $stmt_h = $pdo->prepare("SELECT h.*, COALESCE(u.name, u.username) as action_by_name FROM data_audit_log h LEFT JOIN users u ON h.action_by = u.id WHERE subject_id = ? AND form_id = ? AND field_id = ? AND (repeating_instance_id = ? OR (? = 0 AND repeating_instance_id IS NULL)) AND h.change_type NOT IN ('query_update', 'query_status_updated') ORDER BY action_at DESC");
        $stmt_h->execute([$subject_id, $form_id, $field_id, $repeating_instance_id, $repeating_instance_id]);
        $history = $stmt_h->fetchAll(PDO::FETCH_ASSOC);
        
        // Format action_at to clinical format: dd-mm-yyyy | hh:mm:ss
        foreach ($history as &$h) {
            $h['action_at'] = date('d-m-Y | H:i:s', strtotime($h['action_at']));
        }
        unset($h); // break reference
        
        echo json_encode(['success' => true, 'queries' => $queries, 'comments' => $comments, 'history' => $history]);
    }

    elseif ($action === 'add_query') {
        if (!hasPermission('raise_query')) {
            throw new Exception("Unauthorized: Raise Query permission required");
        }
        $subject_id = $_POST['subject_id'];
        $visit_id = $_POST['visit_id'];
        $form_id = $_POST['form_id'];
        $field_id = $_POST['field_id'];
        $repeating_instance_id = (int)($_POST['repeating_instance_id'] ?? 0);
        $query_text = trim($_POST['query_text'] ?? '');
        $user_id = $_SESSION['user_id'];

        // Server side check if form is reviewed for active role
        $stmt_stat = $pdo->prepare("SELECT monitor_reviewed, manager_reviewed FROM subject_form_status WHERE subject_id = ? AND form_id = ? AND (repeating_instance_id = ? OR (? = 0 AND repeating_instance_id IS NULL))");
        $stmt_stat->execute([$subject_id, $form_id, $repeating_instance_id, $repeating_instance_id]);
        $status_row = $stmt_stat->fetch(PDO::FETCH_ASSOC);
        
        $monitor_reviewed = $status_row ? (bool)$status_row['monitor_reviewed'] : false;
        $manager_reviewed = $status_row ? (bool)$status_row['manager_reviewed'] : false;
        
        if (($is_monitor && $monitor_reviewed) || ($is_manager && $manager_reviewed)) {
            throw new Exception("Cannot Raise Query as the form is marked as review");
        }
        
        // Server side check if there is an unresolved query
        $stmt_open = $pdo->prepare("SELECT COUNT(*) FROM data_queries WHERE subject_id = ? AND form_id = ? AND field_id = ? AND (repeating_instance_id = ? OR (? = 0 AND repeating_instance_id IS NULL)) AND status != 'closed'");
        $stmt_open->execute([$subject_id, $form_id, $field_id, $repeating_instance_id, $repeating_instance_id]);
        if ($stmt_open->fetchColumn() > 0) {
            throw new Exception("Cannot Raise Query as a query is open");
        }
        
        if ($query_text === '') {
            $query_text = 'Query raised';
        }

        $pdo->beginTransaction();
        try {
            // Create Query
            $stmt = $pdo->prepare("INSERT INTO data_queries (study_id, subject_id, visit_id, form_id, field_id, repeating_instance_id, query_text, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, 'new', ?)");
            $stmt->execute([$study_id, $subject_id, $visit_id, $form_id, $field_id, $repeating_instance_id, $query_text, $user_id]);
            $query_id = $pdo->lastInsertId();
            
            // Log History (Initial creation)
            $stmt_h = $pdo->prepare("INSERT INTO data_query_history (query_id, status_from, status_to, remark, created_by) VALUES (?, NULL, 'new', ?, ?)");
            $stmt_h->execute([$query_id, $query_text, $user_id]);
            
            // Log in data_audit_log so it shows in field history
            $stmt_audit = $pdo->prepare("INSERT INTO data_audit_log (study_id, subject_id, visit_id, form_id, field_id, repeating_instance_id, old_value, new_value, reason_for_change, change_type, action_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'query_raised', ?)");
            $stmt_audit->execute([
                $study_id, $subject_id, $visit_id, $form_id, $field_id, $repeating_instance_id,
                null, null, "Query Raised (" . $query_id . "): " . $query_text, $user_id
            ]);
            
            $pdo->commit();
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    elseif ($action === 'update_query_status') {
        $query_id = $_POST['query_id'];
        $new_status = $_POST['status'];
        $remark = trim($_POST['remark'] ?? '');
        $user_id = $_SESSION['user_id'];
        
        if ($remark === '') {
            $remark = 'Status updated';
        }

        if (!$new_status) throw new Exception("Status required");
        
        $pdo->beginTransaction();
        try {
            // Check if there is an optional field value update
            $hist_old_val = null;
            $hist_new_val = null;
            
            if (isset($_POST['field_value'])) {
                if (!hasPermission('enter_data') && !hasPermission('edit')) {
                    throw new Exception("Unauthorized to edit clinical data");
                }
                
                // Get query information to know context
                $stmt_qinfo = $pdo->prepare("SELECT subject_id, visit_id, form_id, field_id, COALESCE(repeating_instance_id, 0) as repeating_instance_id FROM data_queries WHERE id = ?");
                $stmt_qinfo->execute([$query_id]);
                $qinfo = $stmt_qinfo->fetch();
                
                if ($qinfo) {
                    $field_value = $_POST['field_value'];
                    $sub_id = $qinfo['subject_id'];
                    $vis_id = $qinfo['visit_id'];
                    $frm_id = $qinfo['form_id'];
                    $fld_id = $qinfo['field_id'];
                    $inst_id = $qinfo['repeating_instance_id'];
                    
                    // Fetch existing value
                    $stmt_old = $pdo->prepare("SELECT value FROM subject_data WHERE subject_id = ? AND form_id = ? AND field_id = ? AND (repeating_instance_id = ? OR (? = 0 AND repeating_instance_id IS NULL))");
                    $stmt_old->execute([$sub_id, $frm_id, $fld_id, $inst_id, $inst_id]);
                    $old_val = $stmt_old->fetchColumn();
                    
                    if ($old_val !== $field_value) {
                        $hist_old_val = $old_val;
                        $hist_new_val = $field_value;
                        
                        // Delete existing rows
                        $stmt_del = $pdo->prepare("DELETE FROM subject_data WHERE subject_id = ? AND form_id = ? AND field_id = ? AND (repeating_instance_id = ? OR (? = 0 AND repeating_instance_id IS NULL))");
                        $stmt_del->execute([$sub_id, $frm_id, $fld_id, $inst_id, $inst_id]);
                        
                        // Insert new value
                        $stmt_ins = $pdo->prepare("INSERT INTO subject_data (study_id, subject_id, visit_id, form_id, repeating_instance_id, field_id, value, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt_ins->execute([$study_id, $sub_id, $vis_id, $frm_id, $inst_id, $fld_id, $field_value, $user_id]);
                        
                        // Log Audit Log entry
                        $stmt_a = $pdo->prepare("INSERT INTO data_audit_log (study_id, subject_id, visit_id, form_id, field_id, repeating_instance_id, old_value, new_value, reason_for_change, change_type, action_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'update', ?)");
                        $stmt_a->execute([
                            $study_id,
                            $sub_id,
                            $vis_id,
                            $frm_id,
                            $fld_id,
                            $inst_id,
                            $old_val,
                            $field_value,
                            "Updated query (" . $query_id . ") : Answered: " . $remark,
                            $user_id
                        ]);
                        
                        // Recalculate Progress
                        calculateAndSaveProgress($pdo, $study_id, $sub_id, $vis_id, $frm_id, $inst_id);
                    }
                }
            }
            
            // Get Current Status
            $stmt_curr = $pdo->prepare("SELECT status FROM data_queries WHERE id = ?");
            $stmt_curr->execute([$query_id]);
            $curr = $stmt_curr->fetch();
            if (!$curr) throw new Exception("Query not found");
            $old_status = $curr['status'];
            
            // Update Query status
            $stmt_upd = $pdo->prepare("UPDATE data_queries SET status = ? WHERE id = ?");
            $stmt_upd->execute([$new_status, $query_id]);
            
            // Log History (with value changes)
            $stmt_h = $pdo->prepare("INSERT INTO data_query_history (query_id, status_from, status_to, remark, old_value, new_value, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt_h->execute([$query_id, $old_status, $new_status, $remark, $hist_old_val, $hist_new_val, $user_id]);
            
            // Get query information to log in data_audit_log (when closed or requery)
            if ($new_status === 'closed' || ($new_status === 'open' && $old_status === 'answered')) {
                $stmt_qinfo = $pdo->prepare("SELECT subject_id, visit_id, form_id, field_id, COALESCE(repeating_instance_id, 0) as repeating_instance_id FROM data_queries WHERE id = ?");
                $stmt_qinfo->execute([$query_id]);
                $qinfo = $stmt_qinfo->fetch();
                
                if ($qinfo) {
                    $sub_id = $qinfo['subject_id'];
                    $vis_id = $qinfo['visit_id'];
                    $frm_id = $qinfo['form_id'];
                    $fld_id = $qinfo['field_id'];
                    $inst_id = $qinfo['repeating_instance_id'];
                    
                    $reason = '';
                    $change_type = '';
                    if ($new_status === 'closed') {
                        $reason = "Query (" . $query_id . ") Closed: " . $remark;
                        $change_type = 'query_closed';
                    } else {
                        $reason = "Requery (" . $query_id . "): " . $remark;
                        $change_type = 'query_reopened';
                    }
                    
                    $stmt_audit = $pdo->prepare("INSERT INTO data_audit_log (study_id, subject_id, visit_id, form_id, field_id, repeating_instance_id, old_value, new_value, reason_for_change, change_type, action_by) VALUES (?, ?, ?, ?, ?, ?, NULL, NULL, ?, ?, ?)");
                    $stmt_audit->execute([
                        $study_id, $sub_id, $vis_id, $frm_id, $fld_id, $inst_id,
                        $reason, $change_type, $user_id
                    ]);
                }
            }
            
            $pdo->commit();
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    elseif ($action === 'get_query_history') {
        $query_id = $_POST['query_id'];
        $stmt = $pdo->prepare("SELECT h.*, COALESCE(u.name, u.username) as created_by_name FROM data_query_history h LEFT JOIN users u ON h.created_by = u.id WHERE query_id = ? ORDER BY created_at DESC");
        $stmt->execute([$query_id]);
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format created_at to clinical format: dd-mm-yyyy | hh:mm:ss
        foreach ($history as &$h) {
            $h['created_at'] = date('d-m-Y | H:i:s', strtotime($h['created_at']));
        }
        unset($h); // break reference
        
        echo json_encode(['success' => true, 'history' => $history]);
    }
    
    elseif ($action === 'add_comment') {
        $subject_id = $_POST['subject_id'];
        $visit_id = $_POST['visit_id'];
        $form_id = $_POST['form_id'];
        $field_id = $_POST['field_id'];
        $repeating_instance_id = (int)($_POST['repeating_instance_id'] ?? 0);
        $comment = trim($_POST['comment'] ?? '');
        
        if (!$comment) throw new Exception("Comment required");
        
        $stmt = $pdo->prepare("INSERT INTO data_comments (study_id, subject_id, visit_id, form_id, field_id, repeating_instance_id, comment_text, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$study_id, $subject_id, $visit_id, $form_id, $field_id, $repeating_instance_id, $comment, $_SESSION['user_id']]);
        
        echo json_encode(['success' => true]);
    }

    elseif ($action === 'clear_data') {
        // Strict data entry permission required
        if (!hasPermission('enter_data') && !hasPermission('edit')) {
            throw new Exception("Unauthorized: Edit permission required");
        }
        
        $subject_id = $_POST['subject_id'];
        $form_id = $_POST['form_id'];
        $field_id = $_POST['field_id'];
        $repeating_instance_id = (int)($_POST['repeating_instance_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        
        if (!$reason) throw new Exception("Reason required");
        
        // Enforce read-only lock if SDR is submitted
        $stmt_lock = $pdo->prepare("SELECT sdr_submitted FROM subject_form_status WHERE subject_id = ? AND form_id = ? AND (repeating_instance_id = ? OR (? = 0 AND repeating_instance_id IS NULL))");
        $stmt_lock->execute([$subject_id, $form_id, $repeating_instance_id, $repeating_instance_id]);
        $lock_row = $stmt_lock->fetch();
        if ($lock_row && (bool)$lock_row['sdr_submitted']) {
            throw new Exception("This form is locked (SDR submitted) and cannot be edited.");
        }

        $pdo->beginTransaction();
        try {
            // Get Old Value
            $stmt_old = $pdo->prepare("SELECT value FROM subject_data WHERE subject_id = ? AND form_id = ? AND field_id = ? AND (repeating_instance_id = ? OR (? = 0 AND repeating_instance_id IS NULL))");
            $stmt_old->execute([$subject_id, $form_id, $field_id, $repeating_instance_id, $repeating_instance_id]);
            $old_val = $stmt_old->fetchColumn();
            
            // Update (Delete/Set Null)
            // Ideally we keep the row but set value to NULL or delete. 
            // In this system, delete is used for clearing. 
            $stmt_del = $pdo->prepare("DELETE FROM subject_data WHERE subject_id = ? AND form_id = ? AND field_id = ? AND (repeating_instance_id = ? OR (? = 0 AND repeating_instance_id IS NULL))");
            $stmt_del->execute([$subject_id, $form_id, $field_id, $repeating_instance_id, $repeating_instance_id]);
            
            // Log Audit
            $stmt_a = $pdo->prepare("INSERT INTO data_audit_log (study_id, subject_id, visit_id, form_id, field_id, repeating_instance_id, old_value, new_value, reason_for_change, change_type, action_by) VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?, 'clear', ?)");
            $stmt_a->execute([$study_id, $subject_id, $_POST['visit_id'], $form_id, $field_id, $repeating_instance_id, $old_val, $reason, $_SESSION['user_id']]);
            
            // Recalculate Progress
            $progress_stats = calculateAndSaveProgress($pdo, $study_id, $subject_id, $_POST['visit_id'], $form_id, $repeating_instance_id);

            $pdo->commit();
            echo json_encode(array_merge(['success' => true], $progress_stats));
        } catch (Exception $e) {
             $pdo->rollBack();
             throw $e;
        }
    }

    elseif ($action === 'mark_missing') {
        // Strict data entry permission required
        if (!hasPermission('enter_data') && !hasPermission('edit')) {
            throw new Exception("Unauthorized: Edit permission required");
        }
        
        $subject_id = $_POST['subject_id'];
        $form_id = $_POST['form_id'];
        $field_id = $_POST['field_id'];
        $repeating_instance_id = (int)($_POST['repeating_instance_id'] ?? 0);
        $code = $_POST['code']; // e.g. -95
        $comment = trim($_POST['comment'] ?? '');
        
        if (!$code) throw new Exception("Missing code required");
        
        // Enforce read-only lock if SDR is submitted
        $stmt_lock = $pdo->prepare("SELECT sdr_submitted FROM subject_form_status WHERE subject_id = ? AND form_id = ? AND (repeating_instance_id = ? OR (? = 0 AND repeating_instance_id IS NULL))");
        $stmt_lock->execute([$subject_id, $form_id, $repeating_instance_id, $repeating_instance_id]);
        $lock_row = $stmt_lock->fetch();
        if ($lock_row && (bool)$lock_row['sdr_submitted']) {
            throw new Exception("This form is locked (SDR submitted) and cannot be edited.");
        }

        $pdo->beginTransaction();
        try {
            // Get Old Value
            $stmt_old = $pdo->prepare("SELECT value FROM subject_data WHERE subject_id = ? AND form_id = ? AND field_id = ? AND (repeating_instance_id = ? OR (? = 0 AND repeating_instance_id IS NULL))");
            $stmt_old->execute([$subject_id, $form_id, $field_id, $repeating_instance_id, $repeating_instance_id]);
            $old_val = $stmt_old->fetchColumn();
            
            // Delete existing (clean slate)
            $stmt_del = $pdo->prepare("DELETE FROM subject_data WHERE subject_id = ? AND form_id = ? AND field_id = ? AND (repeating_instance_id = ? OR (? = 0 AND repeating_instance_id IS NULL))");
            $stmt_del->execute([$subject_id, $form_id, $field_id, $repeating_instance_id, $repeating_instance_id]);
            
            // Insert Missing Code
            $stmt_ins = $pdo->prepare("INSERT INTO subject_data (study_id, subject_id, visit_id, form_id, repeating_instance_id, field_id, value, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt_ins->execute([$study_id, $subject_id, $_POST['visit_id'], $form_id, $repeating_instance_id, $field_id, $code, $_SESSION['user_id']]);
            
            // Log Audit
            $reason = "Marked missing ($code). " . $comment;
            $stmt_a = $pdo->prepare("INSERT INTO data_audit_log (study_id, subject_id, visit_id, form_id, field_id, repeating_instance_id, old_value, new_value, reason_for_change, change_type, action_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'missing_code', ?)");
            $stmt_a->execute([$study_id, $subject_id, $_POST['visit_id'], $form_id, $field_id, $repeating_instance_id, $old_val, $code, $reason, $_SESSION['user_id']]);
            
            // Recalculate Progress
            $progress_stats = calculateAndSaveProgress($pdo, $study_id, $subject_id, $_POST['visit_id'], $form_id, $repeating_instance_id);

            $pdo->commit();
            echo json_encode(array_merge(['success' => true], $progress_stats));
        } catch (Exception $e) {
             $pdo->rollBack();
             throw $e;
        }
    }

    elseif ($action === 'verify_form') {
        if (!hasPermission('verify')) {
            throw new Exception("Unauthorized: Verify permission required");
        }
        
        $subject_id = $_POST['subject_id'];
        $form_id = $_POST['form_id'];
        $repeating_instance_id = (int)($_POST['repeating_instance_id'] ?? 0);
        
        ensureTablesExist($pdo); // Ensure column exists
        
        $pdo->beginTransaction();
        try {
             error_log("Attempting verification for sub=$subject_id, form=$form_id");
             
             // Update status to 'verified'
             // Ensure it's complete first? Ideally yes.
             $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
             $is_verified_val = ($driver === 'pgsql') ? 'true' : '1';
             $stmt = $pdo->prepare("UPDATE subject_form_status SET status = 'verified', is_verified = $is_verified_val, updated_at = NOW() WHERE subject_id = ? AND form_id = ? AND (repeating_instance_id = ? OR (? = 0 AND repeating_instance_id IS NULL))");
             $stmt->execute([$subject_id, $form_id, $repeating_instance_id, $repeating_instance_id]);
             
             $affected = $stmt->rowCount();
             error_log("Verification Update Rows: " . $affected);
             
             if ($affected === 0) {
                 // Try inserting if not exists? No, verification implies data exists.
                 // Maybe the WHERE clause is failing?
                 error_log("WARNING: Verification update affected 0 rows. Check if status row exists.");
                 
                 // Fallback: Insert if missing (edge case)
                 // But wait, if they are verifying, progress must be 100%, so row should exist?
             }
             
             // Log Audit
             $stmt_a = $pdo->prepare("INSERT INTO data_audit_log (study_id, subject_id, visit_id, form_id, repeating_instance_id, change_type, reason_for_change, action_by) VALUES (?, ?, ?, ?, ?, 'verify', 'Source Data Verification', ?)");
             $stmt_a->execute([$study_id, $subject_id, $_POST['visit_id'] ?? 0, $form_id, $repeating_instance_id, $_SESSION['user_id']]);
             
             // Recalculate study progress
             $progress_stats = calculateAndSaveProgress($pdo, $study_id, $subject_id, $_POST['visit_id'] ?? 0, $form_id, $repeating_instance_id);
             
             // Fetch updated row
             $stmt_stat = $pdo->prepare("SELECT * FROM subject_form_status WHERE subject_id = ? AND form_id = ? AND (repeating_instance_id = ? OR (? = 0 AND repeating_instance_id IS NULL))");
             $stmt_stat->execute([$subject_id, $form_id, $repeating_instance_id, $repeating_instance_id]);
             $f_stat_curr = $stmt_stat->fetch(PDO::FETCH_ASSOC) ?: [
                 'status' => 'empty', 
                 'progress_percent' => 0, 
                 'is_verified' => 0,
                 'sdr_submitted' => 0, 
                 'monitor_reviewed' => 0, 
                 'manager_reviewed' => 0
             ];
             
             $f_stat_curr['progress'] = $f_stat_curr['progress_percent'];
             $rev_status_curr = getFormReviewStatus($f_stat_curr);
             
             // Build buttons HTML
             $all_mandatory_completed = areAllMandatoryFieldsCompleted($pdo, $subject_id, $form_id, $repeating_instance_id);
             $sdr_submitted = (bool)($f_stat_curr['sdr_submitted'] ?? false);
             $monitor_reviewed = (bool)($f_stat_curr['monitor_reviewed'] ?? false);
             $manager_reviewed = (bool)($f_stat_curr['manager_reviewed'] ?? false);
             $is_verified = ($f_stat_curr['status'] === 'verified' || !empty($f_stat_curr['is_verified']));
             $can_edit = hasPermission('enter_data');
             if ($sdr_submitted) {
                 $can_edit = false;
             }
             
             $buttons_html = renderWorkflowButtons($is_coordinator, $is_monitor, $is_manager, $sdr_submitted, $monitor_reviewed, $manager_reviewed, $all_mandatory_completed);
             
             $prev_link = $_POST['prev_link'] ?? '';
             $next_link = $_POST['next_link'] ?? '';
             
             $header_html = renderHeaderActions($prev_link, $next_link, $can_edit, $is_verified);
             $audit_trail_html = renderFormAuditTrail($pdo, $study_id, $subject_id, $form_id, $repeating_instance_id);
             
             $pdo->commit();
             echo json_encode(array_merge([
                 'success' => true,
                 'sdr_submitted' => $sdr_submitted,
                 'monitor_reviewed' => $monitor_reviewed,
                 'manager_reviewed' => $manager_reviewed,
                 'is_verified' => $is_verified,
                 'can_edit' => $can_edit,
                 'review_text' => $rev_status_curr['text'],
                 'review_color' => $rev_status_curr['color'],
                 'review_bg' => $rev_status_curr['bg'],
                 'review_icon' => $rev_status_curr['icon'],
                 'review_progress' => $rev_status_curr['progress'],
                 'review_bar_color' => $rev_status_curr['bar_color'],
                 'buttons_html' => $buttons_html,
                 'header_html' => $header_html,
                 'audit_trail_html' => $audit_trail_html
             ], $progress_stats));
        } catch (Exception $e) {
            error_log("Verification Error: " . $e->getMessage());
            $pdo->rollBack();
            throw $e;
        }
    }

    elseif ($action === 'update_review_status') {
         $subject_id = (int)$_POST['subject_id'];
         $form_id = (int)$_POST['form_id'];
         $visit_id = (int)($_POST['visit_id'] ?? 0);
         $repeating_instance_id = (int)($_POST['repeating_instance_id'] ?? 0);
         $workflow_action = $_POST['workflow_action'] ?? '';
         $user_id = $_SESSION['user_id'];
         

         if (!$subject_id || !$form_id) {
             throw new Exception("Missing required subject or form context.");
         }
         
         ensureTablesExist($pdo);
         
         // Fetch current status row
         $stmt_stat = $pdo->prepare("SELECT * FROM subject_form_status WHERE subject_id = ? AND form_id = ? AND (repeating_instance_id = ? OR (? = 0 AND repeating_instance_id IS NULL))");
         $stmt_stat->execute([$subject_id, $form_id, $repeating_instance_id, $repeating_instance_id]);
         $status_row = $stmt_stat->fetch(PDO::FETCH_ASSOC);
         
         $sdr_submitted = $status_row ? (bool)$status_row['sdr_submitted'] : false;
         $monitor_reviewed = $status_row ? (bool)$status_row['monitor_reviewed'] : false;
         $manager_reviewed = $status_row ? (bool)$status_row['manager_reviewed'] : false;
         
         $pdo->beginTransaction();
         try {
             $audit_logs = []; // entries to write: [change_type, reason_for_change]
             
             if ($workflow_action === 'mark_sdr') {
                 if (!$is_coordinator) {
                     throw new Exception("Unauthorized: Only Data Coordinators can mark a form as SDR.");
                 }
                 if ($sdr_submitted) {
                     throw new Exception("Form is already marked as SDR.");
                 }
                 
                 // Enforce that all mandatory fields are completed
                 if (!areAllMandatoryFieldsCompleted($pdo, $subject_id, $form_id, $repeating_instance_id)) {
                     throw new Exception("Cannot mark as SDR: Not all mandatory fields are completed.");
                 }
                 
                 $sdr_submitted = true;
                 $audit_logs[] = ['sdr_submitted', 'Form marked as SDR'];
                 
             } elseif ($workflow_action === 'revoke_sdr') {
                  if (!$is_coordinator) {
                      throw new Exception("Unauthorized: Only Data Coordinators can revoke SDR.");
                  }
                  if (!$sdr_submitted) {
                      throw new Exception("Form is not marked as SDR.");
                  }
                  if ($monitor_reviewed && $manager_reviewed) {
                      throw new Exception("Cannot revoke SDR: The form has completed the full review workflow.");
                  }
                  
                  $sdr_submitted = false;
                  $audit_logs[] = ['sdr_revoked', 'SDR Revoked'];
                  
                  // Automatically remove monitor review if present
                  if ($monitor_reviewed) {
                      $monitor_reviewed = false;
                      $audit_logs[] = ['monitor_revoked', 'Monitor Review Revoked due to SDR Revocation'];
                  }
                  // Automatically remove manager review if present
                  if ($manager_reviewed) {
                      $manager_reviewed = false;
                      $audit_logs[] = ['manager_revoked', 'Manager Review Revoked due to SDR Revocation'];
                  }
                 
             } elseif ($workflow_action === 'monitor_review') {
                 if (!$is_monitor) {
                     throw new Exception("Unauthorized: Only Monitors can review.");
                 }
                 if (!$sdr_submitted) {
                     throw new Exception("Cannot review: Form must be SDR submitted first.");
                 }
                 if ($monitor_reviewed) {
                     throw new Exception("Form is already reviewed by Monitor.");
                 }
                 
                 $monitor_reviewed = true;
                 $audit_logs[] = ['monitor_reviewed', 'Reviewed by Monitor'];
                 
              } elseif ($workflow_action === 'monitor_revoke') {
                  if (!$is_monitor) {
                      throw new Exception("Unauthorized: Only Monitors can revoke review.");
                  }
                  if (!$monitor_reviewed) {
                      throw new Exception("Monitor review has not been completed.");
                  }
                  
                  $remarks = trim($_POST['remarks'] ?? '');
                  if ($remarks === '') {
                      throw new Exception("Please enter a reason before revoking the review.");
                  }
                  if (mb_strlen($remarks) < 10) {
                      throw new Exception("Remarks must be at least 10 characters.");
                  }
                  if (mb_strlen($remarks) > 500) {
                      throw new Exception("Remarks cannot exceed 500 characters.");
                  }
                  
                  $sdr_submitted = false;
                  $monitor_reviewed = false;
                  $manager_reviewed = false;
                  $audit_logs[] = ['monitor_revoked', $remarks];
                  $audit_logs[] = ['sdr_revoked', 'SDR Revoked due to Monitor Review Revocation. Remarks: ' . $remarks];
                 
             } elseif ($workflow_action === 'manager_review') {
                 if (!$is_manager) {
                     throw new Exception("Unauthorized: Only Managers can review.");
                 }
                 if (!$sdr_submitted) {
                     throw new Exception("Cannot review: Form must be SDR submitted first.");
                 }
                 if ($manager_reviewed) {
                     throw new Exception("Form is already reviewed by Manager.");
                 }
                 
                 $manager_reviewed = true;
                 $audit_logs[] = ['manager_reviewed', 'Reviewed by Manager'];
                 
             } elseif ($workflow_action === 'manager_revoke') {
                   if (!$is_manager) {
                       throw new Exception("Unauthorized: Only Managers can revoke review.");
                   }
                   if (!$manager_reviewed) {
                       throw new Exception("Manager review has not been completed.");
                   }
                   
                   $remarks = trim($_POST['remarks'] ?? '');
                   if ($remarks === '') {
                       throw new Exception("Please enter a reason before revoking the review.");
                   }
                   if (mb_strlen($remarks) < 10) {
                       throw new Exception("Remarks must be at least 10 characters.");
                   }
                   if (mb_strlen($remarks) > 500) {
                       throw new Exception("Remarks cannot exceed 500 characters.");
                   }
                   
                   $sdr_submitted = false;
                   $monitor_reviewed = false;
                   $manager_reviewed = false;
                   $audit_logs[] = ['manager_revoked', $remarks];
                   $audit_logs[] = ['sdr_revoked', 'SDR Revoked due to Manager Review Revocation. Remarks: ' . $remarks];
                 
             } else {
                 throw new Exception("Invalid workflow action: " . htmlspecialchars($workflow_action));
             }
             
             $was_srved = $status_row && (bool)$status_row['monitor_reviewed'] && (bool)$status_row['manager_reviewed'];
             $is_srved = $monitor_reviewed && $manager_reviewed;
             if ($is_srved && !$was_srved) {
                 $audit_logs[] = ['form_srved', 'Form SRVed'];
             }
             
             // Map values for Postgres/MySQL driver
             $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
             $sdr_val = $sdr_submitted ? (($driver === 'pgsql') ? 'true' : '1') : (($driver === 'pgsql') ? 'false' : '0');
             $mon_val = $monitor_reviewed ? (($driver === 'pgsql') ? 'true' : '1') : (($driver === 'pgsql') ? 'false' : '0');
             $mgr_val = $manager_reviewed ? (($driver === 'pgsql') ? 'true' : '1') : (($driver === 'pgsql') ? 'false' : '0');
             
             // Update or Insert the subject_form_status row
             // We can calculate status text based on matrix for backward compatibility
             $status_text = 'in_progress';
             if (!$sdr_submitted) {
                 // Determine completion status
                 $stmt_count_fields = $pdo->prepare("SELECT COUNT(*) FROM form_fields WHERE form_id = ?");
                 $stmt_count_fields->execute([$form_id]);
                 $total_fields = (int)$stmt_count_fields->fetchColumn(); 

                 $stmt_count_filled = $pdo->prepare("SELECT COUNT(DISTINCT field_id) FROM subject_data WHERE subject_id = ? AND form_id = ? AND (repeating_instance_id = ? OR (? = 0 AND repeating_instance_id IS NULL)) AND value IS NOT NULL AND CHAR_LENGTH(value) > 0");
                 $stmt_count_filled->execute([$subject_id, $form_id, $repeating_instance_id, $repeating_instance_id]);
                 $filled_fields = (int)$stmt_count_filled->fetchColumn();

                 $progress = 0;
                 if ($total_fields > 0) {
                     $progress = round(($filled_fields / $total_fields) * 100);
                 } else {
                     $progress = 100;
                 }
                 if ($progress > 100) $progress = 100;
                 $status_text = ($progress == 100) ? 'complete' : 'in_progress';
             } else {
                 if ($manager_reviewed) {
                     $status_text = 'verified'; 
                 } elseif ($monitor_reviewed) {
                     $status_text = 'verified'; 
                 } else {
                     $status_text = 'complete';
                 }
             }
             
             $is_complete_val = ($status_text === 'complete' || $status_text === 'verified') ? 1 : 0;
             
             if ($status_row) {
                 $sql_upd = "UPDATE subject_form_status 
                             SET sdr_submitted = $sdr_val, 
                                 monitor_reviewed = $mon_val, 
                                 manager_reviewed = $mgr_val, 
                                 status = ?, 
                                 is_complete = ?,
                                 updated_at = NOW() 
                             WHERE id = ?";
                 $pdo->prepare($sql_upd)->execute([$status_text, $is_complete_val, $status_row['id']]);
             } else {
                 $sql_ins = "INSERT INTO subject_form_status (study_id, subject_id, visit_id, form_id, repeating_instance_id, sdr_submitted, monitor_reviewed, manager_reviewed, status, is_complete, progress_percent, updated_at) 
                             VALUES (?, ?, ?, ?, ?, $sdr_val, $mon_val, $mgr_val, ?, ?, 0, NOW())";
                 $pdo->prepare($sql_ins)->execute([$study_id, $subject_id, $visit_id, $form_id, $repeating_instance_id, $status_text, $is_complete_val]);
             }
             
             // Insert Data Audit Logs
             $stmt_audit = $pdo->prepare("INSERT INTO data_audit_log (study_id, subject_id, visit_id, form_id, field_id, repeating_instance_id, old_value, new_value, reason_for_change, change_type, action_by) VALUES (?, ?, ?, ?, NULL, ?, NULL, NULL, ?, ?, ?)");
             foreach ($audit_logs as $log) {
                 $stmt_audit->execute([
                     $study_id,
                     $subject_id,
                     $visit_id,
                     $form_id,
                     $repeating_instance_id,
                     $log[1], 
                     $log[0], 
                     $user_id
                 ]);
             }
             
             // Recalculate study progress
             $progress_stats = calculateAndSaveProgress($pdo, $study_id, $subject_id, $visit_id, $form_id, $repeating_instance_id);
             
             // Fetch updated row
             $stmt_stat = $pdo->prepare("SELECT * FROM subject_form_status WHERE subject_id = ? AND form_id = ? AND (repeating_instance_id = ? OR (? = 0 AND repeating_instance_id IS NULL))");
             $stmt_stat->execute([$subject_id, $form_id, $repeating_instance_id, $repeating_instance_id]);
             $f_stat_curr = $stmt_stat->fetch(PDO::FETCH_ASSOC) ?: [
                 'status' => 'empty', 
                 'progress_percent' => 0, 
                 'is_verified' => 0,
                 'sdr_submitted' => 0, 
                 'monitor_reviewed' => 0, 
                 'manager_reviewed' => 0
             ];
             
             $f_stat_curr['progress'] = $f_stat_curr['progress_percent'];
             $rev_status_curr = getFormReviewStatus($f_stat_curr);
             
             // Build buttons HTML
             $all_mandatory_completed = areAllMandatoryFieldsCompleted($pdo, $subject_id, $form_id, $repeating_instance_id);
             $sdr_submitted = (bool)($f_stat_curr['sdr_submitted'] ?? false);
             $monitor_reviewed = (bool)($f_stat_curr['monitor_reviewed'] ?? false);
             $manager_reviewed = (bool)($f_stat_curr['manager_reviewed'] ?? false);
             $is_verified = ($f_stat_curr['status'] === 'verified' || !empty($f_stat_curr['is_verified']));
             $can_edit = hasPermission('enter_data');
             if ($sdr_submitted) {
                 $can_edit = false;
             }
             
             $buttons_html = renderWorkflowButtons($is_coordinator, $is_monitor, $is_manager, $sdr_submitted, $monitor_reviewed, $manager_reviewed, $all_mandatory_completed);
             
             $prev_link = $_POST['prev_link'] ?? '';
             $next_link = $_POST['next_link'] ?? '';
             
             $header_html = renderHeaderActions($prev_link, $next_link, $can_edit, $is_verified);
             $audit_trail_html = renderFormAuditTrail($pdo, $study_id, $subject_id, $form_id, $repeating_instance_id);
             
             $pdo->commit();
             echo json_encode(array_merge([
                 'success' => true,
                 'sdr_submitted' => $sdr_submitted,
                 'monitor_reviewed' => $monitor_reviewed,
                 'manager_reviewed' => $manager_reviewed,
                 'is_verified' => $is_verified,
                 'can_edit' => $can_edit,
                 'review_text' => $rev_status_curr['text'],
                 'review_color' => $rev_status_curr['color'],
                 'review_bg' => $rev_status_curr['bg'],
                 'review_icon' => $rev_status_curr['icon'],
                 'review_progress' => $rev_status_curr['progress'],
                 'review_bar_color' => $rev_status_curr['bar_color'],
                 'buttons_html' => $buttons_html,
                 'header_html' => $header_html,
                 'audit_trail_html' => $audit_trail_html
             ], $progress_stats));
         } catch (Exception $e) {
             $pdo->rollBack();
             throw $e;
         }
     }

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

/**
 * Lazy Table Creation
 */
function ensureTablesExist($pdo) {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'pgsql') {
        $sql = "
        CREATE TABLE IF NOT EXISTS subject_data (
          id SERIAL PRIMARY KEY,
          study_id INT NOT NULL,
          subject_id INT NOT NULL,
          visit_id INT NOT NULL,
          form_id INT NOT NULL,
          field_id INT NOT NULL,
          repeating_instance_id INT DEFAULT NULL,
          value TEXT,
          updated_by INT DEFAULT NULL,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS subject_form_status (
          id SERIAL PRIMARY KEY,
          study_id INT NOT NULL,
          subject_id INT NOT NULL,
          visit_id INT NOT NULL,
          form_id INT NOT NULL,
          repeating_instance_id INT DEFAULT NULL,
          status VARCHAR(50) DEFAULT 'empty',
          progress_percent INT DEFAULT 0,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );
        ";
        
        try {
            $pdo->exec($sql);
            
            // Add indices
            try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_subject_lookup ON subject_data (subject_id, form_id)"); } catch(Exception $e) {}
            try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_field_idx ON subject_data (field_id)"); } catch(Exception $e) {}
            try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_subject_form_inst ON subject_form_status (subject_id, form_id, repeating_instance_id)"); } catch(Exception $e) {}
            
            // Lazy Update: Add is_verified & is_complete if missing
            try {
                $pdo->exec("ALTER TABLE subject_form_status ADD COLUMN is_verified BOOLEAN DEFAULT FALSE");
            } catch (Exception $e) {}
            try {
                $pdo->exec("ALTER TABLE subject_form_status ADD COLUMN is_complete BOOLEAN DEFAULT FALSE");
            } catch (Exception $e) {}
            try {
                $pdo->exec("ALTER TABLE subject_form_status ADD COLUMN sdr_submitted BOOLEAN DEFAULT FALSE");
            } catch (Exception $e) {}
            try {
                $pdo->exec("ALTER TABLE subject_form_status ADD COLUMN monitor_reviewed BOOLEAN DEFAULT FALSE");
            } catch (Exception $e) {}
            try {
                $pdo->exec("ALTER TABLE subject_form_status ADD COLUMN manager_reviewed BOOLEAN DEFAULT FALSE");
            } catch (Exception $e) {}
            try {
                $pdo->exec("ALTER TABLE data_audit_log ALTER COLUMN field_id DROP NOT NULL");
            } catch (Exception $e) {}
            
            // Create trigger/function if not exists
            try {
                $pdo->exec("
                    CREATE OR REPLACE FUNCTION update_updated_at_column()
                    RETURNS TRIGGER AS $$
                    BEGIN
                        NEW.updated_at = CURRENT_TIMESTAMP;
                        RETURN NEW;
                    END;
                    $$ language 'plpgsql';
                ");
                $pdo->exec("
                    DROP TRIGGER IF EXISTS update_subject_data_updated_at ON subject_data;
                    CREATE TRIGGER update_subject_data_updated_at 
                    BEFORE UPDATE ON subject_data 
                    FOR EACH ROW 
                    EXECUTE FUNCTION update_updated_at_column();
                ");
                $pdo->exec("
                    DROP TRIGGER IF EXISTS update_subject_form_status_updated_at ON subject_form_status;
                    CREATE TRIGGER update_subject_form_status_updated_at 
                    BEFORE UPDATE ON subject_form_status 
                    FOR EACH ROW 
                    EXECUTE FUNCTION update_updated_at_column();
                ");
            } catch (Exception $e) {}
            
        } catch (Exception $e) {
            error_log("PostgreSQL dynamic tables creation error: " . $e->getMessage());
        }
    } else {
        // MySQL fallback
        $sql = "
        CREATE TABLE IF NOT EXISTS `subject_data` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `study_id` int(11) NOT NULL,
          `subject_id` int(11) NOT NULL,
          `visit_id` int(11) NOT NULL,
          `form_id` int(11) NOT NULL,
          `field_id` int(11) NOT NULL,
          `repeating_instance_id` int(11) DEFAULT NULL,
          `value` longtext,
          `updated_by` int(11) DEFAULT NULL,
          `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `subject_lookup` (`subject_id`, `form_id`),
          KEY `field_idx` (`field_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS `subject_form_status` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `study_id` int(11) NOT NULL,
          `subject_id` int(11) NOT NULL,
          `visit_id` int(11) NOT NULL,
          `form_id` int(11) NOT NULL,
          `repeating_instance_id` int(11) DEFAULT NULL,
          `status` enum('empty','in_progress','complete','verified') DEFAULT 'empty',
          `progress_percent` int(11) DEFAULT 0,
          `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `subject_form_inst_idx` (`subject_id`, `form_id`, `repeating_instance_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ";
        
        try {
            $pdo->exec($sql);
            
            // Lazy Update: Add is_verified if missing
            try {
                $pdo->exec("ALTER TABLE subject_form_status ADD COLUMN is_verified TINYINT(1) DEFAULT 0");
            } catch (Exception $e) {}
            try {
                $pdo->exec("ALTER TABLE subject_form_status ADD COLUMN sdr_submitted TINYINT(1) DEFAULT 0");
            } catch (Exception $e) {}
            try {
                $pdo->exec("ALTER TABLE subject_form_status ADD COLUMN monitor_reviewed TINYINT(1) DEFAULT 0");
            } catch (Exception $e) {}
            try {
                $pdo->exec("ALTER TABLE subject_form_status ADD COLUMN manager_reviewed TINYINT(1) DEFAULT 0");
            } catch (Exception $e) {}
            try {
                $pdo->exec("ALTER TABLE data_audit_log MODIFY COLUMN field_id INT NULL");
            } catch (Exception $e) {}
            
        } catch (Exception $e) {
            error_log("Table migration logic error: " . $e->getMessage());
        }
    }
}

function ensureSubjectColumn($pdo) {
    try {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'pgsql') {
            $pdo->exec("ALTER TABLE subjects ADD COLUMN progress INT DEFAULT 0");
        } else {
            $pdo->exec("ALTER TABLE subjects ADD COLUMN progress INT DEFAULT 0");
        }
    } catch (Exception $e) {
        // Ignore "Duplicate column name" error
    }
}

/**
 * Helper to Calculate and Save Progress
 * Returns array with progress stats
 */
function calculateAndSaveProgress($pdo, $study_id, $subject_id, $visit_id, $form_id, $repeating_instance_id) {
    // --- 1. Calculate Form Status & Progress ---
    $stmt_count_fields = $pdo->prepare("SELECT COUNT(*) FROM form_fields WHERE form_id = ?");
    $stmt_count_fields->execute([$form_id]);
    $total_fields = (int)$stmt_count_fields->fetchColumn(); 

    // Get Filled Fields (handle instance robustly)
    // Using CHAR_LENGTH(value) > 0 to ensure "0" is counted
    $stmt_count_filled = $pdo->prepare("SELECT COUNT(DISTINCT field_id) FROM subject_data WHERE subject_id = ? AND form_id = ? AND (repeating_instance_id = ? OR (? = 0 AND repeating_instance_id IS NULL)) AND value IS NOT NULL AND CHAR_LENGTH(value) > 0");
    $stmt_count_filled->execute([$subject_id, $form_id, $repeating_instance_id, $repeating_instance_id]);
    $filled_fields = (int)$stmt_count_filled->fetchColumn();

    $progress = 0;
    if ($total_fields > 0) {
        $progress = round(($filled_fields / $total_fields) * 100);
    } else {
        $progress = 100;
    }
    if ($progress > 100) $progress = 100;
    $status = ($progress == 100) ? 'complete' : 'in_progress';

    // Update Status (handle unique constraint updates)
    $stmt_stat_chk = $pdo->prepare("SELECT id, status, sdr_submitted, monitor_reviewed, manager_reviewed FROM subject_form_status WHERE subject_id = ? AND form_id = ? AND (repeating_instance_id = ? OR (? = 0 AND repeating_instance_id IS NULL))");
    $stmt_stat_chk->execute([$subject_id, $form_id, $repeating_instance_id, $repeating_instance_id]);
    $stat_row = $stmt_stat_chk->fetch();
    
    // Preserve Verified/SDR statuses
    if ($stat_row) {
        $db_sdr = (bool)($stat_row['sdr_submitted'] ?? false);
        $db_monitor = (bool)($stat_row['monitor_reviewed'] ?? false);
        $db_manager = (bool)($stat_row['manager_reviewed'] ?? false);
        if ($db_sdr) {
            if ($db_monitor || $db_manager) {
                $status = 'verified';
            } else {
                $status = 'complete';
            }
        }
    }
    
    $is_complete = ($status === 'complete' || $status === 'verified') ? 1 : 0;

    if ($stat_row) {
         $pdo->prepare("UPDATE subject_form_status SET status = ?, is_complete = ?, progress_percent = ?, repeating_instance_id = ?, updated_at = NOW() WHERE id = ?")
             ->execute([$status, $is_complete, $progress, $repeating_instance_id, $stat_row['id']]);
    } else {
         $pdo->prepare("INSERT INTO subject_form_status (study_id, subject_id, visit_id, form_id, repeating_instance_id, status, is_complete, progress_percent, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())")
             ->execute([$study_id, $subject_id, $visit_id, $form_id, $repeating_instance_id, $status, $is_complete, $progress]);
    }

    // --- 2. Calculate Visit Progress ---
    $visit_progress = 0;
    if ($visit_id > 0) {
        $stmt_v_count = $pdo->prepare("SELECT COUNT(*) FROM study_forms WHERE visit_id = ?");
        $stmt_v_count->execute([$visit_id]);
        $visit_form_count = (int)$stmt_v_count->fetchColumn();

        $stmt_v_sum = $pdo->prepare("
            SELECT SUM(s.progress_percent) 
            FROM subject_form_status s
            JOIN study_forms f ON s.form_id = f.id
            WHERE s.subject_id = ? AND f.visit_id = ? AND (s.repeating_instance_id = 0 OR s.repeating_instance_id IS NULL)
        ");
        $stmt_v_sum->execute([$subject_id, $visit_id]);
        $visit_sum = (int)$stmt_v_sum->fetchColumn();

        if ($visit_form_count > 0) {
            $visit_progress = round($visit_sum / $visit_form_count);
        }
    }

    // --- 3. Calculate Subject Overall Progress ---
    $stmt_total_forms = $pdo->prepare("SELECT COUNT(f.id) FROM study_forms f JOIN study_visits v ON f.visit_id = v.id WHERE v.study_id = ?");
    $stmt_total_forms->execute([$study_id]);
    $study_total_forms = (int)$stmt_total_forms->fetchColumn();

    $stmt_sum_progress = $pdo->prepare("SELECT SUM(progress_percent) FROM subject_form_status WHERE subject_id = ? AND (repeating_instance_id = 0 OR repeating_instance_id IS NULL)");
    $stmt_sum_progress->execute([$subject_id]);
    $sum_progress = (int)$stmt_sum_progress->fetchColumn();
    
    $subject_progress = 0;
    if ($study_total_forms > 0) {
        $subject_progress = round($sum_progress / $study_total_forms);
    }
    if ($subject_progress > 100) $subject_progress = 100;

    $upd_sub = $pdo->prepare("UPDATE subjects SET progress = ? WHERE id = ?");
    $upd_sub->execute([$subject_progress, $subject_id]);

    return [
        'form_progress' => $progress,
        'form_status' => $status,
        'visit_progress' => $visit_progress,
        'subject_progress' => $subject_progress
    ];
}

function areAllMandatoryFieldsCompleted($pdo, $subject_id, $form_id, $repeating_instance_id) {
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
?>
