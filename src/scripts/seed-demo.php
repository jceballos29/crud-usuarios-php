<?php

declare(strict_types=1);

/**
 * Inserta usuarios de demostración para probar el listado (paginación, búsqueda...).
 *
 *     docker compose exec php php scripts/seed-demo.php            # 35 usuarios
 *     docker compose exec php php scripts/seed-demo.php 120        # 120 usuarios
 *     docker compose exec php php scripts/seed-demo.php --clean    # borra los de demostración
 *
 * Todos llevan el correo demo-NNN@example.com, así que son fáciles de reconocer y de borrar, y es
 * idempotente: ejecutarlo dos veces no duplica (el correo es UNIQUE y se usa INSERT IGNORE). Los
 * demás usuarios no se tocan. Solo funciona desde la consola.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/connection.php';

$arg = $argv[1] ?? '35';

if ($arg === '--clean') {
    $deleted = $pdo->exec("DELETE FROM users WHERE email LIKE 'demo-%@example.com'");
    echo "Borrados $deleted usuarios de demostración." . PHP_EOL;
    exit(0);
}

if (!ctype_digit($arg) || (int) $arg < 1 || (int) $arg > 100000) {
    fwrite(STDERR, "Uso: php scripts/seed-demo.php [cantidad entre 1 y 100000 | --clean]" . PHP_EOL);
    exit(1);
}

$count = (int) $arg;
$names = ['Ana', 'Luis', 'María', 'Andrés', 'Valentina', 'Juan', 'Sofía', 'Camilo', 'Isabela', 'Nicolás', 'Daniela', 'Sebastián', 'Gabriela', 'Mateo'];
$lastNames = ['García', 'Rodríguez', 'Martínez', 'López', 'González', 'Pérez', 'Sánchez', 'Ramírez', 'Torres', 'Flórez', 'Núñez', 'Cárdenas', 'Muñoz', 'Rojas'];

$stmt = $pdo->prepare('INSERT IGNORE INTO users (name, last_name, email, phone) VALUES (:name, :last_name, :email, :phone)');
$inserted = 0;

$pdo->beginTransaction();

for ($i = 1; $i <= $count; $i++) {
    $stmt->execute([
        ':name' => $names[$i % count($names)],
        ':last_name' => $lastNames[intdiv($i, count($names)) % count($lastNames)],
        ':email' => sprintf('demo-%03d@example.com', $i),
        ':phone' => $i % 3 === 0 ? null : sprintf('300%07d', $i),
    ]);
    $inserted += $stmt->rowCount() > 0 ? 1 : 0;
}

$pdo->commit();

echo "Insertados $inserted usuarios de demostración ($count pedidos; el resto ya existían)." . PHP_EOL;
echo 'Total de usuarios ahora: ' . $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() . PHP_EOL;
