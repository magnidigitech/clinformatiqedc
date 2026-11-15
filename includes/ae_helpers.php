<?php
/**
 * Adverse Events (AE) Form Definitions, Configurable Code Lists,
 * Edit Checks, Audited Reconciliation, and Query Sync Helpers.
 */

if (!function_exists('getAeProposedCodeLists')) {
    /**
     * Configurable proposed AE code lists used when no study-specific
     * protocol-approved Option Group is configured.
     * Any use of proposed defaults is flagged for study/protocol review.
     */
    function getAeProposedCodeLists() {
        return [
            'severity' => [
                ['label' => 'Mild', 'value' => 'Mild'],
                ['label' => 'Moderate', 'value' => 'Moderate'],
                ['label' => 'Severe', 'value' => 'Severe'],
            ],
            'action_taken' => [
                ['label' => 'Dose not changed', 'value' => 'Dose not changed'],
                ['label' => 'Dose reduced', 'value' => 'Dose reduced'],
                ['label' => 'Drug interrupted', 'value' => 'Drug interrupted'],
                ['label' => 'Drug withdrawn', 'value' => 'Drug withdrawn'],
                ['label' => 'Not applicable', 'value' => 'Not applicable'],
                ['label' => 'Unknown', 'value' => 'Unknown'],
                ['label' => 'Other', 'value' => 'Other'],
            ],
            'outcome' => [
                ['label' => 'Recovered/resolved', 'value' => 'Recovered/resolved'],
                ['label' => 'Recovering/resolving', 'value' => 'Recovering/resolving'],
                ['label' => 'Not recovered/not resolved', 'value' => 'Not recovered/not resolved'],
                ['label' => 'Recovered/resolved with sequelae', 'value' => 'Recovered/resolved with sequelae'],
                ['label' => 'Fatal', 'value' => 'Fatal'],
                ['label' => 'Unknown', 'value' => 'Unknown'],
            ],
        ];
    }
}

if (!function_exists('getAeSeriousnessCriteriaDefinitions')) {
    /**
     * Six individual Yes/No seriousness criteria fields for AE records.
     */
    function getAeSeriousnessCriteriaDefinitions() {
        return [
            [
                'key' => 'seriousness_death',
                'label' => 'Resulted in death',
                'legacy_matches' => ['death', 'resulted in death'],
            ],
            [
                'key' => 'seriousness_life_threatening',
                'label' => 'Life-threatening',
                'legacy_matches' => ['life-threatening', 'life threatening'],
            ],
            [
                'key' => 'seriousness_hospitalization',
                'label' => 'Required or prolonged inpatient hospitalization',
                'legacy_matches' => ['hospitalization / prolongation of hospitalization', 'required or prolonged inpatient hospitalization', 'hospitalization'],
            ],
            [
                'key' => 'seriousness_disability',
                'label' => 'Persistent or significant disability/incapacity',
                'legacy_matches' => ['disability / incapacity', 'persistent or significant disability/incapacity', 'disability'],
            ],
            [
                'key' => 'seriousness_congenital_anomaly',
                'label' => 'Congenital anomaly/birth defect',
                'legacy_matches' => ['congenital anomaly', 'congenital anomaly/birth defect', 'birth defect'],
            ],
            [
                'key' => 'seriousness_other_medically_important',
                'label' => 'Other medically important event',
                'legacy_matches' => ['other medically important event', 'other medically important'],
            ],
        ];
    }
}

if (!function_exists('getAeCodeLists')) {
    /**
     * Loads protocol-approved Option Groups from the study configuration if defined,
     * falling back cleanly to configurable proposed options identified for study review.
     */
    function getAeCodeLists(PDO $pdo, $study_id) {
        $proposed = getAeProposedCodeLists();

        $aliases = [
            'severity' => [
                'recommended_group_name' => 'AE Severity',
                'names' => ['ae severity', 'adverse event severity', 'severity'],
            ],
            'action_taken' => [
                'recommended_group_name' => 'AE Treatment Action',
                'names' => [
                    'ae treatment action',
                    'action taken with study treatment',
                    'ae action taken',
                    'treatment action',
                ],
            ],
            'outcome' => [
                'recommended_group_name' => 'AE Outcome',
                'names' => ['ae outcome', 'adverse event outcome', 'outcome'],
            ],
        ];

        $result = [];
        $studyGroups = [];
        try {
            $stmt = $pdo->prepare("SELECT id, name FROM option_groups WHERE study_id = ? ORDER BY id ASC");
            $stmt->execute([(int)$study_id]);
            $studyGroups = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $studyGroups = [];
        }

        $choiceStmt = null;
        try {
            $choiceStmt = $pdo->prepare("SELECT label, value FROM option_choices WHERE group_id = ? ORDER BY order_index ASC, id ASC");
        } catch (Throwable $e) {
            $choiceStmt = null;
        }

        foreach ($aliases as $listKey => $meta) {
            $matchedGroup = null;
            foreach ($studyGroups as $grp) {
                $normName = strtolower(trim((string)($grp['name'] ?? '')));
                if (in_array($normName, $meta['names'], true)) {
                    $matchedGroup = $grp;
                    break;
                }
            }

            $choices = [];
            if ($matchedGroup && $choiceStmt) {
                $choiceStmt->execute([(int)$matchedGroup['id']]);
                $rows = $choiceStmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $r) {
                    $lbl = trim((string)($r['label'] ?? ''));
                    $val = trim((string)($r['value'] ?? ''));
                    if ($lbl === '' && $val === '') continue;
                    $choices[] = [
                        'label' => $lbl !== '' ? $lbl : $val,
                        'value' => $val !== '' ? $val : $lbl,
                    ];
                }
            }

            if (!empty($choices)) {
                $result[$listKey] = [
                    'options' => $choices,
                    'source' => 'study_option_group',
                    'requires_protocol_review' => false,
                    'option_group_id' => (int)$matchedGroup['id'],
                    'option_group_name' => $matchedGroup['name'],
                    'recommended_group_name' => $meta['recommended_group_name'],
                ];
            } else {
                $result[$listKey] = [
                    'options' => $proposed[$listKey],
                    'source' => 'proposed_default',
                    'requires_protocol_review' => true,
                    'option_group_id' => null,
                    'option_group_name' => null,
                    'recommended_group_name' => $meta['recommended_group_name'],
                ];
            }
        }

        return $result;
    }
}

