<?php
// test_workflow.php - Comprehensive Integration Test Suite with DB Role Overrides

require_once 'config/db.php';
$pdo = getDB();

// Ensure scratch directory exists
if (!is_dir('scratch')) {
    mkdir('scratch', 0777, true);
}

// Store original study_users assignments to restore at the end
$stmt_orig = $pdo->prepare("SELECT role_name, permissions FROM study_users WHERE user_id = 1 AND study_id = 1");
$stmt_orig->execute();
$original_assignments = $stmt_orig->fetchAll(PDO::FETCH_ASSOC);

// Store original is_required states for form 1
$orig_req = $pdo->query("SELECT id, is_required FROM form_fields WHERE form_id = 1")->fetchAll(PDO::FETCH_KEY_PAIR);

// Reset helper
function resetDBState($pdo) {
    $pdo->exec("DELETE FROM subject_form_status WHERE subject_id = 1 AND form_id = 1");
    $pdo->exec("DELETE FROM data_audit_log WHERE subject_id = 1 AND form_id = 1");
    $pdo->exec("DELETE FROM data_queries WHERE subject_id = 1 AND form_id = 1");
    $pdo->exec("DELETE FROM subject_data WHERE subject_id = 1 AND form_id = 1 AND field_id = 5");
}

// Function to set user role in DB
function setUserRoleInDB($pdo, $role_name) {
    $pdo->exec("DELETE FROM study_users WHERE user_id = 1 AND study_id = 1");
    $stmt = $pdo->prepare("INSERT INTO study_users (user_id, study_id, role_name, permissions) VALUES (1, 1, ?, 'all') RETURNING id");
    $stmt->execute([$role_name]);
    $new_id = $stmt->fetchColumn();
    $GLOBALS['current_assignment_id'] = $new_id;
    return $new_id;
}

function runTestCase($post, $session) {
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
    
    $tmp_file = 'scratch/tmp_test_runner.php';
    file_put_contents($tmp_file, $code);
    
    $output = shell_exec("php $tmp_file 2>&1");
    if (file_exists($tmp_file)) {
        unlink($tmp_file);
    }
    
    $decoded = json_decode($output, true);
    if ($decoded === null) {
        return ['success' => false, 'error' => 'Failed to decode JSON', 'raw_output' => $output];
    }
    
    return $decoded;
}

echo "==================================================\n";
echo "Clinical Trial SDR & Review Workflow Tests\n";
echo "==================================================\n\n";

resetDBState($pdo);

// Assert helper
function assertEquals($expected, $actual, $message) {
    if ($expected === $actual) {
        echo "✅ PASS: $message\n";
    } else {
        echo "❌ FAIL: $message\n";
        echo "   Expected: " . print_r($expected, true) . "\n";
        echo "   Actual:   " . print_r($actual, true) . "\n";
    }
}

// 1. Session data (role_name will be overridden by requireLogin() but we match it in DB too)
$coord_session = [
    'user_id' => 1,
    'username' => 'admin',
    'logged_in' => true,
    'active_study_id' => 1,
    'active_role_name' => 'Data Coordinator'
];

$monitor_session = [
    'user_id' => 1,
    'username' => 'admin',
    'logged_in' => true,
    'active_study_id' => 1,
    'active_role_name' => 'Data Monitor'
];

$manager_session = [
    'user_id' => 1,
    'username' => 'admin',
    'logged_in' => true,
    'active_study_id' => 1,
    'active_role_name' => 'Data Manager'
];

// Set only field 5 as is_required = TRUE for testing
$pdo->exec("UPDATE form_fields SET is_required = FALSE WHERE form_id = 1");
$pdo->exec("UPDATE form_fields SET is_required = TRUE WHERE id = 5");
echo "Form field 5 (Name) set as required/mandatory.\n\n";

// Test Case 1: Attempt to mark as SDR when mandatory fields are not completed
echo "Test Case 1: Coordinator attempts to mark SDR on incomplete form\n";
setUserRoleInDB($pdo, 'Data Coordinator');
$post = [
    'action' => 'update_review_status',
    'workflow_action' => 'mark_sdr',
    'subject_id' => 1,
    'form_id' => 1,
    'visit_id' => 1,
    'repeating_instance_id' => 0
];
$res = runTestCase($post, $coord_session);
if (isset($res['raw_output'])) {
    echo "Raw output:\n" . $res['raw_output'] . "\n";
}
assertEquals(false, $res['success'] ?? null, "Should fail to mark as SDR when mandatory fields are empty");
if (isset($res['error'])) {
    echo "   Error returned: {$res['error']}\n";
}

