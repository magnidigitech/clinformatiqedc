<?php
/**
 * Clinical Protocol Template Importer
 * Standard Oncology Protocol: Screening, Day 1, End of Treatment / End of Study
 * Implements visits, forms, fields, option groups, and skip-logic rules.
 */

require_once __DIR__ . '/../config/db.php';

function getProtocolTemplateDefinition() {
    return [
        'name' => 'Standard Oncology Protocol (Visits & Forms)',
        'description' => 'Structured 3-visit oncology clinical study protocol with RECIST 1.1, laboratory panels, vital signs, study drug administration, and complete skip-logic workflow.',
        
        // Option groups to seed for the study
        'option_groups' => [
            'yes_no' => [
                'name' => 'Yes / No',
                'description' => 'Standard binary boolean choices',
                'layout' => 'horizontal',
                'choices' => [
                    ['label' => 'Yes', 'value' => 'Yes', 'order' => 1],
                    ['label' => 'No', 'value' => 'No', 'order' => 2]
                ]
            ],
            'visit_not_performed_reason' => [
                'name' => 'Visit Not Performed Reason',
                'description' => 'Reasons a scheduled visit was not conducted',
                'layout' => 'vertical',
                'choices' => [
                    ['label' => 'Subject Screen Failure', 'value' => 'Subject Screen Failure', 'order' => 1],
                    ['label' => 'Subject Unable to Attend Visit', 'value' => 'Subject Unable to Attend Visit', 'order' => 2],
                    ['label' => 'Subject Withdraw Consent', 'value' => 'Subject Withdraw Consent', 'order' => 3],
                    ['label' => 'Death', 'value' => 'Death', 'order' => 4]
                ]
            ],
            'sex' => [
                'name' => 'Biological Sex',
                'description' => 'Demographics sex classification',
                'layout' => 'vertical',
                'choices' => [
                    ['label' => 'Female', 'value' => 'Female', 'order' => 1],
                    ['label' => 'Male', 'value' => 'Male', 'order' => 2],
                    ['label' => 'Intersex', 'value' => 'Intersex', 'order' => 3],
                    ['label' => 'Unknown / Not collected', 'value' => 'Unknown / Not collected', 'order' => 4]
                ]
            ],
            'race' => [
                'name' => 'Race Categories',
                'description' => 'FDA / CDISC standard race categories',
                'layout' => 'vertical',
                'choices' => [
                    ['label' => 'American Indian or Alaska Native', 'value' => 'American Indian or Alaska Native', 'order' => 1],
                    ['label' => 'Asian', 'value' => 'Asian', 'order' => 2],
                    ['label' => 'Black or African American', 'value' => 'Black or African American', 'order' => 3],
                    ['label' => 'Native Hawaiian or Other Pacific Islander', 'value' => 'Native Hawaiian or Other Pacific Islander', 'order' => 4],
                    ['label' => 'White', 'value' => 'White', 'order' => 5],
                    ['label' => 'Other', 'value' => 'Other', 'order' => 6],
                    ['label' => 'Unknown / Not reported', 'value' => 'Unknown / Not reported', 'order' => 7]
                ]
            ],
            'ethnicity' => [
                'name' => 'Ethnicity Categories',
                'description' => 'OMB / CDISC ethnicity',
                'layout' => 'vertical',
                'choices' => [
                    ['label' => 'Hispanic or Latino', 'value' => 'Hispanic or Latino', 'order' => 1],
                    ['label' => 'Not Hispanic or Latino', 'value' => 'Not Hispanic or Latino', 'order' => 2],
                    ['label' => 'Unknown', 'value' => 'Unknown', 'order' => 3]
                ]
            ],
            'vitals_not_assessed_reason' => [
                'name' => 'Vital Signs Reason Not Assessed',
                'description' => 'Reasons why vital signs were not measured',
                'layout' => 'vertical',
                'choices' => [
                    ['label' => 'Subject declined', 'value' => 'Subject declined', 'order' => 1],
                    ['label' => 'Equipment unavailable', 'value' => 'Equipment unavailable', 'order' => 2],
                    ['label' => 'Subject unable to undergo assessment', 'value' => 'Subject unable to undergo assessment', 'order' => 3],
                    ['label' => 'Other', 'value' => 'Other', 'order' => 4]
                ]
            ],
            'lab_not_collected_reason' => [
                'name' => 'Lab Samples Not Collected Reason',
                'description' => 'Reason blood or biological samples were missed',
                'layout' => 'vertical',
                'choices' => [
                    ['label' => 'Subject unavailable', 'value' => 'Subject unavailable', 'order' => 1],
                    ['label' => 'Subject declined', 'value' => 'Subject declined', 'order' => 2],
                    ['label' => 'Vein access failed', 'value' => 'Vein access failed', 'order' => 3],
                    ['label' => 'Equipment unavailable', 'value' => 'Equipment unavailable', 'order' => 4],
                    ['label' => 'Other', 'value' => 'Other', 'order' => 5]
                ]
            ],
            'lab_results_status' => [
                'name' => 'Laboratory Results Status',
                'description' => 'Availability state of lab test results',
                'layout' => 'vertical',
                'choices' => [
                    ['label' => 'Available', 'value' => 'Available', 'order' => 1],
                    ['label' => 'Pending', 'value' => 'Pending', 'order' => 2],
                    ['label' => 'Unavailable', 'value' => 'Unavailable', 'order' => 3]
                ]
            ],
            'lab_test_status' => [
                'name' => 'Individual Lab Test Status',
                'description' => 'Status per individual laboratory test row',
                'layout' => 'vertical',
                'choices' => [
                    ['label' => 'Available', 'value' => 'Available', 'order' => 1],
                    ['label' => 'Pending', 'value' => 'Pending', 'order' => 2],
                    ['label' => 'Not done', 'value' => 'Not done', 'order' => 3],
                    ['label' => 'Unavailable', 'value' => 'Unavailable', 'order' => 4]
                ]
            ],
            'lab_flag' => [
                'name' => 'Laboratory Reference Flag',
                'description' => 'High, Low, Normal indicator against reference range',
                'layout' => 'horizontal',
                'choices' => [
                    ['label' => 'Normal', 'value' => 'Normal', 'order' => 1],
                    ['label' => 'Low', 'value' => 'Low', 'order' => 2],
                    ['label' => 'High', 'value' => 'High', 'order' => 3],
                    ['label' => 'Not provided', 'value' => 'Not provided', 'order' => 4]
                ]
            ],
            'drug_not_administered_reason' => [
                'name' => 'Treatment Not Administered Reason',
                'description' => 'Reasons study drug was skipped or withheld',
                'layout' => 'vertical',
                'choices' => [
                    ['label' => 'Adverse event', 'value' => 'Adverse event', 'order' => 1],
                    ['label' => 'Laboratory finding', 'value' => 'Laboratory finding', 'order' => 2],
                    ['label' => 'Subject declined', 'value' => 'Subject declined', 'order' => 3],
                    ['label' => 'Treatment unavailable', 'value' => 'Treatment unavailable', 'order' => 4],
                    ['label' => 'Investigator decision', 'value' => 'Investigator decision', 'order' => 5],
                    ['label' => 'Other', 'value' => 'Other', 'order' => 6]
                ]
            ],
            'route_of_admin' => [
                'name' => 'Route of Administration',
                'description' => 'Route by which study treatment is given',
                'layout' => 'vertical',
                'choices' => [
                    ['label' => 'IV infusion', 'value' => 'IV infusion', 'order' => 1],
                    ['label' => 'Oral', 'value' => 'Oral', 'order' => 2],
                    ['label' => 'Subcutaneous', 'value' => 'Subcutaneous', 'order' => 3],
                    ['label' => 'Other', 'value' => 'Other', 'order' => 4]
                ]
            ],
            'dose_units' => [
                'name' => 'Dose Units',
                'description' => 'Standard clinical pharmacology dosing units',
                'layout' => 'vertical',
                'choices' => [
                    ['label' => 'mg', 'value' => 'mg', 'order' => 1],
                    ['label' => 'mg/kg', 'value' => 'mg/kg', 'order' => 2],
                    ['label' => 'mg/m²', 'value' => 'mg/m²', 'order' => 3],
                    ['label' => 'mL', 'value' => 'mL', 'order' => 4],
                    ['label' => 'mcg', 'value' => 'mcg', 'order' => 5],
                    ['label' => 'tablets', 'value' => 'tablets', 'order' => 6],
                    ['label' => 'capsules', 'value' => 'capsules', 'order' => 7]
                ]
            ],
            'tumor_timepoint' => [
                'name' => 'Tumor Assessment Timepoint',
                'description' => 'RECIST 1.1 baseline vs post-baseline classification',
                'layout' => 'horizontal',
                'choices' => [
                    ['label' => 'Baseline', 'value' => 'Baseline', 'order' => 1],
                    ['label' => 'Post-baseline', 'value' => 'Post-baseline', 'order' => 2]
                ]
            ],
            'imaging_modality' => [
                'name' => 'Imaging Modality',
                'description' => 'Diagnostic imaging method used for RECIST',
                'layout' => 'vertical',
                'choices' => [
                    ['label' => 'CT', 'value' => 'CT', 'order' => 1],
                    ['label' => 'MRI', 'value' => 'MRI', 'order' => 2],
                    ['label' => 'CT and MRI', 'value' => 'CT and MRI', 'order' => 3],
                    ['label' => 'PET-CT', 'value' => 'PET-CT', 'order' => 4],
                    ['label' => 'Other', 'value' => 'Other', 'order' => 5]
                ]
            ],
            'lesion_type' => [
                'name' => 'Target Lesion Type',
                'description' => 'RECIST 1.1 lesion organ classification',
                'layout' => 'horizontal',
                'choices' => [
                    ['label' => 'Non-nodal', 'value' => 'Non-nodal', 'order' => 1],
                    ['label' => 'Lymph node', 'value' => 'Lymph node', 'order' => 2]
                ]
            ],
            'lesion_axis' => [
                'name' => 'Measurement Axis',
                'description' => 'RECIST 1.1 Long-axis (non-nodal) vs Short-axis (nodal)',
                'layout' => 'horizontal',
                'choices' => [
                    ['label' => 'Long', 'value' => 'Long', 'order' => 1],
                    ['label' => 'Short', 'value' => 'Short', 'order' => 2]
                ]
            ],
            'target_lesion_status' => [
                'name' => 'Target Lesion Status',
                'description' => 'Evaluation status for individual target lesion',
                'layout' => 'vertical',
                'choices' => [
                    ['label' => 'Measured', 'value' => 'Measured', 'order' => 1],
                    ['label' => 'Absent', 'value' => 'Absent', 'order' => 2],
                    ['label' => 'Not assessed', 'value' => 'Not assessed', 'order' => 3],
                    ['label' => 'Too small to measure', 'value' => 'Too small to measure', 'order' => 4]
                ]
            ],
            'nontarget_status' => [
                'name' => 'Non-target Lesion Status',
                'description' => 'RECIST 1.1 non-target response criteria',
                'layout' => 'vertical',
                'choices' => [
                    ['label' => 'Present', 'value' => 'Present', 'order' => 1],
                    ['label' => 'Absent', 'value' => 'Absent', 'order' => 2],
                    ['label' => 'Unequivocal progression', 'value' => 'Unequivocal progression', 'order' => 3],
                    ['label' => 'Not assessed', 'value' => 'Not assessed', 'order' => 4]
                ]
            ],
            'new_lesions_choice' => [
                'name' => 'New Lesions Identified',
                'description' => 'Detection of new metastatic or malignant lesions',
                'layout' => 'horizontal',
                'choices' => [
                    ['label' => 'No', 'value' => 'No', 'order' => 1],
                    ['label' => 'Yes', 'value' => 'Yes', 'order' => 2],
                    ['label' => 'Indeterminate', 'value' => 'Indeterminate', 'order' => 3]
                ]
            ],
            'recist_overall_response' => [
                'name' => 'RECIST Overall Response',
                'description' => 'RECIST 1.1 Overall response assessment',
                'layout' => 'vertical',
                'choices' => [
                    ['label' => 'CR — Complete Response', 'value' => 'CR — Complete Response', 'order' => 1],
                    ['label' => 'PR — Partial Response', 'value' => 'PR — Partial Response', 'order' => 2],
                    ['label' => 'SD — Stable Disease', 'value' => 'SD — Stable Disease', 'order' => 3],
                    ['label' => 'PD — Progressive Disease', 'value' => 'PD — Progressive Disease', 'order' => 4],
                    ['label' => 'NE — Not Evaluable', 'value' => 'NE — Not Evaluable', 'order' => 5]
                ]
            ],
            'treatment_status' => [
                'name' => 'Study Treatment Status',
                'description' => 'Overall completion status for investigational product',
                'layout' => 'vertical',
                'choices' => [
                    ['label' => 'Completed per protocol', 'value' => 'Completed per protocol', 'order' => 1],
                    ['label' => 'Discontinued', 'value' => 'Discontinued', 'order' => 2]
                ]
            ],
            'treatment_discontinuation_reason' => [
                'name' => 'Treatment Discontinuation Reason',
                'description' => 'Primary reason study drug was permanently discontinued',
                'layout' => 'vertical',
                'choices' => [
                    ['label' => 'Disease progression', 'value' => 'Disease progression', 'order' => 1],
                    ['label' => 'Adverse event', 'value' => 'Adverse event', 'order' => 2],
                    ['label' => 'Lack of efficacy', 'value' => 'Lack of efficacy', 'order' => 3],
                    ['label' => 'Subject withdrew from treatment', 'value' => 'Subject withdrew from treatment', 'order' => 4],
                    ['label' => 'Investigator decision', 'value' => 'Investigator decision', 'order' => 5],
                    ['label' => 'Sponsor decision', 'value' => 'Sponsor decision', 'order' => 6],
                    ['label' => 'Other', 'value' => 'Other', 'order' => 7]
                ]
            ],
            'subsequent_study_status' => [
                'name' => 'Subsequent Study Participation Status',
                'description' => 'Subject disposition status following treatment phase',
                'layout' => 'vertical',
                'choices' => [
                    ['label' => 'Continuing follow-up', 'value' => 'Continuing follow-up', 'order' => 1],
                    ['label' => 'Completed study', 'value' => 'Completed study', 'order' => 2],
                    ['label' => 'Withdrawn from study', 'value' => 'Withdrawn from study', 'order' => 3],
                    ['label' => 'Lost to follow-up', 'value' => 'Lost to follow-up', 'order' => 4],
                    ['label' => 'Died', 'value' => 'Died', 'order' => 5]
                ]
            ],
            'followup_type' => [
                'name' => 'Follow-up Type',
                'description' => 'Method for ongoing long-term contact',
                'layout' => 'vertical',
                'choices' => [
                    ['label' => 'Clinic visit', 'value' => 'Clinic visit', 'order' => 1],
                    ['label' => 'Telephone contact', 'value' => 'Telephone contact', 'order' => 2],
                    ['label' => 'Survival follow-up', 'value' => 'Survival follow-up', 'order' => 3],
                    ['label' => 'Medical records review', 'value' => 'Medical records review', 'order' => 4],
                    ['label' => 'Other', 'value' => 'Other', 'order' => 5]
                ]
            ],
            'withdrawal_reason' => [
                'name' => 'Study Withdrawal Reason',
                'description' => 'Primary reason for premature trial withdrawal',
                'layout' => 'vertical',
                'choices' => [
                    ['label' => 'Subject choice / consent withdrawn', 'value' => 'Subject choice / consent withdrawn', 'order' => 1],
                    ['label' => 'Adverse event', 'value' => 'Adverse event', 'order' => 2],
                    ['label' => 'Disease progression', 'value' => 'Disease progression', 'order' => 3],
                    ['label' => 'Protocol deviation', 'value' => 'Protocol deviation', 'order' => 4],
                    ['label' => 'Physician decision', 'value' => 'Physician decision', 'order' => 5],
                    ['label' => 'Sponsor decision', 'value' => 'Sponsor decision', 'order' => 6],
                    ['label' => 'Other', 'value' => 'Other', 'order' => 7]
                ]
            ],
            'scope_of_withdrawal' => [
                'name' => 'Scope of Withdrawal',
                'description' => 'Extent of subject withdrawal permission',
                'layout' => 'vertical',
                'choices' => [
                    ['label' => 'Withdrew from study visits only (permits survival follow-up)', 'value' => 'Withdrew from study visits only (permits survival follow-up)', 'order' => 1],
                    ['label' => 'Full withdrawal of consent (no further contact or data collection)', 'value' => 'Full withdrawal of consent (no further contact or data collection)', 'order' => 2]
                ]
            ],
            'final_disposition_category' => [
                'name' => 'Final Disposition Category',
                'description' => 'Formal trial closeout classification',
                'layout' => 'vertical',
                'choices' => [
                    ['label' => 'Study Completed', 'value' => 'Study Completed', 'order' => 1],
                    ['label' => 'Withdrawn prior to completion', 'value' => 'Withdrawn prior to completion', 'order' => 2],
                    ['label' => 'Lost to follow-up', 'value' => 'Lost to follow-up', 'order' => 3],
                    ['label' => 'Deceased', 'value' => 'Deceased', 'order' => 4]
                ]
            ]
        ],
        
        // Structured Visits and Forms
        'visits' => [
            [
                'name' => 'Screening',
                'order' => 1,
                'forms' => [
                    [
                        'name' => 'Visit Details',
                        'order' => 1,
                        'fields' => getVisitDetailsFields()
                    ],
                    [
                        'name' => 'Demographics',
                        'order' => 2,
                        'fields' => getDemographicsFields()
                    ],
                    [
                        'name' => 'Vital Signs',
                        'order' => 3,
                        'fields' => getVitalSignsFields('Screening')
                    ],
                    [
                        'name' => 'Laboratory Assessment Hematology',
                        'order' => 4,
                        'fields' => getHematologyFields('Screening')
                    ],
                    [
                        'name' => 'Laboratory Assessment Chemistry',
                        'order' => 5,
                        'fields' => getChemistryFields('Screening')
                    ]
                ]
            ],
            [
                'name' => 'Day 1',
                'order' => 2,
                'forms' => [
                    [
                        'name' => 'Visit Details',
                        'order' => 1,
                        'fields' => getVisitDetailsFields()
                    ],
                    [
                        'name' => 'Pre-dose Vital Signs',
                        'order' => 2,
                        'fields' => getVitalSignsFields('Pre-dose')
                    ],
                    [
                        'name' => 'Laboratory Assessment Hematology',
                        'order' => 3,
                        'fields' => getHematologyFields('Pre-dose')
                    ],
                    [
                        'name' => 'Laboratory Assessment Chemistry',
                        'order' => 4,
                        'fields' => getChemistryFields('Pre-dose')
                    ],
                    [
                        'name' => 'Study Drug Administration',
                        'order' => 5,
                        'fields' => getStudyDrugAdministrationFields()
                    ]
                ]
            ],
            [
                'name' => 'End of Treatment / End of Study',
                'order' => 3,
                'forms' => [
                    [
                        'name' => 'Visit Details',
                        'order' => 1,
                        'fields' => getVisitDetailsFields()
                    ],
                    [
                        'name' => 'Treatment Status',
                        'order' => 2,
                        'fields' => getTreatmentStatusFields()
                    ]
                ]
            ]
        ]
    ];
}

