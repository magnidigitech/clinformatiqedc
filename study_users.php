<?php
require_once 'includes/functions.php';
require_once 'includes/auth.php';

requireLogin();

if (!isset($_SESSION['active_study_id'])) {
    redirect('dashboard.php');
}

// Only Admin can manage users
if (!hasPermission('all')) {
    die("Unauthorized access: User Management is restricted to Administrators.");
}

$study_id = $_SESSION['active_study_id'];
$study_name = $_SESSION['active_study_name'];
$current_user_id = $_SESSION['user_id'];

$error = $_SESSION['flash_error'] ?? '';
$success = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_error'], $_SESSION['flash_success']);

// Handle Sample CSV Download
if (isset($_GET['download_sample']) && $_GET['download_sample'] == '1') {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="users_bulk_upload_template.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    $output = fopen('php://output', 'w');
    fputs($output, "\xEF\xBB\xBF"); // UTF-8 BOM

    fputcsv($output, [
        'Name', 
        'Email', 
        'Username (Register Number)', 
        'Temporary Password', 
        'Admin', 
        'Data Coordinator', 
        'Data Entry', 
        'Data Monitor', 
        'College Name', 
        'Batch Name'
    ], ',', '"', '\\');
    fputcsv($output, ['John Doe', 'student1@college.edu', 'REG2026001', 'Welcome2026!', 'NO', 'NO', 'YES', 'NO', 'City Medical College', 'Batch 2026-A'], ',', '"', '\\');
    fputcsv($output, ['Jane Smith', 'student2@college.edu', 'REG2026002', 'Welcome2026!', 'NO', 'YES', 'NO', 'NO', 'City Medical College', 'Batch 2026-A'], ',', '"', '\\');
    fputcsv($output, ['Alex Johnson', 'student3@college.edu', 'REG2026003', 'Welcome2026!', 'YES', 'NO', 'NO', 'YES', 'City Medical College', 'Batch 2026-B'], ',', '"', '\\');
    fclose($output);
    exit();
}

$pdo = getDB();

// Helper to evaluate truthy role values case-insensitively
function isTruthyRole($val) {
    if ($val === null || $val === '') return false;
    $v = strtolower(trim((string)$val));
    return in_array($v, ['yes', 'y', '1', 'true', 'x', 'checked', 'yes!'], true);
}

function getPermissionsForRole($role_name) {
    if ($role_name === 'Admin') return 'all';
    if ($role_name === 'Data Coordinator') return '{"view": true, "add": true, "add_subject": true, "enter_data": true, "edit": true}';
    if ($role_name === 'Data Entry') return '{"view": true, "add_subject": true, "enter_data": true, "edit": true}';
    if ($role_name === 'Data Monitor') return '{"view": true, "query": true, "raise_query": true, "verify": true}';
    if ($role_name === 'Data Manager') return '{"view": true, "query": true, "raise_query": true, "verify": true}';
    return '{"view": true}';
}

function countActiveAdminsInStudy($pdo, $study_id, $exclude_user_ids = []) {
    $where = "su.study_id = :sid AND LOWER(su.role_name) = 'admin' AND COALESCE(u.status, 'active') = 'active'";
    $params = ['sid' => $study_id];
    if (!empty($exclude_user_ids)) {
        $clean_ids = array_map('intval', $exclude_user_ids);
        $in = implode(',', $clean_ids);
        if ($in !== '') {
            $where .= " AND u.id NOT IN ($in)";
        }
    }
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT u.id) FROM study_users su JOIN users u ON su.user_id = u.id WHERE {$where}");
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

// Native PHP XLSX Parser
function parseXlsxRows($filePath) {
    $rows = [];
    if (!class_exists('ZipArchive')) return false;

    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) return false;

    $sharedStrings = [];
    $sharedStringsXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedStringsXml !== false) {
        $xml = @simplexml_load_string($sharedStringsXml);
        if ($xml) {
            foreach ($xml->si as $val) {
                if (isset($val->t)) {
                    $sharedStrings[] = (string)$val->t;
                } elseif (isset($val->r)) {
                    $text = '';
                    foreach ($val->r as $r) {
                        $text .= (string)$r->t;
                    }
                    $sharedStrings[] = $text;
                } else {
                    $sharedStrings[] = '';
                }
            }
        }
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheetXml === false) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (strpos($name, 'xl/worksheets/sheet') === 0) {
                $sheetXml = $zip->getFromName($name);
                break;
            }
        }
    }
    $zip->close();

    if ($sheetXml === false) return false;

    $xml = @simplexml_load_string($sheetXml);
    if (!$xml || !isset($xml->sheetData)) return false;

    foreach ($xml->sheetData->row as $r) {
        $rowCells = [];
        foreach ($r->c as $c) {
            $cellRef = (string)$c['r'];
            preg_match('/^([A-Z]+)/i', $cellRef, $matches);
            $colStr = strtoupper($matches[1] ?? 'A');
            
            $colIdx = 0;
            $len = strlen($colStr);
            for ($k = 0; $k < $len; $k++) {
                $colIdx = $colIdx * 26 + (ord($colStr[$k]) - ord('A') + 1);
            }
            $colIdx -= 1;

            $type = (string)$c['t'];
            $val = '';

            if (isset($c->v)) {
                $rawVal = (string)$c->v;
                if ($type === 's' && isset($sharedStrings[(int)$rawVal])) {
                    $val = $sharedStrings[(int)$rawVal];
                } else {
                    $val = $rawVal;
                }
            } elseif (isset($c->is->t)) {
                $val = (string)$c->is->t;
            }

            for ($i = count($rowCells); $i < $colIdx; $i++) {
                $rowCells[$i] = '';
            }
            $rowCells[$colIdx] = $val;
        }

        if (!empty($rowCells)) {
            $rows[] = $rowCells;
        }
    }

    return $rows;
}

function parseUploadedSpreadsheet($filePath, $fileName) {
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    if ($ext === 'xlsx') {
        $xlsxRows = parseXlsxRows($filePath);
        if ($xlsxRows !== false) {
            return $xlsxRows;
        }
    }

    $handle = fopen($filePath, 'r');
    if (!$handle) return false;

    $bom = fread($handle, 3);
    if ($bom !== "\xEF\xBB\xBF") {
        rewind($handle);
    }

    $firstLine = fgets($handle);
    rewind($handle);
    if ($bom === "\xEF\xBB\xBF") {
        fread($handle, 3);
    }

    $delimiter = ',';
    if ($firstLine !== false) {
        if (substr_count($firstLine, "\t") > substr_count($firstLine, ",")) {
            $delimiter = "\t";
        } elseif (substr_count($firstLine, ";") > substr_count($firstLine, ",")) {
            $delimiter = ";";
        }
    }

    $rows = [];
    while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
        $rows[] = $row;
    }
    fclose($handle);
    return $rows;
}

// Ensure schema columns exist
try {
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS college_name VARCHAR(255) NULL");
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS batch_name VARCHAR(100) NULL");
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS status VARCHAR(20) DEFAULT 'active'");
} catch (Exception $e) {
    // Ignore if existing
}

