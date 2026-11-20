#!/usr/bin/env bash
#
# Comprueba la migración 003 (Mejora 5, administradores) sin tocar la base de datos real: crea dos bases de
# prueba,
#   migration_old  -> el estado ANTERIOR a la 003 (users sin password_hash y sin tabla admins), con 3 usuarios
# aplica la migración y comprueba que la tabla admins queda como se espera, que los usuarios no se tocan y que
# aplicarla dos veces falla sin cambiar nada. (Desde la migración 004 la tabla admins desaparece y database/01-schema.sql
# ya no la lleva, así que aquí no se compara con una instalación nueva: se comprueba contra la definición esperada.) Usa root y borra las
# dos bases al terminar.
#
#   tests/migration-003.sh

set -u
cd "$(dirname "$0")/.." || exit 2
PASS=0; FAIL=0
root() { docker compose exec -T db sh -c 'mysql -N --default-character-set=utf8mb4 -uroot -p"$MYSQL_ROOT_PASSWORD" "$@"' sh "$@" 2>/dev/null; }
ok() { if [ "$2" = 0 ]; then PASS=$((PASS + 1)); echo "  ok    $1"; else FAIL=$((FAIL + 1)); echo "  FALLO $1"; fi; }
cleanup() { printf 'DROP DATABASE IF EXISTS migration_old;\n' | root >/dev/null; }
trap cleanup EXIT
cleanup

printf 'CREATE DATABASE migration_old CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n' | root >/dev/null
root migration_old < database/01-schema.sql >/dev/null
printf 'ALTER TABLE users DROP COLUMN password_hash;\n' | root migration_old >/dev/null; [ $? = 0 ]; ok "se prepara el esquema anterior (sin password_hash ni admins)" $?
root migration_old < database/02-seed.sql >/dev/null 2>&1   # el administrador del seed falla a propósito (no hay password_hash): solo importan los 3 usuarios
USERS_BEFORE=$(printf 'SELECT id,name,email,active FROM users ORDER BY id;\n' | root migration_old)
[ "$(printf 'SELECT COUNT(*) FROM users;\n' | root migration_old)" = 3 ]; ok "hay 3 usuarios antes de migrar" $?

echo "== Aplicar la migración"
root migration_old < database/migrations/003-admins.sql >/dev/null; [ $? = 0 ]; ok "la migración se aplica sin error" $?
[ "$USERS_BEFORE" = "$(printf 'SELECT id,name,email,active FROM users ORDER BY id;\n' | root migration_old)" ]; ok "los usuarios no se tocan" $?
[ "$(printf 'SELECT COUNT(*) FROM admins;\n' | root migration_old)" = 0 ]; ok "no se crea ningún administrador (se crean con create-admin.php)" $?
[ "$(printf "SELECT GROUP_CONCAT(CONCAT(COLUMN_NAME, ':', COLUMN_TYPE, ':', IS_NULLABLE) ORDER BY ORDINAL_POSITION SEPARATOR ' ') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='migration_old' AND TABLE_NAME='admins';\n" | root)" = "id:int unsigned:NO email:varchar(254):NO password_hash:varchar(255):NO created_at:datetime:NO" ]; ok "columnas: id, email (254), password_hash (255, NOT NULL) y created_at" $?
[ "$(printf "SELECT GROUP_CONCAT(INDEX_NAME ORDER BY INDEX_NAME) FROM (SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='migration_old' AND TABLE_NAME='admins') t;\n" | root)" = "PRIMARY,unique_admin_email" ]; ok "índices: PRIMARY y unique_admin_email" $?

echo "== La tabla se comporta como se espera"
HASH=$(docker compose exec -T php php -r 'echo password_hash("x-clave-de-prueba", PASSWORD_DEFAULT);')
printf "INSERT INTO admins (email, password_hash) VALUES ('a@example.com', '%s');\n" "$HASH" | root migration_old >/dev/null; [ $? = 0 ]; ok "admite un hash bcrypt" $?
[ "$(printf "SELECT LENGTH(password_hash) FROM admins;\n" | root migration_old)" = 60 ]; ok "...que mide 60 caracteres (y la columna admite hasta 255 para otros algoritmos)" $?
printf "INSERT INTO admins (email, password_hash) VALUES ('A@EXAMPLE.COM', 'x');\n" | root migration_old >/dev/null 2>&1; [ $? != 0 ]; ok "el correo es único, ignorando mayúsculas" $?
printf "INSERT INTO admins (email) VALUES ('b@example.com');\n" | root migration_old >/dev/null 2>&1; [ $? != 0 ]; ok "no se puede crear un administrador sin hash" $?

echo "== Aplicarla dos veces"
SNAP1=$(printf 'SHOW CREATE TABLE admins;\n' | root migration_old)
root migration_old < database/migrations/003-admins.sql >/dev/null 2>&1; [ $? != 0 ]; ok "la segunda vez falla (la tabla ya existe)" $?
[ "$SNAP1" = "$(printf 'SHOW CREATE TABLE admins;\n' | root migration_old)" ]; ok "...sin cambiar nada" $?

echo; echo "Resultado: $PASS correctas, $FAIL fallidas"
[ "$FAIL" = 0 ]
