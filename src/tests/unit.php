<?php

declare(strict_types=1);

/**
 * Pruebas unitarias de la lógica pura (validador, búsqueda, paginación, entidad User, autenticación, sesión, origen):
 * sin base de datos ni servidor web.
 *
 * Ejecutar (desde la raíz del proyecto):
 *     docker compose exec php php tests/unit.php
 *
 * Sale con código 0 si todo pasa y con 1 si algo falla. Sin dependencias (no hace falta PHPUnit): `same()` y
 * `section()` de harness.php; si algún día se instala PHPUnit, estos mismos casos se trasladan casi tal cual.
 * Esta carpeta está fuera de public/: no se puede abrir desde el navegador.
 */


require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/harness.php';

use App\Auth\AdminAuth;
use App\Domain\User;
use App\Exceptions\DuplicateUserException;
use App\Http\Pagination;
use App\Repository\UserRepository;
use App\Search\UserSearch;
use App\Validation\UserValidator;

/** El validador de siempre (sin reloj fijo). */
function v(): UserValidator
{
    static $validator = null;

    return $validator ??= new UserValidator();
}

/** La huella de una fila (la calcula la entidad User; aquí se le dan solo los campos que cuentan). */
function tokenOf(array $row): string
{
    return User::fromRow($row + ['id' => 1, 'active' => 1, 'created_at' => ''])->rowToken();
}

function nameError(string $name): ?string
{
    $fields = v()->fields();
    $data = v()->read(['name' => $name, 'last_name' => 'Perez', 'email' => 'a@b.co']);

    return v()->validate($data)['name'] ?? null;
}

function emailError(string $email): ?string
{
    $fields = v()->fields();
    $data = v()->read(['name' => 'Ana', 'last_name' => 'Perez', 'email' => $email]);

    return v()->validate($data)['email'] ?? null;
}

function phoneError(string $phone): ?string
{
    $fields = v()->fields();
    $data = v()->read(['name' => 'Ana', 'last_name' => 'Perez', 'email' => 'a@b.co', 'phone' => $phone]);

    return v()->validate($data)['phone'] ?? null;
}

section('User::parseId(): solo dígitos, entre 1 y 4294967295');
foreach (['14' => 14, '1' => 1, '4294967295' => 4294967295] as $raw => $id) {
    same("'$raw' es válido", $id, User::parseId((string) $raw));
}
$invalidIds = ['0', '-3', '+14', ' 14', '14 ', '014', 'abc', '', '1e3', '0x1A', '14.0', '4294967296', '99999999999999999999', '٣'];
foreach ($invalidIds as $raw) {
    same(var_export($raw, true) . ' no es válido', null, User::parseId($raw));
}
same('null (no llegó)', null, User::parseId(null));
same('un arreglo (?id[]=1)', null, User::parseId(['1']));
same('un arreglo vacío', null, User::parseId([]));
same('un int real no es texto de la petición', null, User::parseId(14));
same('un bool', null, User::parseId(true));

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

section('UserValidator::fields(): definición de los campos del formulario');
same('el teléfono usa type="tel" (teclado numérico en el móvil)', 'tel', v()->fields()['phone']['type']);
same('el correo usa type="email"', 'email', v()->fields()['email']['type']);
same('los máximos coinciden con las columnas', [100, 100, 254, 20, 20, 10, 0], array_column(v()->fields(), 'max'));

section('User::rowToken(): huella de la fila para detectar ediciones simultáneas');
$row = ['name' => 'Ana', 'last_name' => 'Ruiz', 'email' => 'a@b.co', 'phone' => null, 'document' => null, 'birth_date' => null, 'role' => 'user'];
$token = tokenOf($row);
same('es un sha256 en hexadecimal', 1, preg_match('/^[0-9a-f]{64}$/', $token));
same('es determinista', $token, tokenOf($row));
same('no depende del orden de las claves', $token, tokenOf(['role' => 'user', 'birth_date' => null, 'document' => null, 'phone' => null, 'email' => 'a@b.co', 'last_name' => 'Ruiz', 'name' => 'Ana']));
foreach (['name' => 'Anita', 'last_name' => 'Ruiz2', 'email' => 'x@b.co', 'phone' => '3001234567', 'document' => 'AB123', 'birth_date' => '1990-01-01', 'role' => 'admin'] as $column => $other) {
    same("cambia si cambia $column", true, $token !== tokenOf([$column => $other] + $row));
}
same('cambia con solo una mayúscula (la comparación SQL no lo vería)', true, $token !== tokenOf(['name' => 'ana'] + $row));
same('phone NULL y phone vacío son distintos', true, $token !== tokenOf(['phone' => ''] + $row));
same('document NULL y document vacío son distintos', true, $token !== tokenOf(['document' => ''] + $row));

