#!/usr/bin/env bash
#
# Pruebas de humo de punta a punta: el CRUD completo contra la aplicación en marcha.
#
#   tests/smoke.sh                  # no toca los contenedores
#   tests/smoke.sh --with-db-stop   # además PARA y vuelve a levantar la BD para comprobar los 503
#
# Requisitos: la aplicación levantada (docker compose up -d), curl y Docker Compose.
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
sql() { printf '%s\n' "$1" | docker compose exec -T db sh -c 'mysql -N --default-character-set=utf8mb4 -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' 2>/dev/null; }
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
id_of() { sql "SELECT id FROM users WHERE email='$1';"; }
exists() { [ "$(sql "SELECT COUNT(*) FROM users WHERE id=$1;")" = 1 ]; }
create() { curl -s -o /dev/null -H "Origin: $ORIGIN" --data-urlencode "name=$1" --data-urlencode "last_name=$2" -d "email=$3" "$BASE/create.php"; }
wait_db() {
    local i
    for i in $(seq 1 40); do [ "$(docker compose ps db --format '{{.Health}}')" = healthy ] && break; sleep 2; done
    for i in $(seq 1 20); do [ "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/")" = 200 ] && break; sleep 1; done
}

# ---------------------------------------------------------------------------------------------
if [ "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/")" != 200 ]; then
    echo "La aplicación no responde en $BASE. ¿Está levantada (docker compose up -d)?" >&2
    exit 2
fi
sql "SELECT 1;" >/dev/null || { echo "No se puede consultar la base de datos con docker compose exec." >&2; exit 2; }
sql "DELETE FROM users WHERE email LIKE 'smoke-%@example.com';" >/dev/null
USERS_BEFORE=$(sql 'SELECT COUNT(*) FROM users;')
echo "Pruebas contra $BASE (origen $ORIGIN); usuarios al empezar: $USERS_BEFORE"

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
[ "$(grep -c 'integrity=' "$TMP/index.html")" -ge 3 ]; ok "las 3 CDN llevan integrity" $?
chk "GET /js/confirm-submit.js" 200 "$BASE/js/confirm-submit.js"
header_of content-type | grep -qiE 'javascript'; ok "el JavaScript se sirve como JavaScript" $?
chk "GET /status.php (solo en APP_ENV=local)" 200 "$BASE/status.php"
chk "GET /.env (nginx lo bloquea)" 403 "$BASE/.env"
chk "GET /?msg=created" 200 "$BASE/?msg=created"; grep -q 'alert theme-success' "$TMP/body"; ok "created: aviso verde" $?
chk "GET /?msg=missing" 200 "$BASE/?msg=missing"; grep -q 'alert theme-info' "$TMP/body"; ok "missing: aviso azul" $?
chk "GET /?msg[]=x (un arreglo)" 200 "$BASE/?msg%5B%5D=x"
chk "GET /?msg=<b>x</b> (valor raro)" 200 "$BASE/?msg=%3Cb%3Ex%3C/b%3E"; ! grep -qE 'alert theme-(success|info)' "$TMP/body"; ok "un valor raro no muestra aviso" $?

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
[ "$(grep -c 'autocomplete="off"' "$TMP/form.html")" = 4 ]; ok "los 4 campos llevan autocomplete=\"off\" (son datos de otra persona)" $?
chk "POST válido SIN Origin ni Referer" 403 -d "name=Ana&last_name=Ruiz&email=smoke-sinorigen@example.com" "$BASE/create.php"
[ "$(sql "SELECT COUNT(*) FROM users WHERE email='smoke-sinorigen@example.com';")" = 0 ]; ok "el 403 no creó ningún usuario" $?
chk "POST válido con Origin ajeno" 403 -H "Origin: http://evil.example" -d "name=Ana&last_name=Ruiz&email=smoke-sinorigen@example.com" "$BASE/create.php"
[ "$(sql "SELECT COUNT(*) FROM users WHERE email='smoke-sinorigen@example.com';")" = 0 ]; ok "con Origin ajeno tampoco se creó" $?
chk "POST inválido SIN Origin (403 antes que 422)" 403 -d "name=&last_name=&email=" "$BASE/create.php"
chk "POST con Origin: null" 403 -H "Origin: null" -d "name=Ana&last_name=Ruiz&email=smoke-sinorigen@example.com" "$BASE/create.php"
chk "POST con solo Referer correcto" 302 -H "Referer: $ORIGIN/create.php" -d "name=Ref&last_name=Erer&email=smoke-ref@example.com" "$BASE/create.php"
chk "POST vacío" 422 -H "Origin: $ORIGIN" -d "name=&last_name=&email=" "$BASE/create.php"
chk "POST con teléfono sin dígitos" 422 -H "Origin: $ORIGIN" -d "name=Ana&last_name=Ruiz&email=smoke-x@example.com&phone=-------" "$BASE/create.php"
chk "POST con name[] (un arreglo)" 422 -H "Origin: $ORIGIN" -d "name[]=x&last_name=Ruiz&email=smoke-x@example.com" "$BASE/create.php"
chk "POST con el correo de un usuario existente" 409 -H "Origin: $ORIGIN" -d "name=Ana&last_name=Ruiz&email=$(sql 'SELECT email FROM users ORDER BY id LIMIT 1;')" "$BASE/create.php"
chk "POST válido (correo en mayúsculas)" 302 -H "Origin: $ORIGIN" -d "name=Smoke&last_name=Uno&email=SMOKE-One@Example.com&phone=%2B57%20300%20123%204567" "$BASE/create.php"
header_of location | grep -q 'msg=created'; ok "redirige a msg=created" $?
U1=$(id_of smoke-one@example.com); [ -n "$U1" ]; ok "el correo se guardó en minúsculas" $?
chk "POST con un nombre con punto medio (Cal·lís)" 302 -H "Origin: $ORIGIN" --data-urlencode "name=Pere" --data-urlencode "last_name=Cal·lís" -d "email=smoke-cat@example.com" "$BASE/create.php"
[ "$(sql "SELECT last_name FROM users WHERE email='smoke-cat@example.com';")" = "Cal·lís" ]; ok "el apellido con punto medio se guardó bien (utf8mb4)" $?

