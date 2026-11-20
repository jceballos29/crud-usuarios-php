<?php

declare(strict_types=1);

namespace App\Validation;

use App\Auth\AdminAuth;
use App\Domain\User;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Reglas de un usuario (crear y editar usan las MISMAS): qué campos tiene el formulario, cómo se limpia lo que
 * llega y qué errores hay. PHP puro: no imprime HTML, no toca la BD y no lee superglobales; todo entra por
 * parámetros, así que se prueba sola (src/tests/unit.php).
 *
 * El correo y el documento duplicados NO se validan aquí: dependen de la BD y los resuelve UserRepository
 * (lanza DuplicateUserException al chocar con el UNIQUE).
 */
final class UserValidator
{
    /** Fecha de nacimiento más antigua que se acepta (nadie vivo nació antes). */
    public const BIRTH_DATE_MIN = '1900-01-01';

    /**
     * @param DateTimeImmutable|null $today «Hoy» (UTC) para comparar la fecha de nacimiento; null = ahora.
     *                                      Solo se pasa en las pruebas (un reloj fijo).
     */
    public function __construct(private readonly ?DateTimeImmutable $today = null)
    {
    }

    /**
     * Definición de los campos del formulario de usuario.
     * Los máximos coinciden con las columnas de database/01-schema.sql.
     * `type` 'select' lleva `options` (valor => etiqueta); `default` es el valor que trae un formulario nuevo.
     *
     * @return array<string, array{label: string, type: string, required: bool, max: int, min_digits?: int, max_digits?: int, options?: array<string, string>, default?: string}>
     */
    public function fields(): array
    {
        return [
            'name'       => ['label' => 'Nombre',   'type' => 'text',  'required' => true,  'max' => 100],
            'last_name'  => ['label' => 'Apellido', 'type' => 'text',  'required' => true,  'max' => 100],
            'email'      => ['label' => 'Email',    'type' => 'email', 'required' => true,  'max' => 254],
            'phone'      => ['label' => 'Teléfono', 'type' => 'tel',   'required' => false, 'max' => 20, 'min_digits' => 7, 'max_digits' => 15],
            'document'   => ['label' => 'Documento de identidad', 'type' => 'text', 'required' => false, 'max' => 20],
            'birth_date' => ['label' => 'Fecha de nacimiento',    'type' => 'date', 'required' => false, 'max' => 10],
            'role'       => ['label' => 'Rol', 'type' => 'select', 'required' => true, 'max' => 0, 'options' => User::roles(), 'default' => User::ROLE_DEFAULT],
            // Solo para el rol administrador (la vista los enseña solo con ese rol). Nunca se vuelven a pintar.
            'password' => ['label' => 'Contraseña', 'type' => 'password', 'required' => false, 'max' => AdminAuth::PASSWORD_MAX_BYTES, 'gate' => 'admin'],
            'password_confirm' => ['label' => 'Repite la contraseña', 'type' => 'password', 'required' => false, 'max' => AdminAuth::PASSWORD_MAX_BYTES, 'gate' => 'admin'],
        ];
    }

    /**
     * Valores vacíos para todos los campos (primera carga del formulario).
     *
     * @return array<string, string>
     */
    public function emptyData(): array
    {
        $data = [];

        foreach ($this->fields() as $fieldName => $definition) {
            $data[$fieldName] = $definition['default'] ?? '';
        }

        return $data;
    }

