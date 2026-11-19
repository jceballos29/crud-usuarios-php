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
# Con borrado lógico (M4) "eliminado" ya no es "la fila no existe": es la fila con active = 0.
soft_deleted() { [ "$(sql "SELECT COUNT(*) FROM users WHERE id=$1 AND active=0;")" = 1 ]; }
is_active() { [ "$(sql "SELECT COUNT(*) FROM users WHERE id=$1 AND active=1;")" = 1 ]; }
create() { curl -s -o /dev/null -H "Origin: $ORIGIN" --data-urlencode "name=$1" --data-urlencode "last_name=$2" -d "role=user&email=$3" "$BASE/create.php"; }
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
[ "$(grep -c 'autocomplete="off"' "$TMP/form.html")" = 7 ]; ok "los 7 campos llevan autocomplete=\"off\" (son datos de otra persona)" $?
chk "POST válido SIN Origin ni Referer" 403 -d "role=user&name=Ana&last_name=Ruiz&email=smoke-sinorigen@example.com" "$BASE/create.php"
[ "$(sql "SELECT COUNT(*) FROM users WHERE email='smoke-sinorigen@example.com';")" = 0 ]; ok "el 403 no creó ningún usuario" $?
chk "POST válido con Origin ajeno" 403 -H "Origin: http://evil.example" -d "role=user&name=Ana&last_name=Ruiz&email=smoke-sinorigen@example.com" "$BASE/create.php"
[ "$(sql "SELECT COUNT(*) FROM users WHERE email='smoke-sinorigen@example.com';")" = 0 ]; ok "con Origin ajeno tampoco se creó" $?
chk "POST inválido SIN Origin (403 antes que 422)" 403 -d "role=user&name=&last_name=&email=" "$BASE/create.php"
chk "POST con Origin: null" 403 -H "Origin: null" -d "role=user&name=Ana&last_name=Ruiz&email=smoke-sinorigen@example.com" "$BASE/create.php"
chk "POST con solo Referer correcto" 302 -H "Referer: $ORIGIN/create.php" -d "role=user&name=Ref&last_name=Erer&email=smoke-ref@example.com" "$BASE/create.php"
chk "POST vacío" 422 -H "Origin: $ORIGIN" -d "role=user&name=&last_name=&email=" "$BASE/create.php"
chk "POST con teléfono sin dígitos" 422 -H "Origin: $ORIGIN" -d "role=user&name=Ana&last_name=Ruiz&email=smoke-x@example.com&phone=-------" "$BASE/create.php"
chk "POST con name[] (un arreglo)" 422 -H "Origin: $ORIGIN" -d "role=user&name[]=x&last_name=Ruiz&email=smoke-x@example.com" "$BASE/create.php"
chk "POST con el correo de un usuario existente" 409 -H "Origin: $ORIGIN" -d "role=user&name=Ana&last_name=Ruiz&email=$(sql 'SELECT email FROM users ORDER BY id LIMIT 1;')" "$BASE/create.php"
chk "POST válido (correo en mayúsculas)" 302 -H "Origin: $ORIGIN" -d "role=user&name=Smoke&last_name=Uno&email=SMOKE-One@Example.com&phone=%2B57%20300%20123%204567" "$BASE/create.php"
header_of location | grep -q 'msg=created'; ok "redirige a msg=created" $?
U1=$(id_of smoke-one@example.com); [ -n "$U1" ]; ok "el correo se guardó en minúsculas" $?
chk "POST con un nombre con punto medio (Cal·lís)" 302 -H "Origin: $ORIGIN" --data-urlencode "name=Pere" --data-urlencode "last_name=Cal·lís" -d "role=user&email=smoke-cat@example.com" "$BASE/create.php"
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

