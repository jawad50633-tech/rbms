-- ============================================================
--  Role-Based Management System — Database Schema
--  Compatible with MySQL 5.7+ / MariaDB 10.3+
--  InfinityFree / cPanel compatible
-- ============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET FOREIGN_KEY_CHECKS = 0;
SET NAMES utf8mb4;

-- ─────────────────────────────────────────
-- Database (change name to match yours)
-- ─────────────────────────────────────────
CREATE DATABASE IF NOT EXISTS `rbms_db`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `rbms_db`;

-- ─────────────────────────────────────────
-- Table: users  (all roles in one table)
-- ─────────────────────────────────────────
CREATE TABLE `users` (
  `id`           INT(11)      NOT NULL AUTO_INCREMENT,
  `full_name`    VARCHAR(150) NOT NULL,
  `email`        VARCHAR(191) NOT NULL,
  `username`     VARCHAR(80)  NOT NULL,
  `password`     VARCHAR(255) NOT NULL,
  `role`         ENUM('super_admin','admin','teacher','student') NOT NULL DEFAULT 'student',
  `status`       ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_email`    (`email`),
  UNIQUE KEY `uq_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────
-- Table: classes
-- ─────────────────────────────────────────
CREATE TABLE `classes` (
  `id`         INT(11)     NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(80) NOT NULL,
  `section`    VARCHAR(20)          DEFAULT NULL,
  `created_at` TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────
-- Table: students  (extended profile)
-- ─────────────────────────────────────────
CREATE TABLE `students` (
  `id`            INT(11)      NOT NULL AUTO_INCREMENT,
  `user_id`       INT(11)      NOT NULL,
  `roll_number`   VARCHAR(40)  NOT NULL,
  `class_id`      INT(11)               DEFAULT NULL,
  `date_of_birth` DATE                  DEFAULT NULL,
  `phone`         VARCHAR(20)           DEFAULT NULL,
  `address`       TEXT                  DEFAULT NULL,
  `photo`         VARCHAR(255)          DEFAULT NULL,
  `enrolled_at`   DATE                  DEFAULT NULL,
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_roll`    (`roll_number`),
  UNIQUE KEY `uq_user_id` (`user_id`),
  CONSTRAINT `fk_students_user`  FOREIGN KEY (`user_id`)  REFERENCES `users`    (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_students_class` FOREIGN KEY (`class_id`) REFERENCES `classes`  (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────
-- Table: assignments
-- ─────────────────────────────────────────
CREATE TABLE `assignments` (
  `id`           INT(11)      NOT NULL AUTO_INCREMENT,
  `teacher_id`   INT(11)      NOT NULL,
  `class_id`     INT(11)               DEFAULT NULL,
  `title`        VARCHAR(255) NOT NULL,
  `description`  TEXT                  DEFAULT NULL,
  `due_date`     DATE                  DEFAULT NULL,
  `total_marks`  INT(11)               DEFAULT 100,
  `status`       ENUM('active','closed') NOT NULL DEFAULT 'active',
  `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_assignments_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users`   (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_assignments_class`   FOREIGN KEY (`class_id`)   REFERENCES `classes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────
-- Table: submissions
-- ─────────────────────────────────────────
CREATE TABLE `submissions` (
  `id`            INT(11)      NOT NULL AUTO_INCREMENT,
  `assignment_id` INT(11)      NOT NULL,
  `student_id`    INT(11)      NOT NULL,
  `file_name`     VARCHAR(255) NOT NULL,
  `file_path`     VARCHAR(255) NOT NULL,
  `file_size`     INT(11)               DEFAULT NULL,
  `marks`         INT(11)               DEFAULT NULL,
  `feedback`      TEXT                  DEFAULT NULL,
  `submitted_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_submission` (`assignment_id`, `student_id`),
  CONSTRAINT `fk_submissions_assignment` FOREIGN KEY (`assignment_id`) REFERENCES `assignments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_submissions_student`    FOREIGN KEY (`student_id`)    REFERENCES `users`       (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────
-- Table: activity_log
-- ─────────────────────────────────────────
CREATE TABLE `activity_log` (
  `id`         INT(11)      NOT NULL AUTO_INCREMENT,
  `user_id`    INT(11)               DEFAULT NULL,
  `action`     VARCHAR(255) NOT NULL,
  `module`     VARCHAR(80)           DEFAULT NULL,
  `ip_address` VARCHAR(45)           DEFAULT NULL,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_log_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────
-- Seed Data — Default Super Admin
-- Password: Admin@12345  (change immediately)
-- ─────────────────────────────────────────
INSERT INTO `users` (`full_name`, `email`, `username`, `password`, `role`, `status`) VALUES
('Super Administrator', 'superadmin@school.com', 'superadmin', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'super_admin', 'active'),
('School Admin',       'admin@school.com',      'admin',      '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin',       'active'),
('John Teacher',       'teacher@school.com',    'teacher1',   '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'teacher',     'active'),
('Jane Student',       'student@school.com',    'student1',   '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student',     'active');

INSERT INTO `classes` (`name`, `section`) VALUES
('Class 9',  'A'),
('Class 9',  'B'),
('Class 10', 'A'),
('Class 10', 'B'),
('Class 11', 'Science'),
('Class 12', 'Commerce');

INSERT INTO `students` (`user_id`, `roll_number`, `class_id`, `enrolled_at`) VALUES
(4, 'STU-2024-001', 1, '2024-01-15');

SET FOREIGN_KEY_CHECKS = 1;

-- ─────────────────────────────────────────
-- NOTE: Default password for all seed users
-- is:  password
-- CHANGE IMMEDIATELY after first login.
-- ─────────────────────────────────────────
