<?php

declare(strict_types=1);

/**
 * Listado de usuarios: el aviso de la acción anterior (si hay) y la lista.
 *
 * @var string|null $message Texto del aviso (creado, eliminado...), o null.
 * @var string $messageType Tipo del aviso (success, info...).
 * @var string|null $errorMessage Error al cargar la lista, o null.
 * @var list<App\Domain\User> $users
 * @var string $search
 * @var string $view
 * @var array<string, int> $pagination
 */
?>
<?php if ($message !== null): ?>
    <div class="alert theme-<?= escape($messageType) ?>" role="alert">
        <p><?= escape($message) ?></p>
        <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Cerrar"></button>
    </div>
<?php endif; ?>

<div class="my-8 px-6">
    <?php App\View\View::render('users/list', ['errorMessage' => $errorMessage, 'users' => $users, 'search' => $search, 'view' => $view, 'pagination' => $pagination]); ?>
</div>