section "Búsqueda (M1): GET ?q=texto"
# Usuarios de prueba: uno con tildes y teléfono, y dos parejas para probar los comodines de LIKE.
curl -s -o /dev/null -H "Origin: $ORIGIN" --data-urlencode "name=Zoé" --data-urlencode "last_name=Núñez-Smoke" -d "role=user&email=smoke-busq-a@example.com" --data-urlencode "phone=+57 300 777 8888" "$BASE/create.php"
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
tr -d '\n' < "$TMP/body" | grep -qE '<form method="get" action="index.php" role="search"'; ok "...que usa el método GET (la URL se puede compartir)" $?
chk "q[]=x (un arreglo)" 200 "$BASE/?q%5B%5D=x"; shows "Núñez-Smoke"; ok "un arreglo cuenta como \"sin búsqueda\" y lista todo" $?
chk "q junto con msg" 200 "$BASE/?q=zoe&msg=created"; shows "Núñez-Smoke" && grep -q 'alert theme-success' "$TMP/body"; ok "q y msg conviven: resultados y aviso" $?
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
grep -qF '<span class="page-link">Anterior</span>' "$TMP/body"; ok "...\"Anterior\" está deshabilitado (un <span>, no un enlace)" $?
grep -qF 'href="index.php?q=smoke-pag&amp;page=2" rel="next">Siguiente</a>' "$TMP/body"; ok "...\"Siguiente\" lleva a la 2 y conserva la búsqueda" $?
grep -qF 'aria-current="page">1</span>' "$TMP/body"; ok "...la página actual (1) está marcada con aria-current" $?
[ "$(grep -c 'aria-current="page"' "$TMP/body")" = 1 ]; ok "...y solo una página está marcada" $?
tr -d '\n' < "$TMP/body" | grep -qF '<nav aria-label="Paginación del listado de usuarios">'; ok "...la navegación es un <nav> con nombre accesible" $?
IDS1=$(ids)
page_of 2; [ "$(rows)" = 10 ]; ok "página 2: 10 filas" $?
flat | grep -qF "Mostrando 11 a 20."; ok "...resumen 11 a 20" $?
grep -qF 'href="index.php?q=smoke-pag" rel="prev">Anterior</a>' "$TMP/body"; ok "...\"Anterior\" lleva a la 1 SIN ?page=1 (cada estado tiene una sola URL)" $?
grep -qF 'href="index.php?q=smoke-pag&amp;page=3" rel="next">Siguiente</a>' "$TMP/body"; ok "...\"Siguiente\" lleva a la 3" $?
IDS2=$(ids)
page_of 3; [ "$(rows)" = 5 ]; ok "página 3 (la última): 5 filas" $?
flat | grep -qF "Mostrando 21 a 25."; ok "...resumen 21 a 25" $?
grep -qF '<span class="page-link">Siguiente</span>' "$TMP/body"; ok "...\"Siguiente\" está deshabilitado en la última" $?
IDS3=$(ids)
ALL=$(printf '%s\n%s\n%s\n' "$IDS1" "$IDS2" "$IDS3")
[ "$(printf '%s\n' "$ALL" | grep -c .)" = 25 ] && [ "$(printf '%s\n' "$ALL" | sort -u | grep -c .)" = 25 ]; ok "las 3 páginas suman 25 usuarios distintos: ni huecos ni repeticiones" $?
[ "$ALL" = "$(printf '%s\n' "$ALL" | sort -rn)" ]; ok "...y los ids van de mayor a menor a través de las páginas" $?
for bad in 4 999 99999999999999999999999; do page_of "$bad"; [ "$(rows)" = 5 ] && flat | grep -qF "Mostrando 21 a 25." && grep -qF 'aria-current="page">3</span>' "$TMP/body"; ok "?page=$bad se corrige a la última (la 3) con 200" $?; done
for bad in 0 abc -3 1.5 " 2" "+2" ""; do page_of "$bad"; [ "$(rows)" = 10 ] && flat | grep -qF "Mostrando 1 a 10."; ok "?page='$bad' cuenta como la página 1" $?; done
chk "?page[]=2 (un arreglo)" 200 "$BASE/?q=smoke-pag&page%5B%5D=2"; flat | grep -qF "Mostrando 1 a 10."; ok "...cuenta como la página 1" $?
page_of 2
LINKS=$(tr -d '\n' < "$TMP/body" | grep -oE '<nav aria-label="Paginación.*</nav>' | grep -oE 'href="[^"]*"')
[ "$(printf '%s\n' "$LINKS" | grep -c .)" -ge 4 ] && ! printf '%s\n' "$LINKS" | grep -qv 'q=smoke-pag'; ok "TODOS los enlaces de la paginación conservan la búsqueda" $?
chk "q con tildes y espacios, página 2" 200 --get --data-urlencode "q=smoke pag" --data-urlencode "page=2" "$BASE/"; ok "(el espacio de «smoke pag» no encuentra nada, pero no rompe)" 0
chk "una sola página no pinta la navegación" 200 --get --data-urlencode "q=smoke-busq" "$BASE/"; ! grep -qF 'aria-label="Paginación' "$TMP/body"; ok "...la búsqueda de 5 resultados no tiene <nav>" $?
chk "sin búsqueda: GET /" 200 "$BASE/"; TOTAL=$(sql 'SELECT COUNT(*) FROM users;'); PAGES=$(( (TOTAL + 9) / 10 ))
flat | grep -qF "Lista de Usuarios ($TOTAL)"; ok "el título cuenta TODOS los usuarios ($TOTAL), no los de la página" $?
[ "$(rows)" = "$(( TOTAL < 10 ? TOTAL : 10 ))" ]; ok "...y la página 1 tiene $(( TOTAL < 10 ? TOTAL : 10 )) filas" $?
chk "sin búsqueda: la última página ($PAGES)" 200 "$BASE/?page=$PAGES"; [ "$(rows)" = "$(( TOTAL - 10 * (PAGES - 1) ))" ]; ok "...tiene $(( TOTAL - 10 * (PAGES - 1) )) filas" $?
chk "sin búsqueda: ?page=$(( PAGES + 7 ))" 200 "$BASE/?page=$(( PAGES + 7 ))"; [ "$(rows)" = "$(( TOTAL - 10 * (PAGES - 1) ))" ]; ok "...se corrige a la última" $?