// Handle All Backend POST Actions
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. ADD USER
    if ($action === 'add_user') {
        $email = trim($_POST['email'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $username_input = trim($_POST['username'] ?? '');
        $college_name = trim($_POST['college_name'] ?? '');
        $batch_name = trim($_POST['batch_name'] ?? '');
        $status = in_array($_POST['status'] ?? 'active', ['active', 'inactive']) ? $_POST['status'] : 'active';
        $selected_roles = $_POST['roles'] ?? [];
        $selected_sites = $_POST['sites'] ?? [];
        $temp_pass = $_POST['temp_password'] ?? 'Welcome2026!';

        if (empty($email)) {
            $error = "Email address is required.";
        } elseif (empty($name)) {
            $error = "Full Name is required.";
        } elseif (empty($selected_roles)) {
            $error = "Please select at least one role for the user.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Invalid email address format.";
        } else {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                $stmt->execute([$email]);
                $existing_user_id = $stmt->fetchColumn();

                $target_user_id = $existing_user_id;
                $is_new_user = false;

                if (!$existing_user_id) {
                    $is_new_user = true;
                    $username = !empty($username_input) ? $username_input : strstr($email, '@', true);
                    
                    // Verify username uniqueness
                    $u_check = $pdo->prepare("SELECT id FROM users WHERE username = ?");
                    $u_check->execute([$username]);
                    if ($u_check->fetchColumn()) {
                        $username .= '_' . rand(100, 999);
                    }

                    $hash = password_hash($temp_pass, PASSWORD_DEFAULT);

                    $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash, name, college_name, batch_name, status) VALUES (:user, :email, :pass, :name, :college, :batch, :status)");
                    $stmt->execute([
                        'user' => $username,
                        'email' => $email,
                        'pass' => $hash,
                        'name' => $name,
                        'college' => $college_name ?: null,
                        'batch' => $batch_name ?: null,
                        'status' => $status
                    ]);
                    $target_user_id = $pdo->lastInsertId();
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET name = :name, college_name = COALESCE(NULLIF(:college, ''), college_name), batch_name = COALESCE(NULLIF(:batch, ''), batch_name), status = :status WHERE id = :id");
                    $stmt->execute([
                        'name' => $name,
                        'college' => $college_name,
                        'batch' => $batch_name,
                        'status' => $status,
                        'id' => $existing_user_id
                    ]);
                }

                // Clear & re-assign study roles
                $stmt = $pdo->prepare("DELETE FROM study_users WHERE user_id = :uid AND study_id = :sid");
                $stmt->execute(['uid' => $target_user_id, 'sid' => $study_id]);

                $stmt = $pdo->prepare("INSERT INTO study_users (user_id, study_id, role_name, permissions) VALUES (:uid, :sid, :role, :perms)");
                foreach ($selected_roles as $role_name) {
                    $perms = getPermissionsForRole($role_name);
                    $stmt->execute([
                        'uid' => $target_user_id,
                        'sid' => $study_id,
                        'role' => $role_name,
                        'perms' => $perms
                    ]);
                }

                // Assign Sites
                $stmt = $pdo->prepare("DELETE FROM study_user_sites WHERE user_id = :uid AND study_id = :sid");
                $stmt->execute(['uid' => $target_user_id, 'sid' => $study_id]);

                if (!empty($selected_sites)) {
                    $stmt = $pdo->prepare("INSERT INTO study_user_sites (user_id, study_id, site_id) VALUES (:uid, :sid, :site_id)");
                    foreach ($selected_sites as $site_id) {
                        $stmt->execute([
                            'uid' => $target_user_id,
                            'sid' => $study_id,
                            'site_id' => $site_id
                        ]);
                    }
                }

                $pdo->commit();

                if ($is_new_user) {
                    $success = "User account <strong>" . htmlspecialchars($name) . "</strong> (" . htmlspecialchars($email) . ") created successfully.";
                } else {
                    $success = "User <strong>" . htmlspecialchars($name) . "</strong> study assignment updated.";
                }

            } catch (Exception $e) {
                $pdo->rollBack();
                $error = "Database Error: " . $e->getMessage();
            }
        }
    }

    // 2. EDIT USER
    elseif ($action === 'edit_user') {
        $user_id = (int)($_POST['user_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $college_name = trim($_POST['college_name'] ?? '');
        $batch_name = trim($_POST['batch_name'] ?? '');
        $status = in_array($_POST['status'] ?? 'active', ['active', 'inactive']) ? $_POST['status'] : 'active';
        $selected_roles = $_POST['roles'] ?? [];
        $selected_sites = $_POST['sites'] ?? [];

        if ($user_id <= 0) {
            $error = "Invalid user specified.";
        } elseif (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Valid email is required.";
        } elseif (empty($name)) {
            $error = "Full Name is required.";
        } elseif (empty($selected_roles)) {
            $error = "At least one role must be assigned.";
        } else {
            // Protection checks
            if ($user_id === $current_user_id && $status === 'inactive') {
                $error = "You cannot deactivate your own account.";
            } elseif ($user_id === $current_user_id && !in_array('Admin', $selected_roles)) {
                $error = "You cannot remove the Admin role from your own account.";
            } elseif ($status === 'inactive' || !in_array('Admin', $selected_roles)) {
                $remaining_admins = countActiveAdminsInStudy($pdo, $study_id, [$user_id]);
                if ($remaining_admins === 0) {
                    $error = "Action blocked: A study must have at least one active Administrator.";
                }
            }

            if (empty($error)) {
                try {
                    $pdo->beginTransaction();

                    // Update user profile
                    $stmt = $pdo->prepare("UPDATE users SET name = :name, email = :email, username = :user, college_name = :college, batch_name = :batch, status = :status WHERE id = :id");
                    $stmt->execute([
                        'name' => $name,
                        'email' => $email,
                        'user' => $username ?: strstr($email, '@', true),
                        'college' => $college_name ?: null,
                        'batch' => $batch_name ?: null,
                        'status' => $status,
                        'id' => $user_id
                    ]);

                    // Sync study roles
                    $stmt = $pdo->prepare("DELETE FROM study_users WHERE user_id = :uid AND study_id = :sid");
                    $stmt->execute(['uid' => $user_id, 'sid' => $study_id]);

                    $stmt = $pdo->prepare("INSERT INTO study_users (user_id, study_id, role_name, permissions) VALUES (:uid, :sid, :role, :perms)");
                    foreach ($selected_roles as $role_name) {
                        $perms = getPermissionsForRole($role_name);
                        $stmt->execute([
                            'uid' => $user_id,
                            'sid' => $study_id,
                            'role' => $role_name,
                            'perms' => $perms
                        ]);
                    }

                    // Sync Sites
                    $stmt = $pdo->prepare("DELETE FROM study_user_sites WHERE user_id = :uid AND study_id = :sid");
                    $stmt->execute(['uid' => $user_id, 'sid' => $study_id]);

                    if (!empty($selected_sites)) {
                        $stmt = $pdo->prepare("INSERT INTO study_user_sites (user_id, study_id, site_id) VALUES (:uid, :sid, :site_id)");
                        foreach ($selected_sites as $site_id) {
                            $stmt->execute([
                                'uid' => $user_id,
                                'sid' => $study_id,
                                'site_id' => $site_id
                            ]);
                        }
                    }

                    $pdo->commit();
                    $success = "User profile for <strong>" . htmlspecialchars($name) . "</strong> updated successfully.";
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $error = "Database Error: " . $e->getMessage();
                }
            }
        }
    }

    // 3. RESET PASSWORD
    elseif ($action === 'reset_password') {
        $user_id = (int)($_POST['user_id'] ?? 0);
        $new_pass = $_POST['new_password'] ?? '';
        $confirm_pass = $_POST['confirm_password'] ?? '';

        if ($user_id <= 0) {
            $error = "Invalid user specified.";
        } elseif (strlen($new_pass) < 6) {
            $error = "New password must be at least 6 characters long.";
        } elseif ($new_pass !== $confirm_pass) {
            $error = "New password and confirmation do not match.";
        } else {
            try {
                $hash = password_hash($new_pass, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                $stmt->execute([$hash, $user_id]);
                $success = "Password reset successfully for the user.";
            } catch (Exception $e) {
                $error = "Failed to reset password: " . $e->getMessage();
            }
        }
    }

    // 4. TOGGLE STATUS (ACTIVATE / DEACTIVATE)
    elseif ($action === 'toggle_status') {
        $user_id = (int)($_POST['user_id'] ?? 0);
        $new_status = $_POST['new_status'] === 'active' ? 'active' : 'inactive';

        if ($user_id <= 0) {
            $error = "Invalid user specified.";
        } elseif ($user_id === $current_user_id && $new_status === 'inactive') {
            $error = "You cannot deactivate your own account.";
        } else {
            if ($new_status === 'inactive') {
                $remaining_admins = countActiveAdminsInStudy($pdo, $study_id, [$user_id]);
                if ($remaining_admins === 0) {
                    $error = "Action blocked: A study must have at least one active Administrator.";
                }
            }

            if (empty($error)) {
                try {
                    $stmt = $pdo->prepare("UPDATE users SET status = ? WHERE id = ?");
                    $stmt->execute([$new_status, $user_id]);
                    $label = ucfirst($new_status);
                    $success = "User status set to <strong>{$label}</strong>.";
                } catch (Exception $e) {
                    $error = "Failed to update status: " . $e->getMessage();
                }
            }
        }
    }

    // 5. REMOVE FROM STUDY
    elseif ($action === 'remove_user') {
        $user_id = (int)($_POST['user_id'] ?? 0);

        if ($user_id <= 0) {
            $error = "Invalid user specified.";
        } elseif ($user_id === $current_user_id) {
            $error = "You cannot remove yourself from the study.";
        } else {
            $remaining_admins = countActiveAdminsInStudy($pdo, $study_id, [$user_id]);
            if ($remaining_admins === 0) {
                $error = "Action blocked: A study must retain at least one active Administrator.";
            } else {
                try {
                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare("DELETE FROM study_users WHERE user_id = :uid AND study_id = :sid");
                    $stmt->execute(['uid' => $user_id, 'sid' => $study_id]);

                    $stmt = $pdo->prepare("DELETE FROM study_user_sites WHERE user_id = :uid AND study_id = :sid");
                    $stmt->execute(['uid' => $user_id, 'sid' => $study_id]);
                    $pdo->commit();

                    $success = "User removed from study team.";
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $error = "Failed to remove user: " . $e->getMessage();
                }
            }
        }
    }

    // 6. BULK ACTIONS
    elseif ($action === 'bulk_action') {
        $bulk_type = $_POST['bulk_type'] ?? '';
        $raw_user_ids = $_POST['user_ids'] ?? [];
        $user_ids = array_filter(array_map('intval', (array)$raw_user_ids));

        if (empty($user_ids)) {
            $error = "No users selected for bulk action.";
        } else {
            $processed = 0;
            $skipped = 0;
            $reasons = [];

            if ($bulk_type === 'activate') {
                $stmt = $pdo->prepare("UPDATE users SET status = 'active' WHERE id = ?");
                foreach ($user_ids as $uid) {
                    $stmt->execute([$uid]);
                    $processed++;
                }
                $success = "Bulk Action Complete: <strong>{$processed}</strong> users activated.";
            } 
            elseif ($bulk_type === 'deactivate') {
                foreach ($user_ids as $uid) {
                    if ($uid === $current_user_id) {
                        $skipped++;
                        $reasons[] = "Skipped your own account.";
                        continue;
                    }
                    $remaining_admins = countActiveAdminsInStudy($pdo, $study_id, [$uid]);
                    if ($remaining_admins === 0) {
                        $skipped++;
                        $reasons[] = "Skipped last active study administrator.";
                        continue;
                    }
                    $stmt = $pdo->prepare("UPDATE users SET status = 'inactive' WHERE id = ?");
                    $stmt->execute([$uid]);
                    $processed++;
                }
                $msg = "Bulk Action Complete: <strong>{$processed}</strong> users deactivated.";
                if ($skipped > 0) $msg .= " ({$skipped} skipped).";
                $success = $msg;
            }
            elseif ($bulk_type === 'remove') {
                foreach ($user_ids as $uid) {
                    if ($uid === $current_user_id) {
                        $skipped++;
                        $reasons[] = "Skipped your own account.";
                        continue;
                    }
                    $remaining_admins = countActiveAdminsInStudy($pdo, $study_id, [$uid]);
                    if ($remaining_admins === 0) {
                        $skipped++;
                        $reasons[] = "Skipped last active study administrator.";
                        continue;
                    }
                    $stmt = $pdo->prepare("DELETE FROM study_users WHERE user_id = :uid AND study_id = :sid");
                    $stmt->execute(['uid' => $uid, 'sid' => $study_id]);
                    $processed++;
                }
                $msg = "Bulk Action Complete: <strong>{$processed}</strong> users removed from study.";
                if ($skipped > 0) $msg .= " ({$skipped} skipped).";
                $success = $msg;
            }
            elseif ($bulk_type === 'assign_roles') {
                $selected_roles = $_POST['roles'] ?? [];
                $mode = $_POST['bulk_role_mode'] ?? 'add'; // 'add' or 'replace'

                if (empty($selected_roles)) {
                    $error = "Please select at least one role for bulk assignment.";
                } else {
                    foreach ($user_ids as $uid) {
                        // If replacing roles and user is admin/self, ensure Admin role isn't lost if self or last admin
                        if ($mode === 'replace') {
                            if ($uid === $current_user_id && !in_array('Admin', $selected_roles)) {
                                $skipped++;
                                $reasons[] = "Skipped removing Admin role from your own account.";
                                continue;
                            }
                            if (!in_array('Admin', $selected_roles)) {
                                $remaining_admins = countActiveAdminsInStudy($pdo, $study_id, [$uid]);
                                if ($remaining_admins === 0) {
                                    $skipped++;
                                    $reasons[] = "Skipped stripping Admin role from last active administrator.";
                                    continue;
                                }
                            }
                            // Delete existing
                            $stmt_del = $pdo->prepare("DELETE FROM study_users WHERE user_id = :uid AND study_id = :sid");
                            $stmt_del->execute(['uid' => $uid, 'sid' => $study_id]);
                        }

                        $stmt_ins = $pdo->prepare("INSERT INTO study_users (user_id, study_id, role_name, permissions) VALUES (:uid, :sid, :role, :perms) ON CONFLICT DO NOTHING");
                        foreach ($selected_roles as $role_name) {
                            $perms = getPermissionsForRole($role_name);
                            $stmt_ins->execute([
                                'uid' => $uid,
                                'sid' => $study_id,
                                'role' => $role_name,
                                'perms' => $perms
                            ]);
                        }
                        $processed++;
                    }
                    $msg = "Bulk Role Assignment Complete: <strong>{$processed}</strong> users updated.";
                    if ($skipped > 0) $msg .= " ({$skipped} skipped).";
                    $success = $msg;
                }
            }
        }
    }

    // 7. BULK IMPORT FILE (CSV, XLSX, TSV, TXT)
    elseif ($action === 'bulk_import') {
        @set_time_limit(300);
        @ini_set('memory_limit', '256M');

        if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
            $error = "Please select a valid CSV, XLSX, or TSV file to upload.";
        } else {
            $file_tmp = $_FILES['import_file']['tmp_name'];
            $file_name = $_FILES['import_file']['name'];

            $all_rows = parseUploadedSpreadsheet($file_tmp, $file_name);

            if ($all_rows === false || empty($all_rows)) {
                $error = "Unable to parse the uploaded file or file is empty.";
            } else {
                $header = array_shift($all_rows);
                if (!$header) {
                    $error = "Uploaded file does not contain a header row.";
                } else {
                    $map = [];
                    foreach ($header as $idx => $col) {
                        $col_clean = strtolower(trim($col));
                        $col_clean = preg_replace('/[^a-z0-9_ ]/', '', $col_clean);
                        
                        if (strpos($col_clean, 'email') !== false) {
                            $map['email'] = $idx;
                        } elseif (strpos($col_clean, 'name') !== false && strpos($col_clean, 'college') === false && strpos($col_clean, 'batch') === false) {
                            $map['name'] = $idx;
                        } elseif (strpos($col_clean, 'username') !== false || strpos($col_clean, 'register') !== false || strpos($col_clean, 'reg') !== false) {
                            $map['username'] = $idx;
                        } elseif (strpos($col_clean, 'password') !== false || strpos($col_clean, 'pass') !== false) {
                            $map['password'] = $idx;
                        } elseif ($col_clean === 'admin' || strpos($col_clean, 'administrator') !== false) {
                            $map['role_admin'] = $idx;
                        } elseif (strpos($col_clean, 'coordinator') !== false) {
                            $map['role_coordinator'] = $idx;
                        } elseif (strpos($col_clean, 'entry') !== false) {
                            $map['role_entry'] = $idx;
                        } elseif (strpos($col_clean, 'monitor') !== false || strpos($col_clean, 'manager') !== false) {
                            $map['role_monitor'] = $idx;
                        } elseif (strpos($col_clean, 'college') !== false || strpos($col_clean, 'institution') !== false) {
                            $map['college'] = $idx;
                        } elseif (strpos($col_clean, 'batch') !== false) {
                            $map['batch'] = $idx;
                        }
                    }

                    if (!isset($map['email']) || !isset($map['name'])) {
                        $error = "File header must contain at least 'Name' and 'Email' columns.";
                    } else {
                        try {
                            $pdo->beginTransaction();

                            $stmt_find = $pdo->prepare("SELECT id FROM users WHERE email = :email OR username = :username");
                            $stmt_insert_user = $pdo->prepare("INSERT INTO users (username, email, password_hash, name, college_name, batch_name, status) VALUES (:username, :email, :pass, :name, :college, :batch, 'active')");
                            $stmt_update_user = $pdo->prepare("UPDATE users SET name = :name, college_name = COALESCE(NULLIF(:college, ''), college_name), batch_name = COALESCE(NULLIF(:batch, ''), batch_name) WHERE id = :id");
                            $stmt_del_roles = $pdo->prepare("DELETE FROM study_users WHERE user_id = :uid AND study_id = :sid");
                            $stmt_ins_role = $pdo->prepare("INSERT INTO study_users (user_id, study_id, role_name, permissions) VALUES (:uid, :sid, :role, :perms)");

                            $row_num = 1;
                            $inserted = 0;
                            $updated = 0;
                            $skipped = 0;

                            foreach ($all_rows as $data) {
                                $row_num++;
                                if (empty($data) || (count($data) === 1 && trim($data[0]) === '')) {
                                    continue;
                                }

                                $email = trim($data[$map['email']] ?? '');
                                $name = trim($data[$map['name']] ?? '');
                                $username_input = isset($map['username']) ? trim($data[$map['username']] ?? '') : '';
                                $temp_pass = isset($map['password']) && !empty(trim($data[$map['password']] ?? '')) ? trim($data[$map['password']]) : 'Welcome2026!';
                                $college_name = isset($map['college']) ? trim($data[$map['college']] ?? '') : null;
                                $batch_name = isset($map['batch']) ? trim($data[$map['batch']] ?? '') : null;

                                if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL) || empty($name)) {
                                    $skipped++;
                                    continue;
                                }

                                $username = !empty($username_input) ? $username_input : strstr($email, '@', true);

                                $assigned_roles = [];
                                if (isset($map['role_admin']) && isTruthyRole($data[$map['role_admin']] ?? '')) {
                                    $assigned_roles[] = 'Admin';
                                }
                                if (isset($map['role_coordinator']) && isTruthyRole($data[$map['role_coordinator']] ?? '')) {
                                    $assigned_roles[] = 'Data Coordinator';
                                }
                                if (isset($map['role_entry']) && isTruthyRole($data[$map['role_entry']] ?? '')) {
                                    $assigned_roles[] = 'Data Entry';
                                }
                                if (isset($map['role_monitor']) && isTruthyRole($data[$map['role_monitor']] ?? '')) {
                                    $assigned_roles[] = 'Data Monitor';
                                }

                                if (empty($assigned_roles)) {
                                    $assigned_roles[] = 'Data Entry';
                                }

                                $stmt_find->execute(['email' => $email, 'username' => $username]);
                                $existing_user_id = $stmt_find->fetchColumn();

                                if (!$existing_user_id) {
                                    $hash = password_hash($temp_pass, PASSWORD_DEFAULT);
                                    $stmt_insert_user->execute([
                                        'username' => $username,
                                        'email' => $email,
                                        'pass' => $hash,
                                        'name' => $name,
                                        'college' => $college_name ?: null,
                                        'batch' => $batch_name ?: null
                                    ]);
                                    $target_user_id = $pdo->lastInsertId();
                                    $inserted++;
                                } else {
                                    $stmt_update_user->execute([
                                        'name' => $name,
                                        'college' => $college_name ?: null,
                                        'batch' => $batch_name ?: null,
                                        'id' => $existing_user_id
                                    ]);
                                    $target_user_id = $existing_user_id;
                                    $updated++;
                                }

                                $stmt_del_roles->execute(['uid' => $target_user_id, 'sid' => $study_id]);
                                foreach ($assigned_roles as $role_name) {
                                    $perms = getPermissionsForRole($role_name);
                                    $stmt_ins_role->execute([
                                        'uid' => $target_user_id,
                                        'sid' => $study_id,
                                        'role' => $role_name,
                                        'perms' => $perms
                                    ]);
                                }
                            }

                            $pdo->commit();

                            $msg = "Import complete: <strong>{$inserted}</strong> users created, <strong>{$updated}</strong> updated.";
                            if ($skipped > 0) {
                                $msg .= " ({$skipped} invalid rows skipped).";
                            }
                            $success = $msg;

                        } catch (Exception $e) {
                            $pdo->rollBack();
                            $error = "Bulk import failed: " . $e->getMessage();
                        }
                    }
                }
            }
        }
    if (!empty($success)) {
        $_SESSION['flash_success'] = $success;
    }
    if (!empty($error)) {
        $_SESSION['flash_error'] = $error;
    }
    redirect('study_users.php');
}
}

