<?php
require_once 'includes/functions.php';
require_once 'includes/auth.php';

function runTest($desc, $cond) {
    if ($cond) {
        echo "PASS: $desc\n";
    } else {
        echo "FAIL: $desc\n";
        exit(1);
    }
}

// 1. Test Data Monitor
$_SESSION['username'] = 'testmonitor';
$_SESSION['user_id'] = 101;
$_SESSION['active_role_name'] = 'Data Monitor';
$_SESSION['active_study_id'] = 1;
$_SESSION['assignments'] = [
    ['id' => 1, 'study_id' => 1, 'role_name' => 'Data Monitor', 'permissions' => '[]']
];

runTest("Monitor: isOversightRole", isOversightRole() === true);
runTest("Monitor: isRestrictedToCreatedSubjects is false", isRestrictedToCreatedSubjects() === false);
runTest("Monitor: hasPermission('raise_query')", hasPermission('raise_query') === true);
runTest("Monitor: hasPermission('verify')", hasPermission('verify') === true);
runTest("Monitor: hasPermission('enter_data') is false", hasPermission('enter_data') === false);
runTest("Monitor: hasPermission('add_subject') is false", hasPermission('add_subject') === false);

// 2. Test Data Manager
$_SESSION['username'] = 'testmanager';
$_SESSION['user_id'] = 102;
$_SESSION['active_role_name'] = 'Data Manager';
$_SESSION['assignments'] = [
    ['id' => 2, 'study_id' => 1, 'role_name' => 'Data Manager', 'permissions' => '[]']
];

runTest("Manager: isOversightRole", isOversightRole() === true);
runTest("Manager: isRestrictedToCreatedSubjects is false", isRestrictedToCreatedSubjects() === false);
runTest("Manager: hasPermission('raise_query')", hasPermission('raise_query') === true);
runTest("Manager: hasPermission('verify')", hasPermission('verify') === true);
runTest("Manager: hasPermission('enter_data') is false", hasPermission('enter_data') === false);

// 3. Test Data Coordinator
$_SESSION['username'] = 'testcoord';
$_SESSION['user_id'] = 103;
$_SESSION['active_role_name'] = 'Data Coordinator';
$_SESSION['assignments'] = [
    ['id' => 3, 'study_id' => 1, 'role_name' => 'Data Coordinator', 'permissions' => '[]']
];

runTest("Coordinator: isOversightRole is false", isOversightRole() === false);
runTest("Coordinator: isRestrictedToCreatedSubjects is true", isRestrictedToCreatedSubjects() === true);
runTest("Coordinator: hasPermission('raise_query') is false", hasPermission('raise_query') === false);
runTest("Coordinator: hasPermission('enter_data')", hasPermission('enter_data') === true);
runTest("Coordinator: hasPermission('add_subject')", hasPermission('add_subject') === true);

// 4. Test Multi-role user: Has Coordinator assignment, but active role is Data Monitor
$_SESSION['username'] = 'multirole_user';
$_SESSION['user_id'] = 104;
$_SESSION['active_role_name'] = 'Data Monitor';
$_SESSION['assignments'] = [
    ['id' => 4, 'study_id' => 1, 'role_name' => 'Data Coordinator', 'permissions' => '[]'],
    ['id' => 5, 'study_id' => 1, 'role_name' => 'Data Monitor', 'permissions' => '[]']
];

runTest("Multi-role in Monitor: isOversightRole", isOversightRole() === true);
runTest("Multi-role in Monitor: isRestrictedToCreatedSubjects is false", isRestrictedToCreatedSubjects() === false);
runTest("Multi-role in Monitor: hasPermission('raise_query')", hasPermission('raise_query') === true);
runTest("Multi-role in Monitor: hasPermission('verify')", hasPermission('verify') === true);
runTest("Multi-role in Monitor: hasPermission('add_subject') via coordinator assignment", hasPermission('add_subject') === true);
runTest("Multi-role in Monitor: hasPermission('enter_data') is false (monitor cannot edit data)", hasPermission('enter_data') === false);

// 5. Test Multi-role user: Switch active role to Data Coordinator
$_SESSION['active_role_name'] = 'Data Coordinator';

runTest("Multi-role in Coord: isOversightRole is false", isOversightRole() === false);
runTest("Multi-role in Coord: isRestrictedToCreatedSubjects is true", isRestrictedToCreatedSubjects() === true);
runTest("Multi-role in Coord: hasPermission('raise_query') is false", hasPermission('raise_query') === false);
runTest("Multi-role in Coord: hasPermission('enter_data')", hasPermission('enter_data') === true);
runTest("Multi-role in Coord: hasPermission('add_subject')", hasPermission('add_subject') === true);

echo "\nALL ROLE & PERMISSION TESTS PASSED!\n";
