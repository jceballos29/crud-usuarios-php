#!/usr/bin/env bash
#
# Comprueba la migración 004 (acceso de administradores: la contraseña pasa a users y la tabla admins desaparece)
# sin tocar la base de datos real. Crea dos bases de prueba:
#   migration_old  -> el estado ANTERIOR (users sin password_hash + tabla admins) con usuarios y 3 administradores:
#                     uno con correo nuevo, uno cuyo correo YA es el de un usuario, y uno que además está ELIMINADO
#   migration_new  -> el esquema de instalación nueva (database/01-schema.sql)
# Aplica la migración y comprueba que cada administrador conserva su hash y puede entrar, que los usuarios existentes
# no cambian, que admins desaparece, que la estructura de users acaba igual que la de una instalación nueva y que
# aplicarla dos veces falla sin cambiar nada. Usa root y borra las dos bases al terminar.
#
#   tests/migration-004.sh

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
printf 'ALTER TABLE users DROP COLUMN password_hash;\n' | root migration_old >/dev/null
root migration_old < database/migrations/003-admins.sql >/dev/null; [ $? = 0 ]; ok "se prepara el estado anterior (users sin password_hash y con la tabla admins)" $?

H1=$(docker compose exec -T php php -r 'echo password_hash("clave-uno-larga", PASSWORD_DEFAULT);')
H2=$(docker compose exec -T php php -r 'echo password_hash("clave-dos-larga", PASSWORD_DEFAULT);')
H3=$(docker compose exec -T php php -r 'echo password_hash("clave-tres-larga", PASSWORD_DEFAULT);')
root migration_old <<SQL >/dev/null
SET NAMES utf8mb4;
INSERT INTO users (name, last_name, email, role, active, created_at) VALUES
    ('Laura', 'Gómez', 'laura.gomez@example.com', 'user', 1, '2026-01-02 03:04:05'),
    ('Pedro', 'Pérez', 'pedro@example.com', 'editor', 1, '2026-02-03 04:05:06'),
    ('Sara', 'Ruiz', 'sara@example.com', 'user', 0, '2026-03-04 05:06:07');
INSERT INTO admins (email, password_hash, created_at) VALUES
    ('nuevo.admin@example.com', '$H1', '2026-04-01 00:00:00'),
    ('pedro@example.com', '$H2', '2026-04-02 00:00:00'),
    ('sara@example.com', '$H3', '2026-04-03 00:00:00');
SQL
ROWS="SELECT id,name,last_name,email,role,active,created_at FROM users WHERE email IN ('laura.gomez@example.com') ORDER BY id;"
BEFORE=$(printf '%s\n' "$ROWS" | root migration_old)

echo "== Aplicar la migración"
root migration_old < database/migrations/004-user-passwords.sql >/dev/null; [ $? = 0 ]; ok "la migración se aplica sin error" $?
[ "$BEFORE" = "$(printf '%s\n' "$ROWS" | root migration_old)" ]; ok "un usuario que no era administrador no cambia (ni el rol, ni los datos, ni la fecha)" $?
[ "$(printf 'SELECT password_hash IS NULL FROM users WHERE email="laura.gomez@example.com";\n' | root migration_old)" = 1 ]; ok "...y queda sin acceso (password_hash NULL)" $?
[ "$(printf 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA="migration_old" AND TABLE_NAME="admins";\n' | root)" = 0 ]; ok "la tabla admins desaparece" $?

