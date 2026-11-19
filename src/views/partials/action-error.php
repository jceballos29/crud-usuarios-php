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
<div class="my-8 px-6">
    <h1 class="mb-4"><?= escape($errorTitle) ?></h1>
    <div class="alert theme-danger" role="alert">
        <p><?= escape($errorMessage) ?></p>
    </div>
    <a class="btn-solid theme-primary" href="index.php" role="button">Volver al listado</a>
</div>