// --------------------------------------------------------------------------
// BACKEND SEARCH, FILTER, SORT & PAGINATION LOGIC
// --------------------------------------------------------------------------

$search_query = trim($_GET['q'] ?? '');
$filter_role = trim($_GET['role'] ?? '');
$filter_college = trim($_GET['college'] ?? '');
$filter_batch = trim($_GET['batch'] ?? '');
$filter_status = trim($_GET['status'] ?? '');
$sort_by = trim($_GET['sort'] ?? 'joined_desc');
$page = max(1, (int)($_GET['page'] ?? 1));
$raw_limit = (int)($_GET['limit'] ?? 10);
$limit = in_array($raw_limit, [10, 25, 50, 100], true) ? $raw_limit : 10;
$safe_limit = max(1, $limit);
$offset = ($page - 1) * $safe_limit;

$where_clauses = ["su.study_id = :sid"];
$params = ['sid' => $study_id];

if ($search_query !== '') {
    $where_clauses[] = "(LOWER(u.name) LIKE :q OR LOWER(u.email) LIKE :q OR LOWER(u.username) LIKE :q OR LOWER(u.college_name) LIKE :q OR LOWER(u.batch_name) LIKE :q)";
    $params['q'] = '%' . strtolower($search_query) . '%';
}

if ($filter_college !== '') {
    $where_clauses[] = "u.college_name = :college";
    $params['college'] = $filter_college;
}

if ($filter_batch !== '') {
    $where_clauses[] = "u.batch_name = :batch";
    $params['batch'] = $filter_batch;
}

if ($filter_status !== '') {
    $where_clauses[] = "COALESCE(u.status, 'active') = :status";
    $params['status'] = $filter_status;
}

if ($filter_role !== '') {
    $where_clauses[] = "su.role_name = :role";
    $params['role'] = $filter_role;
}

$where_sql = implode(' AND ', $where_clauses);

// Count Total Matching Users
$count_stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT u.id) 
    FROM study_users su 
    JOIN users u ON su.user_id = u.id 
    WHERE {$where_sql}
