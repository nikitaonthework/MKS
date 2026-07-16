-- =====================================================================
--  Миграция 7: полный отказ от Telegram-бота и cron. Уведомления IT-отдела
--  теперь идут только через push в браузер (/it/), отправляются синхронно
--  в момент события — без очереди и без cron (см. includes/webpush.php,
--  webpush_notify_users()). Эта миграция убирает то, что было нужно только
--  для Telegram-бота и больше не используется:
--   - таблицу bot_state (offset для getUpdates при polling);
--   - таблицу notification_queue (очередь сообщений в Telegram);
--   - колонку users.telegram_id и её уникальный индекс.
--  Выполните один раз через phpMyAdmin (вкладка «SQL»). При установке
--  с нуля через актуальный db/schema.sql это не нужно — там всего этого
--  уже нет. Столбец badge_color НЕ трогаем — это просто цвет бейджа в
--  интерфейсе, к Telegram отношения не имеет.
-- =====================================================================

DROP TABLE IF EXISTS notification_queue;
DROP TABLE IF EXISTS bot_state;

ALTER TABLE users DROP INDEX uniq_users_telegram_id;
ALTER TABLE users DROP COLUMN telegram_id;
