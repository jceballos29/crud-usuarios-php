<?php

declare(strict_types=1);

/**
 * Pruebas unitarias de las funciones puras: sin base de datos ni servidor web.
 *
 * Ejecutar (desde la raíz del proyecto):
 *     docker compose exec php php tests/unit.php
 *
 * Sale con código 0 si todo pasa y con 1 si algo falla. Sin dependencias (no hace falta PHPUnit):
 * cuando el proyecto crezca (M8), estos mismos casos se trasladan tal cual a PHPUnit.
 * Esta carpeta está fuera de public/: no se puede abrir desde el navegador.
 */

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/user-validation.php';
require_once __DIR__ . '/../includes/origin.php';
require_once __DIR__ . '/../includes/user-search.php';
require_once __DIR__ . '/../includes/pagination.php';

require_once __DIR__ . '/harness.php';

function nameError(string $name): ?string
{
    $fields = user_fields();
    $data = read_user_input(['name' => $name, 'last_name' => 'Perez', 'email' => 'a@b.co'], $fields);

    return validate_user($data, $fields)['name'] ?? null;
}

function emailError(string $email): ?string
{
    $fields = user_fields();
    $data = read_user_input(['name' => 'Ana', 'last_name' => 'Perez', 'email' => $email], $fields);

    return validate_user($data, $fields)['email'] ?? null;
}

function phoneError(string $phone): ?string
{
    $fields = user_fields();
    $data = read_user_input(['name' => 'Ana', 'last_name' => 'Perez', 'email' => 'a@b.co', 'phone' => $phone], $fields);

    return validate_user($data, $fields)['phone'] ?? null;
}

section('parse_user_id(): solo dígitos, entre 1 y 4294967295');
foreach (['14' => 14, '1' => 1, '4294967295' => 4294967295] as $raw => $id) {
    same("'$raw' es válido", $id, parse_user_id((string) $raw));
}
$invalidIds = ['0', '-3', '+14', ' 14', '14 ', '014', 'abc', '', '1e3', '0x1A', '14.0', '4294967296', '99999999999999999999', '٣'];
foreach ($invalidIds as $raw) {
    same(var_export($raw, true) . ' no es válido', null, parse_user_id($raw));
}
same('null (no llegó)', null, parse_user_id(null));
same('un arreglo (?id[]=1)', null, parse_user_id(['1']));
same('un arreglo vacío', null, parse_user_id([]));
same('un int real no es texto de la petición', null, parse_user_id(14));
same('un bool', null, parse_user_id(true));

section('normalize_origin(): esquema://host[:puerto] en minúsculas, sin el puerto por defecto');
same('mayúsculas', 'http://localhost:8080', normalize_origin('HTTP://LOCALHOST:8080'));
same('https con puerto 443 explícito', 'https://sitio.com', normalize_origin('https://sitio.com:443'));
same('http con puerto 80 explícito', 'http://sitio.com', normalize_origin('http://sitio.com:80'));
same('se descarta la ruta y la query de un Referer', 'http://localhost:8080', normalize_origin('http://localhost:8080/index.php?msg=x'));
same('un Origin no admite ruta', null, normalize_origin('http://localhost:8080/x', true));
same('host seguido de texto (…8080.evil.com)', null, normalize_origin('http://localhost:8080.evil.com'));
same('usuario y contraseña (…8080@evil.com)', null, normalize_origin('http://localhost:8080@evil.com'));
same('"null"', null, normalize_origin('null'));
same('esquema javascript:', null, normalize_origin('javascript:alert(1)'));
same('espacio al final (parse_url lo ignoraría)', null, normalize_origin('http://localhost:8080 '));
same('puerto con letra (parse_url leería 80)', null, normalize_origin('http://localhost:80a'));
same('cadena vacía', null, normalize_origin(''));

