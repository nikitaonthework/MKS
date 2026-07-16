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
    badge_color           VARCHAR(20)  NULL, -- 'purple' | 'blue' — только для it-сотрудников
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_users_full_name (full_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Токены «запомнить меня» (постоянный вход — см. includes/auth.php).
-- Хранится только хэш валидатора, не он сам — утечка базы не позволяет
-- воспользоваться украденным токеном. Один пользователь может иметь
-- несколько токенов (по одному на каждое устройство/браузер, где входил).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS remember_tokens (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    selector        VARCHAR(32) NOT NULL,
    validator_hash  CHAR(64) NOT NULL,
    expires_at      DATETIME NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_remember_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_remember_selector (selector),
    KEY idx_remember_user (user_id)
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
    KEY idx_tickets_status_updated (status, updated_at),
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

-- ---------------------------------------------------------------------
-- Push-подписки браузеров сотрудников IT-отдела (отдельное веб-приложение
-- /it/, устанавливается на главный экран — см. README). Один сотрудник
-- может иметь несколько подписок (телефон + компьютер и т.п.).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS push_subscriptions (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    endpoint    VARCHAR(500) NOT NULL,
    p256dh      VARCHAR(255) NOT NULL,
    auth        VARCHAR(255) NOT NULL,
    user_agent  VARCHAR(255) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_push_sub_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_push_endpoint (endpoint(255)),
    KEY idx_push_sub_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- История отправки push-уведомлений (для диагностики it/diagnose_push.php).
-- Отправка синхронная — прямо в момент события (см. includes/webpush.php,
-- webpush_notify_users()), без очереди и cron; сюда каждая попытка просто
-- логируется уже с итоговым статусом (sent/failed).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS push_queue (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    subscription_id  INT UNSIGNED NOT NULL,
    title            VARCHAR(255) NOT NULL,
    body             TEXT NOT NULL,
    url              VARCHAR(500) NULL,
    status           ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
    attempts         TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_error       VARCHAR(500) NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at          DATETIME NULL,
    CONSTRAINT fk_push_queue_sub FOREIGN KEY (subscription_id) REFERENCES push_subscriptions(id) ON DELETE CASCADE,
    KEY idx_push_queue_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- Сотрудники клиники (список из СОТРУДНИКИ.xlsx, 498 уникальных ФИО)
-- Пароль по умолчанию для всех: 123456789 (хэш ниже соответствует ему)
-- Подключается отдельным файлом seed_employees.sql
-- ---------------------------------------------------------------------