section "Listado: fecha local, escape y accesibilidad"
create "O'Brien" "Prueba" "smoke-obrien@example.com"
UO=$(id_of smoke-obrien@example.com)
curl -s "$BASE/" > "$TMP/index.html"
grep -q 'data-confirm="¿Eliminar a O&#039;Brien Prueba?"' "$TMP/index.html"; ok "data-confirm con apóstrofe, escapado para HTML" $?
grep -q 'aria-label="Eliminar a O&#039;Brien Prueba"' "$TMP/index.html"; ok "el botón de eliminar dice a quién (aria-label)" $?
grep -q 'aria-label="Editar a O&#039;Brien Prueba"' "$TMP/index.html"; ok "el botón de editar dice a quién (aria-label)" $?
SHOWN=$(tr -d '\n' < "$TMP/index.html" | grep -oE '<td>O&#039;Brien</td>.{0,300}' | grep -oE '[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9:]{8}' | head -1)
[ "$(docker compose exec -T php php -r 'echo abs(time() - strtotime($argv[1])) < 180 ? "ok" : "mal";' -- "$SHOWN")" = ok ]; ok "la fecha mostrada ($SHOWN) es la hora local actual, no UTC" $?
STORED=$(sql "SELECT created_at FROM users WHERE id=$UO;")
[ "$(docker compose exec -T php php -r 'echo abs(time() - strtotime($argv[1] . " UTC")) < 180 ? "ok" : "mal";' -- "$STORED")" = ok ]; ok "la BD guarda la fecha en UTC ($STORED)" $?
[ "$(sql 'SELECT @@session.time_zone, @@global.time_zone;' | tr '\t' ' ')" = "+00:00 +00:00" ]; ok "el servidor de BD trabaja en UTC" $?

section "Editar (edit.php)"
for q in "" "?id=abc" "?id%5B%5D=1" "?id=0" "?id=-3" "?id=9999" "?id=4294967296" "?id=%2B$U1" "?id=%20$U1" "?id=0$U1"; do
    chk "GET edit.php$q" 404 "$BASE/edit.php$q"
