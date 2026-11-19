<?php

declare(strict_types=1);

/**
 * Lógica compartida de usuarios (create.php, edit.php y delete.php): definición de
 * campos, limpieza de la entrada y validación, incluido el id. PHP puro: no imprime
 * HTML, no toca la BD y no lee superglobales; todo entra por parámetros.
 *
 * El correo duplicado NO se valida aquí: depende de la BD y lo resuelve cada
 * página capturando el error 1062 del UNIQUE.
 */

/**
 * Rango válido de users.id. Coincide con la columna `id INT UNSIGNED AUTO_INCREMENT`
 * de database/01-schema.sql: el primer id real es 1 y el máximo de INT UNSIGNED es
 * 4294967295. Si la columna pasa a BIGINT, este es el único sitio de PHP que cambia
 * (y recuerda que un int de PHP llega a PHP_INT_MAX, 9223372036854775807, y no al
 * máximo de BIGINT UNSIGNED).
 */
const USER_ID_MIN = 1;
const USER_ID_MAX = 4294967295;

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
 *     parse_user_id('0');       // null (fuera de USER_ID_MIN..USER_ID_MAX)
 *     parse_user_id(14);        // null (un int no es texto de la petición: no se "re-valida")
 *
 * @param mixed $raw Valor crudo de $_GET['id'] / $_POST['id'] (o null si no llegó).
 * @return int|null El id, o null si no es válido.
 */
function parse_user_id(mixed $raw): ?int
{
    if (!is_string($raw) || !ctype_digit($raw)) {
        return null;
    }

    $id = filter_var($raw, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => USER_ID_MIN, 'max_range' => USER_ID_MAX],
    ]);

    return $id === false ? null : $id;
}

/**
 * Definición de los campos del formulario de usuario.
 * Los máximos coinciden con las columnas de database/01-schema.sql.
 *
 * @return array<string, array{label: string, type: string, required: bool, max: int, min_digits?: int, max_digits?: int}>
 */
function user_fields(): array
{
    return [
        'name'      => ['label' => 'Nombre',   'type' => 'text',  'required' => true,  'max' => 100],
        'last_name' => ['label' => 'Apellido', 'type' => 'text',  'required' => true,  'max' => 100],
        'email'     => ['label' => 'Email',    'type' => 'email', 'required' => true,  'max' => 254],
        'phone'     => ['label' => 'Teléfono', 'type' => 'text',  'required' => false, 'max' => 20, 'min_digits' => 7, 'max_digits' => 15],
    ];
}

/**
 * Valores vacíos para todos los campos (primera carga del formulario).
 *
 * @param array<string, array<string, mixed>> $fields
 * @return array<string, string>
 */
function empty_user_data(array $fields): array
{
    return array_fill_keys(array_keys($fields), '');
}

/**
 * Limpia la entrada cruda: solo toma las claves definidas, trata como vacío todo
 * lo que no sea string (p. ej. name[]=x), aplica trim y pasa el email a minúsculas.
 *
 * @param array<string, mixed> $source Entrada cruda (p. ej. $_POST).
 * @param array<string, array<string, mixed>> $fields
 * @return array<string, string>
 */
function read_user_input(array $source, array $fields): array
{
    $data = empty_user_data($fields);

    foreach (array_keys($fields) as $fieldName) {
        $value = $source[$fieldName] ?? '';
        $data[$fieldName] = is_string($value) ? trim($value) : '';
    }

    // Normalización: el email se guarda siempre en minúsculas.
    $data['email'] = mb_strtolower($data['email'], 'UTF-8');

    return $data;
}

/**
 * Valida los datos ya limpios. Devuelve solo el primer error de cada campo.
 *
 * @param array<string, string> $data Resultado de read_user_input().
 * @param array<string, array<string, mixed>> $fields
 * @return array<string, string> campo => mensaje (vacío si todo es válido)
 */
function validate_user(array $data, array $fields): array
{
    $errors = [];
    $length = static fn(string $value): int => mb_strlen($value, 'UTF-8');

    // Letras (con tildes), espacio literal, apóstrofe, punto y guion: O'Brien, María-José.
    $personName = '/^\p{L}[\p{L}\p{M} \'’.-]*$/u';

    // Nombre y apellido: sin mínimo de longitud (la BD solo exige NOT NULL).
    $personFields = ['name' => 'nombre', 'last_name' => 'apellido'];
    foreach ($personFields as $fieldName => $noun) {
        $max = $fields[$fieldName]['max'];

        if ($data[$fieldName] === '') {
            $errors[$fieldName] = sprintf('El %s es obligatorio.', $noun);
        } elseif ($length($data[$fieldName]) > $max) {
            $errors[$fieldName] = sprintf('El %s no debe superar los %d caracteres.', $noun, $max);
        } elseif (!preg_match($personName, $data[$fieldName])) {
            $errors[$fieldName] = sprintf('El %s solo puede contener letras, espacios, apóstrofes, puntos y guiones.', $noun);
        }
    }

    // Email
    if ($data['email'] === '') {
        $errors['email'] = 'El email es obligatorio.';
    } elseif ($length($data['email']) > $fields['email']['max']) {
        $errors['email'] = sprintf('El email no debe superar los %d caracteres.', $fields['email']['max']);
    } elseif (filter_var($data['email'], FILTER_VALIDATE_EMAIL) === false) {
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

    return $errors;
}
