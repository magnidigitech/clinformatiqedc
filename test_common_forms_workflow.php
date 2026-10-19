<?php
// test_common_forms_workflow.php
// Comprehensive verification test for Common Forms 3-Role Review Workflow, Queries, and Audit Trail.

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$pdo = getDB();
ensureCommonFormsTables($pdo);

echo "========================================================\n";
echo "COMMON FORMS 3-ROLE WORKFLOW & AUDIT TRAIL TEST SUITE\n";
echo "========================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertTest($expected, $actual, $message, $extra = null) {
    global $passCount, $failCount;
    if ($expected === $actual) {
        echo "✅ PASS: $message\n";
        $passCount++;
    } else {
        echo "❌ FAIL: $message\n";
        echo "   Expected: " . var_export($expected, true) . "\n";
        echo "   Actual:   " . var_export($actual, true) . "\n";
        if ($extra) {
            echo "   Extra info: " . json_encode($extra) . "\n";
        }
        $failCount++;
    }
}

function setTestRole($pdo, $role_name, $userId = 1, $studyId = 1) {
    $pdo->exec("DELETE FROM study_users WHERE user_id = $userId AND study_id = $studyId");
    $stmt = $pdo->prepare("INSERT INTO study_users (user_id, study_id, role_name, permissions) VALUES (?, ?, ?, 'all') RETURNING id");
    $stmt->execute([$userId, $studyId, $role_name]);
    $new_id = $stmt->fetchColumn();
    $GLOBALS['current_assignment_id'] = $new_id;
    $GLOBALS['current_role_name'] = $role_name;
    $GLOBALS['current_user_id'] = $userId;
    return $new_id;
}

function callAjaxCF($post, $session = []) {
    $session['user_id'] = $session['user_id'] ?? ($GLOBALS['current_user_id'] ?? 1);
    $session['active_study_id'] = $session['active_study_id'] ?? 1;
    $session['active_role_name'] = $session['active_role_name'] ?? ($GLOBALS['current_role_name'] ?? 'Data Coordinator');
    $session['active_permissions'] = ['all' => true];
    if (!empty($GLOBALS['current_assignment_id'])) {
        $session['active_assignment_id'] = $GLOBALS['current_assignment_id'];
    }

    $post_serialized = base64_encode(serialize($post));
    $session_serialized = base64_encode(serialize($session));

    $code = "<?php
    require_once 'includes/auth.php';
    \$_POST = unserialize(base64_decode('$post_serialized'));
    \$_SESSION = array_merge(\$_SESSION ?? [], unserialize(base64_decode('$session_serialized')));
    \$_SERVER['REQUEST_METHOD'] = 'POST';
    try {
        include 'ajax_common_forms.php';
    } catch (Throwable \$e) {
        echo json_encode(['success' => false, 'message' => \$e->getMessage()]);
    }
    ";

    $tmp_file = __DIR__ . '/scratch_cf_runner.php';
    file_put_contents($tmp_file, $code);

    $output = shell_exec("php " . escapeshellarg($tmp_file) . " 2>&1");
    if (file_exists($tmp_file)) {
        unlink($tmp_file);
    }

    $decoded = json_decode($output, true);
    if (!$decoded) {
        return ['success' => false, 'raw' => $output];
    }
    return $decoded;
}

// 1. Setup Test Subject
$study_id = 1;
$stmt_sub = $pdo->prepare("SELECT id, subject_code FROM subjects WHERE study_id = ? LIMIT 1");
$stmt_sub->execute([$study_id]);
$subject = $stmt_sub->fetch(PDO::FETCH_ASSOC);

if (!$subject) {
    $stmt_ins_sub = $pdo->prepare("INSERT INTO subjects (study_id, site_id, subject_code, status) VALUES (1, 1, 'SUBJ-CF-001', 'active') RETURNING id");
    $stmt_ins_sub->execute();
    $subject_id = (int)$stmt_ins_sub->fetchColumn();
    $subject_code = 'SUBJ-CF-001';
} else {
    $subject_id = (int)$subject['id'];
    $subject_code = $subject['subject_code'];
}

echo "Testing with Subject ID: $subject_id ($subject_code)\n\n";

