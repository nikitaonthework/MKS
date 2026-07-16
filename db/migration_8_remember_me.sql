-- =====================================================================
--  Миграция 8: добавляет таблицу remember_tokens — постоянный вход
--  («запомнить меня»), чтобы не приходилось логиниться заново при каждом
--  визите (веб-кабинет сотрудников и веб-приложение /it/). См.
--  includes/auth.php. Выполните один раз через phpMyAdmin (вкладка «SQL»).
--  При установке с нуля через актуальный db/schema.sql это не нужно —
--  таблица там уже есть.
-- =====================================================================

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
