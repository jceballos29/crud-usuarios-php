#!/usr/bin/env bash
#
# Comprueba la migración 002 (Mejora 4, borrado lógico) sin tocar la base de datos real: crea dos bases
# de prueba,
#   migration_old  -> el esquema ANTERIOR (01-schema.sql sin la columna active ni su índice) con 3 usuarios,
#                     al que se aplica la migración
#   migration_new  -> el esquema de instalación nueva (database/01-schema.sql)
# y comprueba que los usuarios antiguos quedan activos y con sus datos, que las dos bases acaban con las
# MISMAS columnas e índices y que aplicarla dos veces falla sin cambiar nada. Usa root y borra las bases.
#
#   tests/migration-002.sh

set -u
cd "$(dirname "$0")/.." || exit 2
PASS=0; FAIL=0
root() { docker compose exec -T db sh -c 'mysql -N --default-character-set=utf8mb4 -uroot -p"$MYSQL_ROOT_PASSWORD" "$@"' sh "$@" 2>/dev/null; }
ok() { if [ "$2" = 0 ]; then PASS=$((PASS + 1)); echo "  ok    $1"; else FAIL=$((FAIL + 1)); echo "  FALLO $1"; fi; }
cleanup() { printf 'DROP DATABASE IF EXISTS migration_old; DROP DATABASE IF EXISTS migration_new;\n' | root >/dev/null; }
trap cleanup EXIT
cleanup

printf 'CREATE DATABASE migration_old CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE DATABASE migration_new CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n' | root >/dev/null
root migration_new < database/01-schema.sql >/dev/null
root migration_old < database/01-schema.sql >/dev/null
printf 'ALTER TABLE users DROP KEY idx_active_id, DROP COLUMN active, DROP COLUMN password_hash;\n' | root migration_old >/dev/null; [ $? = 0 ]; ok "se prepara el esquema anterior (sin active, su índice ni password_hash)" $?
root migration_old <<'SQL' >/dev/null
SET NAMES utf8mb4;
INSERT INTO users (name, last_name, email, phone, document, birth_date, role, created_at) VALUES
    ('Laura', 'Gómez', 'laura.gomez@example.com', '3001234567', 'AB-12345', '1990-05-17', 'admin', '2026-01-02 03:04:05'),
    ('Andrés', 'Martínez', 'andres.martinez@example.com', NULL, NULL, NULL, 'editor', '2026-02-03 04:05:06'),
    ('Valentina', 'Rojas', 'valentina.rojas@example.com', NULL, NULL, NULL, 'user', '2026-03-04 05:06:07');
SQL
ROWS="SELECT id,name,last_name,email,IFNULL(phone,'NULL'),IFNULL(document,'NULL'),IFNULL(birth_date,'NULL'),role,created_at FROM users ORDER BY id;"
BEFORE=$(printf '%s\n' "$ROWS" | root migration_old)

echo "== Aplicar la migración sobre una BD con datos"
root migration_old < database/migrations/002-active.sql >/dev/null; [ $? = 0 ]; ok "la migración se aplica sin error" $?
[ "$BEFORE" = "$(printf '%s\n' "$ROWS" | root migration_old)" ]; ok "los 3 usuarios conservan todos sus datos" $?
[ "$(printf 'SELECT COUNT(*) FROM users WHERE active = 1;\n' | root migration_old)" = 3 ]; ok "los 3 quedan activos (siguen visibles)" $?

echo "== La BD migrada y la instalación nueva son idénticas"
# En la historia real la 004 (password_hash) llega DESPUÉS de la 002: se aplica su ALTER para comparar el esquema final.
printf 'ALTER TABLE users ADD COLUMN password_hash VARCHAR(255) DEFAULT NULL AFTER role;\n' | root migration_old >/dev/null
STRUCT="SELECT ORDINAL_POSITION, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, IFNULL(COLUMN_DEFAULT,'NULL'), IFNULL(COLLATION_NAME,'-') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='%s' AND TABLE_NAME='users' ORDER BY ORDINAL_POSITION;"
IDX="SELECT INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME, NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='%s' AND TABLE_NAME='users' ORDER BY INDEX_NAME, SEQ_IN_INDEX;"
A=$(printf "$STRUCT\n" migration_old | root); B=$(printf "$STRUCT\n" migration_new | root)
[ -n "$A" ] && [ "$A" = "$B" ]; ok "mismas columnas, tipos, valores por defecto y orden (active va antes de created_at en las dos)" $?
A=$(printf "$IDX\n" migration_old | root); B=$(printf "$IDX\n" migration_new | root)
[ -n "$A" ] && [ "$A" = "$B" ]; ok "mismos índices (PRIMARY, unique_email, unique_document, idx_active_id)" $?
[ "$(printf 'SELECT GROUP_CONCAT(INDEX_NAME) FROM (SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA="migration_old" AND TABLE_NAME="users" AND NON_UNIQUE=0) t;\n' | root | tr ',' '\n' | sort | tr '\n' ',')" = "PRIMARY,unique_document,unique_email," ]; ok "los UNIQUE de email y documento no cambiaron (siguen cubriendo a los eliminados)" $?

echo "== Después de migrar"
printf 'INSERT INTO users (name,last_name,email) VALUES ("N","A","n@example.com");\n' | root migration_old >/dev/null; [ $? = 0 ]; ok "una fila nueva sin indicar active queda activa (DEFAULT 1)" $?
[ "$(printf 'SELECT active FROM users WHERE email="n@example.com";\n' | root migration_old)" = 1 ]; ok "...con active = 1" $?
printf 'UPDATE users SET active = 0 WHERE email="n@example.com";\n' | root migration_old >/dev/null; [ "$(printf 'SELECT COUNT(*) FROM users WHERE active=0;\n' | root migration_old)" = 1 ]; ok "el borrado lógico es un UPDATE y la fila sigue ahí" $?
printf 'INSERT INTO users (name,last_name,email) VALUES ("D","B","n@example.com");\n' | root migration_old >/dev/null 2>&1; [ $? != 0 ]; ok "el correo de un eliminado sigue ocupado (UNIQUE)" $?
printf 'UPDATE users SET active = 1 WHERE email="n@example.com";\n' | root migration_old >/dev/null 2>&1; [ $? = 0 ]; ok "restaurar no choca con ningún UNIQUE" $?
printf 'INSERT INTO users (name,last_name,email,active) VALUES ("X","Y","x@example.com",NULL);\n' | root migration_old >/dev/null 2>&1; [ $? != 0 ]; ok "active no admite NULL" $?

echo "== Aplicarla dos veces"
SNAP1=$(printf 'SHOW CREATE TABLE users;\n' | root migration_old)
root migration_old < database/migrations/002-active.sql >/dev/null 2>&1; [ $? != 0 ]; ok "la segunda vez falla (Duplicate column name)" $?
[ "$SNAP1" = "$(printf 'SHOW CREATE TABLE users;\n' | root migration_old)" ]; ok "...sin cambiar nada" $?

echo; echo "Resultado: $PASS correctas, $FAIL fallidas"
[ "$FAIL" = 0 ]