if (!function_exists('getAeFieldDefinitions')) {
    /**
     * Canonical AE record schema reusing validation_rules condition format
     * compatible with isFieldApplicable() in includes/functions.php.
     */
    function getAeFieldDefinitions(array $codeLists) {
        $yesNo = [
            ['label' => 'Yes', 'value' => 'Yes'],
            ['label' => 'No', 'value' => 'No'],
        ];

        $defs = [
            [
                'variable_name' => 'ae_experienced',
                'label' => 'Were any adverse events experienced?',
                'type' => 'radio',
                'options' => $yesNo,
                'is_required' => true,
                'validation_rules' => '{}',
            ],
            [
                'variable_name' => 'ae_term',
                'label' => 'Adverse event term',
                'type' => 'text',
                'is_required' => true,
                'validation_rules' => json_encode([
                    'condition' => ['field' => 'ae_experienced', 'operator' => '!=', 'value' => 'No'],
                ]),
            ],
            [
                'variable_name' => 'start_date',
                'label' => 'Start date',
                'type' => 'date',
                'is_required' => true,
                'validation_rules' => json_encode([
                    'condition' => ['field' => 'ae_experienced', 'operator' => '!=', 'value' => 'No'],
                ]),
            ],
            [
                'variable_name' => 'ongoing',
                'label' => 'Is the event ongoing?',
                'type' => 'radio',
                'options' => $yesNo,
                'is_required' => true,
                'validation_rules' => json_encode([
                    'condition' => ['field' => 'ae_experienced', 'operator' => '!=', 'value' => 'No'],
                ]),
            ],
            [
                'variable_name' => 'end_date',
                'label' => 'End date',
                'type' => 'date',
                'is_required' => true,
                'validation_rules' => json_encode([
                    'condition' => ['field' => 'ongoing', 'operator' => '==', 'value' => 'No'],
                ]),
            ],
            [
                'variable_name' => 'severity',
                'label' => 'Severity',
                'type' => 'radio',
                'options' => $codeLists['severity']['options'] ?? getAeProposedCodeLists()['severity'],
                'is_required' => true,
                'validation_rules' => json_encode([
                    'condition' => ['field' => 'ae_experienced', 'operator' => '!=', 'value' => 'No'],
                ]),
            ],
            [
                'variable_name' => 'seriousness',
                'label' => 'Was the event serious?',
                'type' => 'radio',
                'options' => $yesNo,
                'is_required' => true,
                'validation_rules' => json_encode([
                    'condition' => ['field' => 'ae_experienced', 'operator' => '!=', 'value' => 'No'],
                ]),
            ],
        ];

        foreach (getAeSeriousnessCriteriaDefinitions() as $crit) {
            $defs[] = [
                'variable_name' => $crit['key'],
                'label' => $crit['label'],
                'type' => 'radio',
                'options' => $yesNo,
                'is_required' => false,
                'is_seriousness_criterion' => true,
                'validation_rules' => json_encode([
                    'condition' => ['field' => 'seriousness', 'operator' => '==', 'value' => 'Yes'],
                ]),
            ];
        }

        $defs[] = [
            'variable_name' => 'causality',
            'label' => 'Related to study treatment?',
            'type' => 'radio',
            'options' => $yesNo,
            'is_required' => true,
            'validation_rules' => json_encode([
                'condition' => ['field' => 'ae_experienced', 'operator' => '!=', 'value' => 'No'],
            ]),
        ];
        $defs[] = [
            'variable_name' => 'action_taken',
            'label' => 'Action taken with study treatment',
            'type' => 'select',
            'options' => $codeLists['action_taken']['options'] ?? getAeProposedCodeLists()['action_taken'],
            'is_required' => true,
            'validation_rules' => json_encode([
                'condition' => ['field' => 'causality', 'operator' => '==', 'value' => 'Yes'],
            ]),
        ];
        $defs[] = [
            'variable_name' => 'other_action_specify',
            'label' => 'Other treatment action—specify',
            'type' => 'text',
            'is_required' => true,
            'validation_rules' => json_encode([
                'condition' => ['field' => 'action_taken', 'operator' => '==', 'value' => 'Other'],
            ]),
        ];
        $defs[] = [
            'variable_name' => 'outcome',
            'label' => 'Outcome',
            'type' => 'select',
            'options' => $codeLists['outcome']['options'] ?? getAeProposedCodeLists()['outcome'],
            'is_required' => true,
            'validation_rules' => json_encode([
                'condition' => ['field' => 'causality', 'operator' => '==', 'value' => 'Yes'],
            ]),
        ];

        return $defs;
    }
}

if (!function_exists('parsePartialClinicalDate')) {
    /**
     * Parses full (YYYY-MM-DD) or partial (YYYY-MM, YYYY, UNK tokens) dates
     * without inventing missing month/day components.
     */
    function parsePartialClinicalDate($val) {
        $s = trim((string)($val ?? ''));
        if ($s === '') return null;

        // Accept YYYY-MM-DD or YYYY-MM or YYYY (also handle UN/UNK tokens cleanly)
        if (preg_match('/^(\d{4})(?:-(\d{2}|UN|UNK))?(?:-(\d{2}|UN|UNK))?$/i', $s, $m)) {
            $year = (int)$m[1];
            $month = (isset($m[2]) && ctype_digit($m[2])) ? (int)$m[2] : null;
            $day = (isset($m[3]) && ctype_digit($m[3])) ? (int)$m[3] : null;
            if ($month !== null && ($month < 1 || $month > 12)) return null;
            if ($day !== null && ($day < 1 || $day > 31)) return null;
            if ($month !== null && $day !== null && !checkdate($month, $day, $year)) return null;
            return ['year' => $year, 'month' => $month, 'day' => $day, 'raw' => $s];
        }

        return null;
    }
}

