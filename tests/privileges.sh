#!/usr/bin/env bash
#
# Pruebas de los permisos de la base de datos (Mejora 7, mínimo privilegio): qué PUEDE y qué NO PUEDE hacer el
# usuario de la aplicación y el de migraciones, y que database/03-users.sh los deja así, también con claves raras y
# partiendo de un usuario que tenía todos los privilegios. Hablan directamente con MySQL como cada usuario; no
# tocan los datos (lo único que escriben se borra al terminar).
#
#   tests/privileges.sh
#
# Requisitos: la aplicación levantada. Sale con 0 si todo pasa y con 1 si algo falla.

set -u
cd "$(dirname "$0")/.." || exit 2
PASS=0; FAIL=0
ok() { if [ "$2" = 0 ]; then PASS=$((PASS + 1)); echo "  ok    $1"; else FAIL=$((FAIL + 1)); echo "  FALLO $1"; fi; }
section() { printf '\n== %s\n' "$1"; }

# Cada función ejecuta SQL (por stdin) como un usuario y devuelve la salida con los errores incluidos.
app()  { docker compose exec -T db sh -c 'mysql -N -u"$DB_USERNAME" -p"$DB_PASSWORD" "$MYSQL_DATABASE" 2>&1' | grep -v "Using a password"; }
mig()  { docker compose exec -T db sh -c 'mysql -N -u"$DB_MIGRATION_USERNAME" -p"$DB_MIGRATION_PASSWORD" "$MYSQL_DATABASE" 2>&1' | grep -v "Using a password"; }
root() { docker compose exec -T db sh -c 'mysql -N -uroot -p"$MYSQL_ROOT_PASSWORD" 2>&1' | grep -v "Using a password"; }
# denegado "<sql>": MySQL contesta con un error de permisos (1044/1045/1142/1143/1227/1229/1290).
denied_app() { local out; out=$(printf '%s\n' "$1" | app); printf '%s' "$out" | grep -qE 'ERROR (1044|1045|1142|1143|1227|1229)'; }
denied_mig() { local out; out=$(printf '%s\n' "$1" | mig); printf '%s' "$out" | grep -qE 'ERROR (1044|1045|1142|1143|1227|1229)'; }
works_app()  { local out; out=$(printf '%s\n' "$1" | app); ! printf '%s' "$out" | grep -q 'ERROR'; }
works_mig()  { local out; out=$(printf '%s\n' "$1" | mig); ! printf '%s' "$out" | grep -q 'ERROR'; }

APP_USER=$(docker compose exec -T db sh -c 'printf %s "$DB_USERNAME"')
MIG_USER=$(docker compose exec -T db sh -c 'printf %s "$DB_MIGRATION_USERNAME"')
# (se excluyen las filas «priv-» que crean estas mismas pruebas)
REALUSERS="SELECT COUNT(*) FROM users WHERE email NOT LIKE 'priv-%';"
USERS_BEFORE=$(printf '%s\n' "$REALUSERS" | mig)
cleanup() { printf "DELETE FROM users WHERE email LIKE 'priv-%%@example.com'; DROP TABLE IF EXISTS m7_scratch;\n" | mig >/dev/null 2>&1; printf "DROP USER IF EXISTS 'm7_tmp'@'%%';\n" | root >/dev/null 2>&1; }
trap cleanup EXIT
cleanup

section "El usuario de la aplicación ($APP_USER): sus permisos, exactamente"
EXPECTED=$(printf '%s\n' \
  "GRANT USAGE ON *.* TO \`$APP_USER\`@\`%\`" \
  "GRANT SELECT, INSERT, UPDATE ON \`crud_usuarios\`.\`users\` TO \`$APP_USER\`@\`%\`" | sort)