// -------------------------------------------------------------------------
// STEP 1: Coordinator saves draft Medical History (MH) record
// -------------------------------------------------------------------------
echo "Step 1: Coordinator creates a draft MH record\n";
setTestRole($pdo, 'Data Coordinator');
$res = callAjaxCF([
    'action' => 'save_record',
    'subject_id' => $subject_id,
    'form_type' => 'MH',
    'mode' => 'draft',
    'data' => [
        'mh_term' => 'Hypertension',
        'ongoing' => 'Yes',
        'medication_given' => 'Yes',
        'start_date' => '2023-01-10'
    ]
]);

assertTest(true, $res['success'] ?? false, "Draft record saved successfully", $res);
$recordId = (int)($res['record_id'] ?? 0);
assertTest(true, $recordId > 0, "Valid record_id returned ($recordId)");

// -------------------------------------------------------------------------
// STEP 2: Coordinator tries to mark draft record as SDR (MUST FAIL)
// -------------------------------------------------------------------------
echo "\nStep 2: Coordinator attempts to mark draft record as SDR (must fail)\n";
$resSdrDraft = callAjaxCF([
    'action' => 'update_review_status',
    'record_id' => $recordId,
    'workflow_action' => 'mark_sdr'
]);
assertTest(false, $resSdrDraft['success'] ?? true, "Cannot mark draft record as SDR", $resSdrDraft);

// -------------------------------------------------------------------------
// STEP 3: Coordinator marks record complete and submits SDR
// -------------------------------------------------------------------------
echo "\nStep 3: Coordinator marks record complete and submits SDR\n";
$resComp = callAjaxCF([
    'action' => 'save_record',
    'record_id' => $recordId,
    'subject_id' => $subject_id,
    'form_type' => 'MH',
    'mode' => 'complete',
    'data' => [
        'mh_term' => 'Essential Hypertension',
        'ongoing' => 'Yes',
        'medication_given' => 'Yes',
        'start_date' => '2023-01-10'
    ]
]);
assertTest(true, $resComp['success'] ?? false, "Record marked as complete", $resComp);

$resSdr = callAjaxCF([
    'action' => 'update_review_status',
    'record_id' => $recordId,
    'workflow_action' => 'mark_sdr'
]);
assertTest(true, $resSdr['success'] ?? false, "Record marked as SDR (submitted for review)", $resSdr);

$stmt_chk = $pdo->prepare("SELECT * FROM subject_common_records WHERE id = ?");
$stmt_chk->execute([$recordId]);
$rec = $stmt_chk->fetch(PDO::FETCH_ASSOC);
assertTest(true, (bool)$rec['sdr_submitted'], "sdr_submitted is true in database");
assertTest('under_review', $rec['sdr_status'], "sdr_status is 'under_review'");

// -------------------------------------------------------------------------
// STEP 4: Coordinator revokes SDR while unreviewed
// -------------------------------------------------------------------------
echo "\nStep 4: Coordinator revokes SDR before any review\n";
$resRev = callAjaxCF([
    'action' => 'update_review_status',
    'record_id' => $recordId,
    'workflow_action' => 'revoke_sdr'
]);
assertTest(true, $resRev['success'] ?? false, "SDR revoked successfully", $resRev);

$stmt_chk->execute([$recordId]);
$rec = $stmt_chk->fetch(PDO::FETCH_ASSOC);
assertTest(false, (bool)$rec['sdr_submitted'], "sdr_submitted is false");
assertTest('pending', $rec['sdr_status'], "sdr_status reset to 'pending'");

// Re-submit SDR for review tests
callAjaxCF([
    'action' => 'update_review_status',
    'record_id' => $recordId,
    'workflow_action' => 'mark_sdr'
]);

// -------------------------------------------------------------------------
// STEP 5: Data Monitor marks as SDVed
// -------------------------------------------------------------------------
echo "\nStep 5: Data Monitor marks record as SDVed\n";
setTestRole($pdo, 'Data Monitor');
$resMon = callAjaxCF([
    'action' => 'update_review_status',
    'record_id' => $recordId,
    'workflow_action' => 'monitor_review'
]);
assertTest(true, $resMon['success'] ?? false, "Data Monitor successfully marked as SDVed", $resMon);

$stmt_chk->execute([$recordId]);
$rec = $stmt_chk->fetch(PDO::FETCH_ASSOC);
assertTest(true, (bool)$rec['monitor_reviewed'], "monitor_reviewed is true");
assertTest('sdved', $rec['sdr_status'], "sdr_status is 'sdved'");

