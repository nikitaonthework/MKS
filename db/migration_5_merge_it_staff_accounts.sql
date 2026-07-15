-- =====================================================================
--  Миграция 5 (v2): объединяет учётные записи IT-специалистов с их
--  записями в общем списке сотрудников клиники — версия, БЕЗОПАСНАЯ для
--  уже накопленных данных (заявки/сообщения/часы дежурства/push-подписки,
--  привязанные к старой отдельной IT-записи).
--
--  Изначально (db/schema.sql) Александр Вавилов и Никита Павлов заводились
--  как ОТДЕЛЬНЫЕ записи (role='it'). Выяснилось, что оба они и так есть в
--  общем списке сотрудников клиники под теми же ФИО с отчествами — то есть
--  на каждого получилось по ДВЕ записи, что невозможно из-за уникальности
--  full_name, и вызывало ошибку при входе.
--
--  Этот скрипт переносит ВСЕ данные (заявки, сообщения, часы дежурства,
--  push-подписки) со старой отдельной записи на настоящую запись
--  сотрудника (по ФИО с отчеством), затем удаляет старую запись и
--  переносит на настоящую роль/telegram_id/цвет. Пароль сотрудника и его
--  состояние (менял/не менял) не трогаются. Безопасно выполнять повторно.
--  Выполните через phpMyAdmin (вкладка «SQL») ЦЕЛИКОМ одним запуском.
-- =====================================================================

-- ---- Александр Вавилов -------------------------------------------------
SET @old_id = (SELECT id FROM users WHERE telegram_id = 1126928689 AND full_name <> 'Вавилов Александр Игоревич');
SET @new_id = (SELECT id FROM users WHERE full_name = 'Вавилов Александр Игоревич');

UPDATE tickets SET user_id = @new_id WHERE user_id = @old_id;
UPDATE tickets SET assigned_to = @new_id WHERE assigned_to = @old_id;
UPDATE messages SET sender_id = @new_id WHERE sender_id = @old_id;
UPDATE duty_hours SET it_user_id = @new_id WHERE it_user_id = @old_id;
UPDATE push_subscriptions SET user_id = @new_id WHERE user_id = @old_id;

DELETE FROM users WHERE id = @old_id;
UPDATE users SET role = 'it', telegram_id = 1126928689, badge_color = 'purple' WHERE id = @new_id;

-- ---- Никита Павлов ------------------------------------------------------
SET @old_id = (SELECT id FROM users WHERE telegram_id = 98303100 AND full_name <> 'Павлов Никита Максимович');
SET @new_id = (SELECT id FROM users WHERE full_name = 'Павлов Никита Максимович');

UPDATE tickets SET user_id = @new_id WHERE user_id = @old_id;
UPDATE tickets SET assigned_to = @new_id WHERE assigned_to = @old_id;
UPDATE messages SET sender_id = @new_id WHERE sender_id = @old_id;
UPDATE duty_hours SET it_user_id = @new_id WHERE it_user_id = @old_id;
UPDATE push_subscriptions SET user_id = @new_id WHERE user_id = @old_id;

DELETE FROM users WHERE id = @old_id;
UPDATE users SET role = 'it', telegram_id = 98303100, badge_color = 'blue' WHERE id = @new_id;
