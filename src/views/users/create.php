<?php

declare(strict_types=1);

/**
 * Página de alta. Los datos son los del formulario (views/users/form.php).
 *
 * @var array<string, mixed> $form Variables del formulario compartido.
 */
?>
<div class="narrow">
    <div class="form-head">
        <h1>Registrar usuario</h1>
    </div>

    <?php App\View\View::render('users/form', $form); ?>
</div>