echo "== Cada administrador conserva su acceso"
[ "$(printf 'SELECT CONCAT(name,"|",last_name,"|",role,"|",active,"|",created_at,"|",password_hash="%s") FROM users WHERE email="nuevo.admin@example.com";\n' "$H1" | root migration_old)" = "Administrador|nuevo.admin|admin|1|2026-04-01 00:00:00|1" ]; ok "un administrador con correo nuevo se CREA como «Administrador nuevo.admin», rol admin, con su mismo hash y su fecha" $?
[ "$(printf 'SELECT CONCAT(name,"|",last_name,"|",role,"|",active,"|",password_hash="%s") FROM users WHERE email="pedro@example.com";\n' "$H2" | root migration_old)" = "Pedro|Pérez|admin|1|1" ]; ok "uno cuyo correo ya era un usuario lo PROMUEVE (conserva su nombre y apellido; rol admin; su hash)" $?
[ "$(printf 'SELECT COUNT(*) FROM users WHERE email="pedro@example.com";\n' | root migration_old)" = 1 ]; ok "...sin duplicar al usuario (el correo es único)" $?
[ "$(printf 'SELECT CONCAT(role,"|",active,"|",password_hash="%s") FROM users WHERE email="sara@example.com";\n' "$H3" | root migration_old)" = "admin|1|1" ]; ok "uno que era un usuario ELIMINADO queda activo: tenía acceso hasta ahora y no se le quita" $?
for pair in "nuevo.admin@example.com:clave-uno-larga" "pedro@example.com:clave-dos-larga" "sara@example.com:clave-tres-larga"; do
    email=${pair%%:*}; pw=${pair##*:}
    HASH=$(printf 'SELECT password_hash FROM users WHERE email="%s";\n' "$email" | root migration_old)
    docker compose exec -T php php -r 'exit(password_verify($argv[1], $argv[2]) ? 0 : 1);' "$pw" "$HASH"; ok "$email entra con su contraseña de siempre (el hash viaja intacto)" $?
done
[ "$(printf 'SELECT COUNT(*) FROM users WHERE role="admin" AND active=1 AND password_hash IS NOT NULL;\n' | root migration_old)" = 3 ]; ok "hay exactamente 3 usuarios que pueden iniciar sesión (los 3 administradores)" $?

echo "== La BD migrada y la instalación nueva son idénticas"
STRUCT="SELECT ORDINAL_POSITION, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, IFNULL(COLUMN_DEFAULT,'NULL'), IFNULL(COLLATION_NAME,'-') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='%s' AND TABLE_NAME='users' ORDER BY ORDINAL_POSITION;"
IDX="SELECT INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME, NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='%s' AND TABLE_NAME='users' ORDER BY INDEX_NAME, SEQ_IN_INDEX;"
A=$(printf "$STRUCT\n" migration_old | root); B=$(printf "$STRUCT\n" migration_new | root)
[ -n "$A" ] && [ "$A" = "$B" ]; ok "mismas columnas, tipos, valores por defecto y orden en users" $?
A=$(printf "$IDX\n" migration_old | root); B=$(printf "$IDX\n" migration_new | root)
[ -n "$A" ] && [ "$A" = "$B" ]; ok "mismos índices en users" $?
[ "$(printf 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA="migration_new" AND TABLE_NAME="admins";\n' | root)" = 0 ]; ok "y una instalación nueva tampoco tiene admins" $?

echo "== Sin administradores (instalación vacía) y aplicarla dos veces"
SNAP=$(printf 'SHOW CREATE TABLE users;\n' | root migration_old)
root migration_old < database/migrations/004-user-passwords.sql >/dev/null 2>&1; [ $? != 0 ]; ok "la segunda vez falla (Duplicate column name)" $?
[ "$SNAP" = "$(printf 'SHOW CREATE TABLE users;\n' | root migration_old)" ]; ok "...sin cambiar nada" $?
printf 'CREATE DATABASE migration_new2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n' | root >/dev/null
root migration_new2 < database/01-schema.sql >/dev/null
printf 'ALTER TABLE users DROP COLUMN password_hash;\n' | root migration_new2 >/dev/null; root migration_new2 < database/migrations/003-admins.sql >/dev/null
root migration_new2 < database/migrations/004-user-passwords.sql >/dev/null 2>&1; [ $? = 0 ]; ok "con la tabla admins VACÍA la migración también se aplica (no crea nada)" $?
[ "$(printf 'SELECT COUNT(*) FROM users;\n' | root migration_new2)" = 0 ]; ok "...y no inventa usuarios" $?
printf 'DROP DATABASE migration_new2;\n' | root >/dev/null

echo; echo "Resultado: $PASS correctas, $FAIL fallidas"
[ "$FAIL" = 0 ]
