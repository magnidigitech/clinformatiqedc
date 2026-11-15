<?php
// ajax_common_forms.php - Backend API Handler for Subject Common Forms (MH, AE, CM)
require_once 'includes/functions.php';
require_once 'includes/auth.php';
require_once 'includes/ae_helpers.php';

ob_start();
header('Content-Type: application/json');

try {
    requireLogin();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Invalid request method");
    }

    $action = $_POST['action'] ?? '';
    $pdo = getDB();
    $study_id = $_SESSION['active_study_id'] ?? 0;
    $user_id = $_SESSION['user_id'] ?? 0;

    if ($study_id <= 0) {
        throw new Exception("No active study selected.");
    }

    // Lazy load Common Form Tables DDL
    ensureCommonFormsTables($pdo);

    $role_lower = strtolower(trim($_SESSION['active_role_name'] ?? ''));
    $is_admin = isCurrentUserAdmin();
    $is_coordinator = isCoordinatorRole($role_lower) || $is_admin;
    $is_monitor = (strpos($role_lower, 'monitor') !== false) || (strpos($role_lower, 'cra') !== false) || (strpos($role_lower, 'clinical research associate') !== false) || $is_admin;
    $is_manager = (strpos($role_lower, 'manager') !== false) || (strpos($role_lower, 'manage') !== false) || ($role_lower === 'dm') || (strpos($role_lower, 'dm ') !== false) || $is_admin;
    $is_pi = (strpos($role_lower, 'investigator') !== false) || ($role_lower === 'pi') || $is_admin;
    // Strict clinical content editor flag: Data Managers, Data Monitors, and Principal Investigators cannot create, edit, or void clinical content.
    $is_data_editor = ($is_coordinator || $is_admin) && (strpos($role_lower, 'manager') === false) && (strpos($role_lower, 'manage') === false) && ($role_lower !== 'dm') && (strpos($role_lower, 'monitor') === false) && (strpos($role_lower, 'investigator') === false) && ($role_lower !== 'pi');

    // Restrict Data Coordinators to only subjects they created
    $posted_subject_id = (int)($_POST['subject_id'] ?? 0);
    if ($posted_subject_id > 0 && isRestrictedToCreatedSubjects()) {
        $chk_sub = $pdo->prepare("SELECT created_by FROM subjects WHERE id = ? AND study_id = ?");
        $chk_sub->execute([$posted_subject_id, $study_id]);
        $sub_creator = $chk_sub->fetchColumn();
        if ($sub_creator === false || (int)$sub_creator !== (int)$_SESSION['user_id']) {
            throw new Exception("Unauthorized access. You can only view and manage records for subjects created by you.");
        }
    }

    $posted_record_id = (int)($_POST['record_id'] ?? 0);
    if ($posted_record_id > 0 && $posted_subject_id <= 0 && isRestrictedToCreatedSubjects()) {
        $chk_rec = $pdo->prepare("SELECT s.created_by FROM subject_common_records scr JOIN subjects s ON scr.subject_id = s.id WHERE scr.id = ? AND scr.study_id = ?");
        $chk_rec->execute([$posted_record_id, $study_id]);
        $sub_creator = $chk_rec->fetchColumn();
        if ($sub_creator === false || (int)$sub_creator !== (int)$_SESSION['user_id']) {
            throw new Exception("Unauthorized access. You can only view and manage records for subjects created by you.");
        }
    }

    // =========================================================================
    // 1. GET ALL RECORDS FOR A SUBJECT & FORM TYPE
    // =========================================================================
    if ($action === 'get_records') {
        $subject_id = (int)($_POST['subject_id'] ?? 0);
        $form_type = strtoupper(trim($_POST['form_type'] ?? 'MH'));
        $search = trim($_POST['search'] ?? '');
        $status_filter = trim($_POST['status'] ?? '');
        $sdr_filter = trim($_POST['sdr_status'] ?? '');
        $include_voided = !empty($_POST['include_voided']) && $_POST['include_voided'] !== 'false';

        if (!in_array($form_type, ['MH', 'AE', 'CM', 'TA', 'USV'], true)) {
            throw new Exception("Invalid form type");
        }

        // Verify subject belongs to active study
        $stmt_s = $pdo->prepare("SELECT id, subject_code FROM subjects WHERE id = ? AND study_id = ?");
        $stmt_s->execute([$subject_id, $study_id]);
        $subject = $stmt_s->fetch(PDO::FETCH_ASSOC);
        if (!$subject) {
            throw new Exception("Subject not found or unauthorized");
        }

        $sql = "SELECT r.*, 
                       u_c.name as creator_name, u_c.username as creator_user,
                       u_s.name as sdr_user_name, u_s.username as sdr_user_code,
                       u_mon.name as monitor_user_name, u_mon.username as monitor_user_code,
                       u_mgr.name as manager_user_name, u_mgr.username as manager_user_code,
                       u_pi.name as pi_user_name, u_pi.username as pi_user_code,
                       (SELECT COUNT(*) FROM common_form_queries q WHERE q.record_id = r.id AND q.status IN ('open', 'answered')) as open_query_count
                FROM subject_common_records r
                LEFT JOIN users u_c ON r.created_by = u_c.id
                LEFT JOIN users u_s ON r.sdr_by = u_s.id
                LEFT JOIN users u_mon ON r.monitor_by = u_mon.id
                LEFT JOIN users u_mgr ON r.manager_by = u_mgr.id
                LEFT JOIN users u_pi ON r.pi_signed_by = u_pi.id
                WHERE r.study_id = :sid AND r.subject_id = :subid AND r.form_type = :ft";
        
        $params = ['sid' => $study_id, 'subid' => $subject_id, 'ft' => $form_type];

        if (!$include_voided) {
            $sql .= " AND r.is_voided = FALSE";
        }
        if ($status_filter && in_array($status_filter, ['draft', 'complete'], true)) {
            $sql .= " AND r.status = :st";
            $params['st'] = $status_filter;
        }
        if ($sdr_filter) {
            if ($sdr_filter === 'pending') {
                $sql .= " AND (r.sdr_status = 'pending' OR r.sdr_status IS NULL OR r.sdr_status = '')";
            } elseif ($sdr_filter === 'under_review') {
                $sql .= " AND r.sdr_status = 'under_review'";
            } elseif ($sdr_filter === 'sdved' || $sdr_filter === 'reviewed') {
                $sql .= " AND (r.sdr_status = 'sdved' OR r.sdr_status = 'reviewed' OR r.monitor_reviewed = TRUE)";
            } elseif ($sdr_filter === 'dm_reviewed') {
                $sql .= " AND (r.sdr_status = 'dm_reviewed' OR r.manager_reviewed = TRUE)";
            } elseif ($sdr_filter === 'pi_signed_off') {
                $sql .= " AND (r.sdr_status = 'pi_signed_off' OR r.pi_signed_off = TRUE)";
            } elseif ($sdr_filter === 'needs_rereview') {
                $sql .= " AND r.sdr_status = 'needs_rereview'";
            }
        }

        $sql .= " ORDER BY r.seq_number ASC, r.id ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $records = [];
        $ae_code_lists = null;
        if ($form_type === 'AE') {
            $ae_code_lists = getAeCodeLists($pdo, $study_id);
            $today_ist_ae = (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
        }

        foreach ($rows as $r) {
            $data = json_decode($r['data_json'] ?? '{}', true) ?: [];
            if ($form_type === 'AE') {
                $data = normalizeAeRecordData($data);
            }
            
            // Client side search filter
            if ($search !== '') {
                $search_lower = strtolower($search);
                $num_match = strpos(strtolower($r['record_number']), $search_lower) !== false;
                $term_match = false;
                if ($form_type === 'MH' && !empty($data['mh_term'])) $term_match = strpos(strtolower($data['mh_term']), $search_lower) !== false;
                if ($form_type === 'AE' && !empty($data['ae_term'])) $term_match = strpos(strtolower($data['ae_term']), $search_lower) !== false;
                if ($form_type === 'CM' && !empty($data['medication_name'])) $term_match = strpos(strtolower($data['medication_name']), $search_lower) !== false;
                if ($form_type === 'TA') {
                    if (!empty($data['tumor_timepoint']) && strpos(strtolower($data['tumor_timepoint']), $search_lower) !== false) $term_match = true;
                    if (!empty($data['recist_overall_response']) && strpos(strtolower($data['recist_overall_response']), $search_lower) !== false) $term_match = true;
                    if (!empty($data['imaging_modality']) && strpos(strtolower($data['imaging_modality']), $search_lower) !== false) $term_match = true;
                }
                if ($form_type === 'USV') {
                    if (!empty($data['date_of_visit']) && strpos(strtolower($data['date_of_visit']), $search_lower) !== false) $term_match = true;
                    if (!empty($data['visit_reason_unscheduled']) && strpos(strtolower($data['visit_reason_unscheduled']), $search_lower) !== false) $term_match = true;
                    if (!empty($data['visit_comments']) && strpos(strtolower($data['visit_comments']), $search_lower) !== false) $term_match = true;
                }
                
                if (!$num_match && !$term_match) {
                    continue; // skip non-matching
                }
            }

            // Fetch CM links if this is CM record
            $linked_records = [];
            if ($form_type === 'CM') {
                $stmt_l = $pdo->prepare("
                    SELECT target.id, target.record_number, target.form_type, target.is_voided, target.data_json
                    FROM cm_record_links l
                    JOIN subject_common_records target ON l.target_record_id = target.id
                    WHERE l.cm_record_id = ?
                ");
                $stmt_l->execute([$r['id']]);
                $links_raw = $stmt_l->fetchAll(PDO::FETCH_ASSOC);
                foreach ($links_raw as $l_item) {
                    $l_data = json_decode($l_item['data_json'] ?? '{}', true) ?: [];
                    $l_term = $l_item['form_type'] === 'MH' ? ($l_data['mh_term'] ?? '') : ($l_data['ae_term'] ?? '');
                    $linked_records[] = [
                        'id' => (int)$l_item['id'],
                        'record_number' => $l_item['record_number'],
                        'form_type' => $l_item['form_type'],
                        'term' => $l_term,
                        'is_voided' => toBool($l_item['is_voided'])
                    ];
                }
            }

            // Fetch related CM records if this is MH or AE
            $related_cms = [];
            if ($form_type === 'MH' || $form_type === 'AE') {
                $stmt_rel = $pdo->prepare("
                    SELECT cm.id, cm.record_number, cm.data_json
                    FROM cm_record_links l
                    JOIN subject_common_records cm ON l.cm_record_id = cm.id
                    WHERE l.target_record_id = ? AND cm.is_voided = FALSE
                ");
                $stmt_rel->execute([$r['id']]);
                $rel_raw = $stmt_rel->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rel_raw as $rel_item) {
                    $rel_data = json_decode($rel_item['data_json'] ?? '{}', true) ?: [];
                    $related_cms[] = [
                        'id' => (int)$rel_item['id'],
                        'record_number' => $rel_item['record_number'],
                        'medication_name' => $rel_data['medication_name'] ?? ''
                    ];
                }
            }

            $ae_checks = null;
            $ae_reconciliations = [];
            if ($form_type === 'AE') {
                $ae_checks = evaluateAeRecordChecks($data, $ae_code_lists, $today_ist_ae, false);
                $ae_reconciliations = getAeReconciliationItems($data);
            }

            $recItem = [
                'id' => (int)$r['id'],
                'seq_number' => (int)$r['seq_number'],
                'record_number' => $r['record_number'],
                'status' => $r['status'],
                'sdr_status' => $r['sdr_status'],
                'sdr_submitted' => toBool($r['sdr_submitted'] ?? false),
                'monitor_reviewed' => toBool($r['monitor_reviewed'] ?? false),
                'manager_reviewed' => toBool($r['manager_reviewed'] ?? false),
                'sdr_by_name' => $r['sdr_user_name'] ?? $r['sdr_user_code'] ?? '',
                'sdr_at' => $r['sdr_at'] ? date('d-M-Y H:i', strtotime($r['sdr_at'])) : null,
                'monitor_by_name' => $r['monitor_user_name'] ?? $r['monitor_user_code'] ?? '',
                'monitor_at' => $r['monitor_at'] ? date('d-M-Y H:i', strtotime($r['monitor_at'])) : null,
                'manager_by_name' => $r['manager_user_name'] ?? $r['manager_user_code'] ?? '',
                'manager_at' => $r['manager_at'] ? date('d-M-Y H:i', strtotime($r['manager_at'])) : null,
                'pi_signed_off' => toBool($r['pi_signed_off'] ?? false),
                'pi_signed_by_name' => $r['pi_user_name'] ?? $r['pi_user_code'] ?? '',
                'pi_signed_at' => $r['pi_signed_at'] ? date('d-M-Y H:i', strtotime($r['pi_signed_at'])) : null,
                'revision' => (int)$r['revision'],
                'is_voided' => toBool($r['is_voided']),
                'void_reason' => $r['void_reason'] ?? '',
                'data' => $data,
                'created_by' => $r['creator_name'] ?? $r['creator_user'] ?? '',
                'created_at' => date('d-M-Y H:i', strtotime($r['created_at'])),
                'open_queries' => (int)$r['open_query_count'],
                'linked_records' => $linked_records,
                'related_cms' => $related_cms
            ];
            if ($form_type === 'AE') {
                $recItem['ae_checks'] = $ae_checks;
                $recItem['reconciliations'] = $ae_reconciliations;
            }
            $records[] = $recItem;
        }

        // Summary counts
        $stmt_sum = $pdo->prepare("
            SELECT 
                COUNT(*) as total_count,
                SUM(CASE WHEN status = 'complete' THEN 1 ELSE 0 END) as complete_count,
                SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft_count,
                SUM(CASE WHEN sdr_submitted = TRUE THEN 1 ELSE 0 END) as sdr_submitted_count,
                SUM(CASE WHEN monitor_reviewed = TRUE THEN 1 ELSE 0 END) as monitor_reviewed_count,
                SUM(CASE WHEN manager_reviewed = TRUE THEN 1 ELSE 0 END) as manager_reviewed_count,
                SUM(CASE WHEN pi_signed_off = TRUE THEN 1 ELSE 0 END) as pi_signed_off_count,
                SUM(CASE WHEN sdr_status = 'pending' OR sdr_status IS NULL OR sdr_status = '' THEN 1 ELSE 0 END) as sdr_pending_count,
                SUM(CASE WHEN sdr_status = 'under_review' THEN 1 ELSE 0 END) as sdr_under_review_count,
                SUM(CASE WHEN sdr_status = 'sdved' OR sdr_status = 'reviewed' THEN 1 ELSE 0 END) as sdr_sdved_count,
                SUM(CASE WHEN sdr_status = 'pi_signed_off' OR pi_signed_off = TRUE THEN 1 ELSE 0 END) as sdr_pi_signed_count,
                SUM(CASE WHEN sdr_status = 'needs_rereview' THEN 1 ELSE 0 END) as sdr_needs_rereview_count
            FROM subject_common_records
            WHERE study_id = ? AND subject_id = ? AND form_type = ? AND is_voided = FALSE
        ");
        $stmt_sum->execute([$study_id, $subject_id, $form_type]);
        $summary = $stmt_sum->fetch(PDO::FETCH_ASSOC);

        $response = [
            'success' => true,
            'records' => $records,
            'subject_code' => $subject['subject_code'],
            'summary' => [
                'total' => (int)($summary['total_count'] ?? 0),
                'complete' => (int)($summary['complete_count'] ?? 0),
                'draft' => (int)($summary['draft_count'] ?? 0),
                'sdr_submitted' => (int)($summary['sdr_submitted_count'] ?? 0),
                'monitor_reviewed' => (int)($summary['monitor_reviewed_count'] ?? 0),
                'manager_reviewed' => (int)($summary['manager_reviewed_count'] ?? 0),
                'pi_signed_off' => (int)($summary['pi_signed_off_count'] ?? 0),
                'sdr_pending' => (int)($summary['sdr_pending_count'] ?? 0),
                'sdr_under_review' => (int)($summary['sdr_under_review_count'] ?? 0),
                'sdr_sdved' => (int)($summary['sdr_sdved_count'] ?? 0),
                'sdr_reviewed' => (int)($summary['sdr_sdved_count'] ?? 0),
                'sdr_needs_rereview' => (int)($summary['sdr_needs_rereview_count'] ?? 0),
            ]
        ];
        if ($form_type === 'AE') {
            $aeSubjectState = getSubjectAeState($pdo, $study_id, $subject_id);
            $response['ae_meta'] = [
                'code_lists' => $ae_code_lists,
                'field_definitions' => getAeFieldDefinitions($ae_code_lists),
                'seriousness_criteria' => getAeSeriousnessCriteriaDefinitions(),
                'subject_state' => $aeSubjectState,
            ];
        }

        ob_clean();
        echo json_encode($response);
        exit();
    }

    // =========================================================================
    // 1B. SAVE SUBJECT-LEVEL AE OCCURRENCE GATE ("Were any AE experienced?")
    // =========================================================================
    elseif ($action === 'save_ae_occurrence') {
        if (!$is_admin && (!$is_data_editor || (strpos($role_lower, 'manager') !== false) || (strpos($role_lower, 'monitor') !== false) || (strpos($role_lower, 'investigator') !== false) || ($role_lower === 'pi') || (!$is_coordinator && !hasPermission('enter_data') && !hasPermission('edit')))) {
            throw new Exception("Unauthorized: Data Managers, Data Monitors, and Principal Investigators cannot modify AE occurrence status.");
        }

        $subject_id = (int)($_POST['subject_id'] ?? 0);
        $any_experienced = trim($_POST['any_experienced'] ?? '');
        $mode = trim($_POST['mode'] ?? 'draft');
        $change_reason = trim($_POST['change_reason'] ?? 'AE Occurrence Screening Update');

        if (!in_array($any_experienced, ['', 'Yes', 'No'], true)) {
            throw new Exception("Invalid answer for 'Were any adverse events experienced?'. Must be Yes or No.");
        }
        if (!in_array($mode, ['draft', 'complete'], true)) {
            $mode = 'draft';
        }
        if ($subject_id <= 0) {
            throw new Exception("Invalid subject ID");
        }

        $stmt_s = $pdo->prepare("SELECT id, subject_code FROM subjects WHERE id = ? AND study_id = ?");
        $stmt_s->execute([$subject_id, $study_id]);
        $subject = $stmt_s->fetch(PDO::FETCH_ASSOC);
        if (!$subject) {
            throw new Exception("Subject not found");
        }

        $stateBefore = getSubjectAeState($pdo, $study_id, $subject_id);
        if ($stateBefore['pi_signed_off']) {
            throw new Exception("AE Screening Gate has been Signed Off by Principal Investigator and cannot be edited.");
        }
        if ($any_experienced === 'No' && !empty($stateBefore['has_pi_locked_record'])) {
            throw new Exception("Cannot change AE occurrence to No while one or more AE records are Signed Off by Principal Investigator.");
        }

        $stmtActiveRecs = $pdo->prepare("
            SELECT id, record_number, status, data_json
            FROM subject_common_records
            WHERE study_id = ? AND subject_id = ? AND form_type = 'AE' AND is_voided = FALSE
            ORDER BY seq_number ASC, id ASC
        ");
        $stmtActiveRecs->execute([$study_id, $subject_id]);
        $activeRecs = $stmtActiveRecs->fetchAll(PDO::FETCH_ASSOC);
        $activeCount = count($activeRecs);
        $codeLists = getAeCodeLists($pdo, $study_id);

        if ($mode === 'complete') {
            $evalSubj = evaluateSubjectAeChecks($any_experienced, $activeCount, $activeRecs, $codeLists, true);
            if (!$evalSubj['valid']) {
                ob_clean();
                echo json_encode([
                    'success' => false,
                    'message' => $evalSubj['errors'][0]['message'] ?? 'AE form completeness check failed.',
                    'validation_errors' => $evalSubj['errors'],
                    'field_errors' => ['ae_experienced' => $evalSubj['errors'][0]['message'] ?? ''],
                ]);
                exit();
            }
        }

        $pdo->beginTransaction();
        try {
            $stmtLock = $pdo->prepare("SELECT id FROM subjects WHERE id = ? FOR UPDATE");
            $stmtLock->execute([$subject_id]);

            $stmtExisting = $pdo->prepare("SELECT * FROM subject_common_form_status WHERE study_id = ? AND subject_id = ? AND form_type = 'AE' FOR UPDATE");
            $stmtExisting->execute([$study_id, $subject_id]);
            $existing = $stmtExisting->fetch(PDO::FETCH_ASSOC);

            $storedAny = $any_experienced !== '' ? $any_experienced : null;
            if (!$existing) {
                $stmtIns = $pdo->prepare("
                    INSERT INTO subject_common_form_status (study_id, subject_id, form_type, any_experienced, status, sdr_status, revision, created_by)
                    VALUES (?, ?, 'AE', ?, ?, 'pending', 1, ?)
                ");
                $stmtIns->execute([$study_id, $subject_id, $storedAny, $mode, $user_id]);

                $stmtAud = $pdo->prepare("
                    INSERT INTO common_form_audit_log (study_id, subject_id, record_id, form_type, field_name, old_value, new_value, reason_for_change, change_type, action_by)
                    VALUES (?, ?, NULL, 'AE', 'ae_experienced', NULL, ?, ?, 'create', ?)
                ");
                $stmtAud->execute([$study_id, $subject_id, $storedAny ?? '', $change_reason, $user_id]);
            } else {
                $oldVal = $existing['any_experienced'] ?? '';
                $newVal = $storedAny ?? '';
                $hasChange = ($oldVal !== $newVal) || ($existing['status'] !== $mode);
                $newRev = (int)($existing['revision'] ?? 1);
                $newSdrStatus = $existing['sdr_status'] ?? 'pending';
                $newSdrSub = toBool($existing['sdr_submitted'] ?? false);
                $newMonRev = toBool($existing['monitor_reviewed'] ?? false);
                $newMgrRev = toBool($existing['manager_reviewed'] ?? false);

                if ($oldVal !== $newVal && ($newSdrSub || $newMonRev || $newMgrRev)) {
                    $newRev++;
                    $newSdrStatus = 'pending';
                    $newSdrSub = false;
                    $newMonRev = false;
                    $newMgrRev = false;

                    $pdo->prepare("INSERT INTO common_form_sdr_history (record_id, subject_id, form_type, action, reviewed_revision, action_by) VALUES (NULL, ?, 'AE', 'sdr_revoked', ?, ?)")
                        ->execute([$subject_id, $newRev, $user_id]);

                    $pdo->prepare("
                        INSERT INTO common_form_audit_log (study_id, subject_id, record_id, form_type, field_name, old_value, new_value, reason_for_change, change_type, action_by)
                        VALUES (?, ?, NULL, 'AE', 'sdr_status', ?, 'pending', 'SDR Revoked due to AE occurrence update', 'sdr_revoked', ?)
                    ")->execute([$study_id, $subject_id, $existing['sdr_status'], $user_id]);
                }

                $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
                $sdrVal = $newSdrSub ? (($driver === 'pgsql') ? 'true' : '1') : (($driver === 'pgsql') ? 'false' : '0');
                $monVal = $newMonRev ? (($driver === 'pgsql') ? 'true' : '1') : (($driver === 'pgsql') ? 'false' : '0');
                $mgrVal = $newMgrRev ? (($driver === 'pgsql') ? 'true' : '1') : (($driver === 'pgsql') ? 'false' : '0');

                $stmtUpd = $pdo->prepare("
                    UPDATE subject_common_form_status
                    SET any_experienced = ?, status = ?, sdr_status = ?, sdr_submitted = $sdrVal, monitor_reviewed = $monVal, manager_reviewed = $mgrVal, revision = ?, updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $stmtUpd->execute([$storedAny, $mode, $newSdrStatus, $newRev, $existing['id']]);

                if ($hasChange && $oldVal !== $newVal) {
                    $stmtAud = $pdo->prepare("
                        INSERT INTO common_form_audit_log (study_id, subject_id, record_id, form_type, field_name, old_value, new_value, reason_for_change, change_type, action_by)
                        VALUES (?, ?, NULL, 'AE', 'ae_experienced', ?, ?, ?, 'update', ?)
                    ");
                    $stmtAud->execute([$study_id, $subject_id, $oldVal, $newVal, $change_reason, $user_id]);
                }
            }

            syncSubjectAeQueries($pdo, $study_id, $subject_id, $user_id);
            $pdo->commit();

            $updatedState = getSubjectAeState($pdo, $study_id, $subject_id);
            ob_clean();
            echo json_encode([
                'success' => true,
                'subject_state' => $updatedState,
                'conflict_warning' => $updatedState['conflict_occurrence_no_with_records'],
                'message' => $updatedState['conflict_occurrence_no_with_records']
                    ? "Saved, but conflict flagged: {$activeCount} active AE record(s) still exist. Set to Yes or void saved records with an audit reason."
                    : ($mode === 'complete' ? "AE form marked complete." : "AE screening response saved."),
            ]);
            exit();
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // =========================================================================
    // 2. GET LINKABLE RECORDS (ACTIVE MH OR AE RECORDS FOR THIS SUBJECT)
    // =========================================================================
    elseif ($action === 'get_linkable_records') {
        $subject_id = (int)($_POST['subject_id'] ?? 0);
        $target_type = strtoupper(trim($_POST['target_type'] ?? 'MH'));

        if (!in_array($target_type, ['MH', 'AE'], true)) {
            throw new Exception("Invalid target type for linking");
        }

        $stmt = $pdo->prepare("
            SELECT id, record_number, form_type, is_voided, data_json
            FROM subject_common_records
            WHERE study_id = ? AND subject_id = ? AND form_type = ? AND is_voided = FALSE
            ORDER BY seq_number ASC
        ");
        $stmt->execute([$study_id, $subject_id, $target_type]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $linkable = [];
        foreach ($rows as $r) {
            $data = json_decode($r['data_json'] ?? '{}', true) ?: [];
            $term = $target_type === 'MH' ? ($data['mh_term'] ?? '') : ($data['ae_term'] ?? '');
            $linkable[] = [
                'id' => (int)$r['id'],
                'record_number' => $r['record_number'],
                'term' => $term,
                'display' => $r['record_number'] . ' — ' . ($term ?: 'Untitled')
            ];
        }

        ob_clean();
        echo json_encode(['success' => true, 'linkable' => $linkable]);
        exit();
    }

    // =========================================================================
    // 3. GET SINGLE RECORD DETAILS (WITH QUERIES, AUDIT, SDR HISTORY)
    // =========================================================================
    elseif ($action === 'get_record') {
        $record_id = (int)($_POST['record_id'] ?? 0);
        $req_form_type = strtoupper(trim($_POST['form_type'] ?? ''));
        $req_subject_id = (int)($_POST['subject_id'] ?? 0);

        if ($record_id <= 0 && $req_form_type === 'AE' && $req_subject_id > 0) {
            $stmt_sub = $pdo->prepare("SELECT id, subject_code FROM subjects WHERE id = ? AND study_id = ?");
            $stmt_sub->execute([$req_subject_id, $study_id]);
            $subRow = $stmt_sub->fetch(PDO::FETCH_ASSOC);
            if (!$subRow) throw new Exception("Subject not found");

            $aeState = getSubjectAeState($pdo, $study_id, $req_subject_id);
            $aeCodeLists = getAeCodeLists($pdo, $study_id);
            $formattedQueries = [];
            foreach ($aeState['subject_queries'] as $sq) {
                $formattedQueries[] = [
                    'id' => (int)$sq['id'],
                    'field_name' => $sq['field_name'] ?? 'ae_experienced',
                    'rule_code' => $sq['rule_code'] ?? null,
                    'is_system_query' => toBool($sq['is_system_query'] ?? false),
                    'query_text' => $sq['query_text'],
                    'status' => $sq['status'],
                    'created_by' => $sq['created_by_name'] ?? '',
                    'raised_by_role' => $sq['raised_by_role'] ?? 'System Edit Check',
                    'created_at' => date('d-M-Y H:i', strtotime($sq['created_at'])),
                    'history' => array_map(function($h) {
                        return [
                            'action_type' => $h['action_type'],
                            'remark' => $h['remark'],
                            'old_value' => $h['old_value'] ?? null,
                            'new_value' => $h['new_value'] ?? null,
                            'status_from' => $h['status_from'] ?? null,
                            'status_to' => $h['status_to'] ?? null,
                            'user' => $h['user_name'] ?? '',
                            'created_at' => date('d-M-Y H:i', strtotime($h['created_at']))
                        ];
                    }, $sq['history'] ?? [])
                ];
            }
            $formattedAudit = array_map(function($aud) {
                return [
                    'field_name' => $aud['field_name'],
                    'old_value' => $aud['old_value'] ?? '',
                    'new_value' => $aud['new_value'] ?? '',
                    'reason' => $aud['reason_for_change'] ?? '',
                    'change_type' => $aud['change_type'] ?? '',
                    'user' => $aud['user_name'] ?? '',
                    'action_at' => date('d-M-Y H:i', strtotime($aud['action_at']))
                ];
            }, $aeState['subject_audit']);

            ob_clean();
            echo json_encode([
                'success' => true,
                'record' => [
                    'id' => 0,
                    'subject_id' => $req_subject_id,
                    'subject_code' => $subRow['subject_code'],
                    'form_type' => 'AE',
                    'seq_number' => 0,
                    'record_number' => 'AE Screening Gate',
                    'status' => $aeState['status'],
                    'sdr_status' => $aeState['sdr_status'],
                    'sdr_submitted' => $aeState['sdr_submitted'],
                    'monitor_reviewed' => $aeState['monitor_reviewed'],
                    'manager_reviewed' => $aeState['manager_reviewed'],
                    'pi_signed_off' => $aeState['pi_signed_off'],
                    'revision' => $aeState['revision'],
                    'is_voided' => false,
                    'void_reason' => '',
                    'data' => ['ae_experienced' => $aeState['any_experienced'] ?? ''],
                    'queries' => $formattedQueries,
                    'sdr_history' => [],
                    'audit_trail' => $formattedAudit,
                    'ae_meta' => ['code_lists' => $aeCodeLists, 'subject_state' => $aeState],
                ]
            ]);
            exit();
        }

        if ($record_id <= 0) throw new Exception("Invalid record ID");

        $stmt = $pdo->prepare("
            SELECT r.*, 
                   s.subject_code,
                   u_c.name as creator_name, u_c.username as creator_user,
                   u_s.name as sdr_user_name, u_s.username as sdr_user_code,
                   u_mon.name as monitor_user_name, u_mon.username as monitor_user_code,
                   u_mgr.name as manager_user_name, u_mgr.username as manager_user_code,
                   u_pi.name as pi_user_name, u_pi.username as pi_user_code
            FROM subject_common_records r
            JOIN subjects s ON r.subject_id = s.id
            LEFT JOIN users u_c ON r.created_by = u_c.id
            LEFT JOIN users u_s ON r.sdr_by = u_s.id
            LEFT JOIN users u_mon ON r.monitor_by = u_mon.id
            LEFT JOIN users u_mgr ON r.manager_by = u_mgr.id
            LEFT JOIN users u_pi ON r.pi_signed_by = u_pi.id
            WHERE r.id = ? AND r.study_id = ?
        ");
        $stmt->execute([$record_id, $study_id]);
        $rec = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$rec) throw new Exception("Record not found");

        $data = json_decode($rec['data_json'] ?? '{}', true) ?: [];
        $aeMetaForRecord = null;
        if ($rec['form_type'] === 'AE') {
            $data = normalizeAeRecordData($data);
            $aeCodeListsRec = getAeCodeLists($pdo, $study_id);
            $todayIstRec = (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
            $aeMetaForRecord = [
                'code_lists' => $aeCodeListsRec,
                'field_definitions' => getAeFieldDefinitions($aeCodeListsRec),
                'seriousness_criteria' => getAeSeriousnessCriteriaDefinitions(),
                'ae_checks' => evaluateAeRecordChecks($data, $aeCodeListsRec, $todayIstRec, false),
                'reconciliations' => getAeReconciliationItems($data),
                'subject_state' => getSubjectAeState($pdo, $study_id, $rec['subject_id']),
            ];
        }

        // Linked records (for CM)
        $linked_records = [];
        if ($rec['form_type'] === 'CM') {
            $stmt_l = $pdo->prepare("
                SELECT target.id, target.record_number, target.form_type, target.is_voided, target.data_json
                FROM cm_record_links l
                JOIN subject_common_records target ON l.target_record_id = target.id
                WHERE l.cm_record_id = ?
            ");
            $stmt_l->execute([$record_id]);
            $links_raw = $stmt_l->fetchAll(PDO::FETCH_ASSOC);
            foreach ($links_raw as $l_item) {
                $l_data = json_decode($l_item['data_json'] ?? '{}', true) ?: [];
                $l_term = $l_item['form_type'] === 'MH' ? ($l_data['mh_term'] ?? '') : ($l_data['ae_term'] ?? '');
                $linked_records[] = [
                    'id' => (int)$l_item['id'],
                    'record_number' => $l_item['record_number'],
                    'form_type' => $l_item['form_type'],
                    'term' => $l_term,
                    'is_voided' => toBool($l_item['is_voided'])
                ];
            }
        }

        // Queries
        $stmt_q = $pdo->prepare("
            SELECT q.*, u.name as created_by_name, u.username as created_by_user,
                   COALESCE(q.raised_by_role, (SELECT role_name FROM study_users WHERE user_id = q.created_by AND study_id = q.study_id LIMIT 1), 'Data Monitor') AS effective_raised_by_role
            FROM common_form_queries q
            LEFT JOIN users u ON q.created_by = u.id
            WHERE q.record_id = ?
            ORDER BY q.created_at DESC
        ");
        $stmt_q->execute([$record_id]);
        $queries_raw = $stmt_q->fetchAll(PDO::FETCH_ASSOC);
        
        $queries = [];
        foreach ($queries_raw as $q) {
            // History for this query
            $stmt_qh = $pdo->prepare("
                SELECT qh.*, u.name as user_name, u.username as user_code
                FROM common_form_query_history qh
                LEFT JOIN users u ON qh.created_by = u.id
                WHERE qh.query_id = ?
                ORDER BY qh.created_at ASC
            ");
            $stmt_qh->execute([$q['id']]);
            $q_history = $stmt_qh->fetchAll(PDO::FETCH_ASSOC);

            $queries[] = [
                'id' => (int)$q['id'],
                'field_name' => $q['field_name'] ?? '',
                'rule_code' => $q['rule_code'] ?? null,
                'is_system_query' => toBool($q['is_system_query'] ?? false),
                'query_text' => $q['query_text'],
                'status' => $q['status'],
                'created_by' => $q['created_by_name'] ?? $q['created_by_user'] ?? '',
                'raised_by_role' => $q['effective_raised_by_role'] ?? 'Data Monitor',
                'created_at' => date('d-M-Y H:i', strtotime($q['created_at'])),
                'history' => array_map(function($h) {
                    return [
                        'action_type' => $h['action_type'],
                        'remark' => $h['remark'],
                        'old_value' => $h['old_value'] ?? null,
                        'new_value' => $h['new_value'] ?? null,
                        'status_from' => $h['status_from'] ?? null,
                        'status_to' => $h['status_to'] ?? null,
                        'user' => $h['user_name'] ?? $h['user_code'] ?? '',
                        'created_at' => date('d-M-Y H:i', strtotime($h['created_at']))
                    ];
                }, $q_history)
            ];
        }

        // SDR History
        $stmt_sdr = $pdo->prepare("
            SELECT s.*, u.name as user_name, u.username as user_code
            FROM common_form_sdr_history s
            LEFT JOIN users u ON s.action_by = u.id
            WHERE s.record_id = ?
            ORDER BY s.action_at DESC
        ");
        $stmt_sdr->execute([$record_id]);
        $sdr_history = array_map(function($sh) {
            return [
                'action' => $sh['action'],
                'reviewed_revision' => (int)$sh['reviewed_revision'],
                'user' => $sh['user_name'] ?? $sh['user_code'] ?? '',
                'action_at' => date('d-M-Y H:i', strtotime($sh['action_at']))
            ];
        }, $stmt_sdr->fetchAll(PDO::FETCH_ASSOC));

        // Audit Trail
        $stmt_aud = $pdo->prepare("
            SELECT a.*, u.name as user_name, u.username as user_code
            FROM common_form_audit_log a
            LEFT JOIN users u ON a.action_by = u.id
            WHERE a.record_id = ?
            ORDER BY a.action_at DESC, a.id DESC
        ");
        $stmt_aud->execute([$record_id]);
        $audit_trail = array_map(function($aud) {
            return [
                'field_name' => $aud['field_name'],
                'old_value' => $aud['old_value'] ?? '',
                'new_value' => $aud['new_value'] ?? '',
                'reason' => $aud['reason_for_change'] ?? '',
                'change_type' => $aud['change_type'] ?? '',
                'user' => $aud['user_name'] ?? $aud['user_code'] ?? '',
                'action_at' => date('d-M-Y H:i', strtotime($aud['action_at']))
            ];
        }, $stmt_aud->fetchAll(PDO::FETCH_ASSOC));

        $recResponsePayload = [
            'id' => (int)$rec['id'],
            'subject_id' => (int)$rec['subject_id'],
            'subject_code' => $rec['subject_code'],
            'form_type' => $rec['form_type'],
            'seq_number' => (int)$rec['seq_number'],
            'record_number' => $rec['record_number'],
            'status' => $rec['status'],
            'sdr_status' => $rec['sdr_status'],
            'sdr_submitted' => toBool($rec['sdr_submitted'] ?? false),
            'monitor_reviewed' => toBool($rec['monitor_reviewed'] ?? false),
            'manager_reviewed' => toBool($rec['manager_reviewed'] ?? false),
            'pi_signed_off' => toBool($rec['pi_signed_off'] ?? false),
            'sdr_by_name' => $rec['sdr_user_name'] ?? $rec['sdr_user_code'] ?? '',
            'sdr_at' => $rec['sdr_at'] ? date('d-M-Y H:i', strtotime($rec['sdr_at'])) : null,
            'monitor_by_name' => $rec['monitor_user_name'] ?? $rec['monitor_user_code'] ?? '',
            'monitor_at' => $rec['monitor_at'] ? date('d-M-Y H:i', strtotime($rec['monitor_at'])) : null,
            'manager_by_name' => $rec['manager_user_name'] ?? $rec['manager_user_code'] ?? '',
            'manager_at' => $rec['manager_at'] ? date('d-M-Y H:i', strtotime($rec['manager_at'])) : null,
            'pi_signed_by_name' => $rec['pi_user_name'] ?? $rec['pi_user_code'] ?? '',
            'pi_signed_at' => $rec['pi_signed_at'] ? date('d-M-Y H:i', strtotime($rec['pi_signed_at'])) : null,
            'revision' => (int)$rec['revision'],
            'is_voided' => toBool($rec['is_voided']),
            'void_reason' => $rec['void_reason'] ?? '',
            'data' => $data,
            'created_by' => $rec['creator_name'] ?? $rec['creator_user'] ?? '',
            'created_at' => date('d-M-Y H:i', strtotime($rec['created_at'])),
            'linked_records' => $linked_records,
            'queries' => $queries,
            'sdr_history' => $sdr_history,
            'audit_trail' => $audit_trail
        ];
        if ($aeMetaForRecord !== null) {
            $recResponsePayload['ae_meta'] = $aeMetaForRecord;
        }

        ob_clean();
        echo json_encode([
            'success' => true,
            'record' => $recResponsePayload
        ]);
        exit();
    }

    // =========================================================================
    // 4. SAVE RECORD (CREATE OR EDIT - SAVE DRAFT / MARK COMPLETE)
    // =========================================================================
    elseif ($action === 'save_record') {
        if (!$is_admin && (!$is_data_editor || (strpos($role_lower, 'manager') !== false) || (strpos($role_lower, 'monitor') !== false) || (strpos($role_lower, 'investigator') !== false) || ($role_lower === 'pi') || (!$is_coordinator && !hasPermission('enter_data') && !hasPermission('edit')))) {
            throw new Exception("Unauthorized: Data Managers, Data Monitors, and Principal Investigators cannot create or edit clinical records directly.");
        }

        $record_id = (int)($_POST['record_id'] ?? 0);
        $subject_id = (int)($_POST['subject_id'] ?? 0);
        $form_type = strtoupper(trim($_POST['form_type'] ?? 'MH'));
        $mode = trim($_POST['mode'] ?? 'draft'); // 'draft' or 'complete'
        $change_reason = trim($_POST['change_reason'] ?? 'Clinical Data Update');

        if (!in_array($form_type, ['MH', 'AE', 'CM', 'TA', 'USV'], true)) {
            throw new Exception("Invalid form type");
        }
        if (!in_array($mode, ['draft', 'complete'], true)) {
            $mode = 'draft';
        }

        // Verify Subject
        $stmt_s = $pdo->prepare("SELECT id, subject_code FROM subjects WHERE id = ? AND study_id = ?");
        $stmt_s->execute([$subject_id, $study_id]);
        $subject = $stmt_s->fetch(PDO::FETCH_ASSOC);
        if (!$subject) {
            throw new Exception("Subject not found");
        }

        $raw_data = $_POST['data'] ?? [];
        if (is_string($raw_data)) {
            $data = json_decode($raw_data, true) ?: [];
        } else {
            $data = (array)$raw_data;
        }

        $raw_linked = $_POST['linked_ids'] ?? [];
        if (is_string($raw_linked)) {
            $raw_linked = json_decode($raw_linked, true) ?: explode(',', $raw_linked);
        }
        $linked_ids = array_map('intval', (array)$raw_linked);

        $raw_reconciliations = $_POST['reconciliations'] ?? [];
        if (is_string($raw_reconciliations)) {
            $raw_reconciliations = json_decode($raw_reconciliations, true) ?: [];
        }
        $reconcileClears = [];
        foreach ((array)$raw_reconciliations as $recItem) {
            if (!is_array($recItem)) continue;
            $rField = trim((string)($recItem['field'] ?? ''));
            $rAction = trim((string)($recItem['action'] ?? ''));
            $rReason = trim((string)($recItem['reason'] ?? ''));
            if ($rAction === 'clear' && $rField !== '') {
                if ($rReason === '') {
                    throw new Exception("An audit reason is required to reconcile and clear '{$rField}'.");
                }
                $reconcileClears[$rField] = $rReason;
            }
        }

        // Enforce no future date in IST across non-AE Common Forms (AE handles partial-date safe checks below)
        $today_ist = (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
        if ($form_type !== 'AE') {
            foreach (['start_date', 'end_date', 'stop_date', 'imaging_date', 'date_of_visit', 'date_of_death', 'vitals_assessment_date', 'hema_collection_date', 'chem_collection_date', 'drug_admin_date'] as $date_key) {
                if (!empty($data[$date_key])) {
                    $check_date = substr(trim((string)$data[$date_key]), 0, 10);
                    if ($check_date > $today_ist) {
                        throw new Exception("Future Date has been selected, Please Select the Correct Date");
                    }
                }
            }
        }

        $aeCodeLists = null;
        $aeSubjectState = null;
        $aeEval = null;
        $aeOldDataPre = null;
        if ($form_type === 'AE') {
            $aeCodeLists = getAeCodeLists($pdo, $study_id);
            $aeSubjectState = getSubjectAeState($pdo, $study_id, $subject_id);
            if ($aeSubjectState['pi_signed_off']) {
                throw new Exception("AE form has been Signed Off by Principal Investigator and cannot be edited.");
            }

            if (($data['ae_experienced'] ?? '') === 'No') {
                $data['ae_term'] = $data['ae_term'] ?: 'No adverse events experienced';
                $data['ongoing'] = 'No';
                $data['seriousness'] = 'No';
                $data['causality'] = 'No';
                $data['action_taken'] = '';
                $data['other_action_specify'] = '';
                $data['outcome'] = '';
            } elseif (($data['causality'] ?? '') === 'No') {
                $data['action_taken'] = '';
                $data['other_action_specify'] = '';
                $data['outcome'] = '';
            }

            $data = normalizeAeRecordData($data);

            if ($record_id > 0) {
                $stmtPreOld = $pdo->prepare("SELECT * FROM subject_common_records WHERE id = ? AND study_id = ?");
                $stmtPreOld->execute([$record_id, $study_id]);
                $preOldRec = $stmtPreOld->fetch(PDO::FETCH_ASSOC);
                if (!$preOldRec) throw new Exception("Record not found.");
                if (toBool($preOldRec['is_voided'] ?? false)) throw new Exception("Cannot edit a voided record.");
                if (toBool($preOldRec['pi_signed_off'] ?? false)) {
                    throw new Exception("This record has been Signed Off by Principal Investigator and cannot be edited.");
                }
                $aeOldDataPre = normalizeAeRecordData(json_decode($preOldRec['data_json'] ?? '{}', true) ?: []);

                // Protect dependent values from silent deletion when controlling field changes without audited clear
                if ($data['ongoing'] === 'Yes' && $data['end_date'] === '' && $aeOldDataPre['end_date'] !== '') {
                    if (!isset($reconcileClears['end_date'])) {
                        $data['end_date'] = $aeOldDataPre['end_date'];
                    }
                }
                if ($data['seriousness'] === 'No') {
                    foreach (getAeSeriousnessCriteriaDefinitions() as $critDef) {
                        $ck = $critDef['key'];
                        if ($data[$ck] === '' && $aeOldDataPre[$ck] !== '' && !isset($reconcileClears[$ck])) {
                            $data[$ck] = $aeOldDataPre[$ck];
                        }
                    }
                }
                if ($data['action_taken'] !== '' && strcasecmp($data['action_taken'], 'Other') !== 0 && $data['other_action_specify'] === '' && $aeOldDataPre['other_action_specify'] !== '') {
                    if (!isset($reconcileClears['other_action_specify'])) {
                        $data['other_action_specify'] = $aeOldDataPre['other_action_specify'];
                    }
                }
            }

            // Apply explicit audited reconciliation clears
            foreach ($reconcileClears as $clearField => $clearReason) {
                $data[$clearField] = '';
            }

            $aeEval = evaluateAeRecordChecks($data, $aeCodeLists, $today_ist, ($mode === 'complete'));
            if ($mode === 'complete' && !$aeEval['valid']) {
                ob_clean();
                echo json_encode([
                    'success' => false,
                    'message' => $aeEval['all_errors'][0]['message'] ?? 'AE validation failed.',
                    'field_errors' => $aeEval['field_errors'],
                    'validation_errors' => $aeEval['all_errors'],
                    'reconciliations' => getAeReconciliationItems($data),
                ]);
                exit();
            }
        }

        // SERVER SIDE VALIDATION WHEN MODE === 'COMPLETE'
        if ($mode === 'complete') {
            if ($form_type === 'MH') {
                $term = trim($data['mh_term'] ?? '');
                $ongoing = trim($data['ongoing'] ?? '');
                $med_given = trim($data['medication_given'] ?? '');
                $start_date = trim($data['start_date'] ?? '');
                $end_date = trim($data['end_date'] ?? '');

                if ($term === '') throw new Exception("MH Term / Condition is required to mark complete.");
                if ($ongoing === '') throw new Exception("Ongoing / Continuing status is required.");
                if ($med_given === '') throw new Exception("Medication Given status is required.");

                if ($ongoing === 'No') {
                    if ($end_date === '') throw new Exception("End Date is required when Ongoing is No.");
                    if ($start_date !== '' && $end_date < $start_date) {
                        throw new Exception("End Date cannot precede Start Date.");
                    }
                } elseif ($ongoing === 'Yes') {
                    $data['end_date'] = ''; // Ensure cleared when ongoing=Yes
                }
            }
            elseif ($form_type === 'AE') {
                // Validated cleanly above via evaluateAeRecordChecks($data, $aeCodeLists, $today_ist, true)
            }
            elseif ($form_type === 'CM') {
                $med_name = trim($data['medication_name'] ?? '');
                $indication_type = trim($data['indication_type'] ?? '');
                $specify_other = trim($data['specify_other'] ?? '');
                $start_date = trim($data['start_date'] ?? '');
                $stop_date = trim($data['stop_date'] ?? '');

                if ($med_name === '') throw new Exception("Medication Name is required to mark complete.");
                if ($indication_type === '') throw new Exception("Medication Taken For (Indication Type) is required.");

                if ($indication_type === 'Other') {
                    if ($specify_other === '') throw new Exception("Specify Other is required when Medication Taken For is Other.");
                } elseif ($indication_type === 'MH' || $indication_type === 'AE') {
                    if (empty($linked_ids)) {
                        throw new Exception("At least one linked " . $indication_type . " record is required to mark complete.");
                    }
                }

                if ($start_date !== '' && $stop_date !== '' && $stop_date < $start_date) {
                    throw new Exception("Stop Date cannot precede Start Date.");
                }
            }
            elseif ($form_type === 'TA') {
                $tumor_assessed = trim($data['tumor_assessed'] ?? '');
                if ($tumor_assessed === '') throw new Exception("Tumor Assessment Performed status is required to mark complete.");
                if ($tumor_assessed === 'No') {
                    if (empty(trim($data['tumor_not_assessed_reason'] ?? ''))) {
                        throw new Exception("Reason tumor assessment not performed is required.");
                    }
                } else {
                    if (empty(trim($data['tumor_timepoint'] ?? ''))) {
                        throw new Exception("Assessment Time Point is required.");
                    }
                    if (empty(trim($data['imaging_date'] ?? ''))) {
                        throw new Exception("Imaging Assessment Date is required.");
                    }
                    if (empty(trim($data['imaging_modality'] ?? ''))) {
                        throw new Exception("Imaging Modality is required.");
                    }
                }
            }
            elseif ($form_type === 'USV') {
                $visit_performed = trim($data['visit_performed'] ?? '');
                if ($visit_performed === '') {
                    throw new Exception("Visit Performed status is required to mark complete.");
                }
                if ($visit_performed === 'No') {
                    if (empty(trim($data['visit_not_performed_reason'] ?? ''))) {
                        throw new Exception("Reason visit not performed is required.");
                    }
                } else {
                    if (empty(trim($data['date_of_visit'] ?? ''))) {
                        throw new Exception("Date of Visit is required.");
                    }
                    if (empty(trim($data['visit_reason_unscheduled'] ?? ''))) {
                        throw new Exception("Reason for Unscheduled Visit is required.");
                    }
                    if (trim($data['visit_reason_unscheduled'] ?? '') === 'Other' && empty(trim($data['unscheduled_reason_other'] ?? ''))) {
                        throw new Exception("Please specify other reason for unscheduled visit.");
                    }
                }
            }
        }

        // CM SERVER-SIDE BOUNDS CHECK ON LINKED IDS
        if (!empty($linked_ids)) {
            $expected_target_type = trim($data['indication_type'] ?? '');
            if (in_array($expected_target_type, ['MH', 'AE'], true)) {
                $in_ids = implode(',', $linked_ids);
                $stmt_chk_links = $pdo->prepare("
                    SELECT id, subject_id, form_type, is_voided 
                    FROM subject_common_records 
                    WHERE id IN ($in_ids)
                ");
                $stmt_chk_links->execute();
                $valid_targets = $stmt_chk_links->fetchAll(PDO::FETCH_ASSOC);
                
                $valid_target_ids = [];
                foreach ($valid_targets as $vt) {
                    if ((int)$vt['subject_id'] !== $subject_id) {
                        throw new Exception("Security Violation: Cannot link to records from another subject!");
                    }
                    if ($vt['form_type'] !== $expected_target_type) {
                        throw new Exception("Invalid link: Record {$vt['id']} is not of type {$expected_target_type}.");
                    }
                    $valid_target_ids[] = (int)$vt['id'];
                }
                $linked_ids = $valid_target_ids;
            } else {
                $linked_ids = []; // Clear links if indication is 'Other'
            }
        }

        $pdo->beginTransaction();

        try {
            $is_new = ($record_id <= 0);
            $new_status = $mode; // 'draft' or 'complete'
            
            if ($is_new) {
                // ATOMIC SEQUENCE GENERATION SAFE FOR CONCURRENT REQUESTS (Lock subject row for update)
                $stmt_lock = $pdo->prepare("SELECT id FROM subjects WHERE id = ? FOR UPDATE");
                $stmt_lock->execute([$subject_id]);

                $stmt_seq = $pdo->prepare("
                    SELECT COALESCE(MAX(seq_number), 0) + 1 as next_seq
                    FROM subject_common_records
                    WHERE subject_id = ? AND form_type = ?
                ");
                $stmt_seq->execute([$subject_id, $form_type]);
                $seq_number = (int)$stmt_seq->fetchColumn();

                $record_number = $subject['subject_code'] . '-' . $form_type . '-' . str_pad($seq_number, 3, '0', STR_PAD_LEFT);

                $driver_ins = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
                $ret_sql = ($driver_ins === 'pgsql') ? ' RETURNING id' : '';
                $stmt_ins = $pdo->prepare("
                    INSERT INTO subject_common_records (
                        study_id, subject_id, form_type, seq_number, record_number, 
                        status, sdr_status, revision, data_json, created_by
                    ) VALUES (
                        :sid, :subid, :ft, :seq, :rec_num, 
                        :st, 'pending', 1, :json, :uid
                    )$ret_sql
                ");
                $stmt_ins->execute([
                    'sid' => $study_id,
                    'subid' => $subject_id,
                    'ft' => $form_type,
                    'seq' => $seq_number,
                    'rec_num' => $record_number,
                    'st' => $new_status,
                    'json' => json_encode($data),
                    'uid' => $user_id
                ]);

                $target_record_id = ($driver_ins === 'pgsql') ? (int)$stmt_ins->fetchColumn() : (int)$pdo->lastInsertId();

                // Log Audit
                $stmt_aud = $pdo->prepare("
                    INSERT INTO common_form_audit_log (study_id, subject_id, record_id, form_type, field_name, old_value, new_value, reason_for_change, change_type, action_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt_aud->execute([$study_id, $subject_id, $target_record_id, $form_type, 'RECORD_CREATED', null, $record_number, 'Initial Record Creation', 'create', $user_id]);

                if ($form_type === 'AE') {
                    $expVal = (($data['ae_experienced'] ?? '') === 'No') ? 'No' : 'Yes';
                    $pdo->prepare("
                        INSERT INTO subject_common_form_status (study_id, subject_id, form_type, any_experienced, status, sdr_status, revision, created_by)
                        VALUES (?, ?, 'AE', ?, ?, 'pending', 1, ?)
                        ON CONFLICT (study_id, subject_id, form_type) DO UPDATE SET any_experienced = EXCLUDED.any_experienced
                    ")->execute([$study_id, $subject_id, $expVal, $mode, $user_id]);

                    $stmt_aud->execute([$study_id, $subject_id, null, 'AE', 'ae_experienced', null, $expVal, 'Set on AE record creation', 'create', $user_id]);
                }

            } else {
                $target_record_id = $record_id;
                
                // Fetch Existing Record
                $stmt_old = $pdo->prepare("SELECT * FROM subject_common_records WHERE id = ? AND study_id = ? FOR UPDATE");
                $stmt_old->execute([$target_record_id, $study_id]);
                $old_rec = $stmt_old->fetch(PDO::FETCH_ASSOC);

                if (toBool($old_rec['is_voided'] ?? false)) throw new Exception("Cannot edit a voided record.");
                if (toBool($old_rec['pi_signed_off'] ?? false)) {
                    throw new Exception("This record has been Signed Off by Principal Investigator and cannot be edited.");
                }

                $old_data = json_decode($old_rec['data_json'] ?? '{}', true) ?: [];
                if ($form_type === 'AE') {
                    $old_data = normalizeAeRecordData($old_data);
                }
                $old_sdr_status = $old_rec['sdr_status'];
                $old_revision = (int)$old_rec['revision'];

                // Audit Changed Fields
                $stmt_aud = $pdo->prepare("
                    INSERT INTO common_form_audit_log (study_id, subject_id, record_id, form_type, field_name, old_value, new_value, reason_for_change, change_type, action_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                $all_keys = array_unique(array_merge(array_keys($old_data), array_keys($data)));
                $has_clinical_change = false;

                foreach ($all_keys as $k) {
                    $val_old = is_array($old_data[$k] ?? null) ? implode(', ', $old_data[$k]) : (string)($old_data[$k] ?? '');
                    $val_new = is_array($data[$k] ?? null) ? implode(', ', $data[$k]) : (string)($data[$k] ?? '');

                    if ($val_old !== $val_new) {
                        $has_clinical_change = true;
                        if (isset($reconcileClears[$k])) {
                            $stmt_aud->execute([$study_id, $subject_id, $target_record_id, $form_type, $k, $val_old, $val_new, "Audited Reconciliation Clear: " . $reconcileClears[$k], 'reconcile_clear', $user_id]);
                        } else {
                            $stmt_aud->execute([$study_id, $subject_id, $target_record_id, $form_type, $k, $val_old, $val_new, $change_reason, 'update', $user_id]);
                        }
                    }
                }

                // Check link changes for CM
                if ($form_type === 'CM') {
                    $stmt_old_links = $pdo->prepare("SELECT target_record_id FROM cm_record_links WHERE cm_record_id = ?");
                    $stmt_old_links->execute([$target_record_id]);
                    $old_linked_ids = array_map('intval', $stmt_old_links->fetchAll(PDO::FETCH_COLUMN));
                    sort($old_linked_ids);
                    
                    $curr_linked_ids = $linked_ids;
                    sort($curr_linked_ids);

                    if ($old_linked_ids !== $curr_linked_ids) {
                        $has_clinical_change = true;
                        $stmt_aud->execute([
                            $study_id, $subject_id, $target_record_id, 'LINKED_RECORDS', 
                            implode(',', $old_linked_ids), implode(',', $curr_linked_ids), 
                            $change_reason, 'update', $user_id
                        ]);
                    }
                }

                $new_revision = $old_revision;
                $new_sdr_status = $old_sdr_status;
                $new_sdr_submitted = toBool($old_rec['sdr_submitted'] ?? false);
                $new_monitor_reviewed = toBool($old_rec['monitor_reviewed'] ?? false);
                $new_manager_reviewed = toBool($old_rec['manager_reviewed'] ?? false);

                // SDR STATUS TRANSITION IF CLINICAL DATA CHANGED AFTER SDR SUBMISSION OR REVIEW
                $was_submitted_or_reviewed = $new_sdr_submitted || $new_monitor_reviewed || $new_manager_reviewed || in_array($old_sdr_status, ['under_review', 'sdved', 'reviewed'], true);
                if ($has_clinical_change && $was_submitted_or_reviewed) {
                    $new_revision = $old_revision + 1;
                    $new_sdr_status = 'pending';
                    $new_sdr_submitted = false;
                    $new_monitor_reviewed = false;
                    $new_manager_reviewed = false;

                    // Log SDR Status Update History
                    $stmt_sdr_hist = $pdo->prepare("
                        INSERT INTO common_form_sdr_history (record_id, action, reviewed_revision, action_by)
                        VALUES (?, 'sdr_revoked', ?, ?)
                    ");
                    $stmt_sdr_hist->execute([$target_record_id, $new_revision, $user_id]);

                    $stmt_aud->execute([
                        $study_id, $subject_id, $target_record_id, 'sdr_status',
                        $old_sdr_status, 'pending', 'SDR Revoked due to record update', 'sdr_revoked', $user_id
                    ]);
                }

                $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
                $sdr_val = $new_sdr_submitted ? (($driver === 'pgsql') ? 'true' : '1') : (($driver === 'pgsql') ? 'false' : '0');
                $mon_val = $new_monitor_reviewed ? (($driver === 'pgsql') ? 'true' : '1') : (($driver === 'pgsql') ? 'false' : '0');
                $mgr_val = $new_manager_reviewed ? (($driver === 'pgsql') ? 'true' : '1') : (($driver === 'pgsql') ? 'false' : '0');

                // Update Record
                $stmt_upd = $pdo->prepare("
                    UPDATE subject_common_records 
                    SET status = :st, 
                        sdr_status = :sdrst, 
                        sdr_submitted = $sdr_val, 
                        monitor_reviewed = $mon_val, 
                        manager_reviewed = $mgr_val, 
                        revision = :rev, 
                        data_json = :json, 
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id
                ");
                $stmt_upd->execute([
                    'st' => $new_status,
                    'sdrst' => $new_sdr_status,
                    'rev' => $new_revision,
                    'json' => json_encode($data),
                    'id' => $target_record_id
                ]);
            }

            // PERSIST CM LINKS IN JUNCTION TABLE
            if ($form_type === 'CM') {
                $stmt_del_links = $pdo->prepare("DELETE FROM cm_record_links WHERE cm_record_id = ?");
                $stmt_del_links->execute([$target_record_id]);

                if (!empty($linked_ids)) {
                    $stmt_ins_link = $pdo->prepare("INSERT INTO cm_record_links (cm_record_id, target_record_id) VALUES (?, ?) ON CONFLICT DO NOTHING");
                    foreach ($linked_ids as $tid) {
                        $stmt_ins_link->execute([$target_record_id, $tid]);
                    }
                }
            }

            if ($form_type === 'AE') {
                $wasSerious = ($aeOldDataPre && ($aeOldDataPre['seriousness'] ?? '') === 'Yes');
                if (($data['seriousness'] ?? '') === 'Yes' && ($is_new || !$wasSerious)) {
                    $pdo->prepare("
                        INSERT INTO common_form_audit_log (study_id, subject_id, record_id, form_type, field_name, old_value, new_value, reason_for_change, change_type, action_by)
                        VALUES (?, ?, ?, 'AE', 'SAE_SAFETY_NOTICE', ?, 'Yes', ?, 'sae_flag', ?)
                    ")->execute([
                        $study_id,
                        $subject_id,
                        $target_record_id,
                        $aeOldDataPre['seriousness'] ?? null,
                        'Event flagged Serious — follow external SAE reporting/pharmacovigilance SOP (UI seriousness fields alone do not send external SAE notifications).',
                        $user_id,
                    ]);
                }
                $aeEval = syncAeRecordEditCheckQueries($pdo, $study_id, $subject_id, $target_record_id, $data, $user_id, $aeCodeLists, $today_ist);
                syncSubjectAeQueries($pdo, $study_id, $subject_id, $user_id);
            }

            $pdo->commit();

            $saveResp = [
                'success' => true,
                'record_id' => $target_record_id,
                'status' => $new_status,
                'message' => $is_new ? "Record created successfully." : "Record updated successfully."
            ];
            if ($form_type === 'AE') {
                $saveResp['ae_checks'] = $aeEval;
                $saveResp['reconciliations'] = getAeReconciliationItems($data);
                $saveResp['sae_flagged'] = (($data['seriousness'] ?? '') === 'Yes');
                $saveResp['subject_state'] = getSubjectAeState($pdo, $study_id, $subject_id);
            }

            ob_clean();
            echo json_encode($saveResp);
            exit();

        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // =========================================================================
    // 5. UPDATE REVIEW STATUS (3 ROLES WORKFLOW)
    // =========================================================================
    elseif ($action === 'mark_sdr' || $action === 'update_review_status') {
        $record_id = (int)($_POST['record_id'] ?? 0);
        $workflow_action = trim($_POST['workflow_action'] ?? $_POST['sdr_action'] ?? '');

        // Map legacy action names
        if ($workflow_action === 'under_review') $workflow_action = 'mark_sdr';
        elseif ($workflow_action === 'sdved' || $workflow_action === 'reviewed') {
            if ($is_monitor) $workflow_action = 'monitor_review';
            elseif ($is_manager) $workflow_action = 'manager_review';
            else $workflow_action = 'monitor_review';
        }
        elseif ($workflow_action === 'pending') {
            $workflow_action = 'revoke_sdr';
        }

        // Subject-level AE Gate Review Workflow (used when AE screening is No, or overall AE gate sign-off)
        $req_form_type = strtoupper(trim($_POST['form_type'] ?? ''));
        $req_subject_id = (int)($_POST['subject_id'] ?? 0);
        if ($record_id <= 0 && $req_form_type === 'AE' && $req_subject_id > 0) {
            $aeState = getSubjectAeState($pdo, $study_id, $req_subject_id);
            if (empty($aeState['id'])) {
                throw new Exception("Please save 'Were any adverse events experienced?' first.");
            }
            if ($aeState['conflict_occurrence_no_with_records']) {
                throw new Exception("Cannot update AE review status while 'Were any adverse events experienced?' = No conflicts with active AE records.");
            }

            $sdr_submitted = $aeState['sdr_submitted'];
            $monitor_reviewed = $aeState['monitor_reviewed'];
            $manager_reviewed = $aeState['manager_reviewed'];
            $pi_signed_off = $aeState['pi_signed_off'];
            $curr_revision = $aeState['revision'];
            $curr_user_id = $user_id > 0 ? $user_id : (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 1);

            if ($pi_signed_off && $workflow_action !== 'pi_revoke') {
                throw new Exception("AE Screening Gate has been Signed Off by Principal Investigator. No changes are permitted.");
            }

            $pdo->beginTransaction();
            try {
                $audit_logs = [];
                $sdr_hist_action = '';

                if ($workflow_action === 'mark_sdr') {
                    if (!$is_coordinator) throw new Exception("Unauthorized: Only Data Coordinators can mark AE form as SDR.");
                    if ($sdr_submitted) throw new Exception("AE form gate is already marked as SDR.");
                    if ($aeState['status'] !== 'complete') throw new Exception("Cannot mark as SDR: Mark the AE Screening Form complete first.");
                    if ($aeState['has_draft_record']) throw new Exception("Cannot mark as SDR: All active AE records must be marked complete first.");
                    $sdr_submitted = true;
                    $sdr_hist_action = 'mark_sdr';
                    $audit_logs[] = ['sdr_status', $aeState['sdr_status'], 'under_review', 'Subject AE Gate marked as SDR', 'sdr_submitted'];
                } elseif ($workflow_action === 'revoke_sdr') {
                    if (!$is_coordinator) throw new Exception("Unauthorized: Only Data Coordinators can revoke SDR.");
                    if (!$sdr_submitted) throw new Exception("AE form gate is not marked as SDR.");
                    if ($monitor_reviewed || $manager_reviewed || $pi_signed_off) throw new Exception("Cannot revoke SDR: Already reviewed or signed off.");
                    $sdr_submitted = false;
                    $sdr_hist_action = 'revoke_sdr';
                    $audit_logs[] = ['sdr_status', $aeState['sdr_status'], 'pending', 'Subject AE Gate SDR Revoked', 'sdr_revoked'];
                } elseif ($workflow_action === 'monitor_review') {
                    if (!$is_monitor) throw new Exception("Unauthorized: Only Data Monitors can mark as SDVed.");
                    if ($monitor_reviewed) throw new Exception("AE form gate is already marked as SDVed.");
                    if ($aeState['open_subject_queries'] > 0) throw new Exception("Cannot mark as SDVed: Resolve open AE screening queries first.");
                    $stmt_oq = $pdo->prepare("SELECT COUNT(*) FROM common_form_queries q JOIN subject_common_records r ON q.record_id = r.id WHERE r.study_id = ? AND r.subject_id = ? AND r.form_type = 'AE' AND r.is_voided = FALSE AND q.status != 'closed'");
                    $stmt_oq->execute([$study_id, $req_subject_id]);
                    if ((int)$stmt_oq->fetchColumn() > 0) throw new Exception("Cannot mark as SDVed: Resolve open AE record queries first.");
                    $monitor_reviewed = true;
                    $sdr_submitted = true;
                    $sdr_hist_action = 'monitor_review';
                    $audit_logs[] = ['monitor_reviewed', 'false', 'true', 'Subject AE Gate marked as SDVed by Data Monitor', 'monitor_reviewed'];
                } elseif ($workflow_action === 'monitor_revoke') {
                    if (!$is_monitor) throw new Exception("Unauthorized: Only Data Monitors can revoke SDVed.");
                    if (!$monitor_reviewed) throw new Exception("SDV review has not been completed.");
                    $remarks = trim($_POST['remarks'] ?? '') ?: 'SDV Revoked by Data Monitor';
                    $sdr_submitted = false; $monitor_reviewed = false; $manager_reviewed = false;
                    $sdr_hist_action = 'monitor_revoke';
                    $audit_logs[] = ['monitor_reviewed', 'true', 'false', $remarks, 'monitor_revoked'];
                } elseif ($workflow_action === 'manager_review') {
                    if (!$is_manager) throw new Exception("Unauthorized: Only Data Managers can mark as DM Reviewed.");
                    if ($manager_reviewed) throw new Exception("AE form gate is already marked as DM Reviewed.");
                    if ($aeState['open_subject_queries'] > 0) throw new Exception("Cannot mark as DM Reviewed: Resolve open AE screening queries first.");
                    $manager_reviewed = true;
                    $sdr_submitted = true;
                    $sdr_hist_action = 'manager_review';
                    $audit_logs[] = ['manager_reviewed', 'false', 'true', 'Subject AE Gate marked as DM Reviewed by Data Manager', 'manager_reviewed'];
                } elseif ($workflow_action === 'manager_revoke') {
                    if (!$is_manager) throw new Exception("Unauthorized: Only Data Managers can revoke DM Reviewed.");
                    if (!$manager_reviewed) throw new Exception("DM Review has not been completed.");
                    $remarks = trim($_POST['remarks'] ?? '') ?: 'DM Review Revoked by Data Manager';
                    $sdr_submitted = false; $monitor_reviewed = false; $manager_reviewed = false;
                    $sdr_hist_action = 'manager_revoke';
                    $audit_logs[] = ['manager_reviewed', 'true', 'false', $remarks, 'manager_revoked'];
                } elseif ($workflow_action === 'pi_sign_off') {
                    if (!$is_pi) throw new Exception("Unauthorized: Only Principal Investigators can perform PI Sign Off.");
                    if (!$monitor_reviewed || !$manager_reviewed) throw new Exception("Cannot sign off: Must be both SDVed and DM Reviewed first.");
                    if ($pi_signed_off) throw new Exception("AE form gate is already PI Signed Off.");
                    if ($aeState['open_subject_queries'] > 0) throw new Exception("Cannot sign off: Resolve open AE screening queries first.");
                    $pi_signed_off = true;
                    $sdr_hist_action = 'pi_sign_off';
                    $audit_logs[] = ['pi_signed_off', 'false', 'true', 'Subject AE Gate PI Sign Off completed', 'pi_signed_off'];
                } elseif ($workflow_action === 'pi_revoke') {
                    if (!$is_pi) throw new Exception("Unauthorized: Only Principal Investigators can revoke PI Sign Off.");
                    if (!$pi_signed_off) throw new Exception("PI Sign Off has not been completed.");
                    $remarks = trim($_POST['remarks'] ?? '') ?: 'PI Sign Off Revoked by Principal Investigator';
                    $pi_signed_off = false; $sdr_submitted = true; $monitor_reviewed = true; $manager_reviewed = true;
                    $sdr_hist_action = 'pi_revoke';
                    $audit_logs[] = ['pi_signed_off', 'true', 'false', $remarks, 'pi_revoked'];
                } else {
                    throw new Exception("Invalid workflow action: " . htmlspecialchars($workflow_action));
                }

                $final_sdr_status = 'pending';
                if ($pi_signed_off) $final_sdr_status = 'pi_signed_off';
                elseif ($monitor_reviewed && $manager_reviewed) $final_sdr_status = 'sdved_and_dm_reviewed';
                elseif ($monitor_reviewed) $final_sdr_status = 'sdved';
                elseif ($manager_reviewed) $final_sdr_status = 'dm_reviewed';
                elseif ($sdr_submitted) $final_sdr_status = 'under_review';

                $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
                $sdr_val = $sdr_submitted ? (($driver === 'pgsql') ? 'true' : '1') : (($driver === 'pgsql') ? 'false' : '0');
                $mon_val = $monitor_reviewed ? (($driver === 'pgsql') ? 'true' : '1') : (($driver === 'pgsql') ? 'false' : '0');
                $mgr_val = $manager_reviewed ? (($driver === 'pgsql') ? 'true' : '1') : (($driver === 'pgsql') ? 'false' : '0');
                $pi_val = $pi_signed_off ? (($driver === 'pgsql') ? 'true' : '1') : (($driver === 'pgsql') ? 'false' : '0');

                $pdo->prepare("
                    UPDATE subject_common_form_status
                    SET sdr_submitted = $sdr_val,
                        monitor_reviewed = $mon_val,
                        manager_reviewed = $mgr_val,
                        pi_signed_off = $pi_val,
                        pi_signed_by = CASE WHEN $pi_val THEN COALESCE(pi_signed_by, ?) ELSE NULL END,
                        pi_signed_at = CASE WHEN $pi_val THEN COALESCE(pi_signed_at, CURRENT_TIMESTAMP) ELSE NULL END,
                        monitor_reviewed_by = CASE WHEN $mon_val THEN COALESCE(monitor_reviewed_by, ?) ELSE NULL END,
                        monitor_reviewed_at = CASE WHEN $mon_val THEN COALESCE(monitor_reviewed_at, CURRENT_TIMESTAMP) ELSE NULL END,
                        manager_reviewed_by = CASE WHEN $mgr_val THEN COALESCE(manager_reviewed_by, ?) ELSE NULL END,
                        manager_reviewed_at = CASE WHEN $mgr_val THEN COALESCE(manager_reviewed_at, CURRENT_TIMESTAMP) ELSE NULL END,
                        sdr_status = ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ")->execute([$curr_user_id, $curr_user_id, $curr_user_id, $final_sdr_status, $aeState['id']]);

                if ($sdr_hist_action) {
                    $pdo->prepare("INSERT INTO common_form_sdr_history (record_id, subject_id, form_type, action, reviewed_revision, action_by) VALUES (NULL, ?, 'AE', ?, ?, ?)")
                        ->execute([$req_subject_id, $sdr_hist_action, $curr_revision, $curr_user_id]);
                }
                $stmtAudGate = $pdo->prepare("INSERT INTO common_form_audit_log (study_id, subject_id, record_id, form_type, field_name, old_value, new_value, reason_for_change, change_type, action_by) VALUES (?, ?, NULL, 'AE', ?, ?, ?, ?, ?, ?)");
                foreach ($audit_logs as $log) {
                    $stmtAudGate->execute([$study_id, $req_subject_id, $log[0], $log[1], $log[2], $log[3], $log[4], $curr_user_id]);
                }

                $pdo->commit();
                ob_clean();
                echo json_encode([
                    'success' => true,
                    'workflow_action' => $workflow_action,
                    'sdr_submitted' => $sdr_submitted,
                    'monitor_reviewed' => $monitor_reviewed,
                    'manager_reviewed' => $manager_reviewed,
                    'pi_signed_off' => $pi_signed_off,
                    'sdr_status' => $final_sdr_status,
                    'form_type' => 'AE',
                    'message' => 'AE Screening Gate review status updated successfully.'
                ]);
                exit();
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
        }

        if ($record_id <= 0) throw new Exception("Invalid record ID");

        $stmt = $pdo->prepare("SELECT * FROM subject_common_records WHERE id = ?");
        $stmt->execute([$record_id]);
        $rec = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$rec) throw new Exception("Record not found");
        if ($study_id > 0 && (int)$rec['study_id'] !== (int)$study_id) {
            throw new Exception("Unauthorized: Record belongs to another study.");
        }
        if (toBool($rec['is_voided'] ?? false)) throw new Exception("Cannot update review status on a voided record.");

        $sdr_submitted = toBool($rec['sdr_submitted'] ?? false);
        $monitor_reviewed = toBool($rec['monitor_reviewed'] ?? false);
        $manager_reviewed = toBool($rec['manager_reviewed'] ?? false);
        $pi_signed_off = toBool($rec['pi_signed_off'] ?? false);
        $curr_revision = (int)($rec['revision'] ?? 1);
        $curr_user_id = $user_id > 0 ? $user_id : (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 1);

        if ($pi_signed_off && $workflow_action !== 'pi_revoke') {
            throw new Exception("This record has been Signed Off by Principal Investigator. No changes or revocations are permitted for any other roles.");
        }

        $pdo->beginTransaction();
        try {
            $audit_logs = []; // [field_name, old_value, new_value, reason, change_type]
            $sdr_hist_action = '';

            if ($workflow_action === 'mark_sdr') {
                if (!$is_coordinator) {
                    throw new Exception("Unauthorized: Only Data Coordinators can mark a form as SDR.");
                }
                if ($sdr_submitted) {
                    throw new Exception("Record is already marked as SDR.");
                }
                if ($rec['status'] !== 'complete') {
                    throw new Exception("Cannot mark as SDR: Record must be marked complete first.");
                }
                if ($rec['form_type'] === 'AE') {
                    $aeStateRec = getSubjectAeState($pdo, $rec['study_id'], $rec['subject_id']);
                    if ($aeStateRec['conflict_occurrence_no_with_records']) {
                        throw new Exception("Cannot mark as SDR: Resolve 'Were any adverse events experienced?' = No conflict first.");
                    }
                    $aeEvalRec = evaluateAeRecordChecks(json_decode($rec['data_json'] ?? '{}', true) ?: [], getAeCodeLists($pdo, $rec['study_id']), null, true);
                    if (!$aeEvalRec['valid']) {
                        throw new Exception("Cannot mark as SDR: AE record has unresolved edit check errors (" . ($aeEvalRec['all_errors'][0]['message'] ?? 'Invalid') . ").");
                    }
                }

                $sdr_submitted = true;
                $sdr_hist_action = 'mark_sdr';
                $audit_logs[] = ['sdr_status', $rec['sdr_status'] ?? 'pending', 'under_review', 'Form marked as SDR', 'sdr_submitted'];

            } elseif ($workflow_action === 'revoke_sdr') {
                if (!$is_coordinator) {
                    throw new Exception("Unauthorized: Only Data Coordinators can revoke SDR.");
                }
                if (!$sdr_submitted) {
                    throw new Exception("Record is not marked as SDR.");
                }
                if ($monitor_reviewed || $manager_reviewed || $pi_signed_off) {
                    throw new Exception("Cannot revoke SDR: The form is locked because it has already been reviewed or signed off.");
                }

                $sdr_submitted = false;
                $sdr_hist_action = 'revoke_sdr';
                $audit_logs[] = ['sdr_status', $rec['sdr_status'] ?? 'under_review', 'pending', 'SDR Revoked', 'sdr_revoked'];

            } elseif ($workflow_action === 'monitor_review') {
                if (!$is_monitor) {
                    throw new Exception("Unauthorized: Only Data Monitors can mark as SDVed.");
                }
                if ($monitor_reviewed) {
                    throw new Exception("Record is already marked as SDVed.");
                }

                // Check open/unresolved queries
                $stmt_open_q = $pdo->prepare("SELECT COUNT(*) FROM common_form_queries WHERE record_id = ? AND status != 'closed'");
                $stmt_open_q->execute([$record_id]);
                if ((int)$stmt_open_q->fetchColumn() > 0) {
                    throw new Exception("Cannot mark as SDVed: All open queries on this form must be answered and closed before SDV verification.");
                }
                if ($rec['form_type'] === 'AE') {
                    $aeStateMon = getSubjectAeState($pdo, $rec['study_id'], $rec['subject_id']);
                    if ($aeStateMon['conflict_occurrence_no_with_records'] || $aeStateMon['open_subject_queries'] > 0) {
                        throw new Exception("Cannot mark as SDVed: Resolve open subject-level AE occurrence conflicts/queries first.");
                    }
                }

                $monitor_reviewed = true;
                $sdr_submitted = true;
                $rec['monitor_by'] = $curr_user_id;
                $rec['monitor_at'] = date('Y-m-d H:i:s');
                $sdr_hist_action = 'monitor_review';
                $audit_logs[] = ['monitor_reviewed', 'false', 'true', 'Marked as SDVed by Data Monitor', 'monitor_reviewed'];

            } elseif ($workflow_action === 'monitor_revoke') {
                if (!$is_monitor) {
                    throw new Exception("Unauthorized: Only Data Monitors can revoke SDVed.");
                }
                if (!$monitor_reviewed) {
                    throw new Exception("SDV review has not been completed.");
                }

                $remarks = trim($_POST['remarks'] ?? '');
                if ($remarks === '') {
                    $remarks = 'SDV Revoked by Data Monitor';
                }
                if (mb_strlen($remarks) > 500) {
                    $remarks = mb_substr($remarks, 0, 500);
                }

                $sdr_submitted = false;
                $monitor_reviewed = false;
                $manager_reviewed = false;
                $sdr_hist_action = 'monitor_revoke';
                $audit_logs[] = ['monitor_reviewed', 'true', 'false', $remarks, 'monitor_revoked'];
                $audit_logs[] = ['sdr_status', $rec['sdr_status'], 'pending', 'SDR Revoked due to SDVed Revocation. Remarks: ' . $remarks, 'sdr_revoked'];

            } elseif ($workflow_action === 'manager_review') {
                if (!$is_manager) {
                    throw new Exception("Unauthorized: Only Data Managers can mark as DM Reviewed.");
                }
                if ($manager_reviewed) {
                    throw new Exception("Record is already marked as DM Reviewed.");
                }

                $manager_reviewed = true;
                $sdr_submitted = true;
                $rec['manager_by'] = $curr_user_id;
                $rec['manager_at'] = date('Y-m-d H:i:s');
                $sdr_hist_action = 'manager_review';
                $audit_logs[] = ['manager_reviewed', 'false', 'true', 'Marked as DM Reviewed by Data Manager', 'manager_reviewed'];

            } elseif ($workflow_action === 'manager_revoke') {
                if (!$is_manager) {
                    throw new Exception("Unauthorized: Only Data Managers can revoke DM Reviewed.");
                }
                if (!$manager_reviewed) {
                    throw new Exception("DM Review has not been completed.");
                }

                $remarks = trim($_POST['remarks'] ?? '');
                if ($remarks === '') {
                    $remarks = 'DM Review Revoked by Data Manager';
                }
                if (mb_strlen($remarks) > 500) {
                    $remarks = mb_substr($remarks, 0, 500);
                }

                $sdr_submitted = false;
                $monitor_reviewed = false;
                $manager_reviewed = false;
                $sdr_hist_action = 'manager_revoke';
                $audit_logs[] = ['manager_reviewed', 'true', 'false', $remarks, 'manager_revoked'];
                $audit_logs[] = ['sdr_status', $rec['sdr_status'], 'pending', 'SDR Revoked due to DM Reviewed Revocation. Remarks: ' . $remarks, 'sdr_revoked'];

            } elseif ($workflow_action === 'pi_sign_off') {
                if (!$is_pi) {
                    throw new Exception("Unauthorized: Only Principal Investigators can perform PI Sign Off.");
                }
                if (!$monitor_reviewed || !$manager_reviewed) {
                    throw new Exception("Cannot sign off: Record must be both SDVed and DM Reviewed before Principal Investigator sign off.");
                }
                if ($pi_signed_off) {
                    throw new Exception("Record is already signed off by Principal Investigator.");
                }

                // Check open queries on this record
                $stmt_open_q = $pdo->prepare("SELECT COUNT(*) FROM common_form_queries WHERE record_id = ? AND status != 'closed'");
                $stmt_open_q->execute([$record_id]);
                if ((int)$stmt_open_q->fetchColumn() > 0) {
                    throw new Exception("Cannot sign off: All open queries on this record must be answered and closed before PI Sign Off.");
                }

                $pi_signed_off = true;
                $rec['pi_signed_by'] = $curr_user_id;
                $rec['pi_signed_at'] = date('Y-m-d H:i:s');
                $sdr_hist_action = 'pi_sign_off';
                $audit_logs[] = ['pi_signed_off', 'false', 'true', 'PI Sign Off completed by Principal Investigator', 'pi_signed_off'];

            } elseif ($workflow_action === 'pi_revoke') {
                if (!$is_pi) {
                    throw new Exception("Unauthorized: Only Principal Investigators can revoke PI Sign Off.");
                }
                if (!$pi_signed_off) {
                    throw new Exception("PI Sign Off has not been completed.");
                }

                $remarks = trim($_POST['remarks'] ?? '');
                if ($remarks === '') {
                    $remarks = 'PI Sign Off Revoked by Principal Investigator';
                }
                if (mb_strlen($remarks) > 500) {
                    $remarks = mb_substr($remarks, 0, 500);
                }

                // If the PI Sign Off is Revoked it goes back to previous stage of SDVed & DM Reviewed
                $pi_signed_off = false;
                $sdr_submitted = true;
                $monitor_reviewed = true;
                $manager_reviewed = true;
                $sdr_hist_action = 'pi_revoke';
                $audit_logs[] = ['pi_signed_off', 'true', 'false', 'PI Sign Off Revoked. Remarks: ' . $remarks, 'pi_revoked'];

            } else {
                throw new Exception("Invalid workflow action: " . htmlspecialchars($workflow_action));
            }

            $was_srved = $rec && toBool($rec['monitor_reviewed']) && toBool($rec['manager_reviewed']);
            $is_srved = $monitor_reviewed && $manager_reviewed;
            if ($is_srved && !$was_srved) {
                $audit_logs[] = ['sdr_status', $rec['sdr_status'] ?? '', 'sdved', 'SDVed & DM Reviewed', 'form_srved'];
            }

            $final_sdr_status = 'pending';
            if ($pi_signed_off) {
                $final_sdr_status = 'pi_signed_off';
            } elseif ($monitor_reviewed && $manager_reviewed) {
                $final_sdr_status = 'sdved_and_dm_reviewed';
            } elseif ($monitor_reviewed) {
                $final_sdr_status = 'sdved';
            } elseif ($manager_reviewed) {
                $final_sdr_status = 'dm_reviewed';
            } elseif ($sdr_submitted) {
                $final_sdr_status = 'under_review';
            }

            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $sdr_val = $sdr_submitted ? (($driver === 'pgsql') ? 'true' : '1') : (($driver === 'pgsql') ? 'false' : '0');
            $mon_val = $monitor_reviewed ? (($driver === 'pgsql') ? 'true' : '1') : (($driver === 'pgsql') ? 'false' : '0');
            $mgr_val = $manager_reviewed ? (($driver === 'pgsql') ? 'true' : '1') : (($driver === 'pgsql') ? 'false' : '0');
            $pi_val = $pi_signed_off ? (($driver === 'pgsql') ? 'true' : '1') : (($driver === 'pgsql') ? 'false' : '0');

            $stmt_upd = $pdo->prepare("
                UPDATE subject_common_records
                SET sdr_submitted = $sdr_val,
                    monitor_reviewed = $mon_val,
                    manager_reviewed = $mgr_val,
                    pi_signed_off = $pi_val,
                    pi_signed_by = :piby,
                    pi_signed_at = :piat,
                    monitor_by = :monby,
                    monitor_at = :monat,
                    manager_by = :mgrby,
                    manager_at = :mgrat,
                    sdr_status = :sdrst,
                    sdr_by = :uid,
                    sdr_at = CURRENT_TIMESTAMP,
                    sdr_revision = :rev,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ");
            $stmt_upd->execute([
                'piby' => $pi_signed_off ? ($rec['pi_signed_by'] ?? $curr_user_id) : null,
                'piat' => $pi_signed_off ? ($rec['pi_signed_at'] ?? date('Y-m-d H:i:s')) : null,
                'monby' => $monitor_reviewed ? ($rec['monitor_by'] ?? $curr_user_id) : null,
                'monat' => $monitor_reviewed ? ($rec['monitor_at'] ?? date('Y-m-d H:i:s')) : null,
                'mgrby' => $manager_reviewed ? ($rec['manager_by'] ?? $curr_user_id) : null,
                'mgrat' => $manager_reviewed ? ($rec['manager_at'] ?? date('Y-m-d H:i:s')) : null,
                'sdrst' => $final_sdr_status,
                'uid' => $curr_user_id,
                'rev' => $curr_revision,
                'id' => $record_id
            ]);

            if ($sdr_hist_action) {
                $stmt_hist = $pdo->prepare("
                    INSERT INTO common_form_sdr_history (record_id, action, reviewed_revision, action_by)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt_hist->execute([$record_id, $sdr_hist_action, $curr_revision, $curr_user_id]);
            }

            // Insert audit logs
            $stmt_aud = $pdo->prepare("
                INSERT INTO common_form_audit_log (study_id, subject_id, record_id, field_name, old_value, new_value, reason_for_change, change_type, action_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            foreach ($audit_logs as $log) {
                $stmt_aud->execute([
                    $rec['study_id'],
                    $rec['subject_id'],
                    $record_id,
                    $log[0],
                    $log[1],
                    $log[2],
                    $log[3],
                    $log[4],
                    $curr_user_id
                ]);
            }

            $pdo->commit();

            $msg = "Review status updated successfully.";
            if ($workflow_action === 'mark_sdr') $msg = "Record marked as SDR (Under Review)";
            elseif ($workflow_action === 'revoke_sdr') $msg = "SDR submission revoked";
            elseif ($workflow_action === 'monitor_review') $msg = "Record marked as SDVed successfully";
            elseif ($workflow_action === 'monitor_revoke') $msg = "SDVed review revoked";
            elseif ($workflow_action === 'manager_review') $msg = "Record marked as DM Reviewed successfully";
            elseif ($workflow_action === 'manager_revoke') $msg = "DM Review revoked";
            elseif ($workflow_action === 'pi_sign_off') $msg = "Record PI Signed Off successfully";
            elseif ($workflow_action === 'pi_revoke') $msg = "PI Sign Off revoked successfully";

            ob_clean();
            echo json_encode([
                'success' => true,
                'workflow_action' => $workflow_action,
                'sdr_submitted' => $sdr_submitted,
                'monitor_reviewed' => $monitor_reviewed,
                'manager_reviewed' => $manager_reviewed,
                'pi_signed_off' => $pi_signed_off,
                'sdr_status' => $final_sdr_status,
                'form_type' => $rec['form_type'],
                'message' => $msg
            ]);
            exit();

        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // =========================================================================
    // 6. VOID RECORD WITH REASON (COORDINATOR / ADMIN)
    // =========================================================================
    elseif ($action === 'void_record') {
        if (!$is_data_editor || (strpos($role_lower, 'manager') !== false) || (strpos($role_lower, 'monitor') !== false) || (strpos($role_lower, 'investigator') !== false) || ($role_lower === 'pi')) {
            throw new Exception("Unauthorized: Data Managers, Data Monitors, and Principal Investigators cannot void clinical records.");
        }
        if (!$is_coordinator && !$is_admin) {
            throw new Exception("Unauthorized: Voiding records requires Data Coordinator or Admin permissions.");
        }

        $record_id = (int)($_POST['record_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');

        if ($record_id <= 0) throw new Exception("Invalid record ID");
        if ($reason === '') throw new Exception("A reason for voiding this record is required.");

        $stmt = $pdo->prepare("SELECT * FROM subject_common_records WHERE id = ? AND study_id = ?");
        $stmt->execute([$record_id, $study_id]);
        $rec = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$rec) throw new Exception("Record not found.");
        if (toBool($rec['is_voided'])) throw new Exception("Record is already voided.");
        if (toBool($rec['pi_signed_off'] ?? false)) throw new Exception("Cannot void a record that has been Signed Off by Principal Investigator.");
        if ($rec['form_type'] === 'AE') {
            $aeStateVoid = getSubjectAeState($pdo, $study_id, $rec['subject_id']);
            if ($aeStateVoid['pi_signed_off']) {
                throw new Exception("Cannot void an AE record when the subject's AE form is Signed Off by Principal Investigator.");
            }
        }

        $pdo->beginTransaction();
        try {
            $stmt_upd = $pdo->prepare("
                UPDATE subject_common_records
                SET is_voided = TRUE, void_reason = :reason, voided_by = :uid, voided_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ");
            $stmt_upd->execute(['reason' => $reason, 'uid' => $user_id, 'id' => $record_id]);

            $stmt_aud = $pdo->prepare("
                INSERT INTO common_form_audit_log (study_id, subject_id, record_id, form_type, field_name, old_value, new_value, reason_for_change, change_type, action_by)
                VALUES (?, ?, ?, ?, 'RECORD_VOIDED', 'Active', 'Voided', ?, 'void', ?)
            ");
            $stmt_aud->execute([$study_id, $rec['subject_id'], $record_id, $rec['form_type'], $reason, $user_id]);

            if ($rec['form_type'] === 'AE') {
                // Close any open/answered system edit-check queries on the voided record
                $stmtSysQ = $pdo->prepare("SELECT id, status, field_name, rule_code FROM common_form_queries WHERE record_id = ? AND is_system_query = TRUE AND status != 'closed'");
                $stmtSysQ->execute([$record_id]);
                foreach ($stmtSysQ->fetchAll(PDO::FETCH_ASSOC) as $sq) {
                    $pdo->prepare("UPDATE common_form_queries SET status = 'closed', updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$sq['id']]);
                    $pdo->prepare("INSERT INTO common_form_query_history (query_id, action_type, remark, status_from, status_to, created_by) VALUES (?, 'closed', ?, ?, 'closed', ?)")
                        ->execute([$sq['id'], "Auto-closed upon record void: " . $reason, $sq['status'], $user_id]);
                }
                syncSubjectAeQueries($pdo, $study_id, $rec['subject_id'], $user_id);
            }

            $pdo->commit();

            $voidResp = ['success' => true, 'message' => "Record {$rec['record_number']} voided successfully."];
            if ($rec['form_type'] === 'AE') {
                $voidResp['subject_state'] = getSubjectAeState($pdo, $study_id, $rec['subject_id']);
            }
            ob_clean();
            echo json_encode($voidResp);
            exit();

        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // =========================================================================
    // 7. RAISE QUERY ON RECORD / FIELD (DATA MONITOR / DATA MANAGER / ADMIN)
    // =========================================================================
    elseif ($action === 'raise_query' || $action === 'add_query') {
        if (!$is_manager && !$is_monitor && !$is_admin && !hasPermission('raise_query')) {
            throw new Exception("Unauthorized: Raising queries requires Data Monitor, Data Manager, or Administrator permissions.");
        }

        $record_id = (int)($_POST['record_id'] ?? 0);
        $req_form_type = strtoupper(trim($_POST['form_type'] ?? ''));
        $req_subject_id = (int)($_POST['subject_id'] ?? 0);
        $field_name = trim($_POST['field_name'] ?? '');
        $query_text = trim($_POST['query_text'] ?? '');

        if ($query_text === '') throw new Exception("Query text cannot be empty.");

        $is_subject_ae_query = ($record_id <= 0 && $req_form_type === 'AE' && $req_subject_id > 0);
        if (!$is_subject_ae_query && $record_id <= 0) throw new Exception("Invalid record ID");

        $target_subject_id = 0;
        $target_form_type = '';

        if ($is_subject_ae_query) {
            $aeSt = getSubjectAeState($pdo, $study_id, $req_subject_id);
            if ($aeSt['pi_signed_off']) {
                throw new Exception("Cannot Raise Query as the AE form has been Signed Off by Principal Investigator");
            }
            if (!$is_admin && $is_monitor && !$is_manager && $aeSt['monitor_reviewed']) {
                throw new Exception("Cannot Raise Query as the AE Screening Gate is marked as SDVed");
            }
            if (!$is_admin && $is_manager && !$is_monitor && $aeSt['manager_reviewed']) {
                throw new Exception("Cannot Raise Query as the AE Screening Gate is marked as DM Reviewed");
            }
            if ($field_name === '') $field_name = 'ae_experienced';
            $stmt_open = $pdo->prepare("SELECT COUNT(*) FROM common_form_queries WHERE study_id = ? AND subject_id = ? AND record_id IS NULL AND form_type = 'AE' AND field_name = ? AND status != 'closed'");
            $stmt_open->execute([$study_id, $req_subject_id, $field_name]);
            if ((int)$stmt_open->fetchColumn() > 0) {
                throw new Exception("Cannot Raise Query as a query is already open on this field");
            }
            $target_subject_id = $req_subject_id;
            $target_form_type = 'AE';
        } else {
            $stmt = $pdo->prepare("SELECT * FROM subject_common_records WHERE id = ? AND study_id = ?");
            $stmt->execute([$record_id, $study_id]);
            $rec = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$rec) throw new Exception("Record not found.");

            if (toBool($rec['pi_signed_off'] ?? false)) {
                throw new Exception("Cannot Raise Query as the form has been Signed Off by Principal Investigator");
            }
            if (!$is_admin && $is_monitor && !$is_manager && toBool($rec['monitor_reviewed'])) {
                throw new Exception("Cannot Raise Query as the form is marked as SDVed");
            }
            if (!$is_admin && $is_manager && !$is_monitor && toBool($rec['manager_reviewed'])) {
                throw new Exception("Cannot Raise Query as the form is marked as DM Reviewed");
            }

            // Server side check if there is an unresolved query on this field / record
            if ($field_name !== '') {
                $stmt_open = $pdo->prepare("SELECT COUNT(*) FROM common_form_queries WHERE record_id = ? AND field_name = ? AND status != 'closed'");
                $stmt_open->execute([$record_id, $field_name]);
                if ((int)$stmt_open->fetchColumn() > 0) {
                    throw new Exception("Cannot Raise Query as a query is open on this field");
                }
            } else {
                $stmt_open = $pdo->prepare("SELECT COUNT(*) FROM common_form_queries WHERE record_id = ? AND (field_name IS NULL OR field_name = '') AND status != 'closed'");
                $stmt_open->execute([$record_id]);
                if ((int)$stmt_open->fetchColumn() > 0) {
                    throw new Exception("Cannot Raise Query as a query is open on this record");
                }
            }
            $target_subject_id = (int)$rec['subject_id'];
            $target_form_type = $rec['form_type'];
        }

        $role_lower = strtolower($_SESSION['active_role_name'] ?? '');
        $raised_by_role = 'Data Monitor';
        if (strpos($role_lower, 'manager') !== false) {
            $raised_by_role = 'Data Manager';
        } elseif (strpos($role_lower, 'monitor') !== false) {
            $raised_by_role = 'Data Monitor';
        } elseif (strpos($role_lower, 'admin') !== false) {
            $raised_by_role = 'Admin';
        } else {
            $raised_by_role = $_SESSION['active_role_name'] ?? 'Data Monitor';
        }

        $pdo->beginTransaction();
        try {
            $driver_q = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $ret_q = ($driver_q === 'pgsql') ? ' RETURNING id' : '';
            $stmt_q = $pdo->prepare("
                INSERT INTO common_form_queries (study_id, subject_id, record_id, form_type, field_name, query_text, status, created_by, raised_by_role)
                VALUES (?, ?, ?, ?, ?, ?, 'open', ?, ?)$ret_q
            ");
            $stmt_q->execute([
                $study_id,
                $target_subject_id,
                $is_subject_ae_query ? null : $record_id,
                $target_form_type,
                $field_name ?: null,
                $query_text,
                $user_id,
                $raised_by_role
            ]);
            $query_id = ($driver_q === 'pgsql') ? (int)$stmt_q->fetchColumn() : (int)$pdo->lastInsertId();

            $stmt_qh = $pdo->prepare("
                INSERT INTO common_form_query_history (query_id, action_type, remark, status_from, status_to, created_by)
                VALUES (?, 'raised', ?, NULL, 'open', ?)
            ");
            $stmt_qh->execute([$query_id, $query_text, $user_id]);

            // Regulatory Audit Trail
            $stmt_aud = $pdo->prepare("
                INSERT INTO common_form_audit_log (study_id, subject_id, record_id, form_type, field_name, old_value, new_value, reason_for_change, change_type, action_by)
                VALUES (?, ?, ?, ?, ?, NULL, NULL, ?, 'query_raised', ?)
            ");
            $stmt_aud->execute([
                $study_id,
                $target_subject_id,
                $is_subject_ae_query ? null : $record_id,
                $target_form_type,
                $field_name ?: 'RECORD_QUERY',
                "Query Raised (" . $query_id . "): " . $query_text,
                $user_id
            ]);

            $pdo->commit();

            ob_clean();
            echo json_encode(['success' => true, 'query_id' => $query_id, 'message' => "Query raised successfully."]);
            exit();

        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // =========================================================================
    // 8. ANSWER QUERY (COORDINATOR / ADMIN)
    // =========================================================================
    elseif ($action === 'answer_query') {
        if (!$is_coordinator && !$is_admin && !hasPermission('enter_data')) {
            throw new Exception("Unauthorized: Answering queries requires Data Coordinator permissions.");
        }

        $query_id = (int)($_POST['query_id'] ?? 0);
        $remark = trim($_POST['remark'] ?? $_POST['response_text'] ?? $_POST['response'] ?? '');
        $proposed_field_value = isset($_POST['field_value']) ? trim($_POST['field_value']) : null;

        if ($query_id <= 0) throw new Exception("Invalid query ID");
        if ($remark === '') throw new Exception("Response remark cannot be empty.");

        $stmt = $pdo->prepare("
            SELECT q.*, r.data_json, r.pi_signed_off, r.form_type AS rec_form_type
            FROM common_form_queries q
            LEFT JOIN subject_common_records r ON q.record_id = r.id
            WHERE q.id = ? AND q.study_id = ?
        ");
        $stmt->execute([$query_id, $study_id]);
        $q = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$q) throw new Exception("Query not found.");

        $eff_form_type = $q['form_type'] ?: ($q['rec_form_type'] ?? '');
        if ($q['record_id'] !== null && toBool($q['pi_signed_off'] ?? false)) {
            throw new Exception("Action prohibited: Record has been Signed Off by Principal Investigator and is completely locked.");
        } elseif ($q['record_id'] === null && $eff_form_type === 'AE') {
            $aeStateAns = getSubjectAeState($pdo, $study_id, $q['subject_id']);
            if ($aeStateAns['pi_signed_off']) {
                throw new Exception("Action prohibited: AE form has been Signed Off by Principal Investigator and is completely locked.");
            }
        }

        $hist_old_val = null;
        $hist_new_val = null;

        // Check if there is a proposed field value update for the queried field
        if ($proposed_field_value !== null && !empty($q['field_name'])) {
            if ($proposed_field_value !== '' && in_array($q['field_name'], ['start_date', 'end_date', 'stop_date', 'imaging_date', 'date_of_visit', 'date_of_death', 'vitals_assessment_date', 'hema_collection_date', 'chem_collection_date', 'drug_admin_date'], true)) {
                $today_ist = (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
                if ($eff_form_type === 'AE') {
                    if (isClinicalDateInFuture($proposed_field_value, $today_ist)) {
                        throw new Exception("Future Date has been selected, Please Select the Correct Date");
                    }
                } else {
                    $check_date = substr(trim($proposed_field_value), 0, 10);
                    if ($check_date > $today_ist) {
                        throw new Exception("Future Date has been selected, Please Select the Correct Date");
                    }
                }
            }

            if ($q['record_id'] === null && $eff_form_type === 'AE' && $q['field_name'] === 'ae_experienced') {
                $aeStateAns = $aeStateAns ?? getSubjectAeState($pdo, $study_id, $q['subject_id']);
                $curr_val = (string)($aeStateAns['saved_any_experienced'] ?? '');
            } else {
                $rec_data = json_decode($q['data_json'] ?? '{}', true) ?: [];
                $curr_val = is_array($rec_data[$q['field_name']] ?? null) ? implode(', ', $rec_data[$q['field_name']]) : (string)($rec_data[$q['field_name']] ?? '');
            }

            if ($proposed_field_value !== '' && $curr_val !== $proposed_field_value) {
                $hist_old_val = $curr_val;
                $hist_new_val = $proposed_field_value;
                // NOTE: When a query is answered, the field value in data_json must remain unchanged
                // until a Data Monitor or Manager explicitly clicks "Close Query".
            }
        }

        $pdo->beginTransaction();
        try {
            $stmt_upd = $pdo->prepare("UPDATE common_form_queries SET status = 'answered', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt_upd->execute([$query_id]);

            $stmt_qh = $pdo->prepare("
                INSERT INTO common_form_query_history (query_id, action_type, remark, old_value, new_value, status_from, status_to, created_by)
                VALUES (?, 'answered', ?, ?, ?, 'open', 'answered', ?)
            ");
            $stmt_qh->execute([$query_id, $remark, $hist_old_val, $hist_new_val, $user_id]);

            // Regulatory Audit Trail
            $stmt_aud = $pdo->prepare("
                INSERT INTO common_form_audit_log (study_id, subject_id, record_id, field_name, old_value, new_value, reason_for_change, change_type, action_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'query_answered', ?)
            ");
            $stmt_aud->execute([
                $study_id,
                $q['subject_id'],
                $q['record_id'],
                $q['field_name'] ?: 'RECORD_QUERY',
                $hist_old_val,
                $hist_new_val,
                "Query Answered (" . $query_id . "): " . $remark,
                $user_id
            ]);

            $pdo->commit();

            ob_clean();
            echo json_encode(['success' => true, 'message' => "Query response submitted."]);
            exit();

        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // =========================================================================
    // 9. CLOSE OR REOPEN QUERY (DATA MONITOR / DATA MANAGER / ADMIN)
    // =========================================================================
    elseif ($action === 'close_query' || $action === 'update_query_status') {
        if (!$is_manager && !$is_monitor && !$is_admin) {
            throw new Exception("Unauthorized: Closing or reopening queries is restricted to Data Monitors, Data Managers, and Administrators.");
        }

        $query_id = (int)($_POST['query_id'] ?? 0);
        $query_action = trim($_POST['query_action'] ?? $_POST['status'] ?? 'close');
        $remark = trim($_POST['remark'] ?? '');

        if ($query_id <= 0) throw new Exception("Invalid query ID");
        if ($query_action === 'reopen' || $query_action === 'open') {
            $new_status = 'open';
        } else {
            $new_status = 'closed';
        }

        $stmt = $pdo->prepare("
            SELECT q.*, r.pi_signed_off, r.form_type AS rec_form_type,
                   COALESCE(q.raised_by_role, (SELECT role_name FROM study_users WHERE user_id = q.created_by AND study_id = q.study_id LIMIT 1), 'Data Monitor') AS effective_raised_by_role
            FROM common_form_queries q 
            LEFT JOIN subject_common_records r ON q.record_id = r.id
            WHERE q.id = ? AND q.study_id = ?
        ");
        $stmt->execute([$query_id, $study_id]);
        $q = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$q) throw new Exception("Query not found.");

        $eff_form_type_close = $q['form_type'] ?: ($q['rec_form_type'] ?? '');
        if ($q['record_id'] !== null && toBool($q['pi_signed_off'] ?? false)) {
            throw new Exception("Action prohibited: Record has been Signed Off by Principal Investigator and is completely locked.");
        } elseif ($q['record_id'] === null && $eff_form_type_close === 'AE') {
            $aeStateClose = getSubjectAeState($pdo, $study_id, $q['subject_id']);
            if ($aeStateClose['pi_signed_off']) {
                throw new Exception("Action prohibited: AE form has been Signed Off by Principal Investigator and is completely locked.");
            }
        }

        $user_role_lower = strtolower($_SESSION['active_role_name'] ?? '');
        $is_curr_monitor = (strpos($user_role_lower, 'monitor') !== false);
        $is_curr_manager = (strpos($user_role_lower, 'manager') !== false);
        $is_curr_admin = (strpos($user_role_lower, 'admin') !== false);

        $raised_role_lower = strtolower($q['effective_raised_by_role'] ?? '');
        $is_query_monitor = (strpos($raised_role_lower, 'monitor') !== false);
        $is_query_manager = (strpos($raised_role_lower, 'manager') !== false);

        if ($new_status === 'closed') {
            if (!$is_curr_monitor && !$is_curr_manager && !$is_curr_admin) {
                throw new Exception("Unauthorized: Closing queries is restricted to Data Monitors, Data Managers, and Administrators.");
            }
            if ($is_query_monitor && !$is_curr_monitor && !$is_curr_admin) {
                throw new Exception("Unauthorized: This query was raised by Data Monitor and can only be closed by a Data Monitor.");
            }
            if ($is_query_manager && !$is_curr_manager && !$is_curr_admin) {
                throw new Exception("Unauthorized: This query was raised by Data Manager and can only be closed by a Data Manager.");
            }
        } elseif ($new_status === 'open') {
            if (!$is_curr_monitor && !$is_curr_manager && !$is_curr_admin) {
                throw new Exception("Unauthorized: Re-querying is restricted to Data Monitors, Data Managers, and Administrators.");
            }
            if ($is_query_monitor && !$is_curr_monitor && !$is_curr_admin) {
                throw new Exception("Unauthorized: This query was raised by Data Monitor and can only be re-queried by a Data Monitor.");
            }
            if ($is_query_manager && !$is_curr_manager && !$is_curr_admin) {
                throw new Exception("Unauthorized: This query was raised by Data Manager and can only be re-queried by a Data Manager.");
            }
        }

        $old_status = $q['status'];
        $record_id = $q['record_id'] !== null ? (int)$q['record_id'] : null;

        $pdo->beginTransaction();
        try {
            $stmt_aud = $pdo->prepare("
                INSERT INTO common_form_audit_log (study_id, subject_id, record_id, form_type, field_name, old_value, new_value, reason_for_change, change_type, action_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            // IF QUERY IS CLOSED: Apply any answered proposed field value update BEFORE verifying system rules
            $ae_needs_record_sync = false;
            $ae_needs_subject_sync = false;
            if ($new_status === 'closed' && !empty($q['field_name'])) {
                $stmt_change = $pdo->prepare("
                    SELECT old_value, new_value 
                    FROM common_form_query_history 
                    WHERE query_id = ? AND status_to = 'answered'
                    ORDER BY id DESC LIMIT 1
                ");
                $stmt_change->execute([$query_id]);
                $val_change = $stmt_change->fetch(PDO::FETCH_ASSOC);

                if (!$val_change || $val_change['new_value'] === null) {
                    $stmt_change = $pdo->prepare("
                        SELECT old_value, new_value 
                        FROM common_form_query_history 
                        WHERE query_id = ? AND new_value IS NOT NULL AND (old_value IS NULL OR old_value != new_value)
                        ORDER BY id DESC LIMIT 1
                    ");
                    $stmt_change->execute([$query_id]);
                    $val_change = $stmt_change->fetch(PDO::FETCH_ASSOC);
                }

                $target_new_val = $val_change['new_value'] ?? null;

                if ($target_new_val !== null && $target_new_val !== '') {
                    if ($record_id !== null && $record_id > 0) {
                        $stmt_rec = $pdo->prepare("SELECT * FROM subject_common_records WHERE id = ? FOR UPDATE");
                        $stmt_rec->execute([$record_id]);
                        $current_rec = $stmt_rec->fetch(PDO::FETCH_ASSOC);

                        if ($current_rec) {
                            $current_data = json_decode($current_rec['data_json'] ?? '{}', true) ?: [];
                            if ($current_rec['form_type'] === 'AE') {
                                $current_data = normalizeAeRecordData($current_data);
                            }
                            $curr_val_db = is_array($current_data[$q['field_name']] ?? null)
                                ? implode(', ', $current_data[$q['field_name']])
                                : (string)($current_data[$q['field_name']] ?? '');

                            if ($curr_val_db !== $target_new_val) {
                                if ($q['field_name'] === 'seriousness_criteria') {
                                    $current_data[$q['field_name']] = array_values(array_filter(array_map('trim', explode(',', (string)$target_new_val))));
                                } else {
                                    $current_data[$q['field_name']] = $target_new_val;
                                }
                                $new_revision = (int)$current_rec['revision'] + 1;

                                $stmt_update_rec = $pdo->prepare("
                                    UPDATE subject_common_records 
                                    SET data_json = :json, 
                                        revision = :rev, 
                                        sdr_submitted = FALSE, 
                                        monitor_reviewed = FALSE, 
                                        manager_reviewed = FALSE, 
                                        sdr_status = 'pending', 
                                        updated_at = CURRENT_TIMESTAMP
                                    WHERE id = :id
                                ");
                                $stmt_update_rec->execute([
                                    'json' => json_encode($current_data),
                                    'rev' => $new_revision,
                                    'id' => $record_id
                                ]);

                                $stmt_aud->execute([
                                    $study_id,
                                    $q['subject_id'],
                                    $record_id,
                                    $current_rec['form_type'],
                                    $q['field_name'],
                                    $curr_val_db,
                                    $target_new_val,
                                    "Updated via query (" . $query_id . ") closure: " . ($remark ?: 'Closed with value update'),
                                    'update',
                                    $user_id
                                ]);

                                $stmt_aud->execute([
                                    $study_id,
                                    $q['subject_id'],
                                    $record_id,
                                    $current_rec['form_type'],
                                    'sdr_status',
                                    $current_rec['sdr_status'],
                                    'pending',
                                    "SDR Revoked: Query #" . $query_id . " closed with updated field value",
                                    'sdr_revoked',
                                    $user_id
                                ]);

                                $pdo->prepare("
                                    INSERT INTO common_form_sdr_history (record_id, action, reviewed_revision, action_by)
                                    VALUES (?, 'sdr_revoked', ?, ?)
                                ")->execute([$record_id, $new_revision, $user_id]);

                                if ($current_rec['form_type'] === 'AE') {
                                    $ae_needs_record_sync = true;
                                    $ae_needs_subject_sync = true;
                                }
                            }
                        }
                    } elseif ($eff_form_type_close === 'AE' && $q['field_name'] === 'ae_experienced' && in_array($target_new_val, ['Yes', 'No'], true)) {
                        $stmtGate = $pdo->prepare("SELECT * FROM subject_common_form_status WHERE study_id = ? AND subject_id = ? AND form_type = 'AE' FOR UPDATE");
                        $stmtGate->execute([$study_id, $q['subject_id']]);
                        $gateRow = $stmtGate->fetch(PDO::FETCH_ASSOC);
                        $oldGateVal = $gateRow ? ($gateRow['any_experienced'] ?? '') : '';
                        if ($oldGateVal !== $target_new_val) {
                            if ($gateRow) {
                                $newGateRev = (int)($gateRow['revision'] ?? 1) + 1;
                                $pdo->prepare("
                                    UPDATE subject_common_form_status
                                    SET any_experienced = ?, sdr_submitted = FALSE, monitor_reviewed = FALSE, manager_reviewed = FALSE, sdr_status = 'pending', revision = ?, updated_at = CURRENT_TIMESTAMP
                                    WHERE id = ?
                                ")->execute([$target_new_val, $newGateRev, $gateRow['id']]);
                            }
                            $stmt_aud->execute([
                                $study_id, $q['subject_id'], null, 'AE', 'ae_experienced', $oldGateVal, $target_new_val,
                                "Updated via query (" . $query_id . ") closure: " . ($remark ?: 'Closed with value update'),
                                'update', $user_id
                            ]);
                            $ae_needs_subject_sync = true;
                        }
                    }
                }
            }

            // Re-verify System Edit Check Query rule before allowing closure
            if ($new_status === 'closed' && toBool($q['is_system_query'] ?? false) && !empty($q['rule_code'])) {
                if ($record_id !== null && $record_id > 0 && $eff_form_type_close === 'AE') {
                    $stmtChkRec = $pdo->prepare("SELECT data_json, is_voided FROM subject_common_records WHERE id = ?");
                    $stmtChkRec->execute([$record_id]);
                    $chkRec = $stmtChkRec->fetch(PDO::FETCH_ASSOC);
                    if ($chkRec && !toBool($chkRec['is_voided'])) {
                        $chkData = normalizeAeRecordData(json_decode($chkRec['data_json'] ?? '{}', true) ?: []);
                        $chkEval = evaluateAeRecordChecks($chkData, getAeCodeLists($pdo, $study_id), null, false);
                        foreach ($chkEval['discrepancy_checks'] as $dc) {
                            if ($dc['rule_code'] === $q['rule_code']) {
                                throw new Exception("Cannot close system edit-check query ({$q['rule_code']}): {$dc['message']}");
                            }
                        }
                    }
                } elseif ($q['rule_code'] === 'AE_OCCURRENCE_CONFLICT') {
                    $chkAeState = getSubjectAeState($pdo, $study_id, $q['subject_id']);
                    if ($chkAeState['conflict_occurrence_no_with_records']) {
                        throw new Exception("Cannot close system edit-check query (AE_OCCURRENCE_CONFLICT): 'Were any adverse events experienced?' = No still conflicts with saved AE record(s).");
                    }
                }
            }

            $stmt_upd = $pdo->prepare("UPDATE common_form_queries SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt_upd->execute([$new_status, $query_id]);

            $stmt_qh = $pdo->prepare("
                INSERT INTO common_form_query_history (query_id, action_type, remark, status_from, status_to, created_by)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt_qh->execute([
                $query_id, 
                $new_status === 'closed' ? 'closed' : 'reopened', 
                $remark ?: ($new_status === 'closed' ? 'Query closed' : 'Query reopened'), 
                $old_status, 
                $new_status, 
                $user_id
            ]);

            $reason = ($new_status === 'closed')
                ? "Query (" . $query_id . ") Closed: " . ($remark ?: 'Closed by reviewer')
                : "Requery (" . $query_id . "): " . ($remark ?: 'Reopened by reviewer');
            $change_type = ($new_status === 'closed') ? 'query_closed' : 'query_reopened';

            $stmt_aud->execute([
                $study_id,
                $q['subject_id'],
                $record_id,
                $eff_form_type_close ?: null,
                $q['field_name'] ?: 'RECORD_QUERY',
                null,
                null,
                $reason,
                $change_type,
                $user_id
            ]);

            if ($ae_needs_record_sync && $record_id > 0) {
                $stmtLatest = $pdo->prepare("SELECT data_json FROM subject_common_records WHERE id = ?");
                $stmtLatest->execute([$record_id]);
                $latestData = json_decode($stmtLatest->fetchColumn() ?: '{}', true) ?: [];
                syncAeRecordEditCheckQueries($pdo, $study_id, $q['subject_id'], $record_id, $latestData, $user_id);
            }
            if ($ae_needs_subject_sync) {
                syncSubjectAeQueries($pdo, $study_id, $q['subject_id'], $user_id);
            }

            $pdo->commit();

            ob_clean();
            echo json_encode(['success' => true, 'message' => "Query status updated to " . $new_status]);
            exit();

        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    else {
        throw new Exception("Unknown action: " . htmlspecialchars($action));
    }

} catch (Exception $e) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit();
}