section "El listado conserva la búsqueda y la página al editar y eliminar"
page_of 2
grep -qE 'href="edit.php\?id=[0-9]+&amp;q=smoke-pag&amp;page=2"' "$TMP/body"; ok "el enlace de editar lleva q y page" $?
tr -d '\n' < "$TMP/body" | grep -qE '<form method="post" action="delete.php"[^>]*>\s*<input type="hidden" name="id" value="[0-9]+">\s*<input type="hidden" name="q" value="smoke-pag">\s*<input type="hidden" name="page" value="2">'; ok "el formulario de eliminar lleva q y page en campos ocultos" $?
page_of 1
! grep -qE 'edit.php\?id=[0-9]+&amp;page=' "$TMP/body" && grep -qE 'href="edit.php\?id=[0-9]+&amp;q=smoke-pag"' "$TMP/body"; ok "en la página 1 no se escribe page=1 en el enlace de editar" $?
EDIT_ID=$(printf '%s\n' "$IDS2" | head -1)
chk "abrir el formulario de edición desde la página 2" 200 "$BASE/edit.php?id=$EDIT_ID&q=smoke-pag&page=2"
grep -qF 'href="index.php?q=smoke-pag&amp;page=2">Cancelar</a>' "$TMP/body"; ok "\"Cancelar\" vuelve a la búsqueda y a la página 2" $?
tr -d '\n' < "$TMP/body" | grep -qE 'name="q" value="smoke-pag">\s*<input type="hidden" name="page" value="2"'; ok "el formulario lleva q y page en campos ocultos" $?
chk "guardar desde la página 2" 302 -H "Origin: $ORIGIN" -d "role=user&id=$EDIT_ID&q=smoke-pag&page=2&name=Paginado&last_name=Editado&email=smoke-pag-edit@example.com&phone=" "$BASE/edit.php"
[ "$(header_of location)" = "Location: index.php?msg=updated&q=smoke-pag&page=2" ]; ok "...y vuelve a la misma búsqueda y página (con el aviso)" $?
chk "guardar sin estado (como antes de M2)" 302 -H "Origin: $ORIGIN" -d "role=user&id=$EDIT_ID&name=Paginado&last_name=Editado&email=smoke-pag-edit@example.com&phone=" "$BASE/edit.php"
[ "$(header_of location)" = "Location: index.php?msg=updated" ]; ok "...sin estado sigue yendo a index.php?msg=updated" $?
chk "estado hostil al guardar (page=abc, q como arreglo)" 302 -H "Origin: $ORIGIN" --data-urlencode "q[]=x" -d "role=user&id=$EDIT_ID&page=abc&name=Paginado&last_name=Editado&email=smoke-pag-edit@example.com&phone=" "$BASE/edit.php"
[ "$(header_of location)" = "Location: index.php?msg=updated" ]; ok "...un estado inválido se ignora" $?
chk "q con un salto de línea para colar otra cabecera" 302 -H "Origin: $ORIGIN" --data-urlencode $'q=a\r\nSet-Cookie: hack=1' -d "role=user&id=$EDIT_ID&page=2&name=Paginado&last_name=Editado&email=smoke-pag-edit@example.com&phone=" "$BASE/edit.php"
! grep -qi '^Set-Cookie' "$TMP/head"; ok "...no se cuela ninguna cabecera Set-Cookie" $?
[ "$(header_of location)" = "Location: index.php?msg=updated&q=a%20Set-Cookie%3A%20hack%3D1&page=2" ]; ok "...el salto de línea queda como un espacio y todo el texto codificado en el Location" $?

section "Eliminar al último usuario de la última página (el caso límite de M2)"
page_of 3; LAST_IDS=$(ids); [ "$(printf '%s\n' "$LAST_IDS" | grep -c .)" = 5 ]; ok "la página 3 tiene 5 usuarios (de 25)" $?
for id in $LAST_IDS; do
    chk "eliminar el usuario $id desde la página 3" 302 -H "Origin: $ORIGIN" -d "id=$id&q=smoke-pag&page=3" "$BASE/delete.php"
done
[ "$(header_of location)" = "Location: index.php?msg=deleted&q=smoke-pag&page=3" ]; ok "al borrar al ÚLTIMO de la página 3, el Location sigue apuntando a la página 3 (que ya no existe)..." $?
chk "...y al seguir esa redirección" 200 "$BASE/index.php?msg=deleted&q=smoke-pag&page=3"
flat | grep -qF "Mostrando 11 a 20." && [ "$(rows)" = 10 ] && grep -qF 'aria-current="page">2</span>' "$TMP/body"; ok "...se muestra la nueva última página (la 2) con 10 usuarios, no una vacía" $?
grep -q 'alert theme-success' "$TMP/body"; ok "...y el aviso de eliminado se ve" $?
! grep -qF '<span class="page-link">Siguiente</span>' "$TMP/body" || true; grep -qF '<span class="page-link">Siguiente</span>' "$TMP/body"; ok "...con \"Siguiente\" deshabilitado, porque ya es la última" $?
page_of 3; [ "$(rows)" = 10 ]; ok "pedir la 3 directamente también muestra la 2 (ya solo hay 20)" $?
chk "eliminar sin estado (como antes de M2)" 302 -H "Origin: $ORIGIN" -d "id=$(printf '%s\n' "$IDS1" | head -1)" "$BASE/delete.php"
[ "$(header_of location)" = "Location: index.php?msg=deleted" ]; ok "...sin estado sigue yendo a index.php?msg=deleted" $?
chk "eliminar con un estado hostil" 302 -H "Origin: $ORIGIN" --data-urlencode "q[]=x" -d "id=$(printf '%s\n' "$IDS1" | sed -n 2p)&page=%00abc" "$BASE/delete.php"
[ "$(header_of location)" = "Location: index.php?msg=deleted" ]; ok "...y un estado inválido se ignora" $?

section "Editar (edit.php)"
for q in "" "?id=abc" "?id%5B%5D=1" "?id=0" "?id=-3" "?id=9999" "?id=4294967296" "?id=%2B$U1" "?id=%20$U1" "?id=0$U1"; do
    chk "GET edit.php$q" 404 "$BASE/edit.php$q"
