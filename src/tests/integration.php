<?php

declare(strict_types=1);

/**
 * Pruebas de integración: las consultas de búsqueda y paginación contra MySQL de verdad.
 *
 *     docker compose exec php php tests/integration.php
 *
 * No tocan tus datos: crean una tabla `users` TEMPORAL en esta misma conexión, que oculta a la real
 * mientras dura la sesión, no consume su AUTO_INCREMENT y desaparece sola al desconectar. Por eso los
 * recuentos son exactos y no dependen de los usuarios que haya. Requiere el permiso CREATE TEMPORARY
 * TABLES (el usuario `crud` lo tiene hoy; con el mínimo privilegio de M7 habría que dárselo).
 * Sale con 0 si todo pasa y con 1 si algo falla.
 */

require_once __DIR__ . '/../includes/connection.php';
require_once __DIR__ . '/../includes/user-validation.php';
require_once __DIR__ . '/../includes/user-conflict.php';
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/user-search.php';
require_once __DIR__ . '/../includes/pagination.php';
require_once __DIR__ . '/harness.php';

/** @var PDO $pdo */
$realUsers = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
// Se copia la estructura de la real (índice UNIQUE incluido, así no puede desfasarse) a una temporal con
// otro nombre, y se renombra a `users`: una tabla temporal con el mismo nombre oculta a la real en esta
// conexión. (`CREATE TEMPORARY TABLE users LIKE users` no vale: MySQL no sabe a cuál de las dos se refiere.)
$pdo->exec('CREATE TEMPORARY TABLE users_itest LIKE users');
$pdo->exec('ALTER TABLE users_itest RENAME TO users');

$insert = $pdo->prepare('INSERT INTO users (name, last_name, email, phone) VALUES (:n, :l, :e, :p)');
$rows = [];
for ($i = 1; $i <= 25; $i++) {
    $rows[] = ['Usuario', sprintf('Prueba%02d', $i), sprintf('itest-%02d@example.com', $i), $i % 2 === 0 ? sprintf('300%07d', $i) : null];
}
$rows[] = ['Pct%Real', 'Comodin', 'itest-pct@example.com', null];
$rows[] = ['PctXReal', 'Comodin', 'itest-pctx@example.com', null];
$rows[] = ['Sub_Real', 'Comodin', 'itest-sub@example.com', null];
$rows[] = ['SubXReal', 'Comodin', 'itest-subx@example.com', null];
$rows[] = ['Back\\Slash', 'Comodin', 'itest-bs@example.com', null];
$rows[] = ['Zoé', 'Núñez', 'itest-zoe@example.com', '3105551234'];
foreach ($rows as [$name, $last, $email, $phone]) {
    $insert->execute([':n' => $name, ':l' => $last, ':e' => $email, ':p' => $phone]);
}
$pdo->exec("UPDATE users SET document = 'ZX-998877' WHERE email = 'itest-zoe@example.com'");
$total = count($rows); // 31

/**
 * Ejecuta una página del listado igual que lo hace index.php y devuelve los ids.
 *
 * @return array{ids: list<int>, total: int, pagination: array<string, int>}
 */
function fetchPage(PDO $pdo, string $term, int $requestedPage, bool $active = true): array
{
    [$countSql, $countParams] = user_count_query($term, $active);
    $stmt = $pdo->prepare($countSql);
    bind_all($stmt, $countParams);
    $stmt->execute();
    $count = (int) $stmt->fetchColumn();

    $pagination = paginate($count, $requestedPage);
    [$sql, $params] = user_list_query($term, $pagination['perPage'], $pagination['offset'], $active);
    $stmt = $pdo->prepare($sql);
    bind_all($stmt, $params);
    $stmt->execute();

    return ['ids' => array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), 'total' => $count, 'pagination' => $pagination];
}

