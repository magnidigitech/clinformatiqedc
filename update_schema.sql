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
