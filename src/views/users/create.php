<?php

declare(strict_types=1);

/**
 * Página de alta. Los datos son los del formulario (views/users/form.php).
 *
 * @var array<string, mixed> $form Variables del formulario compartido.
 */
?>
<h1 class="mb-4">Registrar usuario</h1>

<?php App\View\View::render('users/form', $form); ?>
