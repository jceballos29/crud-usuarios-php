<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/user-validation.php';

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
            $sql = 'INSERT INTO users (name, last_name, email, phone) VALUES (:name, :last_name, :email, :phone)';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':name' => $data['name'],
                ':last_name' => $data['last_name'],
                ':email' => $data['email'],
                ':phone' => $data['phone'] !== '' ? $data['phone'] : null,
            ]);
            header('Location: index.php?msg=created');
            exit;
        } catch (DatabaseConnectionException $error) {
            http_response_code(503);
            $errorMessage = 'No se pudo conectar a la base de datos. Inténtalo más tarde.';
            log_exception('connection', $error);
        } catch (PDOException $error) {
            // 1062 = entrada duplicada (columna email UNIQUE). 23000 es demasiado amplio.
            if (($error->errorInfo[1] ?? null) === 1062) {
                http_response_code(409);
                $errors['email'] = 'Ya existe un usuario registrado con ese correo.';
            } else {
                http_response_code(500);
                $errorMessage = 'No se pudo guardar el usuario. Inténtalo más tarde.';
                log_exception('query', $error);
            }
        } catch (RuntimeException $error) {
            http_response_code(500);
            $errorMessage = 'Ocurrió un error inesperado. Inténtalo más tarde.';
            log_exception('config', $error);
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

require __DIR__ . '/../includes/layout/header.php';
?>

<h1 class="mb-4">Registrar usuario</h1>

<?php require __DIR__ . '/../includes/partials/user-form.php'; ?>

<?php
require __DIR__ . '/../includes/layout/footer.php';
