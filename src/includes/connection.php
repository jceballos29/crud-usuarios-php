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
