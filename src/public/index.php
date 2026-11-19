<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';

$pageTitle = "Inicio";
$users = [];
$confirmTexts = [];
$errorMessage = null;

try {
    require_once __DIR__ . '/../includes/connection.php';
    $users = $pdo->query('SELECT id, name, last_name, email, phone, created_at FROM users ORDER BY id DESC')->fetchAll();

    foreach ($users as $user) {
        $confirmTexts[$user['id']] = json_encode(
            sprintf('¿Eliminar a %s %s?', $user['name'], $user['last_name']),
            JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        );
    }
    unset($user);
} catch (DatabaseConnectionException $error) {
    http_response_code(503);
    $errorMessage = 'No se pudo conectar a la base de datos. Inténtalo más tarde.';
    log_exception('connection', $error);
} catch (PDOException $error) {
    http_response_code(500);
    $errorMessage = 'No se pudieron obtener los usuarios. Inténtalo más tarde.';
    log_exception('query', $error);
} catch (RuntimeException $error) {
    http_response_code(500);
    $errorMessage = 'Ocurrió un error inesperado. Inténtalo más tarde.';
    log_exception('config', $error);
} catch (JsonException $error) {
    http_response_code(500);
    $users = [];
    $errorMessage = 'No se pudo preparar la lista de usuarios. Inténtalo más tarde.';
    log_exception('json', $error);
}

$messages = [
    "created" => "El usuario ha sido registrado exitosamente.",
    "updated" => "El usuario ha sido actualizado exitosamente.",
    "deleted" => "El usuario ha sido eliminado exitosamente.",
    "missing" => "El usuario ya no existía, no se eliminó nada."
];

// Tipo de aviso (clase theme-*). Solo se listan las excepciones: lo demás es un éxito.
$messageTypes = [
    "missing" => "info", // no es un éxito ni un error: el estado deseado ya se cumplía
];

// Si hubo un error, no se muestra ningún mensaje de éxito.
$message = $errorMessage === null ? ($_GET['msg'] ?? null) : null;
$message = is_string($message) && array_key_exists($message, $messages) ? $message : null;

require __DIR__ . '/../includes/layout/header.php';
?>

<?php if ($message !== null): ?>
    <div class="alert theme-<?= escape($messageTypes[$message] ?? 'success') ?>" role="alert">
        <p><?= escape($messages[$message]) ?></p>
        <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="my-8 px-6">
    <?php require __DIR__ . '/../includes/partials/users-list.php'; ?>
</div>

<?php
require __DIR__ . '/../includes/layout/footer.php';