done
chk "POST sin id" 404 -H "Origin: $ORIGIN" -d "name=A&last_name=B&email=a@b.co" "$BASE/edit.php"
chk "POST con un id inexistente" 404 -H "Origin: $ORIGIN" -d "role=user&id=9999&name=Ana&last_name=B&email=a@b.co" "$BASE/edit.php"
chk "GET con un id válido" 200 "$BASE/edit.php?id=$U1"
tr -d '\n' < "$TMP/body" | grep -qE 'id="last_name"[^>]*value="Uno"'; ok "carga los datos actuales" $?
chk "POST SIN Origin (403 antes que mirar los datos)" 403 -d "role=user&id=$U1&name=Hack&last_name=Hack&email=smoke-one@example.com" "$BASE/edit.php"
[ "$(sql "SELECT last_name FROM users WHERE id=$U1;")" = "Uno" ]; ok "el 403 no modificó al usuario" $?
chk "POST con Origin ajeno" 403 -H "Origin: http://evil.example" -d "role=user&id=$U1&name=Hack&last_name=Hack&email=smoke-one@example.com" "$BASE/edit.php"
chk "POST SIN Origin con un id inexistente (403 antes que 404)" 403 -d "role=user&id=9999&name=A&last_name=B&email=a@b.co" "$BASE/edit.php"
chk "POST SIN Origin con datos inválidos (403 antes que 422)" 403 -d "role=user&id=$U1&name=&last_name=&email=" "$BASE/edit.php"
chk "POST con solo Referer correcto" 302 -H "Referer: $ORIGIN/edit.php?id=$U1" -d "role=user&id=$U1&name=Smoke&last_name=Uno&email=smoke-one@example.com&phone=%2B57%20300%20123%204567" "$BASE/edit.php"
chk "POST con el nombre vacío" 422 -H "Origin: $ORIGIN" -d "role=user&id=$U1&name=&last_name=Uno&email=smoke-one@example.com" "$BASE/edit.php"
grep -q "name=\"id\" value=\"$U1\"" "$TMP/body"; ok "conserva el id oculto" $?
chk "POST con el correo de otro usuario" 409 -H "Origin: $ORIGIN" -d "role=user&id=$U1&name=Smoke&last_name=Uno&email=smoke-cat@example.com" "$BASE/edit.php"
chk "POST sin cambiar ningún valor" 302 -H "Origin: $ORIGIN" -d "role=user&id=$U1&name=Smoke&last_name=Uno&email=smoke-one@example.com" --data-urlencode "phone=+57 300 123 4567" "$BASE/edit.php"
chk "POST cambiando solo el apellido y vaciando el teléfono" 302 -H "Origin: $ORIGIN" -d "role=user&id=$U1&name=Smoke&last_name=Editado&email=smoke-one@example.com&phone=" "$BASE/edit.php"
[ "$(sql "SELECT IFNULL(phone,'NULL') FROM users WHERE id=$U1;")" = NULL ]; ok "el teléfono vacío queda como NULL" $?

section "Ediciones simultáneas (la persona A abre el formulario; la B guarda antes que ella)"
token_of() { tr -d '\n' < "$TMP/body" | grep -oE 'name="row_token" value="[0-9a-f]{64}"' | grep -oE '[0-9a-f]{64}'; }
chk "A abre el formulario de edición" 200 "$BASE/edit.php?id=$U1"
TOKEN_A=$(token_of); [ -n "$TOKEN_A" ]; ok "el formulario lleva un row_token de 64 caracteres" $?
chk "B guarda un cambio (sin token: se acepta como siempre)" 302 -H "Origin: $ORIGIN" -d "role=user&id=$U1&name=Smoke&last_name=DeB&email=smoke-one@example.com&phone=" "$BASE/edit.php"
chk "A guarda con la huella VIEJA: conflicto" 409 -H "Origin: $ORIGIN" -d "role=user&id=$U1&row_token=$TOKEN_A&name=Smoke&last_name=DeA&email=smoke-one@example.com&phone=" "$BASE/edit.php"
[ "$(sql "SELECT last_name FROM users WHERE id=$U1;")" = "DeB" ]; ok "no se pisó lo que guardó B" $?
grep -q 'Otra persona modificó a este usuario' "$TMP/body"; ok "el aviso explica qué pasó" $?
grep -q 'value="DeA"' "$TMP/body"; ok "se conserva lo que había escrito A" $?
grep -q "value=\"$TOKEN_A\"" "$TMP/body"; ok "se conserva la huella vieja: hay que recargar para poder guardar" $?
chk "A insiste con la huella vieja (datos inválidos): el conflicto va antes que el 422" 409 -H "Origin: $ORIGIN" -d "role=user&id=$U1&row_token=$TOKEN_A&name=&last_name=&email=" "$BASE/edit.php"
chk "A recarga el formulario" 200 "$BASE/edit.php?id=$U1"
TOKEN_A2=$(token_of); [ "$TOKEN_A2" != "$TOKEN_A" ]; ok "la huella nueva es distinta de la vieja" $?
chk "A guarda con la huella NUEVA" 302 -H "Origin: $ORIGIN" -d "role=user&id=$U1&row_token=$TOKEN_A2&name=Smoke&last_name=DeA&email=smoke-one@example.com&phone=" "$BASE/edit.php"
[ "$(sql "SELECT last_name FROM users WHERE id=$U1;")" = "DeA" ]; ok "ahora sí se guardó lo de A" $?
chk "A abre otra vez" 200 "$BASE/edit.php?id=$U1"
TOKEN_A3=$(token_of)
chk "B guarda EXACTAMENTE los mismos valores" 302 -H "Origin: $ORIGIN" -d "role=user&id=$U1&name=Smoke&last_name=DeA&email=smoke-one@example.com&phone=" "$BASE/edit.php"
chk "A guarda: no hay conflicto porque los datos no cambiaron" 302 -H "Origin: $ORIGIN" -d "role=user&id=$U1&row_token=$TOKEN_A3&name=Smoke&last_name=DeA2&email=smoke-one@example.com&phone=" "$BASE/edit.php"
chk "un token mal formado se trata como un conflicto, no como un error" 409 -H "Origin: $ORIGIN" -d "role=user&id=$U1&row_token=basura&name=Smoke&last_name=X&email=smoke-one@example.com&phone=" "$BASE/edit.php"
chk "con row_token como arreglo se ignora (como si no llegara)" 302 -H "Origin: $ORIGIN" --data-urlencode "row_token[]=x" -d "role=user&id=$U1&name=Smoke&last_name=DeA&email=smoke-one@example.com&phone=" "$BASE/edit.php"