");
$count_stmt->execute($params);
$total_matching_users = (int)$count_stmt->fetchColumn();
$total_pages = max(1, ceil($total_matching_users / $safe_limit));

// Sort Mapping
$order_by_sql = "MAX(su.created_at) DESC";
if ($sort_by === 'joined_asc') $order_by_sql = "MAX(su.created_at) ASC";
elseif ($sort_by === 'name_asc') $order_by_sql = "COALESCE(NULLIF(u.name, ''), u.username) ASC";
elseif ($sort_by === 'name_desc') $order_by_sql = "COALESCE(NULLIF(u.name, ''), u.username) DESC";

// Fetch Paginated User IDs
$ids_stmt = $pdo->prepare("
    SELECT u.id
    FROM study_users su 
    JOIN users u ON su.user_id = u.id 
    WHERE {$where_sql}
    GROUP BY u.id, u.name, u.username
    ORDER BY {$order_by_sql}
    LIMIT :limit OFFSET :offset
");

foreach ($params as $k => $v) {
    $ids_stmt->bindValue($k, $v);
}
$ids_stmt->bindValue('limit', $limit, PDO::PARAM_INT);
$ids_stmt->bindValue('offset', $offset, PDO::PARAM_INT);
$ids_stmt->execute();
$paginated_user_ids = $ids_stmt->fetchAll(PDO::FETCH_COLUMN);

// Fetch User Rows & Roles for Paginated User IDs
$study_users = [];
if (!empty($paginated_user_ids)) {
    $clean_ids = array_map('intval', $paginated_user_ids);
    $in_clause = implode(',', $clean_ids);
    $users_stmt = $pdo->query("
        SELECT u.id as user_id, u.username, u.email, u.name, u.college_name, u.batch_name, COALESCE(u.status, 'active') as status, su.role_name, su.created_at as joined_at 
        FROM study_users su 
        JOIN users u ON su.user_id = u.id 
        WHERE su.study_id = {$study_id} AND u.id IN ({$in_clause})
        ORDER BY su.created_at DESC
    ");
    $raw_rows = $users_stmt->fetchAll();

    foreach ($raw_rows as $row) {
        $uid = $row['user_id'];
        if (!isset($study_users[$uid])) {
            // FIX DATA MAPPING FOR NAME:
            // Display Name = u.name if populated, else fallback to username.
            $has_real_name = !empty(trim($row['name'] ?? ''));
            $display_name = $has_real_name ? trim($row['name']) : $row['username'];

            $study_users[$uid] = [
                'id' => $row['user_id'],
                'username' => $row['username'],
                'email' => $row['email'],
                'name' => $row['name'],
                'display_name' => $display_name,
                'college_name' => $row['college_name'],
                'batch_name' => $row['batch_name'],
                'status' => $row['status'],
                'joined_at' => $row['joined_at'],
                'roles' => []
            ];
        }
        $study_users[$uid]['roles'][] = $row['role_name'];
    }
}

// Unique Colleges & Batches for Filter Dropdowns
$colleges_stmt = $pdo->query("SELECT DISTINCT college_name FROM users WHERE college_name IS NOT NULL AND college_name != '' ORDER BY college_name");
$all_colleges = $colleges_stmt->fetchAll(PDO::FETCH_COLUMN);

$batches_stmt = $pdo->query("SELECT DISTINCT batch_name FROM users WHERE batch_name IS NOT NULL AND batch_name != '' ORDER BY batch_name");
$all_batches = $batches_stmt->fetchAll(PDO::FETCH_COLUMN);

// Fetch Sites for Assignment
$sites_stmt = $pdo->prepare("SELECT id, name FROM sites WHERE study_id = ? ORDER BY name");
$sites_stmt->execute([$study_id]);
$available_sites = $sites_stmt->fetchAll();

$has_filters = (!empty($search_query) || !empty($filter_role) || !empty($filter_college) || !empty($filter_batch) || !empty($filter_status));
$start_item = $total_matching_users > 0 ? $offset + 1 : 0;
$end_item = min($offset + $limit, $total_matching_users);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Study Team Members - <?php echo htmlspecialchars($study_name); ?></title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
    <style>
        .page-header-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.25rem;
            flex-wrap: wrap;
            gap: 1rem;
        }
        .count-badge {
            background: #e2e8f0;
            color: #475569;
            font-size: 0.8rem;
            font-weight: 700;
            padding: 2px 10px;
            border-radius: 12px;
        }
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 0.72rem;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 12px;
            text-transform: capitalize;
        }
        .status-badge.active {
            background: #dcfce7;
            color: #15803d;
            border: 1px solid #bbf7d0;
        }
        .status-badge.inactive {
            background: #f1f5f9;
            color: #64748b;
            border: 1px solid #e2e8f0;
        }
        .dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
        }
        .status-badge.active .dot { background: #16a34a; }
        .status-badge.inactive .dot { background: #94a3b8; }
        
        .role-pill {
            background: #e0f2fe;
            color: #0369a1;
            font-size: 0.72rem;
            font-weight: 500;
            padding: 2px 8px;
            border-radius: 4px;
            display: inline-block;
            margin-right: 2px;
        }
        .filter-control {
            height: 38px !important;
            padding: 0 10px !important;
            font-size: 0.825rem !important;
            border: 1px solid #cbd5e1 !important;
            border-radius: 6px !important;
            background-color: #ffffff !important;
            color: #334155 !important;
            outline: none !important;
            box-sizing: border-box !important;
            line-height: normal !important;
        }
        input.filter-control {
            padding-left: 34px !important;
        }
        select.filter-control {
            padding-right: 22px !important;
            cursor: pointer !important;
        }

        /* Full Width Table Styling */
        .users-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
            background: #ffffff;
        }
        .users-table th {
            background: #f8fafc;
            color: #64748b;
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            padding: 0.75rem 1rem;
            border-bottom: 1px solid #e2e8f0;
            text-align: left;
        }
        .users-table td {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: middle;
            color: #334155;
        }
        .users-table tr:hover {
            background: #f8fafc;
        }
        .users-table tr.selected {
            background: #eff6ff;
        }

        /* Action Menu Dropdown */
        .action-menu-container {
            position: relative;
            display: inline-block;
        }
        .action-btn {
            background: none;
            border: none;
            color: #64748b;
            cursor: pointer;
            padding: 4px;
            border-radius: 4px;
            display: flex;
            align-items: center;
        }
        .action-btn:hover {
            background: #e2e8f0;
            color: #0f172a;
        }
        .action-dropdown {
            display: none;
            position: absolute;
            right: 0;
            top: 100%;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            border-radius: 6px;
            min-width: 160px;
            z-index: 50;
            padding: 4px 0;
        }
        .action-dropdown.show {
            display: block;
        }
        .action-dropdown button {
            width: 100%;
            text-align: left;
            background: none;
            border: none;
            padding: 8px 12px;
            font-size: 0.8rem;
            color: #334155;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .action-dropdown button:hover {
            background: #f1f5f9;
            color: #0f172a;
        }
        .action-dropdown button.danger:hover {
            background: #fef2f2;
            color: #dc2626;
        }

        /* Modal Overlay */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(15, 23, 42, 0.6) !important;
            backdrop-filter: blur(3px) !important;
            z-index: 9999 !important;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            opacity: 1 !important;
        }
        .modal-overlay.active {
            display: flex !important;
            opacity: 1 !important;
        }
        .modal-card {
            background: white;
            border-radius: 10px;
            width: 100%;
            max-width: 550px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
            position: relative;
        }
        .modal-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-body {
            padding: 1.5rem;
        }
        .modal-footer {
            padding: 1rem 1.5rem;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
            background: #f8fafc;
            border-radius: 0 0 10px 10px;
        }
    </style>
</head>
<body>