section('La tabla temporal aísla las pruebas de los datos reales');
same('la tabla temporal tiene solo las filas de prueba', $total, (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
same('la tabla real no tiene ninguna fila de prueba (se mira con otra conexión, que no ve la temporal)', 0, (function (): int {
    $other = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', getenv('DB_HOST'), getenv('DB_PORT'), getenv('DB_DATABASE')),
        (string) getenv('DB_USERNAME'),
        (string) getenv('DB_PASSWORD')
    );
    return (int) $other->query("SELECT COUNT(*) FROM users WHERE email LIKE 'itest-%'")->fetchColumn();
})());

section('LIMIT y OFFSET: la trampa del plan, en los dos modos de PDO');
foreach ([[false, 'nativas (EMULATE_PREPARES=false, la configuración del proyecto)'], [true, 'emuladas (EMULATE_PREPARES=true)']] as [$emulate, $label]) {
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, $emulate);
    try {
        $page = fetchPage($pdo, 'itest-', 2);
    } catch (PDOException $error) {
        same("consultas $label: pedir la página 2 con bind_all() no debe dar error", 'sin error', $error->getMessage());
        continue;
    }
    same("consultas $label: la página 2 trae 10 filas con bind_all()", 10, count($page['ids']));
    same("consultas $label: el recuento es el esperado", $total, $page['total']);

    [$sql, $params] = user_list_query('', 10, 0);
    $naive = $pdo->prepare($sql);
    $threw = false;
    try {
        $naive->execute($params);
    } catch (PDOException) {
        $threw = true;
    }
    same("consultas $label: pasar los números con execute() " . ($emulate ? 'FALLA (LIMIT \'10\' es un error de sintaxis)' : 'funciona (MySQL convierte el texto)'), $emulate, $threw);
}
$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

section('Paginar recorre todas las filas, sin huecos ni repeticiones, y en orden');
$terms = ['' => $total, 'itest-' => $total, 'prueba' => 25, 'ITEST-0' => 9, 'zzzzz-no-existe' => 0, 'a' => null, '%' => 1, '_' => 1, '\\' => 1];
foreach ($terms as $term => $expected) {
    $term = (string) $term;
    $first = fetchPage($pdo, $term, 1);
    $pages = $first['pagination']['pages'];
    $allIds = [];
    $sizes = [];
    for ($p = 1; $p <= $pages; $p++) {
        $result = fetchPage($pdo, $term, $p);
        $allIds = array_merge($allIds, $result['ids']);
        $sizes[] = count($result['ids']);
    }
    $sorted = $allIds;
    rsort($sorted);
    $label = $term === '' ? '(sin búsqueda)' : "«{$term}»";
    same("búsqueda $label: la suma de las páginas es el recuento", $first['total'], count($allIds));
    same("búsqueda $label: ningún id se repite entre páginas", count($allIds), count(array_unique($allIds)));
    same("búsqueda $label: los ids van de mayor a menor en todas las páginas", $sorted, $allIds);
    same("búsqueda $label: ninguna página tiene más de 10 filas", true, $sizes === [] || max($sizes) <= 10);
    if ($expected !== null) {
        same("búsqueda $label: encuentra exactamente $expected", $expected, $first['total']);
    }
}

section('Una página fuera de rango se corrige a la última y trae sus filas');
$beyond = fetchPage($pdo, 'itest-', 999);
same('?page=999 con 31 usuarios muestra la página 4', 4, $beyond['pagination']['page']);
same('...con las 1 filas que le quedan', 1, count($beyond['ids']));
$huge = fetchPage($pdo, 'itest-', PHP_INT_MAX);
same('PHP_INT_MAX también acaba en la última página, sin desbordar el OFFSET', 4, $huge['pagination']['page']);

section('Los comodines de LIKE se buscan como texto');
$literal = static function (string $term) use ($pdo): array {
    [$sql, $params] = user_list_query($term, 100, 0);
    $stmt = $pdo->prepare(str_replace('SELECT id,', 'SELECT name,', $sql));
    bind_all($stmt, $params);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_COLUMN);
};
same('«%» solo encuentra el que lleva un % de verdad', ['Pct%Real'], $literal('%'));
same('«_» solo encuentra el que lleva un guion bajo de verdad', ['Sub_Real'], $literal('_'));
same('«\\» solo encuentra el que lleva una barra invertida de verdad', ['Back\\Slash'], $literal('\\'));
same('«Pct%Real» no casa con PctXReal', ['Pct%Real'], $literal('Pct%Real'));
same('«Sub_Real» no casa con SubXReal', ['Sub_Real'], $literal('Sub_Real'));

