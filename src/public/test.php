<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';

$users = [];

try {
    require_once __DIR__ . '/../includes/connection.php';
    $users = $pdo->query('SELECT id, name, last_name, email, created_at FROM users ORDER BY id DESC')->fetchAll();
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
}

?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CRUD de Usuarios</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>

<body>
    <div class="container mt-5">
        <?php if (isset($errorMessage)): ?>
            <div class="alert alert-danger" role="alert"><?= escape($errorMessage) ?></div>
        <?php else: ?>
            <h1 class="mb-4">Lista de Usuarios</h1>
            <table class="table table-bordered">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nombre</th>
                        <th>Apellido</th>
                        <th>Email</th>
                        <th>Fecha de Creación</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                        <tr>
                            <td><?= escape($user['id']) ?></td>
                            <td><?= escape($user['name']) ?></td>
                            <td><?= escape($user['last_name']) ?></td>
                            <td><?= escape($user['email']) ?></td>
                            <td><?= escape($user['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>