// Coordinator tries to revoke SDR now (MUST BE BLOCKED)
echo "\nStep 6: Coordinator attempts to revoke SDR on an SDVed form (must be blocked)\n";
setTestRole($pdo, 'Data Coordinator');
$resCoordBlocked = callAjaxCF([
    'action' => 'update_review_status',
    'record_id' => $recordId,
    'workflow_action' => 'revoke_sdr'
]);
assertTest(false, $resCoordBlocked['success'] ?? true, "Coordinator blocked from revoking locked form", $resCoordBlocked);
assertTest(true, strpos($resCoordBlocked['message'] ?? '', 'locked') !== false, "Error message contains 'locked'");

// -------------------------------------------------------------------------
// STEP 7: Data Monitor revoking SDVed requires min 10 characters remarks
// -------------------------------------------------------------------------
echo "\nStep 7: Data Monitor revokes SDVed (remark validation)\n";
setTestRole($pdo, 'Data Monitor');
$resRevShort = callAjaxCF([
    'action' => 'update_review_status',
    'record_id' => $recordId,
    'workflow_action' => 'monitor_revoke',
    'remarks' => 'Too short'
]);
assertTest(false, $resRevShort['success'] ?? true, "Remarks < 10 characters rejected", $resRevShort);

$resRevValid = callAjaxCF([
    'action' => 'update_review_status',
    'record_id' => $recordId,
    'workflow_action' => 'monitor_revoke',
    'remarks' => 'Source document indicates discrepancy in onset date.'
]);
assertTest(true, $resRevValid['success'] ?? false, "SDV revocation with valid remarks succeeded", $resRevValid);

$stmt_chk->execute([$recordId]);
$rec = $stmt_chk->fetch(PDO::FETCH_ASSOC);
assertTest(false, (bool)$rec['monitor_reviewed'], "monitor_reviewed reset to false");
assertTest(false, (bool)$rec['sdr_submitted'], "sdr_submitted reset to false");
assertTest('pending', $rec['sdr_status'], "sdr_status reset to 'pending'");

// -------------------------------------------------------------------------
// STEP 8: Data Manager review and revocation
// -------------------------------------------------------------------------
echo "\nStep 8: Data Manager review and revocation\n";
setTestRole($pdo, 'Data Coordinator');
callAjaxCF(['action' => 'update_review_status', 'record_id' => $recordId, 'workflow_action' => 'mark_sdr']);

setTestRole($pdo, 'Data Manager');
$resMgr = callAjaxCF([
    'action' => 'update_review_status',
    'record_id' => $recordId,
    'workflow_action' => 'manager_review'
]);
assertTest(true, $resMgr['success'] ?? false, "Data Manager marked as DM Reviewed", $resMgr);

$stmt_chk->execute([$recordId]);
$rec = $stmt_chk->fetch(PDO::FETCH_ASSOC);
assertTest(true, (bool)$rec['manager_reviewed'], "manager_reviewed is true");

$resMgrRev = callAjaxCF([
    'action' => 'update_review_status',
    'record_id' => $recordId,
    'workflow_action' => 'manager_revoke',
    'remarks' => 'Medical dictionary coding requires PI condition clarification.'
]);
assertTest(true, $resMgrRev['success'] ?? false, "Data Manager revoked DM Review", $resMgrRev);

$stmt_chk->execute([$recordId]);
$rec = $stmt_chk->fetch(PDO::FETCH_ASSOC);
assertTest(false, (bool)$rec['manager_reviewed'], "manager_reviewed reset to false");
assertTest(false, (bool)$rec['sdr_submitted'], "sdr_submitted reset to false");

// -------------------------------------------------------------------------
// STEP 9: Query Workflow & SDR State Management
// -------------------------------------------------------------------------
echo "\nStep 9: Query raising, answering with proposed value, and closure with SDR revocation\n";
// Coordinator submits SDR
setTestRole($pdo, 'Data Coordinator');
callAjaxCF(['action' => 'update_review_status', 'record_id' => $recordId, 'workflow_action' => 'mark_sdr']);

// Monitor raises query on field 'mh_term'
setTestRole($pdo, 'Data Monitor');
$resQuery = callAjaxCF([
    'action' => 'raise_query',
    'record_id' => $recordId,
    'field_name' => 'mh_term',
    'query_text' => 'Please confirm if patient has Stage 2 Hypertension or Essential Hypertension.'
]);
assertTest(true, $resQuery['success'] ?? false, "Monitor raised query on mh_term", $resQuery);
$queryId = (int)($resQuery['query_id'] ?? 0);
assertTest(true, $queryId > 0, "Valid query_id ($queryId)");