section('Mayúsculas, tildes, nombre completo y teléfono (la collation es de MySQL)');
same('«ZOE nunez» encuentra a Zoé Núñez (nombre completo, sin mayúsculas ni tildes)', ['Zoé'], $literal('ZOE nunez'));
same('«nunez» por apellido', ['Zoé'], $literal('nunez'));
same('«zoé núñez» con tildes también', ['Zoé'], $literal('zoé núñez'));
same('un trozo del teléfono', ['Zoé'], $literal('555123'));
same('una parte del correo', ['Zoé'], $literal('itest-zoe'));

section('Mejora 3: la lista de roles de PHP y el ENUM de la BD son la misma');
$enum = (string) $pdo->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role'")->fetchColumn();
preg_match_all("/'([^']+)'/", $enum, $enumValues);
same('user_roles() tiene exactamente los valores del ENUM, en el mismo orden', $enumValues[1], array_keys(user_roles()));
$default = (string) $pdo->query("SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role'")->fetchColumn();
same('USER_ROLE_DEFAULT es el DEFAULT de la columna', $default, USER_ROLE_DEFAULT);
$lengths = $pdo->query("SELECT COLUMN_NAME, CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME IN ('document', 'phone')")->fetchAll(PDO::FETCH_KEY_PAIR);
same('los máximos de user_fields() coinciden con las columnas (document, phone)', [(int) $lengths['document'], (int) $lengths['phone']], [user_fields()['document']['max'], user_fields()['phone']['max']]);

section('Mejora 3: la búsqueda también encuentra por documento');
same('«zx-9988» (minúsculas, a medias) encuentra a Zoé', ['Zoé'], $literal('zx-9988'));
same('un documento que no existe no encuentra a nadie', [], $literal('QQ-000000'));

section('Mejora 4: el borrado lógico oculta de verdad (listado, recuento, búsqueda y páginas)');
$idOf = static fn(string $email): int => (int) $pdo->query("SELECT id FROM users WHERE email = '$email'")->fetchColumn();
$gone = [$idOf('itest-01@example.com'), $idOf('itest-02@example.com'), $idOf('itest-zoe@example.com'), $idOf('itest-pct@example.com')];
$pdo->exec('UPDATE users SET active = 0 WHERE id IN (' . implode(',', $gone) . ')');
$visible = fetchPage($pdo, '', 1);
same('el recuento de visibles baja en 4', $total - 4, $visible['total']);
same('ningún eliminado aparece en las páginas de visibles', [], (function () use ($pdo, $gone): array {
    $seen = [];
    for ($p = 1; $p <= 4; $p++) {
        $seen = array_merge($seen, fetchPage($pdo, '', $p)['ids']);
    }

    return array_values(array_intersect($seen, $gone));
})());
same('la búsqueda tampoco encuentra a un eliminado («zx-9988» era el documento de Zoé)', [], $literal('zx-9988'));
same('...ni por nombre', [], $literal('ZOE nunez'));
same('«%» deja de encontrar a Pct%Real eliminado', [], $literal('%'));
$hidden = fetchPage($pdo, '', 1, false);
same('la vista de eliminados cuenta exactamente los 4', 4, $hidden['total']);
same('...y trae exactamente esos ids (de mayor a menor)', [...(function () use ($gone): array { rsort($gone); return $gone; })()], $hidden['ids']);
same('buscar dentro de los eliminados: «itest-0» encuentra solo itest-01 e itest-02', 2, fetchPage($pdo, 'itest-0', 1, false)['total']);
same('una búsqueda que solo casa con visibles no encuentra nada en eliminados', 0, fetchPage($pdo, 'itest-03', 1, false)['total']);
same('visibles + eliminados = todos', $total, fetchPage($pdo, '', 1)['total'] + fetchPage($pdo, '', 1, false)['total']);