/**
 * 2. Visit Details Form Fields
 */
function getVisitDetailsFields() {
    return [
        [
            'var' => 'visit_performed',
            'label' => 'Was this Visit Performed',
            'type' => 'radio',
            'opt_group' => 'yes_no',
            'required' => true,
            'help' => 'Required at every visit. Selecting No displays reason and skips actual visit date and routine assessments.'
        ],
        [
            'var' => 'date_of_visit',
            'label' => 'Date of Visit',
            'type' => 'date',
            'required' => true,
            'condition' => ['field' => 'visit_performed', 'operator' => '==', 'value' => 'Yes'],
            'help' => 'Format: MM/DD/YYYY. Actual date procedures were conducted.'
        ],
        [
            'var' => 'visit_not_performed_reason',
            'label' => 'Primary reason the visit was not performed',
            'type' => 'dropdown',
            'opt_group' => 'visit_not_performed_reason',
            'required' => true,
            'condition' => ['field' => 'visit_performed', 'operator' => '==', 'value' => 'No'],
            'help' => 'Required if visit was not performed.'
        ],
        [
            'var' => 'date_of_death',
            'label' => 'Date of death',
            'type' => 'date',
            'required' => true,
            'condition' => ['field' => 'visit_not_performed_reason', 'operator' => '==', 'value' => 'Death'],
            'help' => 'Format: MM/DD/YYYY'
        ]
    ];
}

