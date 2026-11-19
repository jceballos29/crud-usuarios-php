<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Acceso a la tabla admins: el único lugar con SQL de los administradores. Recibe la conexión ya hecha (inyección
 * de dependencias): no sabe cómo se conecta ni de dónde salen las claves.
 */
final class AdminRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param string $email Correo ya normalizado (AdminAuth::normalizeEmail()).
     * @return array{id: int, email: string, password_hash: string}|null
     */
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, email, password_hash FROM admins WHERE email = :email');
        $stmt->execute([':email' => $email]);
        $row = $stmt->fetch();

        return $row === false ? null : ['id' => (int) $row['id'], 'email' => (string) $row['email'], 'password_hash' => (string) $row['password_hash']];
    }

    /** Cambia solo el hash (es lo único de admins que la aplicación puede modificar: ver database/03-users.sh). */
    public function updatePasswordHash(int $id, string $hash): void
    {
        $stmt = $this->pdo->prepare('UPDATE admins SET password_hash = :hash WHERE id = :id');
        $stmt->execute([':hash' => $hash, ':id' => $id]);
    }

    /**
     * Crea un administrador. Lo usa solo create-admin.php (consola, con el usuario de migraciones: la
     * aplicación web no tiene INSERT en admins).
     *
     * @throws \PDOException Con el código 1062 si el correo ya existe.
     */
    public function create(string $email, string $passwordHash): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO admins (email, password_hash) VALUES (:email, :hash)');
        $stmt->execute([':email' => $email, ':hash' => $passwordHash]);
    }
}
