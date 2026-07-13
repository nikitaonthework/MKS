-- =====================================================================
--  IT Service Desk — схема базы данных (MySQL 8.0 / MariaDB 10.3+)
--  Кодировка: utf8mb4
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- Пользователи (сотрудники клиники + сотрудники it-отдела)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name             VARCHAR(255) NOT NULL,
    password_hash         VARCHAR(255) NOT NULL,
    must_change_password  TINYINT(1)   NOT NULL DEFAULT 1,
    role                  ENUM('employee','it') NOT NULL DEFAULT 'employee',
    telegram_id           BIGINT NULL,
    badge_color           VARCHAR(20)  NULL, -- 'purple' | 'blue' — только для it-сотрудников
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_users_full_name (full_name),
    UNIQUE KEY uniq_users_telegram_id (telegram_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Заявки
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tickets (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       INT UNSIGNED NOT NULL,        -- автор заявки
    assigned_to   INT UNSIGNED NULL,             -- сотрудник it-отдела, закрепивший за собой
    status        ENUM('open','closed') NOT NULL DEFAULT 'open',
    subject       VARCHAR(255) NULL,             -- краткое превью первого сообщения
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    closed_at     DATETIME NULL,
    CONSTRAINT fk_tickets_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_tickets_assigned FOREIGN KEY (assigned_to) REFERENCES users(id),
    KEY idx_tickets_status (status),
    KEY idx_tickets_user (user_id),
    KEY idx_tickets_assigned (assigned_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Сообщения диалога по заявке
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS messages (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_id   INT UNSIGNED NOT NULL,
    sender_id   INT UNSIGNED NOT NULL,
    body        TEXT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_messages_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_messages_sender FOREIGN KEY (sender_id) REFERENCES users(id),
    KEY idx_messages_ticket (ticket_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Вложения (фото/документы) к сообщениям
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS attachments (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message_id     INT UNSIGNED NOT NULL,
    stored_name    VARCHAR(255) NOT NULL,   -- имя файла на диске
    original_name  VARCHAR(255) NOT NULL,
    mime_type      VARCHAR(150) NOT NULL,
    file_size      INT UNSIGNED NOT NULL,
    is_image       TINYINT(1) NOT NULL DEFAULT 0,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_attachments_message FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
    KEY idx_attachments_message (message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Учёт часов дежурства в выходные (для еженедельного отчёта)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS duty_hours (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_id    INT UNSIGNED NOT NULL,
    it_user_id   INT UNSIGNED NOT NULL,
    hours        DECIMAL(4,1) NOT NULL DEFAULT 1.0,
    work_date    DATE NOT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_duty_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_duty_user FOREIGN KEY (it_user_id) REFERENCES users(id),
    KEY idx_duty_user_date (it_user_id, work_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- Сотрудники IT-отдела (обрабатывают заявки через Telegram mini app)
-- ---------------------------------------------------------------------
INSERT INTO users (full_name, password_hash, must_change_password, role, telegram_id, badge_color) VALUES
('Вавилов Александр', '$2y$12$2.vXKYPquFojxB.ozZ4SGuKcAdyQZKbXUKzMOSWTsKb4YNQhwZgYW', 1, 'it', 1126928689, 'purple'),
('Павлов Никита', '$2y$12$2.vXKYPquFojxB.ozZ4SGuKcAdyQZKbXUKzMOSWTsKb4YNQhwZgYW', 1, 'it', 98303100, 'blue')
ON DUPLICATE KEY UPDATE full_name = VALUES(full_name);

-- ---------------------------------------------------------------------
-- Сотрудники клиники (список из СОТРУДНИКИ.xlsx, 498 уникальных ФИО)
-- Пароль по умолчанию для всех: 123456789 (хэш ниже соответствует ему)
-- Подключается отдельным файлом seed_employees.sql
-- ---------------------------------------------------------------------
