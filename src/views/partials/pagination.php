<?php

declare(strict_types=1);

/**
 * Navegación entre páginas del listado. No pinta nada si solo hay una.
 *
 * Los enlaces conservan la búsqueda (?q=) y omiten lo que vale por defecto (la página 1), de modo que
 * cada estado tiene una sola URL. El anterior y el siguiente se deshabilitan en los extremos con un
 * <span>, que no recibe el foco ni se puede pulsar, en vez de un enlace que no lleva a ninguna parte.
 *
 * @var string $search Término buscado ya limpio ('' si no hay búsqueda).
 * @var string $view VIEW_ACTIVE o VIEW_INACTIVE: los enlaces de página se quedan en la misma vista.
 * @var array{page: int, pages: int, perPage: int, offset: int, total: int, from: int, to: int} $pagination Resultado de paginate().
 */

if ($pagination['pages'] <= 1) {
    return;
}

$current = $pagination['page'];
$last = $pagination['pages'];
?>
<nav aria-label="Paginación del listado de usuarios">
    <ul class="pagination">
        <?php if ($current > 1): ?>
            <li class="page-item"><a class="page-link" href="<?= escape(list_url($search, $current - 1, [], $view)) ?>" rel="prev">Anterior</a></li>
        <?php else: ?>
            <li class="page-item disabled"><span class="page-link">Anterior</span></li>
        <?php endif; ?>

        <?php foreach (page_links($current, $last) as $number): ?>
            <?php if ($number === null): ?>
                <li class="page-item disabled"><span class="page-link">…</span></li>
            <?php elseif ($number === $current): ?>
                <li class="page-item active"><span class="page-link" aria-current="page"><?= $number ?></span></li>
            <?php else: ?>
                <li class="page-item"><a class="page-link" href="<?= escape(list_url($search, $number, [], $view)) ?>" aria-label="Página <?= $number ?>"><?= $number ?></a></li>
            <?php endif; ?>
        <?php endforeach; ?>

        <?php if ($current < $last): ?>
            <li class="page-item"><a class="page-link" href="<?= escape(list_url($search, $current + 1, [], $view)) ?>" rel="next">Siguiente</a></li>
        <?php else: ?>
            <li class="page-item disabled"><span class="page-link">Siguiente</span></li>
        <?php endif; ?>
    </ul>
</nav>
