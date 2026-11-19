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
<div class="auth">
    <div class="auth__card">
        <div class="auth__brand">
            <svg class="brand__mark" aria-hidden="true" focusable="false"><use href="<?= escape(asset('img/icons.svg')) ?>#brand"></use></svg>
            <h1>Iniciar sesión</h1>
            <p>Entra para gestionar los usuarios.</p>
        </div>

        <?php if ($notice !== null): ?>
            <div class="notice notice--<?= escape($notice[0]) ?> mb-4" role="status">
                <span class="notice__icon"><?= icon($notice[0] === 'info' ? 'info' : 'check-circle') ?></span>
                <div class="notice__text"><p><?= escape($notice[1]) ?></p></div>
            </div>
        <?php endif; ?>

        <?php if ($errorMessage !== null): ?>
            <div class="notice notice--danger mb-4" role="alert">
                <span class="notice__icon"><?= icon('alert') ?></span>
                <div class="notice__text"><p><?= escape($errorMessage) ?></p></div>
            </div>
        <?php endif; ?>

        <form method="post" action="login.php" class="panel panel--form">
            <?= csrf_field() ?>
            <div class="stack">
                <div class="field">
                    <label for="email" class="field__label">Correo</label>
                    <?php /* username: así el gestor de contraseñas del navegador sabe a qué cuenta pertenece la clave. */ ?>
                    <input type="email" class="input" id="email" name="email" value="<?= escape($email) ?>" maxlength="254" autocomplete="username" required autofocus>
                </div>
                <div class="field">
                    <label for="password" class="field__label">Contraseña</label>
                    <?php /* La contraseña NUNCA se vuelve a pintar en el formulario, ni siquiera tras un error. */ ?>
                    <input type="password" class="input" id="password" name="password" autocomplete="current-password" required>
                </div>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn--primary btn--block">Entrar</button>
            </div>
        </form>
    </div>
</div>