/**
 * 3. Screening Demographics Form Fields
 */
function getDemographicsFields() {
    return [
        [
            'var' => 'subject_initials',
            'label' => 'Subject initials',
            'type' => 'text',
            'required' => false,
            'help' => 'Display only if permitted and required by the protocol (e.g. F-M-L).'
        ],
        [
            'var' => 'date_of_birth',
            'label' => 'Date of birth',
            'type' => 'date',
            'required' => true,
            'help' => 'Format: MM/DD/YYYY. DOB cannot be after the screening date.'
        ],
        [
            'var' => 'age_at_screening',
            'label' => 'Age at screening, in years',
            'type' => 'number',
            'required' => true,
            'help' => 'Integer. Calculated from DOB and screening date, or manually entered.'
        ],
        [
            'var' => 'sex',
            'label' => 'Sex',
            'type' => 'dropdown',
            'opt_group' => 'sex',
            'required' => true,
            'help' => 'Biological sex at birth.'
        ],
        [
            'var' => 'race',
            'label' => 'Race',
            'type' => 'dropdown',
            'opt_group' => 'race',
            'required' => true,
            'help' => 'Select race category. Unknown / Not reported cannot be combined with a known race.'
        ],
        [
            'var' => 'race_other_specify',
            'label' => 'Other race — specify',
            'type' => 'text',
            'required' => true,
            'condition' => ['field' => 'race', 'operator' => '==', 'value' => 'Other'],
            'help' => 'Required when Other is selected for race.'
        ],
        [
            'var' => 'ethnicity',
            'label' => 'Ethnicity',
            'type' => 'dropdown',
            'opt_group' => 'ethnicity',
            'required' => true,
            'help' => 'Single selection per FDA/OMB guidance.'
        ]
    ];
}

/**
 * 4. Vital Signs Form Fields
 */
