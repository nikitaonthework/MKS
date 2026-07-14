-- =====================================================================
--  Миграция 2: очередь уведомлений в Telegram (notification_queue).
--  Выполните один раз через phpMyAdmin (вкладка «SQL»), если база уже
--  была развёрнута ДО появления этого файла. При установке с нуля через
--  актуальный db/schema.sql этот файл импортировать не нужно — там уже
--  всё есть.
--
--  Зачем: раньше веб-запросы (создание заявки, ответ, закрытие) сами
--  напрямую обращались к Telegram API, и медленный/нестабильный прокси
--  мог блокировать или обрывать ответ сайта пользователю. Теперь запрос
--  только кладёт уведомление в эту таблицу (мгновенно, без сети), а
--  реальную отправку делает bot/poll.php при каждом запуске по cron.
-- =====================================================================

CREATE TABLE IF NOT EXISTS notification_queue (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    chat_id       BIGINT NOT NULL,
    text          TEXT NOT NULL,
    reply_markup  TEXT NULL,
    status        ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
    attempts      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at       DATETIME NULL,
    KEY idx_notification_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
