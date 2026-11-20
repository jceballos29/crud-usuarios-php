#!/usr/bin/env bash
#
# Pruebas de humo de punta a punta: el CRUD completo contra la aplicación en marcha.
#
#   tests/smoke.sh                  # no toca los contenedores
#   tests/smoke.sh --with-db-stop   # además PARA y vuelve a levantar la BD para comprobar los 503
#
# Requisitos: la aplicación levantada (docker compose up -d), curl y Docker Compose. Usa el usuario de MIGRACIONES
# de la BD (DB_MIGRATION_*, ver database/03-users.sh) para preparar y limpiar datos.
# Variables opcionales: BASE_URL (por defecto http://localhost:APP_PORT) y ORIGIN (por defecto BASE_URL;
# debe coincidir con APP_ORIGIN).
#
# Solo crea y borra usuarios con el correo smoke-*@example.com: no toca los demás. Sale con 0 si todo
# pasa y con 1 si algo falla. Las pruebas de las funciones puras están en src/tests/unit.php.

set -u
cd "$(dirname "$0")/.." || exit 2

PORT="${APP_PORT:-$(grep -E '^APP_PORT=' .env 2>/dev/null | cut -d= -f2)}"
BASE="${BASE_URL:-http://localhost:${PORT:-8080}}"
ORIGIN="${ORIGIN:-$BASE}"
WITH_DB_STOP=0
[ "${1:-}" = "--with-db-stop" ] && WITH_DB_STOP=1

TMP="$(mktemp -d)"
PASS=0
FAIL=0

# --default-character-set=utf8mb4: el cliente mysql de la consola habla latin1 por defecto y mostraría las tildes rotas.
# sql() consulta la BD como el usuario de MIGRACIONES (M7): la aplicación ya no puede borrar filas ni cambiar el esquema,
# y esta prueba necesita preparar y limpiar datos (DELETE) y cambiar tablas (RENAME) por su cuenta.
sql() { printf '%s\n' "$1" | docker compose exec -T db sh -c 'mysql -N --default-character-set=utf8mb4 -u"$DB_MIGRATION_USERNAME" -p"$DB_MIGRATION_PASSWORD" "$MYSQL_DATABASE"' 2>/dev/null; }
cleanup() { sql "RENAME TABLE users_tmp TO users;" >/dev/null 2>&1; sql "DELETE FROM users WHERE email LIKE 'smoke-%@example.com';" >/dev/null 2>&1; rm -rf "$TMP"; }
trap cleanup EXIT

section() { printf '\n== %s\n' "$1"; }
pass() { PASS=$((PASS + 1)); printf '  ok    %s\n' "$1"; }
fail() { FAIL=$((FAIL + 1)); printf '  FALLO %s\n' "$1"; }
ok() { if [ "$2" = 0 ]; then pass "$1"; else fail "$1"; fi; }

# chk "descripción" código_esperado curl-args...   (deja la respuesta en $TMP/body y $TMP/head)
chk() {
    local desc="$1" expected="$2"; shift 2
    local code
    code=$(curl -s -o "$TMP/body" -D "$TMP/head" -w '%{http_code}' "$@")
    if [ "$code" = "$expected" ]; then pass "$desc -> $code"; else fail "$desc -> esperado $expected, obtenido $code"; fi
}
header_of() { grep -i "^$1:" "$TMP/head" | tr -d '\r' | head -1; }
# Desde M5 todo está detrás del login: curl() lleva la sesión del administrador de prueba (la cookie está en
# $JAR). Las pruebas de un visitante anónimo usan "command curl" (anon/achk, más abajo).
JAR="$TMP/jar"
curl() { command curl -b "$JAR" "$@"; }
# Un visitante anónimo abre login.php: recibe una cookie de sesión anónima (en la jarra $1) y el token CSRF del
# formulario, que deja en $ATOKEN. login_jar inicia sesión como el administrador de prueba y deja en $LTOKEN el
# token de esa sesión ya autenticada.
anon_session() { command curl -s -o "$TMP/loginpage.html" -c "$1" "$BASE/login.php"; ATOKEN=$(grep -oE 'name="csrf_token" value="[0-9a-f]{64}"' "$TMP/loginpage.html" | head -1 | grep -oE '[0-9a-f]{64}'); }
login_jar() {
    anon_session "$1"
    command curl -s -o /dev/null -b "$1" -c "$1" -H "Origin: $ORIGIN" --data-urlencode "csrf_token=$ATOKEN" --data-urlencode "email=smoke-admin@example.com" --data-urlencode "password=$ADMIN_PW" "$BASE/login.php"
    LTOKEN=$(command curl -s -b "$1" "$BASE/create.php" | grep -oE 'name="csrf_token" value="[0-9a-f]{64}"' | head -1 | grep -oE '[0-9a-f]{64}')
}
id_of() { sql "SELECT id FROM users WHERE email='$1';"; }
exists() { [ "$(sql "SELECT COUNT(*) FROM users WHERE id=$1;")" = 1 ]; }
# Con borrado lógico (M4) "eliminado" ya no es "la fila no existe": es la fila con active = 0.
soft_deleted() { [ "$(sql "SELECT COUNT(*) FROM users WHERE id=$1 AND active=0;")" = 1 ]; }
is_active() { [ "$(sql "SELECT COUNT(*) FROM users WHERE id=$1 AND active=1;")" = 1 ]; }
create() { curl -s -o /dev/null -H "Origin: $ORIGIN" --data-urlencode "name=$1" --data-urlencode "last_name=$2" -d "csrf_token=$CSRF&role=user&email=$3" "$BASE/create.php"; }
wait_db() {
    local i
    for i in $(seq 1 40); do [ "$(docker compose ps db --format '{{.Health}}')" = healthy ] && break; sleep 2; done
    for i in $(seq 1 20); do [ "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/")" = 200 ] && break; sleep 1; done
}

# ---------------------------------------------------------------------------------------------
if [ "$(command curl -s -o /dev/null -w '%{http_code}' "$BASE/login.php")" != 200 ]; then
    echo "La aplicación no responde en $BASE. ¿Está levantada (docker compose up -d)?" >&2
    exit 2
fi
sql "SELECT 1;" >/dev/null || { echo "No se puede consultar la base de datos con docker compose exec." >&2; exit 2; }
sql "DELETE FROM users WHERE email LIKE 'smoke-%@example.com';" >/dev/null
USERS_BEFORE=$(sql 'SELECT COUNT(*) FROM users;')   # antes de crear al administrador de prueba (que también es un usuario)
# Un administrador de prueba con una contraseña al azar (solo existe durante la prueba), por la entrada estándar.
ADMIN_PW="smoke-$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n')"
printf '%s\n' "$ADMIN_PW" | bin/as-migrator php scripts/create-admin.php smoke-admin@example.com >/dev/null 2>&1 \
    || { echo "No se pudo crear el administrador de prueba (¿está aplicada la migración 004?)." >&2; exit 2; }
login_jar "$JAR"
CSRF=$LTOKEN   # el token de la sesión del administrador de prueba: lo llevan todos los POST de abajo
[ "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/")" = 200 ] && [ -n "$CSRF" ] || { echo "No se pudo iniciar sesión con el administrador de prueba." >&2; exit 2; }
echo "Pruebas contra $BASE (origen $ORIGIN); usuarios al empezar: $USERS_BEFORE"

section "Inicio de sesión (M5): sin sesión no se entra a nada"
anon() { command curl "$@"; }   # sin cookies: un visitante anónimo (curl() de arriba lleva la sesión del administrador)
achk() {                         # como chk(), pero anónimo
    local desc="$1" expected="$2"; shift 2
    local code
    code=$(command curl -s -o "$TMP/body" -D "$TMP/head" -w '%{http_code}' "$@")
    if [ "$code" = "$expected" ]; then pass "$desc -> $code"; else fail "$desc -> esperado $expected, obtenido $code"; fi
}
USER_ANON=$(sql "INSERT INTO users (name, last_name, email) VALUES ('Anon', 'Prueba', 'smoke-anon@example.com'); SELECT id FROM users WHERE email='smoke-anon@example.com';")
for path in "" "?view=inactive" "?q=laura&page=2" "create.php" "edit.php?id=$USER_ANON" "edit.php?id=abc"; do
    achk "GET /$path sin sesión" 302 "$BASE/$path"
    [ "$(header_of location)" = "Location: login.php" ]; ok "...redirige a login.php" $?
    [ ! -s "$TMP/body" ] || ! grep -qE 'smoke-anon|<table|Lista de Usuarios' "$TMP/body"; ok "...y no filtra nada del listado" $?
done
achk "POST create.php sin sesión" 302 -H "Origin: $ORIGIN" -d "role=user&name=Hack&last_name=Anon&email=smoke-hack@example.com" "$BASE/create.php"
[ "$(sql "SELECT COUNT(*) FROM users WHERE email='smoke-hack@example.com';")" = 0 ]; ok "...no creó nada" $?
achk "POST edit.php sin sesión" 302 -H "Origin: $ORIGIN" -d "role=user&id=$USER_ANON&name=Hack&last_name=Hack&email=smoke-anon@example.com" "$BASE/edit.php"
[ "$(sql "SELECT name FROM users WHERE id=$USER_ANON;")" = Anon ]; ok "...no modificó nada" $?
achk "POST delete.php sin sesión" 302 -H "Origin: $ORIGIN" -d "id=$USER_ANON" "$BASE/delete.php"
[ "$(header_of location)" = "Location: login.php" ]; ok "...redirige a login.php" $?
is_active "$USER_ANON"; ok "...y NO eliminó al usuario" $?
sql "UPDATE users SET active=0 WHERE id=$USER_ANON;" >/dev/null
achk "POST restore.php sin sesión" 302 -H "Origin: $ORIGIN" -d "id=$USER_ANON" "$BASE/restore.php"
soft_deleted "$USER_ANON"; ok "...y NO restauró al usuario" $?
achk "POST delete.php sin sesión y con origen ajeno (el 403 va antes que el login)" 403 -H "Origin: http://evil.example" -d "id=$USER_ANON" "$BASE/delete.php"
achk "GET delete.php sin sesión (405 antes que el login)" 405 "$BASE/delete.php?id=$USER_ANON"
achk "GET login.php" 200 "$BASE/login.php"
[ "$(header_of cache-control)" = "Cache-Control: no-store" ]; ok "el formulario de entrada no se guarda en caché (no-store)" $?
tr -d '\n' < "$TMP/body" | grep -qE 'type="password"[^>]*autocomplete="current-password"|autocomplete="current-password"[^>]*type="password"|type="password"\s+class="form-control"\s+id="password"\s+name="password"\s+autocomplete="current-password"'; ok "la contraseña es un campo type=password con autocomplete=current-password" $?
achk "GET / sin sesión" 302 "$BASE/"
[ -z "$(header_of set-cookie)" ]; ok "un visitante anónimo que pide una página protegida NO recibe cookie ni sesión (no se crea nada en el servidor)" $?