section('UserValidator::read(): limpia la entrada cruda');
$fields = v()->fields();
$data = v()->read(['name' => '  Ana  ', 'last_name' => 'Ruiz', 'email' => '  ANA@Ejemplo.COM ', 'phone' => ' 300 ', 'extra' => 'x']);
same('trim del nombre', 'Ana', $data['name']);
same('el correo se pasa a minúsculas', 'ana@ejemplo.com', $data['email']);
same('trim del teléfono', '300', $data['phone']);
same('solo quedan las claves definidas', ['name', 'last_name', 'email', 'phone', 'document', 'birth_date', 'role'], array_keys($data));
$data = v()->read(['name' => ['x'], 'last_name' => null, 'email' => 5]);
same('un arreglo (name[]=x) se trata como vacío', '', $data['name']);
same('null se trata como vacío', '', $data['last_name']);
same('un número se trata como vacío', '', $data['email']);
same('un campo que no llegó queda vacío', '', $data['phone']);

section('UserValidator::validate(): nombres y apellidos (permisivo: una letra y ningún carácter de control)');
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

section('UserValidator::validate(): correo');
foreach (['ñandu@correo.com', 'juan@gmail.co', 'JUAN@Gmail.COM'] as $email) {
    same("acepta $email", null, emailError($email));
}
foreach (['juan@gmail', 'a@b', 'juan@@gmail.com', 'juan @gmail.com', 'juan@gmail..com', '@gmail.com'] as $email) {
    same("rechaza $email", 'Ingresa un email válido.', emailError($email));
}
same('vacío: obligatorio', 'El email es obligatorio.', emailError(''));
same('255 caracteres: rechaza', 'El email no debe superar los 254 caracteres.', emailError(str_repeat('a', 246) . '@mail.com'));

section('UserValidator::validate(): teléfono (opcional; de 7 a 15 dígitos reales)');
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

section('UserSearch::parseTerm(): limpia lo que se escribe en el buscador');
same('null (no llegó)', '', UserSearch::parseTerm(null));
same('un arreglo (?q[]=x)', '', UserSearch::parseTerm(['x']));
same('un número', '', UserSearch::parseTerm(5));
same('texto normal', 'laura', UserSearch::parseTerm('laura'));
same('conserva mayúsculas y tildes (la collation es cosa de MySQL)', 'Gómez', UserSearch::parseTerm('Gómez'));
same('trim', 'laura', UserSearch::parseTerm('  laura  '));
same('espacios repetidos se juntan: "laura   gomez"', 'laura gomez', UserSearch::parseTerm('laura   gomez'));
same('saltos de línea y tabuladores se vuelven un espacio', 'laura gomez', UserSearch::parseTerm("laura\n\tgomez"));
same('un carácter NUL se vuelve un espacio', 'a b', UserSearch::parseTerm("a\0b"));
same('solo espacios: sin búsqueda', '', UserSearch::parseTerm('     '));
same('UTF-8 inválido: sin búsqueda', '', UserSearch::parseTerm("ab\xC3\x28"));
same('300 caracteres se recortan a 254', 254, mb_strlen(UserSearch::parseTerm(str_repeat('a', 300)), 'UTF-8'));
same('el recorte es por caracteres y no por bytes (ñ ocupa 2 bytes)', 254, mb_strlen(UserSearch::parseTerm(str_repeat('ñ', 300)), 'UTF-8'));
same('si el recorte deja un espacio al final, se quita', str_repeat('a', 253), UserSearch::parseTerm(str_repeat('a', 253) . ' bbb'));
same('UserSearch::TERM_MAX coincide con la columna más larga (el correo)', v()->fields()['email']['max'], UserSearch::TERM_MAX);

section('UserSearch::likePattern(): los comodines del texto se escapan, los de los extremos van en el valor');
same('texto normal', '%laura%', UserSearch::likePattern('laura'));
same('% se escapa', '%100\\%%', UserSearch::likePattern('100%'));
same('_ se escapa', '%Sub\\_Real%', UserSearch::likePattern('Sub_Real'));
same('la barra invertida se escapa', '%a\\\\b%', UserSearch::likePattern('a\\b'));
same('barra y porcentaje juntos (\\%): una sola pasada, sin escapar dos veces', '%\\\\\\%%', UserSearch::likePattern('\\%'));
same('vacío', '%%', UserSearch::likePattern(''));
same('los %, _ y \\ de cada extremo también se escapan', '%\\%x\\_\\\\%', UserSearch::likePattern('%x_\\'));