section('is_allowed_origin(): Origin manda; si no, Referer; si no hay ninguno, se rechaza');
$site = 'http://localhost:8080';
same('Origin correcto', true, is_allowed_origin($site, null, $site));
same('solo Referer correcto (con ruta y query)', true, is_allowed_origin(null, 'http://localhost:8080/index.php?msg=x', $site));
same('Origin correcto y Referer ajeno', true, is_allowed_origin($site, 'http://evil.example/', $site));
same('Origin ajeno y Referer correcto (manda Origin)', false, is_allowed_origin('http://evil.example', $site . '/', $site));
same('sin cabeceras', false, is_allowed_origin(null, null, $site));
same('Origin: null', false, is_allowed_origin('null', null, $site));
same('Origin vacío con Referer correcto', false, is_allowed_origin('', $site . '/', $site));
same('Origin con espacios', false, is_allowed_origin('   ', $site . '/', $site));
same('localhost:8080.evil.com', false, is_allowed_origin('http://localhost:8080.evil.com', null, $site));
same('Referer http://evil.com/?http://localhost:8080', false, is_allowed_origin(null, 'http://evil.com/?http://localhost:8080', $site));
same('127.0.0.1 es otro origen', false, is_allowed_origin('http://127.0.0.1:8080', null, $site));
same('otro esquema', false, is_allowed_origin('https://localhost:8080', null, $site));
same('otro puerto', false, is_allowed_origin('http://localhost:9090', null, $site));
same('https:// con y sin :443', true, is_allowed_origin('https://sitio.com:443', null, 'https://sitio.com'));
same('APP_ORIGIN con barra final', true, is_allowed_origin($site, null, $site . '/'));
foreach (['', 'localhost:8080', 'ftp://x', 'null'] as $badConfig) {
    try {
        is_allowed_origin($site, null, $badConfig);
        same('APP_ORIGIN ' . var_export($badConfig, true) . ' lanza excepción', 'UnexpectedValueException', 'no lanzó');
    } catch (UnexpectedValueException) {
        same('APP_ORIGIN ' . var_export($badConfig, true) . ' lanza UnexpectedValueException (un 500, no un 403)', true, true);
    }
}

section('user_fields(): definición de los campos del formulario');
same('el teléfono usa type="tel" (teclado numérico en el móvil)', 'tel', user_fields()['phone']['type']);
same('el correo usa type="email"', 'email', user_fields()['email']['type']);
same('los máximos coinciden con las columnas', [100, 100, 254, 20, 20, 10, 0], array_column(user_fields(), 'max'));

section('user_row_token(): huella de la fila para detectar ediciones simultáneas');
$row = ['name' => 'Ana', 'last_name' => 'Ruiz', 'email' => 'a@b.co', 'phone' => null, 'document' => null, 'birth_date' => null, 'role' => 'user'];
$token = user_row_token($row);
same('es un sha256 en hexadecimal', 1, preg_match('/^[0-9a-f]{64}$/', $token));
same('es determinista', $token, user_row_token($row));
same('no depende del orden de las claves', $token, user_row_token(['role' => 'user', 'birth_date' => null, 'document' => null, 'phone' => null, 'email' => 'a@b.co', 'last_name' => 'Ruiz', 'name' => 'Ana']));
foreach (['name' => 'Anita', 'last_name' => 'Ruiz2', 'email' => 'x@b.co', 'phone' => '3001234567', 'document' => 'AB123', 'birth_date' => '1990-01-01', 'role' => 'admin'] as $column => $other) {
    same("cambia si cambia $column", true, $token !== user_row_token([$column => $other] + $row));
}
same('cambia con solo una mayúscula (la comparación SQL no lo vería)', true, $token !== user_row_token(['name' => 'ana'] + $row));
same('phone NULL y phone vacío son distintos', true, $token !== user_row_token(['phone' => ''] + $row));
same('document NULL y document vacío son distintos', true, $token !== user_row_token(['document' => ''] + $row));

section('read_user_input(): limpia la entrada cruda');
$fields = user_fields();
$data = read_user_input(['name' => '  Ana  ', 'last_name' => 'Ruiz', 'email' => '  ANA@Ejemplo.COM ', 'phone' => ' 300 ', 'extra' => 'x'], $fields);
same('trim del nombre', 'Ana', $data['name']);
same('el correo se pasa a minúsculas', 'ana@ejemplo.com', $data['email']);
same('trim del teléfono', '300', $data['phone']);
same('solo quedan las claves definidas', ['name', 'last_name', 'email', 'phone', 'document', 'birth_date', 'role'], array_keys($data));
$data = read_user_input(['name' => ['x'], 'last_name' => null, 'email' => 5], $fields);
same('un arreglo (name[]=x) se trata como vacío', '', $data['name']);
same('null se trata como vacío', '', $data['last_name']);
same('un número se trata como vacío', '', $data['email']);
same('un campo que no llegó queda vacío', '', $data['phone']);