function getVitalSignsFields($defaultTimepoint = 'Screening') {
    return [
        [
            'var' => 'vitals_assessed',
            'label' => 'Were vital signs assessed?',
            'type' => 'radio',
            'opt_group' => 'yes_no',
            'required' => true,
            'help' => 'Selecting No displays reason and continues to Laboratory Assessments.'
        ],
        [
            'var' => 'vitals_not_assessed_reason',
            'label' => 'Reason vital signs not assessed',
            'type' => 'dropdown',
            'opt_group' => 'vitals_not_assessed_reason',
            'required' => true,
            'condition' => ['field' => 'vitals_assessed', 'operator' => '==', 'value' => 'No'],
            'help' => 'Subject declined / Equipment unavailable / Subject unable to undergo assessment / Other.'
        ],
        [
            'var' => 'vitals_not_assessed_other',
            'label' => 'Please specify reason not assessed',
            'type' => 'text',
            'required' => true,
            'condition' => ['field' => 'vitals_not_assessed_reason', 'operator' => '==', 'value' => 'Other'],
            'help' => 'Required when reason is Other.'
        ],
        [
            'var' => 'vitals_assessment_date',
            'label' => 'Assessment date',
            'type' => 'date',
            'required' => true,
            'condition' => ['field' => 'vitals_assessed', 'operator' => '==', 'value' => 'Yes'],
            'help' => 'Format: MM/DD/YYYY'
        ],
        [
            'var' => 'vitals_assessment_time',
            'label' => 'Assessment time',
            'type' => 'time',
            'required' => true,
            'condition' => ['field' => 'vitals_assessed', 'operator' => '==', 'value' => 'Yes'],
            'help' => 'Format: HH:MM (24-hour clock). Required for pre-dose timing comparison.'
        ],
        
        // Systolic Blood Pressure
        [
            'var' => 'sbp_obtained',
            'label' => 'Systolic blood pressure — Measurement obtained?',
            'type' => 'radio',
            'opt_group' => 'yes_no',
            'required' => true,
            'condition' => ['field' => 'vitals_assessed', 'operator' => '==', 'value' => 'Yes'],
            'help' => 'Select Yes to enter value (mmHg), or No to document reason.'
        ],
        [
            'var' => 'sbp_val',
            'label' => 'Systolic blood pressure (mmHg)',
            'type' => 'number',
            'required' => true,
            'condition' => ['field' => 'sbp_obtained', 'operator' => '==', 'value' => 'Yes'],
            'help' => 'Plausibility range: 70 – 200 mmHg. Unusual values flagged for review.'
        ],
        [
            'var' => 'sbp_reason',
            'label' => 'Reason SBP not obtained',
            'type' => 'dropdown',
            'opt_group' => 'vitals_not_assessed_reason',
            'required' => true,
            'condition' => ['field' => 'sbp_obtained', 'operator' => '==', 'value' => 'No'],
            'help' => 'Subject declined / Equipment unavailable / Subject unable to undergo assessment / Other.'
        ],
        [
            'var' => 'sbp_reason_other',
            'label' => 'Reason SBP not obtained — Please specify',
            'type' => 'text',
            'required' => true,
            'condition' => ['field' => 'sbp_reason', 'operator' => '==', 'value' => 'Other'],
            'help' => 'Required when reason is Other.'
        ],
        
        // Diastolic Blood Pressure
        [
            'var' => 'dbp_obtained',
            'label' => 'Diastolic blood pressure — Measurement obtained?',
            'type' => 'radio',
            'opt_group' => 'yes_no',
            'required' => true,
            'condition' => ['field' => 'vitals_assessed', 'operator' => '==', 'value' => 'Yes'],
            'help' => 'Select Yes to enter value (mmHg), or No to document reason.'
        ],
        [
            'var' => 'dbp_val',
            'label' => 'Diastolic blood pressure (mmHg)',
            'type' => 'number',
            'required' => true,
            'condition' => ['field' => 'dbp_obtained', 'operator' => '==', 'value' => 'Yes'],
            'help' => 'Plausibility range: 40 – 130 mmHg.'
        ],
        [
            'var' => 'dbp_reason',
            'label' => 'Reason DBP not obtained',
            'type' => 'dropdown',
            'opt_group' => 'vitals_not_assessed_reason',
            'required' => true,
            'condition' => ['field' => 'dbp_obtained', 'operator' => '==', 'value' => 'No'],
            'help' => 'Subject declined / Equipment unavailable / Subject unable to undergo assessment / Other.'
        ],
        [
            'var' => 'dbp_reason_other',
            'label' => 'Reason DBP not obtained — Please specify',
            'type' => 'text',
            'required' => true,
            'condition' => ['field' => 'dbp_reason', 'operator' => '==', 'value' => 'Other'],
            'help' => 'Required when reason is Other.'
        ],
        
        // Heart Rate
        [
            'var' => 'hr_obtained',
            'label' => 'Heart rate — Measurement obtained?',
            'type' => 'radio',
            'opt_group' => 'yes_no',
            'required' => true,
            'condition' => ['field' => 'vitals_assessed', 'operator' => '==', 'value' => 'Yes']
        ],
        [
            'var' => 'hr_val',
            'label' => 'Heart rate (bpm)',
            'type' => 'number',
            'required' => true,
            'condition' => ['field' => 'hr_obtained', 'operator' => '==', 'value' => 'Yes'],
            'help' => 'Plausibility range: 40 – 180 bpm.'
        ],
        [
            'var' => 'hr_reason',
            'label' => 'Reason Heart rate not obtained',
            'type' => 'dropdown',
            'opt_group' => 'vitals_not_assessed_reason',
            'required' => true,
            'condition' => ['field' => 'hr_obtained', 'operator' => '==', 'value' => 'No'],
            'help' => 'Subject declined / Equipment unavailable / Subject unable to undergo assessment / Other.'
        ],
        [
            'var' => 'hr_reason_other',
            'label' => 'Reason Heart rate not obtained — Please specify',
            'type' => 'text',
            'required' => true,
            'condition' => ['field' => 'hr_reason', 'operator' => '==', 'value' => 'Other'],
            'help' => 'Required when reason is Other.'
        ],
        
        // Respiratory Rate
        [
            'var' => 'rr_obtained',
            'label' => 'Respiratory rate — Measurement obtained?',
            'type' => 'radio',
            'opt_group' => 'yes_no',
            'required' => true,
            'condition' => ['field' => 'vitals_assessed', 'operator' => '==', 'value' => 'Yes']
        ],
        [
            'var' => 'rr_val',
            'label' => 'Respiratory rate (breaths/min)',
            'type' => 'number',
            'required' => true,
            'condition' => ['field' => 'rr_obtained', 'operator' => '==', 'value' => 'Yes'],
            'help' => 'Plausibility range: 8 – 40 breaths/min.'
        ],
        [
            'var' => 'rr_reason',
            'label' => 'Reason Respiratory rate not obtained',
            'type' => 'dropdown',
            'opt_group' => 'vitals_not_assessed_reason',
            'required' => true,
            'condition' => ['field' => 'rr_obtained', 'operator' => '==', 'value' => 'No'],
            'help' => 'Subject declined / Equipment unavailable / Subject unable to undergo assessment / Other.'
        ],
        [
            'var' => 'rr_reason_other',
            'label' => 'Reason Respiratory rate not obtained — Please specify',
            'type' => 'text',
            'required' => true,
            'condition' => ['field' => 'rr_reason', 'operator' => '==', 'value' => 'Other'],
            'help' => 'Required when reason is Other.'
        ],
        
        // Temperature
        [
            'var' => 'temp_obtained',
            'label' => 'Temperature — Measurement obtained?',
            'type' => 'radio',
            'opt_group' => 'yes_no',
            'required' => true,
            'condition' => ['field' => 'vitals_assessed', 'operator' => '==', 'value' => 'Yes']
        ],
        [
            'var' => 'temp_val',
            'label' => 'Temperature (°C)',
            'type' => 'text',
            'required' => true,
            'condition' => ['field' => 'temp_obtained', 'operator' => '==', 'value' => 'Yes'],
            'help' => 'Numeric decimal, e.g. 36.8 °C. Plausibility: 35.0 – 41.0 °C.'
        ],
        [
            'var' => 'temp_reason',
            'label' => 'Reason Temperature not obtained',
            'type' => 'dropdown',
            'opt_group' => 'vitals_not_assessed_reason',
            'required' => true,
            'condition' => ['field' => 'temp_obtained', 'operator' => '==', 'value' => 'No'],
            'help' => 'Subject declined / Equipment unavailable / Subject unable to undergo assessment / Other.'
        ],
        [
            'var' => 'temp_reason_other',
            'label' => 'Reason Temperature not obtained — Please specify',
            'type' => 'text',
            'required' => true,
            'condition' => ['field' => 'temp_reason', 'operator' => '==', 'value' => 'Other'],
            'help' => 'Required when reason is Other.'
        ],
        
        // Weight
        [
            'var' => 'weight_obtained',
            'label' => 'Weight — Measurement obtained?',
            'type' => 'radio',
            'opt_group' => 'yes_no',
            'required' => true,
            'condition' => ['field' => 'vitals_assessed', 'operator' => '==', 'value' => 'Yes']
        ],
        [
            'var' => 'weight_val',
            'label' => 'Weight (kg)',
            'type' => 'text',
            'required' => true,
            'condition' => ['field' => 'weight_obtained', 'operator' => '==', 'value' => 'Yes'],
            'help' => 'Numeric decimal allowed, e.g. 70.5 kg.'
        ],
        [
            'var' => 'weight_reason',
            'label' => 'Reason Weight not obtained',
            'type' => 'dropdown',
            'opt_group' => 'vitals_not_assessed_reason',
            'required' => true,
            'condition' => ['field' => 'weight_obtained', 'operator' => '==', 'value' => 'No'],
            'help' => 'Subject declined / Equipment unavailable / Subject unable to undergo assessment / Other.'
        ],
        [
            'var' => 'weight_reason_other',
            'label' => 'Reason Weight not obtained — Please specify',
            'type' => 'text',
            'required' => true,
            'condition' => ['field' => 'weight_reason', 'operator' => '==', 'value' => 'Other'],
            'help' => 'Required when reason is Other.'
        ]
    ];
}

/**
 * 5. Laboratory Assessment Hematology Form Fields
 */
function getHematologyFields($timepoint = 'Screening') {
    $fields = [
        [
            'var' => 'hema_panel_collected',
            'label' => 'Hematology Panel — Was a sample collected for this panel?',
            'type' => 'radio',
            'opt_group' => 'yes_no',
            'required' => true,
            'help' => 'Select Yes to enter sample collection details and test results; No to enter reason not collected.'
        ],
        [
            'var' => 'hema_not_collected_reason',
            'label' => 'Hematology Panel — Reason sample not collected',
            'type' => 'dropdown',
            'opt_group' => 'lab_not_collected_reason',
            'required' => true,
            'condition' => ['field' => 'hema_panel_collected', 'operator' => '==', 'value' => 'No'],
            'help' => 'Subject unavailable / Subject declined / Vein access failed / Equipment unavailable / Other.'
        ],
        [
            'var' => 'hema_not_collected_other',
            'label' => 'Hematology Panel — Reason sample not collected (Please specify)',
            'type' => 'text',
            'required' => true,
            'condition' => ['field' => 'hema_not_collected_reason', 'operator' => '==', 'value' => 'Other'],
            'help' => 'Required when reason is Other.'
        ],
        [
            'var' => 'hema_collection_date',
            'label' => 'Hematology — Sample collection date',
            'type' => 'date',
            'required' => true,
            'condition' => ['field' => 'hema_panel_collected', 'operator' => '==', 'value' => 'Yes'],
            'help' => 'Format: MM/DD/YYYY'
        ],
        [
            'var' => 'hema_collection_time',
            'label' => 'Hematology — Sample collection time',
            'type' => 'time',
            'required' => true,
            'condition' => ['field' => 'hema_panel_collected', 'operator' => '==', 'value' => 'Yes'],
            'help' => 'Format: HH:MM (24-hour clock)'
        ]
    ];

    $hemaTests = [
        ['code' => 'hgb', 'name' => 'Hemoglobin', 'unit' => 'g/dL', 'ref_low' => '12.0', 'ref_high' => '17.5'],
        ['code' => 'hct', 'name' => 'Hematocrit', 'unit' => '%', 'ref_low' => '36.0', 'ref_high' => '50.0'],
        ['code' => 'rbc', 'name' => 'Red blood cell count — RBC', 'unit' => '10^6/µL', 'ref_low' => '4.2', 'ref_high' => '5.9'],
        ['code' => 'wbc', 'name' => 'White blood cell count — WBC', 'unit' => '10^3/µL', 'ref_low' => '4.5', 'ref_high' => '11.0'],
        ['code' => 'anc', 'name' => 'Absolute neutrophil count — ANC', 'unit' => '10^3/µL', 'ref_low' => '1.5', 'ref_high' => '8.0'],
        ['code' => 'plt', 'name' => 'Platelet count', 'unit' => '10^3/µL', 'ref_low' => '150', 'ref_high' => '450']
    ];

    foreach ($hemaTests as $t) {
        $fields[] = [
            'var' => 'hema_' . $t['code'] . '_val',
            'label' => $t['name'] . ' — Result (' . $t['unit'] . ')',
            'type' => 'text',
            'required' => true,
            'condition' => ['field' => 'hema_panel_collected', 'operator' => '==', 'value' => 'Yes'],
            'help' => 'Normal range: ' . $t['ref_low'] . ' – ' . $t['ref_high'] . ' ' . $t['unit']
        ];
        $fields[] = [
            'var' => 'hema_' . $t['code'] . '_flag',
            'label' => $t['name'] . ' — Flag',
            'type' => 'dropdown',
            'opt_group' => 'lab_flag',
            'condition' => ['field' => 'hema_panel_collected', 'operator' => '==', 'value' => 'Yes']
        ];
        $fields[] = [
            'var' => 'hema_' . $t['code'] . '_clin_sig',
            'label' => $t['name'] . ' — Clinically significant?',
            'type' => 'radio',
            'opt_group' => 'yes_no',
            'condition' => ['field' => 'hema_' . $t['code'] . '_flag', 'operator' => 'in', 'value' => ['Low', 'High']],
            'help' => 'Shown when result is abnormal (Low/High).'
        ];
        $fields[] = [
            'var' => 'hema_' . $t['code'] . '_action',
            'label' => $t['name'] . ' — Clinical comment / AE Cross-reference',
            'type' => 'text',
            'condition' => ['field' => 'hema_' . $t['code'] . '_clin_sig', 'operator' => '==', 'value' => 'Yes']
        ];
    }

    return $fields;
}

