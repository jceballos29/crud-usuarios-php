<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * El acceso a la aplicación: quién puede iniciar sesión. Desde la migración 004 no hay una tabla aparte: un
 * administrador es un USUARIO con rol «admin», activo y con contraseña (users.password_hash). Así, cambiar su
 * correo o su rol, o eliminarlo, cambia también su acceso, sin sincronizar dos tablas.
 *
 * Recibe la conexión ya hecha (inyección de dependencias): no sabe cómo se conecta.
 */
final class AdminRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * El administrador con ese correo SI puede entrar: rol admin, activo y con contraseña. Cualquier otro caso (no
     * existe, es un editor, está eliminado, no tiene contraseña) da null, igual que un correo inexistente: el
     * login no distingue ninguno de ellos.
     *
     * @param string $email Correo ya normalizado (AdminAuth::normalizeEmail()).
     * @return array{id: int, email: string, password_hash: string}|null
     */
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, email, password_hash FROM users WHERE email = :email AND role = 'admin' AND active = 1 AND password_hash IS NOT NULL"
        );
        $stmt->execute([':email' => $email]);
        $row = $stmt->fetch();

        return $row === false ? null : ['id' => (int) $row['id'], 'email' => (string) $row['email'], 'password_hash' => (string) $row['password_hash']];
    }

    /** Cambia el hash de quien entra (al recalcularlo con un coste o algoritmo más nuevos). */
    public function updatePasswordHash(int $id, string $hash): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
        $stmt->execute([':hash' => $hash, ':id' => $id]);
    }

    /**
     * Da acceso a un correo desde la consola (create-admin.php): si ya es un usuario lo PROMUEVE (rol admin, activo,
     * con ese hash); si no, crea uno llamado «Administrador» con la parte del correo anterior a la arroba como
     * apellido. Lo usa solo la consola, con el usuario de migraciones.
     *
     * @param string $email Correo ya normalizado.
     * @return bool true si creó un usuario nuevo; false si promovió a uno existente.
     */
    public function grantAccess(string $email, string $passwordHash): bool
    {
        $exists = $this->pdo->prepare('SELECT COUNT(*) FROM users WHERE email = :email');
        $exists->execute([':email' => $email]);

        if ((int) $exists->fetchColumn() > 0) {
            $stmt = $this->pdo->prepare("UPDATE users SET role = 'admin', active = 1, password_hash = :hash WHERE email = :email");
            $stmt->execute([':hash' => $passwordHash, ':email' => $email]);

            return false;
        }

        $stmt = $this->pdo->prepare("INSERT INTO users (name, last_name, email, role, password_hash) VALUES ('Administrador', :last_name, :email, 'admin', :hash)");
        $stmt->execute([':last_name' => mb_substr(explode('@', $email)[0], 0, 100, 'UTF-8'), ':email' => $email, ':hash' => $passwordHash]);

        return true;
    }
}
