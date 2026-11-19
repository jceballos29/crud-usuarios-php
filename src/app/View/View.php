<?php

declare(strict_types=1);

namespace App\View;

use InvalidArgumentException;

/**
 * Pinta las vistas de views/: archivos con HTML y la mínima lógica (un `if` y un `foreach`). Una vista no consulta
 * la BD, no lee la petición ni decide códigos HTTP: recibe los datos ya preparados por el controlador, y los usa
 * escapándolos con escape().
 */
final class View
{
    /**
     * Pinta una vista suelta.
     *
     * Cada clave de $data pasa a ser una variable de la vista (`['users' => $users]` -> `$users`). Se incluye dentro
     * de una función para que NO vea las variables del que la llama: solo lo que se le pasa.
     *
     * @param string $name Nombre sin extensión, relativo a views/ (p. ej. 'users/form').
     * @param array<string, mixed> $data
     */
    public static function render(string $name, array $data = []): void
    {
        if (preg_match('#^[a-z0-9]+(?:[/-][a-z0-9]+)*$#', $name) !== 1) {
            throw new InvalidArgumentException("Nombre de vista no válido: $name");
        }

        $file = dirname(__DIR__, 2) . '/views/' . $name . '.php';

        // Los nombres de la función llevan guiones bajos para que no choquen con una variable de la vista
        // (una vista puede recibir una clave `data` o `file`: el formulario recibe `$data`).
        (static function () use ($file, $data): void {
            $__file = $file;
            $__data = $data;
            unset($file, $data);
            extract($__data, EXTR_SKIP);
            unset($__data);
            require $__file;
        })();
    }

    /**
     * Pinta una página completa: la cabecera común, la vista y el pie.
     *
     * @param string $name La vista del contenido.
     * @param array<string, mixed> $data Sus datos.
     * @param string $title Título de la página (va en <title>).
     * @param array{id: int, email: string}|null $admin El administrador conectado (null en el login).
     */
    public static function page(string $name, array $data, string $title, ?array $admin): void
    {
        self::render('layout/header', ['pageTitle' => $title, 'currentAdmin' => $admin]);
        self::render($name, $data);
        self::render('layout/footer');
    }
}
