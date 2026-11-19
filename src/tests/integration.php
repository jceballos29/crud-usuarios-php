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
$total = count($rows); // 31

/**
 * Ejecuta una página del listado igual que lo hace index.php y devuelve los ids.
 *
 * @return array{ids: list<int>, total: int, pagination: array<string, int>}
 */
function fetchPage(PDO $pdo, string $term, int $requestedPage): array
{
    [$countSql, $countParams] = user_count_query($term);
    $stmt = $pdo->prepare($countSql);
    bind_all($stmt, $countParams);
    $stmt->execute();
    $count = (int) $stmt->fetchColumn();

    $pagination = paginate($count, $requestedPage);
    [$sql, $params] = user_list_query($term, $pagination['perPage'], $pagination['offset']);
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
