<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Se intentó guardar un usuario con un correo o un documento que ya tiene otro (choque con un UNIQUE).
 * Lo lanza UserRepository; el controlador lo convierte en el aviso del campo y un 409.
 */
final class DuplicateUserException extends RuntimeException
{
    /**
     * @param string $field 'email' o 'document': el campo que chocó.
     * @param bool $ownerInactive true si el dueño del valor está eliminado (borrado lógico): el listado no lo
     *                            enseña, así que el aviso debe explicar que se restaura.
     */
    public function __construct(public readonly string $field, public readonly bool $ownerInactive, ?Throwable $previous = null)
    {
        parent::__construct("Valor duplicado en $field", 0, $previous);
    }

    /**
     * El aviso para el visitante.
     *
     * @param bool $other true en la edición («otro usuario»), false en el alta.
     */
    public function userMessage(bool $other): string
    {
        $noun = $this->field === 'email' ? 'correo' : 'documento';

        if ($this->ownerInactive) {
            return sprintf('Ese %s pertenece a un usuario eliminado. Restáuralo desde «Eliminados» o usa otro %s.', $noun, $noun);
        }

        return sprintf($other ? 'Ya existe otro usuario registrado con ese %s.' : 'Ya existe un usuario registrado con ese %s.', $noun);
    }
}
