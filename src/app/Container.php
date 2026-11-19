<?php

declare(strict_types=1);

namespace App;

use App\Database\Connection;
use App\Repository\AdminRepository;
use App\Repository\UserRepository;
use App\Validation\UserValidator;
use PDO;

/**
 * Construye y entrega las piezas de la aplicación, cada una con sus dependencias (el único sitio que sabe qué
 * lleva qué). La conexión a la BD es PEREZOSA: no se abre hasta que alguien pide un repositorio. Importa porque
 * hay peticiones que se rechazan sin tocar la BD (un id mal formado da 400 aunque la BD esté caída).
 *
 * Es un contenedor mínimo, hecho a mano: con más piezas se cambiaría por uno de Composer (PHP-DI, Symfony).
 */
final class Container
{
    private ?PDO $pdo = null;
    private ?UserRepository $users = null;
    private ?AdminRepository $admins = null;
    private ?UserValidator $validator = null;

    /** La conexión (se abre la primera vez). @throws \App\Exceptions\DatabaseConnectionException */
    public function pdo(): PDO
    {
        return $this->pdo ??= Connection::create();
    }

    public function users(): UserRepository
    {
        return $this->users ??= new UserRepository($this->pdo());
    }

    public function admins(): AdminRepository
    {
        return $this->admins ??= new AdminRepository($this->pdo());
    }

    public function validator(): UserValidator
    {
        return $this->validator ??= new UserValidator();
    }
}
