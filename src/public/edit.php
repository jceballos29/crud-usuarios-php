<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/user-validation.php';
require_once __DIR__ . '/../includes/request-guard.php';

$pageTitle = "Editar usuario";

$fields = user_fields();
$data = empty_user_data($fields);
$errors = []; // campo => mensaje (solo el primer error de cada campo)
$errorMessage = null; // error general (BD, configuración...)
$submitted = $_SERVER['REQUEST_METHOD'] === 'POST';

if ($submitted) {
    reject_foreign_origin(); // 403 si el POST no viene del propio sitio, antes de mirar el id o lo que envía
}

// El id se lee una sola vez y solo de la fuente que corresponde al método:
// GET al abrir el formulario, POST al enviarlo. Nunca se mezclan.
$rawId = $submitted ? ($_POST['id'] ?? null) : ($_GET['id'] ?? null);

// parse_user_id() devuelve el id como int, o null si no es válido (ausente, arreglo, texto,
// con signo o espacios, o fuera de rango). Ver includes/user-validation.php.
$userId = parse_user_id($rawId);

// Huella de la fila con la que se abrió el formulario (la trae el POST). Si la BD falla, el formulario
// que se vuelve a pintar la conserva; si no llega, simplemente no se comprueba (curl, scripts).
$submittedToken = $submitted && is_string($_POST['row_token'] ?? null) && $_POST['row_token'] !== '' ? $_POST['row_token'] : null;
$rowToken = $submittedToken;

$notFound = $userId === null; // id inválido: el inexistente se detecta más abajo con el SELECT
$loaded = false;               // true cuando el usuario se cargó de la BD
$user = null;

// Lo enviado se lee antes de tocar la BD: si la conexión falla, el formulario conserva lo escrito.
if ($submitted) {
    $data = read_user_input($_POST, $fields);
}

if (!$notFound) {
    try {
        require_once __DIR__ . '/../includes/connection.php';

        // Cargar (también al enviar el POST: un usuario borrado entre tanto debe dar 404).
        $stmt = $pdo->prepare('SELECT name, last_name, email, phone FROM users WHERE id = :id');
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch(); // false si no hay fila

        if ($user === false) {
            $notFound = true;
        } else {
            $loaded = true;
            $currentToken = user_row_token($user);
            $rowToken = $submittedToken ?? $currentToken;

            if ($submitted && !hash_equals($currentToken, $rowToken)) {
                // Edición simultánea: la fila ya no es la que se mostró al abrir el formulario. No se
                // guarda nada. Se conserva lo escrito y la huella VIEJA: hay que recargar para ver los
                // datos actuales y volver a aplicar los cambios a propósito.
                http_response_code(409);
                $errorMessage = 'Otra persona modificó a este usuario mientras lo editabas. Recarga la página para ver los datos actuales: tus cambios no se han guardado.';
            } elseif ($submitted) {
                $errors = validate_user($data, $fields);

                if ($errors === []) {
                    $sql = 'UPDATE users SET name = :name, last_name = :last_name, email = :email, phone = :phone WHERE id = :id';
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([
                        ':name' => $data['name'],
                        ':last_name' => $data['last_name'],
                        ':email' => $data['email'],
                        ':phone' => $data['phone'] !== '' ? $data['phone'] : null,
                        ':id' => $userId,
                    ]);

                    // connection.php activa FOUND_ROWS: rowCount() cuenta las filas que coinciden con el
                    // WHERE aunque los valores no cambien, así que 0 significa que el usuario ya no existe
                    // (alguien lo borró entre el SELECT y el UPDATE).
                    if ($stmt->rowCount() > 0) {
                        header('Location: index.php?msg=updated');
                        exit;
                    }

                    $notFound = true;
                } else {
                    // Regla de códigos: si el envío no se completó nunca es 200.
                    http_response_code(422);
                }
            } else {
                // Rellenar con lo guardado. phone puede ser NULL y el formulario espera texto.
                $data = [
                    'name' => (string) $user['name'],
                    'last_name' => (string) $user['last_name'],
                    'email' => (string) $user['email'],
                    'phone' => (string) ($user['phone'] ?? ''),
                ];
            }
        }
    } catch (RuntimeException $error) {
        // 1062 = correo duplicado (UNIQUE). Aquí significa que lo usa otro usuario:
        // guardar sin cambiar el correo no choca, el registro no entra en conflicto consigo mismo.
        if ($error instanceof PDOException && ($error->errorInfo[1] ?? null) === 1062) {
            http_response_code(409);
            $errors['email'] = 'Ya existe otro usuario registrado con ese correo.';
        } else {
            [$status, $errorMessage] = describe_failure($error, 'No se pudo completar la operación. Inténtalo más tarde.');
            http_response_code($status);
        }
    }
}

// Id inválido o inexistente: 404 con una página que lo explica.
if ($notFound) {
    http_response_code(404);
    $pageTitle = "Usuario no encontrado";
    require __DIR__ . '/../includes/layout/header.php';
    require __DIR__ . '/../includes/partials/user-not-found.php';
    require __DIR__ . '/../includes/layout/footer.php';
    exit;
}

// Variables propias del parcial del formulario.
$action = 'edit.php';
$submitLabel = 'Actualizar';

require __DIR__ . '/../includes/layout/header.php';
?>

<h1 class="mb-4">Editar usuario</h1>

<?php if (!$loaded && !$submitted): ?>
    <?php /* GET con la BD caída: no hay nada que mostrar ni que conservar, solo el aviso. */ ?>
    <div class="alert theme-danger" role="alert">
        <p><?= escape($errorMessage ?? 'No se pudo cargar el usuario.') ?></p>
    </div>
<?php else: ?>
    <?php if ($user): // El nombre viene de la BD (dato externo): siempre escapado. ?>
        <p class="mb-2">Editando a <strong><?= escape($user['name'] . ' ' . $user['last_name']) ?></strong></p>
    <?php endif; ?>

    <?php /* POST con la BD caída: el formulario se conserva con lo escrito y el aviso. */ ?>
    <?php require __DIR__ . '/../includes/partials/user-form.php'; ?>
<?php endif; ?>

<?php
require __DIR__ . '/../includes/layout/footer.php';