section('validate_user(): nombres y apellidos (permisivo: una letra y ningún carácter de control)');
foreach (['Pere Cal·lís', 'Smith, Jr.', 'Juan 2º', 'Jean-Luc', "D'Angelo", 'Nguyễn', '李雷', 'O’Neil', 'می‌خواهم', 'Ng'] as $name) {
    same("acepta $name", null, nameError($name));
}
same('vacío: obligatorio', 'El nombre es obligatorio.', nameError(''));
same('solo espacios: obligatorio', 'El nombre es obligatorio.', nameError('    '));
foreach (['12345', '-.-', "Ana\nMaría", "Ana\tMaría", "Ana\0María", "Ana\xC3\x28"] as $name) {
    same('rechaza ' . json_encode($name, JSON_INVALID_UTF8_SUBSTITUTE), true, nameError($name) !== null);
}
same('100 caracteres: acepta', null, nameError(str_repeat('a', 100)));
same('101 caracteres: rechaza', 'El nombre no debe superar los 100 caracteres.', nameError(str_repeat('a', 101)));

section('validate_user(): correo');
foreach (['ñandu@correo.com', 'juan@gmail.co', 'JUAN@Gmail.COM'] as $email) {
    same("acepta $email", null, emailError($email));
}
foreach (['juan@gmail', 'a@b', 'juan@@gmail.com', 'juan @gmail.com', 'juan@gmail..com', '@gmail.com'] as $email) {
    same("rechaza $email", 'Ingresa un email válido.', emailError($email));
}
same('vacío: obligatorio', 'El email es obligatorio.', emailError(''));
same('255 caracteres: rechaza', 'El email no debe superar los 254 caracteres.', emailError(str_repeat('a', 246) . '@mail.com'));

section('validate_user(): teléfono (opcional; de 7 a 15 dígitos reales)');
same('vacío es válido', null, phoneError(''));
foreach (['3001234567', '+57 300 123 4567', '(601) 234-5678', '1234567', '+123456789012345'] as $phone) {
    same("acepta $phone", null, phoneError($phone));
}
foreach (['-------', '()()()()', '+ - -', '123456', '+1234567890123456', "300\n1234567", "300\t1234567", 'abc', '300.123.4567'] as $phone) {
    same('rechaza ' . json_encode($phone), true, phoneError($phone) !== null);
}
same('mensaje de dígitos', 'El teléfono debe tener entre 7 y 15 dígitos.', phoneError('-------'));
same('más de 20 caracteres', 'El teléfono no debe superar los 20 caracteres.', phoneError('1 2 3 4 5 6 7 8 9 0 1 2 3'));

section('format_utc_datetime(): la BD guarda UTC y se muestra en la zona de la aplicación');
same('UTC a la zona de php.ini (America/Bogota)', '2026-11-19 09:08:36', format_utc_datetime('2026-11-19 14:08:36', 'America/Bogota'));
same('UTC a Madrid en invierno (+1)', '2026-11-19 15:08:36', format_utc_datetime('2026-11-19 14:08:36', 'Europe/Madrid'));
same('UTC a Madrid en verano (+2)', '2026-07-01 14:00:00', format_utc_datetime('2026-07-01 12:00:00', 'Europe/Madrid'));
same('cruza la medianoche hacia atrás', '2026-11-18 22:00:00', format_utc_datetime('2026-11-19 03:00:00', 'America/Bogota'));
same('cruza el año hacia adelante', '2027-01-01 08:30:00', format_utc_datetime('2026-12-31 23:30:00', 'Asia/Tokyo'));
same('una fecha posterior a 2038', '2040-05-31 19:00:00', format_utc_datetime('2040-06-01 00:00:00', 'America/Bogota'));
same('fecha inválida: se devuelve tal cual', 'no es una fecha', format_utc_datetime('no es una fecha', 'America/Bogota'));
same('zona inválida: se devuelve tal cual', '2026-11-19 14:08:36', format_utc_datetime('2026-11-19 14:08:36', 'Zona/Inexistente'));

section('escape(): el único escape de salida');
same('menor y mayor que', '&lt;b&gt;', escape('<b>'));
same('comilla doble y simple', '&quot;a&quot; &#039;b&#039;', escape('"a" \'b\''));
same('ampersand', 'a &amp; b', escape('a & b'));
same('null', '', escape(null));
same('un número', '14', escape(14));
same('UTF-8 inválido se sustituye en vez de borrar todo', "a\u{FFFD}b", escape("a\xC3b"));