// Fill in the mandatory name field (field 5 is name in Demographics)
$pdo->prepare("INSERT INTO subject_data (study_id, subject_id, visit_id, form_id, repeating_instance_id, field_id, value, updated_by) VALUES (1, 1, 1, 1, 0, 5, 'John Doe', 1)")->execute();
echo "\nFilled in the mandatory 'Name' field.\n";

// Test Case 2: Coordinator marks SDR on completed form
echo "Test Case 2: Coordinator marks SDR on completed form\n";
setUserRoleInDB($pdo, 'Data Coordinator');
$res = runTestCase($post, $coord_session);
if (isset($res['raw_output'])) {
    echo "Raw output:\n" . $res['raw_output'] . "\n";
}
assertEquals(true, $res['success'] ?? null, "Should successfully mark as SDR when mandatory fields are complete");

// Check database values
$stat = $pdo->query("SELECT sdr_submitted, status FROM subject_form_status WHERE subject_id = 1 AND form_id = 1")->fetch();
assertEquals(true, (bool)($stat['sdr_submitted'] ?? false), "Database sdr_submitted should be true");

// Test Case 3: Verify editing is locked when SDR is submitted
echo "\nTest Case 3: Verify form is locked/read-only\n";
setUserRoleInDB($pdo, 'Data Coordinator');
$post_save = [
    'action' => 'save_data',
    'subject_id' => 1,
    'form_id' => 1,
    'visit_id' => 1,
    'repeating_instance_id' => 0,
    'data' => json_encode(['5' => 'New Name'])
];
$res = runTestCase($post_save, $coord_session);
if (isset($res['raw_output'])) {
    echo "Raw output:\n" . $res['raw_output'] . "\n";
}
assertEquals(false, $res['success'] ?? null, "Should fail to save data to a locked (SDR submitted) form");

// Test Case 4: Coordinator revokes SDR
echo "\nTest Case 4: Coordinator revokes SDR\n";
setUserRoleInDB($pdo, 'Data Coordinator');
$post_revoke = [
    'action' => 'update_review_status',
    'workflow_action' => 'revoke_sdr',
    'subject_id' => 1,
    'form_id' => 1,
    'visit_id' => 1,
    'repeating_instance_id' => 0
];
$res = runTestCase($post_revoke, $coord_session);
if (isset($res['raw_output'])) {
    echo "Raw output:\n" . $res['raw_output'] . "\n";
}
assertEquals(true, $res['success'] ?? null, "Should successfully revoke SDR");
$stat = $pdo->query("SELECT sdr_submitted FROM subject_form_status WHERE subject_id = 1 AND form_id = 1")->fetch();
assertEquals(false, (bool)($stat['sdr_submitted'] ?? false), "Database sdr_submitted should be false");

// Test Case 5: Verify edits are allowed again after SDR is revoked
echo "\nTest Case 5: Verify form is editable again after revoke\n";
setUserRoleInDB($pdo, 'Data Coordinator');
$res = runTestCase($post_save, $coord_session);
if (isset($res['raw_output'])) {
    echo "Raw output:\n" . $res['raw_output'] . "\n";
}
assertEquals(true, $res['success'] ?? null, "Should successfully save data to form after SDR is revoked");

// Test Case 6: Form Locked when Monitor has SDVed (Coordinator cannot revoke SDR)
echo "\nTest Case 6: Form Locked when Monitor has SDVed (Coordinator cannot revoke SDR)\n";
// Mark as SDR again
setUserRoleInDB($pdo, 'Data Coordinator');
runTestCase($post, $coord_session);

// Monitor marks as SDVed
setUserRoleInDB($pdo, 'Data Monitor');
$post_mon_review = [
    'action' => 'update_review_status',
    'workflow_action' => 'monitor_review',
    'subject_id' => 1,
    'form_id' => 1,
    'visit_id' => 1,
    'repeating_instance_id' => 0
];
$res = runTestCase($post_mon_review, $monitor_session);
if (isset($res['raw_output'])) {
    echo "Raw output:\n" . $res['raw_output'] . "\n";
}
assertEquals(true, $res['success'] ?? null, "Data Monitor should successfully mark form as SDVed");

