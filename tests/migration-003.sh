#!/usr/bin/env bash
#
# Comprueba la migración 003 (Mejora 5, administradores) sin tocar la base de datos real: crea dos bases de
# prueba,
#   migration_old  -> 01-schema.sql SIN la tabla admins (el estado anterior), con 3 usuarios
#   migration_new  -> el esquema de instalación nueva (database/01-schema.sql)
# aplica la migración a la primera y comprueba que la tabla nueva queda idéntica a la de una instalación
# nueva, que los usuarios no se tocan y que aplicarla dos veces falla sin cambiar nada. Usa root y borra las
# dos bases al terminar.
#
#   tests/migration-003.sh

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
printf 'DROP TABLE admins;\n' | root migration_old >/dev/null; [ $? = 0 ]; ok "se prepara el esquema anterior (sin la tabla admins)" $?
# El seed también inserta un administrador de desarrollo: aquí aún no existe la tabla admins (es lo que se migra),
# así que esa última sentencia falla a propósito y solo importan los 3 usuarios, que se insertan antes.
root migration_old < database/02-seed.sql >/dev/null 2>&1
USERS_BEFORE=$(printf 'SELECT id,name,email,active FROM users ORDER BY id;\n' | root migration_old)
[ "$(printf 'SELECT COUNT(*) FROM users;\n' | root migration_old)" = 3 ]; ok "hay 3 usuarios antes de migrar" $?

echo "== Aplicar la migración"
root migration_old < database/migrations/003-admins.sql >/dev/null; [ $? = 0 ]; ok "la migración se aplica sin error" $?
[ "$USERS_BEFORE" = "$(printf 'SELECT id,name,email,active FROM users ORDER BY id;\n' | root migration_old)" ]; ok "los usuarios no se tocan" $?
[ "$(printf 'SELECT COUNT(*) FROM admins;\n' | root migration_old)" = 0 ]; ok "no se crea ningún administrador (se crean con create-admin.php)" $?

echo "== La BD migrada y la instalación nueva son idénticas"
STRUCT="SELECT ORDINAL_POSITION, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, IFNULL(COLUMN_DEFAULT,'NULL'), IFNULL(COLLATION_NAME,'-') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='%s' AND TABLE_NAME='admins' ORDER BY ORDINAL_POSITION;"
IDX="SELECT INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME, NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='%s' AND TABLE_NAME='admins' ORDER BY INDEX_NAME, SEQ_IN_INDEX;"
A=$(printf "$STRUCT\n" migration_old | root); B=$(printf "$STRUCT\n" migration_new | root)
[ -n "$A" ] && [ "$A" = "$B" ]; ok "mismas columnas, tipos, nulos y collation" $?
A=$(printf "$IDX\n" migration_old | root); B=$(printf "$IDX\n" migration_new | root)
[ -n "$A" ] && [ "$A" = "$B" ]; ok "mismos índices (PRIMARY y unique_admin_email)" $?

echo "== La tabla se comporta como se espera"
HASH=$(docker compose exec -T php php -r 'echo password_hash("x-clave-de-prueba", PASSWORD_DEFAULT);')
printf "INSERT INTO admins (email, password_hash) VALUES ('a@example.com', '%s');\n" "$HASH" | root migration_old >/dev/null; [ $? = 0 ]; ok "admite un hash bcrypt" $?
[ "$(printf "SELECT LENGTH(password_hash) FROM admins;\n" | root migration_old)" = 60 ]; ok "...que mide 60 caracteres (y la columna admite hasta 255 para otros algoritmos)" $?
printf "INSERT INTO admins (email, password_hash) VALUES ('A@EXAMPLE.COM', 'x');\n" | root migration_old >/dev/null 2>&1; [ $? != 0 ]; ok "el correo es único, ignorando mayúsculas" $?
printf "INSERT INTO admins (email) VALUES ('b@example.com');\n" | root migration_old >/dev/null 2>&1; [ $? != 0 ]; ok "no se puede crear un administrador sin hash" $?
printf "SELECT 1 FROM users u JOIN admins a ON a.email = u.email LIMIT 1;\n" | root migration_old >/dev/null 2>&1; [ $? = 0 ]; ok "admins es una tabla aparte de users (ningún vínculo entre ellas)" $?

echo "== Aplicarla dos veces"
SNAP1=$(printf 'SHOW CREATE TABLE admins;\n' | root migration_old)
root migration_old < database/migrations/003-admins.sql >/dev/null 2>&1; [ $? != 0 ]; ok "la segunda vez falla (la tabla ya existe)" $?
[ "$SNAP1" = "$(printf 'SHOW CREATE TABLE admins;\n' | root migration_old)" ]; ok "...sin cambiar nada" $?

echo; echo "Resultado: $PASS correctas, $FAIL fallidas"
[ "$FAIL" = 0 ]