section('parse_search_term(): limpia lo que se escribe en el buscador');
same('null (no llegó)', '', parse_search_term(null));
same('un arreglo (?q[]=x)', '', parse_search_term(['x']));
same('un número', '', parse_search_term(5));
same('texto normal', 'laura', parse_search_term('laura'));
same('conserva mayúsculas y tildes (la collation es cosa de MySQL)', 'Gómez', parse_search_term('Gómez'));
same('trim', 'laura', parse_search_term('  laura  '));
same('espacios repetidos se juntan: "laura   gomez"', 'laura gomez', parse_search_term('laura   gomez'));
same('saltos de línea y tabuladores se vuelven un espacio', 'laura gomez', parse_search_term("laura\n\tgomez"));
same('un carácter NUL se vuelve un espacio', 'a b', parse_search_term("a\0b"));
same('solo espacios: sin búsqueda', '', parse_search_term('     '));
same('UTF-8 inválido: sin búsqueda', '', parse_search_term("ab\xC3\x28"));
same('300 caracteres se recortan a 254', 254, mb_strlen(parse_search_term(str_repeat('a', 300)), 'UTF-8'));
same('el recorte es por caracteres y no por bytes (ñ ocupa 2 bytes)', 254, mb_strlen(parse_search_term(str_repeat('ñ', 300)), 'UTF-8'));
same('si el recorte deja un espacio al final, se quita', str_repeat('a', 253), parse_search_term(str_repeat('a', 253) . ' bbb'));
same('SEARCH_TERM_MAX coincide con la columna más larga (el correo)', user_fields()['email']['max'], SEARCH_TERM_MAX);

section('like_pattern(): los comodines del texto se escapan, los de los extremos van en el valor');
same('texto normal', '%laura%', like_pattern('laura'));
same('% se escapa', '%100\\%%', like_pattern('100%'));
same('_ se escapa', '%Sub\\_Real%', like_pattern('Sub_Real'));
same('la barra invertida se escapa', '%a\\\\b%', like_pattern('a\\b'));
same('barra y porcentaje juntos (\\%): una sola pasada, sin escapar dos veces', '%\\\\\\%%', like_pattern('\\%'));
same('vacío', '%%', like_pattern(''));
same('los %, _ y \\ de cada extremo también se escapan', '%\\%x\\_\\\\%', like_pattern('%x_\\'));

section('user_search_where() y user_count_query(): el mismo filtro para listar y para contar');
same('sin búsqueda: ni condición ni parámetros', ['', []], user_search_where(''));
same('recuento sin búsqueda', ['SELECT COUNT(*) FROM users', []], user_count_query(''));
[$countSql, $countParams] = user_count_query('laura');
preg_match_all('/:q\d+/', $countSql, $countMarkers);
same('recuento con búsqueda: 6 marcadores distintos', [6, 6], [count($countMarkers[0]), count(array_unique($countMarkers[0]))]);
same('el recuento empieza por SELECT COUNT(*) y lleva el WHERE', true, str_starts_with($countSql, 'SELECT COUNT(*) FROM users WHERE '));
same('el recuento no lleva ORDER BY ni LIMIT (no hacen falta para contar)', false, str_contains($countSql, 'ORDER BY') || str_contains($countSql, 'LIMIT'));