if (!function_exists('compareClinicalDates')) {
    /**
     * Compares two full or partial clinical dates without inventing missing components.
     * Returns -1 if $end strictly precedes $start, 1 if $end strictly follows $start,
     * 0 if identical complete dates, or null if indeterminate.
     */
    function compareClinicalDates($start, $end) {
        $ps = parsePartialClinicalDate($start);
        $pe = parsePartialClinicalDate($end);
        if (!$ps || !$pe) return null;

        if ($pe['year'] < $ps['year']) return -1;
        if ($pe['year'] > $ps['year']) return 1;

        if ($pe['month'] === null || $ps['month'] === null) return null;
        if ($pe['month'] < $ps['month']) return -1;
        if ($pe['month'] > $ps['month']) return 1;

        if ($pe['day'] === null || $ps['day'] === null) return null;
        if ($pe['day'] < $ps['day']) return -1;
        if ($pe['day'] > $ps['day']) return 1;

        return 0;
    }
}

if (!function_exists('isClinicalDateInFuture')) {
    /**
     * Checks whether a full or partial date is strictly after today ($todayIst)
     * without inventing missing components.
     */
    function isClinicalDateInFuture($dateStr, $todayIst) {
        $p = parsePartialClinicalDate($dateStr);
        $t = parsePartialClinicalDate($todayIst);
        if (!$p || !$t) return false;

        if ($p['year'] > $t['year']) return true;
        if ($p['year'] < $t['year']) return false;

        if ($p['month'] === null) return false;
        if ($p['month'] > $t['month']) return true;
        if ($p['month'] < $t['month']) return false;

        if ($p['day'] === null) return false;
        return $p['day'] > $t['day'];
    }
}

if (!function_exists('classifyAeOutcome')) {
    /**
     * Classifies AE Outcome strings cleanly into resolved / unresolved / fatal / unknown / other.
     */
    function classifyAeOutcome($outcome) {
        $norm = strtolower(preg_replace('/\s*\/\s*/', '/', trim((string)$outcome)));
        if ($norm === '') return 'missing';
        if ($norm === 'recovered/resolved' || $norm === 'recovered/resolved with sequelae' || $norm === 'recovered with sequelae') {
            return 'resolved';
        }
        if ($norm === 'recovering/resolving' || $norm === 'not recovered/not resolved') {
            return 'unresolved';
        }
        if ($norm === 'fatal') {
            return 'fatal';
        }
        if ($norm === 'unknown') {
            return 'unknown';
        }
        return 'other';
    }
}

if (!function_exists('normalizeAeRecordData')) {
    /**
     * Normalizes an AE record payload cleanly without inventing missing dates or 'No' answers.
     * Maps legacy seriousness_criteria arrays only if individual criteria keys are absent.
     */
    function normalizeAeRecordData(array $raw) {
        $criteriaDefs = getAeSeriousnessCriteriaDefinitions();
        $out = [];

        $scalarKeys = [
            'ae_experienced',
            'ae_term', 'start_date', 'ongoing', 'end_date',
            'severity', 'seriousness', 'causality',
            'action_taken', 'other_action_specify', 'outcome',
        ];
        foreach ($scalarKeys as $k) {
            $out[$k] = trim((string)($raw[$k] ?? ''));
        }

        if ($out['ae_experienced'] === '') {
            $out['ae_experienced'] = ($out['ae_term'] === 'No adverse events experienced') ? 'No' : 'Yes';
        }

        if ($out['ae_experienced'] === 'No') {
            $out['ae_term'] = $out['ae_term'] ?: 'No adverse events experienced';
            $out['ongoing'] = 'No';
            $out['seriousness'] = 'No';
            $out['causality'] = 'No';
            $out['action_taken'] = '';
            $out['other_action_specify'] = '';
            $out['outcome'] = '';
        }

        // Preserve any legacy criteria mapping if individual keys were never saved yet
        $hasIndividual = false;
        foreach ($criteriaDefs as $c) {
            $k = $c['key'];
            $v = trim((string)($raw[$k] ?? ''));
            if ($v !== '') $hasIndividual = true;
            $out[$k] = $v;
        }

        if (!$hasIndividual && !empty($raw['seriousness_criteria'])) {
            $legacyList = is_array($raw['seriousness_criteria'])
                ? $raw['seriousness_criteria']
                : explode(',', (string)$raw['seriousness_criteria']);
            $legacyNorm = array_map(fn($x) => strtolower(trim((string)$x)), $legacyList);
            foreach ($criteriaDefs as $c) {
                foreach ($c['legacy_matches'] as $m) {
                    if (in_array($m, $legacyNorm, true)) {
                        $out[$c['key']] = 'Yes';
                        break;
                    }
                }
            }
        }

        return $out;
    }
}

if (!function_exists('getAeDiscrepancyRuleCodes')) {
    /**
     * Canonical list of record-level discrepancy rule codes managed as system edit checks.
     */
    function getAeDiscrepancyRuleCodes() {
        return [
            'AE_FUTURE_START_DATE',
            'AE_FUTURE_END_DATE',
            'AE_END_BEFORE_START',
            'AE_ONGOING_WITH_END_DATE',
            'AE_SERIOUS_NO_CRITERIA_YES',
            'AE_NON_SERIOUS_RETAINED_CRITERIA',
            'AE_OTHER_ACTION_RETAINED',
            'AE_RESOLVED_CONFLICT',
            'AE_UNRESOLVED_WITH_END_DATE',
            'AE_FATAL_CONFLICT',
        ];
    }
}

