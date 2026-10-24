<?php
// test_query_role_restriction.php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$pdo = getDB();

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

function callAjax($file, $post, $session = []) {
    global $pdo;
    $session['user_id'] = $session['user_id'] ?? 1;
    $session['active_study_id'] = $session['active_study_id'] ?? 4;
    $roleName = $session['active_role_name'] ?? 'Data Coordinator';
    
    // Look up assignment id for this role
    $stmt = $pdo->prepare("SELECT id FROM study_users WHERE user_id = ? AND study_id = ? AND role_name = ? LIMIT 1");
    $stmt->execute([$session['user_id'], $session['active_study_id'], $roleName]);
    $assignId = $stmt->fetchColumn();
    if ($assignId) {
        $session['active_assignment_id'] = $assignId;
    }
    $session['active_role_name'] = $roleName;
    $session['active_permissions'] = ['all' => true];
    
    $post_serialized = base64_encode(serialize($post));
    $session_serialized = base64_encode(serialize($session));
    
    $code = "<?php
    require_once 'includes/auth.php';
    \$_POST = unserialize(base64_decode('$post_serialized'));
    \$_SESSION = array_merge(\$_SESSION ?? [], unserialize(base64_decode('$session_serialized')));
    \$_SERVER['REQUEST_METHOD'] = 'POST';
    try {
        include '$file';
    } catch (Throwable \$e) {
        echo json_encode(['success' => false, 'error' => \$e->getMessage()]);
    }
    ";
    
    $tmp_file = __DIR__ . '/scratch_test_runner.php';
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

echo "========================================================\n";
echo "TESTING QUERY CLOSE ROLE RESTRICTION WORKFLOW\n";
echo "========================================================\n\n";

$study_id = 4;
$subject_id = 12;
$visit_id = 7;
$form_id = 32;
$field_id = 691;

// Reset form review status so queries can be raised
$pdo->exec("UPDATE subject_form_status SET sdr_submitted = true, monitor_reviewed = false, manager_reviewed = false WHERE subject_id = $subject_id AND form_id = $form_id");

// Clean up any existing queries on this field
$pdo->exec("UPDATE data_queries SET status = 'closed' WHERE subject_id = $subject_id AND form_id = $form_id AND field_id = $field_id");

// Reset common form record review status
$pdo->exec("UPDATE subject_common_records SET sdr_submitted = true, monitor_reviewed = false, manager_reviewed = false WHERE id = 40");
$pdo->exec("UPDATE common_form_queries SET status = 'closed' WHERE record_id = 40");

// -------------------------------------------------------------------------
// PART 1: CRF QUERY RAISED BY DATA MONITOR
// -------------------------------------------------------------------------
echo "--- PART 1: CRF Query Raised by Data Monitor ---\n";

// 1. Data Monitor raises query
$res_mon_raise = callAjax('ajax_data.php', [
    'action' => 'add_query',
    'subject_id' => $subject_id,
    'visit_id' => $visit_id,
    'form_id' => $form_id,
    'field_id' => $field_id,
    'query_text' => 'Monitor query: please clarify visit performance'
], ['active_role_name' => 'Data Monitor']);

assertTest(true, $res_mon_raise['success'] ?? false, "Data Monitor should successfully raise query", $res_mon_raise);

$query_id_mon = $pdo->query("SELECT id FROM data_queries WHERE subject_id = $subject_id AND form_id = $form_id AND field_id = $field_id AND status = 'new' ORDER BY id DESC LIMIT 1")->fetchColumn();
$query_row = $pdo->query("SELECT * FROM data_queries WHERE id = $query_id_mon")->fetch(PDO::FETCH_ASSOC);

assertTest('Data Monitor', $query_row['raised_by_role'], "Query should record raised_by_role as 'Data Monitor'");

// 2. Data Coordinator answers query
$res_ans1 = callAjax('ajax_data.php', [
    'action' => 'update_query_status',
    'query_id' => $query_id_mon,
    'status' => 'answered',
    'remark' => 'Coordinator: Visit was indeed performed on schedule'
], ['active_role_name' => 'Data Coordinator']);

assertTest(true, $res_ans1['success'] ?? false, "Data Coordinator answers query", $res_ans1);

// 3. Data Manager attempts to CLOSE the Data Monitor's query -> MUST FAIL!
$res_mgr_close_fail = callAjax('ajax_data.php', [
    'action' => 'update_query_status',
    'query_id' => $query_id_mon,
    'status' => 'closed',
    'remark' => 'Manager trying to close monitor query'
], ['active_role_name' => 'Data Manager']);

assertTest(false, $res_mgr_close_fail['success'] ?? false, "Data Manager MUST NOT be able to close Data Monitor query");
assertTest(true, strpos($res_mgr_close_fail['error'] ?? '', 'raised by Data Monitor and can only be closed by a Data Monitor') !== false, "Error message should state query was raised by Data Monitor and can only be closed by Data Monitor", $res_mgr_close_fail);

// 4. Data Manager attempts to RE-QUERY the Data Monitor's query -> MUST FAIL!
$res_mgr_requery_fail = callAjax('ajax_data.php', [
    'action' => 'update_query_status',
    'query_id' => $query_id_mon,
    'status' => 'open',
    'remark' => 'Manager trying to re-query monitor query'
], ['active_role_name' => 'Data Manager']);

assertTest(false, $res_mgr_requery_fail['success'] ?? false, "Data Manager MUST NOT be able to re-query Data Monitor query");

// 5. Data Monitor CLOSES their own query -> MUST SUCCEED!
$res_mon_close = callAjax('ajax_data.php', [
    'action' => 'update_query_status',
    'query_id' => $query_id_mon,
    'status' => 'closed',
    'remark' => 'Data Monitor verified and closed'
], ['active_role_name' => 'Data Monitor']);

assertTest(true, $res_mon_close['success'] ?? false, "Data Monitor successfully closes their own query", $res_mon_close);
$status_after_close = $pdo->query("SELECT status FROM data_queries WHERE id = $query_id_mon")->fetchColumn();
assertTest('closed', $status_after_close, "Query status in DB should be 'closed'");


// -------------------------------------------------------------------------
// PART 2: CRF QUERY RAISED BY DATA MANAGER
// -------------------------------------------------------------------------
echo "\n--- PART 2: CRF Query Raised by Data Manager ---\n";

// 1. Data Manager raises query
$res_mgr_raise = callAjax('ajax_data.php', [
    'action' => 'add_query',
    'subject_id' => $subject_id,
    'visit_id' => $visit_id,
    'form_id' => $form_id,
    'field_id' => $field_id,
    'query_text' => 'Manager query: please check protocol compliance'
], ['active_role_name' => 'Data Manager']);

assertTest(true, $res_mgr_raise['success'] ?? false, "Data Manager should successfully raise query", $res_mgr_raise);

$query_id_mgr = $pdo->query("SELECT id FROM data_queries WHERE subject_id = $subject_id AND form_id = $form_id AND field_id = $field_id AND status = 'new' ORDER BY id DESC LIMIT 1")->fetchColumn();
$query_row2 = $pdo->query("SELECT * FROM data_queries WHERE id = $query_id_mgr")->fetch(PDO::FETCH_ASSOC);

assertTest('Data Manager', $query_row2['raised_by_role'], "Query should record raised_by_role as 'Data Manager'");

// 2. Data Coordinator answers query
$res_ans2 = callAjax('ajax_data.php', [
    'action' => 'update_query_status',
    'query_id' => $query_id_mgr,
    'status' => 'answered',
    'remark' => 'Coordinator: Protocol deviation documented'
], ['active_role_name' => 'Data Coordinator']);

assertTest(true, $res_ans2['success'] ?? false, "Data Coordinator answers query", $res_ans2);

// 3. Data Monitor attempts to CLOSE Data Manager's query -> MUST FAIL!
$res_mon_close_fail = callAjax('ajax_data.php', [
    'action' => 'update_query_status',
    'query_id' => $query_id_mgr,
    'status' => 'closed',
    'remark' => 'Monitor trying to close manager query'
], ['active_role_name' => 'Data Monitor']);

assertTest(false, $res_mon_close_fail['success'] ?? false, "Data Monitor MUST NOT be able to close Data Manager query");
assertTest(true, strpos($res_mon_close_fail['error'] ?? '', 'raised by Data Manager and can only be closed by a Data Manager') !== false, "Error message should state query was raised by Data Manager and can only be closed by Data Manager", $res_mon_close_fail);

// 4. Data Monitor attempts to RE-QUERY Data Manager's query -> MUST FAIL!
$res_mon_requery_fail = callAjax('ajax_data.php', [
    'action' => 'update_query_status',
    'query_id' => $query_id_mgr,
    'status' => 'open',
    'remark' => 'Monitor trying to re-query manager query'
], ['active_role_name' => 'Data Monitor']);

assertTest(false, $res_mon_requery_fail['success'] ?? false, "Data Monitor MUST NOT be able to re-query Data Manager query");

// 5. Data Manager CLOSES their own query -> MUST SUCCEED!
$res_mgr_close = callAjax('ajax_data.php', [
    'action' => 'update_query_status',
    'query_id' => $query_id_mgr,
    'status' => 'closed',
    'remark' => 'Data Manager accepted response and closed'
], ['active_role_name' => 'Data Manager']);

assertTest(true, $res_mgr_close['success'] ?? false, "Data Manager successfully closes their own query", $res_mgr_close);
$status_after_close2 = $pdo->query("SELECT status FROM data_queries WHERE id = $query_id_mgr")->fetchColumn();
assertTest('closed', $status_after_close2, "Query status in DB should be 'closed'");


// -------------------------------------------------------------------------
// PART 3: COMMON FORM QUERY ROLE RESTRICTION
// -------------------------------------------------------------------------
echo "\n--- PART 3: Common Form Query Role Restriction ---\n";

$rec_id = 40; // Common form record

// 1. Data Monitor raises common form query
$res_cf_mon_raise = callAjax('ajax_common_forms.php', [
    'action' => 'raise_query',
    'record_id' => $rec_id,
    'field_name' => 'ta_term',
    'query_text' => 'Monitor CF query: verify term in clinical notes'
], ['active_role_name' => 'Data Monitor']);

assertTest(true, $res_cf_mon_raise['success'] ?? false, "Monitor raised CF query", $res_cf_mon_raise);
$cf_q1_id = (int)$res_cf_mon_raise['query_id'];

$cf_q1_row = $pdo->query("SELECT * FROM common_form_queries WHERE id = $cf_q1_id")->fetch(PDO::FETCH_ASSOC);
assertTest('Data Monitor', $cf_q1_row['raised_by_role'], "CF query should record raised_by_role as 'Data Monitor'");

// 2. Data Coordinator answers
$res_cf_ans1 = callAjax('ajax_common_forms.php', [
    'action' => 'answer_query',
    'query_id' => $cf_q1_id,
    'remark' => 'Coordinator: Term confirmed against notes'
], ['active_role_name' => 'Data Coordinator']);
assertTest(true, $res_cf_ans1['success'] ?? false, "Coordinator answers CF query", $res_cf_ans1);

// 3. Data Manager attempts to close Monitor's CF query -> MUST FAIL!
$res_cf_mgr_fail = callAjax('ajax_common_forms.php', [
    'action' => 'close_query',
    'query_id' => $cf_q1_id,
    'query_action' => 'close',
    'remark' => 'Manager trying to close monitor CF query'
], ['active_role_name' => 'Data Manager']);

assertTest(false, $res_cf_mgr_fail['success'] ?? false, "Data Manager MUST NOT close Monitor's CF query");
assertTest(true, strpos($res_cf_mgr_fail['message'] ?? '', 'raised by Data Monitor and can only be closed by a Data Monitor') !== false, "Error message specifies Monitor requirement", $res_cf_mgr_fail);

// 4. Data Monitor closes their own CF query -> MUST SUCCEED!
$res_cf_mon_close = callAjax('ajax_common_forms.php', [
    'action' => 'close_query',
    'query_id' => $cf_q1_id,
    'query_action' => 'close',
    'remark' => 'Data Monitor accepts and closes'
], ['active_role_name' => 'Data Monitor']);

assertTest(true, $res_cf_mon_close['success'] ?? false, "Data Monitor successfully closes their own CF query", $res_cf_mon_close);

// 5. Data Manager raises common form query
$res_cf_mgr_raise = callAjax('ajax_common_forms.php', [
    'action' => 'raise_query',
    'record_id' => $rec_id,
    'field_name' => 'ta_term',
    'query_text' => 'Manager CF query: verify coding terminology'
], ['active_role_name' => 'Data Manager']);

assertTest(true, $res_cf_mgr_raise['success'] ?? false, "Manager raised CF query", $res_cf_mgr_raise);
$cf_q2_id = (int)$res_cf_mgr_raise['query_id'];

$cf_q2_row = $pdo->query("SELECT * FROM common_form_queries WHERE id = $cf_q2_id")->fetch(PDO::FETCH_ASSOC);
assertTest('Data Manager', $cf_q2_row['raised_by_role'], "CF query should record raised_by_role as 'Data Manager'");

// 6. Data Coordinator answers
$res_cf_ans2 = callAjax('ajax_common_forms.php', [
    'action' => 'answer_query',
    'query_id' => $cf_q2_id,
    'remark' => 'Coordinator: Term coding verified'
], ['active_role_name' => 'Data Coordinator']);
assertTest(true, $res_cf_ans2['success'] ?? false, "Coordinator answers CF query 2", $res_cf_ans2);

// 7. Data Monitor attempts to close Manager's CF query -> MUST FAIL!
$res_cf_mon_fail = callAjax('ajax_common_forms.php', [
    'action' => 'close_query',
    'query_id' => $cf_q2_id,
    'query_action' => 'close',
    'remark' => 'Monitor trying to close manager CF query'
], ['active_role_name' => 'Data Monitor']);

assertTest(false, $res_cf_mon_fail['success'] ?? false, "Data Monitor MUST NOT close Manager's CF query");
assertTest(true, strpos($res_cf_mon_fail['message'] ?? '', 'raised by Data Manager and can only be closed by a Data Manager') !== false, "Error message specifies Manager requirement", $res_cf_mon_fail);

// 8. Data Manager closes their own CF query -> MUST SUCCEED!
$res_cf_mgr_close = callAjax('ajax_common_forms.php', [
    'action' => 'close_query',
    'query_id' => $cf_q2_id,
    'query_action' => 'close',
    'remark' => 'Data Manager verified and closed'
], ['active_role_name' => 'Data Manager']);

assertTest(true, $res_cf_mgr_close['success'] ?? false, "Data Manager successfully closes their own CF query", $res_cf_mgr_close);

echo "\n========================================================\n";
echo "SUMMARY: Passed: $passCount, Failed: $failCount\n";
echo "========================================================\n";

if ($failCount > 0) {
    exit(1);
}