// Monitor tries to SDV while open query exists (MUST BE BLOCKED)
$resSdvBlocked = callAjaxCF([
    'action' => 'update_review_status',
    'record_id' => $recordId,
    'workflow_action' => 'monitor_review'
]);
assertTest(false, $resSdvBlocked['success'] ?? true, "Cannot SDV while open query exists", $resSdvBlocked);

// Coordinator answers query with proposed new value
setTestRole($pdo, 'Data Coordinator');
$resAns = callAjaxCF([
    'action' => 'answer_query',
    'query_id' => $queryId,
    'remark' => 'PI confirmed: Patient has Stage 2 Essential Hypertension',
    'field_value' => 'Stage 2 Essential Hypertension'
]);
assertTest(true, $resAns['success'] ?? false, "Coordinator answered query with proposed new value", $resAns);

// CRITICAL CHECK: After answering, field value in data_json MUST NOT BE CHANGED YET!
$stmt_chk->execute([$recordId]);
$rec = $stmt_chk->fetch(PDO::FETCH_ASSOC);
$dataBeforeClose = json_decode($rec['data_json'] ?? '{}', true);
assertTest('Essential Hypertension', $dataBeforeClose['mh_term'], "Field value in data_json remains UNCHANGED upon query answer");
assertTest(true, (bool)$rec['sdr_submitted'], "sdr_submitted remains true (NOT prematurely revoked)");

// Monitor closes query with field value update
setTestRole($pdo, 'Data Monitor');
$resClose = callAjaxCF([
    'action' => 'close_query',
    'query_id' => $queryId,
    'query_action' => 'close',
    'remark' => 'Verified against physician clinic notes.'
]);
assertTest(true, $resClose['success'] ?? false, "Monitor closed query", $resClose);

// CRITICAL CHECK: Upon closure, field value in data_json is updated and SDR IS REVOKED!
$stmt_chk->execute([$recordId]);
$rec = $stmt_chk->fetch(PDO::FETCH_ASSOC);
$dataAfterClose = json_decode($rec['data_json'] ?? '{}', true);
assertTest('Stage 2 Essential Hypertension', $dataAfterClose['mh_term'], "Field value UPDATED in data_json upon query closure");
assertTest(false, (bool)$rec['sdr_submitted'], "sdr_submitted REVOKED upon query closure with field value change");
assertTest('pending', $rec['sdr_status'], "sdr_status reset to 'pending'");
assertTest(true, (int)$rec['revision'] > 1, "Record revision incremented");

// -------------------------------------------------------------------------
// STEP 10: Query closed WITHOUT field value change preserves SDR state
// -------------------------------------------------------------------------
echo "\nStep 10: Query closed WITHOUT field value change preserves SDR state\n";
setTestRole($pdo, 'Data Coordinator');
callAjaxCF(['action' => 'update_review_status', 'record_id' => $recordId, 'workflow_action' => 'mark_sdr']);

setTestRole($pdo, 'Data Manager');
$resQ2 = callAjaxCF([
    'action' => 'raise_query',
    'record_id' => $recordId,
    'field_name' => '',
    'query_text' => 'Please confirm prior medication start date is verifiable in source.'
]);
assertTest(true, $resQ2['success'] ?? false, "Manager raised record-level query", $resQ2);
$q2Id = (int)($resQ2['query_id'] ?? 0);

setTestRole($pdo, 'Data Coordinator');
callAjaxCF([
    'action' => 'answer_query',
    'query_id' => $q2Id,
    'remark' => 'Verified in patient discharge summary.'
]);

setTestRole($pdo, 'Data Manager');
$resClose2 = callAjaxCF([
    'action' => 'close_query',
    'query_id' => $q2Id,
    'query_action' => 'close',
    'remark' => 'Discharge summary confirmed.'
]);
assertTest(true, $resClose2['success'] ?? false, "Manager closed query without field change", $resClose2);

$stmt_chk->execute([$recordId]);
$rec = $stmt_chk->fetch(PDO::FETCH_ASSOC);
assertTest(true, (bool)$rec['sdr_submitted'], "sdr_submitted PRESERVED as true");
assertTest('under_review', $rec['sdr_status'], "sdr_status PRESERVED as 'under_review'");

