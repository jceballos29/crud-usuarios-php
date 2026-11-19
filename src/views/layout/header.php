<?php

declare(strict_types=1);

/**
 * Cabecera común de las páginas (hasta <main>). Variables (las pasa View::page()):
 *
 * @var string $pageTitle Título de la página.
 * @var array{id: int, email: string}|null $currentAdmin El administrador conectado (null en el login).
 */

$page = $pageTitle ?? "Inicio";
$title = "Gestión de usuarios | " . $page;
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light dark">
    <title><?= escape($title); ?></title>
    <link rel="stylesheet" href="<?= escape(asset('css/app.css')) ?>">
</head>

<body>
    <a class="skip-link" href="#contenido">Saltar al contenido</a>
    <header class="topbar">
        <div class="shell topbar__inner">
            <a class="brand" href="index.php">
                <svg class="brand__mark" aria-hidden="true" focusable="false"><use href="<?= escape(asset('img/icons.svg')) ?>#brand"></use></svg>
                <span>Gestión de usuarios</span>
            </a>
            <?php if (($currentAdmin ?? null) !== null): ?>
                <?php /* Quién está conectado y cómo salir. Cerrar sesión es un POST (ver logout.php). */ ?>
                <form method="post" action="logout.php" class="session">
                    <?= csrf_field() ?>
                    <span class="session__who">
                        <span class="pebble pebble--sm" aria-hidden="true"><?= escape(mb_strtoupper(mb_substr($currentAdmin['email'], 0, 1, 'UTF-8'), 'UTF-8')) ?></span>
                        <span class="visually-hidden">Conectado como</span>
                        <span class="session__email"><?= escape($currentAdmin['email']) ?></span>
                    </span>
                    <button type="submit" class="btn btn--quiet"><?= icon('logout') ?><span>Cerrar sesión</span></button>
                </form>
            <?php endif; ?>
        </div>
    </header>
    <main class="main" id="contenido" tabindex="-1">
        <div class="shell">
