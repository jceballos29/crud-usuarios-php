<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/user-validation.php'; // user_roles(): etiqueta del rol en el listado
require_once __DIR__ . '/../includes/user-search.php';
require_once __DIR__ . '/../includes/pagination.php';

$pageTitle = "Inicio";
$users = [];
$errorMessage = null;

// Búsqueda (M1): GET ?q=texto. Sin texto, o con algo que no es texto (?q[]=x), se lista todo.
$search = parse_search_term($_GET['q'] ?? null);

// Paginación (M2): GET ?page=N. Lo que no sea un entero >= 1 cuenta como la página 1.
$requestedPage = parse_page($_GET['page'] ?? null);
$pagination = paginate(0, $requestedPage);

// Vista (M4): GET ?view=inactive muestra a los eliminados (borrado lógico); cualquier otro valor, a los visibles.
$view = parse_view($_GET['view'] ?? null);
$showActive = $view === VIEW_ACTIVE;

try {
    require_once __DIR__ . '/../includes/connection.php';
    // Las consultas son siempre preparadas: el texto buscado viaja como dato (con los comodines
    // escapados) y LIMIT/OFFSET como enteros, nunca dentro del SQL. Ver includes/user-search.php.
    // Primero se cuenta (con el mismo filtro) para saber cuántas páginas hay; después se pide UNA página.
    [$countSql, $countParams] = user_count_query($search, $showActive);
    $stmt = $pdo->prepare($countSql);
    bind_all($stmt, $countParams);
    $stmt->execute();

    // paginate() corrige la página si se pasó del rango: ?page=999 muestra la última, no una vacía.
    $pagination = paginate((int) $stmt->fetchColumn(), $requestedPage);

    [$sql, $params] = user_list_query($search, $pagination['perPage'], $pagination['offset'], $showActive);
    $stmt = $pdo->prepare($sql);
    bind_all($stmt, $params);
    $stmt->execute();
    $users = $stmt->fetchAll();
} catch (RuntimeException $error) {
    [$status, $errorMessage] = describe_failure($error, 'No se pudieron obtener los usuarios. Inténtalo más tarde.');
    http_response_code($status);
}

$messages = [
    "created" => "El usuario ha sido registrado exitosamente.",
    "updated" => "El usuario ha sido actualizado exitosamente.",
    "deleted" => "El usuario ha sido eliminado exitosamente. Puedes restaurarlo desde «Eliminados».",
    "missing" => "El usuario ya no existía, no se eliminó nada.",
    "restored" => "El usuario ha sido restaurado exitosamente.",
    "not_restored" => "El usuario no estaba eliminado, no se restauró nada."
];

// Tipo de aviso (clase theme-*). Solo se listan las excepciones: lo demás es un éxito.
$messageTypes = [
    "missing" => "info", // no es un éxito ni un error: el estado deseado ya se cumplía
    "not_restored" => "info",
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