$stat = $pdo->query("SELECT monitor_reviewed FROM subject_form_status WHERE subject_id = 1 AND form_id = 1")->fetch();
assertEquals(true, (bool)($stat['monitor_reviewed'] ?? false), "Database monitor_reviewed should be true");

// Coordinator attempts to revoke SDR when Monitor has SDVed (Must FAIL - Form is locked)
setUserRoleInDB($pdo, 'Data Coordinator');
$res = runTestCase($post_revoke, $coord_session);
assertEquals(false, $res['success'] ?? null, "Coordinator should NOT be allowed to revoke SDR when Monitor has SDVed");
if (isset($res['error'])) {
    echo "   Expected error: {$res['error']}\n";
}

// Monitor revokes SDVed (succeeds, resets SDR status, requires remarks)
setUserRoleInDB($pdo, 'Data Monitor');
$post_mon_revoke = [
    'action' => 'update_review_status',
    'workflow_action' => 'monitor_revoke',
    'subject_id' => 1,
    'form_id' => 1,
    'visit_id' => 1,
    'repeating_instance_id' => 0,
    'remarks' => 'Monitor revoking SDVed for testing correction'
];
$res_mon_rev = runTestCase($post_mon_revoke, $monitor_session);
assertEquals(true, $res_mon_rev['success'] ?? null, "Monitor should successfully revoke SDVed with remarks");

// Verify that SDR status and monitor_reviewed are reset
$stat = $pdo->query("SELECT sdr_submitted, monitor_reviewed FROM subject_form_status WHERE subject_id = 1 AND form_id = 1")->fetch();
assertEquals(false, (bool)($stat['sdr_submitted'] ?? false), "SDR submitted status should be reset to false");
assertEquals(false, (bool)($stat['monitor_reviewed'] ?? false), "Monitor review status should be reset to false");

// Test Case 7: Form Locked when Manager has DM Reviewed (Coordinator cannot revoke SDR)
echo "\nTest Case 7: Form Locked when Manager has DM Reviewed or Both Done\n";
// Coordinator marks SDR again
setUserRoleInDB($pdo, 'Data Coordinator');
runTestCase($post, $coord_session);

// Manager marks as DM Reviewed
setUserRoleInDB($pdo, 'Data Manager');
$post_mgr_review = [
    'action' => 'update_review_status',
    'workflow_action' => 'manager_review',
    'subject_id' => 1,
    'form_id' => 1,
    'visit_id' => 1,
    'repeating_instance_id' => 0
];
$res = runTestCase($post_mgr_review, $manager_session);
assertEquals(true, $res['success'] ?? null, "Data Manager should successfully mark form as DM Reviewed");

// Coordinator attempts to revoke SDR (expecting FAILURE because DM Reviewed is done)
setUserRoleInDB($pdo, 'Data Coordinator');
$res = runTestCase($post_revoke, $coord_session);
assertEquals(false, $res['success'] ?? null, "Coordinator should NOT be allowed to revoke SDR when Manager has DM Reviewed");

// Monitor also marks as SDVed (Both are now done)
setUserRoleInDB($pdo, 'Data Monitor');
$res_both = runTestCase($post_mon_review, $monitor_session);
assertEquals(true, $res_both['success'] ?? null, "Data Monitor should also be allowed to mark as SDVed");

// Coordinator attempts to revoke SDR when BOTH are done (expecting FAILURE)
setUserRoleInDB($pdo, 'Data Coordinator');
$res = runTestCase($post_revoke, $coord_session);
assertEquals(false, $res['success'] ?? null, "Coordinator should NOT be allowed to revoke SDR when BOTH reviews are complete");

// Manager revokes DM Reviewed (succeeds, resets SDR status, requires remarks)
setUserRoleInDB($pdo, 'Data Manager');
$post_mgr_revoke = [
    'action' => 'update_review_status',
    'workflow_action' => 'manager_revoke',
    'subject_id' => 1,
    'form_id' => 1,
    'visit_id' => 1,
    'repeating_instance_id' => 0,
    'remarks' => 'Manager revoking DM Reviewed for testing reasons'
];
$res = runTestCase($post_mgr_revoke, $manager_session);
assertEquals(true, $res['success'] ?? null, "Manager should successfully revoke DM Reviewed with valid remarks");

