-- Migracion 004 (Extra: acceso de administradores): la contrasena pasa a la tabla users.
--
-- Hasta ahora quien iniciaba sesion vivia en una tabla aparte (admins) y el rol de users era solo una etiqueta.
-- Ahora un usuario con rol «admin» y una contrasena puede iniciar sesion: una sola fuente de verdad (cambiar el
-- correo, el rol o eliminar a la persona cambia tambien su acceso) y los administradores se crean desde la
-- propia pantalla de usuarios. La tabla admins desaparece.
--
-- Se aplica UNA sola vez (la segunda falla con "Duplicate column name" sin cambiar nada; no hay vuelta atras
-- automatica: haz una copia antes si la BD tiene datos que importan).
--   docker compose exec -T db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" crud_usuarios' < database/migrations/004-user-passwords.sql
--
-- 1) password_hash: NULL = sin acceso. Es el hash bcrypt, nunca la contrasena.
-- 2) Cada administrador de la tabla admins se convierte en un usuario con rol admin y su mismo hash. Si su correo ya
--    es el de un usuario, ese usuario se PROMUEVE (rol admin, activo y con ese hash); si no, se crea uno con
--    nombre «Administrador» y como apellido la parte del correo anterior a la arroba.
-- 3) Se borra admins.
ALTER TABLE users
    ADD COLUMN password_hash VARCHAR(255) DEFAULT NULL AFTER role;

INSERT INTO users (name, last_name, email, role, password_hash, created_at)
SELECT 'Administrador', LEFT(SUBSTRING_INDEX(email, '@', 1), 100), email, 'admin', password_hash, created_at
FROM admins
ON DUPLICATE KEY UPDATE role = 'admin', active = 1, password_hash = VALUES(password_hash);

DROP TABLE admins;
