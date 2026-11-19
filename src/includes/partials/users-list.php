<?php

declare(strict_types=1);

/**
 * Lista de usuarios.
 *
 * @var string|null $errorMessage
 * @var array<int, array{id: int|string, name: string, last_name: string, email: string, phone: ?string, created_at: string}> $users
 * @var string $search Término buscado ya limpio ('' si no hay búsqueda); parse_search_term() en user-search.php.
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

<?php if ($users !== [] || $search !== ''): ?>
    <?php /* El buscador solo tiene sentido si hay usuarios que buscar o ya hay una búsqueda activa. */ ?>
    <form method="get" action="index.php" role="search" class="mb-4">
        <label for="q" class="visually-hidden">Buscar usuarios</label>
        <div class="d-flex align-items-center gap-2">
            <input type="search" id="q" name="q" class="form-control" value="<?= escape($search) ?>" maxlength="<?= SEARCH_TERM_MAX ?>" placeholder="Buscar por nombre, apellido, correo o teléfono" autocomplete="off">
            <button type="submit" class="btn-solid theme-primary">Buscar</button>
            <?php if ($search !== ''): ?>
                <a class="btn-outline" href="index.php" role="button">Limpiar búsqueda</a>
            <?php endif; ?>
        </div>
    </form>
<?php endif; ?>

<?php if ($users === []): ?>
    <div class="alert" role="alert">
        <?php if ($search !== ''): ?>
            <p>No se encontraron resultados para «<?= escape($search) ?>».</p>
        <?php else: ?>
            <p>No hay usuarios registrados.</p>
        <?php endif; ?>
    </div>
    <?php return; ?>
<?php endif; ?>

<?php if ($search !== ''): ?>
    <p class="mb-2"><?= count($users) === 1 ? '1 resultado' : count($users) . ' resultados' ?> para «<?= escape($search) ?>».</p>
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
