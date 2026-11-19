<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/user-validation.php';
require_once __DIR__ . '/../includes/request-guard.php';

// delete.php solo atiende POST y casi nunca muestra pantalla: borra y redirige.
// Cualquier otro método (GET al escribir la URL, PUT, DELETE...) recibe 405 y no se toca nada.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Método no permitido. Usa POST.\n";
    exit;
}

// Orden de las comprobaciones: método (405) -> origen (403) -> id (400) -> BD.
// Primero "¿quién pide?" y después "¿qué pide?" (ver includes/request-guard.php).
reject_foreign_origin();

try {
    // El id se valida ANTES de abrir la BD. Un id mal formado no identifica a ningún usuario:
    // es una petición incorrecta (400), no un usuario "ya borrado".
    $userId = parse_user_id($_POST['id'] ?? null);

    if ($userId === null) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Petición incorrecta: el id no es válido.\n";
        exit;
    }

    require_once __DIR__ . '/../includes/connection.php';

    $stmt = $pdo->prepare('DELETE FROM users WHERE id = :id');
    $stmt->execute([':id' => $userId]);

    // Borrar es idempotente: si el usuario ya no existía, el estado deseado ya se cumple y no es
    // un error. Pero no se dice "eliminado" si esta petición no eliminó nada.
    // (rowCount() cuenta las filas encontradas: connection.php activa Pdo\Mysql::ATTR_FOUND_ROWS.)
    $result = $stmt->rowCount() > 0 ? 'deleted' : 'missing';

    header('Location: index.php?msg=' . $result);
    exit;
} catch (RuntimeException $error) {
    [$status, $errorMessage] = describe_failure($error, 'No se pudo eliminar el usuario. Inténtalo más tarde.');
    http_response_code($status);
}

// Si la BD falla no se redirige (sería un 302 y luego un 200): se responde con el código
// honesto y esa misma respuesta lleva una página con el aviso y un enlace al listado.
$pageTitle = 'No se pudo eliminar';
$errorTitle = 'No se pudo eliminar el usuario';

require __DIR__ . '/../includes/layout/header.php';
require __DIR__ . '/../includes/partials/action-error.php';
require __DIR__ . '/../includes/layout/footer.php';