done
chk "POST sin id" 404 -H "Origin: $ORIGIN" -d "name=A&last_name=B&email=a@b.co" "$BASE/edit.php"
chk "POST con un id inexistente" 404 -H "Origin: $ORIGIN" -d "id=9999&name=Ana&last_name=B&email=a@b.co" "$BASE/edit.php"
chk "GET con un id válido" 200 "$BASE/edit.php?id=$U1"
tr -d '\n' < "$TMP/body" | grep -qE 'id="last_name"[^>]*value="Uno"'; ok "carga los datos actuales" $?
chk "POST SIN Origin (403 antes que mirar los datos)" 403 -d "id=$U1&name=Hack&last_name=Hack&email=smoke-one@example.com" "$BASE/edit.php"
[ "$(sql "SELECT last_name FROM users WHERE id=$U1;")" = "Uno" ]; ok "el 403 no modificó al usuario" $?
chk "POST con Origin ajeno" 403 -H "Origin: http://evil.example" -d "id=$U1&name=Hack&last_name=Hack&email=smoke-one@example.com" "$BASE/edit.php"
chk "POST SIN Origin con un id inexistente (403 antes que 404)" 403 -d "id=9999&name=A&last_name=B&email=a@b.co" "$BASE/edit.php"
chk "POST SIN Origin con datos inválidos (403 antes que 422)" 403 -d "id=$U1&name=&last_name=&email=" "$BASE/edit.php"
chk "POST con solo Referer correcto" 302 -H "Referer: $ORIGIN/edit.php?id=$U1" -d "id=$U1&name=Smoke&last_name=Uno&email=smoke-one@example.com&phone=%2B57%20300%20123%204567" "$BASE/edit.php"
chk "POST con el nombre vacío" 422 -H "Origin: $ORIGIN" -d "id=$U1&name=&last_name=Uno&email=smoke-one@example.com" "$BASE/edit.php"
grep -q "name=\"id\" value=\"$U1\"" "$TMP/body"; ok "conserva el id oculto" $?
chk "POST con el correo de otro usuario" 409 -H "Origin: $ORIGIN" -d "id=$U1&name=Smoke&last_name=Uno&email=smoke-cat@example.com" "$BASE/edit.php"
chk "POST sin cambiar ningún valor" 302 -H "Origin: $ORIGIN" -d "id=$U1&name=Smoke&last_name=Uno&email=smoke-one@example.com" --data-urlencode "phone=+57 300 123 4567" "$BASE/edit.php"
chk "POST cambiando solo el apellido y vaciando el teléfono" 302 -H "Origin: $ORIGIN" -d "id=$U1&name=Smoke&last_name=Editado&email=smoke-one@example.com&phone=" "$BASE/edit.php"
[ "$(sql "SELECT IFNULL(phone,'NULL') FROM users WHERE id=$U1;")" = NULL ]; ok "el teléfono vacío queda como NULL" $?

