<?php

declare(strict_types=1);

/**
 * Página de "usuario no encontrado" (id inválido, inexistente o eliminado). La página que la incluye ya fijó el
 * código 404 antes de imprimir nada; aquí solo se dibuja el aviso. No espera variables.
 */
?>
<div class="narrow">
    <div class="panel empty">
        <span class="empty__icon"><?= icon('users') ?></span>
        <h1>Usuario no encontrado</h1>
        <p>El usuario que intentas editar no existe, fue eliminado o el enlace no es válido.</p>
        <a class="btn btn--primary" href="index.php"><?= icon('arrow-left') ?>Volver al listado</a>
    </div>
</div>
