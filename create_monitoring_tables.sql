-- Create Monitoring Tables

-- 1. Data Queries (Clarification Requests)
CREATE TABLE IF NOT EXISTS data_queries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    study_id INT NOT NULL,
    subject_id INT NOT NULL,
    visit_id INT NOT NULL,
    form_id INT NOT NULL,
    field_id INT NOT NULL,
    repeating_instance_id INT DEFAULT 0,
    query_text TEXT NOT NULL,
    status ENUM('new', 'open', 'unconfirmed', 'confirmed', 'resolved', 'closed') DEFAULT 'new',
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    current_assignee_role ENUM('monitor', 'investigator', 'admin') DEFAULT 'investigator', 
    INDEX idx_query_field (subject_id, form_id, field_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Data Query History (Thread/Conversation)
CREATE TABLE IF NOT EXISTS data_query_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    query_id INT NOT NULL,
    status_from VARCHAR(50) NULL,
    status_to VARCHAR(50) NOT NULL,
    remark TEXT NOT NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_query_hist (query_id),
    FOREIGN KEY (query_id) REFERENCES data_queries(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Data Comments (General Comments - separate from queries)
CREATE TABLE IF NOT EXISTS data_comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    study_id INT NOT NULL,
    subject_id INT NOT NULL,
    visit_id INT NOT NULL,
    form_id INT NOT NULL,
    field_id INT NOT NULL,
    repeating_instance_id INT DEFAULT 0,
    comment_text TEXT NOT NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_comment_field (subject_id, form_id, field_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Data Audit Log (History of Value Changes)
CREATE TABLE IF NOT EXISTS data_audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    study_id INT NOT NULL,
    subject_id INT NOT NULL,
    visit_id INT NOT NULL,
    form_id INT NOT NULL,
    field_id INT NOT NULL,
    repeating_instance_id INT DEFAULT 0,
    old_value LONGTEXT NULL,
    new_value LONGTEXT NULL,
    reason_for_change VARCHAR(255) NULL,
    change_type ENUM('entry', 'update', 'clear', 'missing_code') DEFAULT 'update',
    action_by INT NOT NULL,
    action_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_field (subject_id, form_id, field_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