section "Ediciones simultáneas (la persona A abre el formulario; la B guarda antes que ella)"
token_of() { tr -d '\n' < "$TMP/body" | grep -oE 'name="row_token" value="[0-9a-f]{64}"' | grep -oE '[0-9a-f]{64}'; }
chk "A abre el formulario de edición" 200 "$BASE/edit.php?id=$U1"
TOKEN_A=$(token_of); [ -n "$TOKEN_A" ]; ok "el formulario lleva un row_token de 64 caracteres" $?
chk "B guarda un cambio (sin token: se acepta como siempre)" 302 -H "Origin: $ORIGIN" -d "id=$U1&name=Smoke&last_name=DeB&email=smoke-one@example.com&phone=" "$BASE/edit.php"
chk "A guarda con la huella VIEJA: conflicto" 409 -H "Origin: $ORIGIN" -d "id=$U1&row_token=$TOKEN_A&name=Smoke&last_name=DeA&email=smoke-one@example.com&phone=" "$BASE/edit.php"
[ "$(sql "SELECT last_name FROM users WHERE id=$U1;")" = "DeB" ]; ok "no se pisó lo que guardó B" $?
grep -q 'Otra persona modificó a este usuario' "$TMP/body"; ok "el aviso explica qué pasó" $?
grep -q 'value="DeA"' "$TMP/body"; ok "se conserva lo que había escrito A" $?
grep -q "value=\"$TOKEN_A\"" "$TMP/body"; ok "se conserva la huella vieja: hay que recargar para poder guardar" $?
chk "A insiste con la huella vieja (datos inválidos): el conflicto va antes que el 422" 409 -H "Origin: $ORIGIN" -d "id=$U1&row_token=$TOKEN_A&name=&last_name=&email=" "$BASE/edit.php"
chk "A recarga el formulario" 200 "$BASE/edit.php?id=$U1"
TOKEN_A2=$(token_of); [ "$TOKEN_A2" != "$TOKEN_A" ]; ok "la huella nueva es distinta de la vieja" $?
chk "A guarda con la huella NUEVA" 302 -H "Origin: $ORIGIN" -d "id=$U1&row_token=$TOKEN_A2&name=Smoke&last_name=DeA&email=smoke-one@example.com&phone=" "$BASE/edit.php"
[ "$(sql "SELECT last_name FROM users WHERE id=$U1;")" = "DeA" ]; ok "ahora sí se guardó lo de A" $?
chk "A abre otra vez" 200 "$BASE/edit.php?id=$U1"
TOKEN_A3=$(token_of)
chk "B guarda EXACTAMENTE los mismos valores" 302 -H "Origin: $ORIGIN" -d "id=$U1&name=Smoke&last_name=DeA&email=smoke-one@example.com&phone=" "$BASE/edit.php"
chk "A guarda: no hay conflicto porque los datos no cambiaron" 302 -H "Origin: $ORIGIN" -d "id=$U1&row_token=$TOKEN_A3&name=Smoke&last_name=DeA2&email=smoke-one@example.com&phone=" "$BASE/edit.php"
chk "un token mal formado se trata como un conflicto, no como un error" 409 -H "Origin: $ORIGIN" -d "id=$U1&row_token=basura&name=Smoke&last_name=X&email=smoke-one@example.com&phone=" "$BASE/edit.php"
chk "con row_token como arreglo se ignora (como si no llegara)" 302 -H "Origin: $ORIGIN" --data-urlencode "row_token[]=x" -d "id=$U1&name=Smoke&last_name=DeA&email=smoke-one@example.com&phone=" "$BASE/edit.php"

section "Eliminar (delete.php)"
chk "GET (no borra)" 405 "$BASE/delete.php?id=$U1"
[ "$(header_of allow)" = "Allow: POST" ]; ok "405 con Allow: POST" $?
chk "PUT" 405 -X PUT "$BASE/delete.php"
exists "$U1"; ok "tras los 405, el usuario sigue existiendo" $?
chk "POST sin ninguna cabecera de origen" 403 -d "id=$U1" "$BASE/delete.php"
chk "POST con Origin ajeno" 403 -H "Origin: http://evil.example" -d "id=$U1" "$BASE/delete.php"
chk "POST con Origin: null" 403 -H "Origin: null" -d "id=$U1" "$BASE/delete.php"
chk "POST con Origin vacío y Referer correcto" 403 -H "Origin;" -H "Referer: $ORIGIN/" -d "id=$U1" "$BASE/delete.php"
chk "POST con Origin ajeno y Referer correcto" 403 -H "Origin: http://evil.example" -H "Referer: $ORIGIN/" -d "id=$U1" "$BASE/delete.php"
chk "POST con un Origin que empieza igual que el nuestro" 403 -H "Origin: $ORIGIN.evil.com" -d "id=$U1" "$BASE/delete.php"
chk "POST sin Origin y con id=abc (403 antes que 400)" 403 -d "id=abc" "$BASE/delete.php"
exists "$U1"; ok "tras los 403, el usuario sigue existiendo" $?
for bad in "" "id=abc" "id=0" "id=%2B$U1" "id%5B%5D=1"; do chk "POST con origen correcto y '$bad'" 400 -H "Origin: $ORIGIN" -d "$bad" "$BASE/delete.php"; done
chk "POST con solo Referer correcto (con ruta y query)" 302 -H "Referer: $ORIGIN/index.php?msg=created" -d "id=$U1" "$BASE/delete.php"
header_of location | grep -q 'msg=deleted'; ok "redirige a msg=deleted" $?
! exists "$U1"; ok "el usuario desapareció" $?
chk "repetir el borrado (ya no existe)" 302 -H "Origin: $ORIGIN" -d "id=$U1" "$BASE/delete.php"
header_of location | grep -q 'msg=missing'; ok "redirige a msg=missing (no es un error)" $?
chk "POST con un id válido que nunca existió" 302 -H "Origin: $ORIGIN" -d "id=4294967295" "$BASE/delete.php"
chk "borrar al usuario con apóstrofe, con Origin en mayúsculas" 302 -H "Origin: $(printf '%s' "$ORIGIN" | tr '[:lower:]' '[:upper:]')" -d "id=$UO" "$BASE/delete.php"
! exists "$UO"; ok "el usuario O'Brien desapareció" $?