ACTUAL=$(printf 'SHOW GRANTS FOR "%s"@"%%";\n' "$APP_USER" | root | sort)
[ "$EXPECTED" = "$ACTUAL" ]; ok "SHOW GRANTS es exactamente: users SELECT/INSERT/UPDATE; nada más (desde la migración 004 no hay tabla admins)" $?
[ "$(printf 'SHOW GRANTS FOR "%s"@"%%";\n' "$APP_USER" | root | grep -ciE 'ALL PRIVILEGES|WITH GRANT OPTION|DELETE|DROP|ALTER|CREATE')" = 0 ]; ok "no tiene ALL, GRANT OPTION, DELETE, DROP, ALTER ni CREATE" $?

section "Lo que la aplicación SÍ necesita (si falla alguna, la aplicación se rompería)"
works_app 'SELECT COUNT(*) FROM users;'; ok "SELECT en users" $?
works_app "SELECT id, email, password_hash FROM users WHERE role = 'admin' AND active = 1 AND password_hash IS NOT NULL LIMIT 1;"; ok "SELECT de quien puede iniciar sesión (users.password_hash)" $?
works_app "INSERT INTO users (name, last_name, email) VALUES ('Priv', 'Prueba', 'priv-1@example.com');"; ok "INSERT en users (crear)" $?
works_app "UPDATE users SET active = 0 WHERE email = 'priv-1@example.com';"; ok "UPDATE en users (editar, eliminar lógicamente y restaurar)" $?
works_app 'UPDATE users SET password_hash = password_hash WHERE id = 0;'; ok "UPDATE de users.password_hash (recalcular el hash al entrar, o cambiar la contraseña desde la pantalla de usuarios)" $?
works_app "INSERT INTO users (name, last_name, email, role, password_hash) VALUES ('Priv', 'Admin', 'priv-2@example.com', 'admin', 'x');"; ok "INSERT de un usuario con rol admin y hash (registrar administradores desde la pantalla: la aplicación PUEDE dar acceso, es lo que pide esa función)" $?
works_app 'SELECT VERSION();'; ok "SELECT VERSION() (status.php)" $?

section "Lo que la aplicación NO puede hacer (prueba negativa del plan)"
denied_app 'DROP TABLE users;'; ok "DROP TABLE users" $?
denied_app 'SELECT 1 FROM admins LIMIT 1;'; ok "la tabla admins ya no existe (no hay a dónde mirar)" $?
denied_app 'ALTER TABLE users ADD COLUMN zz INT;'; ok "ALTER TABLE users" $?
denied_app 'TRUNCATE TABLE users;'; ok "TRUNCATE TABLE users" $?
denied_app 'DELETE FROM users WHERE id = 0;'; ok "DELETE FROM users (la aplicación elimina con UPDATE: borrado lógico)" $?
denied_app 'CREATE TABLE zz (id INT);'; ok "CREATE TABLE" $?
denied_app 'CREATE TEMPORARY TABLE zz (id INT);'; ok "CREATE TEMPORARY TABLE" $?
denied_app 'CREATE INDEX zz ON users (name);'; ok "CREATE INDEX" $?
denied_app 'RENAME TABLE users TO users_old;'; ok "RENAME TABLE" $?
denied_app 'CREATE VIEW zz AS SELECT 1;'; ok "CREATE VIEW" $?
denied_app 'CREATE DATABASE zz;'; ok "CREATE DATABASE" $?
denied_app 'DROP DATABASE crud_usuarios;'; ok "DROP DATABASE crud_usuarios" $?
denied_app "CREATE USER 'zz'@'%' IDENTIFIED BY 'x';"; ok "CREATE USER" $?
denied_app "GRANT SELECT ON crud_usuarios.* TO '$APP_USER'@'%';"; ok "GRANT (no puede ampliarse los permisos)" $?
denied_app 'SELECT COUNT(*) FROM mysql.user;'; ok "leer mysql.user" $?
denied_app "SELECT 1 INTO OUTFILE '/tmp/m7_out.txt';"; ok "SELECT ... INTO OUTFILE (privilegio FILE)" $?
[ "$(printf "SELECT IFNULL(LOAD_FILE('/etc/passwd'), 'NULL');\n" | app)" = NULL ]; ok "LOAD_FILE('/etc/passwd') devuelve NULL (sin FILE no lee archivos del servidor)" $?
denied_app 'SET GLOBAL max_connections = 1;'; ok "SET GLOBAL" $?
denied_app 'SHUTDOWN;'; ok "SHUTDOWN" $?
denied_app 'USE mysql;'; ok "USE mysql" $?
denied_app 'SELECT * FROM sys.version;'; ok "leer la base sys" $?
[ "$(printf 'SHOW DATABASES;\n' | app | sort | tr '\n' ' ')" = "crud_usuarios information_schema performance_schema " ] || [ "$(printf 'SHOW DATABASES;\n' | app | sort | tr '\n' ' ')" = "crud_usuarios information_schema " ]; ok "SHOW DATABASES solo enseña crud_usuarios (y las de información)" $?
[ "$USERS_BEFORE" = "$(printf '%s\n' "$REALUSERS" | mig)" ]; ok "tras todos los intentos las tablas siguen ahí con los mismos datos" $?