<div class="app-layout">
    <?php include 'includes/sidebar.php'; ?>

    <main class="main-content">
        <header class="top-nav">
            <div>
                <h2 style="font-size: 1.125rem;">User Management</h2>
                <div style="display: flex; align-items: center; gap: 0.5rem; margin-top: 0.25rem;">
                    <span style="font-size: 0.75rem; color: var(--text-light); text-transform: uppercase;">Viewing as:</span>
                    <?php renderRoleSwitcher($_SESSION['active_study_id']); ?>
                </div>
            </div>
            <div style="display: flex; align-items: center; gap: 0.5rem;">
                <span style="font-weight: 500; font-size: 0.875rem;"><?php echo htmlspecialchars($_SESSION['username']); ?></span>
                <a href="logout.php" style="font-size: 0.875rem; color: var(--text-light);">Logout</a>
            </div>
        </header>

        <div class="page-content">
            <div class="container" style="max-width: 1400px; margin: 0;">

                <!-- Notification Alert Banners -->
                <?php if ($success): ?>
                <div style="display: flex; align-items: center; justify-content: space-between; background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; padding: 0.65rem 1rem; border-radius: 6px; font-size: 0.85rem; margin-bottom: 1rem;">
                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                        <span class="material-icons-round" style="font-size: 18px; color: #10b981;">check_circle</span>
                        <div><?php echo $success; ?></div>
                    </div>
                    <button type="button" onclick="this.parentElement.remove()" style="background:none; border:none; color: #065f46; cursor:pointer; font-size: 1.1rem; line-height: 1;">&times;</button>
                </div>
                <?php endif; ?>

                <?php if ($error): ?>
                <div style="display: flex; align-items: center; justify-content: space-between; background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 0.65rem 1rem; border-radius: 6px; font-size: 0.85rem; margin-bottom: 1rem;">
                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                        <span class="material-icons-round" style="font-size: 18px; color: #ef4444;">error</span>
                        <div><?php echo sanitizeInput($error); ?></div>
                    </div>
                    <button type="button" onclick="this.parentElement.remove()" style="background:none; border:none; color: #991b1b; cursor:pointer; font-size: 1.1rem; line-height: 1;">&times;</button>
                </div>
                <?php endif; ?>

                <!-- Page Header -->
                <div class="page-header-container">
                    <div>
                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                            <h1 style="font-size: 1.35rem; font-weight: 700; color: #1e293b; margin: 0;">Study Team Members</h1>
                            <span class="count-badge"><?php echo $total_matching_users; ?></span>
                        </div>
                        <p style="font-size: 0.85rem; color: #64748b; margin-top: 2px;">Manage users, roles, and study access.</p>
                    </div>

                    <div style="display: flex; gap: 0.6rem; align-items: center;">
                        <button type="button" onclick="openBulkImportModal()" class="btn btn-secondary" style="background: #ffffff; border: 1px solid #cbd5e1; color: #334155; font-size: 0.825rem; font-weight: 500;">
                            <span class="material-icons-round" style="font-size: 18px;">upload_file</span> Bulk Import
                        </button>
                        <button type="button" onclick="openAddUserModal()" class="btn btn-primary" style="font-size: 0.825rem; font-weight: 500;">
                            <span class="material-icons-round" style="font-size: 18px;">person_add</span> Add User
                        </button>
                    </div>
                </div>

                <!-- Floating Bulk Action Bar -->
                <div id="bulkActionBar" style="display: none; background: #0f172a; color: white; padding: 0.6rem 1.25rem; border-radius: 8px; margin-bottom: 1rem; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem;">
                    <div style="font-size: 0.85rem; font-weight: 600; display: flex; align-items: center; gap: 6px;">
                        <span class="material-icons-round" style="font-size: 18px; color: #38bdf8;">checklist</span>
                        <span id="selectedCount">0</span> users selected
                    </div>
                    <div style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
                        <button type="button" onclick="submitBulk('activate')" class="btn btn-sm" style="background: #16a34a; color: white; border: none;">
                            <span class="material-icons-round" style="font-size: 14px;">check_circle</span> Activate
                        </button>
                        <button type="button" onclick="submitBulk('deactivate')" class="btn btn-sm" style="background: #475569; color: white; border: none;">
                            <span class="material-icons-round" style="font-size: 14px;">block</span> Deactivate
                        </button>
                        <button type="button" onclick="openBulkRolesModal()" class="btn btn-sm" style="background: #2563eb; color: white; border: none;">
                            <span class="material-icons-round" style="font-size: 14px;">manage_accounts</span> Assign Roles
                        </button>
                        <button type="button" onclick="submitBulk('remove')" class="btn btn-sm" style="background: #dc2626; color: white; border: none;">
                            <span class="material-icons-round" style="font-size: 14px;">person_remove</span> Remove from Study
                        </button>
                        <button type="button" onclick="clearSelection()" class="btn btn-sm" style="background: transparent; color: #94a3b8; border: none;">
                            Clear
                        </button>
                    </div>
                </div>

                <!-- Filter & Search Toolbar -->
                <div class="card" style="padding: 0.85rem 1.25rem; margin-bottom: 1rem; border-radius: 8px;">
                    <form method="GET" id="filterForm" style="display: flex; flex-wrap: wrap; gap: 0.65rem; align-items: center; justify-content: space-between;">
                        <div style="display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; flex: 1; min-width: 280px;">
                            
                            <!-- Search -->
                            <div style="position: relative; min-width: 210px; flex: 1;">
                                <span class="material-icons-round" style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); font-size: 18px; color: #94a3b8;">search</span>
                                <input type="text" name="q" value="<?php echo htmlspecialchars($search_query); ?>" placeholder="Search name, email, username..." class="filter-control">
                            </div>

                            <!-- Role Filter -->
                            <select name="role" onchange="this.form.submit()" class="filter-control" style="width: auto;">
                                <option value="">All Roles</option>
                                <option value="Admin" <?php echo $filter_role === 'Admin' ? 'selected' : ''; ?>>Admin</option>
                                <option value="Data Coordinator" <?php echo $filter_role === 'Data Coordinator' ? 'selected' : ''; ?>>Data Coordinator</option>
                                <option value="Data Entry" <?php echo $filter_role === 'Data Entry' ? 'selected' : ''; ?>>Data Entry</option>
                                <option value="Data Monitor" <?php echo $filter_role === 'Data Monitor' ? 'selected' : ''; ?>>Data Monitor</option>
                                <option value="Data Manager" <?php echo $filter_role === 'Data Manager' ? 'selected' : ''; ?>>Data Manager</option>
                            </select>

                            <!-- College Filter -->
                            <select name="college" onchange="this.form.submit()" class="filter-control" style="width: auto; max-width: 160px;">
                                <option value="">All Colleges</option>
                                <?php foreach ($all_colleges as $c): ?>
                                <option value="<?php echo htmlspecialchars($c); ?>" <?php echo $filter_college === $c ? 'selected' : ''; ?>><?php echo htmlspecialchars($c); ?></option>
                                <?php endforeach; ?>
                            </select>

                            <!-- Batch Filter -->
                            <select name="batch" onchange="this.form.submit()" class="filter-control" style="width: auto; max-width: 140px;">
                                <option value="">All Batches</option>
                                <?php foreach ($all_batches as $b): ?>
                                <option value="<?php echo htmlspecialchars($b); ?>" <?php echo $filter_batch === $b ? 'selected' : ''; ?>><?php echo htmlspecialchars($b); ?></option>
                                <?php endforeach; ?>
                            </select>

                            <!-- Status Filter -->
                            <select name="status" onchange="this.form.submit()" class="filter-control" style="width: auto;">
                                <option value="">All Statuses</option>
                                <option value="active" <?php echo $filter_status === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo $filter_status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            </select>

                            <!-- Sort -->
                            <select name="sort" onchange="this.form.submit()" class="filter-control" style="width: auto;">
                                <option value="joined_desc" <?php echo $sort_by === 'joined_desc' ? 'selected' : ''; ?>>Newest Joined</option>
                                <option value="joined_asc" <?php echo $sort_by === 'joined_asc' ? 'selected' : ''; ?>>Oldest Joined</option>
                                <option value="name_asc" <?php echo $sort_by === 'name_asc' ? 'selected' : ''; ?>>Name (A-Z)</option>
                                <option value="name_desc" <?php echo $sort_by === 'name_desc' ? 'selected' : ''; ?>>Name (Z-A)</option>
                            </select>

                            <?php if ($has_filters): ?>
                            <a href="study_users.php" class="btn btn-sm" style="background: #f1f5f9; color: #64748b; font-size: 0.78rem;">Clear Filters</a>
                            <?php endif; ?>
                        </div>

                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                            <select name="limit" onchange="this.form.submit()" class="filter-control" style="width: 70px;">
                                <option value="10" <?php echo $limit === 10 ? 'selected' : ''; ?>>10</option>
                                <option value="25" <?php echo $limit === 25 ? 'selected' : ''; ?>>25</option>
                                <option value="50" <?php echo $limit === 50 ? 'selected' : ''; ?>>50</option>
                                <option value="100" <?php echo $limit === 100 ? 'selected' : ''; ?>>100</option>
                            </select>
                            <span style="font-size: 0.78rem; color: #64748b;">per page</span>
                        </div>
                    </form>
                </div>

                <!-- Full-Width Users Table Container -->
                <div class="card" style="padding: 0; overflow: hidden; border-radius: 8px;">
                    <div style="overflow-x: auto;">
                        <table class="users-table">
                            <thead>
                                <tr>
                                    <th style="width: 40px; text-align: center;">
                                        <input type="checkbox" id="selectAllHeader" onclick="toggleSelectAll(this)">
                                    </th>
                                    <th>User</th>
                                    <th>College / Batch</th>
                                    <th>Roles</th>
                                    <th>Email</th>
                                    <th>Status</th>
                                    <th>Joined</th>
                                    <th style="width: 50px; text-align: right;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($study_users)): ?>
                                <tr>
                                    <td colspan="8" style="padding: 3rem 1.5rem; text-align: center; color: #64748b;">
                                        <span class="material-icons-round" style="font-size: 42px; color: #cbd5e1; display: block; margin-bottom: 0.5rem;">people_outline</span>
                                        <div style="font-weight: 600; color: #334155; margin-bottom: 0.25rem;">No study team members found</div>
                                        <div style="font-size: 0.8rem;">Try clearing search filters or add a new user to this study.</div>
                                    </td>
                                </tr>
                                <?php else: ?>
                                    <?php foreach ($study_users as $u): ?>
                                    <tr id="userRow_<?php echo $u['id']; ?>">
                                        <td style="text-align: center;">
                                            <input type="checkbox" class="user-checkbox" value="<?php echo $u['id']; ?>" onchange="updateSelectionState()">
                                        </td>
                                        <td>
                                            <div style="display: flex; align-items: center; gap: 0.6rem;">
                                                <div style="width: 32px; height: 32px; background: #e2e8f0; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; font-weight: 700; color: #475569; flex-shrink: 0;">
                                                    <?php echo strtoupper(substr($u['display_name'], 0, 1)); ?>
                                                </div>
                                                <div>
                                                    <div style="font-weight: 600; color: #1e293b;"><?php echo htmlspecialchars($u['display_name']); ?></div>
                                                    <div style="font-size: 0.75rem; color: #64748b; font-family: monospace;">Reg No: <?php echo htmlspecialchars($u['username']); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div>
                                                <div style="color: #334155; font-size: 0.825rem; font-weight: 500;"><?php echo htmlspecialchars($u['college_name'] ?: '—'); ?></div>
                                                <?php if (!empty($u['batch_name'])): ?>
                                                <div style="color: #64748b; font-size: 0.75rem; margin-top: 1px;">Batch: <?php echo htmlspecialchars($u['batch_name']); ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div>
                                                <?php 
                                                $role_count = count($u['roles']);
                                                $visible_roles = array_slice($u['roles'], 0, 2);
                                                foreach ($visible_roles as $r): ?>
                                                    <span class="role-pill"><?php echo htmlspecialchars($r); ?></span>
                                                <?php endforeach; ?>
                                                <?php if ($role_count > 2): ?>
                                                    <span class="role-more" title="<?php echo htmlspecialchars(implode(', ', $u['roles'])); ?>">+<?php echo ($role_count - 2); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="display: flex; align-items: center; gap: 4px; color: #475569; font-size: 0.825rem;">
                                                <span><?php echo htmlspecialchars($u['email']); ?></span>
                                                <button type="button" onclick="copyText('<?php echo htmlspecialchars($u['email']); ?>')" title="Copy Email" style="background:none; border:none; color:#94a3b8; cursor:pointer; padding: 2px; display:inline-flex;">
                                                    <span class="material-icons-round" style="font-size:14px;">content_copy</span>
                                                </button>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="status-badge <?php echo $u['status'] === 'active' ? 'active' : 'inactive'; ?>">
                                                <span class="dot"></span>
                                                <?php echo ucfirst($u['status']); ?>
                                            </span>
                                        </td>
                                        <td style="color: #64748b; font-size: 0.8rem; white-space: nowrap;">
                                            <?php echo formatDate($u['joined_at']); ?>
                                        </td>
                                        <td style="text-align: right;">
                                            <div class="action-menu-container">
                                                <button type="button" class="action-btn" onclick="toggleActionDropdown(event, <?php echo $u['id']; ?>)">
                                                    <span class="material-icons-round">more_vert</span>
                                                </button>
                                                <div class="action-dropdown" id="actionDropdown_<?php echo $u['id']; ?>">
                                                    <button type="button" onclick="openEditUserModal(event, <?php echo $u['id']; ?>)">
                                                        <span class="material-icons-round" style="font-size:16px;">edit</span> Edit User
                                                    </button>
                                                    <button type="button" onclick="openResetPasswordModal(event, <?php echo $u['id']; ?>)">
                                                        <span class="material-icons-round" style="font-size:16px;">lock_reset</span> Reset Password
                                                    </button>
                                                    <button type="button" onclick="toggleUserStatus(event, <?php echo $u['id']; ?>, '<?php echo $u['status'] === 'active' ? 'inactive' : 'active'; ?>')">
                                                        <span class="material-icons-round" style="font-size:16px;"><?php echo $u['status'] === 'active' ? 'block' : 'check_circle'; ?></span> 
                                                        <?php echo $u['status'] === 'active' ? 'Deactivate' : 'Activate'; ?>
                                                    </button>
                                                    <button type="button" class="danger" onclick="removeUserFromStudy(event, <?php echo $u['id']; ?>)">
                                                        <span class="material-icons-round" style="font-size:16px;">person_remove</span> Remove from Study
                                                    </button>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination Footer -->
                    <div style="padding: 0.85rem 1.25rem; background: #f8fafc; border-top: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.75rem;">
                        <div style="font-size: 0.8rem; color: #64748b;">
                            Showing <strong><?php echo $start_item; ?></strong>–<strong><?php echo $end_item; ?></strong> of <strong><?php echo $total_matching_users; ?></strong> users
                        </div>

                        <?php if ($total_pages > 1): ?>
                        <div style="display: flex; gap: 4px; align-items: center;">
                            <?php if ($page > 1): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="btn btn-sm" style="background: white; border: 1px solid #cbd5e1; color: #475569;">&laquo; Prev</a>
                            <?php endif; ?>

                            <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                                <?php if ($p == $page): ?>
                                    <span class="btn btn-sm" style="background: var(--primary-color); color: white; border: none; font-weight: 700;"><?php echo $p; ?></span>
                                <?php elseif ($p <= 3 || $p >= $total_pages - 1 || abs($p - $page) <= 1): ?>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $p])); ?>" class="btn btn-sm" style="background: white; border: 1px solid #cbd5e1; color: #475569;"><?php echo $p; ?></a>
                                <?php elseif (abs($p - $page) == 2): ?>
                                    <span style="padding: 0 4px; color: #94a3b8;">...</span>
                                <?php endif; ?>
                            <?php endfor; ?>

                            <?php if ($page < $total_pages): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="btn btn-sm" style="background: white; border: 1px solid #cbd5e1; color: #475569;">Next &raquo;</a>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </div>
    </main>