// -------------------------------------------------------------------------
// STEP 11: Regulatory Audit Trail Verification
// -------------------------------------------------------------------------
echo "\nStep 11: Regulatory Audit Trail Verification\n";
$stmt_aud = $pdo->prepare("SELECT * FROM common_form_audit_log WHERE record_id = ? ORDER BY id ASC");
$stmt_aud->execute([$recordId]);
$auditEntries = $stmt_aud->fetchAll(PDO::FETCH_ASSOC);

assertTest(true, count($auditEntries) >= 6, "Audit trail contains complete event history (" . count($auditEntries) . " logs)");

$changeTypes = array_column($auditEntries, 'change_type');
echo "  Recorded Change Types: " . implode(', ', array_unique($changeTypes)) . "\n";

assertTest(true, in_array('create', $changeTypes, true), "Audit log contains 'create'");
assertTest(true, in_array('sdr_submitted', $changeTypes, true), "Audit log contains 'sdr_submitted'");
assertTest(true, in_array('sdr_revoked', $changeTypes, true), "Audit log contains 'sdr_revoked'");
assertTest(true, in_array('monitor_reviewed', $changeTypes, true), "Audit log contains 'monitor_reviewed'");
assertTest(true, in_array('monitor_revoked', $changeTypes, true), "Audit log contains 'monitor_revoked'");
assertTest(true, in_array('manager_reviewed', $changeTypes, true), "Audit log contains 'manager_reviewed'");
assertTest(true, in_array('manager_revoked', $changeTypes, true), "Audit log contains 'manager_revoked'");
assertTest(true, in_array('query_raised', $changeTypes, true), "Audit log contains 'query_raised'");
assertTest(true, in_array('query_answered', $changeTypes, true), "Audit log contains 'query_answered'");
assertTest(true, in_array('query_closed', $changeTypes, true), "Audit log contains 'query_closed'");
assertTest(true, in_array('update', $changeTypes, true), "Audit log contains clinical 'update'");

// -------------------------------------------------------------------------
// STEP 12: Data Manager and Monitor CANNOT Edit Clinical Content
// -------------------------------------------------------------------------
echo "\nStep 12: Data Manager and Monitor CANNOT Edit Clinical Content (Common Forms & Standard CRFs)\n";

function callAjaxData($post, $session = []) {
    $session['user_id'] = $session['user_id'] ?? ($GLOBALS['current_user_id'] ?? 1);
    $session['active_study_id'] = $session['active_study_id'] ?? 1;
    $session['active_role_name'] = $session['active_role_name'] ?? ($GLOBALS['current_role_name'] ?? 'Data Coordinator');
    $session['active_permissions'] = ['all' => true];
    if (!empty($GLOBALS['current_assignment_id'])) {
        $session['active_assignment_id'] = $GLOBALS['current_assignment_id'];
    }

    $post_serialized = base64_encode(serialize($post));
    $session_serialized = base64_encode(serialize($session));

    $code = "<?php
    require_once 'includes/auth.php';
    \$_POST = unserialize(base64_decode('$post_serialized'));
    \$_SESSION = array_merge(\$_SESSION ?? [], unserialize(base64_decode('$session_serialized')));
    \$_SERVER['REQUEST_METHOD'] = 'POST';
    try {
        include 'ajax_data.php';
    } catch (Throwable \$e) {
        echo json_encode(['success' => false, 'message' => \$e->getMessage()]);
    }
    ";

    $tmp_file = __DIR__ . '/scratch_data_runner.php';
    file_put_contents($tmp_file, $code);

    $output = shell_exec("php " . escapeshellarg($tmp_file) . " 2>&1");
    if (file_exists($tmp_file)) {
        unlink($tmp_file);
    }

    $decoded = json_decode($output, true);
    if (!$decoded) {
        return ['success' => false, 'raw' => $output];
    }
    return $decoded;
}

// A. Data Monitor attempts to create a new common form record (must fail)
setTestRole($pdo, 'Data Monitor', 2, 1);
$monCreateRes = callAjaxCF([
    'action' => 'save_record',
    'subject_id' => $subject_id,
    'form_type' => 'MH',
    'mode' => 'draft',
    'data' => json_encode(['mh_term' => 'Monitor Illegal Edit', 'ongoing' => 'Yes'])
]);
assertTest(false, $monCreateRes['success'] ?? false, "Data Monitor must NOT be allowed to create a common form record");
assertTest(true, strpos($monCreateRes['message'] ?? '', 'Unauthorized') !== false, "Monitor error contains 'Unauthorized'");