section "Más campos (M3): documento, fecha de nacimiento y rol"
chk "formulario de alta" 200 "$BASE/create.php"
tr -d '\n' < "$TMP/body" | grep -qE 'type="date"[^>]*id="birth_date"|id="birth_date"[^>]*type="date"|type="date"\s+class="form-control"\s+id="birth_date"'; ok "la fecha de nacimiento es un <input type=\"date\">" $?
[ "$(grep -cE '<option value="(user|editor|admin)"' "$TMP/body")" = 3 ]; ok "el rol es un <select> con exactamente 3 opciones" $?
grep -qF '<option value="user" selected>Usuario</option>' "$TMP/body" && [ "$(grep -c ' selected' "$TMP/body")" = 1 ]; ok "...y solo «Usuario» viene seleccionado" $?
grep -qE 'name="document"[^>]*maxlength="20"|maxlength="20"[^>]*name="document"' <(tr -d '\n' < "$TMP/body"); ok "el documento tiene maxlength=20" $?

chk "alta con todos los campos (documento en minúsculas)" 302 -H "Origin: $ORIGIN" -d "name=Mas&last_name=Campos&email=smoke-m3-a@example.com&document=ab-12345&birth_date=1990-05-17&role=editor" "$BASE/create.php"
UA=$(id_of smoke-m3-a@example.com)
[ "$(sql "SELECT CONCAT(document,'|',birth_date,'|',role) FROM users WHERE id=$UA;")" = "AB-12345|1990-05-17|editor" ]; ok "se guardó: documento en MAYÚSCULAS, fecha y rol" $?
chk "alta sin documento ni fecha" 302 -H "Origin: $ORIGIN" -d "name=Sin&last_name=Extras&email=smoke-m3-b@example.com&document=&birth_date=&role=user" "$BASE/create.php"
chk "...y otra más (varios sin documento no chocan en el UNIQUE)" 302 -H "Origin: $ORIGIN" -d "name=Sin&last_name=Extras2&email=smoke-m3-c@example.com&document=&birth_date=&role=user" "$BASE/create.php"
[ "$(sql "SELECT COUNT(*) FROM users WHERE email IN ('smoke-m3-b@example.com','smoke-m3-c@example.com') AND document IS NULL AND birth_date IS NULL;")" = 2 ]; ok "lo vacío se guardó como NULL (no como cadena vacía)" $?
UB=$(id_of smoke-m3-b@example.com)

chk "documento repetido" 409 -H "Origin: $ORIGIN" -d "name=Dup&last_name=Doc&email=smoke-m3-d@example.com&document=AB-12345&role=user" "$BASE/create.php"
grep -qF 'Ya existe un usuario registrado con ese documento.' "$TMP/body"; ok "...con el aviso del documento (no el del correo)" $?
! grep -qF 'ese correo' "$TMP/body"; ok "...y sin el aviso del correo" $?
tr -d '\n' < "$TMP/body" | grep -qE 'id="document"[^>]*aria-invalid="true"'; ok "...el campo documento queda marcado" $?
chk "documento repetido en otra capitalización (ab-12345)" 409 -H "Origin: $ORIGIN" -d "name=Dup&last_name=Doc&email=smoke-m3-d@example.com&document=ab-12345&role=user" "$BASE/create.php"
chk "correo repetido: sigue avisando del correo" 409 -H "Origin: $ORIGIN" -d "name=Dup&last_name=Doc&email=smoke-m3-a@example.com&document=ZZ-99999&role=user" "$BASE/create.php"
grep -qF 'Ya existe un usuario registrado con ese correo.' "$TMP/body"; ok "...con el aviso del correo" $?
[ "$(sql "SELECT COUNT(*) FROM users WHERE email='smoke-m3-d@example.com';")" = 0 ]; ok "ninguno de los repetidos se guardó" $?

chk "rol inexistente (se manipuló el select)" 422 -H "Origin: $ORIGIN" -d "name=Rol&last_name=Falso&email=smoke-m3-e@example.com&role=superadmin" "$BASE/create.php"
grep -qF 'Selecciona un rol de la lista.' "$TMP/body"; ok "...con su aviso" $?
grep -qF '<option value="" selected disabled>Selecciona…</option>' "$TMP/body"; ok "...y el select vuelve sin ninguna opción elegida (no se pinta el valor falso)" $?
! grep -q 'superadmin' "$TMP/body"; ok "...ni se refleja el valor manipulado" $?
chk "rol con otra capitalización (ADMIN)" 422 -H "Origin: $ORIGIN" -d "name=Rol&last_name=Falso&email=smoke-m3-e@example.com&role=ADMIN" "$BASE/create.php"
chk "rol vacío" 422 -H "Origin: $ORIGIN" -d "name=Rol&last_name=Falso&email=smoke-m3-e@example.com&role=" "$BASE/create.php"
chk "sin rol (curl como antes de M3)" 422 -H "Origin: $ORIGIN" -d "name=Rol&last_name=Falso&email=smoke-m3-e@example.com" "$BASE/create.php"
chk "rol como arreglo" 422 -H "Origin: $ORIGIN" -d "name=Rol&last_name=Falso&email=smoke-m3-e@example.com&role[]=admin" "$BASE/create.php"
[ "$(sql "SELECT COUNT(*) FROM users WHERE email='smoke-m3-e@example.com';")" = 0 ]; ok "ningún rol falso llegó a la BD" $?