section "El usuario de migraciones ($MIG_USER)"
GRANTS=$(printf 'SHOW GRANTS FOR "%s"@"%%";\n' "$MIG_USER" | root)
printf '%s' "$GRANTS" | grep -qF "GRANT ALL PRIVILEGES ON \`crud_usuarios\`.* TO \`$MIG_USER\`@\`%\`"; ok "tiene todos los privilegios sobre crud_usuarios..." $?
! printf '%s' "$GRANTS" | grep -qiE 'GRANT OPTION|ON \*\.\*.*(ALL|SELECT|INSERT|FILE|SUPER|CREATE USER)'; ok "...pero no GRANT OPTION ni nada global (ni FILE, SUPER, CREATE USER)" $?
works_mig 'CREATE TABLE m7_scratch (id INT); ALTER TABLE m7_scratch ADD COLUMN x INT; INSERT INTO m7_scratch VALUES (1, 2); DELETE FROM m7_scratch; TRUNCATE m7_scratch; DROP TABLE m7_scratch;'; ok "CREATE, ALTER, INSERT, DELETE, TRUNCATE y DROP en la base de la aplicación (lo que hace falta para migrar)" $?
works_mig 'CREATE TEMPORARY TABLE zz (id INT);'; ok "CREATE TEMPORARY TABLE (las pruebas de integración)" $?
denied_mig 'CREATE DATABASE zz;'; ok "CREATE DATABASE (no puede crear otras bases)" $?
denied_mig "CREATE USER 'zz'@'%' IDENTIFIED BY 'x';"; ok "CREATE USER" $?
denied_mig "GRANT SELECT ON crud_usuarios.users TO '$APP_USER'@'%';"; ok "GRANT (tampoco puede dar permisos: no tiene GRANT OPTION)" $?
denied_mig 'SELECT COUNT(*) FROM mysql.user;'; ok "leer mysql.user" $?
denied_mig 'SET GLOBAL max_connections = 1;'; ok "SET GLOBAL" $?
denied_mig 'USE mysql;'; ok "USE mysql" $?

section "Ninguna cuenta sin contraseña"
[ "$(printf "SELECT COUNT(*) FROM mysql.user WHERE authentication_string = '' AND account_locked = 'N' AND plugin <> 'auth_socket';\n" | root)" = 0 ]; ok "ninguna cuenta activa tiene la contraseña vacía" $?
[ "$(printf "SELECT COUNT(*) FROM mysql.user WHERE user = '';\n" | root)" = 0 ]; ok "no hay usuarios anónimos" $?
! docker compose ps db --format '{{.Ports}}' | grep -q -- '->'; ok "el puerto de MySQL no está publicado en el equipo: solo se llega desde la red de Docker" $?
[ "$(docker compose exec -T php sh -c 'printenv DB_ROOT_PASSWORD DB_MIGRATION_PASSWORD DB_MIGRATION_USERNAME MYSQL_ROOT_PASSWORD' | wc -l)" = 0 ]; ok "el contenedor php NO conoce ni la clave de root ni la del usuario de migraciones" $?