/**
 * 5b. Laboratory Assessment Chemistry Form Fields
 */
function getChemistryFields($timepoint = 'Screening') {
    $fields = [
        [
            'var' => 'chem_panel_collected',
            'label' => 'Chemistry Panel — Was a sample collected for this panel?',
            'type' => 'radio',
            'opt_group' => 'yes_no',
            'required' => true,
            'help' => 'Select Yes to enter sample collection details and test results; No to enter reason not collected.'
        ],
        [
            'var' => 'chem_not_collected_reason',
            'label' => 'Chemistry Panel — Reason sample not collected',
            'type' => 'dropdown',
            'opt_group' => 'lab_not_collected_reason',
            'required' => true,
            'condition' => ['field' => 'chem_panel_collected', 'operator' => '==', 'value' => 'No'],
            'help' => 'Subject unavailable / Subject declined / Vein access failed / Equipment unavailable / Other.'
        ],
        [
            'var' => 'chem_not_collected_other',
            'label' => 'Chemistry Panel — Reason sample not collected (Please specify)',
            'type' => 'text',
            'required' => true,
            'condition' => ['field' => 'chem_not_collected_reason', 'operator' => '==', 'value' => 'Other'],
            'help' => 'Required when reason is Other.'
        ],
        [
            'var' => 'chem_collection_date',
            'label' => 'Chemistry — Sample collection date',
            'type' => 'date',
            'required' => true,
            'condition' => ['field' => 'chem_panel_collected', 'operator' => '==', 'value' => 'Yes'],
            'help' => 'Format: MM/DD/YYYY'
        ],
        [
            'var' => 'chem_collection_time',
            'label' => 'Chemistry — Sample collection time',
            'type' => 'time',
            'required' => true,
            'condition' => ['field' => 'chem_panel_collected', 'operator' => '==', 'value' => 'Yes'],
            'help' => 'Format: HH:MM (24-hour clock)'
        ]
    ];

    $chemTests = [
        ['code' => 'sod', 'name' => 'Sodium', 'unit' => 'mmol/L', 'ref_low' => '135', 'ref_high' => '145'],
        ['code' => 'pot', 'name' => 'Potassium', 'unit' => 'mmol/L', 'ref_low' => '3.5', 'ref_high' => '5.0'],
        ['code' => 'glu', 'name' => 'Glucose', 'unit' => 'mg/dL', 'ref_low' => '70', 'ref_high' => '100'],
        ['code' => 'cal', 'name' => 'Calcium', 'unit' => 'mg/dL', 'ref_low' => '8.5', 'ref_high' => '10.5'],
        ['code' => 'alb', 'name' => 'Albumin', 'unit' => 'g/dL', 'ref_low' => '3.5', 'ref_high' => '5.5'],
        ['code' => 'tp', 'name' => 'Total protein', 'unit' => 'g/dL', 'ref_low' => '6.0', 'ref_high' => '8.3']
    ];

    foreach ($chemTests as $t) {
        $fields[] = [
            'var' => 'chem_' . $t['code'] . '_val',
            'label' => $t['name'] . ' — Result (' . $t['unit'] . ')',
            'type' => 'text',
            'required' => true,
            'condition' => ['field' => 'chem_panel_collected', 'operator' => '==', 'value' => 'Yes'],
            'help' => 'Normal range: ' . $t['ref_low'] . ' – ' . $t['ref_high'] . ' ' . $t['unit']
        ];
        $fields[] = [
            'var' => 'chem_' . $t['code'] . '_flag',
            'label' => $t['name'] . ' — Flag',
            'type' => 'dropdown',
            'opt_group' => 'lab_flag',
            'condition' => ['field' => 'chem_panel_collected', 'operator' => '==', 'value' => 'Yes']
        ];
        $fields[] = [
            'var' => 'chem_' . $t['code'] . '_clin_sig',
            'label' => $t['name'] . ' — Clinically significant?',
            'type' => 'radio',
            'opt_group' => 'yes_no',
            'condition' => ['field' => 'chem_' . $t['code'] . '_flag', 'operator' => 'in', 'value' => ['Low', 'High']],
            'help' => 'Shown when result is abnormal (Low/High).'
        ];
        $fields[] = [
            'var' => 'chem_' . $t['code'] . '_action',
            'label' => $t['name'] . ' — Clinical comment / AE Cross-reference',
            'type' => 'text',
            'condition' => ['field' => 'chem_' . $t['code'] . '_clin_sig', 'operator' => '==', 'value' => 'Yes']
        ];
    }

    return $fields;
}

function getLaboratoryFields($timepoint = 'Screening') {
    return array_merge(getHematologyFields($timepoint), getChemistryFields($timepoint));
}

/**
 * 6. Study Drug Administration Form Fields
 */
