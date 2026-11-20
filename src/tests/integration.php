<?php

declare(strict_types=1);

/**
 * Pruebas de integración: las consultas de búsqueda y paginación contra MySQL de verdad.
 *
 *     bin/as-migrator php tests/integration.php
 *
 * Se ejecuta con el usuario de MIGRACIONES de la BD (no con el de la aplicación): necesita CREATE TEMPORARY TABLES
 * y la aplicación, por mínimo privilegio, no lo tiene (ver database/03-users.sh y bin/as-migrator).
 *
 * No tocan tus datos: crean una tabla `users` TEMPORAL en esta misma conexión, que oculta a la real
 * mientras dura la sesión, no consume su AUTO_INCREMENT y desaparece sola al desconectar. Por eso los
 * recuentos son exactos y no dependen de los usuarios que haya. Requiere el permiso CREATE TEMPORARY
 * TABLES.
 * Sale con 0 si todo pasa y con 1 si algo falla.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/harness.php';

use App\Auth\AdminAuth;
use App\Database\Connection;
use App\Domain\User;
use App\Exceptions\DuplicateUserException;
use App\Http\Pagination;
use App\Repository\AdminRepository;
use App\Repository\UserRepository;
use App\Search\UserSearch;
use App\Validation\UserValidator;

$pdo = Connection::create();
$repo = new UserRepository($pdo);
$admins = new AdminRepository($pdo);
$validator = new UserValidator();
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
 * Ejecuta una página del listado igual que lo hace el controlador (UserRepository) y devuelve los ids.
 *
 * @return array{ids: list<int>, total: int, pagination: array<string, int>}
 */
