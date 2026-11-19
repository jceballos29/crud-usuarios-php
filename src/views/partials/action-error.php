<?php

declare(strict_types=1);

/**
 * Página de error de una acción que no se pudo completar (p. ej. el borrado con la BD caída).
 * La página que la incluye ya fijó el código HTTP (503 o 500) antes de imprimir nada.
 *
 * @var string $errorTitle Título de la página de error.
 * @var string $errorMessage Aviso para el visitante (sin detalles técnicos).
 */
?>
<div class="narrow">
    <div class="panel empty">
        <span class="empty__icon"><?= icon('alert') ?></span>
        <h1><?= escape($errorTitle) ?></h1>
        <p><?= escape($errorMessage) ?></p>
        <a class="btn btn--primary" href="index.php"><?= icon('arrow-left') ?>Volver al listado</a>
    </div>
</div>
