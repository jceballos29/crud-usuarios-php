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
<h1 class="mb-4">Editar usuario</h1>

<?php if (!$showForm): ?>
    <?php /* GET con la BD caída: no hay nada que mostrar ni que conservar, solo el aviso. */ ?>
    <div class="alert theme-danger" role="alert">
        <p><?= escape($errorMessage ?? 'No se pudo cargar el usuario.') ?></p>
    </div>
<?php else: ?>
    <?php if ($user !== null): // El nombre viene de la BD (dato externo): siempre escapado. ?>
        <p class="mb-2">Editando a <strong><?= escape($user->fullName()) ?></strong></p>
    <?php endif; ?>

    <?php /* POST con la BD caída: el formulario se conserva con lo escrito y el aviso. */ ?>
    <?php App\View\View::render('users/form', $form); ?>
<?php endif; ?>
