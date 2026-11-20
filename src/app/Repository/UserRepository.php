<?php

declare(strict_types=1);

namespace App\Repository;

use App\Auth\AdminAuth;
use App\Domain\User;
use App\Exceptions\DuplicateUserException;
use App\Search\UserSearch;
use PDO;
use PDOException;
use PDOStatement;

/**
 * Acceso a la tabla users: el ÚNICO lugar de la aplicación con SQL de usuarios. Los controladores le piden cosas
 * («la página 2 de los eliminados», «elimina al 14») y reciben objetos User, sin saber qué consulta hay detrás.
 *
 * Recibe la conexión ya hecha en el constructor (inyección de dependencias): no sabe cómo se conecta, así que
 * en las pruebas se le puede dar otra (una tabla temporal, otra base) sin tocar nada. Con `global $pdo` o
 * un `require` que cree la conexión dentro, cada prueba tendría que preparar el entorno entero.
 */
final class UserRepository
{
    // has_access: ¿puede iniciar sesión? Solo se lee si hay contraseña; el hash NUNCA sale de la BD hacia las pantallas.
    private const USER_COLUMNS = 'id, name, last_name, email, phone, document, birth_date, role, (password_hash IS NOT NULL) AS has_access, active, created_at';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Cuántos usuarios cumplen el filtro (hace falta para saber cuántas páginas hay).
     *
     * @param string $term Término ya limpio con UserSearch::parseTerm(), o '' para no filtrar por texto.
     * @param bool $active true = usuarios visibles; false = los eliminados.
     */
    public function count(string $term, bool $active): int
    {
        [$sql, $params] = UserSearch::countQuery($term, $active);

        return (int) $this->run($sql, $params)->fetchColumn();
    }

    /**
     * UNA página del listado, ordenada por id descendente.
     *
     * @param int $limit Filas por página.
     * @param int $offset Filas que se saltan.
     * @return list<User>
     */
    public function page(string $term, bool $active, int $limit, int $offset): array
    {
        [$sql, $params] = UserSearch::listQuery($term, $limit, $offset, $active);

        return array_map(
            static fn(array $row): User => User::fromRow($row + ['active' => $active ? 1 : 0]),
            $this->run($sql, $params)->fetchAll()
        );
    }

    /** Un usuario VISIBLE por su id, o null si no existe o está eliminado (un eliminado no se edita: se restaura). */
    public function findActive(int $id): ?User
    {
        $row = $this->run('SELECT ' . self::USER_COLUMNS . ' FROM users WHERE id = :id AND active = 1', [':id' => $id])->fetch();

        return $row === false ? null : User::fromRow($row);
    }

    /**
     * Crea un usuario.
     *
     * Si el rol es «admin» y viene una contraseña, se guarda su HASH y esa persona podrá iniciar sesión; con
     * cualquier otro rol la contraseña se ignora (no se guarda nada).
     *
     * @param array<string, string> $data Datos ya validados (UserValidator::read() y validate()).
     * @throws DuplicateUserException Si el correo o el documento ya los tiene otro usuario.
     */
    public function create(array $data): void
    {
        $access = ($data['role'] ?? '') === 'admin' && ($data['password'] ?? '') !== '';
        $sql = $access
            ? 'INSERT INTO users (name, last_name, email, phone, document, birth_date, role, password_hash) VALUES (:name, :last_name, :email, :phone, :document, :birth_date, :role, :password_hash)'
            : 'INSERT INTO users (name, last_name, email, phone, document, birth_date, role) VALUES (:name, :last_name, :email, :phone, :document, :birth_date, :role)';

        try {
            $this->run($sql, self::params($data) + ($access ? [':password_hash' => AdminAuth::hash($data['password'])] : []));
        } catch (PDOException $error) {
            throw $this->asDuplicate($error, $data) ?? $error;
        }
    }

    /**
     * Actualiza un usuario VISIBLE. Sobre el ACCESO (users.password_hash):
     *  - rol distinto de «admin»: se BORRA el hash (quien deja de ser administrador deja de poder entrar);
     *  - rol «admin» con contraseña nueva: se guarda su hash;
     *  - rol «admin» sin contraseña nueva: el hash que había se conserva.
     *
     * @param array<string, string> $data Datos ya validados.
     * @return bool false si el usuario ya no existe (o está eliminado): rowCount() cuenta las filas que
     *              coinciden con el WHERE aunque los valores no cambien (ATTR_FOUND_ROWS), así que 0 significa
     *              «ya no está».
     * @throws DuplicateUserException Si el correo o el documento ya los tiene OTRO usuario.
     */
    public function update(int $id, array $data): bool
    {
        $isAdmin = ($data['role'] ?? '') === 'admin';
        $newPassword = $isAdmin && ($data['password'] ?? '') !== '';
        $access = match (true) {
            !$isAdmin => ', password_hash = NULL',
            $newPassword => ', password_hash = :password_hash',
            default => '',
        };
        $sql = 'UPDATE users SET name = :name, last_name = :last_name, email = :email, phone = :phone, document = :document, birth_date = :birth_date, role = :role' . $access . ' WHERE id = :id AND active = 1';

        try {
            $params = self::params($data) + [':id' => $id] + ($newPassword ? [':password_hash' => AdminAuth::hash($data['password'])] : []);

            return $this->run($sql, $params)->rowCount() > 0;
        } catch (PDOException $error) {
            throw $this->asDuplicate($error, $data) ?? $error;
        }
    }

