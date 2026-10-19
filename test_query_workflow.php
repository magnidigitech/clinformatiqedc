<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$pdo = getDB();

function assertTest($expected, $actual, $message, $extra = null) {
    if ($expected === $actual) {
        echo "✅ PASS: $message\n";
    } else {
        echo "❌ FAIL: $message\n";
        echo "   Expected: " . var_export($expected, true) . "\n";
        echo "   Actual:   " . var_export($actual, true) . "\n";
        if ($extra) {
            echo "   Extra info: " . json_encode($extra) . "\n";
        }
        exit(1);
    }
}

function setTestRole($pdo, $role_name) {
    $pdo->exec("DELETE FROM study_users WHERE user_id = 1 AND study_id = 1");
    $stmt = $pdo->prepare("INSERT INTO study_users (user_id, study_id, role_name, permissions) VALUES (1, 1, ?, 'all') RETURNING id");
    $stmt->execute([$role_name]);
    $new_id = $stmt->fetchColumn();
    $GLOBALS['current_assignment_id'] = $new_id;
    return $new_id;
}

function callAjax($post, $session = []) {
    $session['user_id'] = $session['user_id'] ?? 1;
    $session['active_study_id'] = $session['active_study_id'] ?? 1;
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

echo "==================================================\n";
echo "Testing Query Resolution & SDR Revocation Workflow\n";
echo "==================================================\n\n";

$study_id = 1;
$subject_id = 1;
$visit_id = 1;
$form_id = 1;
$field_id = 5;

// Clean up any old open queries on field 5
$pdo->exec("UPDATE data_queries SET status = 'closed' WHERE subject_id = 1 AND form_id = 1 AND field_id = 5");

// 1. Mark form as SDR submitted
$stmt_check_stat = $pdo->query("SELECT id FROM subject_form_status WHERE subject_id = 1 AND form_id = 1");
if ($stmt_check_stat->fetch()) {
    $pdo->exec("UPDATE subject_form_status SET sdr_submitted = true, monitor_reviewed = false, manager_reviewed = false, is_verified = false, status = 'complete', updated_at = NOW() WHERE subject_id = 1 AND form_id = 1");
} else {
    $pdo->exec("INSERT INTO subject_form_status (study_id, subject_id, visit_id, form_id, repeating_instance_id, sdr_submitted, monitor_reviewed, manager_reviewed, is_verified, status, is_complete, progress_percent, updated_at) VALUES (1, 1, 1, 1, 0, true, false, false, false, 'complete', true, 100, NOW())");
}

// Set initial value in subject_data
$pdo->exec("DELETE FROM subject_data WHERE subject_id = 1 AND form_id = 1 AND field_id = 5");
$pdo->exec("INSERT INTO subject_data (study_id, subject_id, visit_id, form_id, repeating_instance_id, field_id, value, updated_by) VALUES (1, 1, 1, 1, 0, 5, 'Original John Doe', 1)");

echo "Step 1: Data Manager raises a query on field 5\n";
setTestRole($pdo, 'Data Manager');
$res_raise = callAjax([
    'action' => 'add_query',
    'study_id' => 1,
    'subject_id' => 1,
    'visit_id' => 1,
    'form_id' => 1,
    'field_id' => 5,
    'query_text' => 'Name spelling incorrect'
], ['active_role_name' => 'Data Manager']);

assertTest(true, $res_raise['success'] ?? false, "Data Manager should successfully raise query", $res_raise);

$query_id = $pdo->query("SELECT id FROM data_queries WHERE subject_id = 1 AND form_id = 1 AND field_id = 5 AND status = 'new' ORDER BY id DESC LIMIT 1")->fetchColumn();
echo "   Query #{$query_id} raised successfully.\n";

echo "\nStep 2: Data Coordinator answers the query with updated field value\n";
setTestRole($pdo, 'Data Coordinator');
$res_ans = callAjax([
    'action' => 'update_query_status',
    'query_id' => $query_id,
    'status' => 'answered',
    'remark' => 'Corrected name per ID document',
    'field_value' => 'John A. Doe'
], ['active_role_name' => 'Data Coordinator']);

assertTest(true, $res_ans['success'] ?? false, "Data Coordinator should successfully answer query with updated value", $res_ans);

// Verify DB after answered
$query_db = $pdo->query("SELECT status FROM data_queries WHERE id = {$query_id}")->fetch();
assertTest('answered', $query_db['status'], "Query status should be 'answered'");

$history_db = $pdo->query("SELECT * FROM data_query_history WHERE query_id = {$query_id} ORDER BY id DESC LIMIT 1")->fetch();
assertTest('answered', $history_db['status_to'], "History status_to should be 'answered'");
assertTest('Original John Doe', $history_db['old_value'], "History old_value should be 'Original John Doe'");
assertTest('John A. Doe', $history_db['new_value'], "History new_value should be 'John A. Doe'");

$data_db = $pdo->query("SELECT value FROM subject_data WHERE subject_id = 1 AND form_id = 1 AND field_id = 5")->fetchColumn();
assertTest('Original John Doe', $data_db, "subject_data field value should remain UNCHANGED ('Original John Doe') after query is answered");

// Check get_field_details includes query_new_value and query_old_value
setTestRole($pdo, 'Data Manager');
$res_details = callAjax([
    'action' => 'get_field_details',
    'subject_id' => 1,
    'form_id' => 1,
    'field_id' => 5
], ['active_role_name' => 'Data Manager']);

$q_item = null;
foreach ($res_details['queries'] ?? [] as $q) {
    if ($q['id'] == $query_id) { $q_item = $q; break; }
}
assertTest(true, !empty($q_item), "get_field_details should return the query");
assertTest('John A. Doe', $q_item['query_new_value'] ?? null, "query_new_value should be 'John A. Doe'");
assertTest('Original John Doe', $q_item['query_old_value'] ?? null, "query_old_value should be 'Original John Doe'");

// Check that SDR is STILL submitted at this point (under review by manager)
$stat_db = $pdo->query("SELECT sdr_submitted FROM subject_form_status WHERE subject_id = 1 AND form_id = 1")->fetch();
assertTest(true, (bool)$stat_db['sdr_submitted'], "SDR should still be submitted while query is answered and awaiting closure");

echo "\nStep 3: Data Manager reviews and closes query (Close Query)\n";
setTestRole($pdo, 'Data Manager');
$res_close = callAjax([
    'action' => 'update_query_status',
    'query_id' => $query_id,
    'status' => 'closed',
    'remark' => 'Verified and accepted'
], ['active_role_name' => 'Data Manager']);

assertTest(true, $res_close['success'] ?? false, "Data Manager should successfully close query", $res_close);

// Verify Query is closed
$query_closed = $pdo->query("SELECT status FROM data_queries WHERE id = {$query_id}")->fetch();
assertTest('closed', $query_closed['status'], "Query status should be 'closed'");

// Verify SDR IS NOW REVOKED!
$stat_closed = $pdo->query("SELECT sdr_submitted, monitor_reviewed, manager_reviewed FROM subject_form_status WHERE subject_id = 1 AND form_id = 1")->fetch();
assertTest(false, (bool)$stat_closed['sdr_submitted'], "SDR should be REVOKED (sdr_submitted = false) because field was changed");
assertTest(false, (bool)$stat_closed['monitor_reviewed'], "monitor_reviewed should be false");
assertTest(false, (bool)$stat_closed['manager_reviewed'], "manager_reviewed should be false");

// Verify Audit Trail has sdr_revoked
$audit_entry = $pdo->query("SELECT * FROM data_audit_log WHERE subject_id = 1 AND form_id = 1 AND change_type = 'sdr_revoked' ORDER BY id DESC LIMIT 1")->fetch();
assertTest(true, !empty($audit_entry), "Audit trail should have an 'sdr_revoked' entry");
echo "   Audit entry reason: '{$audit_entry['reason_for_change']}'\n";

// Verify subject_data retains updated value
$final_data = $pdo->query("SELECT value FROM subject_data WHERE subject_id = 1 AND form_id = 1 AND field_id = 5")->fetchColumn();
assertTest('John A. Doe', $final_data, "Field value should remain the updated value 'John A. Doe'");

echo "\nStep 4: Test query closed WITHOUT field value change (SDR should NOT be revoked)\n";
// Re-mark as SDR submitted
$pdo->exec("UPDATE subject_form_status SET sdr_submitted = true WHERE subject_id = 1 AND form_id = 1");

// Manager raises second query (remark only)
setTestRole($pdo, 'Data Manager');
$res_raise2 = callAjax([
    'action' => 'add_query',
    'study_id' => 1,
    'subject_id' => 1,
    'visit_id' => 1,
    'form_id' => 1,
    'field_id' => 5,
    'query_text' => 'Clarification on source doc'
], ['active_role_name' => 'Data Manager']);
assertTest(true, $res_raise2['success'] ?? false, "Should raise query 2", $res_raise2);

$query_id2 = $pdo->query("SELECT id FROM data_queries WHERE subject_id = 1 AND form_id = 1 AND field_id = 5 AND status = 'new' ORDER BY id DESC LIMIT 1")->fetchColumn();

// Coordinator answers without field value
setTestRole($pdo, 'Data Coordinator');
$res_ans2 = callAjax([
    'action' => 'update_query_status',
    'query_id' => $query_id2,
    'status' => 'answered',
    'remark' => 'Source doc verified on site'
    // no field_value provided
], ['active_role_name' => 'Data Coordinator']);
assertTest(true, $res_ans2['success'] ?? false, "Should answer query 2 without value change", $res_ans2);

// Manager closes query 2
setTestRole($pdo, 'Data Manager');
$res_close2 = callAjax([
    'action' => 'update_query_status',
    'query_id' => $query_id2,
    'status' => 'closed',
    'remark' => 'Clarification accepted'
], ['active_role_name' => 'Data Manager']);
assertTest(true, $res_close2['success'] ?? false, "Should close query 2", $res_close2);

// SDR should STILL be submitted because no field was changed!
$stat_unchanged = $pdo->query("SELECT sdr_submitted FROM subject_form_status WHERE subject_id = 1 AND form_id = 1")->fetch();
assertTest(true, (bool)$stat_unchanged['sdr_submitted'], "SDR should NOT be revoked when query had no field value change");

echo "\n==================================================\n";
echo "All query resolution & SDR revocation tests passed!\n";
echo "==================================================\n";