function fetchPage(UserRepository $repo, string $term, int $requestedPage, bool $active = true): array
{
    $count = $repo->count($term, $active);
    $pagination = Pagination::paginate($count, $requestedPage);
    $users = $repo->page($term, $active, $pagination['perPage'], $pagination['offset']);

    return ['ids' => array_map(static fn(User $user): int => $user->id, $users), 'total' => $count, 'pagination' => $pagination];
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
        $page = fetchPage($repo, 'itest-', 2);
    } catch (PDOException $error) {
        same("consultas $label: pedir la página 2 con el repositorio no debe dar error", 'sin error', $error->getMessage());
        continue;
    }
    same("consultas $label: la página 2 trae 10 filas (el repositorio enlaza LIMIT y OFFSET como enteros)", 10, count($page['ids']));
    same("consultas $label: el recuento es el esperado", $total, $page['total']);

    [$sql, $params] = UserSearch::listQuery('', 10, 0);
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
    $first = fetchPage($repo, $term, 1);
    $pages = $first['pagination']['pages'];
    $allIds = [];
    $sizes = [];
    for ($p = 1; $p <= $pages; $p++) {
        $result = fetchPage($repo, $term, $p);
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
$beyond = fetchPage($repo, 'itest-', 999);
same('?page=999 con 31 usuarios muestra la página 4', 4, $beyond['pagination']['page']);
same('...con las 1 filas que le quedan', 1, count($beyond['ids']));
$huge = fetchPage($repo, 'itest-', PHP_INT_MAX);
same('PHP_INT_MAX también acaba en la última página, sin desbordar el OFFSET', 4, $huge['pagination']['page']);

section('Los comodines de LIKE se buscan como texto');
$literal = fn(string $term): array => array_map(static fn(User $user): string => $user->name, $repo->page($term, true, 100, 0));
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
same('User::roles() tiene exactamente los valores del ENUM, en el mismo orden', $enumValues[1], array_keys(User::roles()));
$default = (string) $pdo->query("SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role'")->fetchColumn();
same('User::ROLE_DEFAULT es el DEFAULT de la columna', $default, User::ROLE_DEFAULT);
$lengths = $pdo->query("SELECT COLUMN_NAME, CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME IN ('document', 'phone')")->fetchAll(PDO::FETCH_KEY_PAIR);
same('los máximos de $validator->fields() coinciden con las columnas (document, phone)', [(int) $lengths['document'], (int) $lengths['phone']], [$validator->fields()['document']['max'], $validator->fields()['phone']['max']]);

section('Mejora 3: la búsqueda también encuentra por documento');
same('«zx-9988» (minúsculas, a medias) encuentra a Zoé', ['Zoé'], $literal('zx-9988'));
same('un documento que no existe no encuentra a nadie', [], $literal('QQ-000000'));

section('Mejora 4: el borrado lógico oculta de verdad (listado, recuento, búsqueda y páginas)');
$idOf = static fn(string $email): int => (int) $pdo->query("SELECT id FROM users WHERE email = '$email'")->fetchColumn();
$gone = [$idOf('itest-01@example.com'), $idOf('itest-02@example.com'), $idOf('itest-zoe@example.com'), $idOf('itest-pct@example.com')];
$pdo->exec('UPDATE users SET active = 0 WHERE id IN (' . implode(',', $gone) . ')');
$visible = fetchPage($repo, '', 1);
same('el recuento de visibles baja en 4', $total - 4, $visible['total']);
same('ningún eliminado aparece en las páginas de visibles', [], (function () use ($repo, $gone): array {
    $seen = [];
    for ($p = 1; $p <= 4; $p++) {
        $seen = array_merge($seen, fetchPage($repo, '', $p)['ids']);
    }

    return array_values(array_intersect($seen, $gone));
})());
same('la búsqueda tampoco encuentra a un eliminado («zx-9988» era el documento de Zoé)', [], $literal('zx-9988'));
same('...ni por nombre', [], $literal('ZOE nunez'));
same('«%» deja de encontrar a Pct%Real eliminado', [], $literal('%'));
$hidden = fetchPage($repo, '', 1, false);
same('la vista de eliminados cuenta exactamente los 4', 4, $hidden['total']);
same('...y trae exactamente esos ids (de mayor a menor)', [...(function () use ($gone): array { rsort($gone); return $gone; })()], $hidden['ids']);
same('buscar dentro de los eliminados: «itest-0» encuentra solo itest-01 e itest-02', 2, fetchPage($repo, 'itest-0', 1, false)['total']);
same('una búsqueda que solo casa con visibles no encuentra nada en eliminados', 0, fetchPage($repo, 'itest-03', 1, false)['total']);
same('visibles + eliminados = todos', $total, fetchPage($repo, '', 1)['total'] + fetchPage($repo, '', 1, false)['total']);

section('Mejora 4: restaurar y los UNIQUE (cubren también a los eliminados)');
$gonePerson = $pdo->query("SELECT email, document FROM users WHERE email = 'itest-zoe@example.com'")->fetch();
$newData = static fn(string $email, string $document = ''): array => ['name' => 'Otro', 'last_name' => 'Zoe', 'email' => $email, 'phone' => '', 'document' => $document, 'birth_date' => '', 'role' => 'user'];
$duplicateOf = static function (array $data) use ($repo): ?DuplicateUserException {
    try {
        $repo->create($data);
    } catch (DuplicateUserException $error) {
        return $error;
    }

    return null;
};
$dup = $duplicateOf($newData('itest-zoe@example.com'));
same('crear otro usuario con el correo de un eliminado SIGUE chocando con el UNIQUE (decisión de M4)...', ['email', true], [$dup?->field, $dup?->ownerInactive]);
same('...y el aviso explica que está eliminado', 'Ese correo pertenece a un usuario eliminado. Restáuralo desde «Eliminados» o usa otro correo.', $dup?->userMessage(false));
$dup = $duplicateOf($newData('ITEST-ZOE@example.com'));
same('...también con otra capitalización (collation)', ['email', true], [$dup?->field, $dup?->ownerInactive]);
$dup = $duplicateOf($newData('otro-itest@example.com', 'ZX-998877'));
same('...y por documento', ['document', true], [$dup?->field, $dup?->ownerInactive]);
$dup = $duplicateOf($newData('itest-03@example.com'));
same('el dueño de un correo ACTIVO no se marca como eliminado', ['email', false], [$dup?->field, $dup?->ownerInactive]);
same('...y su aviso es el normal', 'Ya existe un usuario registrado con ese correo.', $dup?->userMessage(false));
same('...(el de edición dice «otro usuario»)', 'Ya existe otro usuario registrado con ese correo.', $dup?->userMessage(true));
same('en ningún caso se creó el usuario repetido', 0, (int) $pdo->query("SELECT COUNT(*) FROM users WHERE email IN ('otro-itest@example.com') OR (name = 'Otro' AND last_name = 'Zoe')")->fetchColumn());
$pdo->exec('UPDATE users SET active = 1 WHERE id IN (' . implode(',', $gone) . ')');
same('restaurar (active = 1) no choca con ningún UNIQUE', $total, fetchPage($repo, '', 1)['total']);
same('...y la vista de eliminados queda vacía', 0, fetchPage($repo, '', 1, false)['total']);
same('...y Zoé vuelve a encontrarse', ['Zoé'], $literal('zx-9988'));

section('Mejora 4: el índice (active, id) existe');
// Con 31 filas el optimizador prefiere recorrer la clave primaria, así que EXPLAIN no demuestra nada aquí:
// que el índice se USA con muchas filas se midió aparte con 200.000 (ver plan.md, M4). Esto solo vigila que exista.
same('idx_active_id es un índice de (active, id)', ['active', 'id'], $pdo->query("SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_active_id' ORDER BY SEQ_IN_INDEX")->fetchAll(PDO::FETCH_COLUMN));

section('Mejora 5: AdminAuth::authenticate() (un administrador es un usuario con rol admin y contraseña)');
$secret = 'una-clave-de-prueba-123';
$pdo->prepare("INSERT INTO users (name, last_name, email, role, password_hash) VALUES ('Raiz', 'Admin', :e, 'admin', :h)")->execute([':e' => 'root@example.com', ':h' => password_hash($secret, PASSWORD_DEFAULT)]);
$adminId = (int) $pdo->query("SELECT id FROM users WHERE email = 'root@example.com'")->fetchColumn();
same('correo y contraseña correctos', ['id' => $adminId, 'email' => 'root@example.com'], AdminAuth::authenticate($admins, 'root@example.com', $secret));
same('el correo no distingue mayúsculas ni espacios alrededor', ['id' => $adminId, 'email' => 'root@example.com'], AdminAuth::authenticate($admins, '  ROOT@Example.COM ', $secret));
same('contraseña incorrecta', null, AdminAuth::authenticate($admins, 'root@example.com', 'otra-clave-distinta'));
same('la contraseña distingue mayúsculas', null, AdminAuth::authenticate($admins, 'root@example.com', strtoupper($secret)));
same('un espacio de más en la contraseña no se perdona (no se recorta)', null, AdminAuth::authenticate($admins, 'root@example.com', $secret . ' '));
same('contraseña vacía', null, AdminAuth::authenticate($admins, 'root@example.com', ''));
same('correo que no existe', null, AdminAuth::authenticate($admins, 'nadie@example.com', $secret));
same('correo vacío', null, AdminAuth::authenticate($admins, '', $secret));
same('la clave correcta con un NUL al final no entra', null, AdminAuth::authenticate($admins, 'root@example.com', $secret . "\0"));
same('una contraseña de 2000 bytes se rechaza sin hashearla ni dar error', null, AdminAuth::authenticate($admins, 'root@example.com', str_repeat('a', 2000)));
same('la clave correcta seguida de basura DESPUÉS de un NUL no entra (bcrypt lee hasta el NUL)', null, AdminAuth::authenticate($admins, 'root@example.com', $secret . "\0cualquier-cosa"));
same('la clave correcta con 72 bytes de sobra tampoco (bcrypt solo lee 72)', null, AdminAuth::authenticate($admins, 'root@example.com', str_pad($secret, 80, 'x')));
same('...y una contraseña de 72 bytes que SÍ es la guardada sí entra', ['id' => (int) $pdo->query("SELECT id FROM users WHERE email = 'root@example.com'")->fetchColumn(), 'email' => 'root@example.com'], (function () use ($pdo, $admins): ?array {
    $long = str_repeat('k', 72);
    $pdo->prepare('UPDATE users SET password_hash = :h WHERE email = :e')->execute([':h' => password_hash($long, PASSWORD_DEFAULT), ':e' => 'root@example.com']);
    $result = AdminAuth::authenticate($admins, 'root@example.com', $long);
    $pdo->prepare('UPDATE users SET password_hash = :h WHERE email = :e')->execute([':h' => password_hash('una-clave-de-prueba-123', PASSWORD_DEFAULT), ':e' => 'root@example.com']);

    return $result;
})());
foreach (["' OR '1'='1", "root@example.com' -- ", "x' OR 1=1 -- ", '%', 'root@example.com%'] as $injection) {
    same('«' . $injection . '» como correo no entra', null, AdminAuth::authenticate($admins, $injection, $secret));
}
same('el hash guardado no es la contraseña ni la contiene', [true, false], [str_starts_with((string) $pdo->query('SELECT password_hash FROM users WHERE id = ' . $adminId)->fetchColumn(), '$2y$'), str_contains((string) $pdo->query('SELECT password_hash FROM users WHERE id = ' . $adminId)->fetchColumn(), $secret)]);

$cheap = password_hash($secret, PASSWORD_BCRYPT, ['cost' => 4]);
$pdo->prepare('UPDATE users SET password_hash = :h WHERE id = :id')->execute([':h' => $cheap, ':id' => $adminId]);
same('una contraseña correcta con un hash de coste 4 entra...', ['id' => $adminId, 'email' => 'root@example.com'], AdminAuth::authenticate($admins, 'root@example.com', $secret));
$after = (string) $pdo->query('SELECT password_hash FROM users WHERE id = ' . $adminId)->fetchColumn();
same('...y el hash se recalcula con el coste actual (password_needs_rehash)', [false, true], [password_needs_rehash($after, PASSWORD_DEFAULT), $after !== $cheap]);
same('...y la contraseña sigue valiendo con el hash nuevo', true, password_verify($secret, $after));
$pdo->prepare('UPDATE users SET password_hash = :h WHERE id = :id')->execute([':h' => $cheap, ':id' => $adminId]);
AdminAuth::authenticate($admins, 'root@example.com', 'contraseña-incorrecta');
same('una contraseña INCORRECTA no recalcula nada', $cheap, (string) $pdo->query('SELECT password_hash FROM users WHERE id = ' . $adminId)->fetchColumn());
$duplicate = null;
try {
    $pdo->prepare("INSERT INTO users (name, last_name, email, role, password_hash) VALUES ('Otro', 'Raiz', :e, 'admin', :h)")->execute([':e' => 'ROOT@example.com', ':h' => $cheap]);
} catch (PDOException $error) {
    $duplicate = $error->errorInfo[1] ?? null;
}
same('el correo del administrador es único (también con otra capitalización)', 1062, $duplicate);

section('Mejora 8: UserRepository (la única capa con SQL de usuarios)');
$idByEmail = static fn(string $email): int => (int) $pdo->query("SELECT id FROM users WHERE email = '$email'")->fetchColumn();
$id3 = $idByEmail('itest-03@example.com');
$u3 = $repo->findActive($id3);
same('findActive() devuelve una entidad User con tipos', [true, 'Usuario', 'Prueba03', 'itest-03@example.com', null, true, 'user'], [$u3 instanceof User, $u3?->name, $u3?->lastName, $u3?->email, $u3?->phone, $u3?->active, $u3?->role]);
same('...y sus métodos', ['Usuario Prueba03', 'Usuario', '', ''], [$u3?->fullName(), $u3?->formData()['name'], $u3?->formData()['phone'], $u3?->formData()['document']]);
same('un id que no existe no es un usuario', null, $repo->findActive(4294967295));
same('deactivate() lo elimina (true)', true, $repo->deactivate($id3));
same('...y una segunda vez no hace nada (false): ya estaba eliminado', false, $repo->deactivate($id3));
same('un eliminado no se encuentra con findActive() (no se edita)', null, $repo->findActive($id3));
same('la fila SIGUE en la tabla con active = 0 (borrado lógico)', '0', (string) $pdo->query("SELECT active FROM users WHERE id = $id3")->fetchColumn());
same('update() sobre un eliminado no cambia nada (false)', false, $repo->update($id3, $validator->read(['name' => 'Cambiado', 'last_name' => 'X', 'email' => 'itest-03@example.com', 'role' => 'user'])));
same('restore() lo devuelve (true)', true, $repo->restore($id3));
same('...y una segunda vez no hace nada (false): ya estaba activo', false, $repo->restore($id3));
same('restore() de un id que no existe', false, $repo->restore(4294967295));
same('deactivate() de un id que no existe', false, $repo->deactivate(4294967295));

$tokenBefore = $repo->findActive($id3)?->rowToken();
same('update() con los MISMOS valores devuelve true (la fila existe aunque no cambie) y la huella no cambia', [true, $tokenBefore], [
    $repo->update($id3, $validator->read(['name' => 'Usuario', 'last_name' => 'Prueba03', 'email' => 'itest-03@example.com', 'role' => 'user'])),
    $repo->findActive($id3)?->rowToken(),
]);
same('update() con otros valores los guarda y cambia la huella', [true, 'Cambiado', 'AB-99999', '1991-02-03', 'editor', true], (function () use ($repo, $validator, $id3, $tokenBefore): array {
    $ok = $repo->update($id3, $validator->read(['name' => 'Cambiado', 'last_name' => 'Prueba03', 'email' => 'itest-03@example.com', 'document' => 'ab-99999', 'birth_date' => '1991-02-03', 'role' => 'editor']));
    $after = $repo->findActive($id3);

    return [$ok, $after?->name, $after?->document, $after?->birthDate, $after?->role, $after?->rowToken() !== $tokenBefore];
})());
same('update() de un id que no existe', false, $repo->update(4294967295, $validator->read(['name' => 'A', 'last_name' => 'B', 'email' => 'zz@example.com', 'role' => 'user'])));
$err = null;
try {
    $repo->update($id3, $validator->read(['name' => 'Cambiado', 'last_name' => 'Prueba03', 'email' => 'itest-04@example.com', 'role' => 'editor']));
} catch (DuplicateUserException $e) {
    $err = $e;
}
same('update() con el correo de OTRO usuario lanza DuplicateUserException(email, dueño activo)', ['email', false, 'Ya existe otro usuario registrado con ese correo.'], [$err?->field, $err?->ownerInactive, $err?->userMessage(true)]);
same('...y el usuario no cambió', 'itest-03@example.com', $repo->findActive($id3)?->email);

$repo->create($validator->read(['name' => 'Nuevo', 'last_name' => 'Repo', 'email' => 'ITEST-NEW@Example.com', 'role' => 'admin']));
$new = $repo->findActive($idByEmail('itest-new@example.com'));
same('create() guarda lo opcional vacío como NULL (no como "")', [null, null, null, 'admin', 'itest-new@example.com'], [$new?->phone, $new?->document, $new?->birthDate, $new?->role, $new?->email]);
same('...y en la BD son NULL de verdad', [1, 1, 1], array_map('intval', $pdo->query("SELECT phone IS NULL, document IS NULL, birth_date IS NULL FROM users WHERE email = 'itest-new@example.com'")->fetch(PDO::FETCH_NUM)));
$repo->create($validator->read(['name' => 'Nuevo', 'last_name' => 'Repo2', 'email' => 'itest-new2@example.com', 'role' => 'user']));
same('varios usuarios sin documento conviven (todos NULL en un UNIQUE)', 2, (int) $pdo->query("SELECT COUNT(*) FROM users WHERE email IN ('itest-new@example.com', 'itest-new2@example.com') AND document IS NULL")->fetchColumn());
same('create() con un correo ya usado lanza DuplicateUserException', true, $duplicateOf($newData('itest-new@example.com')) instanceof DuplicateUserException);
same('page() trae entidades User, de mayor a menor id', true, (function () use ($repo): bool {
    $page = $repo->page('', true, 10, 0);
    $ids = array_map(static fn(User $u): int => $u->id, $page);
    $sorted = $ids;
    rsort($sorted);

    return $page !== [] && $ids === $sorted && !in_array(false, array_map(static fn($u): bool => $u instanceof User && $u->active, $page), true);
})());
same('page() de la vista de eliminados marca active = false', [false], array_values(array_unique(array_map(static fn(User $u): bool => $u->active, (function () use ($repo, $pdo, $id3): array {
    $repo->deactivate($id3);
    $page = $repo->page('', false, 10, 0);
    $repo->restore($id3);

    return $page;
})()))));

section('Acceso: solo entra quien es administrador, está activo y tiene contraseña');
$rootId = $adminId;
$pdo->prepare('UPDATE users SET password_hash = :h WHERE id = :id')->execute([':h' => password_hash($secret, PASSWORD_DEFAULT), ':id' => $rootId]);
$entry = static fn(): ?array => AdminAuth::authenticate($admins, 'root@example.com', $secret);
$ok = ['id' => $rootId, 'email' => 'root@example.com'];
same('un administrador activo con contraseña entra', $ok, $entry());
$pdo->exec("UPDATE users SET role = 'editor' WHERE id = $rootId");
same('con el rol «editor» NO entra, aunque la contraseña sea correcta', null, $entry());
$pdo->exec("UPDATE users SET role = 'user' WHERE id = $rootId");
same('con el rol «user» tampoco', null, $entry());
$pdo->exec("UPDATE users SET role = 'admin', active = 0 WHERE id = $rootId");
same('un administrador ELIMINADO (borrado lógico) NO entra', null, $entry());
$pdo->exec("UPDATE users SET active = 1 WHERE id = $rootId");
same('...y al restaurarlo vuelve a entrar con la misma contraseña', $ok, $entry());
$pdo->exec("UPDATE users SET password_hash = NULL WHERE id = $rootId");
same('un administrador SIN contraseña no entra (con cualquier clave, también vacía)', [null, null, null], [$entry(), AdminAuth::authenticate($admins, 'root@example.com', ''), AdminAuth::authenticate($admins, 'root@example.com', 'x')]);
$pdo->prepare('UPDATE users SET password_hash = :h WHERE id = :id')->execute([':h' => password_hash($secret, PASSWORD_DEFAULT), ':id' => $rootId]);
same('findByEmail() solo devuelve lo que sirve para entrar (nada más que id, correo y hash)', ['id', 'email', 'password_hash'], array_keys($admins->findByEmail('root@example.com') ?? []));
same('...y no encuentra a un usuario normal (itest-04, sin contraseña)', null, $admins->findByEmail('itest-04@example.com'));
same('grantAccess() promueve a un usuario existente (false = no creó uno nuevo)', [false, 'admin', true], (function () use ($admins, $pdo): array {
    $result = $admins->grantAccess('itest-04@example.com', AdminAuth::hash('clave-de-itest-04'));
    $row = $pdo->query("SELECT role, active, password_hash IS NOT NULL AS has FROM users WHERE email = 'itest-04@example.com'")->fetch();

    return [$result, $row['role'], (int) $row['active'] === 1 && (int) $row['has'] === 1];
})());
same('...y esa persona entra', true, AdminAuth::authenticate($admins, 'itest-04@example.com', 'clave-de-itest-04') !== null);
same('grantAccess() con un correo nuevo crea el usuario «Administrador» (true)', [true, 'Administrador', 'nuevo-itest', 'admin'], (function () use ($admins, $pdo): array {
    $result = $admins->grantAccess('nuevo-itest@example.com', AdminAuth::hash('clave-de-nuevo-itest'));
    $row = $pdo->query("SELECT name, last_name, role FROM users WHERE email = 'nuevo-itest@example.com'")->fetch();

    return [$result, $row['name'], $row['last_name'], $row['role']];
})());

section('UserRepository: crear y editar con contraseña (el hash, nunca la contraseña)');
$hashOf = static fn(string $email): ?string => ($v = $pdo->query("SELECT password_hash FROM users WHERE email = '$email'")->fetchColumn()) === false || $v === null ? null : (string) $v;
$adminData = static fn(string $email, string $password, string $role = 'admin'): array => $validator->read(['name' => 'Pass', 'last_name' => 'Itest', 'email' => $email, 'role' => $role, 'password' => $password, 'password_confirm' => $password]);
$repo->create($adminData('itest-pass1@example.com', 'una-clave-larga-1'));
$h1 = $hashOf('itest-pass1@example.com');
same('create() con rol admin y contraseña guarda un bcrypt que verifica', [true, true, false], [$h1 !== null && str_starts_with($h1, '$2y$'), $h1 !== null && password_verify('una-clave-larga-1', $h1), $h1 !== null && str_contains($h1, 'una-clave-larga-1')]);
same('...y esa persona entra', true, AdminAuth::authenticate($admins, 'itest-pass1@example.com', 'una-clave-larga-1') !== null);
same('...y su ficha dice que tiene acceso', true, $repo->findActive($idByEmail('itest-pass1@example.com'))?->hasAccess);
$repo->create($adminData('itest-pass2@example.com', 'una-clave-larga-2', 'editor'));
same('create() con rol editor IGNORA la contraseña (no se guarda nada)', [null, false], [$hashOf('itest-pass2@example.com'), $repo->findActive($idByEmail('itest-pass2@example.com'))?->hasAccess]);
$repo->create($adminData('itest-pass3@example.com', '', 'admin'));
same('create() de un admin sin contraseña (lo que ya había antes de esta función) lo deja sin acceso', [null, null], [$hashOf('itest-pass3@example.com'), AdminAuth::authenticate($admins, 'itest-pass3@example.com', '')]);
$id1 = $idByEmail('itest-pass1@example.com');
$repo->update($id1, $adminData('itest-pass1@example.com', ''));
same('update() de un admin con la contraseña VACÍA conserva el hash', $h1, $hashOf('itest-pass1@example.com'));
$repo->update($id1, $adminData('itest-pass1@example.com', 'otra-clave-larga-9'));
$h1b = $hashOf('itest-pass1@example.com');
same('update() con una contraseña nueva la reemplaza (la vieja deja de valer)', [true, false, true], [$h1b !== $h1, AdminAuth::authenticate($admins, 'itest-pass1@example.com', 'una-clave-larga-1') !== null, AdminAuth::authenticate($admins, 'itest-pass1@example.com', 'otra-clave-larga-9') !== null]);
$repo->update($id1, $adminData('itest-pass1@example.com', '', 'editor'));
same('update() que le quita el rol de administrador BORRA el hash (deja de poder entrar)', [null, null], [$hashOf('itest-pass1@example.com'), AdminAuth::authenticate($admins, 'itest-pass1@example.com', 'otra-clave-larga-9')]);
$repo->update($id1, $adminData('itest-pass1@example.com', 'tercera-clave-larga', 'admin'));
same('...y al volver a ascenderlo con contraseña entra otra vez', true, AdminAuth::authenticate($admins, 'itest-pass1@example.com', 'tercera-clave-larga') !== null);
same('el correo cambiado en la edición es el que vale para entrar (una sola fuente de verdad)', [null, true], (function () use ($repo, $admins, $adminData, $id1): array {
    $repo->update($id1, $adminData('itest-pass1-nuevo@example.com', ''));

    return [AdminAuth::authenticate($admins, 'itest-pass1@example.com', 'tercera-clave-larga'), AdminAuth::authenticate($admins, 'itest-pass1-nuevo@example.com', 'tercera-clave-larga') !== null];
})());
$repo->deactivate($id1);
same('eliminar (borrado lógico) a un administrador le quita el acceso...', null, AdminAuth::authenticate($admins, 'itest-pass1-nuevo@example.com', 'tercera-clave-larga'));
$repo->restore($id1);
same('...y restaurarlo se lo devuelve', true, AdminAuth::authenticate($admins, 'itest-pass1-nuevo@example.com', 'tercera-clave-larga') !== null);
same('el listado marca el acceso y NO trae nunca el hash', [true, false], (function () use ($repo): array {
    $found = array_values(array_filter($repo->page('itest-pass1', true, 10, 0), static fn(User $u): bool => $u->email === 'itest-pass1-nuevo@example.com'));
    $vars = $found === [] ? [] : array_keys(get_object_vars($found[0]));

    return [$found !== [] && $found[0]->hasAccess, in_array('passwordHash', $vars, true) || in_array('password_hash', $vars, true)];
})());
$pdo->exec("DELETE FROM users WHERE email LIKE 'itest-pass%' OR email IN ('root@example.com', 'ROOT@example.com', 'nuevo-itest@example.com')");
$pdo->exec("UPDATE users SET role = 'user', password_hash = NULL WHERE email = 'itest-04@example.com'");

section('Un texto hostil se trata como texto');
foreach (["' OR '1'='1", "'; DROP TABLE users; --", '" OR 1=1 --', '%\' OR \'x\' LIKE \'%'] as $hostile) {
    $thrown = false;
    $rowsFound = -1;
    try {
        $rowsFound = fetchPage($repo, $hostile, 1)['total'];
    } catch (PDOException) {
        $thrown = true;
    }
    same('«' . $hostile . '»: sin error y sin resultados', [false, 0], [$thrown, $rowsFound]);
}
same('la tabla sigue intacta tras los textos hostiles (los 31 iniciales y los 2 de las pruebas del repositorio)', $total + 2, (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());

$pdo->exec('DROP TEMPORARY TABLE users');
section('Al terminar, los datos reales están como estaban');
same('la tabla real tiene los mismos usuarios que antes de empezar', $realUsers, (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());

finish();
