-- =====================================================================
--  Миграция 1: добавляет таблицу bot_state — нужна только для режима
--  опроса Telegram по cron (bot/poll.php), как альтернативы вебхуку.
--  Если вы уже импортировали db/schema.sql ДО появления этого файла,
--  выполните этот скрипт один раз через phpMyAdmin (вкладка «SQL»).
--  Если вы разворачиваете систему с нуля — этого файла достаточно НЕ
--  импортировать, всё уже есть в db/schema.sql.
-- =====================================================================

CREATE TABLE IF NOT EXISTS bot_state (
    name   VARCHAR(50) PRIMARY KEY,
    value  VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO bot_state (name, value) VALUES ('update_offset', '0')
ON DUPLICATE KEY UPDATE name = name;
