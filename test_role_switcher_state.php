<?php
// Test Role Switcher State Persistence Simulation

function runRoleSwitcherTests() {
    $tests_passed = 0;
    $tests_total = 0;

    $assertCondition = function($desc, $cond) use (&$tests_passed, &$tests_total) {
        $tests_total++;
        if ($cond) {
            $tests_passed++;
            echo "  [PASS] {$desc}\n";
        } else {
            echo "  [FAIL] {$desc}\n";
        }
    };

    echo "Running Role Switcher State Persistence Tests...\n";

    // Setup initial session
    $_SESSION = [];
    $study_id = 1;
    $assignments = [
        ['id' => 10, 'study_id' => 1, 'role_name' => 'Data Coordinator', 'study_name' => 'Miper', 'study_code' => 'MIP-001', 'permissions' => '{"view":true,"add":true,"add_subject":true,"enter_data":true}'],
        ['id' => 11, 'study_id' => 1, 'role_name' => 'Data Monitor', 'study_name' => 'Miper', 'study_code' => 'MIP-001', 'permissions' => '{"view":true,"query":true,"verify":true}'],
        ['id' => 12, 'study_id' => 1, 'role_name' => 'Data Manager', 'study_name' => 'Miper', 'study_code' => 'MIP-001', 'permissions' => '{"view":true,"query":true,"verify":true}']
    ];
    $_SESSION['assignments'] = $assignments;
    $_SESSION['active_assignment_id'] = 10;
    $_SESSION['active_study_id'] = 1;
    $_SESSION['active_role_name'] = 'Data Coordinator';
    $_SESSION['active_study_name'] = 'Miper';

    // 1. Initial State
    $assertCondition("Initial role is Data Coordinator", $_SESSION['active_role_name'] === 'Data Coordinator');

    // 2. Simulate User Switching to Data Monitor (id: 11)
    $_POST = [
        'switch_role_study' => '1',
        'switch_study_id' => '1',
        'new_role_id' => '11',
        'redirect_to' => 'study.php'
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';

    // Switch handler logic
    $target_id = $_POST['new_role_id'];
    $target_study_id = $_POST['switch_study_id'] ?? $_SESSION['active_study_id'] ?? null;
    $valid_assignment_id = null;
    $target_norm = strtolower(trim((string)$target_id));

    foreach (($_SESSION['assignments'] ?? []) as $assign) {
        $study_match = (!$target_study_id || $assign['study_id'] == $target_study_id);
        if ($study_match) {
            if ($assign['id'] == $target_id || strtolower(trim($assign['role_name'] ?? '')) === $target_norm) {
                $valid_assignment_id = $assign['id'];
                break;
            }
        }
    }

    $assertCondition("Switch handler finds valid assignment ID 11", $valid_assignment_id == 11);

    // Apply setActiveContext
    function setActiveContextTest($assignment_id) {
        foreach ($_SESSION['assignments'] as $assign) {
            if ($assign['id'] == $assignment_id) {
                $_SESSION['active_assignment_id'] = $assign['id'];
                $_SESSION['active_study_id'] = $assign['study_id'];
                $_SESSION['active_role_name'] = $assign['role_name'];
                $_SESSION['active_study_name'] = $assign['study_name'];
                $_SESSION['active_study_code'] = $assign['study_code'] ?? 'None';
                return true;
            }
        }
        return false;
    }

    setActiveContextTest($valid_assignment_id);
    $assertCondition("Active role switched to Data Monitor", $_SESSION['active_role_name'] === 'Data Monitor');
    $assertCondition("Active assignment ID is 11", $_SESSION['active_assignment_id'] == 11);

    // 3. Simulate Bulk Role Reassignment in DB (New IDs generated: 50, 51, 52)
    // The old ID 11 no longer exists!
    $updated_db_assignments = [
        ['id' => 50, 'study_id' => 1, 'role_name' => 'Data Coordinator', 'study_name' => 'Miper', 'study_code' => 'MIP-001', 'permissions' => '{"view":true,"add":true}'],
        ['id' => 51, 'study_id' => 1, 'role_name' => 'Data Monitor', 'study_name' => 'Miper', 'study_code' => 'MIP-001', 'permissions' => '{"view":true,"query":true}'],
        ['id' => 52, 'study_id' => 1, 'role_name' => 'Data Manager', 'study_name' => 'Miper', 'study_code' => 'MIP-001', 'permissions' => '{"view":true,"query":true}']
    ];

    // Simulate refreshUserAssignments with new DB state
    function refreshAssignmentsTest($new_db_assignments) {
        $_SESSION['assignments'] = $new_db_assignments;
        $active_id = $_SESSION['active_assignment_id'] ?? null;
        $still_valid = false;
        
        if ($active_id) {
            foreach ($new_db_assignments as $assign) {
                if ($assign['id'] == $active_id) {
                    $still_valid = true;
                    $_SESSION['active_study_id'] = $assign['study_id'];
                    $_SESSION['active_role_name'] = $assign['role_name'];
                    break;
                }
            }
        }

        // Role recovery by role name and study if ID was regenerated
        if (!$still_valid && !empty($new_db_assignments)) {
            $saved_role_norm = strtolower(trim($_SESSION['active_role_name'] ?? ''));
            $saved_study_id = $_SESSION['active_study_id'] ?? null;
            
            if ($saved_role_norm !== '' && $saved_study_id) {
                foreach ($new_db_assignments as $assign) {
                    if ($assign['study_id'] == $saved_study_id && strtolower(trim($assign['role_name'] ?? '')) === $saved_role_norm) {
                        $still_valid = true;
                        setActiveContextTest($assign['id']);
                        break;
                    }
                }
            }

            if (!$still_valid && $saved_study_id) {
                foreach ($new_db_assignments as $assign) {
                    if ($assign['study_id'] == $saved_study_id) {
                        $still_valid = true;
                        setActiveContextTest($assign['id']);
                        break;
                    }
                }
            }

            if (!$still_valid) {
                setActiveContextTest($new_db_assignments[0]['id']);
            }
        }
    }

    refreshAssignmentsTest($updated_db_assignments);
    $assertCondition("After bulk role ID regeneration, role REMAINS Data Monitor", $_SESSION['active_role_name'] === 'Data Monitor');
    $assertCondition("Active assignment ID updated to new ID 51", $_SESSION['active_assignment_id'] == 51);

    // 4. Simulate Visiting Dashboard (ensure initializeUserRoles does NOT override existing active role)
    function initializeUserRolesTest($assignments) {
        $_SESSION['assignments'] = $assignments;
        
        if (!empty($assignments)) {
            $active_id = $_SESSION['active_assignment_id'] ?? null;
            $active_role = $_SESSION['active_role_name'] ?? null;
            $active_study = $_SESSION['active_study_id'] ?? null;
            $already_valid = false;

            if ($active_id) {
                foreach ($assignments as $a) {
                    if ($a['id'] == $active_id) {
                        $already_valid = true;
                        setActiveContextTest($a['id']);
                        break;
                    }
                }
            }

            if (!$already_valid && $active_role && $active_study) {
                $target_norm = strtolower(trim($active_role));
                foreach ($assignments as $a) {
                    if ($a['study_id'] == $active_study && strtolower(trim($a['role_name'] ?? '')) === $target_norm) {
                        $already_valid = true;
                        setActiveContextTest($a['id']);
                        break;
                    }
                }
            }

            if (!$already_valid) {
                $default_id = $assignments[0]['id'];
                setActiveContextTest($default_id);
            }
        }
    }

    initializeUserRolesTest($updated_db_assignments);
    $assertCondition("Visiting Dashboard does NOT reset active role to Data Coordinator", $_SESSION['active_role_name'] === 'Data Monitor');

    // 5. Simulate Switch to Data Manager (id: 52)
    $_POST = [
        'switch_role_study' => '1',
        'switch_study_id' => '1',
        'new_role_id' => '52',
        'redirect_to' => 'subject_data_entry.php?subject_id=1'
    ];
    $target_id = $_POST['new_role_id'];
    $valid_assignment_id = null;
    foreach ($_SESSION['assignments'] as $assign) {
        if ($assign['id'] == $target_id) {
            $valid_assignment_id = $assign['id'];
            break;
        }
    }
    setActiveContextTest($valid_assignment_id);
    $assertCondition("Switched to Data Manager (ID 52)", $_SESSION['active_role_name'] === 'Data Manager');

    // 6. Test HTML Rendering of Role Switcher
    function renderRoleSwitcherHTML($study_id) {
        $assignments = $_SESSION['assignments'] ?? [];
        $my_roles = [];
        $seen = [];
        foreach ($assignments as $a) {
            if ($a['study_id'] == $study_id) {
                $norm = strtolower(trim($a['role_name'] ?? ''));
                if ($norm !== '' && !isset($seen[$norm])) {
                    $seen[$norm] = true;
                    $my_roles[] = $a;
                }
            }
        }

        $active_norm = strtolower(trim($_SESSION['active_role_name'] ?? ''));
        $active_id = $_SESSION['active_assignment_id'] ?? null;

        $selected_assignment_id = null;
        if ($active_id) {
            foreach ($my_roles as $role) {
                if ($role['id'] == $active_id) {
                    $selected_assignment_id = $role['id'];
                    break;
                }
            }
        }
        if (!$selected_assignment_id && $active_norm !== '') {
            foreach ($my_roles as $role) {
                if (strtolower(trim($role['role_name'] ?? '')) === $active_norm) {
                    $selected_assignment_id = $role['id'];
                    break;
                }
            }
        }

        ob_start();
        echo '<select name="new_role_id">';
        foreach ($my_roles as $role) {
            $sel = ($role['id'] == $selected_assignment_id) ? 'selected' : '';
            echo '<option value="'.$role['id'].'" '.$sel.'>'.htmlspecialchars($role['role_name']).'</option>';
        }
        echo '</select>';
        return ob_get_clean();
    }

    $html = renderRoleSwitcherHTML(1);
    $assertCondition("HTML contains Data Manager selected", strpos($html, '<option value="52" selected>Data Manager</option>') !== false);
    $assertCondition("HTML does NOT select Data Coordinator", strpos($html, '<option value="50" selected>Data Coordinator</option>') === false);

    echo "\nResults: {$tests_passed}/{$tests_total} tests passed.\n";
}

runRoleSwitcherTests();