section('Mejora 4: restaurar y los UNIQUE (cubren también a los eliminados)');
$gonePerson = $pdo->query("SELECT email, document FROM users WHERE email = 'itest-zoe@example.com'")->fetch();
same('el dueño eliminado de un correo se detecta como eliminado', true, conflicting_user_is_inactive($pdo, 'email', 'itest-zoe@example.com'));
same('...también con otra capitalización (collation)', true, conflicting_user_is_inactive($pdo, 'email', 'ITEST-ZOE@example.com'));
same('...y por documento', true, conflicting_user_is_inactive($pdo, 'document', 'ZX-998877'));
same('el dueño de un correo ACTIVO no se marca como eliminado', false, conflicting_user_is_inactive($pdo, 'email', 'itest-03@example.com'));
same('un correo que no existe no es de un eliminado', false, conflicting_user_is_inactive($pdo, 'email', 'nadie@example.com'));
$duplicate = null;
try {
    $insert->execute([':n' => 'Otro', ':l' => 'Zoe', ':e' => 'itest-zoe@example.com', ':p' => null]);
} catch (PDOException $error) {
    $duplicate = duplicate_user_field((string) ($error->errorInfo[2] ?? ''));
}
same('crear otro usuario con el correo de un eliminado SIGUE chocando con el UNIQUE (decisión de M4)', 'email', $duplicate);
$pdo->exec('UPDATE users SET active = 1 WHERE id IN (' . implode(',', $gone) . ')');
same('restaurar (active = 1) no choca con ningún UNIQUE', $total, fetchPage($pdo, '', 1)['total']);
same('...y la vista de eliminados queda vacía', 0, fetchPage($pdo, '', 1, false)['total']);
same('...y Zoé vuelve a encontrarse', ['Zoé'], $literal('zx-9988'));

section('Mejora 4: el índice (active, id) existe');
// Con 31 filas el optimizador prefiere recorrer la clave primaria, así que EXPLAIN no demuestra nada aquí:
// que el índice se USA con muchas filas se midió aparte con 200.000 (ver plan.md, M4). Esto solo vigila que exista.
same('idx_active_id es un índice de (active, id)', ['active', 'id'], $pdo->query("SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_active_id' ORDER BY SEQ_IN_INDEX")->fetchAll(PDO::FETCH_COLUMN));

section('Mejora 5: authenticate_admin() contra la tabla admins');
$pdo->exec('CREATE TEMPORARY TABLE admins_itest LIKE admins');
$pdo->exec('ALTER TABLE admins_itest RENAME TO admins');
$secret = 'una-clave-de-prueba-123';
$pdo->prepare('INSERT INTO admins (email, password_hash) VALUES (:e, :h)')->execute([':e' => 'root@example.com', ':h' => password_hash($secret, PASSWORD_DEFAULT)]);
$adminId = (int) $pdo->query("SELECT id FROM admins WHERE email = 'root@example.com'")->fetchColumn();
same('correo y contraseña correctos', ['id' => $adminId, 'email' => 'root@example.com'], authenticate_admin($pdo, 'root@example.com', $secret));
same('el correo no distingue mayúsculas ni espacios alrededor', ['id' => $adminId, 'email' => 'root@example.com'], authenticate_admin($pdo, '  ROOT@Example.COM ', $secret));
same('contraseña incorrecta', null, authenticate_admin($pdo, 'root@example.com', 'otra-clave-distinta'));
same('la contraseña distingue mayúsculas', null, authenticate_admin($pdo, 'root@example.com', strtoupper($secret)));
same('un espacio de más en la contraseña no se perdona (no se recorta)', null, authenticate_admin($pdo, 'root@example.com', $secret . ' '));
same('contraseña vacía', null, authenticate_admin($pdo, 'root@example.com', ''));
same('correo que no existe', null, authenticate_admin($pdo, 'nadie@example.com', $secret));
same('correo vacío', null, authenticate_admin($pdo, '', $secret));
same('la clave correcta con un NUL al final no entra', null, authenticate_admin($pdo, 'root@example.com', $secret . "\0"));
same('una contraseña de 2000 bytes se rechaza sin hashearla ni dar error', null, authenticate_admin($pdo, 'root@example.com', str_repeat('a', 2000)));
same('la clave correcta seguida de basura DESPUÉS de un NUL no entra (bcrypt lee hasta el NUL)', null, authenticate_admin($pdo, 'root@example.com', $secret . "\0cualquier-cosa"));
same('la clave correcta con 72 bytes de sobra tampoco (bcrypt solo lee 72)', null, authenticate_admin($pdo, 'root@example.com', str_pad($secret, 80, 'x')));
same('...y una contraseña de 72 bytes que SÍ es la guardada sí entra', ['id' => (int) $pdo->query("SELECT id FROM admins WHERE email = 'root@example.com'")->fetchColumn(), 'email' => 'root@example.com'], (function () use ($pdo): ?array {
    $long = str_repeat('k', 72);
    $pdo->prepare('UPDATE admins SET password_hash = :h WHERE email = :e')->execute([':h' => password_hash($long, PASSWORD_DEFAULT), ':e' => 'root@example.com']);
    $result = authenticate_admin($pdo, 'root@example.com', $long);
    $pdo->prepare('UPDATE admins SET password_hash = :h WHERE email = :e')->execute([':h' => password_hash('una-clave-de-prueba-123', PASSWORD_DEFAULT), ':e' => 'root@example.com']);

    return $result;
})());
foreach (["' OR '1'='1", "root@example.com' -- ", "x' OR 1=1 -- ", '%', 'root@example.com%'] as $injection) {
    same('«' . $injection . '» como correo no entra', null, authenticate_admin($pdo, $injection, $secret));
}
same('el hash guardado no es la contraseña ni la contiene', [true, false], [str_starts_with((string) $pdo->query('SELECT password_hash FROM admins WHERE id = ' . $adminId)->fetchColumn(), '$2y$'), str_contains((string) $pdo->query('SELECT password_hash FROM admins WHERE id = ' . $adminId)->fetchColumn(), $secret)]);

