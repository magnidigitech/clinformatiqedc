-- Add Option Groups Tables

CREATE TABLE IF NOT EXISTS `option_groups` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `study_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `study_id` (`study_id`),
  CONSTRAINT `fk_og_study` FOREIGN KEY (`study_id`) REFERENCES `studies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `option_choices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `group_id` int(11) NOT NULL,
  `label` varchar(255) NOT NULL,
  `value` varchar(100) NOT NULL, -- The stored value (e.g., "1", "0", "US")
  `order_index` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `group_id` (`group_id`),
  CONSTRAINT `fk_oc_group` FOREIGN KEY (`group_id`) REFERENCES `option_groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add option_group_id to form_fields to link dropdowns/radios to a shared group
-- We check if column exists effectively by trying to add it (MySQL doesn't support IF NOT EXISTS for columns easily in one line without procedure, so we use a safe ALTER via PHP or just expect it to succeed/fail gracefully if handled)
-- For this script, we assume it's a fresh run. 
-- Note: User might run this multiple times, so we should be careful. 
-- However, typically `ADD COLUMN` fails if it exists. 

ALTER TABLE `form_fields` ADD COLUMN `option_group_id` int(11) DEFAULT NULL AFTER `options`;
ALTER TABLE `form_fields` ADD CONSTRAINT `fk_field_og` FOREIGN KEY (`option_group_id`) REFERENCES `option_groups` (`id`) ON DELETE SET NULL;
