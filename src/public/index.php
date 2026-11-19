<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/user-search.php';

$pageTitle = "Inicio";
$users = [];
$errorMessage = null;

// Búsqueda (M1): GET ?q=texto. Sin texto, o con algo que no es texto (?q[]=x), se lista todo.
$search = parse_search_term($_GET['q'] ?? null);

try {
    require_once __DIR__ . '/../includes/connection.php';
    // La consulta es siempre preparada: el texto buscado viaja como dato (con los comodines escapados),
    // nunca dentro del SQL. Ver includes/user-search.php.
    [$sql, $params] = user_list_query($search);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $users = $stmt->fetchAll();
} catch (RuntimeException $error) {
    [$status, $errorMessage] = describe_failure($error, 'No se pudieron obtener los usuarios. Inténtalo más tarde.');
    http_response_code($status);
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
        <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Cerrar"></button>
    </div>
<?php endif; ?>

<div class="my-8 px-6">
    <?php require __DIR__ . '/../includes/partials/users-list.php'; ?>
</div>

<?php
require __DIR__ . '/../includes/layout/footer.php';