section('UserSearch::where() y UserSearch::countQuery(): el mismo filtro para listar y para contar');
same('sin búsqueda: solo el filtro de usuarios visibles', [' WHERE active = :active', [':active' => 1]], UserSearch::where(''));
same('sin búsqueda, vista de eliminados', [' WHERE active = :active', [':active' => 0]], UserSearch::where('', false));
same('con búsqueda: active va FUERA del paréntesis del OR (si no, un eliminado que case se colaría)', true, str_starts_with(UserSearch::where('x')[0], ' WHERE active = :active AND (') && str_ends_with(UserSearch::where('x')[0], ')'));
same('con búsqueda: :active es un entero (UserRepository lo enlaza con PARAM_INT)', true, is_int(UserSearch::where('x')[1][':active']));
same('recuento sin búsqueda', ['SELECT COUNT(*) FROM users WHERE active = :active', [':active' => 1]], UserSearch::countQuery(''));
same('recuento de eliminados', [':active' => 0], UserSearch::countQuery('', false)[1]);
[$countSql, $countParams] = UserSearch::countQuery('laura');
preg_match_all('/:q\d+/', $countSql, $countMarkers);
same('recuento con búsqueda: 6 marcadores distintos', [6, 6], [count($countMarkers[0]), count(array_unique($countMarkers[0]))]);
same('el recuento empieza por SELECT COUNT(*) y lleva el WHERE', true, str_starts_with($countSql, 'SELECT COUNT(*) FROM users WHERE active = :active AND ('));
same('el recuento no lleva ORDER BY ni LIMIT (no hacen falta para contar)', false, str_contains($countSql, 'ORDER BY') || str_contains($countSql, 'LIMIT'));

section('UserSearch::listQuery(): una página, marcadores distintos y nada del visitante dentro del SQL');
[$allSql, $allParams] = UserSearch::listQuery('', 10, 0);
same('sin búsqueda: active y los parámetros de la página', [':active' => 1, ':limit' => 10, ':offset' => 0], $allParams);
same('la vista de eliminados pide active = 0', 0, UserSearch::listQuery('', 10, 0, false)[1][':active']);
same('sin búsqueda: el único filtro es active', true, str_contains($allSql, ' WHERE active = :active ORDER BY'));
same('ordena por id descendente y pagina', true, str_ends_with($allSql, 'ORDER BY id DESC LIMIT :limit OFFSET :offset'));
same('LIMIT y OFFSET son enteros de PHP (UserRepository los enlaza con PARAM_INT)', [true, true], [is_int($allParams[':limit']), is_int($allParams[':offset'])]);
[$sql, $params] = UserSearch::listQuery('laura', 10, 20);
preg_match_all('/:q\d+/', $sql, $matches);
same('con búsqueda: 6 marcadores de columna', 6, count($matches[0]));
same('con búsqueda: NINGÚN marcador repetido (si no, HY093 con prepares reales)', count($matches[0]), count(array_unique($matches[0])));
same('los parámetros son exactamente esos marcadores más :limit y :offset', [':active', ...array_unique($matches[0]), ':limit', ':offset'], array_keys($params));
same('los 5 de la búsqueda reciben el mismo patrón', ['%laura%'], array_values(array_unique(array_slice($params, 1, 6))));
same('LIMIT y OFFSET llegan tal cual', [10, 20], [$params[':limit'], $params[':offset']]);
same('busca en el nombre completo ("Laura Gómez")', true, str_contains($sql, "CONCAT(name, ' ', last_name) LIKE"));
same('busca en el correo y en el teléfono', true, str_contains($sql, 'email LIKE') && str_contains($sql, 'phone LIKE'));
[$negSql, $negParams] = UserSearch::listQuery('', 0, -5);
same('LIMIT 0 se corrige a 1 (MySQL no admite negativos)', 1, $negParams[':limit']);
same('OFFSET negativo se corrige a 0', 0, $negParams[':offset']);
[$negSql2, $negParams2] = UserSearch::listQuery('', -3, 5);
same('LIMIT negativo se corrige a 1', 1, $negParams2[':limit']);
[$listSql] = UserSearch::listQuery('x', 10, 0);
[$countSqlX] = UserSearch::countQuery('x');
same('el listado y el recuento comparten exactamente el mismo WHERE', substr($countSqlX, strlen('SELECT COUNT(*) FROM users')), substr($listSql, strlen('SELECT id, name, last_name, email, phone, document, birth_date, role, created_at FROM users'), strpos($listSql, ' ORDER BY') - strlen('SELECT id, name, last_name, email, phone, document, birth_date, role, created_at FROM users')));
$hostile = "'; DROP TABLE users; --";
[$hostileSql, $hostileParams] = UserSearch::listQuery($hostile, 10, 0);
same('un texto hostil no aparece en el SQL', false, str_contains($hostileSql, 'DROP') || str_contains($hostileSql, '--'));
same('un texto hostil viaja solo como parámetro', true, str_contains($hostileParams[':q0'], 'DROP TABLE users'));
[$uniqueSql] = UserSearch::listQuery('zzzunico', 10, 0);
same('lo que se busca nunca está en el SQL', false, str_contains($uniqueSql, 'zzzunico'));