section "Inicio de sesión (M5): credenciales"
AJ="$TMP/ajar"; anon_session "$AJ"; AJ_TOKEN=$ATOKEN   # sesión anónima con su token (desde M6 el login también lo exige)
login_body() { printf 'email=%s&password=%s' "$1" "$2"; }
achk "correo inexistente" 401 -b "$AJ" -c "$AJ" -H "Origin: $ORIGIN" --data-urlencode "csrf_token=$AJ_TOKEN" --data-urlencode "email=nadie-smoke@example.com" --data-urlencode "password=cualquier-cosa-123" "$BASE/login.php"
cp "$TMP/body" "$TMP/unknown.html"
achk "contraseña incorrecta" 401 -b "$AJ" -c "$AJ" -H "Origin: $ORIGIN" --data-urlencode "csrf_token=$AJ_TOKEN" --data-urlencode "email=smoke-admin@example.com" --data-urlencode "password=cualquier-cosa-123" "$BASE/login.php"
cp "$TMP/body" "$TMP/wrong.html"
grep -qF 'Credenciales inválidas.' "$TMP/unknown.html" && grep -qF 'Credenciales inválidas.' "$TMP/wrong.html"; ok "los dos dicen «Credenciales inválidas.»" $?
[ "$(sed -E 's/value="[^"]*"//g' "$TMP/unknown.html")" = "$(sed -E 's/value="[^"]*"//g' "$TMP/wrong.html")" ]; ok "...y las dos páginas son IDÉNTICAS (salvo el correo escrito): no se puede distinguir cuál falló" $?
! grep -q 'cualquier-cosa-123' "$TMP/wrong.html"; ok "la contraseña escrita nunca se vuelve a pintar" $?
tr -d '\n' < "$TMP/wrong.html" | grep -qF 'value="smoke-admin@example.com"'; ok "el correo sí se conserva para no reescribirlo" $?
[ -z "$(sed -nE 's/^[Ss]et-[Cc]ookie:.*/x/p' "$TMP/head")" ]; ok "un login fallido no entrega cookie de sesión" $?
achk "correo con otra capitalización y espacios, contraseña mala" 401 -b "$AJ" -c "$AJ" -H "Origin: $ORIGIN" --data-urlencode "csrf_token=$AJ_TOKEN" --data-urlencode "email= SMOKE-Admin@Example.com " --data-urlencode "password=mala-mala-mala" "$BASE/login.php"
achk "correo y contraseña vacíos" 422 -b "$AJ" -c "$AJ" -H "Origin: $ORIGIN" --data-urlencode "csrf_token=$AJ_TOKEN" -d "email=&password=" "$BASE/login.php"
achk "sin contraseña" 422 -b "$AJ" -c "$AJ" -H "Origin: $ORIGIN" --data-urlencode "csrf_token=$AJ_TOKEN" -d "email=smoke-admin@example.com" "$BASE/login.php"
achk "email como arreglo" 422 -b "$AJ" -c "$AJ" -H "Origin: $ORIGIN" --data-urlencode "csrf_token=$AJ_TOKEN" -d "email[]=x&password=abc" "$BASE/login.php"
achk "password como arreglo" 422 -b "$AJ" -c "$AJ" -H "Origin: $ORIGIN" --data-urlencode "csrf_token=$AJ_TOKEN" -d "email=smoke-admin@example.com&password[]=abc" "$BASE/login.php"
achk "inyección SQL en el correo" 401 -b "$AJ" -c "$AJ" -H "Origin: $ORIGIN" --data-urlencode "csrf_token=$AJ_TOKEN" --data-urlencode "email=' OR '1'='1' -- " --data-urlencode "password=x" "$BASE/login.php"
achk "la clave correcta seguida de un NUL y basura (bcrypt lee hasta el NUL)" 401 -b "$AJ" -c "$AJ" -H "Origin: $ORIGIN" --data-urlencode "csrf_token=$AJ_TOKEN" -d "email=smoke-admin@example.com&password=${ADMIN_PW}%00cualquier-cosa" "$BASE/login.php"
achk "una contraseña de 100.000 caracteres" 401 -b "$AJ" -c "$AJ" -H "Origin: $ORIGIN" --data-urlencode "csrf_token=$AJ_TOKEN" --data-urlencode "email=smoke-admin@example.com" --data-urlencode "password=$(head -c 100000 /dev/zero | tr '\0' 'a')" "$BASE/login.php"
achk "login sin Origin ni Referer" 403 --data-urlencode "email=smoke-admin@example.com" --data-urlencode "password=$ADMIN_PW" "$BASE/login.php"
achk "login con Origin ajeno (con la clave CORRECTA)" 403 -H "Origin: http://evil.example" --data-urlencode "email=smoke-admin@example.com" --data-urlencode "password=$ADMIN_PW" "$BASE/login.php"
[ -z "$(sed -nE 's/^[Ss]et-[Cc]ookie:.*/x/p' "$TMP/head")" ]; ok "...y no inicia ninguna sesión" $?
[ "$(sql "SELECT COUNT(*) FROM users WHERE email='smoke-admin@example.com' AND role='admin' AND password_hash LIKE '\$2y\$%' AND INSTR(password_hash, '$ADMIN_PW') = 0;")" = 1 ]; ok "en la BD hay un hash bcrypt (\$2y\$), no la contraseña" $?

section "Inicio de sesión (M5): entrar, la cookie y la fijación de sesión"
J2="$TMP/jar2"
anon_session "$J2"
achk "login correcto (correo en mayúsculas)" 302 -b "$J2" -c "$J2" -H "Origin: $ORIGIN" --data-urlencode "csrf_token=$ATOKEN" --data-urlencode "email=SMOKE-ADMIN@example.com" --data-urlencode "password=$ADMIN_PW" "$BASE/login.php"
[ "$(header_of location)" = "Location: index.php" ]; ok "redirige a index.php" $?
COOKIE_LINE=$(grep -i '^set-cookie: crud_session=' "$TMP/head" | tr -d '\r')
[ -n "$COOKIE_LINE" ]; ok "entrega la cookie crud_session (no el PHPSESSID por defecto)" $?
printf '%s' "$COOKIE_LINE" | grep -qi 'HttpOnly'; ok "...HttpOnly (JavaScript no la lee)" $?
printf '%s' "$COOKIE_LINE" | grep -qi 'SameSite=Lax'; ok "...SameSite=Lax" $?
! printf '%s' "$COOKIE_LINE" | grep -qi 'secure'; ok "...sin Secure (la aplicación va por http; con APP_ORIGIN https se activaría)" $?
! printf '%s' "$COOKIE_LINE" | grep -qiE 'expires|max-age'; ok "...cookie de sesión: sin fecha de caducidad (se va al cerrar el navegador)" $?
printf '%s' "$COOKIE_LINE" | grep -qE 'crud_session=[0-9a-z,-]{20,}'; ok "...con un identificador largo" $?
! grep -qi 'phpsessid' "$TMP/head"; ok "no aparece PHPSESSID" $?
SID=$(awk '$6=="crud_session"{print $7}' "$J2")
achk "con la sesión: GET /" 200 -b "$J2" "$BASE/"
T2=$(grep -oE 'name="csrf_token" value="[0-9a-f]{64}"' "$TMP/body" | head -1 | grep -oE '[0-9a-f]{64}')
tr -d '\n' < "$TMP/body" | sed -E 's/\s+/ /g' | grep -qF '<span class="visually-hidden">Conectado como</span> <span class="session__email">smoke-admin@example.com</span>'; ok "el encabezado dice quién está conectado" $?
tr -d '\n' < "$TMP/body" | grep -qE '<form method="post" action="logout.php"[^>]*>.*Cerrar sesión'; ok "...y ofrece «Cerrar sesión» como formulario POST" $?
[ "$(header_of cache-control)" = "Cache-Control: no-store" ]; ok "las páginas protegidas llevan Cache-Control: no-store (el botón «atrás» no las enseña tras salir)" $?
achk "con la sesión: GET login.php" 302 -b "$J2" "$BASE/login.php"
[ "$(header_of location)" = "Location: index.php" ]; ok "quien ya entró no ve el formulario: va a index.php" $?
achk "el id de sesión en la URL no vale (?crud_session=...)" 302 "$BASE/?crud_session=$SID"
achk "otra cookie con el mismo valor pero distinto nombre (PHPSESSID) no vale" 302 -b "PHPSESSID=$SID" "$BASE/"
achk "un id de sesión inventado" 302 -b "crud_session=inventado1234567890inventado12" "$BASE/"
[ "$(header_of location)" = "Location: login.php" ]; ok "...va a login.php" $?
achk "fijación de sesión: un id inventado por el atacante no puede ni enviar el login (la sesión nueva no tiene su token)" 403 -b "crud_session=atacante1234567890atacante1234" -H "Origin: $ORIGIN" --data-urlencode "csrf_token=$AJ_TOKEN" --data-urlencode "email=smoke-admin@example.com" --data-urlencode "password=$ADMIN_PW" "$BASE/login.php"
NEWSID=$(grep -i '^set-cookie: crud_session=' "$TMP/head" | sed -E 's/^[^=]*=([^;]*);.*/\1/' | tr -d '\r')
[ "$NEWSID" != "atacante1234567890atacante1234" ]; ok "el servidor no adopta el id elegido por el atacante" $?
achk "...y el id que el atacante conocía no sirve" 302 -b "crud_session=atacante1234567890atacante1234" "$BASE/"
# El caso que de verdad exige session_regenerate_id(): un id que el servidor SÍ conoce (ya existe su archivo de
# sesión, aunque esté vacío) y que el atacante hizo llegar a la víctima. El modo estricto lo acepta; lo que lo
# neutraliza es que al entrar se cambie por otro.
FIXED=fijadoexistente0123456789abcdef
FIXTOKEN=$(head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n')
docker compose exec -T php sh -c "rm -f /tmp/sess_$FIXED; printf 'csrf_token|s:64:\"$FIXTOKEN\";' > /tmp/sess_$FIXED"
achk "fijación con un id que el servidor SÍ conoce (y cuyo token el atacante conoce): la víctima entra con él" 302 -b "crud_session=$FIXED" -H "Origin: $ORIGIN" --data-urlencode "csrf_token=$FIXTOKEN" --data-urlencode "email=smoke-admin@example.com" --data-urlencode "password=$ADMIN_PW" "$BASE/login.php"
FIXSID=$(grep -i '^set-cookie: crud_session=' "$TMP/head" | sed -E 's/^[^=]*=([^;]*);.*/\1/' | tr -d '\r')
[ -n "$FIXSID" ] && [ "$FIXSID" != "$FIXED" ]; ok "al entrar, el id cambia (session_regenerate_id): el servidor entrega otro" $?
achk "...y el id que conocía el atacante ya no da acceso" 302 -b "crud_session=$FIXED" "$BASE/"
[ "$(header_of location)" = "Location: login.php" ]; ok "...lo manda a login.php" $?
achk "...mientras que el id nuevo sí" 200 -b "crud_session=$FIXSID" "$BASE/"
[ "$(docker compose exec -T php sh -c "[ -e /tmp/sess_$FIXED ] && echo si || echo no" | tr -d '\r')" = no ]; ok "...y el archivo de sesión viejo se borró del servidor" $?

section "Inicio de sesión (M5): cerrar sesión"
achk "GET logout.php" 405 "$BASE/logout.php"
[ "$(header_of allow)" = "Allow: POST" ]; ok "405 con Allow: POST (una imagen <img src=logout.php> no cierra sesiones)" $?
achk "la sesión sigue viva tras el 405" 200 -b "$J2" "$BASE/"
achk "POST logout con Origin ajeno" 403 -b "$J2" -H "Origin: http://evil.example" -d "csrf_token=$T2" "$BASE/logout.php"
achk "POST logout sin Origin" 403 -b "$J2" -d "csrf_token=$T2" "$BASE/logout.php"
achk "...la sesión sigue viva tras los 403" 200 -b "$J2" "$BASE/"
cp "$J2" "$TMP/jar_before_logout"
achk "POST logout" 302 -b "$J2" -c "$J2" -H "Origin: $ORIGIN" -d "csrf_token=$T2" "$BASE/logout.php"
[ "$(header_of location)" = "Location: login.php?msg=logged_out" ]; ok "redirige a login.php?msg=logged_out" $?
grep -i '^set-cookie: crud_session=' "$TMP/head" | grep -qiE 'expires=Thu, 01 Jan 1970|Max-Age=0'; ok "borra la cookie del navegador (caducada en el pasado)" $?
achk "con la cookie de ANTES de salir (sesión destruida en el servidor)" 302 -b "$TMP/jar_before_logout" "$BASE/"
[ "$(header_of location)" = "Location: login.php" ]; ok "ya no vale: va a login.php" $?
achk "POST delete con la cookie vieja" 302 -b "$TMP/jar_before_logout" -H "Origin: $ORIGIN" -d "id=$USER_ANON" "$BASE/delete.php"
[ "$(header_of location)" = "Location: login.php" ]; ok "...tampoco permite eliminar" $?
achk "el aviso de «Cerraste la sesión»" 200 "$BASE/login.php?msg=logged_out"
grep -qF 'Cerraste la sesión.' "$TMP/body"; ok "se muestra en login.php" $?
achk "cerrar sesión sin tener sesión" 302 -H "Origin: $ORIGIN" -X POST "$BASE/logout.php"
[ "$(header_of location)" = "Location: login.php?msg=logged_out" ]; ok "es idempotente (no da error)" $?

section "Inicio de sesión (M5): caducidad por inactividad"
J3="$TMP/jar3"
anon_session "$J3"
achk "login para la prueba de caducidad" 302 -b "$J3" -c "$J3" -H "Origin: $ORIGIN" --data-urlencode "csrf_token=$ATOKEN" --data-urlencode "email=smoke-admin@example.com" --data-urlencode "password=$ADMIN_PW" "$BASE/login.php"
SID3=$(awk '$6=="crud_session"{print $7}' "$J3")
setseen() { docker compose exec -T php sh -c "sed -i -E 's/last_seen\\|i:[0-9]+/last_seen|i:$1/' /tmp/sess_$SID3"; }
getseen() { docker compose exec -T php sh -c "sed -nE 's/.*last_seen\\|i:([0-9]+).*/\\1/p' /tmp/sess_$SID3" | tr -d '\r'; }
NOW=$(date +%s)
[ -n "$(getseen)" ]; ok "la sesión guarda la hora de la última actividad (archivo de sesión en el contenedor de PHP)" $?
setseen $((NOW - 1700))
achk "a los 28 minutos sin actividad (el límite son 30)" 200 -b "$J3" "$BASE/"
[ "$(getseen)" -ge $((NOW - 5)) ]; ok "...sigue viva y la actividad se renueva" $?
setseen $((NOW - 1805))
achk "a los 30 minutos y 5 segundos sin actividad" 302 -b "$J3" "$BASE/"
[ "$(header_of location)" = "Location: login.php?msg=expired" ]; ok "caducó: va a login.php?msg=expired" $?
achk "...el aviso de caducidad" 200 "$BASE/login.php?msg=expired"
grep -qF 'caducó por inactividad' "$TMP/body"; ok "se muestra en login.php" $?
achk "...y la sesión caducada no se reaprovecha" 302 -b "$J3" "$BASE/"
[ "$(header_of location)" = "Location: login.php" ]; ok "sigue yendo a login.php (ya sin el aviso)" $?
anon_session "$J3"
achk "una sesión con la hora en el futuro tampoco es de fiar" 302 -b "$J3" -c "$J3" -H "Origin: $ORIGIN" --data-urlencode "csrf_token=$ATOKEN" --data-urlencode "email=smoke-admin@example.com" --data-urlencode "password=$ADMIN_PW" "$BASE/login.php"
SID3=$(awk '$6=="crud_session"{print $7}' "$J3")
setseen $((NOW + 4000))
achk "con last_seen en el futuro" 302 -b "$J3" "$BASE/"

section "Tokens CSRF (M6): cada formulario lleva el token de la sesión"
VICTIM=$(sql "INSERT INTO users (name, last_name, email, role) VALUES ('Victima', 'Csrf', 'smoke-csrf-v@example.com', 'user'), ('Otra', 'Csrf', 'smoke-csrf-w@example.com', 'user'); SELECT id FROM users WHERE email='smoke-csrf-v@example.com';")
for page in "" "?view=inactive" "create.php" "edit.php?id=$VICTIM"; do
    chk "GET /$page" 200 "$BASE/$page"
    FORMS=$(grep -c 'method="post"' "$TMP/body"); TOKENS=$(grep -c 'name="csrf_token"' "$TMP/body")
    [ "$FORMS" -ge 1 ] && [ "$FORMS" = "$TOKENS" ]; ok "...TODOS sus formularios POST ($FORMS) llevan el campo csrf_token ($TOKENS)" $?
done
chk "el listado con usuarios" 200 --get --data-urlencode "q=smoke-csrf" "$BASE/"
FORMS=$(grep -c 'method="post"' "$TMP/body"); TOKENS=$(grep -c 'name="csrf_token"' "$TMP/body")
[ "$FORMS" -ge 3 ] && [ "$FORMS" = "$TOKENS" ]; ok "...con varias filas: cada formulario de eliminar y el de cerrar sesión llevan token ($FORMS de $FORMS)" $?
[ "$(grep -oE 'name="csrf_token" value="[0-9a-f]{64}"' "$TMP/body" | sort -u | wc -l)" = 1 ]; ok "...y es el MISMO token en todos (uno por sesión, no por formulario)" $?
[ "$(grep -oE 'name="csrf_token" value="[0-9a-f]{64}"' "$TMP/body" | grep -oE '[0-9a-f]{64}' | head -1)" = "$CSRF" ]; ok "...y el mismo en otra página de la misma sesión (sirve con varias pestañas y con «atrás»)" $?
! grep -qF "$CSRF" <(grep -iE '^(location|set-cookie)' "$TMP/head"); ok "el token no viaja en la cabecera Location ni en una cookie" $?
! grep -qE 'href="[^"]*csrf_token' "$TMP/body"; ok "ni en la URL de ningún enlace (iría al historial y al Referer)" $?
login_jar "$TMP/jar_other"; OTHER=$LTOKEN
[ -n "$OTHER" ] && [ "$OTHER" != "$CSRF" ]; ok "otra sesión del mismo administrador tiene OTRO token" $?
anon_session "$TMP/jar_rot"; PRE=$ATOKEN
command curl -s -o /dev/null -b "$TMP/jar_rot" -c "$TMP/jar_rot" -H "Origin: $ORIGIN" --data-urlencode "csrf_token=$PRE" --data-urlencode "email=smoke-admin@example.com" --data-urlencode "password=$ADMIN_PW" "$BASE/login.php"
POST_TOKEN=$(command curl -s -b "$TMP/jar_rot" "$BASE/create.php" | grep -oE 'name="csrf_token" value="[0-9a-f]{64}"' | head -1 | grep -oE '[0-9a-f]{64}')
[ -n "$PRE" ] && [ -n "$POST_TOKEN" ] && [ "$PRE" != "$POST_TOKEN" ]; ok "el token CAMBIA al iniciar sesión (el de la página de login no sirve después)" $?
achk "...y el token anterior al login ya no vale en la sesión autenticada" 403 -b "$TMP/jar_rot" -H "Origin: $ORIGIN" -d "csrf_token=$PRE&id=$USER_ANON" "$BASE/delete.php"

section "Tokens CSRF (M6): un envío sin token válido da 403 y NO cambia nada"
# Lo que envía un ataque CSRF que ya hubiera superado la comprobación de Origin (aquí se simula poniendo el Origin
# correcto): la cookie de la víctima (la adjunta el navegador) y un formulario SIN el token, que el atacante no conoce.
A64=$(printf 'a%.0s' $(seq 1 64))
declare -a BAD_NAMES=("sin el campo" "campo vacío" "64 letras «a»" "token de OTRA sesión" "el token con un espacio al final" "el token en MAYÚSCULAS" "el token como arreglo" "el token truncado (63 caracteres)")
declare -a BAD_VALUES=("" "csrf_token=" "csrf_token=$A64" "csrf_token=$OTHER" "csrf_token=$CSRF%20" "csrf_token=${CSRF^^}" "csrf_token[]=$CSRF" "csrf_token=${CSRF:0:63}")
for i in "${!BAD_VALUES[@]}"; do
    bad="${BAD_VALUES[$i]}"; name="${BAD_NAMES[$i]}"
    chk "delete.php: $name" 403 -H "Origin: $ORIGIN" -d "${bad}&id=$VICTIM" "$BASE/delete.php"
    grep -qF 'Token de seguridad no válido' "$TMP/body"; ok "...con el aviso del token" $?
    is_active "$VICTIM"; ok "...y el usuario sigue activo" $?
done
sql "UPDATE users SET active=0 WHERE id=$VICTIM;" >/dev/null
for i in "${!BAD_VALUES[@]}"; do
    chk "restore.php: ${BAD_NAMES[$i]}" 403 -H "Origin: $ORIGIN" -d "${BAD_VALUES[$i]}&id=$VICTIM" "$BASE/restore.php"
done
soft_deleted "$VICTIM"; ok "restore.php: tras los 8 intentos el usuario SIGUE eliminado" $?
sql "UPDATE users SET active=1 WHERE id=$VICTIM;" >/dev/null
for i in "${!BAD_VALUES[@]}"; do
    chk "edit.php: ${BAD_NAMES[$i]}" 403 -H "Origin: $ORIGIN" -d "${BAD_VALUES[$i]}&role=admin&id=$VICTIM&name=Hackeado&last_name=Csrf&email=smoke-csrf-v@example.com" "$BASE/edit.php"
done
[ "$(sql "SELECT CONCAT(name,'|',role) FROM users WHERE id=$VICTIM;")" = "Victima|user" ]; ok "edit.php: tras los 8 intentos el usuario NO cambió (ni el nombre ni el rol)" $?
for i in "${!BAD_VALUES[@]}"; do
    chk "create.php: ${BAD_NAMES[$i]}" 403 -H "Origin: $ORIGIN" -d "${BAD_VALUES[$i]}&role=admin&name=Hack&last_name=Csrf&email=smoke-csrf-nuevo@example.com" "$BASE/create.php"
done
[ "$(sql "SELECT COUNT(*) FROM users WHERE email='smoke-csrf-nuevo@example.com';")" = 0 ]; ok "create.php: tras los 8 intentos no se creó nada" $?
chk "delete.php: el token solo en la URL (?csrf_token=...), no en el cuerpo" 403 -H "Origin: $ORIGIN" -d "id=$VICTIM" "$BASE/delete.php?csrf_token=$CSRF"
is_active "$VICTIM"; ok "...no cuenta (solo se lee de \$_POST) y el usuario sigue activo" $?
chk "delete.php: el token en una cabecera" 403 -H "Origin: $ORIGIN" -H "X-CSRF-Token: $CSRF" -d "id=$VICTIM" "$BASE/delete.php"
chk "delete.php: el token en una cookie" 403 -b "csrf_token=$CSRF" -H "Origin: $ORIGIN" -d "id=$VICTIM" "$BASE/delete.php"
is_active "$VICTIM"; ok "...tampoco (un token solo en una cookie no prueba nada: el navegador la adjunta él solo)" $?
chk "delete.php: con el token CORRECTO funciona" 302 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&id=$VICTIM" "$BASE/delete.php"
soft_deleted "$VICTIM"; ok "...y elimina al usuario" $?
chk "el mismo token sirve otra vez (es por sesión, no de un solo uso)" 302 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&id=$VICTIM" "$BASE/restore.php"
is_active "$VICTIM"; ok "...y restaura" $?

section "Tokens CSRF (M6): el orden de las comprobaciones"
chk "token malo + id inválido: 403 (no 400)" 403 -H "Origin: $ORIGIN" -d "csrf_token=$A64&id=abc" "$BASE/delete.php"
chk "token malo + usuario inexistente en edit: 403 (no 404)" 403 -H "Origin: $ORIGIN" -d "csrf_token=$A64&id=4294967295&name=A&last_name=B&email=a@b.co&role=user" "$BASE/edit.php"
chk "token bueno + usuario inexistente en edit: 404" 404 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&id=4294967295&name=A&last_name=B&email=a@b.co&role=user" "$BASE/edit.php"
chk "token malo + datos inválidos en create: 403 (no 422)" 403 -H "Origin: $ORIGIN" -d "csrf_token=$A64&name=&last_name=&email=" "$BASE/create.php"
chk "token bueno + datos inválidos en create: 422" 422 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&role=user&name=&last_name=&email=" "$BASE/create.php"
chk "origen ajeno + token BUENO: 403 por el origen" 403 -H "Origin: http://evil.example" -d "csrf_token=$CSRF&id=$VICTIM" "$BASE/delete.php"
grep -qF 'Origen no permitido' "$TMP/body"; ok "...y es el mensaje del origen, no el del token" $?
achk "sin sesión + token inventado: 302 a login (la sesión va antes que el token)" 302 -H "Origin: $ORIGIN" -d "csrf_token=$A64&id=$VICTIM" "$BASE/delete.php"
[ "$(header_of location)" = "Location: login.php" ]; ok "...a login.php" $?
chk "el 403 del token no se guarda en caché" 403 -H "Origin: $ORIGIN" -d "id=$VICTIM" "$BASE/delete.php"
[ "$(header_of cache-control)" = "Cache-Control: no-store" ]; ok "...(Cache-Control: no-store)" $?

section "Tokens CSRF (M6): login y logout"
anon_session "$TMP/jar_lg"; TOKEN_LG=$ATOKEN
grep -qE 'set-cookie' <(command curl -s -D - -o /dev/null -c "$TMP/jar_lg2" "$BASE/login.php" | tr 'A-Z' 'a-z'); ok "login.php entrega una cookie de sesión anónima (guarda el token del formulario)" $?
[ -n "$TOKEN_LG" ] && [ ${#TOKEN_LG} = 64 ]; ok "...y el formulario de login lleva un token de 64 caracteres hexadecimales" $?
achk "login SIN token (con la clave correcta)" 403 -b "$TMP/jar_lg" -c "$TMP/jar_lg" -H "Origin: $ORIGIN" --data-urlencode "email=smoke-admin@example.com" --data-urlencode "password=$ADMIN_PW" "$BASE/login.php"
grep -qF 'La página caducó o el envío no es válido' "$TMP/body"; ok "...vuelve a pintar el formulario con un aviso (no una pantalla en blanco)" $?
tr -d '\n' < "$TMP/body" | grep -qE 'name="csrf_token" value="[0-9a-f]{64}"'; ok "...con un token para reintentar" $?
! grep -q "$ADMIN_PW" "$TMP/body"; ok "...sin repetir la contraseña" $?
achk "login con el token de OTRA sesión" 403 -b "$TMP/jar_lg" -c "$TMP/jar_lg" -H "Origin: $ORIGIN" --data-urlencode "csrf_token=$OTHER" --data-urlencode "email=smoke-admin@example.com" --data-urlencode "password=$ADMIN_PW" "$BASE/login.php"
achk "login con el token pero SIN la cookie de sesión (otro navegador)" 403 -H "Origin: $ORIGIN" --data-urlencode "csrf_token=$TOKEN_LG" --data-urlencode "email=smoke-admin@example.com" --data-urlencode "password=$ADMIN_PW" "$BASE/login.php"
achk "login con el token y la cookie correctos" 302 -b "$TMP/jar_lg" -c "$TMP/jar_lg" -H "Origin: $ORIGIN" --data-urlencode "csrf_token=$TOKEN_LG" --data-urlencode "email=smoke-admin@example.com" --data-urlencode "password=$ADMIN_PW" "$BASE/login.php"
OLD_TOKEN=$TOKEN_LG
achk "logout SIN token (con sesión)" 403 -b "$TMP/jar_lg" -H "Origin: $ORIGIN" -X POST "$BASE/logout.php"
achk "...la sesión sigue viva" 200 -b "$TMP/jar_lg" "$BASE/"
NEW_LG=$(grep -oE 'name="csrf_token" value="[0-9a-f]{64}"' "$TMP/body" | head -1 | grep -oE '[0-9a-f]{64}')
achk "logout con el token de la sesión ANTERIOR al login (el de la página de entrada)" 403 -b "$TMP/jar_lg" -H "Origin: $ORIGIN" -d "csrf_token=$OLD_TOKEN" "$BASE/logout.php"
achk "logout con el token correcto" 302 -b "$TMP/jar_lg" -c "$TMP/jar_lg" -H "Origin: $ORIGIN" -d "csrf_token=$NEW_LG" "$BASE/logout.php"
[ "$(header_of location)" = "Location: login.php?msg=logged_out" ]; ok "...cierra la sesión" $?
login_jar "$TMP/jar_lg"
achk "tras salir y entrar de nuevo, el token VIEJO ya no sirve" 403 -b "$TMP/jar_lg" -H "Origin: $ORIGIN" -d "csrf_token=$NEW_LG&id=$VICTIM" "$BASE/delete.php"

section "Interfaz propia (sin Bootstrap ni CDN)"
chk "GET /css/app.css" 200 "$BASE/css/app.css"
grep -qi 'text/css' "$TMP/head"; ok "se sirve como text/css" $?
[ "$(wc -c < "$TMP/body")" -gt 5000 ]; ok "la hoja de estilos tiene contenido" $?
! grep -qE 'url\(["'"'"']?https?://|@import' "$TMP/body"; ok "no importa nada de fuera (ni @import ni url(http...))" $?
grep -qF 'prefers-color-scheme: dark' "$TMP/body" && grep -qF 'prefers-reduced-motion' "$TMP/body"; ok "respeta el modo oscuro y el movimiento reducido" $?
chk "GET /img/icons.svg" 200 "$BASE/img/icons.svg"
grep -qi 'image/svg+xml' "$TMP/head"; ok "los iconos son un SVG propio" $?
[ "$(grep -c '<symbol id=' "$TMP/body")" -ge 15 ]; ok "...con todos sus símbolos" $?
chk "el listado enlaza el SVG de iconos" 200 "$BASE/"
grep -q '<use href="/img/icons.svg?v=[0-9]\+#i-' "$TMP/body"; ok "...con la fecha del archivo" $?
grep -q 'class="skip-link" href="#contenido"' "$TMP/body" && grep -q '<main class="main" id="contenido"' "$TMP/body"; ok "hay un enlace «Saltar al contenido» y su destino" $?
! grep -qE 'style="|<style' "$TMP/body"; ok "sin estilos en línea (lo exige la CSP)" $?
[ "$(grep -c 'aria-hidden="true" focusable="false"' "$TMP/body")" -ge 1 ] && ! grep -qE '<svg class="icon"[^>]*role=' "$TMP/body"; ok "los iconos son decorativos (aria-hidden)" $?
chk "GET /js/app.js sin eval ni innerHTML" 200 "$BASE/js/app.js"
! grep -qE 'innerHTML|eval\(|document\.write' "$TMP/body"; ok "el JavaScript no usa innerHTML, eval ni document.write" $?
grep -q 'showModal' "$TMP/body"; ok "la confirmación es un <dialog> propio" $?

section "Páginas y cabeceras de seguridad"
chk "GET /" 200 "$BASE/"
for h in x-content-type-options x-frame-options content-security-policy referrer-policy; do
    [ -n "$(header_of $h)" ]; ok "cabecera $h presente" $?
done
header_of server | grep -qE 'nginx/[0-9]'; [ $? -ne 0 ]; ok "Server no revela la versión de nginx" $?
[ -z "$(header_of x-powered-by)" ]; ok "no hay X-Powered-By (no se revela la versión de PHP)" $?
curl -s "$BASE/" > "$TMP/index.html"
[ "$(grep -cE '\son[a-z]+="' "$TMP/index.html")" = 0 ]; ok "el listado no tiene manejadores en línea (onsubmit, onclick...)" $?
[ "$(grep -cE '<style|\sstyle=' "$TMP/index.html")" = 0 ]; ok "el listado no tiene estilos en línea" $?
! grep -qE '(src|href)="https?://' "$TMP/index.html"; ok "la página no carga nada de fuera (ni CDN ni fuentes): todo es propio" $?
grep -q 'href="/css/app.css?v=[0-9]\+"' "$TMP/index.html"; ok "la hoja de estilos propia se enlaza con su fecha (se renueva sola al cambiar)" $?
! grep -qiE 'bootstrap|data-bs-|bi bi-' "$TMP/index.html"; ok "no queda rastro de Bootstrap en el HTML" $?
chk "GET /js/app.js" 200 "$BASE/js/app.js"
header_of content-type | grep -qiE 'javascript'; ok "el JavaScript se sirve como JavaScript" $?
chk "GET /status.php (solo en APP_ENV=local)" 200 "$BASE/status.php"
chk "GET /.env (nginx lo bloquea)" 403 "$BASE/.env"
chk "GET /?msg=created" 200 "$BASE/?msg=created"; grep -q 'notice--success' "$TMP/body"; ok "created: aviso verde" $?
chk "GET /?msg=missing" 200 "$BASE/?msg=missing"; grep -q 'notice--info' "$TMP/body"; ok "missing: aviso azul" $?
chk "GET /?msg[]=x (un arreglo)" 200 "$BASE/?msg%5B%5D=x"
chk "GET /?msg=<b>x</b> (valor raro)" 200 "$BASE/?msg=%3Cb%3Ex%3C/b%3E"; ! grep -qE 'notice--(success|info)' "$TMP/body"; ok "un valor raro no muestra aviso" $?

section "Configuración de PHP y de la imagen"
APP_ENV_NOW=$(docker compose exec -T php sh -c 'echo $APP_ENV')
SHOW_ERRORS=$(docker compose exec -T php php -r 'echo ini_get("display_errors");')
if [ "$APP_ENV_NOW" = local ]; then
    [ "$SHOW_ERRORS" = 1 ]; ok "APP_ENV=local: los errores se muestran en pantalla (display_errors=1)" $?
else
    [ -z "$SHOW_ERRORS" ]; ok "APP_ENV=$APP_ENV_NOW: los errores NO se muestran en pantalla (display_errors vacío)" $?
fi
[ "$(docker compose exec -T php php -r 'echo ini_get("log_errors");')" = 1 ]; ok "los errores siempre se registran en el log (log_errors=1)" $?
[ -z "$(docker compose exec -T php php -r 'echo ini_get("expose_php");')" ]; ok "expose_php apagado" $?
grep -qE '^FROM php:[0-9]+\.[0-9]+\.[0-9]+-fpm' docker/php/Dockerfile; ok "la imagen de PHP tiene la versión de parche fijada" $?

section "Crear (create.php)"
chk "GET" 200 "$BASE/create.php"
curl -s "$BASE/create.php" > "$TMP/form.html"
tr -d '\n' < "$TMP/form.html" | grep -qE 'type="tel"[^>]*id="phone"'; ok "el teléfono es un campo type=\"tel\"" $?
[ "$(grep -c 'autocomplete="off"' "$TMP/form.html")" = 7 ]; ok "los 7 campos llevan autocomplete=\"off\" (son datos de otra persona)" $?
chk "POST válido SIN Origin ni Referer" 403 -d "csrf_token=$CSRF&role=user&name=Ana&last_name=Ruiz&email=smoke-sinorigen@example.com" "$BASE/create.php"
[ "$(sql "SELECT COUNT(*) FROM users WHERE email='smoke-sinorigen@example.com';")" = 0 ]; ok "el 403 no creó ningún usuario" $?
chk "POST válido con Origin ajeno" 403 -H "Origin: http://evil.example" -d "csrf_token=$CSRF&role=user&name=Ana&last_name=Ruiz&email=smoke-sinorigen@example.com" "$BASE/create.php"
[ "$(sql "SELECT COUNT(*) FROM users WHERE email='smoke-sinorigen@example.com';")" = 0 ]; ok "con Origin ajeno tampoco se creó" $?
chk "POST inválido SIN Origin (403 antes que 422)" 403 -d "csrf_token=$CSRF&role=user&name=&last_name=&email=" "$BASE/create.php"
chk "POST con Origin: null" 403 -H "Origin: null" -d "csrf_token=$CSRF&role=user&name=Ana&last_name=Ruiz&email=smoke-sinorigen@example.com" "$BASE/create.php"
chk "POST con solo Referer correcto" 302 -H "Referer: $ORIGIN/create.php" -d "csrf_token=$CSRF&role=user&name=Ref&last_name=Erer&email=smoke-ref@example.com" "$BASE/create.php"
chk "POST vacío" 422 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&role=user&name=&last_name=&email=" "$BASE/create.php"
chk "POST con teléfono sin dígitos" 422 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&role=user&name=Ana&last_name=Ruiz&email=smoke-x@example.com&phone=-------" "$BASE/create.php"
chk "POST con name[] (un arreglo)" 422 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&role=user&name[]=x&last_name=Ruiz&email=smoke-x@example.com" "$BASE/create.php"
chk "POST con el correo de un usuario existente" 409 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&role=user&name=Ana&last_name=Ruiz&email=$(sql 'SELECT email FROM users ORDER BY id LIMIT 1;')" "$BASE/create.php"
chk "POST válido (correo en mayúsculas)" 302 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&role=user&name=Smoke&last_name=Uno&email=SMOKE-One@Example.com&phone=%2B57%20300%20123%204567" "$BASE/create.php"
header_of location | grep -q 'msg=created'; ok "redirige a msg=created" $?
U1=$(id_of smoke-one@example.com); [ -n "$U1" ]; ok "el correo se guardó en minúsculas" $?
chk "POST con un nombre con punto medio (Cal·lís)" 302 -H "Origin: $ORIGIN" --data-urlencode "name=Pere" --data-urlencode "last_name=Cal·lís" -d "csrf_token=$CSRF&role=user&email=smoke-cat@example.com" "$BASE/create.php"
[ "$(sql "SELECT last_name FROM users WHERE email='smoke-cat@example.com';")" = "Cal·lís" ]; ok "el apellido con punto medio se guardó bien (utf8mb4)" $?

section "Listado: fecha local, escape y accesibilidad"
create "O'Brien" "Prueba" "smoke-obrien@example.com"
UO=$(id_of smoke-obrien@example.com)
curl -s "$BASE/" > "$TMP/index.html"
grep -q 'data-confirm="¿Eliminar a O&#039;Brien Prueba?"' "$TMP/index.html"; ok "data-confirm con apóstrofe, escapado para HTML" $?
grep -q 'aria-label="Eliminar a O&#039;Brien Prueba"' "$TMP/index.html"; ok "el botón de eliminar dice a quién (aria-label)" $?
grep -q 'aria-label="Editar a O&#039;Brien Prueba"' "$TMP/index.html"; ok "el botón de editar dice a quién (aria-label)" $?
SHOWN=$(tr -d '\n' < "$TMP/index.html" | grep -oE 'person__name">O&#039;Brien Prueba</span>.{0,900}' | grep -oE '[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9:]{8}' | head -1)
[ "$(docker compose exec -T php php -r 'echo abs(time() - strtotime($argv[1])) < 180 ? "ok" : "mal";' -- "$SHOWN")" = ok ]; ok "la fecha mostrada ($SHOWN) es la hora local actual, no UTC" $?
STORED=$(sql "SELECT created_at FROM users WHERE id=$UO;")
[ "$(docker compose exec -T php php -r 'echo abs(time() - strtotime($argv[1] . " UTC")) < 180 ? "ok" : "mal";' -- "$STORED")" = ok ]; ok "la BD guarda la fecha en UTC ($STORED)" $?
[ "$(sql 'SELECT @@session.time_zone, @@global.time_zone;' | tr '\t' ' ')" = "+00:00 +00:00" ]; ok "el servidor de BD trabaja en UTC" $?

section "Búsqueda (M1): GET ?q=texto"
# Usuarios de prueba: uno con tildes y teléfono, y dos parejas para probar los comodines de LIKE.
curl -s -o /dev/null -H "Origin: $ORIGIN" --data-urlencode "name=Zoé" --data-urlencode "last_name=Núñez-Smoke" -d "csrf_token=$CSRF&role=user&email=smoke-busq-a@example.com" --data-urlencode "phone=+57 300 777 8888" "$BASE/create.php"
create "Pct%Real" "Smoke" "smoke-busq-b@example.com"
create "PctXReal" "Smoke" "smoke-busq-c@example.com"
create "Sub_Real" "Smoke" "smoke-busq-d@example.com"
create "SubXReal" "Smoke" "smoke-busq-e@example.com"
search() { chk "q=$1" 200 --get --data-urlencode "q=$1" "$BASE/"; }
shows() { tr -d '\n' < "$TMP/body" | grep -qF "$1"; }
search "smoke-busq"; shows "5 resultados para «smoke-busq»"; ok "cuenta los resultados (5, en plural)" $?
search "smoke-busq-a"; shows "1 resultado para «smoke-busq-a»"; ok "cuenta un solo resultado (en singular)" $?
search "ZOE NUNEZ-SMOKE"; shows "Núñez-Smoke"; ok "ignora mayúsculas y tildes, y busca en el nombre completo (\"Zoé Núñez-Smoke\")" $?
search "nunez"; shows "Núñez-Smoke"; ok "encuentra por apellido sin la tilde" $?
search "zoe     nunez"; shows "Núñez-Smoke"; ok "los espacios repetidos se juntan" $?
search "busq-a@"; shows "Núñez-Smoke"; ok "encuentra por una parte del correo" $?
search "777 8888"; shows "Núñez-Smoke"; ok "encuentra por una parte del teléfono" $?
search "%"; shows "Pct%Real"; ok "q=% encuentra solo a quien tiene un % de verdad..." $?
! shows "PctXReal"; ok "...y no a PctXReal (el % no es un comodín)" $?
search "Pct%Real"; shows "Pct%Real" && ! shows "PctXReal"; ok "q=Pct%Real encuentra solo el literal" $?
search "_"; shows "Sub_Real"; ok "q=_ encuentra el guion bajo de verdad..." $?
! shows "SubXReal"; ok "...y no SubXReal (el _ no casa con cualquier letra)" $?
search '\'; ok "q=\\ (una barra invertida) no rompe la consulta" 0
! grep -qiE 'Fatal error|SQLSTATE' "$TMP/body"; ok "...ni deja ningún error en la página" $?
search "zzzz-no-existe"; shows "No se encontraron resultados para «zzzz-no-existe»"; ok "sin resultados: mensaje propio (distinto de \"No hay usuarios registrados\")" $?
! shows "No hay usuarios registrados"; ok "...que no es el del estado vacío general" $?
grep -q '<table' "$TMP/body"; [ $? -ne 0 ]; ok "...y no pinta la tabla" $?
shows "Limpiar búsqueda"; ok "...pero sí ofrece \"Limpiar búsqueda\"" $?
grep -q 'value="zzzz-no-existe"' "$TMP/body"; ok "el campo conserva lo que se escribió" $?
chk "q vacío" 200 --get --data-urlencode "q=" "$BASE/"; shows "Núñez-Smoke"; ok "q vacío lista todo" $?
! shows "Limpiar búsqueda"; ok "...y sin búsqueda no hay enlace \"Limpiar búsqueda\"" $?
grep -q 'role="search"' "$TMP/body" && grep -q 'name="q"' "$TMP/body" && grep -q 'type="search"' "$TMP/body"; ok "el formulario es un GET de role=search con un campo type=search name=q" $?
tr -d '\n' < "$TMP/body" | grep -qE '<form class="search" method="get" action="index.php" role="search"'; ok "...que usa el método GET (la URL se puede compartir)" $?
chk "q[]=x (un arreglo)" 200 "$BASE/?q%5B%5D=x"; shows "Núñez-Smoke"; ok "un arreglo cuenta como \"sin búsqueda\" y lista todo" $?
chk "q junto con msg" 200 "$BASE/?q=zoe&msg=created"; shows "Núñez-Smoke" && grep -q 'notice--success' "$TMP/body"; ok "q y msg conviven: resultados y aviso" $?
search "' OR '1'='1"; shows "No se encontraron resultados"; ok "un intento de inyección es texto literal: sin resultados" $?
search "'; DROP TABLE users; --"; [ "$(sql 'SELECT COUNT(*) FROM users;')" -ge 5 ]; ok "...y la tabla users sigue ahí" $?
tr -d '\n' < "$TMP/body" | grep -qF "No se encontraron resultados para «&#039;; DROP TABLE users; --»"; ok "...y el texto se muestra escapado" $?
search "<script>alert(1)</script>"; ! grep -qF '<script>alert(1)' "$TMP/body"; ok "XSS: el texto buscado no sale como HTML" $?
grep -qF '&lt;script&gt;alert(1)&lt;/script&gt;' "$TMP/body"; ok "...sale escapado, tanto en el campo como en el mensaje" $?
LONG=$(printf 'a%.0s' $(seq 1 600)); search "$LONG"
[ "$(tr -d '\n' < "$TMP/body" | grep -oE 'value="a+"' | head -1 | sed -E 's/value="(a+)"/\1/' | tr -d '\n' | wc -c)" = 254 ]; ok "un texto de 600 caracteres se recorta a 254" $?
[ "$(sql 'SELECT @@sql_mode;' | grep -c NO_BACKSLASH_ESCAPES)" = 0 ]; ok "sql_mode no tiene NO_BACKSLASH_ESCAPES (si lo tuviera, el escape con \\ dejaría de funcionar)" $?

section "Paginación (M2): GET ?page=N, 10 usuarios por página"
# 25 usuarios de prueba (3 páginas aunque la BD tenga pocos); se aíslan con la búsqueda "smoke-pag".
sql "SET SESSION cte_max_recursion_depth = 1000; INSERT INTO users (name, last_name, email) WITH RECURSIVE n(i) AS (SELECT 1 UNION ALL SELECT i+1 FROM n WHERE i < 25) SELECT 'Paginado', 'Smoke', CONCAT('smoke-pag-', LPAD(i, 2, '0'), '@example.com') FROM n;" >/dev/null
rows() { grep -c 'edit.php?id=' "$TMP/body"; }
ids() { grep -oE 'edit.php\?id=[0-9]+' "$TMP/body" | grep -oE '[0-9]+'; }
flat() { tr -d '\n' < "$TMP/body" | sed -E 's/\s+/ /g'; }
page_of() { chk "q=smoke-pag page=$1" 200 --get --data-urlencode "q=smoke-pag" --data-urlencode "page=$1" "$BASE/"; }
page_of 1; [ "$(rows)" = 10 ]; ok "página 1: 10 filas" $?
flat | grep -qF "25 resultados para «smoke-pag»"; ok "...el resumen cuenta los 25 (no los 10 de la página)" $?
flat | grep -qF "Mostrando 1 a 10."; ok "...y dice qué filas muestra (1 a 10)" $?
flat | grep -qE 'pager__item--disabled"><span class="pager__link">.{0,260}Anterior</span></span>'; ok "...\"Anterior\" está deshabilitado (un <span>, no un enlace)" $?
flat | grep -qE 'href="index.php\?q=smoke-pag&amp;page=2" rel="next"><span class="pager__label">Siguiente'; ok "...\"Siguiente\" lleva a la 2 y conserva la búsqueda" $?
grep -qF 'aria-current="page">1</span>' "$TMP/body"; ok "...la página actual (1) está marcada con aria-current" $?
[ "$(grep -c 'aria-current="page"' "$TMP/body")" = 1 ]; ok "...y solo una página está marcada" $?
tr -d '\n' < "$TMP/body" | grep -qF '<nav class="pager" aria-label="Paginación del listado de usuarios">'; ok "...la navegación es un <nav> con nombre accesible" $?
IDS1=$(ids)
page_of 2; [ "$(rows)" = 10 ]; ok "página 2: 10 filas" $?
flat | grep -qF "Mostrando 11 a 20."; ok "...resumen 11 a 20" $?
flat | grep -qE 'href="index.php\?q=smoke-pag" rel="prev">.{0,260}Anterior</span></a>'; ok "...\"Anterior\" lleva a la 1 SIN ?page=1 (cada estado tiene una sola URL)" $?
flat | grep -qE 'href="index.php\?q=smoke-pag&amp;page=3" rel="next"><span class="pager__label">Siguiente'; ok "...\"Siguiente\" lleva a la 3" $?
IDS2=$(ids)
page_of 3; [ "$(rows)" = 5 ]; ok "página 3 (la última): 5 filas" $?
flat | grep -qF "Mostrando 21 a 25."; ok "...resumen 21 a 25" $?
flat | grep -qE 'pager__item--disabled"><span class="pager__link"><span class="pager__label">Siguiente'; ok "...\"Siguiente\" está deshabilitado en la última" $?
IDS3=$(ids)
ALL=$(printf '%s\n%s\n%s\n' "$IDS1" "$IDS2" "$IDS3")
[ "$(printf '%s\n' "$ALL" | grep -c .)" = 25 ] && [ "$(printf '%s\n' "$ALL" | sort -u | grep -c .)" = 25 ]; ok "las 3 páginas suman 25 usuarios distintos: ni huecos ni repeticiones" $?
[ "$ALL" = "$(printf '%s\n' "$ALL" | sort -rn)" ]; ok "...y los ids van de mayor a menor a través de las páginas" $?
for bad in 4 999 99999999999999999999999; do page_of "$bad"; [ "$(rows)" = 5 ] && flat | grep -qF "Mostrando 21 a 25." && grep -qF 'aria-current="page">3</span>' "$TMP/body"; ok "?page=$bad se corrige a la última (la 3) con 200" $?; done
for bad in 0 abc -3 1.5 " 2" "+2" ""; do page_of "$bad"; [ "$(rows)" = 10 ] && flat | grep -qF "Mostrando 1 a 10."; ok "?page='$bad' cuenta como la página 1" $?; done
chk "?page[]=2 (un arreglo)" 200 "$BASE/?q=smoke-pag&page%5B%5D=2"; flat | grep -qF "Mostrando 1 a 10."; ok "...cuenta como la página 1" $?
page_of 2
LINKS=$(tr -d '\n' < "$TMP/body" | grep -oE '<nav class="pager" aria-label="Paginación.*</nav>' | grep -oE 'href="index.php[^"]*"')
[ "$(printf '%s\n' "$LINKS" | grep -c .)" -ge 4 ] && ! printf '%s\n' "$LINKS" | grep -qv 'q=smoke-pag'; ok "TODOS los enlaces de la paginación conservan la búsqueda" $?
chk "q con tildes y espacios, página 2" 200 --get --data-urlencode "q=smoke pag" --data-urlencode "page=2" "$BASE/"; ok "(el espacio de «smoke pag» no encuentra nada, pero no rompe)" 0
chk "una sola página no pinta la navegación" 200 --get --data-urlencode "q=smoke-busq" "$BASE/"; ! grep -qF 'aria-label="Paginación' "$TMP/body"; ok "...la búsqueda de 5 resultados no tiene <nav>" $?
chk "sin búsqueda: GET /" 200 "$BASE/"; TOTAL=$(sql 'SELECT COUNT(*) FROM users WHERE active=1;'); PAGES=$(( (TOTAL + 9) / 10 ))
flat | grep -qF "Lista de Usuarios ($TOTAL)"; ok "el título cuenta TODOS los usuarios ($TOTAL), no los de la página" $?
[ "$(rows)" = "$(( TOTAL < 10 ? TOTAL : 10 ))" ]; ok "...y la página 1 tiene $(( TOTAL < 10 ? TOTAL : 10 )) filas" $?
chk "sin búsqueda: la última página ($PAGES)" 200 "$BASE/?page=$PAGES"; [ "$(rows)" = "$(( TOTAL - 10 * (PAGES - 1) ))" ]; ok "...tiene $(( TOTAL - 10 * (PAGES - 1) )) filas" $?
chk "sin búsqueda: ?page=$(( PAGES + 7 ))" 200 "$BASE/?page=$(( PAGES + 7 ))"; [ "$(rows)" = "$(( TOTAL - 10 * (PAGES - 1) ))" ]; ok "...se corrige a la última" $?

section "El listado conserva la búsqueda y la página al editar y eliminar"
page_of 2
grep -qE 'href="edit.php\?id=[0-9]+&amp;q=smoke-pag&amp;page=2"' "$TMP/body"; ok "el enlace de editar lleva q y page" $?
tr -d '\n' < "$TMP/body" | grep -qE '<form method="post" action="delete.php"[^>]*>\s*<input type="hidden" name="csrf_token" value="[0-9a-f]{64}">\s*<input type="hidden" name="id" value="[0-9]+">\s*<input type="hidden" name="q" value="smoke-pag">\s*<input type="hidden" name="page" value="2">'; ok "el formulario de eliminar lleva q y page en campos ocultos" $?
page_of 1
! grep -qE 'edit.php\?id=[0-9]+&amp;page=' "$TMP/body" && grep -qE 'href="edit.php\?id=[0-9]+&amp;q=smoke-pag"' "$TMP/body"; ok "en la página 1 no se escribe page=1 en el enlace de editar" $?
EDIT_ID=$(printf '%s\n' "$IDS2" | head -1)
chk "abrir el formulario de edición desde la página 2" 200 "$BASE/edit.php?id=$EDIT_ID&q=smoke-pag&page=2"
grep -qF 'href="index.php?q=smoke-pag&amp;page=2">Cancelar</a>' "$TMP/body"; ok "\"Cancelar\" vuelve a la búsqueda y a la página 2" $?
tr -d '\n' < "$TMP/body" | grep -qE 'name="q" value="smoke-pag">\s*<input type="hidden" name="page" value="2"'; ok "el formulario lleva q y page en campos ocultos" $?
chk "guardar desde la página 2" 302 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&role=user&id=$EDIT_ID&q=smoke-pag&page=2&name=Paginado&last_name=Editado&email=smoke-pag-edit@example.com&phone=" "$BASE/edit.php"
[ "$(header_of location)" = "Location: index.php?msg=updated&q=smoke-pag&page=2" ]; ok "...y vuelve a la misma búsqueda y página (con el aviso)" $?
chk "guardar sin estado (como antes de M2)" 302 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&role=user&id=$EDIT_ID&name=Paginado&last_name=Editado&email=smoke-pag-edit@example.com&phone=" "$BASE/edit.php"
[ "$(header_of location)" = "Location: index.php?msg=updated" ]; ok "...sin estado sigue yendo a index.php?msg=updated" $?
chk "estado hostil al guardar (page=abc, q como arreglo)" 302 -H "Origin: $ORIGIN" --data-urlencode "q[]=x" -d "csrf_token=$CSRF&role=user&id=$EDIT_ID&page=abc&name=Paginado&last_name=Editado&email=smoke-pag-edit@example.com&phone=" "$BASE/edit.php"
[ "$(header_of location)" = "Location: index.php?msg=updated" ]; ok "...un estado inválido se ignora" $?
chk "q con un salto de línea para colar otra cabecera" 302 -H "Origin: $ORIGIN" --data-urlencode $'q=a\r\nSet-Cookie: hack=1' -d "csrf_token=$CSRF&role=user&id=$EDIT_ID&page=2&name=Paginado&last_name=Editado&email=smoke-pag-edit@example.com&phone=" "$BASE/edit.php"
! grep -qi '^Set-Cookie' "$TMP/head"; ok "...no se cuela ninguna cabecera Set-Cookie" $?
[ "$(header_of location)" = "Location: index.php?msg=updated&q=a%20Set-Cookie%3A%20hack%3D1&page=2" ]; ok "...el salto de línea queda como un espacio y todo el texto codificado en el Location" $?

section "Eliminar al último usuario de la última página (el caso límite de M2)"
page_of 3; LAST_IDS=$(ids); [ "$(printf '%s\n' "$LAST_IDS" | grep -c .)" = 5 ]; ok "la página 3 tiene 5 usuarios (de 25)" $?
for id in $LAST_IDS; do
    chk "eliminar el usuario $id desde la página 3" 302 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&id=$id&q=smoke-pag&page=3" "$BASE/delete.php"
done
[ "$(header_of location)" = "Location: index.php?msg=deleted&q=smoke-pag&page=3" ]; ok "al borrar al ÚLTIMO de la página 3, el Location sigue apuntando a la página 3 (que ya no existe)..." $?
chk "...y al seguir esa redirección" 200 "$BASE/index.php?msg=deleted&q=smoke-pag&page=3"
flat | grep -qF "Mostrando 11 a 20." && [ "$(rows)" = 10 ] && grep -qF 'aria-current="page">2</span>' "$TMP/body"; ok "...se muestra la nueva última página (la 2) con 10 usuarios, no una vacía" $?
grep -q 'notice--success' "$TMP/body"; ok "...y el aviso de eliminado se ve" $?
flat | grep -qE 'pager__item--disabled"><span class="pager__link"><span class="pager__label">Siguiente'; ok "...con \"Siguiente\" deshabilitado, porque ya es la última" $?
page_of 3; [ "$(rows)" = 10 ]; ok "pedir la 3 directamente también muestra la 2 (ya solo hay 20)" $?
chk "eliminar sin estado (como antes de M2)" 302 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&id=$(printf '%s\n' "$IDS1" | head -1)" "$BASE/delete.php"
[ "$(header_of location)" = "Location: index.php?msg=deleted" ]; ok "...sin estado sigue yendo a index.php?msg=deleted" $?
chk "eliminar con un estado hostil" 302 -H "Origin: $ORIGIN" --data-urlencode "q[]=x" -d "csrf_token=$CSRF&id=$(printf '%s\n' "$IDS1" | sed -n 2p)&page=%00abc" "$BASE/delete.php"
[ "$(header_of location)" = "Location: index.php?msg=deleted" ]; ok "...y un estado inválido se ignora" $?

section "Editar (edit.php)"
for q in "" "?id=abc" "?id%5B%5D=1" "?id=0" "?id=-3" "?id=9999" "?id=4294967296" "?id=%2B$U1" "?id=%20$U1" "?id=0$U1"; do
    chk "GET edit.php$q" 404 "$BASE/edit.php$q"
done
chk "POST sin id" 404 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&name=A&last_name=B&email=a@b.co" "$BASE/edit.php"
chk "POST con un id inexistente" 404 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&role=user&id=9999&name=Ana&last_name=B&email=a@b.co" "$BASE/edit.php"
chk "GET con un id válido" 200 "$BASE/edit.php?id=$U1"
tr -d '\n' < "$TMP/body" | grep -qE 'id="last_name"[^>]*value="Uno"'; ok "carga los datos actuales" $?
chk "POST SIN Origin (403 antes que mirar los datos)" 403 -d "csrf_token=$CSRF&role=user&id=$U1&name=Hack&last_name=Hack&email=smoke-one@example.com" "$BASE/edit.php"
[ "$(sql "SELECT last_name FROM users WHERE id=$U1;")" = "Uno" ]; ok "el 403 no modificó al usuario" $?
chk "POST con Origin ajeno" 403 -H "Origin: http://evil.example" -d "csrf_token=$CSRF&role=user&id=$U1&name=Hack&last_name=Hack&email=smoke-one@example.com" "$BASE/edit.php"
chk "POST SIN Origin con un id inexistente (403 antes que 404)" 403 -d "csrf_token=$CSRF&role=user&id=9999&name=A&last_name=B&email=a@b.co" "$BASE/edit.php"
chk "POST SIN Origin con datos inválidos (403 antes que 422)" 403 -d "csrf_token=$CSRF&role=user&id=$U1&name=&last_name=&email=" "$BASE/edit.php"
chk "POST con solo Referer correcto" 302 -H "Referer: $ORIGIN/edit.php?id=$U1" -d "csrf_token=$CSRF&role=user&id=$U1&name=Smoke&last_name=Uno&email=smoke-one@example.com&phone=%2B57%20300%20123%204567" "$BASE/edit.php"
chk "POST con el nombre vacío" 422 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&role=user&id=$U1&name=&last_name=Uno&email=smoke-one@example.com" "$BASE/edit.php"
grep -q "name=\"id\" value=\"$U1\"" "$TMP/body"; ok "conserva el id oculto" $?
chk "POST con el correo de otro usuario" 409 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&role=user&id=$U1&name=Smoke&last_name=Uno&email=smoke-cat@example.com" "$BASE/edit.php"
chk "POST sin cambiar ningún valor" 302 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&role=user&id=$U1&name=Smoke&last_name=Uno&email=smoke-one@example.com" --data-urlencode "phone=+57 300 123 4567" "$BASE/edit.php"
chk "POST cambiando solo el apellido y vaciando el teléfono" 302 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&role=user&id=$U1&name=Smoke&last_name=Editado&email=smoke-one@example.com&phone=" "$BASE/edit.php"
[ "$(sql "SELECT IFNULL(phone,'NULL') FROM users WHERE id=$U1;")" = NULL ]; ok "el teléfono vacío queda como NULL" $?

section "Ediciones simultáneas (la persona A abre el formulario; la B guarda antes que ella)"
token_of() { tr -d '\n' < "$TMP/body" | grep -oE 'name="row_token" value="[0-9a-f]{64}"' | grep -oE '[0-9a-f]{64}'; }
chk "A abre el formulario de edición" 200 "$BASE/edit.php?id=$U1"
TOKEN_A=$(token_of); [ -n "$TOKEN_A" ]; ok "el formulario lleva un row_token de 64 caracteres" $?
chk "B guarda un cambio (sin token: se acepta como siempre)" 302 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&role=user&id=$U1&name=Smoke&last_name=DeB&email=smoke-one@example.com&phone=" "$BASE/edit.php"
chk "A guarda con la huella VIEJA: conflicto" 409 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&role=user&id=$U1&row_token=$TOKEN_A&name=Smoke&last_name=DeA&email=smoke-one@example.com&phone=" "$BASE/edit.php"
[ "$(sql "SELECT last_name FROM users WHERE id=$U1;")" = "DeB" ]; ok "no se pisó lo que guardó B" $?
grep -q 'Otra persona modificó a este usuario' "$TMP/body"; ok "el aviso explica qué pasó" $?
grep -q 'value="DeA"' "$TMP/body"; ok "se conserva lo que había escrito A" $?
grep -q "value=\"$TOKEN_A\"" "$TMP/body"; ok "se conserva la huella vieja: hay que recargar para poder guardar" $?
chk "A insiste con la huella vieja (datos inválidos): el conflicto va antes que el 422" 409 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&role=user&id=$U1&row_token=$TOKEN_A&name=&last_name=&email=" "$BASE/edit.php"
chk "A recarga el formulario" 200 "$BASE/edit.php?id=$U1"
TOKEN_A2=$(token_of); [ "$TOKEN_A2" != "$TOKEN_A" ]; ok "la huella nueva es distinta de la vieja" $?
chk "A guarda con la huella NUEVA" 302 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&role=user&id=$U1&row_token=$TOKEN_A2&name=Smoke&last_name=DeA&email=smoke-one@example.com&phone=" "$BASE/edit.php"
[ "$(sql "SELECT last_name FROM users WHERE id=$U1;")" = "DeA" ]; ok "ahora sí se guardó lo de A" $?
chk "A abre otra vez" 200 "$BASE/edit.php?id=$U1"
TOKEN_A3=$(token_of)
chk "B guarda EXACTAMENTE los mismos valores" 302 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&role=user&id=$U1&name=Smoke&last_name=DeA&email=smoke-one@example.com&phone=" "$BASE/edit.php"
chk "A guarda: no hay conflicto porque los datos no cambiaron" 302 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&role=user&id=$U1&row_token=$TOKEN_A3&name=Smoke&last_name=DeA2&email=smoke-one@example.com&phone=" "$BASE/edit.php"
chk "un token mal formado se trata como un conflicto, no como un error" 409 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&role=user&id=$U1&row_token=basura&name=Smoke&last_name=X&email=smoke-one@example.com&phone=" "$BASE/edit.php"
chk "con row_token como arreglo se ignora (como si no llegara)" 302 -H "Origin: $ORIGIN" --data-urlencode "row_token[]=x" -d "csrf_token=$CSRF&role=user&id=$U1&name=Smoke&last_name=DeA&email=smoke-one@example.com&phone=" "$BASE/edit.php"

section "Más campos (M3): documento, fecha de nacimiento y rol"
chk "formulario de alta" 200 "$BASE/create.php"
tr -d '\n' < "$TMP/body" | grep -qE 'type="date"[^>]*id="birth_date"|id="birth_date"[^>]*type="date"|type="date"\s+class="form-control"\s+id="birth_date"'; ok "la fecha de nacimiento es un <input type=\"date\">" $?
[ "$(grep -cE '<option value="(user|editor|admin)"' "$TMP/body")" = 3 ]; ok "el rol es un <select> con exactamente 3 opciones" $?
grep -qF '<option value="user" selected>Usuario</option>' "$TMP/body" && [ "$(grep -c ' selected' "$TMP/body")" = 1 ]; ok "...y solo «Usuario» viene seleccionado" $?
grep -qE 'name="document"[^>]*maxlength="20"|maxlength="20"[^>]*name="document"' <(tr -d '\n' < "$TMP/body"); ok "el documento tiene maxlength=20" $?

chk "alta con todos los campos (documento en minúsculas)" 302 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&name=Mas&last_name=Campos&email=smoke-m3-a@example.com&document=ab-12345&birth_date=1990-05-17&role=editor" "$BASE/create.php"
UA=$(id_of smoke-m3-a@example.com)
[ "$(sql "SELECT CONCAT(document,'|',birth_date,'|',role) FROM users WHERE id=$UA;")" = "AB-12345|1990-05-17|editor" ]; ok "se guardó: documento en MAYÚSCULAS, fecha y rol" $?
chk "alta sin documento ni fecha" 302 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&name=Sin&last_name=Extras&email=smoke-m3-b@example.com&document=&birth_date=&role=user" "$BASE/create.php"
chk "...y otra más (varios sin documento no chocan en el UNIQUE)" 302 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&name=Sin&last_name=Extras2&email=smoke-m3-c@example.com&document=&birth_date=&role=user" "$BASE/create.php"
[ "$(sql "SELECT COUNT(*) FROM users WHERE email IN ('smoke-m3-b@example.com','smoke-m3-c@example.com') AND document IS NULL AND birth_date IS NULL;")" = 2 ]; ok "lo vacío se guardó como NULL (no como cadena vacía)" $?
UB=$(id_of smoke-m3-b@example.com)

chk "documento repetido" 409 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&name=Dup&last_name=Doc&email=smoke-m3-d@example.com&document=AB-12345&role=user" "$BASE/create.php"
grep -qF 'Ya existe un usuario registrado con ese documento.' "$TMP/body"; ok "...con el aviso del documento (no el del correo)" $?
! grep -qF 'ese correo' "$TMP/body"; ok "...y sin el aviso del correo" $?
tr -d '\n' < "$TMP/body" | grep -qE 'id="document"[^>]*aria-invalid="true"'; ok "...el campo documento queda marcado" $?
chk "documento repetido en otra capitalización (ab-12345)" 409 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&name=Dup&last_name=Doc&email=smoke-m3-d@example.com&document=ab-12345&role=user" "$BASE/create.php"
chk "correo repetido: sigue avisando del correo" 409 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&name=Dup&last_name=Doc&email=smoke-m3-a@example.com&document=ZZ-99999&role=user" "$BASE/create.php"
grep -qF 'Ya existe un usuario registrado con ese correo.' "$TMP/body"; ok "...con el aviso del correo" $?
[ "$(sql "SELECT COUNT(*) FROM users WHERE email='smoke-m3-d@example.com';")" = 0 ]; ok "ninguno de los repetidos se guardó" $?

chk "rol inexistente (se manipuló el select)" 422 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&name=Rol&last_name=Falso&email=smoke-m3-e@example.com&role=superadmin" "$BASE/create.php"
grep -qF 'Selecciona un rol de la lista.' "$TMP/body"; ok "...con su aviso" $?
grep -qF '<option value="" selected disabled>Selecciona…</option>' "$TMP/body"; ok "...y el select vuelve sin ninguna opción elegida (no se pinta el valor falso)" $?
! grep -q 'superadmin' "$TMP/body"; ok "...ni se refleja el valor manipulado" $?
chk "rol con otra capitalización (ADMIN)" 422 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&name=Rol&last_name=Falso&email=smoke-m3-e@example.com&role=ADMIN" "$BASE/create.php"
chk "rol vacío" 422 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&name=Rol&last_name=Falso&email=smoke-m3-e@example.com&role=" "$BASE/create.php"
chk "sin rol (curl como antes de M3)" 422 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&name=Rol&last_name=Falso&email=smoke-m3-e@example.com" "$BASE/create.php"
chk "rol como arreglo" 422 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&name=Rol&last_name=Falso&email=smoke-m3-e@example.com&role[]=admin" "$BASE/create.php"
[ "$(sql "SELECT COUNT(*) FROM users WHERE email='smoke-m3-e@example.com';")" = 0 ]; ok "ningún rol falso llegó a la BD" $?

for bad in 2023-02-30 1990-13-01 17/05/1990 1990-5-17 ayer 2999-01-01 1899-12-31; do
    chk "fecha de nacimiento inválida «$bad»" 422 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&name=Fe&last_name=Cha&email=smoke-m3-f@example.com&birth_date=$bad&role=user" "$BASE/create.php"
done
tr -d '\n' < "$TMP/body" | grep -qE 'name="birth_date"\s+value="1899-12-31"'; ok "...y el formulario conserva lo escrito en la fecha" $?
chk "documento inválido (con espacios)" 422 -H "Origin: $ORIGIN" --data-urlencode "document=123 456" -d "csrf_token=$CSRF&name=Do&last_name=Cu&email=smoke-m3-f@example.com&role=user" "$BASE/create.php"
chk "documento corto" 422 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&name=Do&last_name=Cu&email=smoke-m3-f@example.com&document=1234&role=user" "$BASE/create.php"
chk "documento de 21 caracteres" 422 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&name=Do&last_name=Cu&email=smoke-m3-f@example.com&document=$(printf '9%.0s' $(seq 1 21))&role=user" "$BASE/create.php"
[ "$(sql "SELECT COUNT(*) FROM users WHERE email='smoke-m3-f@example.com';")" = 0 ]; ok "ninguno de los inválidos se guardó" $?

section "Más campos (M3): listado y búsqueda"
chk "listado buscando por documento (en minúsculas y a medias)" 200 --get --data-urlencode "q=b-123" "$BASE/"
[ "$(grep -c 'edit.php?id=' "$TMP/body")" = 1 ] && grep -qF 'data-label="Documento" class="num">AB-12345</td>' "$TMP/body"; ok "la búsqueda encuentra por documento y lo muestra" $?
tr -d '\n' < "$TMP/body" | grep -qE 'data-label="Documento" class="num">AB-12345</td>\s*<td data-label="Nacimiento" class="col-secondary num">1990-05-17</td>\s*<td data-label="Rol"><span class="tag tag--editor">Editor</span></td>'; ok "...con la fecha y la ETIQUETA del rol (Editor)" $?
chk "listado de uno sin los campos nuevos" 200 --get --data-urlencode "q=smoke-m3-b" "$BASE/"
tr -d '\n' < "$TMP/body" | grep -qE 'data-label="Teléfono" class="num is-empty is-muted">—</td>\s*<td data-label="Documento" class="num is-empty is-muted">—</td>\s*<td data-label="Nacimiento" class="col-secondary num is-empty is-muted">—</td>\s*<td data-label="Rol"><span class="tag tag--user">Usuario</span></td>'; ok "teléfono, documento y fecha vacíos se ven como «—» (y se ocultan en móvil) y el rol «Usuario»" $?

section "Más campos (M3): edición, usuarios antiguos y ediciones simultáneas"
chk "editar al usuario con todos los campos" 200 "$BASE/edit.php?id=$UA"
tr -d '\n' < "$TMP/body" | grep -qE 'name="document"\s+value="AB-12345"'; ok "carga el documento" $?
tr -d '\n' < "$TMP/body" | grep -qE 'name="birth_date"\s+value="1990-05-17"'; ok "carga la fecha de nacimiento" $?
grep -qF '<option value="editor" selected>Editor</option>' "$TMP/body" && [ "$(grep -c ' selected' "$TMP/body")" = 1 ]; ok "carga el rol (solo «Editor» seleccionado)" $?
TOKEN_M3=$(grep -oE 'name="row_token" value="[0-9a-f]{64}"' "$TMP/body" | grep -oE '[0-9a-f]{64}')
sql "INSERT INTO users (name, last_name, email) VALUES ('Antiguo', 'Sin Campos', 'smoke-m3-old@example.com');" >/dev/null
UOLD=$(id_of smoke-m3-old@example.com)
[ "$(sql "SELECT CONCAT(IFNULL(document,'NULL'),'|',IFNULL(birth_date,'NULL'),'|',role) FROM users WHERE id=$UOLD;")" = "NULL|NULL|user" ]; ok "una fila creada SIN los campos nuevos (como las de antes de la migración) queda NULL|NULL|user" $?
chk "editar al usuario antiguo" 200 "$BASE/edit.php?id=$UOLD"
tr -d '\n' < "$TMP/body" | grep -qE 'name="document"\s+value=""' && tr -d '\n' < "$TMP/body" | grep -qE 'name="birth_date"\s+value=""' && grep -qF '<option value="user" selected>Usuario</option>' "$TMP/body"; ok "...se abre con documento y fecha vacíos y el rol «Usuario»" $?
chk "guardar al antiguo sin tocar nada nuevo" 302 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&id=$UOLD&name=Antiguo&last_name=Sin+Campos&email=smoke-m3-old@example.com&phone=&document=&birth_date=&role=user" "$BASE/edit.php"
[ "$(sql "SELECT CONCAT(IFNULL(document,'NULL'),'|',IFNULL(birth_date,'NULL')) FROM users WHERE id=$UOLD;")" = "NULL|NULL" ]; ok "...y siguen en NULL" $?
chk "ponerle a otro un documento que ya existe" 409 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&id=$UOLD&name=Antiguo&last_name=Sin+Campos&email=smoke-m3-old@example.com&document=ab-12345&role=user" "$BASE/edit.php"
grep -qF 'Ya existe otro usuario registrado con ese documento.' "$TMP/body"; ok "...con el aviso del documento" $?
chk "guardar a A conservando SU PROPIO documento (no choca consigo mismo)" 302 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&id=$UA&name=Mas&last_name=Campos&email=smoke-m3-a@example.com&document=AB-12345&birth_date=1990-05-17&role=editor" "$BASE/edit.php"
chk "cambiar el rol de A a admin" 302 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&id=$UA&name=Mas&last_name=Campos&email=smoke-m3-a@example.com&document=AB-12345&birth_date=1990-05-17&role=admin&password=clave-m3-larga-1&password_confirm=clave-m3-larga-1" "$BASE/edit.php"
[ "$(sql "SELECT role FROM users WHERE id=$UA;")" = admin ]; ok "el rol cambió en la BD" $?
chk "rol manipulado al editar" 422 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&id=$UA&name=Mas&last_name=Campos&email=smoke-m3-a@example.com&role=root" "$BASE/edit.php"
[ "$(sql "SELECT role FROM users WHERE id=$UA;")" = admin ]; ok "...y el rol guardado no cambió" $?
chk "quitar documento y fecha a A" 302 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&id=$UA&name=Mas&last_name=Campos&email=smoke-m3-a@example.com&document=&birth_date=&role=admin" "$BASE/edit.php"
[ "$(sql "SELECT CONCAT(IFNULL(document,'NULL'),'|',IFNULL(birth_date,'NULL')) FROM users WHERE id=$UA;")" = "NULL|NULL" ]; ok "vaciarlos los deja en NULL" $?
chk "A guarda con la huella VIEJA tras cambiar solo el rol (el rol cuenta en la huella)" 409 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&id=$UA&row_token=$TOKEN_M3&name=Mas&last_name=Campos&email=smoke-m3-a@example.com&role=user" "$BASE/edit.php"
chk "fecha inválida al editar" 422 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&id=$UA&name=Mas&last_name=Campos&email=smoke-m3-a@example.com&birth_date=2023-02-30&role=admin" "$BASE/edit.php"
tr -d '\n' < "$TMP/body" | grep -qE 'name="birth_date"\s+value="2023-02-30"'; ok "...y conserva lo escrito" $?

section "Acceso de administradores: un usuario con rol admin y contraseña puede iniciar sesión"
# Intenta iniciar sesión (jarra $1, correo $2, contraseña $3) y deja el código HTTP en $LOGIN_CODE.
login_code() { anon_session "$1"; LOGIN_CODE=$(command curl -s -o /dev/null -w '%{http_code}' -b "$1" -c "$1" -H "Origin: $ORIGIN" --data-urlencode "csrf_token=$ATOKEN" --data-urlencode "email=$2" --data-urlencode "password=$3" "$BASE/login.php"); }
chk "formulario de alta" 200 "$BASE/create.php"
tr -d '\n' < "$TMP/body" | grep -qE '<div class="field" data-gate="admin">\s*<label for="password"[^>]*>Contraseña</label>\s*<input\s+type="password"\s+class="input"\s+id="password"\s+name="password"\s+autocomplete="new-password"'; ok "hay un campo de contraseña (type=password, autocomplete=new-password) que solo se enseña con el rol admin" $?
tr -d '\n' < "$TMP/body" | grep -qE 'data-gate="admin">\s*<label for="password_confirm"[^>]*>Repite la contraseña</label>\s*<input\s+type="password"[^>]*id="password_confirm"'; ok "...y otro para repetirla" $?
! grep -qE 'id="password(_confirm)?"[^>]*value=' "$TMP/body"; ok "los campos de contraseña NUNCA llevan value" $?
grep -qF 'Mínimo 10 caracteres.' "$TMP/body"; ok "con una ayuda sobre la contraseña" $?
ACC_PW="clave-acceso-$(head -c 6 /dev/urandom | od -An -tx1 | tr -d ' \n')"
post_user() { chk "$1" "$2" -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&name=Acceso&last_name=Prueba&email=$3&role=$4&password=$5&password_confirm=$6&document=&birth_date=&phone=" "$BASE/create.php"; }
post_user "admin sin contraseña" 422 smoke-acc-a@example.com admin "" ""
grep -qF 'Escribe una contraseña para que pueda iniciar sesión.' "$TMP/body"; ok "...avisa que hace falta" $?
post_user "admin con contraseña que no coincide" 422 smoke-acc-a@example.com admin "$ACC_PW" "${ACC_PW}x"
grep -qF 'Las contraseñas no coinciden.' "$TMP/body"; ok "...avisa que no coinciden" $?
! grep -qF "$ACC_PW" "$TMP/body"; ok "...y la contraseña escrita NO se vuelve a pintar en la página" $?
post_user "admin con contraseña corta" 422 smoke-acc-a@example.com admin "corta" "corta"
grep -qF 'al menos 10 caracteres' "$TMP/body"; ok "...avisa que es corta" $?
[ "$(sql "SELECT COUNT(*) FROM users WHERE email='smoke-acc-a@example.com';")" = 0 ]; ok "ninguno de los intentos inválidos creó al usuario" $?
post_user "admin con contraseña válida" 302 smoke-acc-a@example.com admin "$ACC_PW" "$ACC_PW"
UACC=$(id_of smoke-acc-a@example.com)
[ "$(sql "SELECT CONCAT(role,'|',IF(password_hash LIKE '\$2y\$%','bcrypt','no'),'|',IF(INSTR(password_hash,'$ACC_PW')=0,'sin-clave','CLAVE-EN-CLARO') ) FROM users WHERE id=$UACC;")" = "admin|bcrypt|sin-clave" ]; ok "se guardó el HASH bcrypt, no la contraseña" $?
login_code "$TMP/jar_acc" smoke-acc-a@example.com "$ACC_PW"; [ "$LOGIN_CODE" = 302 ]; ok "el nuevo administrador INICIA SESIÓN con su correo y contraseña" $?
achk "...y entra al listado" 200 -b "$TMP/jar_acc" "$BASE/"
grep -qF 'smoke-acc-a@example.com</span>' "$TMP/body"; ok "...donde el encabezado dice que es él" $?
login_code "$TMP/jar_acc2" smoke-acc-a@example.com "${ACC_PW}x"; [ "$LOGIN_CODE" = 401 ]; ok "con una contraseña incorrecta no entra (401)" $?
chk "listado con el nuevo administrador" 200 --get --data-urlencode "q=smoke-acc-a" "$BASE/"
grep -qF 'class="access" title="Puede iniciar sesión"' "$TMP/body"; ok "el listado marca con una llave a quien puede iniciar sesión" $?
! grep -qE '\$2y\$' "$TMP/body"; ok "...y el hash nunca sale en ninguna página" $?

section "Acceso: solo entra quien tiene rol admin, está activo y tiene contraseña"
post_user "editor con contraseña (se ignora)" 302 smoke-acc-e@example.com editor "$ACC_PW" "$ACC_PW"
UEDI=$(id_of smoke-acc-e@example.com)
[ "$(sql "SELECT password_hash IS NULL FROM users WHERE id=$UEDI;")" = 1 ]; ok "con otro rol la contraseña NO se guarda" $?
login_code "$TMP/jar_e" smoke-acc-e@example.com "$ACC_PW"; [ "$LOGIN_CODE" = 401 ]; ok "un editor no puede iniciar sesión, ni con esa contraseña" $?
post_user "usuario normal" 302 smoke-acc-u@example.com user "" ""
login_code "$TMP/jar_u" smoke-acc-u@example.com ""; [ "$LOGIN_CODE" = 422 ]; ok "un usuario normal no puede (y una clave vacía se pide)" $?
post_user "admin sin contraseña heredado (SQL directo, como antes de esta función)" 302 smoke-acc-l@example.com user "" ""
ULEG=$(id_of smoke-acc-l@example.com); sql "UPDATE users SET role='admin' WHERE id=$ULEG;" >/dev/null
chk "editar a un admin SIN contraseña dejándola vacía" 302 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&id=$ULEG&name=Acceso&last_name=Heredado&email=smoke-acc-l@example.com&role=admin&password=&password_confirm=" "$BASE/edit.php"
[ "$(sql "SELECT last_name FROM users WHERE id=$ULEG;")" = Heredado ]; ok "no se le exige una contraseña (ya era admin: el dato de antes sigue siendo válido)" $?
chk "formulario de edición de un admin con acceso" 200 "$BASE/edit.php?id=$UACC"
grep -qF 'Déjala vacía para conservar la actual.' "$TMP/body"; ok "la ayuda dice que vacía conserva la actual" $?
! grep -qF "$ACC_PW" "$TMP/body" && ! grep -qE 'id="password(_confirm)?"[^>]*value=' "$TMP/body"; ok "...y la contraseña no está en el HTML" $?
ACC_PW2="${ACC_PW}-nueva"
chk "guardar al admin con la contraseña VACÍA" 302 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&id=$UACC&name=Acceso&last_name=Cambiado&email=smoke-acc-a@example.com&role=admin&password=&password_confirm=" "$BASE/edit.php"
login_code "$TMP/jar_a2" smoke-acc-a@example.com "$ACC_PW"; [ "$LOGIN_CODE" = 302 ]; ok "vacía conserva la contraseña: sigue entrando con la de siempre" $?
chk "cambiar la contraseña" 302 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&id=$UACC&name=Acceso&last_name=Cambiado&email=smoke-acc-a@example.com&role=admin&password=$ACC_PW2&password_confirm=$ACC_PW2" "$BASE/edit.php"
login_code "$TMP/jar_a3" smoke-acc-a@example.com "$ACC_PW"; [ "$LOGIN_CODE" = 401 ]; ok "la contraseña vieja deja de valer" $?
login_code "$TMP/jar_a4" smoke-acc-a@example.com "$ACC_PW2"; [ "$LOGIN_CODE" = 302 ]; ok "la nueva sí" $?
chk "cambiar la contraseña con una repetición distinta" 422 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&id=$UACC&name=Acceso&last_name=Cambiado&email=smoke-acc-a@example.com&role=admin&password=otra-clave-larga&password_confirm=distinta-clave-larga" "$BASE/edit.php"
login_code "$TMP/jar_a5" smoke-acc-a@example.com "$ACC_PW2"; [ "$LOGIN_CODE" = 302 ]; ok "...y no cambió nada (la nueva anterior sigue valiendo)" $?
chk "cambiar el correo del administrador" 302 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&id=$UACC&name=Acceso&last_name=Cambiado&email=smoke-acc-a2@example.com&role=admin&password=&password_confirm=" "$BASE/edit.php"
login_code "$TMP/jar_a6" smoke-acc-a@example.com "$ACC_PW2"; [ "$LOGIN_CODE" = 401 ]; ok "con el correo VIEJO ya no entra (una sola fuente de verdad)" $?
login_code "$TMP/jar_a7" smoke-acc-a2@example.com "$ACC_PW2"; [ "$LOGIN_CODE" = 302 ]; ok "con el nuevo sí" $?
chk "ascender al editor a administrador SIN contraseña" 422 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&id=$UEDI&name=Acceso&last_name=Prueba&email=smoke-acc-e@example.com&role=admin&password=&password_confirm=" "$BASE/edit.php"
grep -qF 'Escribe una contraseña para que pueda iniciar sesión.' "$TMP/body"; ok "...la contraseña es obligatoria al ascender" $?
chk "ascender al editor a administrador CON contraseña" 302 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&id=$UEDI&name=Acceso&last_name=Prueba&email=smoke-acc-e@example.com&role=admin&password=$ACC_PW&password_confirm=$ACC_PW" "$BASE/edit.php"
login_code "$TMP/jar_e2" smoke-acc-e@example.com "$ACC_PW"; [ "$LOGIN_CODE" = 302 ]; ok "...y entra" $?
chk "quitarle el rol de administrador" 302 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&id=$UEDI&name=Acceso&last_name=Prueba&email=smoke-acc-e@example.com&role=editor&password=&password_confirm=" "$BASE/edit.php"
[ "$(sql "SELECT password_hash IS NULL FROM users WHERE id=$UEDI;")" = 1 ]; ok "se BORRA su hash (no queda una contraseña guardada sin rol)" $?
login_code "$TMP/jar_e3" smoke-acc-e@example.com "$ACC_PW"; [ "$LOGIN_CODE" = 401 ]; ok "...y deja de poder entrar" $?
chk "volver a darle el rol sin contraseña" 422 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&id=$UEDI&name=Acceso&last_name=Prueba&email=smoke-acc-e@example.com&role=admin&password=&password_confirm=" "$BASE/edit.php"
chk "eliminar (borrado lógico) al administrador" 302 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&id=$UACC" "$BASE/delete.php"
login_code "$TMP/jar_a8" smoke-acc-a2@example.com "$ACC_PW2"; [ "$LOGIN_CODE" = 401 ]; ok "un administrador ELIMINADO no puede iniciar sesión" $?
chk "restaurarlo" 302 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&id=$UACC" "$BASE/restore.php"
login_code "$TMP/jar_a9" smoke-acc-a2@example.com "$ACC_PW2"; [ "$LOGIN_CODE" = 302 ]; ok "...y al restaurarlo vuelve a entrar con la misma contraseña" $?
chk "SQL y comillas en la contraseña" 302 -H "Origin: $ORIGIN" --data-urlencode "password=x'; DROP TABLE users; -- \"clave\\" --data-urlencode "password_confirm=x'; DROP TABLE users; -- \"clave\\" -d "csrf_token=$CSRF&id=$UACC&name=Acceso&last_name=Cambiado&email=smoke-acc-a2@example.com&role=admin" "$BASE/edit.php"
login_code "$TMP/jar_a10" smoke-acc-a2@example.com "x'; DROP TABLE users; -- \"clave\\"; [ "$LOGIN_CODE" = 302 ]; ok "una contraseña con comillas, punto y coma y barra invertida se guarda y vale tal cual" $?
[ "$(sql 'SELECT COUNT(*) > 0 FROM users;')" = 1 ]; ok "...y la tabla users sigue ahí" $?

section "Acceso: nadie se quita a sí mismo el acceso"
SELF=$(id_of smoke-acc-a2@example.com)
login_code "$TMP/jar_self" smoke-acc-a2@example.com "x'; DROP TABLE users; -- \"clave\\"
SELFTOK=$(command curl -s -b "$TMP/jar_self" "$BASE/create.php" | grep -oE 'name="csrf_token" value="[0-9a-f]{64}"' | head -1 | grep -oE '[0-9a-f]{64}')
achk "como ese administrador: eliminarse a sí mismo" 302 -b "$TMP/jar_self" -H "Origin: $ORIGIN" -d "csrf_token=$SELFTOK&id=$SELF" "$BASE/delete.php"
[ "$(header_of location)" = "Location: index.php?msg=self" ]; ok "se rechaza con un aviso (msg=self)" $?
is_active "$SELF"; ok "...y sigue activo" $?
achk "...el aviso se ve" 200 -b "$TMP/jar_self" "$BASE/index.php?msg=self"
grep -qF 'No puedes eliminar tu propia cuenta' "$TMP/body"; ok "con su texto" $?
achk "quitarse a sí mismo el rol de administrador" 422 -b "$TMP/jar_self" -H "Origin: $ORIGIN" -d "csrf_token=$SELFTOK&id=$SELF&name=Acceso&last_name=Cambiado&email=smoke-acc-a2@example.com&role=editor&password=&password_confirm=" "$BASE/edit.php"
grep -qF 'No puedes quitarte el rol de administrador a ti mismo' "$TMP/body"; ok "se rechaza con su aviso" $?
[ "$(sql "SELECT role FROM users WHERE id=$SELF;")" = admin ]; ok "...y sigue siendo administrador" $?
achk "cambiarse el correo a sí mismo" 302 -b "$TMP/jar_self" -c "$TMP/jar_self" -H "Origin: $ORIGIN" -d "csrf_token=$SELFTOK&id=$SELF&name=Acceso&last_name=Cambiado&email=smoke-acc-a3@example.com&role=admin&password=&password_confirm=" "$BASE/edit.php"
achk "...el encabezado enseña el correo nuevo sin volver a entrar" 200 -b "$TMP/jar_self" "$BASE/"
grep -qF 'smoke-acc-a3@example.com</span>' "$TMP/body"; ok "(la sesión se actualiza)" $?
chk "otro administrador SÍ puede eliminarlo" 302 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&id=$SELF" "$BASE/delete.php"
[ "$(header_of location)" = "Location: index.php?msg=deleted" ]; ok "...con el aviso normal de eliminado" $?
printf '%s\n' "clave-de-consola-1" | bin/as-migrator php scripts/create-admin.php smoke-acc-u@example.com 2>&1 | grep -qF 'ya era un usuario: ahora es administrador'; ok "la consola PROMUEVE a un usuario existente (no falla ni lo duplica)" $?
login_code "$TMP/jar_c" smoke-acc-u@example.com "clave-de-consola-1"; [ "$LOGIN_CODE" = 302 ]; ok "...y entra con esa contraseña" $?
[ "$(sql "SELECT COUNT(*) FROM users WHERE email='smoke-acc-u@example.com';")" = 1 ]; ok "...sin duplicar al usuario" $?

section "Eliminar (delete.php)"
chk "GET (no borra)" 405 "$BASE/delete.php?id=$U1"
[ "$(header_of allow)" = "Allow: POST" ]; ok "405 con Allow: POST" $?
chk "PUT" 405 -X PUT "$BASE/delete.php"
exists "$U1"; ok "tras los 405, el usuario sigue existiendo" $?
chk "POST sin ninguna cabecera de origen" 403 -d "csrf_token=$CSRF&id=$U1" "$BASE/delete.php"
chk "POST con Origin ajeno" 403 -H "Origin: http://evil.example" -d "csrf_token=$CSRF&id=$U1" "$BASE/delete.php"
chk "POST con Origin: null" 403 -H "Origin: null" -d "csrf_token=$CSRF&id=$U1" "$BASE/delete.php"
chk "POST con Origin vacío y Referer correcto" 403 -H "Origin;" -H "Referer: $ORIGIN/" -d "csrf_token=$CSRF&id=$U1" "$BASE/delete.php"
chk "POST con Origin ajeno y Referer correcto" 403 -H "Origin: http://evil.example" -H "Referer: $ORIGIN/" -d "csrf_token=$CSRF&id=$U1" "$BASE/delete.php"
chk "POST con un Origin que empieza igual que el nuestro" 403 -H "Origin: $ORIGIN.evil.com" -d "csrf_token=$CSRF&id=$U1" "$BASE/delete.php"
chk "POST sin Origin y con id=abc (403 antes que 400)" 403 -d "csrf_token=$CSRF&id=abc" "$BASE/delete.php"
exists "$U1"; ok "tras los 403, el usuario sigue existiendo" $?
for bad in "" "id=abc" "id=0" "id=%2B$U1" "id%5B%5D=1"; do chk "POST con origen correcto y '$bad'" 400 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&$bad" "$BASE/delete.php"; done
chk "POST con solo Referer correcto (con ruta y query)" 302 -H "Referer: $ORIGIN/index.php?msg=created" -d "csrf_token=$CSRF&id=$U1" "$BASE/delete.php"
header_of location | grep -q 'msg=deleted'; ok "redirige a msg=deleted" $?
soft_deleted "$U1"; ok "el usuario NO se borró: la fila sigue con active = 0" $?
chk "repetir el borrado (ya estaba eliminado)" 302 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&id=$U1" "$BASE/delete.php"
header_of location | grep -q 'msg=missing'; ok "redirige a msg=missing (no es un error)" $?
chk "POST con un id válido que nunca existió" 302 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&id=4294967295" "$BASE/delete.php"
chk "borrar al usuario con apóstrofe, con Origin en mayúsculas" 302 -H "Origin: $(printf '%s' "$ORIGIN" | tr '[:lower:]' '[:upper:]')" -d "csrf_token=$CSRF&id=$UO" "$BASE/delete.php"
soft_deleted "$UO"; ok "el usuario O'Brien quedó con active = 0 (la fila sigue)" $?

section "Borrado lógico (M4): la fila se conserva y deja de verse"
chk "alta de un usuario para eliminar" 302 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&name=Papelera&last_name=Prueba&email=smoke-m4-a@example.com&document=PA-123456&role=editor" "$BASE/create.php"
M4=$(id_of smoke-m4-a@example.com)
chk "otro usuario que se queda" 302 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&name=Papelera&last_name=Quedo&email=smoke-m4-b@example.com&role=user" "$BASE/create.php"
M4B=$(id_of smoke-m4-b@example.com)
chk "buscar antes de eliminar" 200 --get --data-urlencode "q=smoke-m4" "$BASE/"
[ "$(grep -c 'edit.php?id=' "$TMP/body")" = 2 ]; ok "se ven los 2 usuarios" $?
chk "eliminar a uno" 302 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&id=$M4" "$BASE/delete.php"
[ "$(header_of location)" = "Location: index.php?msg=deleted" ]; ok "redirige a msg=deleted" $?
[ "$(sql "SELECT CONCAT(email,'|',document,'|',role,'|',active) FROM users WHERE id=$M4;")" = "smoke-m4-a@example.com|PA-123456|editor|0" ]; ok "la fila conserva TODOS sus datos y solo cambia active a 0" $?
chk "listado tras eliminar" 200 --get --data-urlencode "q=smoke-m4" "$BASE/"
[ "$(grep -c 'edit.php?id=' "$TMP/body")" = 1 ] && ! grep -q "id=$M4" "$TMP/body"; ok "el eliminado ya no sale en el listado (queda 1)" $?
tr -d '\n' < "$TMP/body" | sed -E 's/\s+/ /g' | grep -qF "Lista de Usuarios"; ok "...que sigue siendo «Lista de Usuarios»" $?
chk "buscar por su documento" 200 --get --data-urlencode "q=PA-123456" "$BASE/"
! grep -q 'edit.php?id=' "$TMP/body" && grep -qF 'No se encontraron resultados' "$TMP/body"; ok "...tampoco se encuentra por documento" $?
chk "el total del título (sin buscar) no cuenta al eliminado" 200 "$BASE/"
TOTAL_ACTIVE=$(sql 'SELECT COUNT(*) FROM users WHERE active=1;')
tr -d '\n' < "$TMP/body" | sed -E 's/\s+/ /g' | grep -qF "Lista de Usuarios ($TOTAL_ACTIVE)"; ok "el título cuenta $TOTAL_ACTIVE (solo los visibles)" $?
chk "editar al eliminado" 404 "$BASE/edit.php?id=$M4"
chk "guardar cambios del eliminado" 404 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&id=$M4&name=Hack&last_name=Hack&email=smoke-m4-a@example.com&role=admin" "$BASE/edit.php"
[ "$(sql "SELECT name FROM users WHERE id=$M4;")" = Papelera ]; ok "...y no se modificó" $?
chk "eliminar otra vez al eliminado" 302 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&id=$M4" "$BASE/delete.php"
header_of location | grep -q 'msg=missing'; ok "...avisa que no había nada que eliminar (missing)" $?

section "Vista de eliminados (M4): ?view=inactive"
chk "vista de eliminados" 200 --get --data-urlencode "view=inactive" --data-urlencode "q=smoke-m4" "$BASE/"
[ "$(grep -c 'action="restore.php"' "$TMP/body")" = 1 ] && grep -q "name=\"id\" value=\"$M4\"" "$TMP/body"; ok "lista solo al eliminado, con su botón Restaurar" $?
! grep -q 'edit.php?id=' "$TMP/body" && ! grep -q 'action="delete.php"' "$TMP/body"; ok "...sin Editar ni Eliminar (un eliminado se restaura, no se edita)" $?
tr -d '\n' < "$TMP/body" | sed -E 's/\s+/ /g' | grep -qF "Usuarios eliminados"; ok "...con su título" $?
tr -d '\n' < "$TMP/body" | grep -qE 'type="hidden" name="view" value="inactive"'; ok "...y el buscador se queda en esta vista (campo oculto view)" $?
grep -qF 'Ver usuarios' "$TMP/body"; ok "...con enlace de vuelta a los usuarios" $?
chk "view=INACTIVE (mayúsculas) es la vista normal" 200 --get --data-urlencode "view=INACTIVE" --data-urlencode "q=smoke-m4" "$BASE/"
grep -q "id=$M4B" "$TMP/body" && ! grep -q 'action="restore.php"' "$TMP/body"; ok "...(muestra al visible, no al eliminado)" $?
chk "view como arreglo es la vista normal" 200 "$BASE/?view%5B%5D=inactive&q=smoke-m4"
! grep -q 'action="restore.php"' "$TMP/body"; ok "...igual" $?
chk "el enlace «Eliminados» desde la vista normal" 200 --get --data-urlencode "q=smoke-m4" "$BASE/"
grep -qF 'href="index.php?view=inactive&amp;q=smoke-m4"' "$TMP/body"; ok "conserva la búsqueda al cambiar de vista" $?

section "Restaurar (restore.php)"
chk "GET restore.php" 405 "$BASE/restore.php?id=$M4"
[ "$(header_of allow)" = "Allow: POST" ]; ok "405 con Allow: POST" $?
chk "restaurar sin origen" 403 -d "csrf_token=$CSRF&id=$M4" "$BASE/restore.php"
chk "restaurar con Origin ajeno" 403 -H "Origin: http://evil.example" -d "csrf_token=$CSRF&id=$M4" "$BASE/restore.php"
chk "restaurar sin origen y con id=abc (403 antes que 400)" 403 -d "csrf_token=$CSRF&id=abc" "$BASE/restore.php"
soft_deleted "$M4"; ok "tras los 405 y 403 sigue eliminado" $?
for bad in "" "id=abc" "id=0" "id%5B%5D=1"; do chk "restaurar con origen correcto y '$bad'" 400 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&$bad" "$BASE/restore.php"; done
chk "restaurar a un id que no existe" 302 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&id=4294967295" "$BASE/restore.php"
header_of location | grep -q 'msg=not_restored'; ok "...avisa que no había nada que restaurar" $?
chk "restaurar a un usuario que está ACTIVO" 302 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&id=$M4B" "$BASE/restore.php"
header_of location | grep -q 'msg=not_restored'; ok "...tampoco restaura nada" $?
chk "restaurar al eliminado" 302 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&id=$M4&view=inactive&q=smoke-m4" "$BASE/restore.php"
[ "$(header_of location)" = "Location: index.php?msg=restored&view=inactive&q=smoke-m4" ]; ok "vuelve a la lista de eliminados, con su búsqueda y el aviso" $?
is_active "$M4"; ok "active vuelve a 1" $?
[ "$(sql "SELECT CONCAT(name,'|',document,'|',role) FROM users WHERE id=$M4;")" = "Papelera|PA-123456|editor" ]; ok "...con todos sus datos intactos" $?
chk "aviso de restaurado" 200 "$BASE/index.php?msg=restored&view=inactive"
grep -qF 'El usuario ha sido restaurado exitosamente.' "$TMP/body"; ok "se muestra el aviso" $?
chk "el restaurado vuelve al listado" 200 --get --data-urlencode "q=PA-123456" "$BASE/"
grep -q "id=$M4" "$TMP/body"; ok "y se encuentra por su documento" $?
chk "restaurar otra vez" 302 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&id=$M4" "$BASE/restore.php"
header_of location | grep -q 'msg=not_restored'; ok "...no restaura nada (not_restored)" $?

section "El correo y el documento de un eliminado siguen ocupados (decisión de M4)"
chk "eliminar de nuevo" 302 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&id=$M4" "$BASE/delete.php"
chk "crear con el correo de un eliminado" 409 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&name=Otro&last_name=Nuevo&email=smoke-m4-a@example.com&role=user" "$BASE/create.php"
grep -qF 'Ese correo pertenece a un usuario eliminado. Restáuralo desde «Eliminados»' "$TMP/body"; ok "el aviso explica que está eliminado y cómo recuperarlo" $?
chk "crear con el documento de un eliminado (otra capitalización)" 409 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&name=Otro&last_name=Nuevo&email=smoke-m4-n@example.com&document=pa-123456&role=user" "$BASE/create.php"
grep -qF 'Ese documento pertenece a un usuario eliminado' "$TMP/body"; ok "...también por documento" $?
chk "crear con el correo de un usuario ACTIVO: aviso normal" 409 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&name=Otro&last_name=Nuevo&email=smoke-m4-b@example.com&role=user" "$BASE/create.php"
grep -qF 'Ya existe un usuario registrado con ese correo.' "$TMP/body" && ! grep -qF 'eliminado' "$TMP/body"; ok "...sin hablar de eliminados" $?
chk "editar a uno para ponerle el correo de un eliminado" 409 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&id=$M4B&name=Papelera&last_name=Quedo&email=smoke-m4-a@example.com&role=user" "$BASE/edit.php"
grep -qF 'Ese correo pertenece a un usuario eliminado' "$TMP/body"; ok "...el aviso de edición también lo dice" $?
[ "$(sql "SELECT COUNT(*) FROM users WHERE email='smoke-m4-n@example.com';")" = 0 ]; ok "no se creó ningún usuario con esos datos" $?

section "El estado se conserva en la vista de eliminados"
sql "SET SESSION cte_max_recursion_depth = 100; INSERT INTO users (name, last_name, email, active) WITH RECURSIVE n(i) AS (SELECT 1 UNION ALL SELECT i+1 FROM n WHERE i < 12) SELECT 'Elim', 'Pagina', CONCAT('smoke-m4-e', LPAD(i, 2, '0'), '@example.com'), 0 FROM n;" >/dev/null
chk "eliminados, página 2" 200 --get --data-urlencode "view=inactive" --data-urlencode "q=smoke-m4-e" --data-urlencode "page=2" "$BASE/"
[ "$(grep -c 'action="restore.php"' "$TMP/body")" = 2 ]; ok "12 eliminados: la página 2 tiene 2 filas" $?
LINKS=$(tr -d '\n' < "$TMP/body" | grep -oE '<nav class="pager" aria-label="Paginación.*</nav>' | grep -oE 'href="index.php[^"]*"')
[ -n "$LINKS" ] && ! printf '%s\n' "$LINKS" | grep -qv 'view=inactive'; ok "los enlaces de página se quedan en la vista de eliminados" $?
tr -d '\n' < "$TMP/body" | grep -qE 'name="view" value="inactive">\s*<input type="hidden" name="q" value="smoke-m4-e">\s*<input type="hidden" name="page" value="2">'; ok "el botón Restaurar lleva view, q y page" $?
LAST2=$(grep -oE 'name="id" value="[0-9]+"' "$TMP/body" | grep -oE '[0-9]+')
for id in $LAST2; do chk "restaurar al $id desde la página 2" 302 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&id=$id&view=inactive&q=smoke-m4-e&page=2" "$BASE/restore.php"; done
chk "al restaurar a los de la última página, la página 2 ya no existe: se muestra la nueva última" 200 "$BASE/index.php?msg=restored&view=inactive&q=smoke-m4-e&page=2"
tr -d '\n' < "$TMP/body" | sed -E 's/\s+/ /g' | grep -qF "10 resultados para «smoke-m4-e»."; ok "(quedan 10: una sola página, sin «Mostrando»)" $?
[ "$(grep -c 'action="restore.php"' "$TMP/body")" = 10 ]; ok "...con 10 filas" $?

if [ "$WITH_DB_STOP" = 1 ]; then
    section "Base de datos detenida (503)"
    chk "se crea un usuario para la prueba" 302 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&role=user&name=Smoke&last_name=Dos&email=smoke-two@example.com" "$BASE/create.php"
    U2=$(id_of smoke-two@example.com)
    docker compose stop db >/dev/null 2>&1
    chk "GET /" 503 "$BASE/"
    chk "GET /?q=zoe (una búsqueda)" 503 --get --data-urlencode "q=zoe" "$BASE/"
    chk "GET /?page=2 (una página)" 503 "$BASE/?page=2"
    chk "GET /?q=zoe&page=3 (búsqueda y página)" 503 --get --data-urlencode "q=zoe" --data-urlencode "page=3" "$BASE/"
    achk "POST login.php con la BD parada" 503 -b "$AJ" -c "$AJ" -H "Origin: $ORIGIN" --data-urlencode "csrf_token=$AJ_TOKEN" --data-urlencode "email=smoke-admin@example.com" --data-urlencode "password=$ADMIN_PW" "$BASE/login.php"
    ! grep -qiE 'SQLSTATE|Access denied|getaddrinfo|/var/www' "$TMP/body"; ok "...sin detalles técnicos" $?
    chk "GET create.php" 200 "$BASE/create.php"
    chk "POST create.php" 503 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&role=user&name=Ana&last_name=Ruiz&email=smoke-y@example.com" "$BASE/create.php"
    chk "GET edit.php" 503 "$BASE/edit.php?id=$U2"
    chk "POST edit.php" 503 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&role=user&id=$U2&name=Ana&last_name=Ruiz&email=smoke-y@example.com" "$BASE/edit.php"
    grep -q 'value="Ana"' "$TMP/body"; ok "edit.php conserva lo que escribió el usuario" $?
    chk "POST delete.php con origen correcto" 503 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&id=$U2" "$BASE/delete.php"
    chk "POST restore.php con origen correcto" 503 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&id=$U2" "$BASE/restore.php"
    chk "GET /?view=inactive" 503 "$BASE/?view=inactive"
    chk "POST delete.php con origen ajeno (se comprueba antes que la BD)" 403 -H "Origin: http://evil.example" -d "csrf_token=$CSRF&id=$U2" "$BASE/delete.php"
    chk "POST delete.php con id inválido (se comprueba antes que la BD)" 400 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&id=abc" "$BASE/delete.php"
    chk "GET status.php" 503 "$BASE/status.php"
    ! grep -qiE 'SQLSTATE|Access denied|getaddrinfo|/var/www' "$TMP/body"; ok "ninguna página filtra detalles técnicos" $?
    docker compose start db >/dev/null 2>&1
    wait_db
    chk "tras volver a levantar la BD, GET /" 200 "$BASE/"

    section "Una consulta que falla (500)"
    sql "RENAME TABLE users TO users_tmp;" >/dev/null
    chk "GET /" 500 "$BASE/"
    grep -q 'No se pudieron obtener los usuarios' "$TMP/body"; ok "el listado dice qué falló, sin detalles" $?
    chk "POST create.php" 500 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&role=user&name=Ana&last_name=Ruiz&email=smoke-q@example.com" "$BASE/create.php"
    grep -q 'No se pudo guardar el usuario' "$TMP/body"; ok "crear: aviso propio de la página" $?
    chk "GET edit.php?id=1" 500 "$BASE/edit.php?id=1"
    grep -q 'No se pudo completar la operación' "$TMP/body"; ok "editar: aviso propio de la página" $?
    chk "POST delete.php" 500 -H "Origin: $ORIGIN" -d "csrf_token=$CSRF&id=1" "$BASE/delete.php"
    grep -q 'No se pudo eliminar el usuario' "$TMP/body"; ok "eliminar: aviso propio de la página" $?
    ! grep -qiE 'SQLSTATE|users_tmp|doesn.t exist|/var/www' "$TMP/body"; ok "ninguna página filtra detalles técnicos" $?
    sql "RENAME TABLE users_tmp TO users;" >/dev/null
    chk "tras restaurar la tabla, GET /" 200 "$BASE/"
fi

section "Limpieza"
sql "DELETE FROM users WHERE email LIKE 'smoke-%@example.com';" >/dev/null
[ "$(sql "SELECT COUNT(*) FROM users WHERE email LIKE 'smoke-%@example.com';")" = 0 ]; ok "no quedan usuarios de prueba" $?
[ "$(sql 'SELECT COUNT(*) FROM users;')" = "$USERS_BEFORE" ]; ok "hay los mismos usuarios que al empezar ($USERS_BEFORE)" $?

printf '\nResultado: %d correctas, %d fallidas\n' "$PASS" "$FAIL"
[ "$FAIL" = 0 ]