function getStudyDrugAdministrationFields() {
    return [
        [
            'var' => 'drug_administered',
            'label' => 'Was study treatment administered at this visit?',
            'type' => 'radio',
            'opt_group' => 'yes_no',
            'required' => true,
            'help' => 'Selecting No displays reason and continues to other scheduled assessments without discontinuing treatment.'
        ],
        [
            'var' => 'drug_not_administered_reason',
            'label' => 'Reason treatment not administered',
            'type' => 'dropdown',
            'opt_group' => 'drug_not_administered_reason',
            'required' => true,
            'condition' => ['field' => 'drug_administered', 'operator' => '==', 'value' => 'No']
        ],
        [
            'var' => 'drug_ae_reference',
            'label' => 'Adverse event record reference',
            'type' => 'text',
            'condition' => ['field' => 'drug_not_administered_reason', 'operator' => '==', 'value' => 'Adverse event'],
            'help' => 'Reference existing Adverse Event identifier (e.g. AE-01).'
        ],
        [
            'var' => 'drug_not_administered_other',
            'label' => 'Please specify reason not administered',
            'type' => 'text',
            'required' => true,
            'condition' => ['field' => 'drug_not_administered_reason', 'operator' => '==', 'value' => 'Other']
        ],
        [
            'var' => 'drug_name',
            'label' => 'Study treatment name / code',
            'type' => 'text',
            'required' => true,
            'condition' => ['field' => 'drug_administered', 'operator' => '==', 'value' => 'Yes'],
            'help' => 'Protocol investigational medicinal product (IMP) name.'
        ],
        [
            'var' => 'drug_admin_date',
            'label' => 'Administration date',
            'type' => 'date',
            'required' => true,
            'condition' => ['field' => 'drug_administered', 'operator' => '==', 'value' => 'Yes']
        ],
        [
            'var' => 'drug_admin_start_time',
            'label' => 'Administration start time',
            'type' => 'time',
            'required' => true,
            'condition' => ['field' => 'drug_administered', 'operator' => '==', 'value' => 'Yes'],
            'help' => 'Format: HH:MM (24-hour clock)'
        ],
        [
            'var' => 'drug_route',
            'label' => 'Route of administration',
            'type' => 'dropdown',
            'opt_group' => 'route_of_admin',
            'required' => true,
            'condition' => ['field' => 'drug_administered', 'operator' => '==', 'value' => 'Yes'],
            'help' => 'Select IV infusion, Oral, Subcutaneous, or Other.'
        ],
        [
            'var' => 'drug_route_other',
            'label' => 'Other route — specify',
            'type' => 'text',
            'required' => true,
            'condition' => ['field' => 'drug_route', 'operator' => '==', 'value' => 'Other']
        ],
        [
            'var' => 'drug_actual_dose',
            'label' => 'Actual dose administered',
            'type' => 'text',
            'required' => true,
            'condition' => ['field' => 'drug_administered', 'operator' => '==', 'value' => 'Yes'],
            'help' => 'Numeric, decimal allowed (e.g. 150.0)'
        ],
        [
            'var' => 'drug_dose_unit',
            'label' => 'Dose unit',
            'type' => 'dropdown',
            'opt_group' => 'dose_units',
            'required' => true,
            'condition' => ['field' => 'drug_administered', 'operator' => '==', 'value' => 'Yes']
        ],
        [
            'var' => 'drug_comments',
            'label' => 'Dose comments',
            'type' => 'textarea',
            'condition' => ['field' => 'drug_administered', 'operator' => '==', 'value' => 'Yes']
        ],
        
        // IV Infusion Specific Logic
        [
            'var' => 'iv_end_datetime',
            'label' => 'Infusion end date / time',
            'type' => 'datetime',
            'condition' => ['field' => 'drug_route', 'operator' => '==', 'value' => 'IV infusion'],
            'help' => 'Date and time infusion completed.'
        ],
        [
            'var' => 'iv_interrupted',
            'label' => 'Was the infusion interrupted?',
            'type' => 'radio',
            'opt_group' => 'yes_no',
            'condition' => ['field' => 'drug_route', 'operator' => '==', 'value' => 'IV infusion']
        ],
        [
            'var' => 'iv_interruption_time',
            'label' => 'Interruption time (HH:MM)',
            'type' => 'time',
            'condition' => ['field' => 'iv_interrupted', 'operator' => '==', 'value' => 'Yes']
        ],
        [
            'var' => 'iv_interruption_reason',
            'label' => 'Reason for interruption',
            'type' => 'text',
            'condition' => ['field' => 'iv_interrupted', 'operator' => '==', 'value' => 'Yes']
        ],
        [
            'var' => 'iv_restarted',
            'label' => 'Was infusion restarted?',
            'type' => 'radio',
            'opt_group' => 'yes_no',
            'condition' => ['field' => 'iv_interrupted', 'operator' => '==', 'value' => 'Yes']
        ],
        [
            'var' => 'iv_restart_time',
            'label' => 'Restart time (HH:MM)',
            'type' => 'time',
            'condition' => ['field' => 'iv_restarted', 'operator' => '==', 'value' => 'Yes']
        ],
        
        // Oral Specific
        [
            'var' => 'oral_admin_time',
            'label' => 'Oral administration time',
            'type' => 'time',
            'condition' => ['field' => 'drug_route', 'operator' => '==', 'value' => 'Oral']
        ],
        [
            'var' => 'oral_capsule_details',
            'label' => 'Tablet / capsule count and details',
            'type' => 'text',
            'condition' => ['field' => 'drug_route', 'operator' => '==', 'value' => 'Oral']
        ],
        
        // Subcutaneous Specific
        [
            'var' => 'sc_injection_time',
            'label' => 'Injection time (HH:MM)',
            'type' => 'time',
            'condition' => ['field' => 'drug_route', 'operator' => '==', 'value' => 'Subcutaneous']
        ],
        [
            'var' => 'sc_injection_site',
            'label' => 'Injection site',
            'type' => 'text',
            'condition' => ['field' => 'drug_route', 'operator' => '==', 'value' => 'Subcutaneous'],
            'help' => 'e.g. Abdomen, Upper outer thigh, Upper arm'
        ]
    ];
}

/**
 * 7. Tumor Assessment Form Fields (RECIST 1.1)
 */
function getTumorAssessmentFields() {
    $fields = [
        [
            'var' => 'recist_method',
            'label' => 'Assessment method',
            'type' => 'text',
            'required' => true,
            'default' => 'RECIST 1.1',
            'help' => 'Read-only: RECIST 1.1 Criteria (max 5 target lesions total, max 2 per organ).'
        ],
        [
            'var' => 'tumor_assessed',
            'label' => 'Was the tumor assessment performed?',
            'type' => 'radio',
            'opt_group' => 'yes_no',
            'required' => true,
            'help' => 'Yes displays lesion entry tables; No requires a reason.'
        ],
        [
            'var' => 'tumor_not_assessed_reason',
            'label' => 'Reason tumor assessment not performed',
            'type' => 'text',
            'required' => true,
            'condition' => ['field' => 'tumor_assessed', 'operator' => '==', 'value' => 'No']
        ],
        [
            'var' => 'tumor_timepoint',
            'label' => 'Assessment time point',
            'type' => 'text',
            'required' => true,
            'condition' => ['field' => 'tumor_assessed', 'operator' => '==', 'value' => 'Yes'],
            'help' => 'Baseline: establish target lesions (no CR/PR/SD/PD). Post-baseline: evaluate changes.'
        ],
        [
            'var' => 'imaging_date',
            'label' => 'Imaging assessment date',
            'type' => 'date',
            'required' => true,
            'condition' => ['field' => 'tumor_assessed', 'operator' => '==', 'value' => 'Yes'],
            'help' => 'Format: MM/DD/YYYY'
        ],
        [
            'var' => 'imaging_modality',
            'label' => 'Imaging modality',
            'type' => 'dropdown',
            'opt_group' => 'imaging_modality',
            'required' => true,
            'condition' => ['field' => 'tumor_assessed', 'operator' => '==', 'value' => 'Yes']
        ],
        [
            'var' => 'imaging_modality_other',
            'label' => 'Other modality — specify',
            'type' => 'text',
            'required' => true,
            'condition' => ['field' => 'imaging_modality', 'operator' => '==', 'value' => 'Other']
        ],
        
        // Target Lesions
        [
            'var' => 'target_lesions_present',
            'label' => 'Are target lesions present?',
            'type' => 'radio',
            'opt_group' => 'yes_no',
            'required' => true,
            'condition' => ['field' => 'tumor_assessed', 'operator' => '==', 'value' => 'Yes']
        ]
    ];

    // RECIST Target Lesions TL1 to TL5
    for ($i = 1; $i <= 5; $i++) {
        $prefix = "tl{$i}_";
        $fields[] = [
            'var' => $prefix . 'organ',
            'label' => "TL{$i} — Organ / location",
            'type' => 'text',
            'condition' => ['field' => 'target_lesions_present', 'operator' => '==', 'value' => 'Yes'],
            'help' => "Target lesion #{$i} anatomic location (max 2 per organ)."
        ];
        $fields[] = [
            'var' => $prefix . 'type',
            'label' => "TL{$i} — Lesion type",
            'type' => 'dropdown',
            'opt_group' => 'lesion_type',
            'condition' => ['field' => 'target_lesions_present', 'operator' => '==', 'value' => 'Yes']
        ];
        $fields[] = [
            'var' => $prefix . 'axis',
            'label' => "TL{$i} — Measurement axis",
            'type' => 'dropdown',
            'opt_group' => 'lesion_axis',
            'condition' => ['field' => 'target_lesions_present', 'operator' => '==', 'value' => 'Yes'],
            'help' => 'Long axis for non-nodal; Short axis for lymph nodes (RECIST 1.1).'
        ];
        $fields[] = [
            'var' => $prefix . 'baseline_diam',
            'label' => "TL{$i} — Baseline diameter (mm)",
            'type' => 'number',
            'condition' => ['field' => 'target_lesions_present', 'operator' => '==', 'value' => 'Yes'],
            'help' => 'Baseline measurement (mm). Maintained as read-only in post-baseline visits.'
        ];
        $fields[] = [
            'var' => $prefix . 'current_diam',
            'label' => "TL{$i} — Current diameter (mm)",
            'type' => 'number',
            'condition' => ['field' => 'tumor_timepoint', 'operator' => '==', 'value' => 'Post-baseline'],
            'help' => 'Current assessment diameter (mm). Hidden at Baseline.'
        ];
        $fields[] = [
            'var' => $prefix . 'status',
            'label' => "TL{$i} — Assessment status",
            'type' => 'dropdown',
            'opt_group' => 'target_lesion_status',
            'condition' => ['field' => 'target_lesions_present', 'operator' => '==', 'value' => 'Yes']
        ];
    }

    // Calculated Target Lesion Sums
    $fields[] = [
        'var' => 'tl_baseline_sum',
        'label' => 'Baseline sum of target-lesion diameters (mm)',
        'type' => 'number',
        'condition' => ['field' => 'target_lesions_present', 'operator' => '==', 'value' => 'Yes'],
        'help' => 'Calculated sum of baseline diameters for all evaluable target lesions.'
    ];
    $fields[] = [
        'var' => 'tl_current_sum',
        'label' => 'Current sum of target-lesion diameters (mm)',
        'type' => 'number',
        'condition' => ['field' => 'tumor_timepoint', 'operator' => '==', 'value' => 'Post-baseline'],
        'help' => 'Calculated sum of current diameters. Do not treat unassessed lesion as zero.'
    ];
    $fields[] = [
        'var' => 'tl_percent_change',
        'label' => 'Percentage change from baseline (%)',
        'type' => 'text',
        'condition' => ['field' => 'tumor_timepoint', 'operator' => '==', 'value' => 'Post-baseline'],
        'help' => 'Calculated: ((Current Sum - Baseline Sum) / Baseline Sum) * 100.'
    ];

    // Non-target Lesions
    $fields[] = [
        'var' => 'nontarget_lesions_present',
        'label' => 'Are non-target lesions present?',
        'type' => 'radio',
        'opt_group' => 'yes_no',
        'required' => true,
        'condition' => ['field' => 'tumor_assessed', 'operator' => '==', 'value' => 'Yes']
    ];

    for ($j = 1; $j <= 3; $j++) {
        $fields[] = [
            'var' => "ntl{$j}_organ",
            'label' => "NTL{$j} — Organ / location",
            'type' => 'text',
            'condition' => ['field' => 'nontarget_lesions_present', 'operator' => '==', 'value' => 'Yes']
        ];
        $fields[] = [
            'var' => "ntl{$j}_desc",
            'label' => "NTL{$j} — Baseline description",
            'type' => 'text',
            'condition' => ['field' => 'nontarget_lesions_present', 'operator' => '==', 'value' => 'Yes']
        ];
        $fields[] = [
            'var' => "ntl{$j}_status",
            'label' => "NTL{$j} — Current status",
            'type' => 'dropdown',
            'opt_group' => 'nontarget_status',
            'condition' => ['field' => 'nontarget_lesions_present', 'operator' => '==', 'value' => 'Yes']
        ];
    }

    // New Lesions (Post-baseline)
    $fields[] = [
        'var' => 'new_lesions_identified',
        'label' => 'Are new lesions identified?',
        'type' => 'dropdown',
        'opt_group' => 'new_lesions_choice',
        'condition' => ['field' => 'tumor_timepoint', 'operator' => '==', 'value' => 'Post-baseline'],
        'help' => 'Post-baseline only. Yes indicates progressive disease (PD).'
    ];
    $fields[] = [
        'var' => 'new_lesion_details',
        'label' => 'New lesion details',
        'type' => 'textarea',
        'condition' => ['field' => 'new_lesions_identified', 'operator' => '==', 'value' => 'Yes'],
        'help' => 'Record lesion ID, location, first detection date, modality, and description.'
    ];

    // Overall RECIST Response (Post-baseline)
    $fields[] = [
        'var' => 'overall_recist_response',
        'label' => 'Overall RECIST Response',
        'type' => 'dropdown',
        'opt_group' => 'recist_overall_response',
        'condition' => ['field' => 'tumor_timepoint', 'operator' => '==', 'value' => 'Post-baseline'],
        'help' => 'Considers target, non-target, and new lesions. CR / PR / SD / PD / NE.'
    ];
    $fields[] = [
        'var' => 'ne_response_reason',
        'label' => 'Reason response is Not Evaluable (NE)',
        'type' => 'text',
        'required' => true,
        'condition' => ['field' => 'overall_recist_response', 'operator' => '==', 'value' => 'NE — Not Evaluable']
    ];

    return $fields;
}

