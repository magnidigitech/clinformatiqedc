<?php
require_once 'includes/functions.php';
require_once 'includes/auth.php';

function assertCondition($desc, $cond) {
    if ($cond) {
        echo "PASS: $desc\n";
    } else {
        echo "FAIL: $desc\n";
        exit(1);
    }
}

echo "=== 1. TEST toBool HELPER ===\n";
assertCondition("toBool('t') === true", toBool('t') === true);
assertCondition("toBool('f') === false", toBool('f') === false);
assertCondition("toBool('true') === true", toBool('true') === true);
assertCondition("toBool('false') === false", toBool('false') === false);
assertCondition("toBool(true) === true", toBool(true) === true);
assertCondition("toBool(false) === false", toBool(false) === false);
assertCondition("toBool(1) === true", toBool(1) === true);
assertCondition("toBool(0) === false", toBool(0) === false);
assertCondition("toBool('1') === true", toBool('1') === true);
assertCondition("toBool('0') === false", toBool('0') === false);
assertCondition("toBool(null) === false", toBool(null) === false);
assertCondition("toBool('') === false", toBool('') === false);

echo "\n=== 2. TEST getFormReviewStatus WITH POSTGRESQL 't'/'f' STRINGS ===\n";
// Draft form with pg strings
$draft_stat = [
    'sdr_submitted' => 'f',
    'monitor_reviewed' => 'f',
    'manager_reviewed' => 'f',
    'pi_signed_off' => 'f',
    'status' => 'complete',
    'progress' => 100
];
$res_draft = getFormReviewStatus($draft_stat);
assertCondition("Draft complete form has text 'Draft (100% Complete)' (NOT 'PI Signed Off')", $res_draft['text'] === 'Draft (100% Complete)');

// Under Review
$under_rev_stat = [
    'sdr_submitted' => 't',
    'monitor_reviewed' => 'f',
    'manager_reviewed' => 'f',
    'pi_signed_off' => 'f',
    'status' => 'complete',
    'progress' => 100
];
$res_under_rev = getFormReviewStatus($under_rev_stat);
assertCondition("SDR submitted form has text 'Under Review'", $res_under_rev['text'] === 'Under Review');

// SDVed
$sdved_stat = [
    'sdr_submitted' => 't',
    'monitor_reviewed' => 't',
    'manager_reviewed' => 'f',
    'pi_signed_off' => 'f',
    'status' => 'verified',
    'progress' => 100
];
$res_sdved = getFormReviewStatus($sdved_stat);
assertCondition("Monitor reviewed form has text 'SDVed'", $res_sdved['text'] === 'SDVed');

// DM Reviewed
$dm_stat = [
    'sdr_submitted' => 't',
    'monitor_reviewed' => 'f',
    'manager_reviewed' => 't',
    'pi_signed_off' => 'f',
    'status' => 'verified',
    'progress' => 100
];
$res_dm = getFormReviewStatus($dm_stat);
assertCondition("Manager reviewed form has text 'DM Reviewed'", $res_dm['text'] === 'DM Reviewed');

// SDVed & DM Reviewed
$both_stat = [
    'sdr_submitted' => 't',
    'monitor_reviewed' => 't',
    'manager_reviewed' => 't',
    'pi_signed_off' => 'f',
    'status' => 'verified',
    'progress' => 100
];
$res_both = getFormReviewStatus($both_stat);
assertCondition("Both reviewed form has text 'SDVed & DM Reviewed'", $res_both['text'] === 'SDVed & DM Reviewed');

// PI Signed Off
$pi_stat = [
    'sdr_submitted' => 't',
    'monitor_reviewed' => 't',
    'manager_reviewed' => 't',
    'pi_signed_off' => 't',
    'status' => 'verified',
    'progress' => 100
];
$res_pi = getFormReviewStatus($pi_stat);
assertCondition("PI signed off form has text 'PI Signed Off'", $res_pi['text'] === 'PI Signed Off');