// Verify that SDR status and both reviews are cleared
$stat = $pdo->query("SELECT sdr_submitted, manager_reviewed, monitor_reviewed FROM subject_form_status WHERE subject_id = 1 AND form_id = 1")->fetch();
assertEquals(false, (bool)($stat['sdr_submitted'] ?? false), "SDR submitted status should be reset to false");
assertEquals(false, (bool)($stat['manager_reviewed'] ?? false), "Manager review status should be reset to false");
assertEquals(false, (bool)($stat['monitor_reviewed'] ?? false), "Monitor review status should be reset to false");

// Test Case 8: Strict Role Separation and Review Progress (50% / 100%)
echo "\nTest Case 8: Strict Role Separation and Review Progress (50% / 100%)\n";
resetDBState($pdo);

// 1. Fill mandatory name field
$pdo->prepare("INSERT INTO subject_data (study_id, subject_id, visit_id, form_id, repeating_instance_id, field_id, value, updated_by) VALUES (1, 1, 1, 1, 0, 5, 'John Doe Test', 1)")->execute();

// 2. Data Manager attempts to Mark as SDR (expecting failure)
setUserRoleInDB($pdo, 'Data Manager');
$res = runTestCase($post, $manager_session);
assertEquals(false, $res['success'] ?? null, "Data Manager should NOT be allowed to Mark as SDR");

// 3. Data Coordinator marks as SDR (succeeds)
setUserRoleInDB($pdo, 'Data Coordinator');
$res = runTestCase($post, $coord_session);
assertEquals(true, $res['success'] ?? null, "Data Coordinator should be allowed to Mark as SDR");

// 4. Data Manager attempts to perform Monitor Review (expecting failure)
setUserRoleInDB($pdo, 'Data Manager');
$res = runTestCase($post_mon_review, $manager_session);
assertEquals(false, $res['success'] ?? null, "Data Manager should NOT be allowed to perform Monitor Review");

// 5. Data Manager performs Manager Review (succeeds)
setUserRoleInDB($pdo, 'Data Manager');
$res = runTestCase($post_mgr_review, $manager_session);
assertEquals(true, $res['success'] ?? null, "Data Manager should be allowed to perform Manager Review");

// Check database flags
$stat = $pdo->query("SELECT sdr_submitted, monitor_reviewed, manager_reviewed FROM subject_form_status WHERE subject_id = 1 AND form_id = 1")->fetch();
assertEquals(true, (bool)($stat['sdr_submitted'] ?? false), "Database sdr_submitted should be true");
assertEquals(false, (bool)($stat['monitor_reviewed'] ?? false), "Database monitor_reviewed should be false");
assertEquals(true, (bool)($stat['manager_reviewed'] ?? false), "Database manager_reviewed should be true");

// 6. Data Monitor performs Monitor Review (succeeds)
setUserRoleInDB($pdo, 'Data Monitor');
$res = runTestCase($post_mon_review, $monitor_session);
assertEquals(true, $res['success'] ?? null, "Data Monitor should be allowed to perform Monitor Review");

// Check if Form SRVed audit entry is generated
$srved_log_exists = (bool)$pdo->query("SELECT COUNT(*) FROM data_audit_log WHERE subject_id = 1 AND form_id = 1 AND change_type = 'form_srved'")->fetchColumn();
assertEquals(true, $srved_log_exists, "Audit trail should contain 'form_srved' entry when both monitor and manager reviews are complete");

// Check both are reviewed
$stat = $pdo->query("SELECT sdr_submitted, monitor_reviewed, manager_reviewed FROM subject_form_status WHERE subject_id = 1 AND form_id = 1")->fetch();
assertEquals(true, (bool)($stat['sdr_submitted'] ?? false), "Database sdr_submitted should be true");
assertEquals(true, (bool)($stat['monitor_reviewed'] ?? false), "Database monitor_reviewed should be true");
assertEquals(true, (bool)($stat['manager_reviewed'] ?? false), "Database manager_reviewed should be true");

// 7. Revoke Manager Review (resets both reviews and SDR status)
setUserRoleInDB($pdo, 'Data Manager');
$post_mgr_revoke = [
    'action' => 'update_review_status',
    'workflow_action' => 'manager_revoke',
    'subject_id' => 1,
    'form_id' => 1,
    'visit_id' => 1,
    'repeating_instance_id' => 0,
    'remarks' => 'Manager revoking case 8 review'
];
$res = runTestCase($post_mgr_revoke, $manager_session);
assertEquals(true, $res['success'] ?? null, "Data Manager should be able to Revoke Manager Review");