    /**
     * Limpia la entrada cruda: solo toma las claves definidas, trata como vacío todo
     * lo que no sea string (p. ej. name[]=x), aplica trim y pasa el email a minúsculas.
     *
     * @param array<string, mixed> $source Entrada cruda (p. ej. $_POST).
     * @return array<string, string>
     */
    public function read(array $source): array
    {
        $data = $this->emptyData();

        foreach (array_keys($this->fields()) as $fieldName) {
            $value = $source[$fieldName] ?? '';
            // Si no llega (o no es texto) un campo con valor por defecto se queda vacío, NO con el valor por
            // defecto: un envío sin rol no debe convertirse en un rol por sorpresa; validate_user() lo rechaza.
            // Las contraseñas NO se recortan: un espacio al principio o al final es parte de la contraseña.
            $data[$fieldName] = is_string($value) ? (str_starts_with($fieldName, 'password') ? $value : trim($value)) : '';
        }

        // Normalización: el email se guarda siempre en minúsculas.
        $data['email'] = mb_strtolower($data['email'], 'UTF-8');
        // ...y el documento en mayúsculas ("ab-123" y "AB-123" son el mismo, y el UNIQUE debe verlo así).
        $data['document'] = mb_strtoupper($data['document'], 'UTF-8');

        return $data;
    }

