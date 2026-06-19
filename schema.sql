-- PostgreSQL Database Schema for Clinformatiq EDC

-- Users table
CREATE TABLE IF NOT EXISTS users (
    id SERIAL PRIMARY KEY,
    username VARCHAR(100) UNIQUE NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    name VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Studies table
CREATE TABLE IF NOT EXISTS studies (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    trial_registry_id VARCHAR(100) NULL,
    study_code VARCHAR(50) UNIQUE NOT NULL,
    site_name VARCHAR(255) NULL,
    site_abbreviation VARCHAR(10) NULL,
    site_country VARCHAR(100) NULL,
    template VARCHAR(50) DEFAULT 'none',
    type VARCHAR(50) DEFAULT 'test',
    created_by INT NULL REFERENCES users(id) ON DELETE SET NULL,
    status VARCHAR(50) DEFAULT 'design',
    main_contact VARCHAR(255) NULL,
    sponsor VARCHAR(255) NULL,
    study_design VARCHAR(100) NULL,
    study_category VARCHAR(100) NULL,
    approval_study_type VARCHAR(100) NULL,
    therapeutic_area VARCHAR(100) NULL,
    randomization_enabled BOOLEAN DEFAULT FALSE,
    monitoring_enabled BOOLEAN DEFAULT TRUE,
    surveys_enabled BOOLEAN DEFAULT FALSE,
    gcp_reason_required BOOLEAN DEFAULT TRUE,
    participant_id_method VARCHAR(50) DEFAULT 'incremental',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Sites table
CREATE TABLE IF NOT EXISTS sites (
    id SERIAL PRIMARY KEY,
    study_id INT NOT NULL REFERENCES studies(id) ON DELETE CASCADE,
    name VARCHAR(255) NOT NULL,
    country VARCHAR(100) NOT NULL,
    site_code VARCHAR(50) NOT NULL,
    abbreviation VARCHAR(50) NOT NULL,
    date_format VARCHAR(50) DEFAULT 'YYYY-MM-DD (ISO)',
    main_site BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Study Users table
CREATE TABLE IF NOT EXISTS study_users (
    id SERIAL PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    study_id INT NOT NULL REFERENCES studies(id) ON DELETE CASCADE,
    role_name VARCHAR(100) NOT NULL,
    permissions TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Study User Sites table
CREATE TABLE IF NOT EXISTS study_user_sites (
    id SERIAL PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    study_id INT NOT NULL REFERENCES studies(id) ON DELETE CASCADE,
    site_id INT NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Study Visits table
CREATE TABLE IF NOT EXISTS study_visits (
    id SERIAL PRIMARY KEY,
    study_id INT NOT NULL REFERENCES studies(id) ON DELETE CASCADE,
    name VARCHAR(255) NOT NULL,
    order_index INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Study Repeating Modules table
CREATE TABLE IF NOT EXISTS study_repeating_modules (
    id SERIAL PRIMARY KEY,
    study_id INT NOT NULL REFERENCES studies(id) ON DELETE CASCADE,
    name VARCHAR(255) NOT NULL,
    order_index INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Study Forms table
CREATE TABLE IF NOT EXISTS study_forms (
    id SERIAL PRIMARY KEY,
    visit_id INT NULL REFERENCES study_visits(id) ON DELETE CASCADE,
    repeating_module_id INT NULL REFERENCES study_repeating_modules(id) ON DELETE CASCADE,
    name VARCHAR(255) NOT NULL,
    order_index INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Option Groups table
CREATE TABLE IF NOT EXISTS option_groups (
    id SERIAL PRIMARY KEY,
    study_id INT NULL REFERENCES studies(id) ON DELETE CASCADE,
    name VARCHAR(100) NOT NULL,
    description TEXT NULL,
    layout VARCHAR(50) DEFAULT 'vertical',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Option Choices table
CREATE TABLE IF NOT EXISTS option_choices (
    id SERIAL PRIMARY KEY,
    group_id INT NOT NULL REFERENCES option_groups(id) ON DELETE CASCADE,
    label VARCHAR(255) NOT NULL,
    value VARCHAR(100) NOT NULL,
    order_index INT DEFAULT 0
);

-- Form Fields table
CREATE TABLE IF NOT EXISTS form_fields (
    id SERIAL PRIMARY KEY,
    form_id INT NOT NULL REFERENCES study_forms(id) ON DELETE CASCADE,
    type VARCHAR(100) NOT NULL,
    label TEXT NOT NULL,
    variable_name VARCHAR(100) NOT NULL,
    order_index INT DEFAULT 0,
    is_required BOOLEAN DEFAULT FALSE,
    help_text TEXT NULL,
    validation_rules TEXT NULL,
    option_group_id INT NULL REFERENCES option_groups(id) ON DELETE SET NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Subjects table
CREATE TABLE IF NOT EXISTS subjects (
    id SERIAL PRIMARY KEY,
    study_id INT NOT NULL REFERENCES studies(id) ON DELETE CASCADE,
    subject_code VARCHAR(100) NOT NULL,
    site_name VARCHAR(255) NULL,
    status VARCHAR(50) DEFAULT 'Screening',
    progress INT DEFAULT 0,
    created_by INT NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Subject Repeating Instances table
CREATE TABLE IF NOT EXISTS subject_repeating_instances (
    id SERIAL PRIMARY KEY,
    study_id INT NOT NULL REFERENCES studies(id) ON DELETE CASCADE,
    subject_id INT NOT NULL REFERENCES subjects(id) ON DELETE CASCADE,
    repeating_module_id INT NOT NULL REFERENCES study_repeating_modules(id) ON DELETE CASCADE,
    instance_label VARCHAR(100) NOT NULL,
    status VARCHAR(50) DEFAULT 'active',
    created_by INT NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Subject Data table
CREATE TABLE IF NOT EXISTS subject_data (
    id SERIAL PRIMARY KEY,
    study_id INT NOT NULL REFERENCES studies(id) ON DELETE CASCADE,
    subject_id INT NOT NULL REFERENCES subjects(id) ON DELETE CASCADE,
    visit_id INT NOT NULL,
    form_id INT NOT NULL REFERENCES study_forms(id) ON DELETE CASCADE,
    field_id INT NOT NULL REFERENCES form_fields(id) ON DELETE CASCADE,
    repeating_instance_id INT DEFAULT NULL,
    value TEXT NULL,
    updated_by INT NULL REFERENCES users(id) ON DELETE SET NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Subject Form Status table
CREATE TABLE IF NOT EXISTS subject_form_status (
    id SERIAL PRIMARY KEY,
    study_id INT NOT NULL REFERENCES studies(id) ON DELETE CASCADE,
    subject_id INT NOT NULL REFERENCES subjects(id) ON DELETE CASCADE,
    visit_id INT NOT NULL,
    form_id INT NOT NULL REFERENCES study_forms(id) ON DELETE CASCADE,
    repeating_instance_id INT DEFAULT NULL,
    status VARCHAR(50) DEFAULT 'empty',
    is_complete BOOLEAN DEFAULT FALSE,
    is_verified BOOLEAN DEFAULT FALSE,
    progress_percent INT DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Data Queries table
CREATE TABLE IF NOT EXISTS data_queries (
    id SERIAL PRIMARY KEY,
    study_id INT NOT NULL REFERENCES studies(id) ON DELETE CASCADE,
    subject_id INT NOT NULL REFERENCES subjects(id) ON DELETE CASCADE,
    visit_id INT NOT NULL,
    form_id INT NOT NULL REFERENCES study_forms(id) ON DELETE CASCADE,
    field_id INT NOT NULL REFERENCES form_fields(id) ON DELETE CASCADE,
    repeating_instance_id INT DEFAULT 0,
    query_text TEXT NOT NULL,
    status VARCHAR(50) DEFAULT 'new',
    created_by INT NOT NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    current_assignee_role VARCHAR(50) DEFAULT 'investigator',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Data Query History table
CREATE TABLE IF NOT EXISTS data_query_history (
    id SERIAL PRIMARY KEY,
    query_id INT NOT NULL REFERENCES data_queries(id) ON DELETE CASCADE,
    status_from VARCHAR(50) NULL,
    status_to VARCHAR(50) NOT NULL,
    remark TEXT NOT NULL,
    created_by INT NOT NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Data Comments table
CREATE TABLE IF NOT EXISTS data_comments (
    id SERIAL PRIMARY KEY,
    study_id INT NOT NULL REFERENCES studies(id) ON DELETE CASCADE,
    subject_id INT NOT NULL REFERENCES subjects(id) ON DELETE CASCADE,
    visit_id INT NOT NULL,
    form_id INT NOT NULL REFERENCES study_forms(id) ON DELETE CASCADE,
    field_id INT NOT NULL REFERENCES form_fields(id) ON DELETE CASCADE,
    repeating_instance_id INT DEFAULT 0,
    comment_text TEXT NOT NULL,
    created_by INT NOT NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Data Audit Log table
CREATE TABLE IF NOT EXISTS data_audit_log (
    id SERIAL PRIMARY KEY,
    study_id INT NOT NULL REFERENCES studies(id) ON DELETE CASCADE,
    subject_id INT NOT NULL REFERENCES subjects(id) ON DELETE CASCADE,
    visit_id INT NOT NULL,
    form_id INT NOT NULL REFERENCES study_forms(id) ON DELETE CASCADE,
    field_id INT NOT NULL REFERENCES form_fields(id) ON DELETE CASCADE,
    repeating_instance_id INT DEFAULT 0,
    old_value TEXT NULL,
    new_value TEXT NULL,
    reason_for_change VARCHAR(255) NULL,
    change_type VARCHAR(50) DEFAULT 'update',
    action_by INT NOT NULL REFERENCES users(id) ON DELETE SET NULL,
    action_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Triggers to update updated_at columns automatically
CREATE OR REPLACE FUNCTION update_updated_at_column()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ language 'plpgsql';

CREATE OR REPLACE TRIGGER update_subject_data_updated_at 
BEFORE UPDATE ON subject_data 
FOR EACH ROW 
EXECUTE FUNCTION update_updated_at_column();

CREATE OR REPLACE TRIGGER update_subject_form_status_updated_at 
BEFORE UPDATE ON subject_form_status 
FOR EACH ROW 
EXECUTE FUNCTION update_updated_at_column();

CREATE OR REPLACE TRIGGER update_data_queries_updated_at 
BEFORE UPDATE ON data_queries 
FOR EACH ROW 
EXECUTE FUNCTION update_updated_at_column();

-- Seed default admin user
INSERT INTO users (username, email, password_hash)
VALUES ('admin', 'admin@clinformatiq.com', '$2y$12$tOmIbpAofxtIpuiuCjbaW.D2VaBh0tZzzTrSLt/4tB9dVI09WvpCa')
ON CONFLICT (username) DO NOTHING;
