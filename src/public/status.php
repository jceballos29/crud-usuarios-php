<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';

// Página de diagnóstico del stack: solo en desarrollo. Enseña versiones exactas de PHP y de MySQL,
// información que un atacante usaría para buscar vulnerabilidades, así que fuera de APP_ENV=local
// responde como si no existiera.
if (getenv('APP_ENV') !== 'local') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "No encontrado.\n";
    exit;
}

/**
 * Comprueba la conexión a la base de datos con la misma conexión que usa la aplicación.
 *
 * @return array{ok: bool, message: string}
 */
function check_database(): array
{
    try {
        require_once __DIR__ . '/../includes/connection.php';

        $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();

        return ['ok' => true, 'message' => "Conectado a MySQL $version"];
    } catch (RuntimeException | PDOException $error) {
        // No se muestra el detalle técnico en pantalla: se registra.
        log_exception('connection', $error);

        return ['ok' => false, 'message' => 'No se pudo conectar a la base de datos'];
    }
}

$pageTitle = 'Estado del stack';
$requiredExtensions = ['mbstring', 'intl', 'pdo_mysql', 'zip', 'Zend OPcache'];
$database = check_database();

if (!$database['ok']) {
    http_response_code(503);
}

require __DIR__ . '/../includes/layout/header.php';
?>

<h1 class="mb-4">Stack PHP en Docker para CRUD de Usuarios</h1>

<p>PHP <strong><?= escape(PHP_VERSION) ?></strong> · SAPI: <?= escape(PHP_SAPI) ?> · Entorno: <?= escape((string) getenv('APP_ENV')) ?></p>

<h2>Extensiones</h2>
<ul>
    <?php foreach ($requiredExtensions as $extension): ?>
        <li>
            <?= extension_loaded($extension) ? '✔' : '✘' ?> <?= escape($extension) ?>
            <?= extension_loaded($extension) ? '' : '(falta)' ?>
        </li>
    <?php endforeach; ?>
</ul>

<h2>Base de datos</h2>
<div class="alert theme-<?= $database['ok'] ? 'success' : 'danger' ?>" role="alert">
    <p><?= escape($database['message']) ?></p>
</div>

<?php
require __DIR__ . '/../includes/layout/footer.php';