section('Pagination::parsePage(): lo que no es un entero >= 1 cuenta como la página 1');
foreach (['1' => 1, '2' => 2, '35' => 35, '02' => 2, '007' => 7, '999999999999999' => 999999999999999] as $raw => $page) {
    same("'$raw' es la página $page", $page, Pagination::parsePage((string) $raw));
}
foreach (['0', '00', '-3', '1.5', 'abc', '', ' 2', '2 ', '+2', '1e3', '٣'] as $raw) {
    same(var_export($raw, true) . ' es la página 1', 1, Pagination::parsePage($raw));
}
same('null (no llegó)', 1, Pagination::parsePage(null));
same('un arreglo (?page[]=2)', 1, Pagination::parsePage(['2']));
same('un int real no es texto de la petición', 1, Pagination::parsePage(5));
same('16 dígitos: no cabe con holgura, se trata como "más allá de la última"', PHP_INT_MAX, Pagination::parsePage('9999999999999999'));
same('23 dígitos: igual', PHP_INT_MAX, Pagination::parsePage('99999999999999999999999'));

section('Pagination::paginate(): total de páginas, corrección del rango y filas mostradas');
same('0 usuarios: una sola página, vacía (nunca cero páginas)', ['page' => 1, 'pages' => 1, 'perPage' => 10, 'offset' => 0, 'total' => 0, 'from' => 0, 'to' => 0], Pagination::paginate(0, 1));
same('1 usuario', [1, 1, 1], [Pagination::paginate(1, 1)['pages'], Pagination::paginate(1, 1)['from'], Pagination::paginate(1, 1)['to']]);
same('10 usuarios caben en una página', [1, 10], [Pagination::paginate(10, 1)['pages'], Pagination::paginate(10, 1)['to']]);
same('11 usuarios necesitan dos', 2, Pagination::paginate(11, 1)['pages']);
same('11 usuarios, página 2: solo la fila 11', [10, 11, 11], [Pagination::paginate(11, 2)['offset'], Pagination::paginate(11, 2)['from'], Pagination::paginate(11, 2)['to']]);
same('35 usuarios son 4 páginas', 4, Pagination::paginate(35, 1)['pages']);
same('35 usuarios, página 4: filas 31 a 35', [30, 31, 35], [Pagination::paginate(35, 4)['offset'], Pagination::paginate(35, 4)['from'], Pagination::paginate(35, 4)['to']]);
same('?page=999 con 35 usuarios muestra la 4, no una vacía', 4, Pagination::paginate(35, 999)['page']);
same('PHP_INT_MAX también se corrige a la última', 4, Pagination::paginate(35, PHP_INT_MAX)['page']);
same('la página 0 se corrige a la 1', 1, Pagination::paginate(35, 0)['page']);
same('un total negativo se trata como 0', 0, Pagination::paginate(-5, 1)['total']);
same('con otro tamaño de página (5): 11 usuarios son 3 páginas', 3, Pagination::paginate(11, 1, 5)['pages']);
// Propiedad: para cualquier total y cualquier página pedida, las páginas cubren TODAS las filas, sin huecos ni repeticiones.
$violations = [];
for ($total = 0; $total <= 120; $total++) {
    $covered = 0;
    $pages = Pagination::paginate($total, 1)['pages'];
    for ($requested = 1; $requested <= $pages; $requested++) {
        $info = Pagination::paginate($total, $requested);
        $rows = $info['to'] - $info['from'] + 1;
        $covered += $total === 0 ? 0 : $rows;
        if ($info['offset'] < 0 || $info['page'] !== $requested || ($total > 0 && ($info['from'] !== $info['offset'] + 1 || $info['to'] > $total || $rows < 1 || $rows > 10))) {
            $violations[] = "total=$total página=$requested";
        }
    }
    if ($covered !== $total) {
        $violations[] = "total=$total cubierto=$covered";
    }
    if (Pagination::paginate($total, $pages + 50)['page'] !== $pages) {
        $violations[] = "total=$total no corrige una página fuera de rango";
    }
}
same('propiedad: para cualquier total de 0 a 120, las páginas cubren todas las filas sin huecos ni repeticiones', [], $violations);

