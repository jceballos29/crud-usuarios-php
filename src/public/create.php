<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/user-validation.php';
require_once __DIR__ . '/../includes/request-guard.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/user-conflict.php';

// Orden: origen (403) -> sesión (302 a login.php) -> lo demás. Primero «¿quién pide?», después «¿qué pide?».
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    reject_foreign_origin();
}
$currentAdmin = require_login();

$pageTitle = "Registrar";

$fields = user_fields();
$data = empty_user_data($fields);
$errors = []; // campo => mensaje (solo el primer error de cada campo)
$errorMessage = null; // error general (BD, configuración...)
$submitted = $_SERVER['REQUEST_METHOD'] === 'POST';

if ($submitted) {
    $data = read_user_input($_POST, $fields);
    $errors = validate_user($data, $fields);

    if ($errors === []) {
        try {
            require_once __DIR__ . '/../includes/connection.php';
            $sql = 'INSERT INTO users (name, last_name, email, phone, document, birth_date, role) VALUES (:name, :last_name, :email, :phone, :document, :birth_date, :role)';
            $stmt = $pdo->prepare($sql);
            $stmt->execute(user_db_params($data));
            header('Location: index.php?msg=created');
            exit;
        } catch (RuntimeException $error) {
            // 1062 = entrada duplicada (UNIQUE de email o de document). 23000 es demasiado amplio.
            $duplicate = $error instanceof PDOException && ($error->errorInfo[1] ?? null) === 1062
                ? duplicate_user_field((string) ($error->errorInfo[2] ?? ''))
                : null;

            if ($duplicate !== null) {
                http_response_code(409);
                // ¿El dueño está en la papelera? Entonces el aviso lo dice (el listado no lo enseña).
                $errors[$duplicate] = duplicate_message($duplicate, false, conflicting_user_is_inactive($pdo, $duplicate, $data[$duplicate]));
            } else {
                [$status, $errorMessage] = describe_failure($error, 'No se pudo guardar el usuario. Inténtalo más tarde.');
                http_response_code($status);
            }
        }
    } else {
        // Regla de códigos: si el envío no se completó nunca es 200.
        // 422 = datos inválidos, 409 = choca con lo ya guardado (email duplicado), 5xx = fallo del servidor.
        http_response_code(422);
    }
}

// Variables propias del parcial del formulario.
$action = 'create.php';
$submitLabel = 'Registrar';
$userId = null;
$rowToken = null;
$returnState = []; // un alta no viene de ninguna página: el usuario nuevo sale el primero
$cancelUrl = 'index.php';

require __DIR__ . '/../includes/layout/header.php';
?>

<h1 class="mb-4">Registrar usuario</h1>

<?php require __DIR__ . '/../includes/partials/user-form.php'; ?>

<?php
require __DIR__ . '/../includes/layout/footer.php';