for bad in 2023-02-30 1990-13-01 17/05/1990 1990-5-17 ayer 2999-01-01 1899-12-31; do
    chk "fecha de nacimiento inválida «$bad»" 422 -H "Origin: $ORIGIN" -d "name=Fe&last_name=Cha&email=smoke-m3-f@example.com&birth_date=$bad&role=user" "$BASE/create.php"
done
tr -d '\n' < "$TMP/body" | grep -qE 'name="birth_date"\s+value="1899-12-31"'; ok "...y el formulario conserva lo escrito en la fecha" $?
chk "documento inválido (con espacios)" 422 -H "Origin: $ORIGIN" --data-urlencode "document=123 456" -d "name=Do&last_name=Cu&email=smoke-m3-f@example.com&role=user" "$BASE/create.php"
chk "documento corto" 422 -H "Origin: $ORIGIN" -d "name=Do&last_name=Cu&email=smoke-m3-f@example.com&document=1234&role=user" "$BASE/create.php"
chk "documento de 21 caracteres" 422 -H "Origin: $ORIGIN" -d "name=Do&last_name=Cu&email=smoke-m3-f@example.com&document=$(printf '9%.0s' $(seq 1 21))&role=user" "$BASE/create.php"
[ "$(sql "SELECT COUNT(*) FROM users WHERE email='smoke-m3-f@example.com';")" = 0 ]; ok "ninguno de los inválidos se guardó" $?

section "Más campos (M3): listado y búsqueda"
chk "listado buscando por documento (en minúsculas y a medias)" 200 --get --data-urlencode "q=b-123" "$BASE/"
[ "$(grep -c 'edit.php?id=' "$TMP/body")" = 1 ] && grep -qF '<td>AB-12345</td>' "$TMP/body"; ok "la búsqueda encuentra por documento y lo muestra" $?
tr -d '\n' < "$TMP/body" | grep -qE '<td>AB-12345</td>\s*<td>1990-05-17</td>\s*<td>Editor</td>'; ok "...con la fecha y la ETIQUETA del rol (Editor)" $?
chk "listado de uno sin los campos nuevos" 200 --get --data-urlencode "q=smoke-m3-b" "$BASE/"
tr -d '\n' < "$TMP/body" | grep -qE '<td>-</td>\s*<td>-</td>\s*<td>-</td>\s*<td>Usuario</td>'; ok "teléfono, documento y fecha vacíos se ven como «-» y el rol «Usuario»" $?

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
chk "guardar al antiguo sin tocar nada nuevo" 302 -H "Origin: $ORIGIN" -d "id=$UOLD&name=Antiguo&last_name=Sin+Campos&email=smoke-m3-old@example.com&phone=&document=&birth_date=&role=user" "$BASE/edit.php"
[ "$(sql "SELECT CONCAT(IFNULL(document,'NULL'),'|',IFNULL(birth_date,'NULL')) FROM users WHERE id=$UOLD;")" = "NULL|NULL" ]; ok "...y siguen en NULL" $?
chk "ponerle a otro un documento que ya existe" 409 -H "Origin: $ORIGIN" -d "id=$UOLD&name=Antiguo&last_name=Sin+Campos&email=smoke-m3-old@example.com&document=ab-12345&role=user" "$BASE/edit.php"
grep -qF 'Ya existe otro usuario registrado con ese documento.' "$TMP/body"; ok "...con el aviso del documento" $?
chk "guardar a A conservando SU PROPIO documento (no choca consigo mismo)" 302 -H "Origin: $ORIGIN" -d "id=$UA&name=Mas&last_name=Campos&email=smoke-m3-a@example.com&document=AB-12345&birth_date=1990-05-17&role=editor" "$BASE/edit.php"
chk "cambiar el rol de A a admin" 302 -H "Origin: $ORIGIN" -d "id=$UA&name=Mas&last_name=Campos&email=smoke-m3-a@example.com&document=AB-12345&birth_date=1990-05-17&role=admin" "$BASE/edit.php"
[ "$(sql "SELECT role FROM users WHERE id=$UA;")" = admin ]; ok "el rol cambió en la BD" $?
chk "rol manipulado al editar" 422 -H "Origin: $ORIGIN" -d "id=$UA&name=Mas&last_name=Campos&email=smoke-m3-a@example.com&role=root" "$BASE/edit.php"
[ "$(sql "SELECT role FROM users WHERE id=$UA;")" = admin ]; ok "...y el rol guardado no cambió" $?
chk "quitar documento y fecha a A" 302 -H "Origin: $ORIGIN" -d "id=$UA&name=Mas&last_name=Campos&email=smoke-m3-a@example.com&document=&birth_date=&role=admin" "$BASE/edit.php"
[ "$(sql "SELECT CONCAT(IFNULL(document,'NULL'),'|',IFNULL(birth_date,'NULL')) FROM users WHERE id=$UA;")" = "NULL|NULL" ]; ok "vaciarlos los deja en NULL" $?
chk "A guarda con la huella VIEJA tras cambiar solo el rol (el rol cuenta en la huella)" 409 -H "Origin: $ORIGIN" -d "id=$UA&row_token=$TOKEN_M3&name=Mas&last_name=Campos&email=smoke-m3-a@example.com&role=user" "$BASE/edit.php"
chk "fecha inválida al editar" 422 -H "Origin: $ORIGIN" -d "id=$UA&name=Mas&last_name=Campos&email=smoke-m3-a@example.com&birth_date=2023-02-30&role=admin" "$BASE/edit.php"
tr -d '\n' < "$TMP/body" | grep -qE 'name="birth_date"\s+value="2023-02-30"'; ok "...y conserva lo escrito" $?

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
soft_deleted "$U1"; ok "el usuario NO se borró: la fila sigue con active = 0" $?
chk "repetir el borrado (ya estaba eliminado)" 302 -H "Origin: $ORIGIN" -d "id=$U1" "$BASE/delete.php"
header_of location | grep -q 'msg=missing'; ok "redirige a msg=missing (no es un error)" $?
chk "POST con un id válido que nunca existió" 302 -H "Origin: $ORIGIN" -d "id=4294967295" "$BASE/delete.php"
chk "borrar al usuario con apóstrofe, con Origin en mayúsculas" 302 -H "Origin: $(printf '%s' "$ORIGIN" | tr '[:lower:]' '[:upper:]')" -d "id=$UO" "$BASE/delete.php"
soft_deleted "$UO"; ok "el usuario O'Brien quedó con active = 0 (la fila sigue)" $?

