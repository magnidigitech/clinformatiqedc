-- PostgreSQL Schema Update Script

-- 1. Add name column to users table if it does not exist
ALTER TABLE users ADD COLUMN IF NOT EXISTS name VARCHAR(255) NULL;

-- 2. Add old_value and new_value columns to data_query_history table if they do not exist
ALTER TABLE data_query_history ADD COLUMN IF NOT EXISTS old_value TEXT NULL;
ALTER TABLE data_query_history ADD COLUMN IF NOT EXISTS new_value TEXT NULL;

-- 3. Add repeating_instance_id columns to query/audit/comment tables if they do not exist
ALTER TABLE data_queries ADD COLUMN IF NOT EXISTS repeating_instance_id INT DEFAULT 0;
ALTER TABLE data_comments ADD COLUMN IF NOT EXISTS repeating_instance_id INT DEFAULT 0;
ALTER TABLE data_audit_log ADD COLUMN IF NOT EXISTS repeating_instance_id INT DEFAULT 0;

-- 4. Add is_verified and is_complete columns to subject_form_status table if they do not exist
ALTER TABLE subject_form_status ADD COLUMN IF NOT EXISTS is_verified BOOLEAN DEFAULT FALSE;
ALTER TABLE subject_form_status ADD COLUMN IF NOT EXISTS is_complete BOOLEAN DEFAULT FALSE;

-- 5. Add progress column to subjects table if it does not exist
ALTER TABLE subjects ADD COLUMN IF NOT EXISTS progress INT DEFAULT 0;

-- 6. Add workflow status columns to subject_form_status table if they do not exist
ALTER TABLE subject_form_status ADD COLUMN IF NOT EXISTS sdr_submitted BOOLEAN DEFAULT FALSE;
ALTER TABLE subject_form_status ADD COLUMN IF NOT EXISTS monitor_reviewed BOOLEAN DEFAULT FALSE;
ALTER TABLE subject_form_status ADD COLUMN IF NOT EXISTS manager_reviewed BOOLEAN DEFAULT FALSE;

-- 8. Add college_name, batch_name, and status columns to users table if they do not exist
ALTER TABLE users ADD COLUMN IF NOT EXISTS college_name VARCHAR(255) NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS batch_name VARCHAR(100) NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS status VARCHAR(20) DEFAULT 'active';

-- 9. Add Common Forms tables (MH, AE, CM)
CREATE TABLE IF NOT EXISTS subject_common_records (
    id SERIAL PRIMARY KEY,
    study_id INT NOT NULL REFERENCES studies(id) ON DELETE CASCADE,
    subject_id INT NOT NULL REFERENCES subjects(id) ON DELETE CASCADE,
    form_type VARCHAR(10) NOT NULL,
    seq_number INT NOT NULL,
    record_number VARCHAR(50) NOT NULL,
    status VARCHAR(20) DEFAULT 'draft',
    sdr_status VARCHAR(20) DEFAULT 'pending',
    sdr_by INT NULL REFERENCES users(id) ON DELETE SET NULL,
    sdr_at TIMESTAMP NULL,
    sdr_revision INT DEFAULT 0,
    revision INT DEFAULT 1,
    is_voided BOOLEAN DEFAULT FALSE,
    void_reason TEXT NULL,
    voided_by INT NULL REFERENCES users(id) ON DELETE SET NULL,
    voided_at TIMESTAMP NULL,
    data_json TEXT NULL,
    created_by INT NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT unq_subj_form_seq UNIQUE(subject_id, form_type, seq_number)
);

CREATE TABLE IF NOT EXISTS cm_record_links (
    id SERIAL PRIMARY KEY,
    cm_record_id INT NOT NULL REFERENCES subject_common_records(id) ON DELETE CASCADE,
    target_record_id INT NOT NULL REFERENCES subject_common_records(id) ON DELETE CASCADE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT unq_cm_target UNIQUE(cm_record_id, target_record_id)
);

CREATE TABLE IF NOT EXISTS common_form_queries (
    id SERIAL PRIMARY KEY,
    study_id INT NOT NULL REFERENCES studies(id) ON DELETE CASCADE,
    subject_id INT NOT NULL REFERENCES subjects(id) ON DELETE CASCADE,
    record_id INT NOT NULL REFERENCES subject_common_records(id) ON DELETE CASCADE,
    field_name VARCHAR(100) NULL,
    query_text TEXT NOT NULL,
    status VARCHAR(20) DEFAULT 'open',
    created_by INT NOT NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS common_form_query_history (
    id SERIAL PRIMARY KEY,
    query_id INT NOT NULL REFERENCES common_form_queries(id) ON DELETE CASCADE,
    action_type VARCHAR(50) NOT NULL,
    remark TEXT NOT NULL,
    created_by INT NOT NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS common_form_sdr_history (
    id SERIAL PRIMARY KEY,
    record_id INT NOT NULL REFERENCES subject_common_records(id) ON DELETE CASCADE,
    action VARCHAR(50) NOT NULL,
    reviewed_revision INT NOT NULL,
    action_by INT NOT NULL REFERENCES users(id) ON DELETE SET NULL,
    action_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS common_form_audit_log (
    id SERIAL PRIMARY KEY,
    study_id INT NOT NULL REFERENCES studies(id) ON DELETE CASCADE,
    subject_id INT NOT NULL REFERENCES subjects(id) ON DELETE CASCADE,
    record_id INT NOT NULL REFERENCES subject_common_records(id) ON DELETE CASCADE,
    field_name VARCHAR(100) NOT NULL,
    old_value TEXT NULL,
    new_value TEXT NULL,
    reason_for_change TEXT NULL,
    action_by INT NOT NULL REFERENCES users(id) ON DELETE SET NULL,
    action_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);



