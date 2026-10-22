<?php

/**
 * Generate a single Subject ID based on method and sequence
 */
function generateSubjectIdString($method, $seq, $site_data) {
    if (!$site_data) return null;

    $country_code = getCountryCode($site_data['country'] ?? 'India', 2);
    $site_code = $site_data['site_code'] ?? '01';
    $site_abbr = $site_data['abbreviation'] ?? 'SITE';
    
    $generated_code = '';
    
    switch ($method) {
        case 'incremental':
            $generated_code = str_pad($seq, 4, '0', STR_PAD_LEFT);
            break;
        case 'random':
            $generated_code = rand(100000, 999999);
            break;
        case 'incremental_site':
            $generated_code = $site_code . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);
            break;
        case 'country_site_2': // Country(2) - Site(2) - Seq(3)
            $generated_code = $country_code . '-' . substr($site_code, 0, 2) . '-' . str_pad($seq, 3, '0', STR_PAD_LEFT);
            break;
        case 'country_site_3': // Country(2) - Site(3) - Seq(3)
            $generated_code = $country_code . '-' . substr($site_code, 0, 3) . '-' . str_pad($seq, 3, '0', STR_PAD_LEFT);
            break;
        case 'country_abbr_2': // Country(2) - Abbr(2) - Seq(3)
            $generated_code = $country_code . '-' . substr($site_abbr, 0, 2) . '-' . str_pad($seq, 3, '0', STR_PAD_LEFT);
            break;
        default:
            $generated_code = str_pad($seq, 4, '0', STR_PAD_LEFT);
    }
    
    return strtoupper($generated_code);
}

/**
 * Regenerate IDs for ALL subjects in a study
 */
function regenerateStudySubjectIDs($pdo, $study_id) {
    // 1. Get Study Config
    $stmt = $pdo->prepare("SELECT participant_id_method FROM studies WHERE id = ?");
    $stmt->execute([$study_id]);
    $method = $stmt->fetchColumn() ?? 'incremental';

    if ($method === 'free_text') return false; // Cannot auto-migrate free text

    // 2. Get Sites
    $stmt = $pdo->prepare("SELECT * FROM sites WHERE study_id = ?");
    $stmt->execute([$study_id]);
    $sites_raw = $stmt->fetchAll();
    $sites = [];
    foreach ($sites_raw as $s) {
        $sites[$s['name']] = $s;
    }

    // 3. Get Subjects (Ordered by creation)
    $stmt = $pdo->prepare("SELECT * FROM subjects WHERE study_id = ? ORDER BY created_at ASC, id ASC");
    $stmt->execute([$study_id]);
    $subjects = $stmt->fetchAll();

    // 4. Counters
    $global_seq = 0;
    $site_seqs = []; 

    // 5. Update Loop
    foreach ($subjects as $sub) {
        $site_name = $sub['site_name'];
        if (!isset($sites[$site_name])) continue; // Skip if site missing
        
        if (!isset($site_seqs[$site_name])) $site_seqs[$site_name] = 0;
        
        $global_seq++;
        $site_seqs[$site_name]++;
        
        $is_site_specific = (strpos($method, 'site') !== false || strpos($method, 'abbr') !== false);
        $seq = $is_site_specific ? $site_seqs[$site_name] : $global_seq;
        
        $new_code = generateSubjectIdString($method, $seq, $sites[$site_name]);
        
        if ($new_code && $new_code !== $sub['subject_code']) {
            $upd = $pdo->prepare("UPDATE subjects SET subject_code = ? WHERE id = ?");
            $upd->execute([$new_code, $sub['id']]);
        }
    }
    
    return true;
}

/**
 * Get the next available unique Subject Code for a study/site
 * Guaranteed never to duplicate existing subject codes and fills gaps if prior IDs were deleted.
 */
function getNextAvailableSubjectCode($pdo, $study_id, $site_name = null) {
    // 1. Get Study Method
    $study_stmt = $pdo->prepare("SELECT participant_id_method FROM studies WHERE id = ?");
    $study_stmt->execute([$study_id]);
    $method = $study_stmt->fetchColumn() ?: 'incremental';

    if ($method === 'free_text') {
        return null;
    }

    // 2. Fetch site data
    $site_data = null;
    if ($site_name) {
        $site_stmt = $pdo->prepare("SELECT * FROM sites WHERE name = ? AND study_id = ?");
        $site_stmt->execute([$site_name, $study_id]);
        $site_data = $site_stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$site_data) {
        $site_stmt = $pdo->prepare("SELECT * FROM sites WHERE study_id = ? ORDER BY id ASC LIMIT 1");
        $site_stmt->execute([$study_id]);
        $site_data = $site_stmt->fetch(PDO::FETCH_ASSOC);
        if (!$site_data) {
            $site_data = ['country' => 'India', 'site_code' => '01', 'abbreviation' => 'SITE'];
        }
    }

    // 3. Fetch all currently existing subject codes for this study
    $stmt_existing = $pdo->prepare("SELECT UPPER(subject_code) FROM subjects WHERE study_id = ?");
    $stmt_existing->execute([$study_id]);
    $existing_codes = $stmt_existing->fetchAll(PDO::FETCH_COLUMN);
    $existing_map = array_fill_keys($existing_codes, true);

    // 4. Random generator check
    if ($method === 'random') {
        do {
            $code = (string)rand(100000, 999999);
        } while (isset($existing_map[$code]));
        return $code;
    }

    // 5. Sequential Search starting from 1 to find the lowest unused sequence
    $seq = 1;
    while ($seq < 1000000) {
        $candidate = generateSubjectIdString($method, $seq, $site_data);
        if (!isset($existing_map[$candidate])) {
            return $candidate;
        }
        $seq++;
    }

    return generateSubjectIdString($method, $seq, $site_data);
}

/**
 * Fix any duplicate subject codes in a study by reassigning duplicates to the next available unique code
 */
function fixDuplicateSubjectCodes($pdo, $study_id) {
    if (!$study_id) return;
    
    // Find all duplicate codes
    $stmt = $pdo->prepare("
        SELECT subject_code, COUNT(*) as cnt 
        FROM subjects 
        WHERE study_id = ? 
        GROUP BY subject_code 
        HAVING COUNT(*) > 1
    ");
    $stmt->execute([$study_id]);
    $dups = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($dups as $dup) {
        $code = $dup['subject_code'];
        // Fetch subjects with this code ordered by creation date / id
        $stmt_s = $pdo->prepare("SELECT id, site_name FROM subjects WHERE study_id = ? AND subject_code = ? ORDER BY id ASC");
        $stmt_s->execute([$study_id, $code]);
        $rows = $stmt_s->fetchAll(PDO::FETCH_ASSOC);

        // Keep the first row, update subsequent duplicate rows with new unique IDs
        for ($i = 1; $i < count($rows); $i++) {
            $next_code = getNextAvailableSubjectCode($pdo, $study_id, $rows[$i]['site_name']);
            if ($next_code) {
                $upd = $pdo->prepare("UPDATE subjects SET subject_code = ? WHERE id = ?");
                $upd->execute([$next_code, $rows[$i]['id']]);
            }
        }
    }
}
?>
