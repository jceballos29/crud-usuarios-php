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
    <title><?= escape($title); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@6.0.0-alpha.1/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-B/GM4XqrwHnWXNOWMbloTmrYXZg10cakYGmpfsR/bbzQ6JAJI4ihuyADKLnBgrCe" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.2/font/bootstrap-icons.min.css" integrity="sha384-nWN3KqaaVcbEsCjLSV+2jMjh62Iny/+mN3srM8TZeM8N6aj7eDl5wwYggjAJ4/sJ" crossorigin="anonymous">
</head>

<body>
    <header>
        <nav class="navbar bg-2 fg-2">
            <div class="container">
                <a class="navbar-brand" href="index.php">Gestión de usuarios</a>
                <?php if (($currentAdmin ?? null) !== null): ?>
                    <?php /* Quién está conectado y cómo salir. Cerrar sesión es un POST (ver logout.php). */ ?>
                    <form method="post" action="logout.php" class="d-flex align-items-center gap-2">
                        <?= csrf_field() ?>
                        <span>Conectado como <strong><?= escape($currentAdmin['email']) ?></strong></span>
                        <button type="submit" class="btn-outline">Cerrar sesión</button>
                    </form>
                <?php endif; ?>
            </div>
        </nav>
    </header>
    <main class="container my-12 py-12">