section('Pagination::pageLinks(): primera, última y las cercanas a la actual; huecos solo si faltan 2 o más');
same('una sola página', [1], Pagination::pageLinks(1, 1));
same('5 páginas, en la 3: todas', [1, 2, 3, 4, 5], Pagination::pageLinks(3, 5));
same('20 páginas, en la 1', [1, 2, 3, null, 20], Pagination::pageLinks(1, 20));
same('20 páginas, en la 10', [1, null, 8, 9, 10, 11, 12, null, 20], Pagination::pageLinks(10, 20));
same('20 páginas, en la última', [1, null, 18, 19, 20], Pagination::pageLinks(20, 20));
same('8 páginas, en la 1: el hueco es de 4, se pinta ...', [1, 2, 3, null, 8], Pagination::pageLinks(1, 8));
same('8 páginas, en la 5: un hueco de UNA página se escribe, no se tapa con ...', [1, 2, 3, 4, 5, 6, 7, 8], Pagination::pageLinks(5, 8));
same('500 páginas, en la 250', [1, null, 248, 249, 250, 251, 252, null, 500], Pagination::pageLinks(250, 500));
$linkViolations = [];
for ($pages = 1; $pages <= 40; $pages++) {
    for ($page = 1; $page <= $pages; $page++) {
        $links = Pagination::pageLinks($page, $pages);
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

section('Pagination::listState() y Pagination::listUrl(): una sola URL por estado, bien codificada');
same('sin búsqueda y en la 1: no hay parámetros', [], Pagination::listState('', 1));
same('solo búsqueda', ['q' => 'laura'], Pagination::listState('laura', 1));
same('solo página', ['page' => 3], Pagination::listState('', 3));
same('búsqueda y página', ['q' => 'laura', 'page' => 3], Pagination::listState('laura', 3));
same('la página 1 se omite (así no hay dos URL para lo mismo)', 'index.php', Pagination::listUrl('', 1));
same('con búsqueda', 'index.php?q=laura', Pagination::listUrl('laura'));
same('con búsqueda y página', 'index.php?q=laura&page=2', Pagination::listUrl('laura', 2));
same('con un parámetro extra delante', 'index.php?msg=deleted', Pagination::listUrl('', 1, ['msg' => 'deleted']));
same('extra, búsqueda y página', 'index.php?msg=updated&q=laura&page=2', Pagination::listUrl('laura', 2, ['msg' => 'updated']));
same('los espacios se codifican (%20)', 'index.php?q=laura%20gomez', Pagination::listUrl('laura gomez'));
same('las tildes se codifican en UTF-8', 'index.php?q=G%C3%B3mez', Pagination::listUrl('Gómez'));
same('un & del texto no abre otro parámetro', 'index.php?q=a%26b%3Dc%23x', Pagination::listUrl('a&b=c#x'));
same('un salto de línea no puede colar otra cabecera', false, str_contains(Pagination::listUrl("a\r\nSet-Cookie: x=1"), "\n") || str_contains(Pagination::listUrl("a\r\nSet-Cookie: x=1"), "\r"));
same('el salto de línea queda como %0D%0A', true, str_contains(Pagination::listUrl("a\r\nSet-Cookie: x=1"), '%0D%0A'));
foreach (['laura', 'laura gomez', 'a&b=c#x', 'Gómez', '100%', "O'Brien", '<script>', '李雷', 'a+b'] as $term) {
    parse_str((string) parse_url(Pagination::listUrl($term, 4), PHP_URL_QUERY), $back);
    same("ida y vuelta de «{$term}»", ['q' => $term, 'page' => '4'], $back);
}

function validWith(array $extra): array
{
    $fields = v()->fields();
    $data = v()->read($extra + ['name' => 'Ana', 'last_name' => 'Perez', 'email' => 'a@b.co', 'role' => 'user']);

    return (new UserValidator(new DateTimeImmutable('2026-11-19', new DateTimeZone('UTC'))))->validate($data);
}

section('Mejora 3: un usuario con los campos nuevos vacíos sigue siendo válido (salvo el rol)');
same('document y birth_date son opcionales', [], validWith([]));
same('sin rol: error (un envío sin rol no se convierte en rol por sorpresa)', ['role' => 'Selecciona un rol de la lista.'], v()->validate(v()->read(['name' => 'Ana', 'last_name' => 'Perez', 'email' => 'a@b.co'])));
same('un formulario NUEVO sí trae el rol por defecto', User::ROLE_DEFAULT, v()->emptyData()['role']);
same('...y los demás campos nuevos vacíos', ['', ''], [v()->emptyData()['document'], v()->emptyData()['birth_date']]);

section('Rol: lista blanca estricta');
foreach (array_keys(User::roles()) as $role) {
    same("«{$role}» es válido", [], validWith(['role' => $role]));
}
foreach (['superadmin', 'ADMIN', 'Admin', '', '0', '1', 'user,admin', "ad\0min", 'usuario', 'null'] as $role) {
    same('rol ' . var_export($role, true) . ' se rechaza', ['role' => 'Selecciona un rol de la lista.'], validWith(['role' => $role]));
}
same('con espacios alrededor se acepta (trim, como todos los campos)', [], validWith(['role' => ' admin ']));
same('role[] (un arreglo) se rechaza', ['role' => 'Selecciona un rol de la lista.'], v()->validate(v()->read(['name' => 'Ana', 'last_name' => 'P', 'email' => 'a@b.co', 'role' => ['admin']])));
same('los roles son los del ENUM (lista fija y en orden)', ['user', 'editor', 'admin'], array_keys(User::roles()));
same('el rol por defecto es uno de ellos', true, array_key_exists(User::ROLE_DEFAULT, User::roles()));

section('Documento: opcional, texto, mayúsculas, único en la BD');
same('vacío es válido', [], validWith(['document' => '']));
foreach (['12345', '1234567890', 'AB-123456', 'ab-123456', '00012345', 'X1234567Z', str_repeat('9', 20)] as $doc) {
    same("«{$doc}» es válido", [], validWith(['document' => $doc]));
}
foreach (['1234', 'ab', '-12345', '123 456', '123.456', '12_345', '12345;', "12345\n6", 'ÁÉ1234', '٣٣٣٣٣٣'] as $doc) {
    same('«' . addcslashes($doc, "\n") . '» se rechaza', true, isset(validWith(['document' => $doc])['document']));
}
same('21 caracteres: demasiado largo', 'El documento no debe superar los 20 caracteres.', validWith(['document' => str_repeat('9', 21)])['document']);
same('se guarda en mayúsculas', 'AB-123456', v()->read(['document' => ' ab-123456 '])['document']);
same('los ceros a la izquierda se conservan (es texto)', '00012345', v()->read(['document' => '00012345'])['document']);

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
    $fields = v()->fields();
    $utc = new DateTimeZone('UTC');
    $check = static fn(string $when): array => v()->validate(v()->read(['name' => 'Ana', 'last_name' => 'P', 'email' => 'a@b.co', 'role' => 'user', 'birth_date' => (new DateTimeImmutable($when, $utc))->format('Y-m-d')]));

    return [$check('yesterday'), isset($check('tomorrow')['birth_date'])];
})());