/**
 * 8. Form A: Treatment Status Form Fields
 */
function getTreatmentStatusFields() {
    return [
        [
            'var' => 'treatment_status',
            'label' => 'Study treatment status',
            'type' => 'dropdown',
            'opt_group' => 'treatment_status',
            'required' => true,
            'help' => 'Completed per protocol / Discontinued.'
        ],
        [
            'var' => 'treatment_completion_date',
            'label' => 'Treatment completion date',
            'type' => 'date',
            'required' => true,
            'condition' => ['field' => 'treatment_status', 'operator' => '==', 'value' => 'Completed per protocol'],
            'help' => 'Format: MM/DD/YYYY'
        ],
        [
            'var' => 'treatment_discontinuation_date',
            'label' => 'Treatment discontinuation date',
            'type' => 'date',
            'required' => true,
            'condition' => ['field' => 'treatment_status', 'operator' => '==', 'value' => 'Discontinued']
        ],
        [
            'var' => 'discontinuation_reason',
            'label' => 'Primary reason for treatment discontinuation',
            'type' => 'dropdown',
            'opt_group' => 'treatment_discontinuation_reason',
            'required' => true,
            'condition' => ['field' => 'treatment_status', 'operator' => '==', 'value' => 'Discontinued'],
            'help' => 'Disease progression / Adverse event / Lack of efficacy / Subject withdrew / Investigator decision / Sponsor / Other.'
        ],
        [
            'var' => 'discontinuation_ae_ref',
            'label' => 'Relevant adverse event record reference',
            'type' => 'text',
            'condition' => ['field' => 'discontinuation_reason', 'operator' => '==', 'value' => 'Adverse event'],
            'help' => 'Cross-reference Adverse Event ID.'
        ],
        [
            'var' => 'discontinuation_other_specify',
            'label' => 'Please specify other discontinuation reason',
            'type' => 'text',
            'required' => true,
            'condition' => ['field' => 'discontinuation_reason', 'operator' => '==', 'value' => 'Other']
        ],
        [
            'var' => 'date_of_last_treatment',
            'label' => 'Date of last study treatment',
            'type' => 'date',
            'condition' => ['field' => 'treatment_status', 'operator' => 'in', 'value' => ['Completed per protocol', 'Discontinued']],
            'help' => 'Format: MM/DD/YYYY. Preferably derived from administration records.'
        ]
    ];
}

/**
 * 8. Form B: Further Study Participation Form Fields
 */
function getFurtherParticipationFields() {
    return [
        [
            'var' => 'subsequent_study_status',
            'label' => 'Subject’s subsequent study status',
            'type' => 'dropdown',
            'opt_group' => 'subsequent_study_status',
            'required' => true,
            'help' => 'Continuing follow-up / Completed study / Withdrawn from study / Lost to follow-up / Died.'
        ],
        
        // Continuing follow-up
        [
            'var' => 'followup_type',
            'label' => 'Follow-up type',
            'type' => 'dropdown',
            'opt_group' => 'followup_type',
            'required' => true,
            'condition' => ['field' => 'subsequent_study_status', 'operator' => '==', 'value' => 'Continuing follow-up']
        ],
        [
            'var' => 'next_planned_contact_date',
            'label' => 'Next planned contact date',
            'type' => 'date',
            'condition' => ['field' => 'subsequent_study_status', 'operator' => '==', 'value' => 'Continuing follow-up']
        ],
        
        // Completed study
        [
            'var' => 'study_completion_date',
            'label' => 'Study completion date',
            'type' => 'date',
            'required' => true,
            'condition' => ['field' => 'subsequent_study_status', 'operator' => '==', 'value' => 'Completed study'],
            'help' => 'Format: MM/DD/YYYY'
        ],
        
        // Withdrawn from study
        [
            'var' => 'withdrawal_date',
            'label' => 'Date of withdrawal from study',
            'type' => 'date',
            'required' => true,
            'condition' => ['field' => 'subsequent_study_status', 'operator' => '==', 'value' => 'Withdrawn from study']
        ],
        [
            'var' => 'withdrawal_reason',
            'label' => 'Primary reason for study withdrawal',
            'type' => 'dropdown',
            'opt_group' => 'withdrawal_reason',
            'required' => true,
            'condition' => ['field' => 'subsequent_study_status', 'operator' => '==', 'value' => 'Withdrawn from study']
        ],
        [
            'var' => 'withdrawal_reason_other',
            'label' => 'Please specify withdrawal reason',
            'type' => 'text',
            'required' => true,
            'condition' => ['field' => 'withdrawal_reason', 'operator' => '==', 'value' => 'Other']
        ],
        [
            'var' => 'scope_of_withdrawal',
            'label' => 'Scope of withdrawal',
            'type' => 'dropdown',
            'opt_group' => 'scope_of_withdrawal',
            'required' => true,
            'condition' => ['field' => 'subsequent_study_status', 'operator' => '==', 'value' => 'Withdrawn from study'],
            'help' => 'Distinguish stopping visits vs full withdrawal of consent.'
        ],
        
        // Lost to follow-up
        [
            'var' => 'last_contact_date',
            'label' => 'Last successful contact date',
            'type' => 'date',
            'condition' => ['field' => 'subsequent_study_status', 'operator' => '==', 'value' => 'Lost to follow-up']
        ],
        [
            'var' => 'date_determined_lost',
            'label' => 'Date determined lost to follow-up',
            'type' => 'date',
            'condition' => ['field' => 'subsequent_study_status', 'operator' => '==', 'value' => 'Lost to follow-up']
        ],
        [
            'var' => 'contact_attempts_count',
            'label' => 'Number of documented contact attempts',
            'type' => 'number',
            'condition' => ['field' => 'subsequent_study_status', 'operator' => '==', 'value' => 'Lost to follow-up'],
            'help' => 'Minimum 3 contact attempts required per GCP.'
        ],
        [
            'var' => 'contact_attempts_summary',
            'label' => 'Summary of contact attempts',
            'type' => 'textarea',
            'condition' => ['field' => 'subsequent_study_status', 'operator' => '==', 'value' => 'Lost to follow-up']
        ],
        
        // Died
        [
            'var' => 'date_of_death',
            'label' => 'Date of death',
            'type' => 'date',
            'required' => true,
            'condition' => ['field' => 'subsequent_study_status', 'operator' => '==', 'value' => 'Died'],
            'help' => 'Format: MM/DD/YYYY'
        ],
        [
            'var' => 'primary_cause_of_death',
            'label' => 'Primary cause of death',
            'type' => 'text',
            'required' => true,
            'condition' => ['field' => 'subsequent_study_status', 'operator' => '==', 'value' => 'Died']
        ],
        [
            'var' => 'death_treatment_related',
            'label' => 'Is death considered related to study treatment?',
            'type' => 'radio',
            'opt_group' => 'yes_no',
            'condition' => ['field' => 'subsequent_study_status', 'operator' => '==', 'value' => 'Died']
        ],
        [
            'var' => 'death_record_reference',
            'label' => 'Death report / SAE reference number',
            'type' => 'text',
            'condition' => ['field' => 'subsequent_study_status', 'operator' => '==', 'value' => 'Died']
        ]
    ];
}

