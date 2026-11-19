<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/request-guard.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/admin-auth.php';

header('Cache-Control: no-store');

$pageTitle = 'Iniciar sesión';
$submitted = $_SERVER['REQUEST_METHOD'] === 'POST';

if ($submitted) {
    reject_foreign_origin(); // también el login: si no, otro sitio podría iniciar SU sesión en tu navegador
}

// Quien ya entró no necesita el formulario.
if (current_admin() !== null) {
    header('Location: index.php');
    exit;
}

$email = '';
$errorMessage = null;

if ($submitted) {
    // Solo texto: login.php?email[]=x no debe romper nada. La contraseña NO se recorta (los espacios cuentan).
    $email = is_string($_POST['email'] ?? null) ? trim($_POST['email']) : '';
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';

    if ($email === '' || $password === '') {
        http_response_code(422);
        $errorMessage = 'Escribe tu correo y tu contraseña.';
    } else {
        try {
            require_once __DIR__ . '/../includes/connection.php';
            $admin = authenticate_admin($pdo, $email, $password);

            if ($admin !== null) {
                log_in_admin($admin);
                header('Location: index.php');
                exit;
            }

            // El MISMO mensaje y el mismo código para «no existe ese correo» y «contraseña incorrecta»: si no,
            // el formulario serviría para descubrir qué correos están registrados.
            http_response_code(401);
            $errorMessage = LOGIN_FAILED_MESSAGE;
        } catch (RuntimeException $error) {
            [$status, $errorMessage] = describe_failure($error, 'No se pudo iniciar sesión. Inténtalo más tarde.');
            http_response_code($status);
        }
    }
}

$messages = [
    'logged_out' => ['success', 'Cerraste la sesión.'],
    'expired' => ['info', 'Tu sesión caducó por inactividad. Vuelve a entrar.'],
];
$notice = $errorMessage === null && !$submitted && is_string($_GET['msg'] ?? null) ? ($messages[$_GET['msg']] ?? null) : null;

require __DIR__ . '/../includes/layout/header.php';
?>

<div class="my-8 px-6">
    <h1 class="mb-4">Iniciar sesión</h1>

    <?php if ($notice !== null): ?>
        <div class="alert theme-<?= escape($notice[0]) ?>" role="alert">
            <p><?= escape($notice[1]) ?></p>
        </div>
    <?php endif; ?>

    <?php if ($errorMessage !== null): ?>
        <div class="alert theme-danger" role="alert">
            <p><?= escape($errorMessage) ?></p>
        </div>
    <?php endif; ?>

    <form method="post" action="login.php" class="my-4">
        <div class="form-field mb-3">
            <label for="email" class="form-label">Correo</label>
            <?php /* username: así el gestor de contraseñas del navegador sabe a qué cuenta pertenece la clave. */ ?>
            <input type="email" class="form-control" id="email" name="email" value="<?= escape($email) ?>" maxlength="254" autocomplete="username" required autofocus>
        </div>
        <div class="form-field mb-3">
            <label for="password" class="form-label">Contraseña</label>
            <?php /* La contraseña NUNCA se vuelve a pintar en el formulario, ni siquiera tras un error. */ ?>
            <input type="password" class="form-control" id="password" name="password" autocomplete="current-password" required>
        </div>
        <div class="d-grid gap-4 mt-10">
            <button type="submit" class="btn-solid theme-primary">Entrar</button>
        </div>
    </form>
</div>

<?php
require __DIR__ . '/../includes/layout/footer.php';