section('UserRepository::params(): lo opcional vacío es NULL, nunca ""');
$params = UserRepository::params(v()->read(['name' => 'Ana', 'last_name' => 'P', 'email' => 'A@B.co', 'role' => 'editor']));
same('phone, document y birth_date vacíos van como NULL', [null, null, null], [$params[':phone'], $params[':document'], $params[':birth_date']]);
same('el rol y el correo normalizado pasan', ['editor', 'a@b.co'], [$params[':role'], $params[':email']]);
same('los marcadores son los del INSERT/UPDATE', [':name', ':last_name', ':email', ':phone', ':document', ':birth_date', ':role'], array_keys($params));
$full = UserRepository::params(v()->read(['name' => 'Ana', 'last_name' => 'P', 'email' => 'a@b.co', 'role' => 'admin', 'document' => 'ab12345', 'birth_date' => '1990-05-17', 'phone' => '3001234567']));
same('con valores, van tal cual (documento en mayúsculas)', ['3001234567', 'AB12345', '1990-05-17'], [$full[':phone'], $full[':document'], $full[':birth_date']]);

section('UserRepository::duplicateField(): qué UNIQUE chocó, según el mensaje de MySQL');
same('correo', 'email', UserRepository::duplicateField("Duplicate entry 'a@b.co' for key 'users.unique_email'"));
same('documento', 'document', UserRepository::duplicateField("Duplicate entry 'AB12345' for key 'users.unique_document'"));
same('sin el prefijo de tabla (MySQL 5.7 / MariaDB)', 'document', UserRepository::duplicateField("Duplicate entry 'x' for key 'unique_document'"));
same('una clave desconocida', null, UserRepository::duplicateField("Duplicate entry '3' for key 'users.PRIMARY'"));
same('un mensaje que no es de duplicado', null, UserRepository::duplicateField('Connection refused'));