echo "\n=== 3. TEST renderWorkflowButtons WITH POSTGRESQL 't'/'f' STRINGS ===\n";
// Data Monitor on Under Review form ('sdr_submitted' => 't', 'monitor_reviewed' => 'f')
$btn_monitor_under_rev = renderWorkflowButtons(false, true, false, 't', 'f', 'f', true, 'f', false);
assertCondition("Monitor sees 'Mark as SDVed' button when monitor_reviewed is 'f'", strpos($btn_monitor_under_rev, 'Mark as SDVed') !== false);
assertCondition("Monitor does NOT see 'PI Signed Off' badge when pi_signed_off is 'f'", strpos($btn_monitor_under_rev, 'PI Signed Off') === false);

// Data Monitor on SDVed form ('sdr_submitted' => 't', 'monitor_reviewed' => 't')
$btn_monitor_sdved = renderWorkflowButtons(false, true, false, 't', 't', 'f', true, 'f', false);
assertCondition("Monitor sees 'Revoke SDVed' button when monitor_reviewed is 't'", strpos($btn_monitor_sdved, 'Revoke SDVed') !== false);
assertCondition("Monitor does NOT see 'Mark as SDVed' when already SDVed", strpos($btn_monitor_sdved, 'Mark as SDVed') === false);

// Data Manager on Under Review form ('sdr_submitted' => 't', 'manager_reviewed' => 'f')
$btn_manager_under_rev = renderWorkflowButtons(false, false, true, 't', 'f', 'f', true, 'f', false);
assertCondition("Manager sees 'Mark as DM Reviewed' button when manager_reviewed is 'f'", strpos($btn_manager_under_rev, 'Mark as DM Reviewed') !== false);

// Data Manager on DM Reviewed form ('sdr_submitted' => 't', 'manager_reviewed' => 't')
$btn_manager_reviewed = renderWorkflowButtons(false, false, true, 't', 'f', 't', true, 'f', false);
assertCondition("Manager sees 'Revoke DM Reviewed' button when manager_reviewed is 't'", strpos($btn_manager_reviewed, 'Revoke DM Reviewed') !== false);

// When PI signed off is 't'
$btn_pi_locked = renderWorkflowButtons(false, true, true, 't', 't', 't', true, 't', false);
assertCondition("When PI signed off is 't', form shows 'PI Signed Off' badge", strpos($btn_pi_locked, 'PI Signed Off') !== false);


echo "\n=== 4. TEST ROLE CASE INSENSITIVITY & BULK ROLES ===\n";
assertCondition("getPermissionsForRole('Data Monitor') contains raise_query", strpos(getPermissionsForRole('Data Monitor'), 'raise_query') !== false);
assertCondition("getPermissionsForRole('data monitor') in small case contains raise_query", strpos(getPermissionsForRole('data monitor'), 'raise_query') !== false);
assertCondition("getPermissionsForRole('DATA MONITOR') in upper case contains raise_query", strpos(getPermissionsForRole('DATA MONITOR'), 'raise_query') !== false);
assertCondition("getPermissionsForRole('Data Manager') contains raise_query", strpos(getPermissionsForRole('Data Manager'), 'raise_query') !== false);
assertCondition("getPermissionsForRole('data manager') in small case contains raise_query", strpos(getPermissionsForRole('data manager'), 'raise_query') !== false);
assertCondition("getPermissionsForRole('data coordinator') in small case contains add_subject", strpos(getPermissionsForRole('data coordinator'), 'add_subject') !== false);

// Check hasPermission with small case session
$_SESSION['active_role_name'] = 'data monitor';
assertCondition("hasPermission('raise_query') with 'data monitor'", hasPermission('raise_query') === true);
assertCondition("hasPermission('verify') with 'data monitor'", hasPermission('verify') === true);
assertCondition("isOversightRole() with 'data monitor'", isOversightRole() === true);

$_SESSION['active_role_name'] = 'data manager';
assertCondition("hasPermission('raise_query') with 'data manager'", hasPermission('raise_query') === true);
assertCondition("hasPermission('verify') with 'data manager'", hasPermission('verify') === true);
assertCondition("isOversightRole() with 'data manager'", isOversightRole() === true);

$_SESSION['active_role_name'] = 'data coordinator';
assertCondition("hasPermission('add_subject') with 'data coordinator'", hasPermission('add_subject') === true);
assertCondition("isOversightRole() with 'data coordinator' is false", isOversightRole() === false);

echo "\nALL WORKFLOW & POSTGRESQL BOOLEAN TESTS PASSED!\n";
