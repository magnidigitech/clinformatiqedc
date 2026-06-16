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
?>