section('Mejora 4: Pagination::parseView() y la vista en la URL del listado');
same('«inactive» pide los eliminados', Pagination::VIEW_INACTIVE, Pagination::parseView('inactive'));
foreach (['active', '', 'INACTIVE', 'Inactive', ' inactive', 'inactive ', '1', 'deleted', 'all'] as $raw) {
    same(var_export($raw, true) . ' es la vista normal', Pagination::VIEW_ACTIVE, Pagination::parseView($raw));
}
same('null (no llegó)', Pagination::VIEW_ACTIVE, Pagination::parseView(null));
same('un arreglo (?view[]=inactive)', Pagination::VIEW_ACTIVE, Pagination::parseView(['inactive']));
same('un bool', Pagination::VIEW_ACTIVE, Pagination::parseView(true));
same('la vista normal se omite del estado', [], Pagination::listState('', 1, Pagination::VIEW_ACTIVE));
same('la vista de eliminados va en el estado', ['view' => 'inactive'], Pagination::listState('', 1, Pagination::VIEW_INACTIVE));
same('con búsqueda y página', ['view' => 'inactive', 'q' => 'laura', 'page' => 3], Pagination::listState('laura', 3, Pagination::VIEW_INACTIVE));
same('list_url de la vista normal no cambia', 'index.php?q=laura&page=2', Pagination::listUrl('laura', 2));
same('list_url de eliminados', 'index.php?view=inactive', Pagination::listUrl('', 1, [], Pagination::VIEW_INACTIVE));
same('list_url de eliminados con aviso, búsqueda y página', 'index.php?msg=restored&view=inactive&q=laura&page=2', Pagination::listUrl('laura', 2, ['msg' => 'restored'], Pagination::VIEW_INACTIVE));

section('Mejora 4: avisos de duplicado cuando el dueño está eliminado');
same('correo, alta', 'Ya existe un usuario registrado con ese correo.', (new DuplicateUserException('email', false))->userMessage(false));
same('documento, edición', 'Ya existe otro usuario registrado con ese documento.', (new DuplicateUserException('document', false))->userMessage(true));
same('correo de un eliminado: manda a restaurar', 'Ese correo pertenece a un usuario eliminado. Restáuralo desde «Eliminados» o usa otro correo.', (new DuplicateUserException('email', true))->userMessage(false));
same('documento de un eliminado', 'Ese documento pertenece a un usuario eliminado. Restáuralo desde «Eliminados» o usa otro documento.', (new DuplicateUserException('document', true))->userMessage(true));

section('Mejora 5: AdminAuth::normalizeEmail() y AdminAuth::validateNew()');
same('recorta y pasa a minúsculas', 'ana@ejemplo.com', AdminAuth::normalizeEmail("  ANA@Ejemplo.COM \n"));
same('un correo y una contraseña válidos', [], AdminAuth::validateNew('ana@ejemplo.com', 'una-clave-larga'));
same('correo vacío', ['email' => 'El correo es obligatorio.'], AdminAuth::validateNew('', 'una-clave-larga'));
same('correo sin arroba', ['email' => 'Ingresa un correo válido.'], AdminAuth::validateNew('ana.ejemplo.com', 'una-clave-larga'));
same('correo de más de 254 caracteres', ['email' => 'El correo no debe superar los 254 caracteres.'], AdminAuth::validateNew(str_repeat('a', 250) . '@b.co', 'una-clave-larga'));
same('contraseña de 9 caracteres: corta', ['password' => 'La contraseña debe tener al menos 10 caracteres.'], AdminAuth::validateNew('a@b.co', '123456789'));
same('contraseña de 10 caracteres: válida', [], AdminAuth::validateNew('a@b.co', '1234567890'));
same('los espacios cuentan como caracteres (no se recorta)', [], AdminAuth::validateNew('a@b.co', '          '));
same('contraseña de exactamente 72 bytes: válida', [], AdminAuth::validateNew('a@b.co', str_repeat('x', 72)));
same('contraseña de 73 bytes: se rechaza (bcrypt la truncaría)', ['password' => 'La contraseña no puede superar los 72 bytes (límite de bcrypt).'], AdminAuth::validateNew('a@b.co', str_repeat('x', 73)));
same('36 letras "ñ" son 36 caracteres pero 72 bytes: válida', [], AdminAuth::validateNew('a@b.co', str_repeat('ñ', 36)));
same('37 letras "ñ" son 74 bytes: se rechaza', true, isset(AdminAuth::validateNew('a@b.co', str_repeat('ñ', 37))['password']));
same('con un byte NUL', true, isset(AdminAuth::validateNew('a@b.co', "clave-larga\0x")['password']));
same('UTF-8 inválido', true, isset(AdminAuth::validateNew('a@b.co', "clave-larga-\xff\xfe")['password']));
same('el error de contraseña no repite la contraseña', false, str_contains(implode('|', AdminAuth::validateNew('a@b.co', 'corta')), 'corta'));

