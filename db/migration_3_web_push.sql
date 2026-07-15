-- =====================================================================
--  Миграция 3: push-подписки браузеров для отдельного веб-приложения
--  IT-отдела (/it/) — таблицы push_subscriptions и push_queue.
--  Выполните один раз через phpMyAdmin (вкладка «SQL»), если база уже
--  была развёрнута ДО появления этого файла. При установке с нуля через
--  актуальный db/schema.sql этот файл импортировать не нужно.
-- =====================================================================

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

CREATE TABLE IF NOT EXISTS push_queue (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    subscription_id  INT UNSIGNED NOT NULL,
    title            VARCHAR(255) NOT NULL,
    body             TEXT NOT NULL,
    url              VARCHAR(500) NULL,
    status           ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
    attempts         TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at          DATETIME NULL,
    CONSTRAINT fk_push_queue_sub FOREIGN KEY (subscription_id) REFERENCES push_subscriptions(id) ON DELETE CASCADE,
    KEY idx_push_queue_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