// Verify final database state is Draft
$stat = $pdo->query("SELECT sdr_submitted, monitor_reviewed, manager_reviewed FROM subject_form_status WHERE subject_id = 1 AND form_id = 1")->fetch();
assertEquals(false, (bool)($stat['sdr_submitted'] ?? false), "Database sdr_submitted should be false");
assertEquals(false, (bool)($stat['monitor_reviewed'] ?? false), "Database monitor_reviewed should be false");
assertEquals(false, (bool)($stat['manager_reviewed'] ?? false), "Database manager_reviewed should be false");

// Verify audit trail entries
echo "\nVerifying Audit Trail entries in DB:\n";
$audit_trail = $pdo->query("SELECT change_type, reason_for_change FROM data_audit_log WHERE subject_id = 1 AND form_id = 1 AND change_type IN ('sdr_submitted', 'sdr_revoked', 'monitor_reviewed', 'monitor_revoked', 'manager_reviewed', 'manager_revoked', 'form_srved') ORDER BY action_at ASC, id ASC")->fetchAll();
foreach ($audit_trail as $idx => $entry) {
    echo "   [" . ($idx + 1) . "] Action: \"{$entry['reason_for_change']}\" (Type: {$entry['change_type']})\n";
}

// Test Case 9: Unresolved query constraints
echo "\nTest Case 9: Unresolved query constraints\n";
setUserRoleInDB($pdo, 'Data Monitor');
$post_add_q = [
    'action' => 'add_query',
    'subject_id' => 1,
    'form_id' => 1,
    'visit_id' => 1,
    'repeating_instance_id' => 0,
    'field_id' => 5,
    'query_text' => 'Test Query on Name'
];
$res_add = runTestCase($post_add_q, $monitor_session);
assertEquals(true, $res_add['success'] ?? null, "Should successfully raise a new query on field 5");

$res_add_again = runTestCase($post_add_q, $monitor_session);
assertEquals(false, $res_add_again['success'] ?? null, "Should fail to raise another query while one is unresolved");
if (isset($res_add_again['error'])) {
    echo "   Expected error: {$res_add_again['error']}\n";
}

// Test Case 10: Revoke review remarks validation
echo "\nTest Case 10: Revoke review remarks validation\n";
// Coordinator marks SDR again
setUserRoleInDB($pdo, 'Data Coordinator');
runTestCase($post, $coord_session);
// Manager reviews
setUserRoleInDB($pdo, 'Data Manager');
runTestCase($post_mgr_review, $manager_session);

// Try to revoke without remarks (expect failure)
$post_mgr_revoke_invalid = [
    'action' => 'update_review_status',
    'workflow_action' => 'manager_revoke',
    'subject_id' => 1,
    'form_id' => 1,
    'visit_id' => 1,
    'repeating_instance_id' => 0,
    'remarks' => ''
];
$res_invalid1 = runTestCase($post_mgr_revoke_invalid, $manager_session);
assertEquals(false, $res_invalid1['success'] ?? null, "Should fail to revoke review with empty remarks");

// Try to revoke with too short remarks (expect failure)
$post_mgr_revoke_invalid['remarks'] = 'short';
$res_invalid2 = runTestCase($post_mgr_revoke_invalid, $manager_session);
assertEquals(false, $res_invalid2['success'] ?? null, "Should fail to revoke review with remarks under 10 chars");
if (isset($res_invalid2['error'])) {
    echo "   Expected error: {$res_invalid2['error']}\n";
}

// Test Case 11: Button wordings and visibility for SDVed, DM Reviewed, and SDR locking
echo "\nTest Case 11: UI Button wordings and SDR Revoke visibility verification\n";
require_once 'includes/functions.php';

// 1. Coordinator when SDR not submitted -> "Mark as SDR"
$btn_coord_draft = renderWorkflowButtons(true, false, false, false, false, false, true);
assertEquals(true, strpos($btn_coord_draft, 'Mark as SDR') !== false, "Coordinator should see 'Mark as SDR'");

// 2. Coordinator when SDR submitted and neither reviewed -> "Revoke SDR"
$btn_coord_sdr = renderWorkflowButtons(true, false, false, true, false, false, true);
assertEquals(true, strpos($btn_coord_sdr, 'Revoke SDR') !== false, "Coordinator should see 'Revoke SDR' when neither reviewed");