section('user_list_query(): una página, marcadores distintos y nada del visitante dentro del SQL');
[$allSql, $allParams] = user_list_query('', 10, 0);
same('sin búsqueda: solo los parámetros de la página', [':limit' => 10, ':offset' => 0], $allParams);
same('sin búsqueda: sin WHERE', false, str_contains($allSql, 'WHERE'));
same('ordena por id descendente y pagina', true, str_ends_with($allSql, 'ORDER BY id DESC LIMIT :limit OFFSET :offset'));
same('LIMIT y OFFSET son enteros de PHP (bind_all los enlaza con PARAM_INT)', [true, true], [is_int($allParams[':limit']), is_int($allParams[':offset'])]);
[$sql, $params] = user_list_query('laura', 10, 20);
preg_match_all('/:q\d+/', $sql, $matches);
same('con búsqueda: 6 marcadores de columna', 6, count($matches[0]));
same('con búsqueda: NINGÚN marcador repetido (si no, HY093 con prepares reales)', count($matches[0]), count(array_unique($matches[0])));
same('los parámetros son exactamente esos marcadores más :limit y :offset', [...array_unique($matches[0]), ':limit', ':offset'], array_keys($params));
same('los 5 de la búsqueda reciben el mismo patrón', ['%laura%'], array_values(array_unique(array_slice($params, 0, 6))));
same('LIMIT y OFFSET llegan tal cual', [10, 20], [$params[':limit'], $params[':offset']]);
same('busca en el nombre completo ("Laura Gómez")', true, str_contains($sql, "CONCAT(name, ' ', last_name) LIKE"));
same('busca en el correo y en el teléfono', true, str_contains($sql, 'email LIKE') && str_contains($sql, 'phone LIKE'));
[$negSql, $negParams] = user_list_query('', 0, -5);
same('LIMIT 0 se corrige a 1 (MySQL no admite negativos)', 1, $negParams[':limit']);
same('OFFSET negativo se corrige a 0', 0, $negParams[':offset']);
[$negSql2, $negParams2] = user_list_query('', -3, 5);
same('LIMIT negativo se corrige a 1', 1, $negParams2[':limit']);
[$listSql] = user_list_query('x', 10, 0);
[$countSqlX] = user_count_query('x');
same('el listado y el recuento comparten exactamente el mismo WHERE', substr($countSqlX, strlen('SELECT COUNT(*) FROM users')), substr($listSql, strlen('SELECT id, name, last_name, email, phone, document, birth_date, role, created_at FROM users'), strpos($listSql, ' ORDER BY') - strlen('SELECT id, name, last_name, email, phone, document, birth_date, role, created_at FROM users')));
$hostile = "'; DROP TABLE users; --";
[$hostileSql, $hostileParams] = user_list_query($hostile, 10, 0);
same('un texto hostil no aparece en el SQL', false, str_contains($hostileSql, 'DROP') || str_contains($hostileSql, '--'));
same('un texto hostil viaja solo como parámetro', true, str_contains($hostileParams[':q0'], 'DROP TABLE users'));
[$uniqueSql] = user_list_query('zzzunico', 10, 0);
same('lo que se busca nunca está en el SQL', false, str_contains($uniqueSql, 'zzzunico'));

section('parse_page(): lo que no es un entero >= 1 cuenta como la página 1');
foreach (['1' => 1, '2' => 2, '35' => 35, '02' => 2, '007' => 7, '999999999999999' => 999999999999999] as $raw => $page) {
    same("'$raw' es la página $page", $page, parse_page((string) $raw));
}
foreach (['0', '00', '-3', '1.5', 'abc', '', ' 2', '2 ', '+2', '1e3', '٣'] as $raw) {
    same(var_export($raw, true) . ' es la página 1', 1, parse_page($raw));
}
same('null (no llegó)', 1, parse_page(null));
same('un arreglo (?page[]=2)', 1, parse_page(['2']));
same('un int real no es texto de la petición', 1, parse_page(5));
same('16 dígitos: no cabe con holgura, se trata como "más allá de la última"', PHP_INT_MAX, parse_page('9999999999999999'));
same('23 dígitos: igual', PHP_INT_MAX, parse_page('99999999999999999999999'));

section('paginate(): total de páginas, corrección del rango y filas mostradas');
same('0 usuarios: una sola página, vacía (nunca cero páginas)', ['page' => 1, 'pages' => 1, 'perPage' => 10, 'offset' => 0, 'total' => 0, 'from' => 0, 'to' => 0], paginate(0, 1));
same('1 usuario', [1, 1, 1], [paginate(1, 1)['pages'], paginate(1, 1)['from'], paginate(1, 1)['to']]);
same('10 usuarios caben en una página', [1, 10], [paginate(10, 1)['pages'], paginate(10, 1)['to']]);
same('11 usuarios necesitan dos', 2, paginate(11, 1)['pages']);
same('11 usuarios, página 2: solo la fila 11', [10, 11, 11], [paginate(11, 2)['offset'], paginate(11, 2)['from'], paginate(11, 2)['to']]);
same('35 usuarios son 4 páginas', 4, paginate(35, 1)['pages']);
same('35 usuarios, página 4: filas 31 a 35', [30, 31, 35], [paginate(35, 4)['offset'], paginate(35, 4)['from'], paginate(35, 4)['to']]);
same('?page=999 con 35 usuarios muestra la 4, no una vacía', 4, paginate(35, 999)['page']);
same('PHP_INT_MAX también se corrige a la última', 4, paginate(35, PHP_INT_MAX)['page']);
same('la página 0 se corrige a la 1', 1, paginate(35, 0)['page']);
same('un total negativo se trata como 0', 0, paginate(-5, 1)['total']);
same('con otro tamaño de página (5): 11 usuarios son 3 páginas', 3, paginate(11, 1, 5)['pages']);
// Propiedad: para cualquier total y cualquier página pedida, las páginas cubren TODAS las filas, sin huecos ni repeticiones.
$violations = [];
for ($total = 0; $total <= 120; $total++) {
    $covered = 0;
    $pages = paginate($total, 1)['pages'];
    for ($requested = 1; $requested <= $pages; $requested++) {
        $info = paginate($total, $requested);
        $rows = $info['to'] - $info['from'] + 1;
        $covered += $total === 0 ? 0 : $rows;
        if ($info['offset'] < 0 || $info['page'] !== $requested || ($total > 0 && ($info['from'] !== $info['offset'] + 1 || $info['to'] > $total || $rows < 1 || $rows > 10))) {
            $violations[] = "total=$total página=$requested";
        }
    }
    if ($covered !== $total) {
        $violations[] = "total=$total cubierto=$covered";
    }
    if (paginate($total, $pages + 50)['page'] !== $pages) {
        $violations[] = "total=$total no corrige una página fuera de rango";
    }
}
same('propiedad: para cualquier total de 0 a 120, las páginas cubren todas las filas sin huecos ni repeticiones', [], $violations);

