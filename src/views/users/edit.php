<?php

declare(strict_types=1);

/**
 * Página de edición.
 *
 * @var bool $showForm false si la BD falló al CARGAR al usuario (no hay nada que mostrar ni que conservar).
 * @var string|null $errorMessage Aviso general.
 * @var App\Domain\User|null $user El usuario cargado (null si no se pudo cargar).
 * @var array<string, mixed> $form Variables del formulario compartido.
 */
?>
<div class="narrow">
    <div class="form-head">
        <?php if ($showForm && $user !== null): ?>
            <span class="pebble pebble--lg pebble--<?= $user->tone() ?>" aria-hidden="true"><?= escape($user->initials()) ?></span>
        <?php endif; ?>
        <div>
            <h1>Editar usuario</h1>
            <?php if ($showForm && $user !== null): // El nombre viene de la BD (dato externo): siempre escapado. ?>
                <p class="page-lead">Editando a <strong><?= escape($user->fullName()) ?></strong></p>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$showForm): ?>
        <?php /* GET con la BD caída: no hay nada que mostrar ni que conservar, solo el aviso. */ ?>
        <div class="notice notice--danger" role="alert">
            <span class="notice__icon"><?= icon('alert') ?></span>
            <div class="notice__text"><p><?= escape($errorMessage ?? 'No se pudo cargar el usuario.') ?></p></div>
        </div>
    <?php else: ?>
        <?php /* POST con la BD caída: el formulario se conserva con lo escrito y el aviso. */ ?>
        <?php App\View\View::render('users/form', $form); ?>
    <?php endif; ?>
</div>