</div>

<!-- ========================================================================= -->
<!-- MODAL 1: ADD USER -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="modalAddUser">
    <div class="modal-card">
        <div class="modal-header">
            <h3 style="margin: 0; font-size: 1.1rem; color: #0f172a;">Add Study Team Member</h3>
            <button type="button" onclick="closeModal('modalAddUser')" style="background:none; border:none; font-size: 1.25rem; color:#64748b; cursor:pointer;">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="add_user">
            <div class="modal-body">
                <div class="form-group" style="margin-bottom: 0.85rem;">
                    <label class="form-label">Full Name *</label>
                    <input type="text" name="name" class="form-input" placeholder="e.g. John Doe" required>
                </div>
                <div class="form-group" style="margin-bottom: 0.85rem;">
                    <label class="form-label">Email Address *</label>
                    <input type="email" name="email" class="form-input" placeholder="e.g. student@college.edu" required>
                </div>
                <div class="form-group" style="margin-bottom: 0.85rem;">
                    <label class="form-label">Register Number / Username</label>
                    <input type="text" name="username" class="form-input" placeholder="e.g. REG2026001 (Optional)">
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; margin-bottom: 0.85rem;">
                    <div class="form-group">
                        <label class="form-label">College Name</label>
                        <input type="text" name="college_name" class="form-input" placeholder="College name">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Batch Name</label>
                        <input type="text" name="batch_name" class="form-input" placeholder="Batch tag">
                    </div>
                </div>
                <div class="form-group" style="margin-bottom: 0.85rem;">
                    <label class="form-label">Account Status</label>
                    <select name="status" class="form-input">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom: 0.85rem;">
                    <label class="form-label">Roles *</label>
                    <div style="display: flex; flex-direction: column; gap: 0.35rem; margin-top: 0.25rem;">
                        <label style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.85rem;">
                            <input type="checkbox" name="roles[]" value="Admin"> Admin (Full Access)
                        </label>
                        <label style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.85rem;">
                            <input type="checkbox" name="roles[]" value="Data Coordinator"> Data Coordinator
                        </label>
                        <label style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.85rem;">
                            <input type="checkbox" name="roles[]" value="Data Entry" checked> Data Entry
                        </label>
                        <label style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.85rem;">
                            <input type="checkbox" name="roles[]" value="Data Monitor"> Data Monitor
                        </label>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Temporary Password *</label>
                    <input type="text" name="temp_password" class="form-input" value="Welcome2026!" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeModal('modalAddUser')" class="btn btn-sm" style="background:#e2e8f0; color:#334155;">Cancel</button>
                <button type="submit" class="btn btn-sm btn-primary">Create User</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 2: EDIT USER -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="modalEditUser">
    <div class="modal-card">
        <div class="modal-header">
            <h3 style="margin: 0; font-size: 1.1rem; color: #0f172a;">Edit User Profile</h3>
            <button type="button" onclick="closeModal('modalEditUser')" style="background:none; border:none; font-size: 1.25rem; color:#64748b; cursor:pointer;">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="edit_user">
            <input type="hidden" name="user_id" id="editUserId">
            <div class="modal-body">
                <div class="form-group" style="margin-bottom: 0.85rem;">
                    <label class="form-label">Full Name *</label>
                    <input type="text" name="name" id="editName" class="form-input" required>
                </div>
                <div class="form-group" style="margin-bottom: 0.85rem;">
                    <label class="form-label">Email Address *</label>
                    <input type="email" name="email" id="editEmail" class="form-input" required>
                </div>
                <div class="form-group" style="margin-bottom: 0.85rem;">
                    <label class="form-label">Username / Register Number</label>
                    <input type="text" name="username" id="editUsername" class="form-input">
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; margin-bottom: 0.85rem;">
                    <div class="form-group">
                        <label class="form-label">College Name</label>
                        <input type="text" name="college_name" id="editCollege" class="form-input">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Batch Name</label>
                        <input type="text" name="batch_name" id="editBatch" class="form-input">
                    </div>
                </div>
                <div class="form-group" style="margin-bottom: 0.85rem;">
                    <label class="form-label">Account Status</label>
                    <select name="status" id="editStatus" class="form-input">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Assigned Roles *</label>
                    <div style="display: flex; flex-direction: column; gap: 0.35rem; margin-top: 0.25rem;">
                        <label style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.85rem;">
                            <input type="checkbox" name="roles[]" value="Admin" id="role_Admin"> Admin
                        </label>
                        <label style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.85rem;">
                            <input type="checkbox" name="roles[]" value="Data Coordinator" id="role_DataCoordinator"> Data Coordinator
                        </label>
                        <label style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.85rem;">
                            <input type="checkbox" name="roles[]" value="Data Entry" id="role_DataEntry"> Data Entry
                        </label>
                        <label style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.85rem;">
                            <input type="checkbox" name="roles[]" value="Data Monitor" id="role_DataMonitor"> Data Monitor
                        </label>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeModal('modalEditUser')" class="btn btn-sm" style="background:#e2e8f0; color:#334155;">Cancel</button>
                <button type="submit" class="btn btn-sm btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 3: BULK IMPORT (CSV, XLSX, TSV, TXT) -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="modalBulkImport">
    <div class="modal-card" style="max-width: 600px;">
        <div class="modal-header">
            <h3 style="margin: 0; font-size: 1.1rem; color: #0f172a;">Bulk Import Team Members</h3>
            <button type="button" onclick="closeModal('modalBulkImport')" style="background:none; border:none; font-size: 1.25rem; color:#64748b; cursor:pointer;">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="bulk_import">
            <div class="modal-body">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
                    <span style="font-size: 0.8rem; color: #64748b;">Upload student roster or team list</span>
                    <a href="study_users.php?download_sample=1" class="btn btn-sm" style="background: #f1f5f9; color: #334155; font-size: 0.75rem; border: 1px solid #cbd5e1;">
                        <span class="material-icons-round" style="font-size: 14px;">download</span> Sample CSV
                    </a>
                </div>

                <div class="form-group" style="margin-bottom: 1rem;">
                    <label class="form-label">Choose File (.csv, .xlsx, .tsv, .txt)</label>
                    <input type="file" name="import_file" accept=".csv, .xlsx, .xls, .tsv, .txt" class="form-input" required style="padding: 0.4rem;">
                </div>

                <details style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 0.75rem; font-size: 0.78rem; color: #475569;">
                    <summary style="font-weight: 600; cursor: pointer; color: #0f172a;">View Column Requirements & Instructions</summary>
                    <div style="margin-top: 0.5rem; line-height: 1.4;">
                        <strong>Supported File Formats:</strong> .CSV, .XLSX (Excel), .TSV, .TXT
                        <br><br>
                        <strong>Expected Header Columns:</strong>
                        <ul style="margin: 0.3rem 0 0 1.2rem; padding: 0;">
                            <li><code>Name</code> & <code>Email</code> (Required)</li>
                            <li><code>Username</code> (Register Number)</li>
                            <li><code>Temporary Password</code></li>
                            <li>Role Columns: <code>Admin</code>, <code>Data Coordinator</code>, <code>Data Entry</code>, <code>Data Monitor</code> (Values: <code>YES</code>, <code>yes</code>, <code>Yes</code>, <code>YEs</code>, <code>1</code>, or <code>no</code>)</li>
                            <li><code>College Name</code> & <code>Batch Name</code></li>
                        </ul>
                    </div>
                </details>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeModal('modalBulkImport')" class="btn btn-sm" style="background:#e2e8f0; color:#334155;">Cancel</button>
                <button type="submit" class="btn btn-sm btn-primary">Process Import</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 4: RESET PASSWORD -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="modalResetPassword">
    <div class="modal-card" style="max-width: 450px;">
        <div class="modal-header">
            <h3 style="margin: 0; font-size: 1.1rem; color: #0f172a;">Reset Password</h3>
            <button type="button" onclick="closeModal('modalResetPassword')" style="background:none; border:none; font-size: 1.25rem; color:#64748b; cursor:pointer;">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="user_id" id="resetUserId">
            <div class="modal-body">
                <p style="font-size: 0.85rem; color: #475569; margin-bottom: 1rem;">
                    Reset temporary password for <strong id="resetUserName">User</strong>.
                </p>
                <div class="form-group" style="margin-bottom: 0.85rem;">
                    <label class="form-label">New Password *</label>
                    <input type="password" name="new_password" class="form-input" minlength="6" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Confirm New Password *</label>
                    <input type="password" name="confirm_password" class="form-input" minlength="6" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeModal('modalResetPassword')" class="btn btn-sm" style="background:#e2e8f0; color:#334155;">Cancel</button>
                <button type="submit" class="btn btn-sm btn-primary">Save New Password</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 5: BULK ROLES ASSIGNMENT -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="modalBulkRoles">
    <div class="modal-card" style="max-width: 480px;">
        <div class="modal-header">
            <h3 style="margin: 0; font-size: 1.1rem; color: #0f172a;">Assign Roles to Selected Users</h3>
            <button type="button" onclick="closeModal('modalBulkRoles')" style="background:none; border:none; font-size: 1.25rem; color:#64748b; cursor:pointer;">&times;</button>
        </div>
        <form method="POST" id="formBulkRoles">
            <input type="hidden" name="action" value="bulk_action">
            <input type="hidden" name="bulk_type" value="assign_roles">
            <div id="bulkRolesUserIdsContainer"></div>

            <div class="modal-body">
                <div style="font-size: 0.85rem; color: #475569; margin-bottom: 1rem;">
                    Updating roles for <strong id="bulkRolesCount">0</strong> selected users.
                </div>

                <div class="form-group" style="margin-bottom: 1rem;">
                    <label class="form-label">Assignment Mode</label>
                    <div style="display: flex; gap: 1rem; margin-top: 0.25rem;">
                        <label style="display: flex; align-items: center; gap: 0.4rem; font-size: 0.85rem;">
                            <input type="radio" name="bulk_role_mode" value="add" checked> Add Roles (Append)
                        </label>
                        <label style="display: flex; align-items: center; gap: 0.4rem; font-size: 0.85rem;">
                            <input type="radio" name="bulk_role_mode" value="replace"> Replace Roles (Overwrite)
                        </label>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Roles to Apply *</label>
                    <div style="display: flex; flex-direction: column; gap: 0.35rem; margin-top: 0.25rem;">
                        <label style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.85rem;">
                            <input type="checkbox" name="roles[]" value="Admin"> Admin
                        </label>
                        <label style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.85rem;">
                            <input type="checkbox" name="roles[]" value="Data Coordinator"> Data Coordinator
                        </label>
                        <label style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.85rem;">
                            <input type="checkbox" name="roles[]" value="Data Entry"> Data Entry
                        </label>
                        <label style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.85rem;">
                            <input type="checkbox" name="roles[]" value="Data Monitor"> Data Monitor
                        </label>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" onclick="closeModal('modalBulkRoles')" class="btn btn-sm" style="background:#e2e8f0; color:#334155;">Cancel</button>
                <button type="submit" class="btn btn-sm btn-primary">Apply Roles</button>
            </div>
        </form>
    </div>
