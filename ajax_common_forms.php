<?php
// ajax_common_forms.php - Backend API Handler for Subject Common Forms (MH, AE, CM)
require_once 'includes/functions.php';
require_once 'includes/auth.php';

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

    $role_lower = strtolower($_SESSION['active_role_name'] ?? '');
    $is_coordinator = (strpos($role_lower, 'coordinator') !== false) || (strpos($role_lower, 'admin') !== false) || (strpos($role_lower, 'entry') !== false) || (strpos($role_lower, 'investigator') !== false);
    $is_manager = (strpos($role_lower, 'manager') !== false) || (strpos($role_lower, 'admin') !== false) || (strpos($role_lower, 'monitor') !== false);
    $is_admin = (strpos($role_lower, 'admin') !== false);

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

        if (!in_array($form_type, ['MH', 'AE', 'CM'], true)) {
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
                       (SELECT COUNT(*) FROM common_form_queries q WHERE q.record_id = r.id AND q.status IN ('open', 'answered')) as open_query_count
                FROM subject_common_records r
                LEFT JOIN users u_c ON r.created_by = u_c.id
                LEFT JOIN users u_s ON r.sdr_by = u_s.id
                WHERE r.study_id = :sid AND r.subject_id = :subid AND r.form_type = :ft";
        
        $params = ['sid' => $study_id, 'subid' => $subject_id, 'ft' => $form_type];

        if (!$include_voided) {
            $sql .= " AND r.is_voided = FALSE";
        }
        if ($status_filter && in_array($status_filter, ['draft', 'complete'], true)) {
            $sql .= " AND r.status = :st";
            $params['st'] = $status_filter;
        }
        if ($sdr_filter && in_array($sdr_filter, ['pending', 'reviewed', 'needs_rereview'], true)) {
            $sql .= " AND r.sdr_status = :sdrst";
            $params['sdrst'] = $sdr_filter;
        }

        $sql .= " ORDER BY r.seq_number ASC, r.id ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $records = [];
        foreach ($rows as $r) {
            $data = json_decode($r['data_json'] ?? '{}', true) ?: [];
            
            // Client side search filter
            if ($search !== '') {
                $search_lower = strtolower($search);
                $num_match = strpos(strtolower($r['record_number']), $search_lower) !== false;
                $term_match = false;
                if ($form_type === 'MH' && !empty($data['mh_term'])) $term_match = strpos(strtolower($data['mh_term']), $search_lower) !== false;
                if ($form_type === 'AE' && !empty($data['ae_term'])) $term_match = strpos(strtolower($data['ae_term']), $search_lower) !== false;
                if ($form_type === 'CM' && !empty($data['medication_name'])) $term_match = strpos(strtolower($data['medication_name']), $search_lower) !== false;
                
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
                        'is_voided' => (bool)$l_item['is_voided']
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

            $records[] = [
                'id' => (int)$r['id'],
                'seq_number' => (int)$r['seq_number'],
                'record_number' => $r['record_number'],
                'status' => $r['status'],
                'sdr_status' => $r['sdr_status'],
                'sdr_by_name' => $r['sdr_user_name'] ?? $r['sdr_user_code'] ?? '',
                'sdr_at' => $r['sdr_at'] ? date('d-M-Y H:i', strtotime($r['sdr_at'])) : null,
                'revision' => (int)$r['revision'],
                'is_voided' => (bool)$r['is_voided'],
                'void_reason' => $r['void_reason'] ?? '',
                'data' => $data,
                'created_by' => $r['creator_name'] ?? $r['creator_user'] ?? '',
                'created_at' => date('d-M-Y H:i', strtotime($r['created_at'])),
                'open_queries' => (int)$r['open_query_count'],
                'linked_records' => $linked_records,
                'related_cms' => $related_cms
            ];
        }

        // Summary counts
        $stmt_sum = $pdo->prepare("
            SELECT 
                COUNT(*) as total_count,
                SUM(CASE WHEN status = 'complete' THEN 1 ELSE 0 END) as complete_count,
                SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft_count,
                SUM(CASE WHEN sdr_status = 'pending' THEN 1 ELSE 0 END) as sdr_pending_count,
                SUM(CASE WHEN sdr_status = 'reviewed' THEN 1 ELSE 0 END) as sdr_reviewed_count,
                SUM(CASE WHEN sdr_status = 'needs_rereview' THEN 1 ELSE 0 END) as sdr_needs_rereview_count
            FROM subject_common_records
            WHERE study_id = ? AND subject_id = ? AND form_type = ? AND is_voided = FALSE
        ");
        $stmt_sum->execute([$study_id, $subject_id, $form_type]);
        $summary = $stmt_sum->fetch(PDO::FETCH_ASSOC);

        ob_clean();
        echo json_encode([
            'success' => true,
            'records' => $records,
            'subject_code' => $subject['subject_code'],
            'summary' => [
                'total' => (int)($summary['total_count'] ?? 0),
                'complete' => (int)($summary['complete_count'] ?? 0),
                'draft' => (int)($summary['draft_count'] ?? 0),
                'sdr_pending' => (int)($summary['sdr_pending_count'] ?? 0),
                'sdr_reviewed' => (int)($summary['sdr_reviewed_count'] ?? 0),
                'sdr_needs_rereview' => (int)($summary['sdr_needs_rereview_count'] ?? 0),
            ]
        ]);
        exit();
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
        if ($record_id <= 0) throw new Exception("Invalid record ID");

        $stmt = $pdo->prepare("
            SELECT r.*, 
                   s.subject_code,
                   u_c.name as creator_name, u_c.username as creator_user,
                   u_s.name as sdr_user_name, u_s.username as sdr_user_code
            FROM subject_common_records r
            JOIN subjects s ON r.subject_id = s.id
            LEFT JOIN users u_c ON r.created_by = u_c.id
            LEFT JOIN users u_s ON r.sdr_by = u_s.id
            WHERE r.id = ? AND r.study_id = ?
        ");
        $stmt->execute([$record_id, $study_id]);
        $rec = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$rec) throw new Exception("Record not found");

        $data = json_decode($rec['data_json'] ?? '{}', true) ?: [];

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
                    'is_voided' => (bool)$l_item['is_voided']
                ];
            }
        }

        // Queries
        $stmt_q = $pdo->prepare("
            SELECT q.*, u.name as created_by_name, u.username as created_by_user
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
                'query_text' => $q['query_text'],
                'status' => $q['status'],
                'created_by' => $q['created_by_name'] ?? $q['created_by_user'] ?? '',
                'created_at' => date('d-M-Y H:i', strtotime($q['created_at'])),
                'history' => array_map(function($h) {
                    return [
                        'action_type' => $h['action_type'],
                        'remark' => $h['remark'],
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
            ORDER BY a.action_at DESC
        ");
        $stmt_aud->execute([$record_id]);
        $audit_trail = array_map(function($aud) {
            return [
                'field_name' => $aud['field_name'],
                'old_value' => $aud['old_value'] ?? '',
                'new_value' => $aud['new_value'] ?? '',
                'reason' => $aud['reason_for_change'] ?? '',
                'user' => $aud['user_name'] ?? $aud['user_code'] ?? '',
                'action_at' => date('d-M-Y H:i', strtotime($aud['action_at']))
            ];
        }, $stmt_aud->fetchAll(PDO::FETCH_ASSOC));

        ob_clean();
        echo json_encode([
            'success' => true,
            'record' => [
                'id' => (int)$rec['id'],
                'subject_id' => (int)$rec['subject_id'],
                'subject_code' => $rec['subject_code'],
                'form_type' => $rec['form_type'],
                'seq_number' => (int)$rec['seq_number'],
                'record_number' => $rec['record_number'],
                'status' => $rec['status'],
                'sdr_status' => $rec['sdr_status'],
                'sdr_by_name' => $rec['sdr_user_name'] ?? $rec['sdr_user_code'] ?? '',
                'sdr_at' => $rec['sdr_at'] ? date('d-M-Y H:i', strtotime($rec['sdr_at'])) : null,
                'revision' => (int)$rec['revision'],
                'is_voided' => (bool)$rec['is_voided'],
                'void_reason' => $rec['void_reason'] ?? '',
                'data' => $data,
                'created_by' => $rec['creator_name'] ?? $rec['creator_user'] ?? '',
                'created_at' => date('d-M-Y H:i', strtotime($rec['created_at'])),
                'linked_records' => $linked_records,
                'queries' => $queries,
                'sdr_history' => $sdr_history,
                'audit_trail' => $audit_trail
            ]
        ]);
        exit();
    }

    // =========================================================================
    // 4. SAVE RECORD (CREATE OR EDIT - SAVE DRAFT / MARK COMPLETE)
    // =========================================================================
    elseif ($action === 'save_record') {
        if (!$is_coordinator && !$is_admin && !hasPermission('enter_data') && !hasPermission('edit')) {
            throw new Exception("Unauthorized: Enter Data / Edit permission required.");
        }

        $record_id = (int)($_POST['record_id'] ?? 0);
        $subject_id = (int)($_POST['subject_id'] ?? 0);
        $form_type = strtoupper(trim($_POST['form_type'] ?? 'MH'));
        $mode = trim($_POST['mode'] ?? 'draft'); // 'draft' or 'complete'
        $change_reason = trim($_POST['change_reason'] ?? 'Clinical Data Update');

        if (!in_array($form_type, ['MH', 'AE', 'CM'], true)) {
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
        $linked_ids = array_values(array_filter($linked_ids, function($id) { return $id > 0; }));

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
                $term = trim($data['ae_term'] ?? '');
                $severity = trim($data['severity'] ?? '');
                $seriousness = trim($data['seriousness'] ?? '');
                $causality = trim($data['causality'] ?? '');
                $action_taken = trim($data['action_taken'] ?? '');
                $outcome = trim($data['outcome'] ?? '');
                $concomitant_treatment = trim($data['concomitant_treatment'] ?? '');
                $start_date = trim($data['start_date'] ?? '');
                $end_date = trim($data['end_date'] ?? '');

                if ($term === '') throw new Exception("AE Term / Verbatim is required to mark complete.");
                if ($severity === '') throw new Exception("Severity / Intensity is required.");
                if ($seriousness === '') throw new Exception("Seriousness status is required.");
                
                if ($seriousness === 'Yes') {
                    $crit = (array)($data['seriousness_criteria'] ?? []);
                    if (empty($crit)) throw new Exception("At least one Seriousness Criteria is required when Seriousness is Yes.");
                }

                if ($causality === '') throw new Exception("Causality / Relationship to Study Drug is required.");
                if ($action_taken === '') throw new Exception("Action Taken with Study Treatment is required.");
                if ($outcome === '') throw new Exception("Outcome is required.");
                if ($concomitant_treatment === '') throw new Exception("Other Action Taken / Concomitant Treatment Given is required.");

                if ($outcome === 'Recovered / Resolved') {
                    if ($end_date === '') throw new Exception("End Date is required when Outcome is Recovered / Resolved.");
                    if ($start_date !== '' && $end_date < $start_date) {
                        throw new Exception("End Date cannot precede Start Date.");
                    }
                } elseif (in_array($outcome, ['Recovering / Resolving', 'Not Recovered / Not Resolved'], true)) {
                    $data['end_date'] = '';
                } elseif ($start_date !== '' && $end_date !== '' && $end_date < $start_date) {
                    throw new Exception("End Date cannot precede Start Date.");
                }
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

                $stmt_ins = $pdo->prepare("
                    INSERT INTO subject_common_records (
                        study_id, subject_id, form_type, seq_number, record_number, 
                        status, sdr_status, revision, data_json, created_by
                    ) VALUES (
                        :sid, :subid, :ft, :seq, :rec_num, 
                        :st, 'pending', 1, :json, :uid
                    )
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

                $target_record_id = (int)$pdo->lastInsertId();

                // Log Audit
                $stmt_aud = $pdo->prepare("
                    INSERT INTO common_form_audit_log (study_id, subject_id, record_id, field_name, old_value, new_value, reason_for_change, action_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt_aud->execute([$study_id, $subject_id, $target_record_id, 'RECORD_CREATED', null, $record_number, 'Initial Record Creation', $user_id]);

            } else {
                $target_record_id = $record_id;
                
                // Fetch Existing Record
                $stmt_old = $pdo->prepare("SELECT * FROM subject_common_records WHERE id = ? AND study_id = ? FOR UPDATE");
                $stmt_old->execute([$target_record_id, $study_id]);
                $old_rec = $stmt_old->fetch(PDO::FETCH_ASSOC);

                if (!$old_rec) throw new Exception("Record not found.");
                if ($old_rec['is_voided']) throw new Exception("Cannot edit a voided record.");

                $old_data = json_decode($old_rec['data_json'] ?? '{}', true) ?: [];
                $old_sdr_status = $old_rec['sdr_status'];
                $old_revision = (int)$old_rec['revision'];

                // Audit Changed Fields
                $stmt_aud = $pdo->prepare("
                    INSERT INTO common_form_audit_log (study_id, subject_id, record_id, field_name, old_value, new_value, reason_for_change, action_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");

                $all_keys = array_unique(array_merge(array_keys($old_data), array_keys($data)));
                $has_clinical_change = false;

                foreach ($all_keys as $k) {
                    $val_old = is_array($old_data[$k] ?? null) ? implode(', ', $old_data[$k]) : (string)($old_data[$k] ?? '');
                    $val_new = is_array($data[$k] ?? null) ? implode(', ', $data[$k]) : (string)($data[$k] ?? '');

                    if ($val_old !== $val_new) {
                        $has_clinical_change = true;
                        $stmt_aud->execute([$study_id, $subject_id, $target_record_id, $k, $val_old, $val_new, $change_reason, $user_id]);
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
                            $change_reason, $user_id
                        ]);
                    }
                }

                $new_revision = $old_revision;
                $new_sdr_status = $old_sdr_status;

                // SDR STATUS TRANSITION IF CLINICAL DATA CHANGED AFTER SDR REVIEW
                if ($has_clinical_change && $old_sdr_status === 'reviewed') {
                    $new_revision = $old_revision + 1;
                    $new_sdr_status = 'needs_rereview';

                    // Log SDR Status Update History
                    $stmt_sdr_hist = $pdo->prepare("
                        INSERT INTO common_form_sdr_history (record_id, action, reviewed_revision, action_by)
                        VALUES (?, 'needs_rereview', ?, ?)
                    ");
                    $stmt_sdr_hist->execute([$target_record_id, $new_revision, $user_id]);
                }

                // Update Record
                $stmt_upd = $pdo->prepare("
                    UPDATE subject_common_records 
                    SET status = :st, sdr_status = :sdrst, revision = :rev, data_json = :json, updated_at = CURRENT_TIMESTAMP
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

            $pdo->commit();

            ob_clean();
            echo json_encode([
                'success' => true,
                'record_id' => $target_record_id,
                'status' => $new_status,
                'message' => $is_new ? "Record created successfully." : "Record updated successfully."
            ]);
            exit();

        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // =========================================================================
    // 5. MARK SDR STATUS (DATA MANAGER / ADMIN ONLY)
    // =========================================================================
    elseif ($action === 'mark_sdr') {
        if (!$is_manager) {
            throw new Exception("Unauthorized: SDR marking is restricted to Data Managers and Administrators.");
        }

        $record_id = (int)($_POST['record_id'] ?? 0);
        $sdr_action = trim($_POST['sdr_action'] ?? 'reviewed'); // 'reviewed' or 'needs_rereview'

        if ($record_id <= 0) throw new Exception("Invalid record ID");
        if (!in_array($sdr_action, ['reviewed', 'needs_rereview', 'pending'], true)) {
            $sdr_action = 'reviewed';
        }

        $stmt = $pdo->prepare("SELECT * FROM subject_common_records WHERE id = ? AND study_id = ?");
        $stmt->execute([$record_id, $study_id]);
        $rec = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$rec) throw new Exception("Record not found");
        if ($rec['is_voided']) throw new Exception("Cannot mark SDR on a voided record.");

        $curr_revision = (int)$rec['revision'];

        $pdo->beginTransaction();
        try {
            $stmt_upd = $pdo->prepare("
                UPDATE subject_common_records
                SET sdr_status = :sdrst, sdr_by = :uid, sdr_at = CURRENT_TIMESTAMP, sdr_revision = :rev
                WHERE id = :id
            ");
            $stmt_upd->execute([
                'sdrst' => $sdr_action,
                'uid' => $user_id,
                'rev' => $curr_revision,
                'id' => $record_id
            ]);

            $stmt_hist = $pdo->prepare("
                INSERT INTO common_form_sdr_history (record_id, action, reviewed_revision, action_by)
                VALUES (?, ?, ?, ?)
            ");
            $stmt_hist->execute([$record_id, $sdr_action, $curr_revision, $user_id]);

            $pdo->commit();

            ob_clean();
            echo json_encode(['success' => true, 'sdr_status' => $sdr_action, 'message' => "SDR status updated to " . ucfirst($sdr_action)]);
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
        if ($rec['is_voided']) throw new Exception("Record is already voided.");

        $pdo->beginTransaction();
        try {
            $stmt_upd = $pdo->prepare("
                UPDATE subject_common_records
                SET is_voided = TRUE, void_reason = :reason, voided_by = :uid, voided_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ");
            $stmt_upd->execute(['reason' => $reason, 'uid' => $user_id, 'id' => $record_id]);

            $stmt_aud = $pdo->prepare("
                INSERT INTO common_form_audit_log (study_id, subject_id, record_id, field_name, old_value, new_value, reason_for_change, action_by)
                VALUES (?, ?, ?, 'RECORD_VOIDED', 'Active', 'Voided', ?, ?)
            ");
            $stmt_aud->execute([$study_id, $rec['subject_id'], $record_id, $reason, $user_id]);

            $pdo->commit();

            ob_clean();
            echo json_encode(['success' => true, 'message' => "Record {$rec['record_number']} voided successfully."]);
            exit();

        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // =========================================================================
    // 7. RAISE QUERY ON RECORD / FIELD (DATA MANAGER / ADMIN ONLY)
    // =========================================================================
    elseif ($action === 'raise_query') {
        if (!$is_manager) {
            throw new Exception("Unauthorized: Raising queries is restricted to Data Managers and Administrators.");
        }

        $record_id = (int)($_POST['record_id'] ?? 0);
        $field_name = trim($_POST['field_name'] ?? '');
        $query_text = trim($_POST['query_text'] ?? '');

        if ($record_id <= 0) throw new Exception("Invalid record ID");
        if ($query_text === '') throw new Exception("Query text cannot be empty.");

        $stmt = $pdo->prepare("SELECT * FROM subject_common_records WHERE id = ? AND study_id = ?");
        $stmt->execute([$record_id, $study_id]);
        $rec = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$rec) throw new Exception("Record not found.");

        $pdo->beginTransaction();
        try {
            $stmt_q = $pdo->prepare("
                INSERT INTO common_form_queries (study_id, subject_id, record_id, field_name, query_text, status, created_by)
                VALUES (?, ?, ?, ?, ?, 'open', ?)
            ");
            $stmt_q->execute([$study_id, $rec['subject_id'], $record_id, $field_name ?: null, $query_text, $user_id]);
            $query_id = (int)$pdo->lastInsertId();

            $stmt_qh = $pdo->prepare("
                INSERT INTO common_form_query_history (query_id, action_type, remark, created_by)
                VALUES (?, 'raised', ?, ?)
            ");
            $stmt_qh->execute([$query_id, $query_text, $user_id]);

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
        if (!$is_coordinator && !$is_admin) {
            throw new Exception("Unauthorized: Answering queries requires Data Coordinator permissions.");
        }

        $query_id = (int)($_POST['query_id'] ?? 0);
        $remark = trim($_POST['remark'] ?? $_POST['response_text'] ?? $_POST['response'] ?? '');

        if ($query_id <= 0) throw new Exception("Invalid query ID");
        if ($remark === '') throw new Exception("Response remark cannot be empty.");

        $stmt = $pdo->prepare("SELECT * FROM common_form_queries WHERE id = ? AND study_id = ?");
        $stmt->execute([$query_id, $study_id]);
        $q = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$q) throw new Exception("Query not found.");

        $pdo->beginTransaction();
        try {
            $stmt_upd = $pdo->prepare("UPDATE common_form_queries SET status = 'answered', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt_upd->execute([$query_id]);

            $stmt_qh = $pdo->prepare("
                INSERT INTO common_form_query_history (query_id, action_type, remark, created_by)
                VALUES (?, 'answered', ?, ?)
            ");
            $stmt_qh->execute([$query_id, $remark, $user_id]);

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
    // 9. CLOSE OR REOPEN QUERY (DATA MANAGER / ADMIN ONLY)
    // =========================================================================
    elseif ($action === 'close_query') {
        if (!$is_manager) {
            throw new Exception("Unauthorized: Closing / reopening queries is restricted to Data Managers and Administrators.");
        }

        $query_id = (int)($_POST['query_id'] ?? 0);
        $query_action = trim($_POST['query_action'] ?? 'close'); // 'close' or 'reopen'
        $remark = trim($_POST['remark'] ?? '');

        if ($query_id <= 0) throw new Exception("Invalid query ID");
        $new_status = ($query_action === 'reopen') ? 'open' : 'closed';

        $stmt = $pdo->prepare("SELECT * FROM common_form_queries WHERE id = ? AND study_id = ?");
        $stmt->execute([$query_id, $study_id]);
        $q = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$q) throw new Exception("Query not found.");

        $pdo->beginTransaction();
        try {
            $stmt_upd = $pdo->prepare("UPDATE common_form_queries SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt_upd->execute([$new_status, $query_id]);

            $stmt_qh = $pdo->prepare("
                INSERT INTO common_form_query_history (query_id, action_type, remark, created_by)
                VALUES (?, ?, ?, ?)
            ");
            $stmt_qh->execute([$query_id, $new_status, $remark ?: ($new_status === 'closed' ? 'Query closed' : 'Query reopened'), $user_id]);

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
