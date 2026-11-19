-- Migracion 001 (Mejora 3): documento, fecha de nacimiento y rol.
--
-- Se aplica UNA sola vez sobre una BD que ya tiene datos (el script de docker-entrypoint-initdb.d solo
-- corre al crear el volumen). No es repetible: MySQL no tiene ADD COLUMN IF NOT EXISTS, y una segunda
-- ejecucion falla con "Duplicate column name" sin tocar nada (el ALTER es atomico en InnoDB 8).
-- 01-schema.sql lleva las mismas columnas para las instalaciones nuevas: hay que mantener AMBOS.
--
--   docker compose exec -T db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" crud_usuarios' < database/migrations/001-more-fields.sql
--
-- Usuarios existentes: document y birth_date quedan en NULL (no se conocen) y role en 'user'.
-- document es UNIQUE pero admite NULL: MySQL permite varios NULL en un indice UNIQUE (NULL no es igual a
-- NULL); por eso la aplicacion guarda NULL y nunca '' cuando el documento viene vacio.
ALTER TABLE users
    ADD COLUMN document VARCHAR(20) DEFAULT NULL AFTER phone,
    ADD COLUMN birth_date DATE DEFAULT NULL AFTER document,
    ADD COLUMN role ENUM('user', 'editor', 'admin') NOT NULL DEFAULT 'user' AFTER birth_date,
    ADD UNIQUE KEY unique_document (document);