section('page_links(): primera, última y las cercanas a la actual; huecos solo si faltan 2 o más');
same('una sola página', [1], page_links(1, 1));
same('5 páginas, en la 3: todas', [1, 2, 3, 4, 5], page_links(3, 5));
same('20 páginas, en la 1', [1, 2, 3, null, 20], page_links(1, 20));
same('20 páginas, en la 10', [1, null, 8, 9, 10, 11, 12, null, 20], page_links(10, 20));
same('20 páginas, en la última', [1, null, 18, 19, 20], page_links(20, 20));
same('8 páginas, en la 1: el hueco es de 4, se pinta ...', [1, 2, 3, null, 8], page_links(1, 8));
same('8 páginas, en la 5: un hueco de UNA página se escribe, no se tapa con ...', [1, 2, 3, 4, 5, 6, 7, 8], page_links(5, 8));
same('500 páginas, en la 250', [1, null, 248, 249, 250, 251, 252, null, 500], page_links(250, 500));
$linkViolations = [];
for ($pages = 1; $pages <= 40; $pages++) {
    for ($page = 1; $page <= $pages; $page++) {
        $links = page_links($page, $pages);
        $numbers = array_values(array_filter($links, static fn($n) => $n !== null));
        if (!in_array($page, $numbers, true) || $numbers[0] !== 1 || end($numbers) !== $pages || $numbers !== array_values(array_unique($numbers)) || $numbers !== (function (array $n): array { sort($n); return $n; })($numbers)) {
            $linkViolations[] = "pages=$pages page=$page";
        }
        if ($links[0] === null || end($links) === null) {
            $linkViolations[] = "hueco en un extremo pages=$pages page=$page";
        }
        foreach ($links as $i => $n) {
            if ($n === null && ($links[$i - 1] ?? null) !== null && ($links[$i + 1] ?? null) !== null && $links[$i + 1] - $links[$i - 1] < 3) {
                $linkViolations[] = "hueco que tapa menos de 2 páginas pages=$pages page=$page";
            }
        }
    }
}
same('propiedad: siempre están la actual, la primera y la última, en orden, sin repetir y sin huecos inútiles (1 a 40 páginas)', [], $linkViolations);