section "Borrado lógico (M4): la fila se conserva y deja de verse"
chk "alta de un usuario para eliminar" 302 -H "Origin: $ORIGIN" -d "name=Papelera&last_name=Prueba&email=smoke-m4-a@example.com&document=PA-123456&role=editor" "$BASE/create.php"
M4=$(id_of smoke-m4-a@example.com)
chk "otro usuario que se queda" 302 -H "Origin: $ORIGIN" -d "name=Papelera&last_name=Quedo&email=smoke-m4-b@example.com&role=user" "$BASE/create.php"
M4B=$(id_of smoke-m4-b@example.com)
chk "buscar antes de eliminar" 200 --get --data-urlencode "q=smoke-m4" "$BASE/"
[ "$(grep -c 'edit.php?id=' "$TMP/body")" = 2 ]; ok "se ven los 2 usuarios" $?
chk "eliminar a uno" 302 -H "Origin: $ORIGIN" -d "id=$M4" "$BASE/delete.php"
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
chk "guardar cambios del eliminado" 404 -H "Origin: $ORIGIN" -d "id=$M4&name=Hack&last_name=Hack&email=smoke-m4-a@example.com&role=admin" "$BASE/edit.php"
[ "$(sql "SELECT name FROM users WHERE id=$M4;")" = Papelera ]; ok "...y no se modificó" $?
chk "eliminar otra vez al eliminado" 302 -H "Origin: $ORIGIN" -d "id=$M4" "$BASE/delete.php"
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
chk "restaurar sin origen" 403 -d "id=$M4" "$BASE/restore.php"
chk "restaurar con Origin ajeno" 403 -H "Origin: http://evil.example" -d "id=$M4" "$BASE/restore.php"
chk "restaurar sin origen y con id=abc (403 antes que 400)" 403 -d "id=abc" "$BASE/restore.php"
soft_deleted "$M4"; ok "tras los 405 y 403 sigue eliminado" $?
for bad in "" "id=abc" "id=0" "id%5B%5D=1"; do chk "restaurar con origen correcto y '$bad'" 400 -H "Origin: $ORIGIN" -d "$bad" "$BASE/restore.php"; done
chk "restaurar a un id que no existe" 302 -H "Origin: $ORIGIN" -d "id=4294967295" "$BASE/restore.php"
header_of location | grep -q 'msg=not_restored'; ok "...avisa que no había nada que restaurar" $?
chk "restaurar a un usuario que está ACTIVO" 302 -H "Origin: $ORIGIN" -d "id=$M4B" "$BASE/restore.php"
header_of location | grep -q 'msg=not_restored'; ok "...tampoco restaura nada" $?
chk "restaurar al eliminado" 302 -H "Origin: $ORIGIN" -d "id=$M4&view=inactive&q=smoke-m4" "$BASE/restore.php"
[ "$(header_of location)" = "Location: index.php?msg=restored&view=inactive&q=smoke-m4" ]; ok "vuelve a la lista de eliminados, con su búsqueda y el aviso" $?
is_active "$M4"; ok "active vuelve a 1" $?
[ "$(sql "SELECT CONCAT(name,'|',document,'|',role) FROM users WHERE id=$M4;")" = "Papelera|PA-123456|editor" ]; ok "...con todos sus datos intactos" $?
chk "aviso de restaurado" 200 "$BASE/index.php?msg=restored&view=inactive"
grep -qF 'El usuario ha sido restaurado exitosamente.' "$TMP/body"; ok "se muestra el aviso" $?
chk "el restaurado vuelve al listado" 200 --get --data-urlencode "q=PA-123456" "$BASE/"
grep -q "id=$M4" "$TMP/body"; ok "y se encuentra por su documento" $?
chk "restaurar otra vez" 302 -H "Origin: $ORIGIN" -d "id=$M4" "$BASE/restore.php"
header_of location | grep -q 'msg=not_restored'; ok "...no restaura nada (not_restored)" $?