if [ "$WITH_DB_STOP" = 1 ]; then
    section "Base de datos detenida (503)"
    chk "se crea un usuario para la prueba" 302 -H "Origin: $ORIGIN" -d "name=Smoke&last_name=Dos&email=smoke-two@example.com" "$BASE/create.php"
    U2=$(id_of smoke-two@example.com)
    docker compose stop db >/dev/null 2>&1
    chk "GET /" 503 "$BASE/"
    chk "GET create.php" 200 "$BASE/create.php"
    chk "POST create.php" 503 -H "Origin: $ORIGIN" -d "name=Ana&last_name=Ruiz&email=smoke-y@example.com" "$BASE/create.php"
    chk "GET edit.php" 503 "$BASE/edit.php?id=$U2"
    chk "POST edit.php" 503 -H "Origin: $ORIGIN" -d "id=$U2&name=Ana&last_name=Ruiz&email=smoke-y@example.com" "$BASE/edit.php"
    grep -q 'value="Ana"' "$TMP/body"; ok "edit.php conserva lo que escribió el usuario" $?
    chk "POST delete.php con origen correcto" 503 -H "Origin: $ORIGIN" -d "id=$U2" "$BASE/delete.php"
    chk "POST delete.php con origen ajeno (se comprueba antes que la BD)" 403 -H "Origin: http://evil.example" -d "id=$U2" "$BASE/delete.php"
    chk "POST delete.php con id inválido (se comprueba antes que la BD)" 400 -H "Origin: $ORIGIN" -d "id=abc" "$BASE/delete.php"
    chk "GET status.php" 503 "$BASE/status.php"
    ! grep -qiE 'SQLSTATE|Access denied|getaddrinfo|/var/www' "$TMP/body"; ok "ninguna página filtra detalles técnicos" $?
    docker compose start db >/dev/null 2>&1
    wait_db
    chk "tras volver a levantar la BD, GET /" 200 "$BASE/"

    section "Una consulta que falla (500)"
    sql "RENAME TABLE users TO users_tmp;" >/dev/null
    chk "GET /" 500 "$BASE/"
    grep -q 'No se pudieron obtener los usuarios' "$TMP/body"; ok "el listado dice qué falló, sin detalles" $?
    chk "POST create.php" 500 -H "Origin: $ORIGIN" -d "name=Ana&last_name=Ruiz&email=smoke-q@example.com" "$BASE/create.php"
    grep -q 'No se pudo guardar el usuario' "$TMP/body"; ok "crear: aviso propio de la página" $?
    chk "GET edit.php?id=1" 500 "$BASE/edit.php?id=1"
    grep -q 'No se pudo completar la operación' "$TMP/body"; ok "editar: aviso propio de la página" $?
    chk "POST delete.php" 500 -H "Origin: $ORIGIN" -d "id=1" "$BASE/delete.php"
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
