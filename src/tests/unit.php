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

$passed = 0;
$failed = 0;

function section(string $title): void
{
    echo PHP_EOL . "== $title" . PHP_EOL;
}

function same(string $description, mixed $expected, mixed $actual): void
{
    global $passed, $failed;

    if ($expected === $actual) {
        $passed++;
        echo "  ok    $description" . PHP_EOL;
        return;
    }

    $failed++;
    echo "  FALLO $description" . PHP_EOL;
    echo '        esperado: ' . var_export($expected, true) . PHP_EOL;
    echo '        obtenido: ' . var_export($actual, true) . PHP_EOL;
}

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
same('los máximos coinciden con las columnas', [100, 100, 254, 20], array_column(user_fields(), 'max'));

section('user_row_token(): huella de la fila para detectar ediciones simultáneas');
$row = ['name' => 'Ana', 'last_name' => 'Ruiz', 'email' => 'a@b.co', 'phone' => null];
$token = user_row_token($row);
same('es un sha256 en hexadecimal', 1, preg_match('/^[0-9a-f]{64}$/', $token));
same('es determinista', $token, user_row_token($row));
same('no depende del orden de las claves', $token, user_row_token(['phone' => null, 'email' => 'a@b.co', 'last_name' => 'Ruiz', 'name' => 'Ana']));
foreach (['name' => 'Anita', 'last_name' => 'Ruiz2', 'email' => 'x@b.co', 'phone' => '3001234567'] as $column => $other) {
    same("cambia si cambia $column", true, $token !== user_row_token([$column => $other] + $row));
}
same('cambia con solo una mayúscula (la comparación SQL no lo vería)', true, $token !== user_row_token(['name' => 'ana'] + $row));
same('phone NULL y phone vacío son distintos', true, $token !== user_row_token(['phone' => ''] + $row));

section('read_user_input(): limpia la entrada cruda');
$fields = user_fields();
$data = read_user_input(['name' => '  Ana  ', 'last_name' => 'Ruiz', 'email' => '  ANA@Ejemplo.COM ', 'phone' => ' 300 ', 'extra' => 'x'], $fields);
same('trim del nombre', 'Ana', $data['name']);
same('el correo se pasa a minúsculas', 'ana@ejemplo.com', $data['email']);
same('trim del teléfono', '300', $data['phone']);
same('solo quedan las claves definidas', ['name', 'last_name', 'email', 'phone'], array_keys($data));
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

echo PHP_EOL . "Resultado: $passed correctas, $failed fallidas" . PHP_EOL;
exit($failed === 0 ? 0 : 1);
