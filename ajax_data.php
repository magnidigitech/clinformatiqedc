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

    if ($action === 'save_data') {
        if (!hasPermission('enter_data') && !hasPermission('edit') && !hasPermission('all')) {
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

            foreach ($data as $field_id => $value) {
                // Ensure value is a string for storage (collapses checkbox arrays)
                $val_to_save = is_array($value) ? implode(',', $value) : $value;
                
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
        if (!hasPermission('enter_data') && !hasPermission('all')) throw new Exception("Unauthorized");
        
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
        if (!hasPermission('enter_data') && !hasPermission('all')) throw new Exception("Unauthorized");
        
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
        $stmt_q = $pdo->prepare("SELECT q.*, u.username as created_by_name FROM data_queries q LEFT JOIN users u ON q.created_by = u.id WHERE subject_id = ? AND form_id = ? AND field_id = ? AND repeating_instance_id = ? ORDER BY created_at DESC");
        $stmt_q->execute([$subject_id, $form_id, $field_id, $repeating_instance_id]);
        $queries = $stmt_q->fetchAll(PDO::FETCH_ASSOC);
        
        // 2. Comments
        $stmt_c = $pdo->prepare("SELECT c.*, u.username as created_by_name FROM data_comments c LEFT JOIN users u ON c.created_by = u.id WHERE subject_id = ? AND form_id = ? AND field_id = ? AND repeating_instance_id = ? ORDER BY created_at DESC");
        $stmt_c->execute([$subject_id, $form_id, $field_id, $repeating_instance_id]);
        $comments = $stmt_c->fetchAll(PDO::FETCH_ASSOC);
        
        // 3. History (Audit Log)
        $stmt_h = $pdo->prepare("SELECT h.*, u.username as action_by_name FROM data_audit_log h LEFT JOIN users u ON h.action_by = u.id WHERE subject_id = ? AND form_id = ? AND field_id = ? AND repeating_instance_id = ? ORDER BY action_at DESC");
        $stmt_h->execute([$subject_id, $form_id, $field_id, $repeating_instance_id]);
        $history = $stmt_h->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'queries' => $queries, 'comments' => $comments, 'history' => $history]);
    }

    elseif ($action === 'add_query') {
        if (!hasPermission('raise_query') && !hasPermission('all')) {
            throw new Exception("Unauthorized: Raise Query permission required");
        }
        $subject_id = $_POST['subject_id'];
        $visit_id = $_POST['visit_id'];
        $form_id = $_POST['form_id'];
        $field_id = $_POST['field_id'];
        $repeating_instance_id = (int)($_POST['repeating_instance_id'] ?? 0);
        $query_text = trim($_POST['query_text'] ?? '');
        $user_id = $_SESSION['user_id'];
        
        if (!$query_text) throw new Exception("Remark is required");

        $pdo->beginTransaction();
        try {
            // Create Query
            $stmt = $pdo->prepare("INSERT INTO data_queries (study_id, subject_id, visit_id, form_id, field_id, repeating_instance_id, query_text, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, 'new', ?)");
            $stmt->execute([$study_id, $subject_id, $visit_id, $form_id, $field_id, $repeating_instance_id, $query_text, $user_id]);
            $query_id = $pdo->lastInsertId();
            
            // Log History (Initial creation)
            $stmt_h = $pdo->prepare("INSERT INTO data_query_history (query_id, status_from, status_to, remark, created_by) VALUES (?, NULL, 'new', ?, ?)");
            $stmt_h->execute([$query_id, $query_text, $user_id]);
            
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

        if (!$new_status) throw new Exception("Status required");
        
        $pdo->beginTransaction();
        try {
            // Get Current Status
            $stmt_curr = $pdo->prepare("SELECT status FROM data_queries WHERE id = ?");
            $stmt_curr->execute([$query_id]);
            $curr = $stmt_curr->fetch();
            if (!$curr) throw new Exception("Query not found");
            $old_status = $curr['status'];
            
            // Workflow Logic:
            // If replying to a query (adding a remark) without explicit status change, or if Manager replies
            // Manager Replied -> Set to 'answered' (or 'open' if we don't have answered)
            // Monitor Verified -> Set to 'closed'
            
            // For now, trust the frontend passed 'status', but maybe force 'answered' if Manager replies?
            // Let's rely on frontend passing the correct Next Status, but validate here if needed.
            
            // Update Query
            $stmt_upd = $pdo->prepare("UPDATE data_queries SET status = ? WHERE id = ?");
            $stmt_upd->execute([$new_status, $query_id]);
            
            // Log History
            $stmt_h = $pdo->prepare("INSERT INTO data_query_history (query_id, status_from, status_to, remark, created_by) VALUES (?, ?, ?, ?, ?)");
            $stmt_h->execute([$query_id, $old_status, $new_status, $remark, $user_id]);
            
            $pdo->commit();
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    elseif ($action === 'get_query_history') {
        $query_id = $_POST['query_id'];
        $stmt = $pdo->prepare("SELECT h.*, u.username as created_by_name FROM data_query_history h LEFT JOIN users u ON h.created_by = u.id WHERE query_id = ? ORDER BY created_at DESC");
        $stmt->execute([$query_id]);
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
        if (!hasPermission('enter_data') && !hasPermission('edit') && !hasPermission('all')) {
            throw new Exception("Unauthorized: Edit permission required");
        }
        
        $subject_id = $_POST['subject_id'];
        $form_id = $_POST['form_id'];
        $field_id = $_POST['field_id'];
        $repeating_instance_id = (int)($_POST['repeating_instance_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        
        if (!$reason) throw new Exception("Reason required");
        
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
        if (!hasPermission('enter_data') && !hasPermission('edit') && !hasPermission('all')) {
            throw new Exception("Unauthorized: Edit permission required");
        }
        
        $subject_id = $_POST['subject_id'];
        $form_id = $_POST['form_id'];
        $field_id = $_POST['field_id'];
        $repeating_instance_id = (int)($_POST['repeating_instance_id'] ?? 0);
        $code = $_POST['code']; // e.g. -95
        $comment = trim($_POST['comment'] ?? '');
        
        if (!$code) throw new Exception("Missing code required");
        
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
        if (!hasPermission('verify') && !hasPermission('all')) {
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
             
             $pdo->commit();
             echo json_encode(['success' => true]);
        } catch (Exception $e) {
            error_log("Verification Error: " . $e->getMessage());
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
            } catch (Exception $e) {
                // Ignore if exists
            }
            
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
    $stmt_stat_chk = $pdo->prepare("SELECT id, status FROM subject_form_status WHERE subject_id = ? AND form_id = ? AND (repeating_instance_id = ? OR (? = 0 AND repeating_instance_id IS NULL))");
    $stmt_stat_chk->execute([$subject_id, $form_id, $repeating_instance_id, $repeating_instance_id]);
    $stat_row = $stmt_stat_chk->fetch();
    
    // Preserve Verified status
    if ($stat_row && $stat_row['status'] === 'verified') {
        $status = 'verified';
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
?>
