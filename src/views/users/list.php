<?php

declare(strict_types=1);

use App\Domain\User;
use App\Http\Pagination;
use App\Search\UserSearch;

/**
 * Lista de usuarios.
 *
 * @var string|null $errorMessage
 * @var list<User> $users
 * @var string $search Término buscado ya limpio ('' si no hay búsqueda); UserSearch::parseTerm().
 * @var string $view Pagination::VIEW_ACTIVE (usuarios visibles) o Pagination::VIEW_INACTIVE (eliminados, con «Restaurar»).
 * @var array{page: int, pages: int, perPage: int, offset: int, total: int, from: int, to: int} $pagination Resultado de paginate().
 */
?>
<?php if ($errorMessage !== null): ?>
    <div class="notice notice--danger" role="alert">
        <span class="notice__icon"><?= icon('alert') ?></span>
        <div class="notice__text"><p><?= escape($errorMessage) ?></p></div>
    </div>
    <?php return; ?>
<?php endif; ?>

<?php $isInactiveView = $view === Pagination::VIEW_INACTIVE; ?>
<div class="page-head">
    <div>
        <h1><?= $isInactiveView ? 'Usuarios eliminados' : 'Lista de Usuarios' ?> <?= $pagination['total'] > 0 ? '(' . $pagination['total'] . ')' : '' ?></h1>
        <?php if ($isInactiveView): ?>
            <p class="page-lead">No aparecen en la lista. Restáuralos para volver a verlos y editarlos.</p>
        <?php endif; ?>
    </div>
    <div class="page-head__actions">
        <?php /* Cambiar de vista conserva la búsqueda (no la página: cada vista tiene su propia paginación). */ ?>
        <?php if ($isInactiveView): ?>
            <a class="btn btn--ghost" href="<?= escape(Pagination::listUrl($search)) ?>"><?= icon('arrow-left') ?>Ver usuarios</a>
        <?php else: ?>
            <a class="btn btn--ghost" href="<?= escape(Pagination::listUrl($search, 1, [], Pagination::VIEW_INACTIVE)) ?>"><?= icon('archive') ?>Eliminados</a>
            <a class="btn btn--primary" href="create.php"><?= icon('plus') ?>Registrar usuario</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($users !== [] || $search !== ''): ?>
    <?php /* El buscador solo tiene sentido si hay usuarios que buscar o ya hay una búsqueda activa. */ ?>
    <form class="search" method="get" action="index.php" role="search">
        <?php if ($isInactiveView): ?>
            <input type="hidden" name="view" value="<?= Pagination::VIEW_INACTIVE ?>">
        <?php endif; ?>
        <div class="search__field">
            <label for="q" class="visually-hidden">Buscar usuarios</label>
            <?= icon('search') ?>
            <input type="search" id="q" name="q" class="input" value="<?= escape($search) ?>" maxlength="<?= UserSearch::TERM_MAX ?>" placeholder="Buscar por nombre, correo, teléfono o documento" autocomplete="off">
        </div>
        <button type="submit" class="btn btn--primary">Buscar</button>
        <?php if ($search !== ''): ?>
            <a class="btn btn--ghost" href="<?= escape(Pagination::listUrl('', 1, [], $view)) ?>">Limpiar búsqueda</a>
        <?php endif; ?>
    </form>
<?php endif; ?>

<?php if ($users === []): ?>
    <div class="panel empty" role="status">
        <span class="empty__icon"><?= icon($search !== '' ? 'search' : ($isInactiveView ? 'archive' : 'users')) ?></span>
        <?php if ($search !== ''): ?>
            <p>No se encontraron resultados para «<?= escape($search) ?>».</p>
        <?php else: ?>
            <p><?= $isInactiveView ? 'No hay usuarios eliminados.' : 'No hay usuarios registrados.' ?></p>
        <?php endif; ?>
        <?php if (!$isInactiveView && $search === ''): ?>
            <a class="btn btn--primary" href="create.php"><?= icon('plus') ?>Registrar usuario</a>
        <?php endif; ?>
    </div>
    <?php return; ?>
<?php endif; ?>

<?php /* Estado del listado (búsqueda y página YA corregida): lo llevan los enlaces de editar y los formularios de eliminar para volver aquí. */ ?>
<?php $listState = Pagination::listState($search, $pagination['page'], $view); ?>