    /**
     * «Elimina» a un usuario: borrado LÓGICO (active = 0), la fila se conserva. El `AND active = 1` hace que
     * eliminar a quien ya estaba eliminado no cuente como una eliminación.
     *
     * @return bool true si esta llamada lo eliminó; false si ya no existía o ya estaba eliminado.
     */
    public function deactivate(int $id): bool
    {
        return $this->run('UPDATE users SET active = 0 WHERE id = :id AND active = 1', [':id' => $id])->rowCount() > 0;
    }

    /**
     * Restaura a un usuario eliminado (active = 1). Nunca choca con un UNIQUE: la fila conserva su correo y su
     * documento y nadie más pudo ocuparlos.
     *
     * @return bool true si esta llamada lo restauró; false si ya estaba activo o no existe.
     */
    public function restore(int $id): bool
    {
        return $this->run('UPDATE users SET active = 1 WHERE id = :id AND active = 0', [':id' => $id])->rowCount() > 0;
    }

    /**
     * Qué campo chocó en un error 1062 de MySQL (entrada duplicada), a partir de su mensaje:
     * "Duplicate entry 'x' for key 'users.unique_document'". Null si la clave no es una de las conocidas.
     *
     * @param string $message PDOException::errorInfo[2] (o el mensaje completo).
     */
    public static function duplicateField(string $message): ?string
    {
        return match (1 === preg_match("/for key '(?:[^'.]+\\.)?([^']+)'/", $message, $m) ? $m[1] : null) {
            'unique_email' => 'email',
            'unique_document' => 'document',
            default => null,
        };
    }

    /**
     * Parámetros para el INSERT y el UPDATE de un usuario ya validado. Lo opcional vacío se guarda como NULL,
     * nunca como '': en el UNIQUE de document varios NULL conviven, pero dos '' chocarían (y una fecha '' es
     * un error de MySQL).
     *
     * @param array<string, string> $data Resultado de UserValidator::read() ya validado.
     * @return array<string, string|null> marcador => valor
     */
    public static function params(array $data): array
    {
        $orNull = static fn(string $value): ?string => $value !== '' ? $value : null;

        return [
            ':name' => $data['name'],
            ':last_name' => $data['last_name'],
            ':email' => $data['email'],
            ':phone' => $orNull($data['phone']),
            ':document' => $orNull($data['document']),
            ':birth_date' => $orNull($data['birth_date']),
            ':role' => $data['role'],
        ];
    }

    /**
     * Si el error es un choque con un UNIQUE conocido (1062), devuelve la excepción de dominio (averiguando si el
     * dueño del valor está eliminado); si no, null y se relanza el original.
     *
     * @param array<string, string> $data Lo que se intentó guardar.
     */
    private function asDuplicate(PDOException $error, array $data): ?DuplicateUserException
    {
        if (($error->errorInfo[1] ?? null) !== 1062) {
            return null;
        }

        $field = self::duplicateField((string) ($error->errorInfo[2] ?? ''));

        return $field === null ? null : new DuplicateUserException($field, $this->ownerIsInactive($field, $data[$field]), $error);
    }

    /**
     * ¿El usuario que ocupa ese correo o documento está eliminado? (Con borrado lógico los UNIQUE siguen
     * cubriendo también a los eliminados: «ya existe» puede significar que el dueño está en la papelera.)
     *
     * @param string $field 'email' o 'document'.
     * @param string $value Valor ya normalizado, tal como se intentó guardar.
     * @return bool false si el dueño está activo, no existe o la consulta falla (el aviso genérico sigue siendo cierto).
     */
    private function ownerIsInactive(string $field, string $value): bool
    {
        // El nombre de la columna sale de una lista cerrada, nunca del visitante.
        $sql = match ($field) {
            'email' => 'SELECT active FROM users WHERE email = :value',
            'document' => 'SELECT active FROM users WHERE document = :value',
            default => null,
        };

        if ($sql === null) {
            return false;
        }

        try {
            $active = $this->run($sql, [':value' => $value])->fetchColumn();
        } catch (PDOException) {
            return false;
        }

        return $active !== false && (int) $active === 0;
    }

    /**
     * Prepara y ejecuta una consulta enlazando cada parámetro con su tipo: los enteros de PHP con PDO::PARAM_INT y
     * todo lo demás como texto (null incluido). Hace falta para LIMIT y OFFSET: ni `execute($params)` ni
     * `bindValue()` sin tipo valen con consultas emuladas, que escribirían `LIMIT '10'` en el SQL.
     *
     * @param array<string, string|int|null> $params Marcador => valor (p. ej. [':limit' => 10]).
     */
    private function run(string $sql, array $params): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);

        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value, match (true) {
                is_int($value) => PDO::PARAM_INT,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            });
        }

        $stmt->execute();

        return $stmt;
    }
}