if (!function_exists('evaluateAeRecordChecks')) {
    /**
     * Evaluates all completeness requirements and edit checks for an AE record.
     * Reuses getAeFieldDefinitions() and isFieldApplicable() rule structure.
     * Incomplete drafts remain saveable ($requireCompleteness = false).
     */
    function evaluateAeRecordChecks(array $rawData, array $codeLists, $todayIst = null, $requireCompleteness = true) {
        if ($todayIst === null) {
            $todayIst = (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
        }

        $d = normalizeAeRecordData($rawData);

        // If 'Were any adverse events experienced?' is No, no adverse events occurred for this record/subject
        if (($d['ae_experienced'] ?? '') === 'No') {
            return [
                'valid' => true,
                'completeness_errors' => [],
                'discrepancy_checks' => [],
                'all_errors' => [],
                'field_errors' => [],
            ];
        }

        $fieldDefs = getAeFieldDefinitions($codeLists);
        $criteriaDefs = getAeSeriousnessCriteriaDefinitions();

        $fieldsByVar = [];
        foreach ($fieldDefs as $fd) {
            $fieldsByVar[$fd['variable_name']] = $fd;
        }

        $completenessErrors = [];
        $discrepancyChecks = [];

        $addCompleteness = function($field, $ruleCode, $msg) use (&$completenessErrors) {
            $completenessErrors[] = ['field' => $field, 'rule_code' => $ruleCode, 'message' => $msg];
        };
        $addDiscrepancy = function($field, $ruleCode, $msg) use (&$discrepancyChecks) {
            $discrepancyChecks[] = ['field' => $field, 'rule_code' => $ruleCode, 'message' => $msg];
        };

        // 1. Completeness checks driven by field definitions & isFieldApplicable()
        foreach ($fieldDefs as $fd) {
            $var = $fd['variable_name'];
            if (empty($fd['is_required'])) {
                continue;
            }
            if (!isFieldApplicable($fd, $d, [], $fieldsByVar)) {
                continue;
            }
            if ($d[$var] === '') {
                $ruleMap = [
                    'ae_experienced' => 'AE_EXPERIENCED_REQUIRED',
                    'ae_term' => 'AE_TERM_REQUIRED',
                    'start_date' => 'AE_START_DATE_REQUIRED',
                    'ongoing' => 'AE_ONGOING_REQUIRED',
                    'end_date' => 'AE_END_DATE_REQUIRED',
                    'severity' => 'AE_SEVERITY_REQUIRED',
                    'seriousness' => 'AE_SERIOUSNESS_REQUIRED',
                    'causality' => 'AE_CAUSALITY_REQUIRED',
                    'action_taken' => 'AE_ACTION_TAKEN_REQUIRED',
                    'other_action_specify' => 'AE_OTHER_ACTION_REQUIRED',
                    'outcome' => 'AE_OUTCOME_REQUIRED',
                ];
                $ruleCode = $ruleMap[$var] ?? (str_starts_with($var, 'seriousness_') ? 'AE_SERIOUSNESS_CRITERION_REQUIRED' : 'AE_FIELD_REQUIRED');
                $msg = $fd['label'] . " is required to mark complete.";
                if ($var === 'end_date') {
                    $msg = "End date is required when 'Is the event ongoing?' is No.";
                } elseif (str_starts_with($var, 'seriousness_')) {
                    $msg = "Please answer Yes or No for '" . $fd['label'] . "' when the event is serious.";
                } elseif ($var === 'other_action_specify') {
                    $msg = "Other treatment action—specify is required when Action taken is Other.";
                }
                $addCompleteness($var, $ruleCode, $msg);
            }
        }

        // Code-list membership checks (when a value is provided)
        $validCodeSets = [];
        foreach (['severity', 'action_taken', 'outcome'] as $listKey) {
            $rawOpts = $codeLists[$listKey]['options'] ?? ($codeLists[$listKey] ?? []);
            $validCodeSets[$listKey] = array_map(
                fn($opt) => is_array($opt) ? (string)($opt['value'] ?? '') : (string)$opt,
                $rawOpts
            );
        }
        if ($d['severity'] !== '' && !in_array($d['severity'], $validCodeSets['severity'], true)) {
            $addDiscrepancy('severity', 'AE_INVALID_SEVERITY', "Severity must be a configured option.");
        }
        if ($d['action_taken'] !== '' && ($d['causality'] ?? '') === 'Yes' && !in_array($d['action_taken'], $validCodeSets['action_taken'], true)) {
            $addDiscrepancy('action_taken', 'AE_INVALID_ACTION_TAKEN', "Action taken with study treatment must be a configured option.");
        }
        if ($d['outcome'] !== '' && ($d['causality'] ?? '') === 'Yes' && !in_array($d['outcome'], $validCodeSets['outcome'], true)) {
            $addDiscrepancy('outcome', 'AE_INVALID_OUTCOME', "Outcome must be a configured option.");
        }

        // 2. Date validation & partial-date safe chronological check
        if ($d['start_date'] !== '' && isClinicalDateInFuture($d['start_date'], $todayIst)) {
            $addDiscrepancy('start_date', 'AE_FUTURE_START_DATE', "Start date cannot be in the future.");
        }
        if ($d['end_date'] !== '' && isClinicalDateInFuture($d['end_date'], $todayIst)) {
            $addDiscrepancy('end_date', 'AE_FUTURE_END_DATE', "End date cannot be in the future.");
        }
        if ($d['start_date'] !== '' && $d['end_date'] !== '') {
            $cmp = compareClinicalDates($d['start_date'], $d['end_date']);
            if ($cmp === -1) {
                $addDiscrepancy('end_date', 'AE_END_BEFORE_START', "End date cannot precede Start date.");
            }
        }

        // 3. Ongoing = Yes conflicts with a recorded end date
        if ($d['ongoing'] === 'Yes' && $d['end_date'] !== '') {
            $addDiscrepancy(
                'end_date',
                'AE_ONGOING_WITH_END_DATE',
                "Conflict: End date ({$d['end_date']}) is recorded while 'Is the event ongoing?' is Yes. Reconcile before completing."
            );
        }

        // 4. Seriousness criteria consistency checks
        $yesCriteria = [];
        $unansweredCriteria = 0;
        foreach ($criteriaDefs as $c) {
            $val = $d[$c['key']];
            if ($val === 'Yes') {
                $yesCriteria[] = $c['label'];
            } elseif ($val === '') {
                $unansweredCriteria++;
            }
        }

        if ($d['seriousness'] === 'Yes') {
            if (empty($yesCriteria) && ($unansweredCriteria === 0 || $requireCompleteness)) {
                $addDiscrepancy(
                    'seriousness',
                    'AE_SERIOUS_NO_CRITERIA_YES',
                    "When 'Was the event serious?' is Yes, at least one seriousness criterion must be Yes."
                );
            }
        } elseif ($d['seriousness'] === 'No' && !empty($yesCriteria)) {
            $addDiscrepancy(
                'seriousness',
                'AE_NON_SERIOUS_RETAINED_CRITERIA',
                "Conflict: 'Was the event serious?' is No, but seriousness criteria are retained as Yes (" . implode('; ', $yesCriteria) . "). Reconcile before completing."
            );
        }

        // 5. Retained hidden 'Other treatment action—specify' when action_taken != Other (only when causality is Yes)
        if (($d['causality'] ?? '') === 'Yes' && $d['action_taken'] !== '' && strcasecmp($d['action_taken'], 'Other') !== 0 && $d['other_action_specify'] !== '') {
            $addDiscrepancy(
                'other_action_specify',
                'AE_OTHER_ACTION_RETAINED',
                "Conflict: 'Other treatment action—specify' retains text ('{$d['other_action_specify']}') while Action taken is '{$d['action_taken']}'. Reconcile before completing."
            );
        }

        // 6. Outcome cross-checks (only when causality is Yes)
        if (($d['causality'] ?? '') === 'Yes') {
            $outcomeClass = classifyAeOutcome($d['outcome']);
            if ($outcomeClass === 'resolved') {
                if ($d['ongoing'] === 'Yes') {
                    $addDiscrepancy(
                        'outcome',
                        'AE_RESOLVED_CONFLICT',
                        "Conflict: Outcome '{$d['outcome']}' conflicts with 'Is the event ongoing?' = Yes."
                    );
                } elseif ($d['end_date'] === '' && ($d['ongoing'] === 'No' || $requireCompleteness)) {
                    $addDiscrepancy(
                        'end_date',
                        'AE_RESOLVED_CONFLICT',
                        "End date is required when Outcome is '{$d['outcome']}'."
                    );
                }
            } elseif ($outcomeClass === 'unresolved' && $d['end_date'] !== '') {
                $addDiscrepancy(
                    'end_date',
                    'AE_UNRESOLVED_WITH_END_DATE',
                    "Conflict: Outcome '{$d['outcome']}' conflicts with recorded End date ({$d['end_date']})."
                );
            } elseif ($outcomeClass === 'fatal') {
                if ($d['seriousness'] !== 'Yes') {
                    $addDiscrepancy(
                        'seriousness',
                        'AE_FATAL_CONFLICT',
                        "Outcome 'Fatal' requires 'Was the event serious?' = Yes and 'Resulted in death' = Yes."
                    );
                } elseif ($d['seriousness_death'] !== 'Yes') {
                    $addDiscrepancy(
                        'seriousness_death',
                        'AE_FATAL_CONFLICT',
                        "Outcome 'Fatal' requires 'Resulted in death' = Yes."
                    );
                }
            }
        }

        $activeErrors = $requireCompleteness
            ? array_merge($discrepancyChecks, $completenessErrors)
            : $discrepancyChecks;

        $fieldErrors = [];
        foreach ($activeErrors as $err) {
            if (!isset($fieldErrors[$err['field']])) {
                $fieldErrors[$err['field']] = $err['message'];
            }
        }

        $discrepancyFieldErrors = [];
        foreach ($discrepancyChecks as $err) {
            if (!isset($discrepancyFieldErrors[$err['field']])) {
                $discrepancyFieldErrors[$err['field']] = $err['message'];
            }
        }

        return [
            'valid' => empty($activeErrors),
            'normalized_data' => $d,
            'completeness_errors' => $completenessErrors,
            'discrepancy_checks' => $discrepancyChecks,
            'all_errors' => $activeErrors,
            'field_errors' => $fieldErrors,
            'discrepancy_field_errors' => $discrepancyFieldErrors,
        ];
    }
}

if (!function_exists('getAeReconciliationItems')) {
    /**
     * Detects dependent values that conflict with a new controlling answer and
     * must be reconciled via explicit audited action rather than silent erasure.
     */
    function getAeReconciliationItems(array $rawData) {
        $d = normalizeAeRecordData($rawData);
        $items = [];

        if ($d['ongoing'] === 'Yes' && $d['end_date'] !== '') {
            $items[] = [
                'control_field' => 'ongoing',
                'dependent_fields' => ['end_date'],
                'saved_summary' => "End date = {$d['end_date']}",
                'message' => "'Is the event ongoing?' is Yes, but End date ({$d['end_date']}) is present.",
            ];
        }

        if ($d['seriousness'] === 'No') {
            $retained = [];
            $depKeys = [];
            foreach (getAeSeriousnessCriteriaDefinitions() as $c) {
                if ($d[$c['key']] !== '') {
                    $retained[] = $c['label'] . ': ' . $d[$c['key']];
                    $depKeys[] = $c['key'];
                }
            }
            if (!empty($retained)) {
                $items[] = [
                    'control_field' => 'seriousness',
                    'dependent_fields' => $depKeys,
                    'saved_summary' => implode('; ', $retained),
                    'message' => "'Was the event serious?' is No, while previously answered seriousness criteria remain (" . implode('; ', $retained) . ").",
                ];
            }
        }

        if ($d['action_taken'] !== '' && strcasecmp($d['action_taken'], 'Other') !== 0 && $d['other_action_specify'] !== '') {
            $items[] = [
                'control_field' => 'action_taken',
                'dependent_fields' => ['other_action_specify'],
                'saved_summary' => "Other treatment action—specify = {$d['other_action_specify']}",
                'message' => "Action taken is '{$d['action_taken']}', while 'Other treatment action—specify' retains '{$d['other_action_specify']}'.",
            ];
        }

        return $items;
    }
}

if (!function_exists('getSubjectAeState')) {
    /**
     * Loads subject-level AE gate state ("Were any adverse events experienced?"),
     * active record counts, lock flags, and subject-level queries/audit entries.
     */
    function getSubjectAeState(PDO $pdo, $study_id, $subject_id) {
        $stmt = $pdo->prepare("
            SELECT * FROM subject_common_form_status
            WHERE study_id = ? AND subject_id = ? AND form_type = 'AE'
            LIMIT 1
        ");
        $stmt->execute([(int)$study_id, (int)$subject_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $stmtActive = $pdo->prepare("
            SELECT id, record_number, status, pi_signed_off, monitor_reviewed, manager_reviewed, sdr_submitted, data_json
            FROM subject_common_records
            WHERE study_id = ? AND subject_id = ? AND form_type = 'AE' AND is_voided = FALSE
            ORDER BY seq_number ASC, id ASC
        ");
        $stmtActive->execute([(int)$study_id, (int)$subject_id]);
        $activeRecs = $stmtActive->fetchAll(PDO::FETCH_ASSOC);
        $activeCount = count($activeRecs);

        $hasPiLockedRecord = false;
        $hasDraftRecord = false;
        foreach ($activeRecs as $ar) {
            if (toBool($ar['pi_signed_off'] ?? false)) $hasPiLockedRecord = true;
            if (($ar['status'] ?? 'draft') !== 'complete') $hasDraftRecord = true;
        }

        $anyExperienced = $row ? ($row['any_experienced'] ?? null) : null;
        if ($anyExperienced === '') $anyExperienced = null;

        // If a legacy subject has saved AE records before this gate existed, surface effective 'Yes'
        $inferredFromLegacy = false;
        if ($anyExperienced === null && $activeCount > 0) {
            $anyExperienced = 'Yes';
            $inferredFromLegacy = true;
        }

        // Subject-level AE queries (record_id IS NULL, form_type = 'AE')
        $stmtSq = $pdo->prepare("
            SELECT q.*, COALESCE(u.name, u.username) as created_by_name
            FROM common_form_queries q
            LEFT JOIN users u ON q.created_by = u.id
            WHERE q.study_id = ? AND q.subject_id = ? AND q.record_id IS NULL AND q.form_type = 'AE'
            ORDER BY q.created_at DESC, q.id DESC
        ");
        $stmtSq->execute([(int)$study_id, (int)$subject_id]);
        $subjectQueries = $stmtSq->fetchAll(PDO::FETCH_ASSOC);

        $openSubjectQueries = 0;
        foreach ($subjectQueries as &$sq) {
            if (($sq['status'] ?? '') !== 'closed') $openSubjectQueries++;
            $stmtH = $pdo->prepare("
                SELECT h.*, COALESCE(u.name, u.username) as user_name
                FROM common_form_query_history h
                LEFT JOIN users u ON h.created_by = u.id
                WHERE h.query_id = ?
                ORDER BY h.created_at ASC, h.id ASC
            ");
            $stmtH->execute([(int)$sq['id']]);
            $sq['history'] = $stmtH->fetchAll(PDO::FETCH_ASSOC);
        }
        unset($sq);

        // Subject-level AE audit log entries
        $stmtAud = $pdo->prepare("
            SELECT a.*, COALESCE(u.name, u.username) as user_name
            FROM common_form_audit_log a
            LEFT JOIN users u ON a.action_by = u.id
            WHERE a.study_id = ? AND a.subject_id = ? AND a.record_id IS NULL AND a.form_type = 'AE'
            ORDER BY a.action_at DESC, a.id DESC
        ");
        $stmtAud->execute([(int)$study_id, (int)$subject_id]);
        $subjectAudit = $stmtAud->fetchAll(PDO::FETCH_ASSOC);

        $conflict = ($anyExperienced === 'No' && $activeCount > 0);
        $gatePiSigned = ($row && toBool($row['pi_signed_off'] ?? false));

        return [
            'id' => $row ? (int)$row['id'] : null,
            'any_experienced' => $anyExperienced,
            'saved_any_experienced' => $row ? ($row['any_experienced'] ?? null) : null,
            'inferred_from_legacy' => $inferredFromLegacy,
            'status' => $row ? ($row['status'] ?? 'draft') : 'draft',
            'sdr_status' => $row ? ($row['sdr_status'] ?? 'pending') : 'pending',
            'sdr_submitted' => $row ? toBool($row['sdr_submitted'] ?? false) : false,
            'monitor_reviewed' => $row ? toBool($row['monitor_reviewed'] ?? false) : false,
            'manager_reviewed' => $row ? toBool($row['manager_reviewed'] ?? false) : false,
            'pi_signed_off' => $gatePiSigned,
            'has_pi_locked_record' => $hasPiLockedRecord,
            'revision' => $row ? (int)($row['revision'] ?? 1) : 1,
            'active_record_count' => $activeCount,
            'has_draft_record' => $hasDraftRecord,
            'conflict_occurrence_no_with_records' => $conflict,
            'subject_queries' => $subjectQueries,
            'open_subject_queries' => $openSubjectQueries,
            'subject_audit' => $subjectAudit,
        ];
    }
}

if (!function_exists('evaluateSubjectAeChecks')) {
    /**
     * Evaluates subject-level AE occurrence checks (#1 and #13).
     */
    function evaluateSubjectAeChecks($anyExperienced, $activeCount, array $activeRecords, array $codeLists, $requireCompleteness = true) {
        $errors = [];
        $todayIst = (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');

        if ($anyExperienced === 'No' && $activeCount > 0) {
            $errors[] = [
                'field' => 'ae_experienced',
                'rule_code' => 'AE_OCCURRENCE_CONFLICT',
                'message' => "Conflict: 'Were any adverse events experienced?' is No, but {$activeCount} active AE record(s) exist. Set to Yes or void existing AE record(s) with an audit reason.",
            ];
        }

        if ($requireCompleteness) {
            if ($anyExperienced === null || $anyExperienced === '') {
                $errors[] = [
                    'field' => 'ae_experienced',
                    'rule_code' => 'AE_OCCURRENCE_REQUIRED',
                    'message' => "Please answer 'Were any adverse events experienced?' (Yes or No).",
                ];
            } elseif ($anyExperienced === 'Yes') {
                if ($activeCount === 0) {
                    $errors[] = [
                        'field' => 'ae_experienced',
                        'rule_code' => 'AE_YES_NO_RECORDS',
                        'message' => "At least one AE record is required when 'Were any adverse events experienced?' is Yes.",
                    ];
                } else {
                    foreach ($activeRecords as $rec) {
                        $recNum = $rec['record_number'] ?? ('#' . ($rec['id'] ?? ''));
                        if (($rec['status'] ?? 'draft') !== 'complete') {
                            $errors[] = [
                                'field' => 'ae_experienced',
                                'rule_code' => 'AE_RECORD_INCOMPLETE',
                                'message' => "AE record {$recNum} is still a Draft and must be marked complete.",
                            ];
                            continue;
                        }
                        $recData = is_string($rec['data_json'] ?? null)
                            ? (json_decode($rec['data_json'], true) ?: [])
                            : (array)($rec['data'] ?? []);
                        $chk = evaluateAeRecordChecks($recData, $codeLists, $todayIst, true);
                        if (!$chk['valid']) {
                            $firstErr = $chk['all_errors'][0]['message'] ?? 'Validation failed';
                            $errors[] = [
                                'field' => 'ae_experienced',
                                'rule_code' => 'AE_RECORD_INVALID',
                                'message' => "AE record {$recNum} has unresolved edit check error(s): {$firstErr}",
                            ];
                        }
                    }
                }
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }
}

if (!function_exists('upsertSystemCommonQuery')) {
    /**
     * Ensures at most one open/answered system edit-check query exists for a given
     * (subject_id, record_id, rule_code), avoiding duplicates cleanly.
     */
    function upsertSystemCommonQuery(PDO $pdo, $study_id, $subject_id, $record_id, $form_type, $field_name, $rule_code, $query_text, $user_id) {
        $params = [(int)$study_id, (int)$subject_id, $rule_code];
        if ($record_id !== null && $record_id > 0) {
            $sql = "SELECT id, status, query_text FROM common_form_queries WHERE study_id = ? AND subject_id = ? AND record_id = ? AND rule_code = ? AND status != 'closed' ORDER BY id ASC LIMIT 1";
            $params = [(int)$study_id, (int)$subject_id, (int)$record_id, $rule_code];
        } else {
            $sql = "SELECT id, status, query_text FROM common_form_queries WHERE study_id = ? AND subject_id = ? AND record_id IS NULL AND form_type = ? AND rule_code = ? AND status != 'closed' ORDER BY id ASC LIMIT 1";
            $params = [(int)$study_id, (int)$subject_id, $form_type, $rule_code];
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            if ($existing['query_text'] !== $query_text) {
                $upd = $pdo->prepare("UPDATE common_form_queries SET query_text = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $upd->execute([$query_text, (int)$existing['id']]);
            }
            return (int)$existing['id'];
        }

        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sysBool = ($driver === 'pgsql') ? 'TRUE' : '1';
        $returningSql = ($driver === 'pgsql') ? ' RETURNING id' : '';
        $ins = $pdo->prepare("
            INSERT INTO common_form_queries (study_id, subject_id, record_id, form_type, field_name, rule_code, is_system_query, query_text, status, created_by, raised_by_role)
            VALUES (?, ?, ?, ?, ?, ?, $sysBool, ?, 'open', ?, 'System Edit Check')$returningSql
        ");
        $ins->execute([
            (int)$study_id,
            (int)$subject_id,
            ($record_id !== null && $record_id > 0) ? (int)$record_id : null,
            $form_type,
            $field_name ?: null,
            $rule_code,
            $query_text,
            (int)$user_id,
        ]);
        $qid = ($driver === 'pgsql') ? (int)$ins->fetchColumn() : (int)$pdo->lastInsertId();

        $qh = $pdo->prepare("
            INSERT INTO common_form_query_history (query_id, action_type, remark, status_from, status_to, created_by)
            VALUES (?, 'raised', ?, NULL, 'open', ?)
        ");
        $qh->execute([$qid, $query_text, (int)$user_id]);

        $aud = $pdo->prepare("
            INSERT INTO common_form_audit_log (study_id, subject_id, record_id, form_type, field_name, old_value, new_value, reason_for_change, change_type, action_by)
            VALUES (?, ?, ?, ?, ?, NULL, NULL, ?, 'query_raised', ?)
        ");
        $aud->execute([
            (int)$study_id,
            (int)$subject_id,
            ($record_id !== null && $record_id > 0) ? (int)$record_id : null,
            $form_type,
            $field_name ?: 'RECORD_QUERY',
            "System Edit Check Query Raised ({$rule_code} / #{$qid}): {$query_text}",
            (int)$user_id,
        ]);

        return $qid;
    }
}

if (!function_exists('closeResolvedSystemCommonQueries')) {
    /**
     * Auto-closes open/answered system edit-check queries once the underlying rule passes.
     */
    function closeResolvedSystemCommonQueries(PDO $pdo, $study_id, $subject_id, $record_id, $form_type, $rule_code, $user_id, $remark = 'Resolved automatically upon data update') {
        if ($record_id !== null && $record_id > 0) {
            $stmt = $pdo->prepare("
                SELECT id, status, field_name FROM common_form_queries
                WHERE study_id = ? AND subject_id = ? AND record_id = ? AND rule_code = ? AND is_system_query = TRUE AND status != 'closed'
            ");
            $stmt->execute([(int)$study_id, (int)$subject_id, (int)$record_id, $rule_code]);
        } else {
            $stmt = $pdo->prepare("
                SELECT id, status, field_name FROM common_form_queries
                WHERE study_id = ? AND subject_id = ? AND record_id IS NULL AND form_type = ? AND rule_code = ? AND is_system_query = TRUE AND status != 'closed'
            ");
            $stmt->execute([(int)$study_id, (int)$subject_id, $form_type, $rule_code]);
        }
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) return 0;

        $closed = 0;
        $upd = $pdo->prepare("UPDATE common_form_queries SET status = 'closed', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $qh = $pdo->prepare("
            INSERT INTO common_form_query_history (query_id, action_type, remark, status_from, status_to, created_by)
            VALUES (?, 'closed', ?, ?, 'closed', ?)
        ");
        $aud = $pdo->prepare("
            INSERT INTO common_form_audit_log (study_id, subject_id, record_id, form_type, field_name, old_value, new_value, reason_for_change, change_type, action_by)
            VALUES (?, ?, ?, ?, ?, NULL, NULL, ?, 'query_closed', ?)
        ");

        foreach ($rows as $row) {
            $qid = (int)$row['id'];
            $upd->execute([$qid]);
            $qh->execute([$qid, $remark, $row['status'], (int)$user_id]);
            $aud->execute([
                (int)$study_id,
                (int)$subject_id,
                ($record_id !== null && $record_id > 0) ? (int)$record_id : null,
                $form_type,
                $row['field_name'] ?: 'RECORD_QUERY',
                "System Edit Check Query Auto-Closed ({$rule_code} / #{$qid}): {$remark}",
                (int)$user_id,
            ]);
            $closed++;
        }

        return $closed;
    }
}

if (!function_exists('syncAeRecordEditCheckQueries')) {
    /**
     * Synchronizes all record-level AE system edit-check queries with saved record data.
     */
    function syncAeRecordEditCheckQueries(PDO $pdo, $study_id, $subject_id, $record_id, array $data, $user_id, ?array $codeLists = null, $todayIst = null) {
        if ($codeLists === null) {
            $codeLists = getAeCodeLists($pdo, $study_id);
        }
        $eval = evaluateAeRecordChecks($data, $codeLists, $todayIst, false);
        $activeByRule = [];
        foreach ($eval['discrepancy_checks'] as $disc) {
            if (!isset($activeByRule[$disc['rule_code']])) {
                $activeByRule[$disc['rule_code']] = $disc;
            }
        }

        foreach (getAeDiscrepancyRuleCodes() as $ruleCode) {
            if (isset($activeByRule[$ruleCode])) {
                $disc = $activeByRule[$ruleCode];
                upsertSystemCommonQuery(
                    $pdo,
                    $study_id,
                    $subject_id,
                    $record_id,
                    'AE',
                    $disc['field'],
                    $ruleCode,
                    $disc['message'],
                    $user_id
                );
            } else {
                closeResolvedSystemCommonQueries(
                    $pdo,
                    $study_id,
                    $subject_id,
                    $record_id,
                    'AE',
                    $ruleCode,
                    $user_id
                );
            }
        }

        return $eval;
    }
}

if (!function_exists('syncSubjectAeQueries')) {
    /**
     * Synchronizes subject-level AE edit-check queries (e.g. AE_OCCURRENCE_CONFLICT).
     */
    function syncSubjectAeQueries(PDO $pdo, $study_id, $subject_id, $user_id) {
        $stmtRow = $pdo->prepare("
            SELECT any_experienced FROM subject_common_form_status
            WHERE study_id = ? AND subject_id = ? AND form_type = 'AE'
            LIMIT 1
        ");
        $stmtRow->execute([(int)$study_id, (int)$subject_id]);
        $anyExp = $stmtRow->fetchColumn();

        $stmtCnt = $pdo->prepare("
            SELECT COUNT(*) FROM subject_common_records
            WHERE study_id = ? AND subject_id = ? AND form_type = 'AE' AND is_voided = FALSE
        ");
        $stmtCnt->execute([(int)$study_id, (int)$subject_id]);
        $activeCount = (int)$stmtCnt->fetchColumn();

        if ($anyExp === 'No' && $activeCount > 0) {
            $msg = "Conflict: 'Were any adverse events experienced?' is No, but {$activeCount} active AE record(s) exist. Set to Yes or void existing AE record(s) with an audit reason.";
            upsertSystemCommonQuery(
                $pdo,
                $study_id,
                $subject_id,
                null,
                'AE',
                'ae_experienced',
                'AE_OCCURRENCE_CONFLICT',
                $msg,
                $user_id
            );
        } else {
            closeResolvedSystemCommonQueries(
                $pdo,
                $study_id,
                $subject_id,
                null,
                'AE',
                'AE_OCCURRENCE_CONFLICT',
                $user_id,
                'AE occurrence reconciled cleanly'
            );
        }
    }
}