section('list_state() y list_url(): una sola URL por estado, bien codificada');
same('sin búsqueda y en la 1: no hay parámetros', [], list_state('', 1));
same('solo búsqueda', ['q' => 'laura'], list_state('laura', 1));
same('solo página', ['page' => 3], list_state('', 3));
same('búsqueda y página', ['q' => 'laura', 'page' => 3], list_state('laura', 3));
same('la página 1 se omite (así no hay dos URL para lo mismo)', 'index.php', list_url('', 1));
same('con búsqueda', 'index.php?q=laura', list_url('laura'));
same('con búsqueda y página', 'index.php?q=laura&page=2', list_url('laura', 2));
same('con un parámetro extra delante', 'index.php?msg=deleted', list_url('', 1, ['msg' => 'deleted']));
same('extra, búsqueda y página', 'index.php?msg=updated&q=laura&page=2', list_url('laura', 2, ['msg' => 'updated']));
same('los espacios se codifican (%20)', 'index.php?q=laura%20gomez', list_url('laura gomez'));
same('las tildes se codifican en UTF-8', 'index.php?q=G%C3%B3mez', list_url('Gómez'));
same('un & del texto no abre otro parámetro', 'index.php?q=a%26b%3Dc%23x', list_url('a&b=c#x'));
same('un salto de línea no puede colar otra cabecera', false, str_contains(list_url("a\r\nSet-Cookie: x=1"), "\n") || str_contains(list_url("a\r\nSet-Cookie: x=1"), "\r"));
same('el salto de línea queda como %0D%0A', true, str_contains(list_url("a\r\nSet-Cookie: x=1"), '%0D%0A'));
foreach (['laura', 'laura gomez', 'a&b=c#x', 'Gómez', '100%', "O'Brien", '<script>', '李雷', 'a+b'] as $term) {
    parse_str((string) parse_url(list_url($term, 4), PHP_URL_QUERY), $back);
    same("ida y vuelta de «{$term}»", ['q' => $term, 'page' => '4'], $back);
}

function validWith(array $extra): array
{
    $fields = user_fields();
    $data = read_user_input($extra + ['name' => 'Ana', 'last_name' => 'Perez', 'email' => 'a@b.co', 'role' => 'user'], $fields);

    return validate_user($data, $fields, new DateTimeImmutable('2026-11-19', new DateTimeZone('UTC')));
}

section('Mejora 3: un usuario con los campos nuevos vacíos sigue siendo válido (salvo el rol)');
same('document y birth_date son opcionales', [], validWith([]));
same('sin rol: error (un envío sin rol no se convierte en rol por sorpresa)', ['role' => 'Selecciona un rol de la lista.'], validate_user(read_user_input(['name' => 'Ana', 'last_name' => 'Perez', 'email' => 'a@b.co'], user_fields()), user_fields()));
same('un formulario NUEVO sí trae el rol por defecto', USER_ROLE_DEFAULT, empty_user_data(user_fields())['role']);
same('...y los demás campos nuevos vacíos', ['', ''], [empty_user_data(user_fields())['document'], empty_user_data(user_fields())['birth_date']]);

section('Rol: lista blanca estricta');
foreach (array_keys(user_roles()) as $role) {
    same("«{$role}» es válido", [], validWith(['role' => $role]));
}
foreach (['superadmin', 'ADMIN', 'Admin', '', '0', '1', 'user,admin', "ad\0min", 'usuario', 'null'] as $role) {
    same('rol ' . var_export($role, true) . ' se rechaza', ['role' => 'Selecciona un rol de la lista.'], validWith(['role' => $role]));
}
same('con espacios alrededor se acepta (trim, como todos los campos)', [], validWith(['role' => ' admin ']));
same('role[] (un arreglo) se rechaza', ['role' => 'Selecciona un rol de la lista.'], validate_user(read_user_input(['name' => 'Ana', 'last_name' => 'P', 'email' => 'a@b.co', 'role' => ['admin']], user_fields()), user_fields()));
same('los roles son los del ENUM (lista fija y en orden)', ['user', 'editor', 'admin'], array_keys(user_roles()));
same('el rol por defecto es uno de ellos', true, array_key_exists(USER_ROLE_DEFAULT, user_roles()));

section('Documento: opcional, texto, mayúsculas, único en la BD');
same('vacío es válido', [], validWith(['document' => '']));
foreach (['12345', '1234567890', 'AB-123456', 'ab-123456', '00012345', 'X1234567Z', str_repeat('9', 20)] as $doc) {
    same("«{$doc}» es válido", [], validWith(['document' => $doc]));
}
foreach (['1234', 'ab', '-12345', '123 456', '123.456', '12_345', '12345;', "12345\n6", 'ÁÉ1234', '٣٣٣٣٣٣'] as $doc) {
    same('«' . addcslashes($doc, "\n") . '» se rechaza', true, isset(validWith(['document' => $doc])['document']));
}
same('21 caracteres: demasiado largo', 'El documento no debe superar los 20 caracteres.', validWith(['document' => str_repeat('9', 21)])['document']);
same('se guarda en mayúsculas', 'AB-123456', read_user_input(['document' => ' ab-123456 '], user_fields())['document']);
same('los ceros a la izquierda se conservan (es texto)', '00012345', read_user_input(['document' => '00012345'], user_fields())['document']);

