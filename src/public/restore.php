<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/user-validation.php';
require_once __DIR__ . '/../includes/request-guard.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/user-search.php';
require_once __DIR__ . '/../includes/pagination.php';

// restore.php (M4) deshace un borrado lógico: pone active = 1. Como delete.php, solo atiende POST y casi
// nunca muestra pantalla: actúa y redirige. Mismo orden de comprobaciones: método (405) -> origen (403)
// -> id (400) -> BD. Restaurar nunca choca con un UNIQUE: la fila conserva su correo y su documento, y
// esos índices cubren también a los eliminados, así que nadie más pudo ocuparlos.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Método no permitido. Usa POST.\n";
    exit;
}

reject_foreign_origin();
require_login(); // sin sesión no se toca nada: 302 a login.php (después del origen y antes de mirar el id)
require_csrf();  // y sin el token de esa sesión tampoco: 403 (M6)

try {
    $userId = parse_user_id($_POST['id'] ?? null);

    if ($userId === null) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Petición incorrecta: el id no es válido.\n";
        exit;
    }

    // Se vuelve a la lista de eliminados en el mismo estado (búsqueda y página): si era el último de la
    // última página, index.php corrige la página.
    $returnSearch = parse_search_term($_POST['q'] ?? null);
    $returnPage = parse_page($_POST['page'] ?? null);

    require_once __DIR__ . '/../includes/connection.php';

    // `AND active = 0`: restaurar a quien ya está activo (o no existe) no cuenta como restauración.
    $stmt = $pdo->prepare('UPDATE users SET active = 1 WHERE id = :id AND active = 0');
    $stmt->execute([':id' => $userId]);

    $result = $stmt->rowCount() > 0 ? 'restored' : 'not_restored';

    header('Location: ' . list_url($returnSearch, $returnPage, ['msg' => $result], VIEW_INACTIVE));
    exit;
} catch (RuntimeException $error) {
    [$status, $errorMessage] = describe_failure($error, 'No se pudo restaurar el usuario. Inténtalo más tarde.');
    http_response_code($status);
}

$pageTitle = 'No se pudo restaurar';
$errorTitle = 'No se pudo restaurar el usuario';

require __DIR__ . '/../includes/layout/header.php';
require __DIR__ . '/../includes/partials/action-error.php';
require __DIR__ . '/../includes/layout/footer.php';
