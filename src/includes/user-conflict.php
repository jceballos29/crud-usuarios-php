<?php

declare(strict_types=1);

/**
 * Con borrado lógico (M4) los UNIQUE de email y document siguen cubriendo también a los usuarios
 * eliminados: «ya existe» puede significar que el dueño está en la papelera. Esta consulta lo averigua
 * para dar un aviso útil («restáuralo») en lugar de uno que parece mentira (el listado no lo muestra).
 */

/**
 * ¿El usuario que ocupa ese correo o documento está eliminado?
 *
 * @param PDO $pdo Conexión.
 * @param string $field 'email' o 'document' (lo que devuelve duplicate_user_field()).
 * @param string $value Valor ya normalizado, tal como se intentó guardar.
 * @return bool true si el dueño existe y está eliminado; false si está activo, no existe o la consulta falla.
 */
function conflicting_user_is_inactive(PDO $pdo, string $field, string $value): bool
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
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':value' => $value]);
        $active = $stmt->fetchColumn();
    } catch (PDOException) {
        return false; // el aviso genérico de duplicado sigue siendo cierto
    }

    return $active !== false && (int) $active === 0;
}

/**
 * Mensaje de «ya existe» para un correo o documento duplicado.
 *
 * @param string $field 'email' o 'document'.
 * @param bool $other true en la edición («otro usuario»), false en el alta.
 * @param bool $inactive true si el dueño está eliminado.
 */
function duplicate_message(string $field, bool $other, bool $inactive): string
{
    $noun = $field === 'email' ? 'correo' : 'documento';

    if ($inactive) {
        return sprintf('Ese %s pertenece a un usuario eliminado. Restáuralo desde «Eliminados» o usa otro %s.', $noun, $noun);
    }

    return sprintf($other ? 'Ya existe otro usuario registrado con ese %s.' : 'Ya existe un usuario registrado con ese %s.', $noun);
}
