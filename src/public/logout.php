<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/request-guard.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/csrf.php';

// Cerrar sesión CAMBIA el estado del servidor, así que es POST (como eliminar): si fuera un enlace GET, una
// imagen <img src="logout.php"> en cualquier otro sitio cerraría la sesión de quien la visite.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Método no permitido. Usa POST.\n";
    exit;
}

reject_foreign_origin();

// Con una sesión iniciada hace falta el token (si no, una página ajena cerraría tu sesión: molesto, y un paso
// para ataques de «login CSRF»). Sin sesión no hay nada que proteger y termina bien (ya estás fuera).
if (current_admin() !== null) {
    require_csrf();
}

// Idempotente.
log_out_admin();

header('Cache-Control: no-store');
header('Location: login.php?msg=logged_out');
exit;