$cheap = password_hash($secret, PASSWORD_BCRYPT, ['cost' => 4]);
$pdo->prepare('UPDATE admins SET password_hash = :h WHERE id = :id')->execute([':h' => $cheap, ':id' => $adminId]);
same('una contraseña correcta con un hash de coste 4 entra...', ['id' => $adminId, 'email' => 'root@example.com'], authenticate_admin($pdo, 'root@example.com', $secret));
$after = (string) $pdo->query('SELECT password_hash FROM admins WHERE id = ' . $adminId)->fetchColumn();
same('...y el hash se recalcula con el coste actual (password_needs_rehash)', [false, true], [password_needs_rehash($after, PASSWORD_DEFAULT), $after !== $cheap]);
same('...y la contraseña sigue valiendo con el hash nuevo', true, password_verify($secret, $after));
$pdo->prepare('UPDATE admins SET password_hash = :h WHERE id = :id')->execute([':h' => $cheap, ':id' => $adminId]);
authenticate_admin($pdo, 'root@example.com', 'contraseña-incorrecta');
same('una contraseña INCORRECTA no recalcula nada', $cheap, (string) $pdo->query('SELECT password_hash FROM admins WHERE id = ' . $adminId)->fetchColumn());
$duplicate = null;
try {
    $pdo->prepare('INSERT INTO admins (email, password_hash) VALUES (:e, :h)')->execute([':e' => 'ROOT@example.com', ':h' => $cheap]);
} catch (PDOException $error) {
    $duplicate = $error->errorInfo[1] ?? null;
}
same('el correo del administrador es único (también con otra capitalización)', 1062, $duplicate);
$pdo->exec('DROP TEMPORARY TABLE admins');

section('Un texto hostil se trata como texto');
foreach (["' OR '1'='1", "'; DROP TABLE users; --", '" OR 1=1 --', '%\' OR \'x\' LIKE \'%'] as $hostile) {
    $thrown = false;
    $rowsFound = -1;
    try {
        $rowsFound = fetchPage($pdo, $hostile, 1)['total'];
    } catch (PDOException) {
        $thrown = true;
    }
    same('«' . $hostile . '»: sin error y sin resultados', [false, 0], [$thrown, $rowsFound]);
}
same('la tabla sigue intacta tras los textos hostiles', $total, (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());

$pdo->exec('DROP TEMPORARY TABLE users');
section('Al terminar, los datos reales están como estaban');
same('la tabla real tiene los mismos usuarios que antes de empezar', $realUsers, (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());

finish();
