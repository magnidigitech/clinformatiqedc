<?php
// test_common_form_query_field_types.php
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
echo "TESTING COMMON FORMS QUERY FIELD TYPES & WORKFLOW\n";
echo "========================================================\n\n";

$study_id = 4;
$subject_id = 12;

// 1. Create or ensure an AE common form record exists for testing
$stmt = $pdo->prepare("SELECT id FROM subject_common_records WHERE study_id = ? AND subject_id = ? AND form_type = 'AE' AND is_voided = FALSE ORDER BY id DESC LIMIT 1");
$stmt->execute([$study_id, $subject_id]);
$rec_id = $stmt->fetchColumn();

if (!$rec_id) {
    // Create an AE record
    $initial_data = [
        'ae_term' => 'Headache',
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-05',
        'severity' => 'Mild',
        'seriousness' => 'Yes',
        'seriousness_criteria' => ['Hospitalization / prolongation of hospitalization'],
        'causality' => 'Possible',
        'action_taken' => 'None',
        'outcome' => 'Recovered / Resolved',
        'concomitant_treatment' => 'No'
    ];
    $stmt_ins = $pdo->prepare("
        INSERT INTO subject_common_records (study_id, subject_id, form_type, seq_number, record_number, data_json, status, sdr_submitted, monitor_reviewed, manager_reviewed, revision, created_by)
        VALUES (?, ?, 'AE', 1, 'AE-TEST-01', ?, 'complete', TRUE, FALSE, FALSE, 1, 1)
    ");
    $stmt_ins->execute([$study_id, $subject_id, json_encode($initial_data)]);
    $rec_id = $pdo->lastInsertId();
} else {
    // Reset test record to known baseline
    $initial_data = [
        'ae_term' => 'Headache',
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-05',
        'severity' => 'Mild',
        'seriousness' => 'Yes',
        'seriousness_criteria' => ['Hospitalization / prolongation of hospitalization'],
        'causality' => 'Possible',
        'action_taken' => 'None',
        'outcome' => 'Recovered / Resolved',
        'concomitant_treatment' => 'No'
    ];
    $stmt_reset = $pdo->prepare("
        UPDATE subject_common_records 
        SET data_json = ?, sdr_submitted = TRUE, monitor_reviewed = FALSE, manager_reviewed = FALSE, revision = 1
        WHERE id = ?
    ");
    $stmt_reset->execute([json_encode($initial_data), $rec_id]);
}

echo "Using Common Form Record ID: $rec_id\n\n";

// -------------------------------------------------------------------------
// TEST 1: DROPDOWN FIELD (SEVERITY)
// -------------------------------------------------------------------------
echo "--- TEST 1: Dropdown Field Query (severity) ---\n";

// Monitor raises query on severity
$res_raise = callAjax('ajax_common_forms.php', [
    'action' => 'raise_query',
    'record_id' => $rec_id,
    'field_name' => 'severity',
    'query_text' => 'Severity seems inconsistent with hospitalization, please verify if Moderate or Severe.'
], ['active_role_name' => 'Data Monitor']);

assertTest(true, $res_raise['success'] ?? false, "Monitor raised query on dropdown field 'severity'");

// Get query ID
$stmt_q = $pdo->prepare("SELECT id FROM common_form_queries WHERE record_id = ? AND field_name = 'severity' AND status = 'open' ORDER BY id DESC LIMIT 1");
$stmt_q->execute([$rec_id]);
$query_id = $stmt_q->fetchColumn();
assertTest(true, $query_id > 0, "Query was created in DB with ID $query_id");

// Coordinator responds selecting 'Severe' from dropdown
$res_ans = callAjax('ajax_common_forms.php', [
    'action' => 'answer_query',
    'query_id' => $query_id,
    'field_value' => 'Severe',
    'remark' => 'PI re-evaluated patient condition as Severe.'
], ['active_role_name' => 'Data Coordinator']);

assertTest(true, $res_ans['success'] ?? false, "Coordinator answered query proposing dropdown value 'Severe'");

// Check proposed value in history
$stmt_hist = $pdo->prepare("SELECT old_value, new_value FROM common_form_query_history WHERE query_id = ? AND action_type = 'answered' ORDER BY id DESC LIMIT 1");
$stmt_hist->execute([$query_id]);
$hist = $stmt_hist->fetch(PDO::FETCH_ASSOC);
assertTest('Mild', $hist['old_value'], "History records old_value as 'Mild'");
assertTest('Severe', $hist['new_value'], "History records proposed new_value as 'Severe'");

// Monitor closes query
$res_close = callAjax('ajax_common_forms.php', [
    'action' => 'close_query',
    'query_id' => $query_id,
    'remark' => 'Accepted PI assessment of Severe.'
], ['active_role_name' => 'Data Monitor']);

assertTest(true, $res_close['success'] ?? false, "Monitor closed query");

// Verify clinical data updated in DB
$stmt_check = $pdo->prepare("SELECT data_json, sdr_submitted, revision FROM subject_common_records WHERE id = ?");
$stmt_check->execute([$rec_id]);
$rec_after = $stmt_check->fetch(PDO::FETCH_ASSOC);
$data_after = json_decode($rec_after['data_json'], true);

assertTest('Severe', $data_after['severity'], "Record severity successfully updated to 'Severe' in data_json");
assertTest(false, (bool)$rec_after['sdr_submitted'], "SDR status revoked on query closure with value change");
assertTest(2, (int)$rec_after['revision'], "Revision incremented to 2");

// -------------------------------------------------------------------------
// TEST 2: DATE FIELD (START_DATE)
// -------------------------------------------------------------------------
echo "\n--- TEST 2: Date Field Query (start_date) ---\n";

// Monitor marks SDR again so query can be tested cleanly
$pdo->exec("UPDATE subject_common_records SET sdr_submitted = TRUE, monitor_reviewed = FALSE, manager_reviewed = FALSE WHERE id = $rec_id");

// Monitor raises query on start_date
$res_raise_date = callAjax('ajax_common_forms.php', [
    'action' => 'raise_query',
    'record_id' => $rec_id,
    'field_name' => 'start_date',
    'query_text' => 'Source note shows onset on 2026-01-02, please check.'
], ['active_role_name' => 'Data Monitor']);

assertTest(true, $res_raise_date['success'] ?? false, "Monitor raised query on date field 'start_date'");

$stmt_q->execute([$rec_id]);
$query_id_date = $pdo->query("SELECT id FROM common_form_queries WHERE record_id = $rec_id AND field_name = 'start_date' AND status = 'open' ORDER BY id DESC LIMIT 1")->fetchColumn();

// Coordinator answers with proposed date '2026-01-02'
$res_ans_date = callAjax('ajax_common_forms.php', [
    'action' => 'answer_query',
    'query_id' => $query_id_date,
    'field_value' => '2026-01-02',
    'remark' => 'Corrected start date to 2026-01-02.'
], ['active_role_name' => 'Data Coordinator']);

assertTest(true, $res_ans_date['success'] ?? false, "Coordinator answered query proposing date '2026-01-02'");

// Monitor closes query
$res_close_date = callAjax('ajax_common_forms.php', [
    'action' => 'close_query',
    'query_id' => $query_id_date,
    'remark' => 'Date verified.'
], ['active_role_name' => 'Data Monitor']);

assertTest(true, $res_close_date['success'] ?? false, "Monitor closed date query");

$data_after_date = json_decode($pdo->query("SELECT data_json FROM subject_common_records WHERE id = $rec_id")->fetchColumn(), true);
assertTest('2026-01-02', $data_after_date['start_date'], "Record start_date successfully updated to '2026-01-02'");

// -------------------------------------------------------------------------
// TEST 3: CHECKBOX GROUP (SERIOUSNESS_CRITERIA)
// -------------------------------------------------------------------------
echo "\n--- TEST 3: Checkbox Group Query (seriousness_criteria) ---\n";

// Monitor raises query on seriousness_criteria
$res_raise_crit = callAjax('ajax_common_forms.php', [
    'action' => 'raise_query',
    'record_id' => $rec_id,
    'field_name' => 'seriousness_criteria',
    'query_text' => 'Patient was also reported to have life-threatening condition.'
], ['active_role_name' => 'Data Monitor']);

assertTest(true, $res_raise_crit['success'] ?? false, "Monitor raised query on 'seriousness_criteria'");

$query_id_crit = $pdo->query("SELECT id FROM common_form_queries WHERE record_id = $rec_id AND field_name = 'seriousness_criteria' AND status = 'open' ORDER BY id DESC LIMIT 1")->fetchColumn();

// Coordinator answers with combined criteria
$res_ans_crit = callAjax('ajax_common_forms.php', [
    'action' => 'answer_query',
    'query_id' => $query_id_crit,
    'field_value' => 'Life-threatening, Hospitalization / prolongation of hospitalization',
    'remark' => 'Added Life-threatening criterion as confirmed by PI.'
], ['active_role_name' => 'Data Coordinator']);

assertTest(true, $res_ans_crit['success'] ?? false, "Coordinator answered query with multi criteria");

// Monitor closes query
$res_close_crit = callAjax('ajax_common_forms.php', [
    'action' => 'close_query',
    'query_id' => $query_id_crit,
    'remark' => 'Criteria updated.'
], ['active_role_name' => 'Data Monitor']);

assertTest(true, $res_close_crit['success'] ?? false, "Monitor closed criteria query");

$data_after_crit = json_decode($pdo->query("SELECT data_json FROM subject_common_records WHERE id = $rec_id")->fetchColumn(), true);
assertTest(true, is_array($data_after_crit['seriousness_criteria']), "seriousness_criteria is stored as array in data_json");
assertTest(true, in_array('Life-threatening', $data_after_crit['seriousness_criteria']), "seriousness_criteria contains 'Life-threatening'");
assertTest(true, in_array('Hospitalization / prolongation of hospitalization', $data_after_crit['seriousness_criteria']), "seriousness_criteria contains 'Hospitalization / prolongation of hospitalization'");

// -------------------------------------------------------------------------
// TEST 4: JAVASCRIPT LOGIC VALIDATION
// -------------------------------------------------------------------------
echo "\n--- TEST 4: Frontend Input Generator Validation (Node/JS) ---\n";

$js_test_code = <<<'NODE'
const fs = require('fs');
const content = fs.readFileSync('subject_data_entry.php', 'utf8');

// Extract COMMON_FORM_FIELD_MAP and renderCommonQueryProposedInput
const mapMatch = content.match(/const COMMON_FORM_FIELD_MAP = \{[\s\S]*?\n\};/);
if (!mapMatch) {
    console.error("COMMON_FORM_FIELD_MAP not found");
    process.exit(1);
}

const funcMatch = content.match(/function renderCommonQueryProposedInput\([\s\S]*?\n\}/);
if (!funcMatch) {
    console.error("renderCommonQueryProposedInput not found");
    process.exit(1);
}

function escapeHtml(text) {
    return String(text).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
}

eval(mapMatch[0].replace('const COMMON_FORM_FIELD_MAP =', 'global.COMMON_FORM_FIELD_MAP ='));
eval(funcMatch[0]);

let testsPassed = 0;
let testsFailed = 0;

function assertJs(condition, desc) {
    if (condition) {
        console.log("✅ PASS: " + desc);
        testsPassed++;
    } else {
        console.error("❌ FAIL: " + desc);
        testsFailed++;
    }
}

// 1. Dropdown field: AE severity
const htmlSeverity = renderCommonQueryProposedInput('AE', 'severity', 101, 'Mild');
assertJs(htmlSeverity.includes('<select id="inp_ans_val_101"'), "AE severity renders as select element");
assertJs(htmlSeverity.includes('<option value="Mild">Mild (Current Value)</option>'), "AE severity marks current value 'Mild'");
assertJs(htmlSeverity.includes('<option value="Moderate">Moderate</option>'), "AE severity contains Moderate option");
assertJs(htmlSeverity.includes('<option value="Severe">Severe</option>'), "AE severity contains Severe option");

// 2. Dropdown field: MH ongoing
const htmlOngoing = renderCommonQueryProposedInput('MH', 'ongoing', 102, 'Yes');
assertJs(htmlOngoing.includes('<select id="inp_ans_val_102"'), "MH ongoing renders as select element");
assertJs(htmlOngoing.includes('<option value="Yes">Yes (Current Value)</option>'), "MH ongoing has Yes as current");
assertJs(htmlOngoing.includes('<option value="No">No</option>'), "MH ongoing has No option");

// 3. Date field: CM start_date
const htmlDate = renderCommonQueryProposedInput('CM', 'start_date', 103, '2026-02-01');
assertJs(htmlDate.includes('<input type="date" id="inp_ans_val_103"'), "CM start_date renders as date input");

// 4. Textarea field: MH comments
const htmlComments = renderCommonQueryProposedInput('MH', 'comments', 104, 'Some notes');
assertJs(htmlComments.includes('<textarea id="inp_ans_val_104"'), "MH comments renders as textarea");

// 5. Number field: TA sum_longest_dia
const htmlDia = renderCommonQueryProposedInput('TA', 'sum_longest_dia', 105, '15.5');
assertJs(htmlDia.includes('<input type="number" step="0.1" id="inp_ans_val_105"'), "TA sum_longest_dia renders as number input with step 0.1");

// 6. Checkbox group: AE seriousness_criteria
const htmlCrit = renderCommonQueryProposedInput('AE', 'seriousness_criteria', 106, ['Death']);
assertJs(htmlCrit.includes('class="inp_crit_cb_106"'), "AE seriousness_criteria renders checkboxes");
assertJs(htmlCrit.includes('value="Death"'), "AE seriousness_criteria contains Death checkbox");
assertJs(htmlCrit.includes('id="inp_ans_val_106"'), "AE seriousness_criteria contains hidden sync input");

// 7. Text field: AE ae_term
const htmlTerm = renderCommonQueryProposedInput('AE', 'ae_term', 107, 'Headache');
assertJs(htmlTerm.includes('<input type="text" id="inp_ans_val_107"'), "AE ae_term renders as text input");

// 8. RECIST Overall Response: TA recist_overall_response
const htmlTaResp = renderCommonQueryProposedInput('TA', 'recist_overall_response', 108, 'PR — Partial Response');
assertJs(htmlTaResp.includes('<select id="inp_ans_val_108"'), "TA recist_overall_response renders as select");
assertJs(htmlTaResp.includes('CR — Complete Response'), "TA recist_overall_response has CR option");
assertJs(htmlTaResp.includes('PR — Partial Response (Current Value)'), "TA recist_overall_response has PR option marked current");

if (testsFailed > 0) {
    process.exit(1);
}
NODE;

$node_tmp = __DIR__ . '/scratch_test_js.js';
file_put_contents($node_tmp, $js_test_code);
$js_out = shell_exec("node " . escapeshellarg($node_tmp) . " 2>&1");
if (file_exists($node_tmp)) {
    unlink($node_tmp);
}
echo $js_out;

echo "\n========================================================\n";
echo "SUMMARY: Passed: $passCount, Failed: $failCount\n";
echo "========================================================\n";