// 3. Coordinator when Monitor SDVed -> "Revoke SDR" must NOT exist, "Form Locked" badge shown
$btn_coord_sdved = renderWorkflowButtons(true, false, false, true, true, false, true);
assertEquals(false, strpos($btn_coord_sdved, 'Revoke SDR'), "Coordinator must NOT see 'Revoke SDR' when Monitor SDVed");
assertEquals(true, strpos($btn_coord_sdved, 'Form Locked') !== false, "Coordinator should see 'Form Locked' badge when Monitor SDVed");

// 4. Coordinator when Manager DM Reviewed -> "Revoke SDR" must NOT exist, "Form Locked" badge shown
$btn_coord_dm = renderWorkflowButtons(true, false, false, true, false, true, true);
assertEquals(false, strpos($btn_coord_dm, 'Revoke SDR'), "Coordinator must NOT see 'Revoke SDR' when Manager DM Reviewed");
assertEquals(true, strpos($btn_coord_dm, 'Form Locked') !== false, "Coordinator should see 'Form Locked' badge when Manager DM Reviewed");

// 5. Coordinator when BOTH reviewed -> "Revoke SDR" must NOT exist, "Form Locked" badge shown
$btn_coord_both = renderWorkflowButtons(true, false, false, true, true, true, true);
assertEquals(false, strpos($btn_coord_both, 'Revoke SDR'), "Coordinator must NOT see 'Revoke SDR' when BOTH reviewed");
assertEquals(true, strpos($btn_coord_both, 'Form Locked') !== false, "Coordinator should see 'Form Locked' badge when BOTH reviewed");

// 6. Data Monitor buttons: "Mark as SDVed" when unverified, "Revoke SDVed" when verified
$btn_mon_unverified = renderWorkflowButtons(false, true, false, true, false, false, true);
assertEquals(true, strpos($btn_mon_unverified, 'Mark as SDVed') !== false, "Data Monitor should see 'Mark as SDVed' button");
$btn_mon_verified = renderWorkflowButtons(false, true, false, true, true, false, true);
assertEquals(true, strpos($btn_mon_verified, 'Revoke SDVed') !== false, "Data Monitor should see 'Revoke SDVed' button");

// 7. Data Manager buttons: "Mark as DM Reviewed" when unverified, "Revoke DM Reviewed" when verified
$btn_mgr_unverified = renderWorkflowButtons(false, false, true, true, false, false, true);
assertEquals(true, strpos($btn_mgr_unverified, 'Mark as DM Reviewed') !== false, "Data Manager should see 'Mark as DM Reviewed' button");
$btn_mgr_verified = renderWorkflowButtons(false, false, true, true, false, true, true);
assertEquals(true, strpos($btn_mgr_verified, 'Revoke DM Reviewed') !== false, "Data Manager should see 'Revoke DM Reviewed' button");

// 8. Review status text checks
$stat_under_review = getFormReviewStatus(['sdr_submitted' => 1, 'monitor_reviewed' => 0, 'manager_reviewed' => 0]);
assertEquals('Under Review', $stat_under_review['text'], "Review status should be 'Under Review'");

$stat_sdved = getFormReviewStatus(['sdr_submitted' => 1, 'monitor_reviewed' => 1, 'manager_reviewed' => 0]);
assertEquals('SDVed', $stat_sdved['text'], "Review status should be 'SDVed' when only Monitor reviewed");

$stat_dm_rev = getFormReviewStatus(['sdr_submitted' => 1, 'monitor_reviewed' => 0, 'manager_reviewed' => 1]);
assertEquals('DM Reviewed', $stat_dm_rev['text'], "Review status should be 'DM Reviewed' when only Manager reviewed");

$stat_both = getFormReviewStatus(['sdr_submitted' => 1, 'monitor_reviewed' => 1, 'manager_reviewed' => 1]);
assertEquals('SDVed & DM Reviewed', $stat_both['text'], "Review status should be 'SDVed & DM Reviewed' when both reviewed");

// Clean up database and restore original assignments
resetDBState($pdo);
$pdo->exec("DELETE FROM study_users WHERE user_id = 1 AND study_id = 1");
$stmt_rest = $pdo->prepare("INSERT INTO study_users (user_id, study_id, role_name, permissions) VALUES (1, 1, ?, ?)");
foreach ($original_assignments as $assign) {
    $stmt_rest->execute([$assign['role_name'], $assign['permissions']]);
}
foreach ($orig_req as $fid => $req) {
    $pdo->prepare("UPDATE form_fields SET is_required = ? WHERE id = ?")->execute([$req ? 1 : 0, $fid]);
}

echo "\n==================================================\n";
echo "Tests completed.\n";
echo "==================================================\n";