section "El correo y el documento de un eliminado siguen ocupados (decisión de M4)"
chk "eliminar de nuevo" 302 -H "Origin: $ORIGIN" -d "id=$M4" "$BASE/delete.php"
chk "crear con el correo de un eliminado" 409 -H "Origin: $ORIGIN" -d "name=Otro&last_name=Nuevo&email=smoke-m4-a@example.com&role=user" "$BASE/create.php"
grep -qF 'Ese correo pertenece a un usuario eliminado. Restáuralo desde «Eliminados»' "$TMP/body"; ok "el aviso explica que está eliminado y cómo recuperarlo" $?
chk "crear con el documento de un eliminado (otra capitalización)" 409 -H "Origin: $ORIGIN" -d "name=Otro&last_name=Nuevo&email=smoke-m4-n@example.com&document=pa-123456&role=user" "$BASE/create.php"
grep -qF 'Ese documento pertenece a un usuario eliminado' "$TMP/body"; ok "...también por documento" $?
chk "crear con el correo de un usuario ACTIVO: aviso normal" 409 -H "Origin: $ORIGIN" -d "name=Otro&last_name=Nuevo&email=smoke-m4-b@example.com&role=user" "$BASE/create.php"
grep -qF 'Ya existe un usuario registrado con ese correo.' "$TMP/body" && ! grep -qF 'eliminado' "$TMP/body"; ok "...sin hablar de eliminados" $?
chk "editar a uno para ponerle el correo de un eliminado" 409 -H "Origin: $ORIGIN" -d "id=$M4B&name=Papelera&last_name=Quedo&email=smoke-m4-a@example.com&role=user" "$BASE/edit.php"
grep -qF 'Ese correo pertenece a un usuario eliminado' "$TMP/body"; ok "...el aviso de edición también lo dice" $?
[ "$(sql "SELECT COUNT(*) FROM users WHERE email='smoke-m4-n@example.com';")" = 0 ]; ok "no se creó ningún usuario con esos datos" $?

section "El estado se conserva en la vista de eliminados"
sql "SET SESSION cte_max_recursion_depth = 100; INSERT INTO users (name, last_name, email, active) WITH RECURSIVE n(i) AS (SELECT 1 UNION ALL SELECT i+1 FROM n WHERE i < 12) SELECT 'Elim', 'Pagina', CONCAT('smoke-m4-e', LPAD(i, 2, '0'), '@example.com'), 0 FROM n;" >/dev/null
chk "eliminados, página 2" 200 --get --data-urlencode "view=inactive" --data-urlencode "q=smoke-m4-e" --data-urlencode "page=2" "$BASE/"
[ "$(grep -c 'action="restore.php"' "$TMP/body")" = 2 ]; ok "12 eliminados: la página 2 tiene 2 filas" $?
LINKS=$(tr -d '\n' < "$TMP/body" | grep -oE '<nav aria-label="Paginación.*</nav>' | grep -oE 'href="[^"]*"')
[ -n "$LINKS" ] && ! printf '%s\n' "$LINKS" | grep -qv 'view=inactive'; ok "los enlaces de página se quedan en la vista de eliminados" $?
tr -d '\n' < "$TMP/body" | grep -qE 'name="view" value="inactive">\s*<input type="hidden" name="q" value="smoke-m4-e">\s*<input type="hidden" name="page" value="2">'; ok "el botón Restaurar lleva view, q y page" $?
LAST2=$(grep -oE 'name="id" value="[0-9]+"' "$TMP/body" | grep -oE '[0-9]+')
for id in $LAST2; do chk "restaurar al $id desde la página 2" 302 -H "Origin: $ORIGIN" -d "id=$id&view=inactive&q=smoke-m4-e&page=2" "$BASE/restore.php"; done
chk "al restaurar a los de la última página, la página 2 ya no existe: se muestra la nueva última" 200 "$BASE/index.php?msg=restored&view=inactive&q=smoke-m4-e&page=2"
tr -d '\n' < "$TMP/body" | sed -E 's/\s+/ /g' | grep -qF "10 resultados para «smoke-m4-e»."; ok "(quedan 10: una sola página, sin «Mostrando»)" $?
[ "$(grep -c 'action="restore.php"' "$TMP/body")" = 10 ]; ok "...con 10 filas" $?

if [ "$WITH_DB_STOP" = 1 ]; then
    section "Base de datos detenida (503)"
    chk "se crea un usuario para la prueba" 302 -H "Origin: $ORIGIN" -d "role=user&name=Smoke&last_name=Dos&email=smoke-two@example.com" "$BASE/create.php"
    U2=$(id_of smoke-two@example.com)
    docker compose stop db >/dev/null 2>&1
    chk "GET /" 503 "$BASE/"
    chk "GET /?q=zoe (una búsqueda)" 503 --get --data-urlencode "q=zoe" "$BASE/"
    chk "GET /?page=2 (una página)" 503 "$BASE/?page=2"
    chk "GET /?q=zoe&page=3 (búsqueda y página)" 503 --get --data-urlencode "q=zoe" --data-urlencode "page=3" "$BASE/"
    chk "GET create.php" 200 "$BASE/create.php"
    chk "POST create.php" 503 -H "Origin: $ORIGIN" -d "role=user&name=Ana&last_name=Ruiz&email=smoke-y@example.com" "$BASE/create.php"
    chk "GET edit.php" 503 "$BASE/edit.php?id=$U2"
    chk "POST edit.php" 503 -H "Origin: $ORIGIN" -d "role=user&id=$U2&name=Ana&last_name=Ruiz&email=smoke-y@example.com" "$BASE/edit.php"
    grep -q 'value="Ana"' "$TMP/body"; ok "edit.php conserva lo que escribió el usuario" $?
    chk "POST delete.php con origen correcto" 503 -H "Origin: $ORIGIN" -d "id=$U2" "$BASE/delete.php"
    chk "POST restore.php con origen correcto" 503 -H "Origin: $ORIGIN" -d "id=$U2" "$BASE/restore.php"
    chk "GET /?view=inactive" 503 "$BASE/?view=inactive"
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
    chk "POST create.php" 500 -H "Origin: $ORIGIN" -d "role=user&name=Ana&last_name=Ruiz&email=smoke-q@example.com" "$BASE/create.php"
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