section "database/03-users.sh: idempotente y robusto"
BEFORE=$(printf 'SHOW GRANTS FOR "%s"@"%%";\n' "$APP_USER" | root; printf 'SHOW GRANTS FOR "%s"@"%%";\n' "$MIG_USER" | root)
docker compose exec -T db bash /docker-entrypoint-initdb.d/03-users.sh >/dev/null 2>&1; ok "se puede volver a ejecutar" $?
[ "$BEFORE" = "$(printf 'SHOW GRANTS FOR "%s"@"%%";\n' "$APP_USER" | root; printf 'SHOW GRANTS FOR "%s"@"%%";\n' "$MIG_USER" | root)" ]; ok "...y los permisos quedan igual" $?
printf "GRANT ALL PRIVILEGES ON crud_usuarios.* TO '%s'@'%%';\n" "$APP_USER" | root >/dev/null
works_app 'CREATE TABLE m7_scratch (id INT); DROP TABLE m7_scratch;'; ok "(se le da ALL a la aplicación a mano: ahora SÍ puede hacer DROP; es el estado del usuario que creaba la imagen)" $?
docker compose exec -T db bash /docker-entrypoint-initdb.d/03-users.sh >/dev/null 2>&1
denied_app 'CREATE TABLE m7_scratch (id INT);'; ok "al re-ejecutar el script se revoca todo y vuelve el mínimo (un usuario existente con ALL se corrige)" $?
WEIRD="p'a\\s\"s;x\$y\`z"
docker compose exec -T -e DB_USERNAME=m7_tmp -e "DB_PASSWORD=$WEIRD" db bash /docker-entrypoint-initdb.d/03-users.sh >/dev/null 2>&1; ok "una clave con comilla simple, doble, barra invertida, punto y coma, \$ y comilla inversa se acepta" $?
printf 'SELECT 1;\n' | docker compose exec -T -e MYSQL_PWD="$WEIRD" db sh -c 'mysql -N -um7_tmp "$MYSQL_DATABASE" 2>&1' | grep -qx 1; ok "...y esa clave funciona tal cual para entrar" $?
printf 'SELECT COUNT(*) FROM users;\n' | docker compose exec -T -e MYSQL_PWD="$WEIRD" db sh -c 'mysql -N -um7_tmp "$MYSQL_DATABASE" 2>&1' | grep -qE '^[0-9]+$'; ok "...con los permisos de la aplicación" $?
printf "DROP USER 'm7_tmp'@'%%';\n" | root >/dev/null
docker compose exec -T -e "DB_USERNAME=x'; DROP DATABASE crud_usuarios; --" db bash /docker-entrypoint-initdb.d/03-users.sh >/dev/null 2>&1; [ $? != 0 ]; ok "un nombre de usuario con comillas se rechaza (no se inyecta SQL por el nombre)" $?
docker compose exec -T -e DB_USERNAME=root db bash /docker-entrypoint-initdb.d/03-users.sh >/dev/null 2>&1; [ $? != 0 ]; ok "DB_USERNAME=root se rechaza" $?
docker compose exec -T -e "DB_USERNAME=$MIG_USER" db bash /docker-entrypoint-initdb.d/03-users.sh >/dev/null 2>&1; [ $? != 0 ]; ok "el mismo usuario para la aplicación y las migraciones se rechaza" $?
[ "$USERS_BEFORE" = "$(printf '%s\n' "$REALUSERS" | mig)" ]; ok "los datos siguen intactos" $?

echo; echo "Resultado: $PASS correctas, $FAIL fallidas"
[ "$FAIL" = 0 ]
