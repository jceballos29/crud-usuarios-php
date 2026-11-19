<?php

declare(strict_types=1);

/**
 * Formulario de inicio de sesión.
 *
 * @var string $email Correo escrito (se conserva tras un error; la contraseña NUNCA).
 * @var string|null $errorMessage
 * @var array{0: string, 1: string}|null $notice [tipo, texto] de un aviso (sesión cerrada, caducada).
 */
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
        <?= csrf_field() ?>
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