<?php if ($search !== '' || $pagination['pages'] > 1): ?>
    <p class="summary">
        <?php if ($search !== ''): ?>
            <?= $pagination['total'] === 1 ? '1 resultado' : $pagination['total'] . ' resultados' ?> para «<?= escape($search) ?>»<?= $pagination['pages'] > 1 ? ' · ' : '.' ?>
        <?php endif; ?>
        <?php if ($pagination['pages'] > 1): ?>
            Mostrando <?= $pagination['from'] ?> a <?= $pagination['to'] ?><?= $search === '' ? ' de ' . $pagination['total'] : '' ?>.
        <?php endif; ?>
    </p>
<?php endif; ?>

<div class="panel table-wrap">
    <table class="table table--stack">
        <thead>
            <tr>
                <th scope="col">Usuario</th>
                <th scope="col">Teléfono</th>
                <th scope="col">Documento</th>
                <th scope="col" class="col-secondary">Nacimiento</th>
                <th scope="col">Rol</th>
                <th scope="col" class="col-secondary">Creado</th>
                <th scope="col"><span class="visually-hidden">Acciones</span></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $user): ?>
                <?php $fullName = $user->fullName(); ?>
                <tr>
                    <td class="cell-user">
                        <div class="person">
                            <span class="pebble pebble--<?= $user->tone() ?>" aria-hidden="true"><?= escape($user->initials()) ?></span>
                            <div class="person__text">
                                <span class="person__name"><?= escape($fullName) ?></span>
                                <span class="person__email"><?= escape($user->email) ?></span>
                            </div>
                        </div>
                    </td>
                    <?php /* Lo opcional vacío se pinta como un guion y en pantallas pequeñas se oculta (td.is-empty). */ ?>
                    <td data-label="Teléfono" class="num<?= $user->phone === null ? ' is-empty is-muted' : '' ?>"><?= escape($user->phone ?? '—') ?></td>
                    <td data-label="Documento" class="num<?= $user->document === null ? ' is-empty is-muted' : '' ?>"><?= escape($user->document ?? '—') ?></td>
                    <td data-label="Nacimiento" class="col-secondary num<?= $user->birthDate === null ? ' is-empty is-muted' : '' ?>"><?= escape($user->birthDate ?? '—') ?></td>
                    <?php /* El rol viene de la BD (ENUM): se muestra su etiqueta, o la clave si algún día no estuviera en la lista. */ ?>
                    <td data-label="Rol"><span class="tag tag--<?= escape($user->role) ?>"><?= escape(User::roleLabel($user->role)) ?></span></td>
                    <td data-label="Creado" class="col-secondary num is-muted"><?= escape(format_utc_datetime($user->createdAt)) ?></td>
                    <td class="cell-actions">
                        <?php if ($isInactiveView): ?>
                            <?php /* Un eliminado no se edita: se restaura (POST, como eliminar). Vuelve a esta misma vista. */ ?>
                            <form class="row-actions" method="post" action="restore.php">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= escape($user->id) ?>">
                                <?php foreach ($listState as $stateName => $stateValue): ?>
                                    <input type="hidden" name="<?= escape($stateName) ?>" value="<?= escape($stateValue) ?>">
                                <?php endforeach; ?>
                                <button type="submit" class="btn btn--ghost btn--small" title="Restaurar" aria-label="Restaurar a <?= escape($fullName) ?>"><?= icon('undo') ?>Restaurar</button>
                            </form>
                        <?php else: ?>
                            <div class="row-actions">
                                <a class="btn btn--icon" href="<?= escape('edit.php?' . http_build_query(['id' => $user->id] + $listState, '', '&', PHP_QUERY_RFC3986)) ?>" title="Editar" aria-label="Editar a <?= escape($fullName) ?>"><?= icon('pencil') ?></a>
                                <?php /* data-confirm lo lee js/app.js (un <dialog> propio): sin onsubmit en línea (compatible con la CSP) y solo hace falta escapar para HTML. */ ?>
                                <form method="post" action="delete.php" data-confirm="<?= escape(sprintf('¿Eliminar a %s?', $fullName)) ?>" data-confirm-note="Dejará de aparecer en la lista. Podrás restaurarlo desde «Eliminados»." data-confirm-action="Eliminar">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= escape($user->id) ?>">
                                    <?php foreach ($listState as $stateName => $stateValue): ?>
                                        <input type="hidden" name="<?= escape($stateName) ?>" value="<?= escape($stateValue) ?>">
                                    <?php endforeach; ?>
                                    <button type="submit" class="btn btn--icon btn--icon-danger" title="Eliminar" aria-label="Eliminar a <?= escape($fullName) ?>"><?= icon('trash') ?></button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php App\View\View::render('partials/pagination', ['search' => $search, 'view' => $view, 'pagination' => $pagination]); ?>
