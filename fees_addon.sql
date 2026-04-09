-- ============================================================
--  FEES MODULE — Add these tables to your existing rbms_db
--  Run this in phpMyAdmin → SQL tab
-- ============================================================

USE `rbms_db`;

-- ─── Fee Structure (configurable per class) ─────────────────
ALTER TABLE `classes`
  ADD COLUMN `monthly_fee`   DECIMAL(10,2) NOT NULL DEFAULT 3000.00 AFTER `section`,
  ADD COLUMN `admission_fee` DECIMAL(10,2) NOT NULL DEFAULT 800.00  AFTER `monthly_fee`;

-- Update the seed classes with fees from original system
UPDATE `classes` SET `monthly_fee` = 3000, `admission_fee` = 800 WHERE `name` = 'Class 9';
UPDATE `classes` SET `monthly_fee` = 3000, `admission_fee` = 800 WHERE `name` = 'Class 10';
UPDATE `classes` SET `monthly_fee` = 4000, `admission_fee` = 800 WHERE `name` = 'Class 11';
UPDATE `classes` SET `monthly_fee` = 5000, `admission_fee` = 800 WHERE `name` = 'Class 12';

-- ─── Add father_name to students ────────────────────────────
ALTER TABLE `students`
  ADD COLUMN `father_name` VARCHAR(150) DEFAULT NULL AFTER `user_id`;

-- ─── Fees table ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `fees` (
  `id`             INT(11)      NOT NULL AUTO_INCREMENT,
  `student_id`     INT(11)      NOT NULL,
  `fee_type`       ENUM('Admission','Monthly') NOT NULL,
  `amount`         DECIMAL(10,2) NOT NULL,
  `discount`       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `discount_pct`   TINYINT(3)   NOT NULL DEFAULT 0,
  `final_amount`   DECIMAL(10,2) NOT NULL,
  `status`         ENUM('Paid','Unpaid') NOT NULL DEFAULT 'Paid',
  `payment_date`   DATE         NOT NULL,
  `receipt_number` VARCHAR(60)  NOT NULL,
  `collected_by`   INT(11)               DEFAULT NULL,
  `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_receipt` (`receipt_number`),
  CONSTRAINT `fk_fees_student`    FOREIGN KEY (`student_id`)   REFERENCES `users`  (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fees_collector`  FOREIGN KEY (`collected_by`) REFERENCES `users`  (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Fees backup (audit trail — never deleted) ───────────────
CREATE TABLE IF NOT EXISTS `fees_backup` (
  `id`             INT(11)      NOT NULL AUTO_INCREMENT,
  `student_id`     INT(11)      NOT NULL,
  `fee_type`       ENUM('Admission','Monthly') NOT NULL,
  `amount`         DECIMAL(10,2) NOT NULL,
  `discount`       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `final_amount`   DECIMAL(10,2) NOT NULL,
  `status`         ENUM('Paid','Unpaid') NOT NULL DEFAULT 'Paid',
  `payment_date`   DATE         NOT NULL,
  `receipt_number` VARCHAR(60)  NOT NULL,
  `collected_by`   INT(11)               DEFAULT NULL,
  `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
