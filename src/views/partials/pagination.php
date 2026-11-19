<?php

declare(strict_types=1);

use App\Http\Pagination;

/**
 * Navegación entre páginas del listado. No pinta nada si solo hay una.
 *
 * Los enlaces conservan la búsqueda (?q=) y omiten lo que vale por defecto (la página 1), de modo que
 * cada estado tiene una sola URL. El anterior y el siguiente se deshabilitan en los extremos con un
 * <span>, que no recibe el foco ni se puede pulsar, en vez de un enlace que no lleva a ninguna parte.
 *
 * @var string $search Término buscado ya limpio ('' si no hay búsqueda).
 * @var string $view Pagination::VIEW_ACTIVE o Pagination::VIEW_INACTIVE: los enlaces de página se quedan en la misma vista.
 * @var array{page: int, pages: int, perPage: int, offset: int, total: int, from: int, to: int} $pagination Resultado de paginate().
 */

if ($pagination['pages'] <= 1) {
    return;
}

$current = $pagination['page'];
$last = $pagination['pages'];
?>
<nav class="pager" aria-label="Paginación del listado de usuarios">
    <ul class="pager__list list-plain">
        <?php if ($current > 1): ?>
            <li class="pager__item"><a class="pager__link" href="<?= escape(Pagination::listUrl($search, $current - 1, [], $view)) ?>" rel="prev"><?= icon('chevron-left') ?><span class="pager__label">Anterior</span></a></li>
        <?php else: ?>
            <li class="pager__item pager__item--disabled"><span class="pager__link"><?= icon('chevron-left') ?><span class="pager__label">Anterior</span></span></li>
        <?php endif; ?>

        <?php foreach (Pagination::pageLinks($current, $last) as $number): ?>
            <?php if ($number === null): ?>
                <li class="pager__item pager__item--gap"><span class="pager__link">…</span></li>
            <?php elseif ($number === $current): ?>
                <li class="pager__item pager__item--current"><span class="pager__link" aria-current="page"><?= $number ?></span></li>
            <?php else: ?>
                <li class="pager__item"><a class="pager__link" href="<?= escape(Pagination::listUrl($search, $number, [], $view)) ?>" aria-label="Página <?= $number ?>"><?= $number ?></a></li>
            <?php endif; ?>
        <?php endforeach; ?>

        <?php if ($current < $last): ?>
            <li class="pager__item"><a class="pager__link" href="<?= escape(Pagination::listUrl($search, $current + 1, [], $view)) ?>" rel="next"><span class="pager__label">Siguiente</span><?= icon('chevron-right') ?></a></li>
        <?php else: ?>
            <li class="pager__item pager__item--disabled"><span class="pager__link"><span class="pager__label">Siguiente</span><?= icon('chevron-right') ?></span></li>
        <?php endif; ?>
    </ul>
</nav>