section('Fecha de nacimiento: AAAA-MM-DD, real, ni futura ni anterior a 1900');
same('vacía es válida', [], validWith(['birth_date' => '']));
foreach (['1990-05-17', '2000-02-29', '1900-01-01', '2026-11-19', '2026-11-18'] as $d) {
    same("$d es válida", [], validWith(['birth_date' => $d]));
}
foreach (['2023-02-29' => 'no existe', '2026-02-30' => 'no existe', '1999-13-01' => 'no existe', '1999-00-10' => 'no existe', '1999-12-32' => 'no existe', '1900-02-29' => '1900 no fue bisiesto'] as $d => $why) {
    same("$d se rechaza ($why)", 'Ingresa una fecha válida con el formato AAAA-MM-DD.', validWith(['birth_date' => $d])['birth_date'] ?? null);
}
foreach (['17/05/1990', '1990-5-7', '90-05-17', '1990/05/17', '19900517', 'ayer', '1990-05-17 10:00', '1990-05-17T10:00', '+1990-05-17', '1990-05-17x', '１９９０-05-17', '0000-00-00'] as $d) {
    same("«{$d}» se rechaza por formato", 'Ingresa una fecha válida con el formato AAAA-MM-DD.', validWith(['birth_date' => $d])['birth_date'] ?? null);
}
same('mañana es futura', 'La fecha de nacimiento no puede ser futura.', validWith(['birth_date' => '2026-11-20'])['birth_date'] ?? null);
same('el año 9999 es futuro', 'La fecha de nacimiento no puede ser futura.', validWith(['birth_date' => '9999-12-31'])['birth_date'] ?? null);
same('1899-12-31 es anterior a 1900', 'La fecha de nacimiento no puede ser anterior al 1 de enero de 1900.', validWith(['birth_date' => '1899-12-31'])['birth_date'] ?? null);
same('0001-01-01 es anterior a 1900', 'La fecha de nacimiento no puede ser anterior al 1 de enero de 1900.', validWith(['birth_date' => '0001-01-01'])['birth_date'] ?? null);
same('sin pasar "hoy", se usa la fecha real (la de ayer es válida y la de mañana no)', [[], true], (function (): array {
    $fields = user_fields();
    $utc = new DateTimeZone('UTC');
    $check = static fn(string $when): array => validate_user(read_user_input(['name' => 'Ana', 'last_name' => 'P', 'email' => 'a@b.co', 'role' => 'user', 'birth_date' => (new DateTimeImmutable($when, $utc))->format('Y-m-d')], $fields), $fields);

    return [$check('yesterday'), isset($check('tomorrow')['birth_date'])];
})());

section('user_db_params(): lo opcional vacío es NULL, nunca ""');
$params = user_db_params(read_user_input(['name' => 'Ana', 'last_name' => 'P', 'email' => 'A@B.co', 'role' => 'editor'], user_fields()));
same('phone, document y birth_date vacíos van como NULL', [null, null, null], [$params[':phone'], $params[':document'], $params[':birth_date']]);
same('el rol y el correo normalizado pasan', ['editor', 'a@b.co'], [$params[':role'], $params[':email']]);
same('los marcadores son los del INSERT/UPDATE', [':name', ':last_name', ':email', ':phone', ':document', ':birth_date', ':role'], array_keys($params));
$full = user_db_params(read_user_input(['name' => 'Ana', 'last_name' => 'P', 'email' => 'a@b.co', 'role' => 'admin', 'document' => 'ab12345', 'birth_date' => '1990-05-17', 'phone' => '3001234567'], user_fields()));
same('con valores, van tal cual (documento en mayúsculas)', ['3001234567', 'AB12345', '1990-05-17'], [$full[':phone'], $full[':document'], $full[':birth_date']]);

section('duplicate_user_field(): qué UNIQUE chocó, según el mensaje de MySQL');
same('correo', 'email', duplicate_user_field("Duplicate entry 'a@b.co' for key 'users.unique_email'"));
same('documento', 'document', duplicate_user_field("Duplicate entry 'AB12345' for key 'users.unique_document'"));
same('sin el prefijo de tabla (MySQL 5.7 / MariaDB)', 'document', duplicate_user_field("Duplicate entry 'x' for key 'unique_document'"));
same('una clave desconocida', null, duplicate_user_field("Duplicate entry '3' for key 'users.PRIMARY'"));
same('un mensaje que no es de duplicado', null, duplicate_user_field('Connection refused'));

finish();