</div>

<!-- HIDDEN FORMS FOR DYNAMIC ACTIONS -->
<form id="actionForm" method="POST" style="display:none;">
    <input type="hidden" name="action" id="actionFormAction">
    <input type="hidden" name="user_id" id="actionFormUserId">
    <input type="hidden" name="new_status" id="actionFormStatus">
</form>

<form id="bulkActionForm" method="POST" style="display:none;">
    <input type="hidden" name="action" value="bulk_action">
    <input type="hidden" name="bulk_type" id="bulkFormType">
<!-- ========================================================================= -->
<!-- MODAL 6: REUSABLE CONFIRMATION DIALOG -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="modalConfirmAction" style="z-index: 10000;">
    <div class="modal-card" style="max-width: 440px; border-radius: 12px; overflow: hidden; padding: 0; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04);">
        <div style="padding: 1.75rem 1.5rem; text-align: center;">
            <div id="confirmIconContainer" style="width: 56px; height: 56px; border-radius: 50%; background: #fef3c7; color: #d97706; display: flex; align-items: center; justify-content: center; margin: 0 auto 1rem auto;">
                <span class="material-icons-round" id="confirmIcon" style="font-size: 30px;">warning</span>
            </div>
            <h3 id="confirmTitle" style="margin: 0 0 0.5rem 0; font-size: 1.15rem; color: #0f172a; font-weight: 700;">Confirm Action</h3>
            <div id="confirmMessage" style="font-size: 0.875rem; color: #475569; line-height: 1.5;">
                Are you sure you want to proceed with this action?
            </div>
        </div>
        <div class="modal-footer" style="background: #f8fafc; padding: 0.85rem 1.5rem; display: flex; gap: 0.75rem; justify-content: flex-end; border-top: 1px solid #e2e8f0;">
            <button type="button" onclick="closeModal('modalConfirmAction')" class="btn btn-sm" style="background: #ffffff; border: 1px solid #cbd5e1; color: #475569; padding: 0.45rem 1rem;">
                Cancel
            </button>
            <button type="button" id="confirmSubmitBtn" class="btn btn-sm btn-primary" style="padding: 0.45rem 1.25rem;">
                Confirm
            </button>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 7: REUSABLE CUSTOM ALERT DIALOG -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="modalCustomAlert" style="z-index: 10001;">
    <div class="modal-card" style="max-width: 420px; border-radius: 12px; overflow: hidden; padding: 0; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04);">
        <div style="padding: 1.75rem 1.5rem; text-align: center;">
            <div id="alertIconContainer" style="width: 56px; height: 56px; border-radius: 50%; background: #eff6ff; color: #2563eb; display: flex; align-items: center; justify-content: center; margin: 0 auto 1rem auto;">
                <span class="material-icons-round" id="alertIcon" style="font-size: 30px;">info</span>
            </div>
            <h3 id="alertTitle" style="margin: 0 0 0.5rem 0; font-size: 1.15rem; color: #0f172a; font-weight: 700;">Notice</h3>
            <div id="alertMessage" style="font-size: 0.875rem; color: #475569; line-height: 1.5;"></div>
        </div>
        <div class="modal-footer" style="background: #f8fafc; padding: 0.85rem 1.5rem; display: flex; justify-content: center; border-top: 1px solid #e2e8f0;">
            <button type="button" id="alertOkBtn" onclick="closeModal('modalCustomAlert')" class="btn btn-sm btn-primary" style="padding: 0.45rem 1.75rem; font-weight: 600;">
                OK
            </button>
        </div>
    </div>
</div>

<!-- TOAST NOTIFICATION CONTAINER -->
<div id="toastContainer" style="position: fixed; bottom: 24px; right: 24px; z-index: 99999; display: flex; flex-direction: column; gap: 10px; pointer-events: none;"></div>