section('Mejora 5: AdminAuth::DUMMY_PASSWORD_HASH (igualar el tiempo con y sin correo)');
$info = password_get_info(AdminAuth::DUMMY_PASSWORD_HASH);
same('es un hash bcrypt válido', ['bcrypt', 60], [$info['algoName'], strlen(AdminAuth::DUMMY_PASSWORD_HASH)]);
same('tiene el MISMO coste que PASSWORD_DEFAULT hoy (si PHP lo sube, esta prueba avisa)', password_get_info(password_hash('x', PASSWORD_DEFAULT))['options']['cost'], $info['options']['cost']);
same('no necesita rehash (si no, el dummy sería más barato que uno real)', false, password_needs_rehash(AdminAuth::DUMMY_PASSWORD_HASH, PASSWORD_DEFAULT));
same('no coincide con contraseñas comunes', [false, false, false], [password_verify('', AdminAuth::DUMMY_PASSWORD_HASH), password_verify('password', AdminAuth::DUMMY_PASSWORD_HASH), password_verify('admin', AdminAuth::DUMMY_PASSWORD_HASH)]);
same('el mensaje de login fallido es uno solo y no menciona el correo', ['Credenciales inválidas.', false], [AdminAuth::LOGIN_FAILED_MESSAGE, stripos(AdminAuth::LOGIN_FAILED_MESSAGE, 'correo') !== false]);

section('Mejora 5: opciones de la cookie de sesión');
$o = session_cookie_options(false);
same('httponly (JavaScript no la lee)', true, $o['httponly']);
same('samesite Lax', 'Lax', $o['samesite']);
same('cookie de sesión: sin lifetime (desaparece al cerrar el navegador)', 0, $o['lifetime']);
same('path / y sin domain (solo este host)', ['/', ''], [$o['path'], $o['domain']]);
same('sin HTTPS, secure apagado (el navegador no guardaría una cookie secure en http)', false, $o['secure']);
same('con HTTPS, secure encendido', true, session_cookie_options(true)['secure']);
same('el nombre no es el PHPSESSID por defecto', true, SESSION_NAME !== 'PHPSESSID');

section('Mejora 5: session_is_expired()');
$now = 1_700_000_000;
same('activa hace un instante', false, session_is_expired($now - 1, $now));
same('1 segundo antes del límite', false, session_is_expired($now - (SESSION_IDLE_TIMEOUT - 1), $now));
same('justo en el límite caduca', true, session_is_expired($now - SESSION_IDLE_TIMEOUT, $now));
same('pasado el límite', true, session_is_expired($now - SESSION_IDLE_TIMEOUT - 1, $now));
same('una marca del futuro no es de fiar', true, session_is_expired($now + 5, $now));
same('sin marca (null)', true, session_is_expired(null, $now));
same('una marca que es texto no vale (solo enteros)', true, session_is_expired((string) ($now - 1), $now));
same('un decimal no vale', true, session_is_expired((float) ($now - 1), $now));
same('con otro tiempo límite (60 s)', [false, true], [session_is_expired($now - 59, $now, 60), session_is_expired($now - 60, $now, 60)]);
same('el límite son 30 minutos', 1800, SESSION_IDLE_TIMEOUT);

section('Mejora 6: csrf_is_valid() rechaza todo lo que no sea un texto (antes de tocar la sesión)');
foreach ([null, '', [], ['a' => 'b'], 0, 123, true, false, 1.5, new stdClass()] as $weird) {
    same('un ' . get_debug_type($weird) . ' (' . var_export($weird instanceof stdClass ? 'obj' : $weird, true) . ') no es un token', false, csrf_is_valid($weird));
}
same('un texto cualquiera sin sesión ni cookie tampoco (no hay con qué compararlo)', false, csrf_is_valid(str_repeat('a', 64)));
same('el nombre del campo', 'csrf_token', CSRF_FIELD);
same('el token son 32 bytes al azar: 64 caracteres hexadecimales', [32, 64], [CSRF_TOKEN_BYTES, CSRF_TOKEN_BYTES * 2]);
same('hash_equals no es === : compara en tiempo constante y exige texto en ambos lados', [true, false], [hash_equals('abc', 'abc'), hash_equals('abc', 'abd')]);

finish();
