<?php

declare(strict_types=1);

/**
 * Diagnóstico del stack.
 *
 * @var list<string> $requiredExtensions
 * @var array{ok: bool, message: string} $database
 */
?>
<div class="narrow stack">
    <h1>Stack PHP en Docker para CRUD de Usuarios</h1>

    <p class="muted">PHP <strong><?= escape(PHP_VERSION) ?></strong> · SAPI: <?= escape(PHP_SAPI) ?> · Entorno: <?= escape((string) getenv('APP_ENV')) ?></p>

    <section class="panel panel--form stack">
        <h2>Extensiones</h2>
        <ul class="list-plain stack">
            <?php foreach ($requiredExtensions as $extension): ?>
                <li>
                    <?= extension_loaded($extension) ? '✔' : '✘' ?> <?= escape($extension) ?>
                    <?= extension_loaded($extension) ? '' : '(falta)' ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>

    <section class="stack">
        <h2>Base de datos</h2>
        <div class="notice notice--<?= $database['ok'] ? 'success' : 'danger' ?>" role="status">
            <span class="notice__icon"><?= icon($database['ok'] ? 'check-circle' : 'alert') ?></span>
            <div class="notice__text"><p><?= escape($database['message']) ?></p></div>
        </div>
    </section>
</div>