    /**
     * Valida los datos ya limpios. Devuelve solo el primer error de cada campo.
     *
     * @param array<string, string> $data Resultado de read().
     * @param bool $requirePassword true si un administrador DEBE traer contraseña (alta, o ascender a alguien a ese rol);
     *                              false al editar a quien ya es administrador (vacía = conservar la actual).
     * @return array<string, string> campo => mensaje (vacío si todo es válido)
     */
    public function validate(array $data, bool $requirePassword = true): array
    {
        $fields = $this->fields();
        $errors = [];
        $length = static fn(string $value): int => mb_strlen($value, 'UTF-8');

        // Nombre y apellido: permisivo a propósito. Una validación que rechaza datos legítimos es un
        // fallo, y la BD solo exige NOT NULL. Basta con (1) al menos una letra de cualquier alfabeto y
        // (2) ningún carácter de control (saltos de línea, tabuladores, NUL). Así valen Pere Cal·lís,
        // Smith, Jr., O'Brien o 李雷, y también los nombres con ZWNJ (persa, por ejemplo), que una lista
        // de caracteres permitidos o \p{C} rechazarían. El escape al mostrar (escape()) es lo que
        // protege del XSS, no esta regla. Un UTF-8 inválido hace fallar preg_match y también se rechaza.
        $personName = '/^(?=.*\p{L})[^\p{Cc}]+$/u';

        // Nombre y apellido: sin mínimo de longitud (la BD solo exige NOT NULL).
        $personFields = ['name' => 'nombre', 'last_name' => 'apellido'];
        foreach ($personFields as $fieldName => $noun) {
            $max = $fields[$fieldName]['max'];

            if ($data[$fieldName] === '') {
                $errors[$fieldName] = sprintf('El %s es obligatorio.', $noun);
            } elseif ($length($data[$fieldName]) > $max) {
                $errors[$fieldName] = sprintf('El %s no debe superar los %d caracteres.', $noun, $max);
            } elseif (!preg_match($personName, $data[$fieldName])) {
                $errors[$fieldName] = sprintf('El %s debe incluir al menos una letra y no puede contener saltos de línea ni caracteres de control.', $noun);
            }
        }

        // Email
        if ($data['email'] === '') {
            $errors['email'] = 'El email es obligatorio.';
        } elseif ($length($data['email']) > $fields['email']['max']) {
            $errors['email'] = sprintf('El email no debe superar los %d caracteres.', $fields['email']['max']);
        } elseif (filter_var($data['email'], FILTER_VALIDATE_EMAIL, FILTER_FLAG_EMAIL_UNICODE) === false) {
            $errors['email'] = 'Ingresa un email válido.';
        }

        // Teléfono (opcional): longitud total acorde a la columna, solo caracteres de teléfono
        // y entre min_digits y max_digits dígitos reales (E.164 admite hasta 15).
        if ($data['phone'] !== '') {
            ['max' => $max, 'min_digits' => $minDigits, 'max_digits' => $maxDigits] = $fields['phone'];
            $digits = strlen(preg_replace('/\D/', '', $data['phone']));

            if ($length($data['phone']) > $max) {
                $errors['phone'] = sprintf('El teléfono no debe superar los %d caracteres.', $max);
            } elseif (!preg_match('/^\+?[0-9 ()-]+$/', $data['phone'])) {
                $errors['phone'] = 'El teléfono solo puede contener números, espacios, paréntesis y guiones (con + opcional al inicio).';
            } elseif ($digits < $minDigits || $digits > $maxDigits) {
                $errors['phone'] = sprintf('El teléfono debe tener entre %d y %d dígitos.', $minDigits, $maxDigits);
            }
        }

        // Documento (opcional): letras, números y guiones; empieza por letra o número. Es texto, no número:
        // admite ceros a la izquierda y letras (pasaportes). Ya está en mayúsculas (read_user_input).
        if ($data['document'] !== '') {
            $max = $fields['document']['max'];

            if ($length($data['document']) > $max) {
                $errors['document'] = sprintf('El documento no debe superar los %d caracteres.', $max);
            } elseif (!preg_match('/^[A-Z0-9][A-Z0-9-]{4,}$/', $data['document'])) {
                $errors['document'] = 'El documento debe tener al menos 5 caracteres: letras, números y guiones, sin espacios.';
            }
        }

        // Fecha de nacimiento (opcional): formato AAAA-MM-DD (lo que envía <input type="date">), una fecha
        // que existe, no futura y no anterior a BIRTH_DATE_MIN.
        if ($data['birth_date'] !== '') {
            $error = self::birthDateError($data['birth_date'], $this->today ?? new DateTimeImmutable('now', new DateTimeZone('UTC')));

            if ($error !== null) {
                $errors['birth_date'] = $error;
            }
        }

        // Rol: obligatorio y SOLO uno de la lista blanca (comparación estricta, sin conversiones de tipo).
        if (!array_key_exists($data['role'], $fields['role']['options'])) {
            $errors['role'] = 'Selecciona un rol de la lista.';
        }

        // Contraseña: solo cuenta con el rol administrador (con cualquier otro se ignora). Tiene que cumplir las reglas
        // de siempre (AdminAuth::passwordError()) y coincidir con su repetición. Es obligatoria al crear a un
        // administrador o al ascender a alguien a ese rol ($requirePassword); al editar a quien ya lo es, vacía
        // significa «conservar la actual».
        if ($data['role'] === 'admin') {
            if ($data['password'] === '') {
                if ($requirePassword) {
                    $errors['password'] = 'Escribe una contraseña para que pueda iniciar sesión.';
                }
            } elseif (($passwordError = AdminAuth::passwordError($data['password'])) !== null) {
                $errors['password'] = $passwordError;
            } elseif ($data['password'] !== $data['password_confirm']) {
                $errors['password_confirm'] = 'Las contraseñas no coinciden.';
            }
        }

        return $errors;
    }

    /**
     * Comprueba una fecha de nacimiento escrita como AAAA-MM-DD. DateTimeImmutable::createFromFormat no
     * rechaza "2023-02-31": la corrige a marzo y solo lo avisa en getLastErrors(), así que además se comprueba
     * que al volver a escribirla sale el mismo texto.
     *
     * @param string $value Texto ya limpio (no vacío).
     * @param DateTimeImmutable $today Hoy en UTC (la zona de la aplicación y de la BD).
     * @return string|null El mensaje de error, o null si es válida.
     */
    private static function birthDateError(string $value, DateTimeImmutable $today): ?string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        $problems = DateTimeImmutable::getLastErrors();

        if ($date === false || ($problems !== false && ($problems['warning_count'] > 0 || $problems['error_count'] > 0)) || $date->format('Y-m-d') !== $value) {
            return 'Ingresa una fecha válida con el formato AAAA-MM-DD.';
        }

        if ($date > $today) {
            return 'La fecha de nacimiento no puede ser futura.';
        }

        if ($date < new DateTimeImmutable(self::BIRTH_DATE_MIN, new DateTimeZone('UTC'))) {
            return 'La fecha de nacimiento no puede ser anterior al 1 de enero de 1900.';
        }

        return null;
    }
}
