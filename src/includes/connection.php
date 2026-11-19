<?php

declare(strict_types=1);

require_once __DIR__ . '/DatabaseConnectionException.php';
require_once __DIR__ . '/helpers.php';

$dsn = sprintf(
    "mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4",
    environment('DB_HOST'),
    environment('DB_PORT'),
    environment('DB_DATABASE')
);

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    // rowCount() de un UPDATE cuenta las filas que coinciden con el WHERE, no solo las que cambian
    // de valor. Sin esto, guardar sin modificar nada daría 0, igual que un id que no existe.
    // Pdo\Mysql existe desde PHP 8.4; PDO::MYSQL_ATTR_FOUND_ROWS está obsoleta desde PHP 8.5.
    Pdo\Mysql::ATTR_FOUND_ROWS => true,
    // Máximo de segundos para conectar. Sin esto, una BD que no responde (colgada, no parada) hace
    // esperar 30 s a cada petición y agota los procesos de PHP-FPM; con esto falla pronto con un 503.
    PDO::ATTR_TIMEOUT => 5,
    // La sesión de MySQL trabaja siempre en UTC, sea cual sea la configuración del servidor: las
    // fechas se guardan en UTC y se convierten a la zona de la aplicación al mostrarlas
    // (format_utc_datetime() en helpers.php).
    Pdo\Mysql::ATTR_INIT_COMMAND => "SET time_zone = '+00:00'",
];

try {

    $pdo = new PDO($dsn, environment('DB_USERNAME'), environment('DB_PASSWORD'), $options);
} catch (PDOException $e) {
    throw new DatabaseConnectionException(
        'Error al conectar a la base de datos',
        (int) $e->getCode(),
        $e
    );
}
