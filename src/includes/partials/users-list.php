<?php

declare(strict_types=1);

/**
 * Lista de usuarios.
 *
 * @var string|null $errorMessage
 * @var array<int, array{id: int|string, name: string, last_name: string, email: string, phone: ?string, created_at: string}> $users
 */
?>
<?php if ($errorMessage !== null): ?>
    <div class="alert theme-danger" role="alert">
        <p><?= escape($errorMessage) ?></p>
    </div>
    <?php return; ?>
<?php endif; ?>

<div class="d-flex align-items-center justify-content-between">
    <h1 class="mb-4">Lista de Usuarios <?= $users ? '(' . count($users) . ')' : '' ?></h1>
    <a class="btn-solid theme-primary" href="create.php" role="button">Registrar usuario</a>
</div>

<?php if ($users === []): ?>
    <div class="alert" role="alert">
        <p>No hay usuarios registrados.</p>
    </div>
    <?php return; ?>
<?php endif; ?>

<table class="table">
    <thead>
        <tr>
            <th>Nombre</th>
            <th>Apellido</th>
            <th>Email</th>
            <th>Teléfono</th>
            <th>Fecha de Creación</th>
            <th aria-label="Acciones"></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($users as $user): ?>
            <tr>
                <td><?= escape($user['name']) ?></td>
                <td><?= escape($user['last_name']) ?></td>
                <td><?= escape($user['email']) ?></td>
                <td><?= escape($user['phone'] ?? "-") ?></td>
                <td><?= escape(format_utc_datetime((string) $user['created_at'])) ?></td>
                <td>
                    <?php $fullName = $user['name'] . ' ' . $user['last_name']; ?>
                    <div class="d-flex align-items-center">
                        <a class="btn-subtle theme-warning btn-icon me-2" href="edit.php?id=<?= escape($user['id']) ?>" role="button" title="Editar" aria-label="Editar a <?= escape($fullName) ?>"><i class="bi bi-pencil" aria-hidden="true"></i></a>
                        <?php /* data-confirm lo lee js/confirm-submit.js: sin onsubmit en línea (compatible con la CSP) y solo hace falta escapar para HTML. */ ?>
                        <form method="post" action="delete.php" class="d-inline" data-confirm="<?= escape(sprintf('¿Eliminar a %s?', $fullName)) ?>">
                            <input type="hidden" name="id" value="<?= escape($user['id']) ?>">
                            <button type="submit" class="btn-subtle theme-danger btn-icon" title="Eliminar" aria-label="Eliminar a <?= escape($fullName) ?>"><i class="bi bi-trash-fill" aria-hidden="true"></i></button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
