<?php

declare(strict_types=1);

/**
 * Página de "usuario no encontrado" (id inválido o inexistente). La página que la
 * incluye ya fijó el código 404 antes de imprimir nada; aquí solo se dibuja el aviso.
 * No espera variables.
 */
?>
<div class="my-8 px-6">
    <h1 class="mb-4">Usuario no encontrado</h1>
    <div class="alert theme-danger" role="alert">
        <p>El usuario que intentas editar no existe o el enlace no es válido.</p>
    </div>
    <a class="btn-solid theme-primary" href="index.php" role="button">Volver al listado</a>
</div>
