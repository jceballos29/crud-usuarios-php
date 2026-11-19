<?php

declare(strict_types=1);

/**
 * Lista de usuarios.
 *
 * @var string|null $errorMessage
 * @var array<int, array{id: int|string, name: string, last_name: string, email: string, phone: ?string, document: ?string, birth_date: ?string, role: string, created_at: string}> $users
 * @var string $search Término buscado ya limpio ('' si no hay búsqueda); parse_search_term() en user-search.php.
 * @var string $view VIEW_ACTIVE (usuarios visibles) o VIEW_INACTIVE (eliminados, con «Restaurar»).
 * @var array{page: int, pages: int, perPage: int, offset: int, total: int, from: int, to: int} $pagination Resultado de paginate().
 */
?>
<?php if ($errorMessage !== null): ?>
    <div class="alert theme-danger" role="alert">
        <p><?= escape($errorMessage) ?></p>
    </div>
    <?php return; ?>
<?php endif; ?>

<?php $isInactiveView = $view === VIEW_INACTIVE; ?>
<div class="d-flex align-items-center justify-content-between">
    <h1 class="mb-4"><?= $isInactiveView ? 'Usuarios eliminados' : 'Lista de Usuarios' ?> <?= $pagination['total'] > 0 ? '(' . $pagination['total'] . ')' : '' ?></h1>
    <div class="d-flex align-items-center gap-2">
        <?php /* Cambiar de vista conserva la búsqueda (no la página: cada vista tiene su propia paginación). */ ?>
        <?php if ($isInactiveView): ?>
            <a class="btn-outline" href="<?= escape(list_url($search)) ?>" role="button">Ver usuarios</a>
        <?php else: ?>
            <a class="btn-outline" href="<?= escape(list_url($search, 1, [], VIEW_INACTIVE)) ?>" role="button">Eliminados</a>
            <a class="btn-solid theme-primary" href="create.php" role="button">Registrar usuario</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($users !== [] || $search !== ''): ?>
    <?php /* El buscador solo tiene sentido si hay usuarios que buscar o ya hay una búsqueda activa. */ ?>
    <form method="get" action="index.php" role="search" class="mb-4">
        <?php if ($isInactiveView): ?>
            <input type="hidden" name="view" value="<?= VIEW_INACTIVE ?>">
        <?php endif; ?>
        <label for="q" class="visually-hidden">Buscar usuarios</label>
        <div class="d-flex align-items-center gap-2">
            <input type="search" id="q" name="q" class="form-control" value="<?= escape($search) ?>" maxlength="<?= SEARCH_TERM_MAX ?>" placeholder="Buscar por nombre, apellido, correo, teléfono o documento" autocomplete="off">
            <button type="submit" class="btn-solid theme-primary">Buscar</button>
            <?php if ($search !== ''): ?>
                <a class="btn-outline" href="<?= escape(list_url('', 1, [], $view)) ?>" role="button">Limpiar búsqueda</a>
            <?php endif; ?>
        </div>
    </form>
<?php endif; ?>

<?php if ($users === []): ?>
    <div class="alert" role="alert">
        <?php if ($search !== ''): ?>
            <p>No se encontraron resultados para «<?= escape($search) ?>».</p>
        <?php else: ?>
            <p><?= $isInactiveView ? 'No hay usuarios eliminados.' : 'No hay usuarios registrados.' ?></p>
        <?php endif; ?>
    </div>
    <?php return; ?>
<?php endif; ?>

<?php /* Estado del listado (búsqueda y página YA corregida): lo llevan los enlaces de editar y los formularios de eliminar para volver aquí. */ ?>
<?php $listState = list_state($search, $pagination['page'], $view); ?>

<?php if ($search !== '' || $pagination['pages'] > 1): ?>
    <p class="mb-2">
        <?php if ($search !== ''): ?>
            <?= $pagination['total'] === 1 ? '1 resultado' : $pagination['total'] . ' resultados' ?> para «<?= escape($search) ?>»<?= $pagination['pages'] > 1 ? ' · ' : '.' ?>
        <?php endif; ?>
        <?php if ($pagination['pages'] > 1): ?>
            Mostrando <?= $pagination['from'] ?> a <?= $pagination['to'] ?><?= $search === '' ? ' de ' . $pagination['total'] : '' ?>.
        <?php endif; ?>
    </p>
<?php endif; ?>

<table class="table">
    <thead>
        <tr>
            <th>Nombre</th>
            <th>Apellido</th>
            <th>Email</th>
            <th>Teléfono</th>
            <th>Documento</th>
            <th>Nacimiento</th>
            <th>Rol</th>
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
                <td><?= escape($user['document'] ?? "-") ?></td>
                <td><?= escape($user['birth_date'] ?? "-") ?></td>
                <?php /* El rol viene de la BD (ENUM): se muestra su etiqueta, o la clave si algún día no estuviera en la lista. */ ?>
                <td><?= escape(user_roles()[$user['role']] ?? $user['role']) ?></td>
                <td><?= escape(format_utc_datetime((string) $user['created_at'])) ?></td>
                <td>
                    <?php $fullName = $user['name'] . ' ' . $user['last_name']; ?>
                    <?php if ($isInactiveView): ?>
                        <?php /* Un eliminado no se edita: se restaura (POST, como eliminar). Vuelve a esta misma vista. */ ?>
                        <form method="post" action="restore.php" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= escape($user['id']) ?>">
                            <?php foreach ($listState as $stateName => $stateValue): ?>
                                <input type="hidden" name="<?= escape($stateName) ?>" value="<?= escape($stateValue) ?>">
                            <?php endforeach; ?>
                            <button type="submit" class="btn-subtle theme-success" title="Restaurar" aria-label="Restaurar a <?= escape($fullName) ?>"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Restaurar</button>
                        </form>
                    <?php else: ?>
                        <div class="d-flex align-items-center">
                            <a class="btn-subtle theme-warning btn-icon me-2" href="<?= escape('edit.php?' . http_build_query(['id' => $user['id']] + $listState, '', '&', PHP_QUERY_RFC3986)) ?>" role="button" title="Editar" aria-label="Editar a <?= escape($fullName) ?>"><i class="bi bi-pencil" aria-hidden="true"></i></a>
                            <?php /* data-confirm lo lee js/confirm-submit.js: sin onsubmit en línea (compatible con la CSP) y solo hace falta escapar para HTML. */ ?>
                            <form method="post" action="delete.php" class="d-inline" data-confirm="<?= escape(sprintf('¿Eliminar a %s?', $fullName)) ?>">
                            <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= escape($user['id']) ?>">
                                <?php foreach ($listState as $stateName => $stateValue): ?>
                                    <input type="hidden" name="<?= escape($stateName) ?>" value="<?= escape($stateValue) ?>">
                                <?php endforeach; ?>
                                <button type="submit" class="btn-subtle theme-danger btn-icon" title="Eliminar" aria-label="Eliminar a <?= escape($fullName) ?>"><i class="bi bi-trash-fill" aria-hidden="true"></i></button>
                            </form>
                        </div>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php require __DIR__ . '/pagination.php'; ?>