<script>
const STUDY_USERS = <?php echo json_encode($study_users, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

// Toggle Action Dropdown
function toggleActionDropdown(e, id) {
    if (e) e.stopPropagation();
    document.querySelectorAll('.action-dropdown').forEach(d => {
        if (d.id !== 'actionDropdown_' + id) d.classList.remove('show');
    });
    const target = document.getElementById('actionDropdown_' + id);
    if (target) target.classList.toggle('show');
}

document.addEventListener('click', function() {
    document.querySelectorAll('.action-dropdown').forEach(d => d.classList.remove('show'));
});

// Modal Handlers
function openModal(id) {
    const el = document.getElementById(id);
    if (el) {
        el.style.display = 'flex';
        el.style.opacity = '1';
        el.classList.add('active');
    }
}
function closeModal(id) {
    const el = document.getElementById(id);
    if (el) {
        el.style.display = 'none';
        el.style.opacity = '0';
        el.classList.remove('active');
    }
}

function togglePasswordVisibility(inputId, btn) {
    const inp = document.getElementById(inputId);
    if (!inp) return;
    const icon = btn.querySelector('.material-icons-round');
    if (inp.type === 'password') {
        inp.type = 'text';
        if (icon) icon.textContent = 'visibility_off';
    } else {
        inp.type = 'password';
        if (icon) icon.textContent = 'visibility';
    }
}

function openAddUserModal() {
    openModal('modalAddUser');
}

function openBulkImportModal() {
    openModal('modalBulkImport');
}

function openEditUserModal(e, id) {
    if (e) e.stopPropagation();
    document.querySelectorAll('.action-dropdown').forEach(d => d.classList.remove('show'));
    const u = STUDY_USERS[id];
    if (!u) return;

    document.getElementById('editUserId').value = u.id;
    document.getElementById('editName').value = u.name || u.display_name || '';
    document.getElementById('editEmail').value = u.email || '';
    document.getElementById('editUsername').value = u.username || '';
    document.getElementById('editCollege').value = u.college_name || '';
    document.getElementById('editBatch').value = u.batch_name || '';
    document.getElementById('editStatus').value = u.status || 'active';

    ['Admin', 'DataCoordinator', 'DataEntry', 'DataMonitor'].forEach(rKey => {
        const cb = document.getElementById('role_' + rKey);
        if (cb) cb.checked = false;
    });

    if (u.roles && Array.isArray(u.roles)) {
        u.roles.forEach(r => {
            const clean = r.replace(/\s+/g, '');
            const cb = document.getElementById('role_' + clean);
            if (cb) cb.checked = true;
        });
    }

    openModal('modalEditUser');
}

function openResetPasswordModal(e, id) {
    if (e) e.stopPropagation();
    document.querySelectorAll('.action-dropdown').forEach(d => d.classList.remove('show'));
    const u = STUDY_USERS[id];
    if (!u) return;

    document.getElementById('resetUserId').value = u.id;
    document.getElementById('resetUserName').textContent = u.display_name;
    openModal('modalResetPassword');
}

let pendingConfirmCallback = null;

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function showConfirmModal(options) {
    const title = options.title || 'Confirm Action';
    const message = options.message || 'Are you sure you want to proceed?';
    const icon = options.icon || 'warning';
    const iconBg = options.iconBg || '#fef3c7';
    const iconColor = options.iconColor || '#d97706';
    const btnText = options.btnText || 'Confirm';
    const btnClass = options.btnClass || 'btn-primary';
    const btnStyle = options.btnStyle || '';

    document.getElementById('confirmTitle').textContent = title;
    document.getElementById('confirmMessage').innerHTML = message;
    
    const iconEl = document.getElementById('confirmIcon');
    if (iconEl) iconEl.textContent = icon;
    
    const iconBox = document.getElementById('confirmIconContainer');
    if (iconBox) {
        iconBox.style.background = iconBg;
        iconBox.style.color = iconColor;
    }

    const confirmBtn = document.getElementById('confirmSubmitBtn');
    if (confirmBtn) {
        confirmBtn.textContent = btnText;
        confirmBtn.className = 'btn btn-sm ' + btnClass;
        confirmBtn.style.cssText = btnStyle;
        confirmBtn.onclick = function() {
            closeModal('modalConfirmAction');
            if (typeof pendingConfirmCallback === 'function') {
                pendingConfirmCallback();
                pendingConfirmCallback = null;
            }
        };
    }

    pendingConfirmCallback = options.onConfirm || null;
    openModal('modalConfirmAction');
}

// Single Action Actions
function toggleUserStatus(e, id, newStatus) {
    if (e) e.stopPropagation();
    document.querySelectorAll('.action-dropdown').forEach(d => d.classList.remove('show'));
    const u = STUDY_USERS[id];
    const name = u ? u.display_name : 'this user';
    const statusLabel = newStatus.toUpperCase();

    showConfirmModal({
        title: newStatus === 'inactive' ? 'Deactivate User Account' : 'Activate User Account',
        message: 'Are you sure you want to set status to <strong>' + statusLabel + '</strong> for <strong>' + escapeHtml(name) + '</strong>?',
        icon: newStatus === 'inactive' ? 'block' : 'check_circle',
        iconBg: newStatus === 'inactive' ? '#fef2f2' : '#ecfdf5',
        iconColor: newStatus === 'inactive' ? '#dc2626' : '#16a34a',
        btnText: newStatus === 'inactive' ? 'Deactivate Account' : 'Activate Account',
        btnStyle: newStatus === 'inactive' ? 'background: #dc2626; color: white; border: none;' : '',
        onConfirm: function() {
            document.getElementById('actionFormAction').value = 'toggle_status';
            document.getElementById('actionFormUserId').value = id;
            document.getElementById('actionFormStatus').value = newStatus;
            document.getElementById('actionForm').submit();
        }
    });
}

function removeUserFromStudy(e, id) {
    if (e) e.stopPropagation();
    document.querySelectorAll('.action-dropdown').forEach(d => d.classList.remove('show'));
    const u = STUDY_USERS[id];
    const name = u ? u.display_name : 'this user';

    showConfirmModal({
        title: 'Remove User from Study',
        message: 'Are you sure you want to remove <strong>' + escapeHtml(name) + '</strong> from this study team? Clinical data and historical records will be preserved.',
        icon: 'person_remove',
        iconBg: '#fef2f2',
        iconColor: '#dc2626',
        btnText: 'Remove User',
        btnStyle: 'background: #dc2626; color: white; border: none;',
        onConfirm: function() {
            document.getElementById('actionFormAction').value = 'remove_user';
            document.getElementById('actionFormUserId').value = id;
            document.getElementById('actionForm').submit();
        }
    });
}

// Checkbox & Bulk Actions
function toggleSelectAll(masterCb) {
    const checkboxes = document.querySelectorAll('.user-checkbox');
    checkboxes.forEach(cb => {
        cb.checked = masterCb.checked;
        const row = document.getElementById('userRow_' + cb.value);
        if (row) {
            if (cb.checked) row.classList.add('selected');
            else row.classList.remove('selected');
        }
    });
    updateSelectionState();
}

function updateSelectionState() {
    const checked = document.querySelectorAll('.user-checkbox:checked');
    const total = document.querySelectorAll('.user-checkbox');
    const master = document.getElementById('selectAllHeader');
    const bar = document.getElementById('bulkActionBar');
    const countSpan = document.getElementById('selectedCount');

    if (checked.length > 0) {
        bar.style.display = 'flex';
        countSpan.textContent = checked.length;
    } else {
        bar.style.display = 'none';
    }

    if (master) {
        master.checked = (checked.length === total.length && total.length > 0);
        master.indeterminate = (checked.length > 0 && checked.length < total.length);
    }
}

function clearSelection() {
    const master = document.getElementById('selectAllHeader');
    if (master) master.checked = false;
    document.querySelectorAll('.user-checkbox').forEach(cb => {
        cb.checked = false;
        const row = document.getElementById('userRow_' + cb.value);
        if (row) row.classList.remove('selected');
    });
    updateSelectionState();
}

function submitBulk(actionType) {
    const checked = document.querySelectorAll('.user-checkbox:checked');
    if (checked.length === 0) return;

    let actionTitle = 'Perform Bulk Action';
    let actionIcon = 'checklist';
    let iconBg = '#eff6ff';
    let iconColor = '#2563eb';
    let btnStyle = '';

    if (actionType === 'activate') {
        actionTitle = 'Bulk Activate Accounts';
        actionIcon = 'check_circle';
        iconBg = '#ecfdf5';
        iconColor = '#16a34a';
    } else if (actionType === 'deactivate') {
        actionTitle = 'Bulk Deactivate Accounts';
        actionIcon = 'block';
        iconBg = '#fef2f2';
        iconColor = '#dc2626';
        btnStyle = 'background: #dc2626; color: white; border: none;';
    } else if (actionType === 'remove') {
        actionTitle = 'Bulk Remove from Study';
        actionIcon = 'person_remove';
        iconBg = '#fef2f2';
        iconColor = '#dc2626';
        btnStyle = 'background: #dc2626; color: white; border: none;';
    }

    showConfirmModal({
        title: actionTitle,
        message: 'Are you sure you want to perform <strong>' + actionType.toUpperCase() + '</strong> on <strong>' + checked.length + '</strong> selected user(s)?',
        icon: actionIcon,
        iconBg: iconBg,
        iconColor: iconColor,
        btnText: 'Proceed (' + checked.length + ' Users)',
        btnStyle: btnStyle,
        onConfirm: function() {
            const container = document.getElementById('bulkFormUserIds');
            container.innerHTML = '';
            checked.forEach(cb => {
                const inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = 'user_ids[]';
                inp.value = cb.value;
                container.appendChild(inp);
            });
            document.getElementById('bulkFormType').value = actionType;
            document.getElementById('bulkActionForm').submit();
        }
    });
}

function openBulkRolesModal() {
    const checked = document.querySelectorAll('.user-checkbox:checked');
    if (checked.length === 0) return;

    const container = document.getElementById('bulkRolesUserIdsContainer');
    container.innerHTML = '';
    checked.forEach(cb => {
        const inp = document.createElement('input');
        inp.type = 'hidden';
        inp.name = 'user_ids[]';
        inp.value = cb.value;
        container.appendChild(inp);
    });

    document.getElementById('bulkRolesCount').textContent = checked.length;
    openModal('modalBulkRoles');
}

function showToast(message, type = 'success', duration = 3500) {
    const container = document.getElementById('toastContainer');
    if (!container) return;
    
    const toast = document.createElement('div');
    toast.style.cssText = `
        pointer-events: auto;
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 12px 18px;
        background: #0f172a;
        color: #ffffff;
        border-radius: 10px;
        box-shadow: 0 10px 15px -3px rgba(0,0,0,0.2), 0 4px 6px -2px rgba(0,0,0,0.1);
        font-size: 0.875rem;
        font-weight: 500;
        opacity: 0;
        transform: translateY(10px);
        transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        max-width: 380px;
    `;
    
    let icon = 'info';
    let iconColor = '#3b82f6';
    if (type === 'success') { icon = 'check_circle'; iconColor = '#10b981'; }
    else if (type === 'error') { icon = 'error'; iconColor = '#ef4444'; }
    else if (type === 'warning') { icon = 'warning'; iconColor = '#f59e0b'; }

    toast.innerHTML = `
        <span class="material-icons-round" style="color: ${iconColor}; font-size: 20px;">${icon}</span>
        <span style="flex: 1; line-height: 1.4;">${escapeHtml(message)}</span>
    `;

    container.appendChild(toast);
    
    requestAnimationFrame(() => {
        toast.style.opacity = '1';
        toast.style.transform = 'translateY(0)';
    });

    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(10px)';
        setTimeout(() => toast.remove(), 300);
    }, duration);
}

function customAlert(message, title = 'Notice', icon = 'info', iconBg = '#eff6ff', iconColor = '#2563eb', callback = null) {
    document.getElementById('alertTitle').textContent = title;
    document.getElementById('alertMessage').innerHTML = message;
    
    const iconEl = document.getElementById('alertIcon');
    if (iconEl) iconEl.textContent = icon;
    
    const iconBox = document.getElementById('alertIconContainer');
    if (iconBox) {
        iconBox.style.background = iconBg;
        iconBox.style.color = iconColor;
    }

    const btn = document.getElementById('alertOkBtn');
    if (btn) {
        btn.onclick = function() {
            closeModal('modalCustomAlert');
            if (typeof callback === 'function') callback();
        };
    }
    openModal('modalCustomAlert');
}

// Override native window.alert to use custom professional toast/modal
window.alert = function(msg) {
    showToast(msg, 'info');
};

// Override native window.confirm to route to custom confirm modal
window.confirm = function(msg) {
    showConfirmModal({
        title: 'Confirmation Needed',
        message: escapeHtml(msg),
        icon: 'help_outline',
        iconBg: '#eff6ff',
        iconColor: '#2563eb',
        btnText: 'Confirm',
        onConfirm: function() {}
    });
    return false;
};

function copyText(text) {
    if (!text) return;
    navigator.clipboard.writeText(text).then(() => {
        showToast('Email copied to clipboard!', 'success');
    }).catch(() => {
        showToast('Failed to copy email.', 'error');
    });
}
</script>
<script src="assets/js/app.js"></script>
</body>
</html>
