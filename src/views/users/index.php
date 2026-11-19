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
    <div class="notice notice--<?= escape($messageType) ?> mb-4" role="status">
        <span class="notice__icon"><?= icon($messageType === 'info' ? 'info' : 'check-circle') ?></span>
        <div class="notice__text"><p><?= escape($message) ?></p></div>
        <button type="button" class="btn btn--icon notice__close" data-dismiss aria-label="Cerrar aviso"><?= icon('x') ?></button>
    </div>
<?php endif; ?>

<?php App\View\View::render('users/list', ['errorMessage' => $errorMessage, 'users' => $users, 'search' => $search, 'view' => $view, 'pagination' => $pagination]); ?>