// B. Data Manager attempts to create a new common form record (must fail)
setTestRole($pdo, 'Data Manager', 3, 1);
$mgrCreateRes = callAjaxCF([
    'action' => 'save_record',
    'subject_id' => $subject_id,
    'form_type' => 'MH',
    'mode' => 'draft',
    'data' => json_encode(['mh_term' => 'Manager Illegal Edit', 'ongoing' => 'Yes'])
]);
assertTest(false, $mgrCreateRes['success'] ?? false, "Data Manager must NOT be allowed to create a common form record");
assertTest(true, strpos($mgrCreateRes['message'] ?? '', 'Unauthorized') !== false, "Manager error contains 'Unauthorized'");

// C. Data Monitor attempts to edit an existing record (must fail)
setTestRole($pdo, 'Data Monitor', 2, 1);
$monEditRes = callAjaxCF([
    'action' => 'save_record',
    'record_id' => $recordId,
    'subject_id' => $subject_id,
    'form_type' => 'MH',
    'mode' => 'complete',
    'data' => json_encode(['mh_term' => 'Monitor Direct Mutation', 'ongoing' => 'No', 'medication_given' => 'No'])
]);
assertTest(false, $monEditRes['success'] ?? false, "Data Monitor must NOT be allowed to edit existing clinical content directly");

// D. Data Manager attempts to edit an existing record (must fail)
setTestRole($pdo, 'Data Manager', 3, 1);
$mgrEditRes = callAjaxCF([
    'action' => 'save_record',
    'record_id' => $recordId,
    'subject_id' => $subject_id,
    'form_type' => 'MH',
    'mode' => 'complete',
    'data' => json_encode(['mh_term' => 'Manager Direct Mutation', 'ongoing' => 'No', 'medication_given' => 'No'])
]);
assertTest(false, $mgrEditRes['success'] ?? false, "Data Manager must NOT be allowed to edit existing clinical content directly");

// E. Data Monitor attempts to void a record (must fail)
setTestRole($pdo, 'Data Monitor', 2, 1);
$monVoidRes = callAjaxCF([
    'action' => 'void_record',
    'record_id' => $recordId,
    'reason' => 'Monitor trying to void directly'
]);
assertTest(false, $monVoidRes['success'] ?? false, "Data Monitor must NOT be allowed to void records directly");

// F. Data Manager attempts to void a record (must fail)
setTestRole($pdo, 'Data Manager', 3, 1);
$mgrVoidRes = callAjaxCF([
    'action' => 'void_record',
    'record_id' => $recordId,
    'reason' => 'Manager trying to void directly'
]);
assertTest(false, $mgrVoidRes['success'] ?? false, "Data Manager must NOT be allowed to void records directly");

// G. Data Monitor attempts save_data on standard CRF (must fail)
setTestRole($pdo, 'Data Monitor', 2, 1);
$monCrfRes = callAjaxData([
    'action' => 'save_data',
    'subject_id' => $subject_id,
    'visit_id' => 1,
    'form_id' => 1,
    'data' => json_encode(['5' => 'Monitor Value'])
]);
assertTest(false, $monCrfRes['success'] ?? false, "Data Monitor must NOT be allowed to edit standard CRF content");

// H. Data Manager attempts save_data on standard CRF (must fail)
setTestRole($pdo, 'Data Manager', 3, 1);
$mgrCrfRes = callAjaxData([
    'action' => 'save_data',
    'subject_id' => $subject_id,
    'visit_id' => 1,
    'form_id' => 1,
    'data' => json_encode(['5' => 'Manager Value'])
]);
assertTest(false, $mgrCrfRes['success'] ?? false, "Data Manager must NOT be allowed to edit standard CRF content");

// -------------------------------------------------------------------------
// SUMMARY
// -------------------------------------------------------------------------
echo "\n========================================================\n";
echo "TEST RESULTS: Total Pass: $passCount | Total Fail: $failCount\n";
echo "========================================================\n";

if ($failCount === 0) {
    echo "🎉 ALL COMMON FORMS 3-ROLE & AUDIT TRAIL TESTS PASSED!\n";
    exit(0);
} else {
    echo "❌ SOME TESTS FAILED!\n";
    exit(1);
}
