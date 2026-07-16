-- =====================================================================
--  Назначает роль IT-отдела двум сотрудникам, которые уже есть в общем
--  списке (db/seed_employees.sql), по их ФИО — вместо того чтобы заводить
--  для них отдельные, дублирующие записи (ФИО в таблице users уникально).
--
--  ВАЖНО: выполнять ПОСЛЕ db/seed_employees.sql, иначе строки для этих
--  ФИО ещё не будет и INSERT ... ON DUPLICATE KEY UPDATE ниже создаст
--  отдельную новую запись вместо обновления существующей.
-- =====================================================================

INSERT INTO users (full_name, password_hash, must_change_password, role, badge_color)
VALUES ('Вавилов Александр Игоревич', '$2y$12$2.vXKYPquFojxB.ozZ4SGuKcAdyQZKbXUKzMOSWTsKb4YNQhwZgYW', 1, 'it', 'purple')
ON DUPLICATE KEY UPDATE role = 'it', badge_color = VALUES(badge_color);

INSERT INTO users (full_name, password_hash, must_change_password, role, badge_color)
VALUES ('Павлов Никита Максимович', '$2y$12$2.vXKYPquFojxB.ozZ4SGuKcAdyQZKbXUKzMOSWTsKb4YNQhwZgYW', 1, 'it', 'blue')
ON DUPLICATE KEY UPDATE role = 'it', badge_color = VALUES(badge_color);
