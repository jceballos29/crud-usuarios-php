<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Un usuario del CRUD (la entidad): sus datos, tal como están en la tabla `users`, con tipos. Es inmutable
 * (`readonly`): para «cambiar» a un usuario se guarda con el repositorio y se vuelve a leer. No sabe de SQL ni
 * de HTML: quien lo guarda es UserRepository y quien lo muestra son las vistas (views/).
 */
final class User
{
    /**
     * Rango válido de users.id. Coincide con la columna `id INT UNSIGNED AUTO_INCREMENT`
     * de database/01-schema.sql: el primer id real es 1 y el máximo de INT UNSIGNED es
     * 4294967295. Si la columna pasa a BIGINT, este es el único sitio de PHP que cambia
     * (y recuerda que un int de PHP llega a PHP_INT_MAX, 9223372036854775807, y no al
     * máximo de BIGINT UNSIGNED).
     */
    public const ID_MIN = 1;
    public const ID_MAX = 4294967295;

    /** Rol de un usuario nuevo (el DEFAULT de la columna). */
    public const ROLE_DEFAULT = 'user';

    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $lastName,
        public readonly string $email,
        public readonly ?string $phone,
        public readonly ?string $document,
        public readonly ?string $birthDate,
        public readonly string $role,
        public readonly bool $active,
        public readonly string $createdAt,
        public readonly bool $hasAccess = false,
    ) {
    }

    /**
     * Crea el usuario desde una fila de la BD (las columnas llegan como texto o entero según el controlador).
     *
     * @param array<string, mixed> $row Fila de users (todas las columnas).
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['name'],
            (string) $row['last_name'],
            (string) $row['email'],
            isset($row['phone']) ? (string) $row['phone'] : null,
            isset($row['document']) ? (string) $row['document'] : null,
            isset($row['birth_date']) ? (string) $row['birth_date'] : null,
            (string) $row['role'],
            (bool) ($row['active'] ?? true),
            (string) ($row['created_at'] ?? ''),
            (bool) ($row['has_access'] ?? false),
        );
    }

    /**
     * Las iniciales para el «guijarro» de la interfaz: la primera letra del nombre y la del apellido, en mayúsculas
     * («Laura Gómez» -> «LG»). Cuenta letras, no bytes: «Ángela» da «Á».
     */
    public function initials(): string
    {
        $first = static fn(string $text): string => mb_strtoupper(mb_substr(ltrim($text), 0, 1, 'UTF-8'), 'UTF-8');

        return $first($this->name) . $first($this->lastName);
    }

    /**
     * Uno de los 6 tonos de color del guijarro (1 a 6), siempre el mismo para la misma persona: sale del correo, que
     * es único. No es un dato guardado ni un secreto: solo una forma estable de repartir colores.
     */
    public function tone(): int
    {
        return crc32(mb_strtolower($this->email, 'UTF-8')) % 6 + 1;
    }

    /** Nombre y apellido juntos, como se muestra («Laura Gómez»). */
    public function fullName(): string
    {
        return $this->name . ' ' . $this->lastName;
    }

    /**
     * Los campos del formulario con los valores de este usuario (lo opcional que es NULL, como texto vacío: el
     * formulario espera texto).
     *
     * @return array<string, string>
     */
    public function formData(): array
    {
        return [
            'name' => $this->name,
            'last_name' => $this->lastName,
            'email' => $this->email,
            'phone' => $this->phone ?? '',
            'document' => $this->document ?? '',
            'birth_date' => $this->birthDate ?? '',
            'role' => $this->role,
        ];
    }

    /**
     * Huella del contenido de una fila de usuario, para detectar ediciones simultáneas (bloqueo
     * optimista). El formulario de edición la lleva en un campo oculto (row_token); al enviarlo se compara
     * con la huella de la fila tal como está AHORA en la BD: si no coincide, otra persona la modificó
     * mientras se editaba y no se guarda nada. Si dos personas guardan los mismos valores la huella no
     * cambia y no hay conflicto. No es un secreto ni una medida de seguridad: es el "control de versiones"
     * de la fila, sin añadir ninguna columna al esquema.
     *
     * @return string sha256 en hexadecimal (64 caracteres).
     */
    public function rowToken(): string
    {
        $content = json_encode([
            $this->name,
            $this->lastName,
            $this->email,
            $this->phone,
            $this->document,
            $this->birthDate,
            $this->role,
            $this->hasAccess,
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return hash('sha256', $content === false ? '' : $content);
    }

    /**
     * Convierte el id que llega por la petición (GET o POST) en un entero válido.
     *
     * Acepta `mixed` porque la entrada no es de fiar: puede ser un string ("14"), null
     * (no llegó) o un arreglo (?id[]=1). Con `string $raw` y strict_types, null y los
     * arreglos lanzarían TypeError antes de poder validarlos.
     *
     * Solo se admiten dígitos ASCII sin signo, espacios ni ceros a la izquierda, para que
     * cada usuario tenga una sola URL ("+14", " 14" y "014" no valen). Devuelve null
     * cuando no es válido: un id inválido es un resultado normal de datos no confiables,
     * no una situación excepcional, así que no se lanza una excepción.
     *
     * Ejemplo:
     *     parse_user_id('14');      // 14
     *     parse_user_id('+14');     // null
     *     parse_user_id(null);      // null
     *     parse_user_id(['1']);     // null
     *     parse_user_id('0');       // null (fuera de self::ID_MIN..self::ID_MAX)
     *     parse_user_id(14);        // null (un int no es texto de la petición: no se "re-valida")
     *
     * @param mixed $raw Valor crudo de $_GET['id'] / $_POST['id'] (o null si no llegó).
     * @return int|null El id, o null si no es válido.
     */
    public static function parseId(mixed $raw): ?int
    {
        if (!is_string($raw) || !ctype_digit($raw)) {
            return null;
        }

        $id = filter_var($raw, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => self::ID_MIN, 'max_range' => self::ID_MAX],
        ]);

        return $id === false ? null : $id;
    }

    /**
     * Roles válidos: clave guardada en la BD => etiqueta que ve el usuario. Es la **lista blanca**: el valor
     * que llega del <select> es texto del visitante (se puede cambiar con F12 o con curl) y solo se acepta si
     * es una de estas claves. Debe coincidir con el ENUM de `role` en database/01-schema.sql; una prueba
     * de integración lo comprueba contra la BD.
     *
     * @return array<string, string>
     */
    public static function roles(): array
    {
        return ['user' => 'Usuario', 'editor' => 'Editor', 'admin' => 'Administrador'];
    }

    /** Etiqueta de un rol para mostrarla (la clave misma si no está en la lista). */
    public static function roleLabel(string $role): string
    {
        return self::roles()[$role] ?? $role;
    }
}