/**
 * 8. Form C: Study Completion / Withdrawal Details
 */
function getStudyCompletionDetailsFields() {
    return [
        [
            'var' => 'final_disposition_category',
            'label' => 'Subject Final Disposition Category',
            'type' => 'dropdown',
            'opt_group' => 'final_disposition_category',
            'required' => true,
            'help' => 'Overall trial disposition status.'
        ],
        [
            'var' => 'final_disposition_date',
            'label' => 'Final disposition date',
            'type' => 'date',
            'required' => true,
            'help' => 'Format: MM/DD/YYYY'
        ],
        [
            'var' => 'pi_confirmed',
            'label' => 'Has the Principal Investigator reviewed and confirmed all study disposition records?',
            'type' => 'radio',
            'opt_group' => 'yes_no',
            'required' => true,
            'help' => 'PI regulatory sign-off requirement.'
        ],
        [
            'var' => 'pi_confirmation_date',
            'label' => 'PI review confirmation date',
            'type' => 'date',
            'required' => true,
            'condition' => ['field' => 'pi_confirmed', 'operator' => '==', 'value' => 'Yes']
        ],
        [
            'var' => 'study_closing_comments',
            'label' => 'Protocol closing comments / data reconciliations',
            'type' => 'textarea',
            'help' => 'Final audit notes and reconciliation remarks.'
        ]
    ];
}

/**
 * Import the Protocol Template into a Study
 *
 * @param PDO $pdo
 * @param int $study_id
 * @return array Result stats
 */
function importProtocolTemplate($pdo, $study_id) {
    $def = getProtocolTemplateDefinition();
    
    $visitsCreated = 0;
    $formsCreated = 0;
    $fieldsCreated = 0;
    $optGroupsCreated = 0;

    $manageTransaction = !$pdo->inTransaction();
    if ($manageTransaction) {
        $pdo->beginTransaction();
    }

    try {
        // 1. Seed or find Option Groups
        $optGroupMap = []; // code => group_id
        
        foreach ($def['option_groups'] as $code => $ogData) {
            // Check if group already exists for this study
            $stmt = $pdo->prepare("SELECT id FROM option_groups WHERE study_id = ? AND name = ?");
            $stmt->execute([$study_id, $ogData['name']]);
            $existingGroupId = $stmt->fetchColumn();

            if ($existingGroupId) {
                $groupId = (int)$existingGroupId;
            } else {
                $stmt = $pdo->prepare("INSERT INTO option_groups (study_id, name, description, layout) VALUES (?, ?, ?, ?)");
                $stmt->execute([$study_id, $ogData['name'], $ogData['description'], $ogData['layout']]);
                $groupId = (int)$pdo->lastInsertId();
                $optGroupsCreated++;

                // Seed choices
                $stmtChoice = $pdo->prepare("INSERT INTO option_choices (group_id, label, value, order_index) VALUES (?, ?, ?, ?)");
                foreach ($ogData['choices'] as $ch) {
                    $stmtChoice->execute([$groupId, $ch['label'], $ch['value'], $ch['order']]);
                }
            }

            $optGroupMap[$code] = $groupId;
        }

        // 2. Create Visits and Forms
        foreach ($def['visits'] as $vData) {
            // Check if visit exists
            $stmt = $pdo->prepare("SELECT id FROM study_visits WHERE study_id = ? AND name = ?");
            $stmt->execute([$study_id, $vData['name']]);
            $existingVisitId = $stmt->fetchColumn();

            if ($existingVisitId) {
                $visitId = (int)$existingVisitId;
            } else {
                $stmt = $pdo->prepare("INSERT INTO study_visits (study_id, name, order_index) VALUES (?, ?, ?)");
                $stmt->execute([$study_id, $vData['name'], $vData['order']]);
                $visitId = (int)$pdo->lastInsertId();
                $visitsCreated++;
            }

            // Create Forms under Visit
            foreach ($vData['forms'] as $fData) {
                $stmt = $pdo->prepare("SELECT id FROM study_forms WHERE visit_id = ? AND name = ?");
                $stmt->execute([$visitId, $fData['name']]);
                $existingFormId = $stmt->fetchColumn();

                if ($existingFormId) {
                    $formId = (int)$existingFormId;
                    $pdo->prepare("UPDATE study_forms SET order_index = ? WHERE id = ?")->execute([$fData['order'], $formId]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO study_forms (visit_id, name, order_index) VALUES (?, ?, ?)");
                    $stmt->execute([$visitId, $fData['name'], $fData['order']]);
                    $formId = (int)$pdo->lastInsertId();
                    $formsCreated++;
                }

                // Create Form Fields
                $orderIdx = 0;
                foreach ($fData['fields'] as $fld) {
                    $orderIdx++;
                    
                    // Check if field already exists by variable_name in this form
                    $stmt = $pdo->prepare("SELECT id FROM form_fields WHERE form_id = ? AND variable_name = ?");
                    $stmt->execute([$formId, $fld['var']]);
                    $existingFieldId = $stmt->fetchColumn();

                    $ogId = null;
                    if (!empty($fld['opt_group']) && isset($optGroupMap[$fld['opt_group']])) {
                        $ogId = $optGroupMap[$fld['opt_group']];
                    }

                    // Build validation rules JSON with condition / skip logic
                    $validationRules = [];
                    if (!empty($fld['condition'])) {
                        $validationRules['condition'] = $fld['condition'];
                    }
                    if (!empty($fld['default'])) {
                        $validationRules['default'] = $fld['default'];
                    }
                    $validationRulesJson = json_encode($validationRules);

                    $isRequired = !empty($fld['required']) ? 1 : 0;

                    if (!$existingFieldId) {
                        $stmt = $pdo->prepare("
                            INSERT INTO form_fields 
                            (form_id, type, label, variable_name, order_index, is_required, help_text, validation_rules, option_group_id) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $formId,
                            $fld['type'],
                            $fld['label'],
                            $fld['var'],
                            $orderIdx,
                            $isRequired,
                            $fld['help'] ?? null,
                            $validationRulesJson,
                            $ogId
                        ]);
                        $fieldsCreated++;
                    } else {
                        // Update existing field attributes and validation_rules
                        $stmt = $pdo->prepare("
                            UPDATE form_fields 
                            SET type = ?, label = ?, order_index = ?, is_required = ?, help_text = ?, validation_rules = ?, option_group_id = ?
                            WHERE id = ?
                        ");
                        $stmt->execute([
                            $fld['type'],
                            $fld['label'],
                            $orderIdx,
                            $isRequired,
                            $fld['help'] ?? null,
                            $validationRulesJson,
                            $ogId,
                            $existingFieldId
                        ]);
                    }
                }
            }
        }

        if ($manageTransaction) {
            $pdo->commit();
        }

        return [
            'success' => true,
            'message' => 'Protocol template imported successfully.',
            'visits_created' => $visitsCreated,
            'forms_created' => $formsCreated,
            'fields_created' => $fieldsCreated,
            'opt_groups_created' => $optGroupsCreated
        ];

    } catch (Exception $e) {
        if ($manageTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}
