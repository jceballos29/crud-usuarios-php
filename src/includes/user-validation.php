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
 * Huella del contenido de una fila de usuario, para detectar ediciones simultáneas (bloqueo
 * optimista). El formulario de edición la lleva en un campo oculto (row_token); al enviarlo se compara
 * con la huella de la fila tal como está AHORA en la BD: si no coincide, otra persona la modificó
 * mientras se editaba y no se guarda nada. Si dos personas guardan los mismos valores la huella no
 * cambia y no hay conflicto. No es un secreto ni una medida de seguridad: es el "control de versiones"
 * de la fila, sin añadir ninguna columna al esquema.
 *
 * @param array{name: mixed, last_name: mixed, email: mixed, phone: mixed, document: mixed, birth_date: mixed, role: mixed} $row Fila de la tabla users.
 * @return string sha256 en hexadecimal (64 caracteres).
 */
function user_row_token(array $row): string
{
    $content = json_encode([
        (string) $row['name'],
        (string) $row['last_name'],
        (string) $row['email'],
        $row['phone'] === null ? null : (string) $row['phone'],
        $row['document'] === null ? null : (string) $row['document'],
        $row['birth_date'] === null ? null : (string) $row['birth_date'],
        (string) $row['role'],
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

    return hash('sha256', $content === false ? '' : $content);
}

/** Fecha de nacimiento más antigua que se acepta (nadie vivo nació antes). */
const BIRTH_DATE_MIN = '1900-01-01';

/**
 * Roles válidos: clave guardada en la BD => etiqueta que ve el usuario. Es la **lista blanca**: el valor
 * que llega del <select> es texto del visitante (se puede cambiar con F12 o con curl) y solo se acepta si
 * es una de estas claves. Debe coincidir con el ENUM de `role` en database/01-schema.sql; una prueba
 * de integración lo comprueba contra la BD.
 *
 * @return array<string, string>
 */
function user_roles(): array
{
    return ['user' => 'Usuario', 'editor' => 'Editor', 'admin' => 'Administrador'];
}

/** Rol de un usuario nuevo (el DEFAULT de la columna). */
const USER_ROLE_DEFAULT = 'user';

/**
 * Definición de los campos del formulario de usuario.
 * Los máximos coinciden con las columnas de database/01-schema.sql.
 * `type` 'select' lleva `options` (valor => etiqueta); `default` es el valor que trae un formulario nuevo.
 *
 * @return array<string, array{label: string, type: string, required: bool, max: int, min_digits?: int, max_digits?: int, options?: array<string, string>, default?: string}>
 */
function user_fields(): array
{
    return [
        'name'       => ['label' => 'Nombre',   'type' => 'text',  'required' => true,  'max' => 100],
        'last_name'  => ['label' => 'Apellido', 'type' => 'text',  'required' => true,  'max' => 100],
        'email'      => ['label' => 'Email',    'type' => 'email', 'required' => true,  'max' => 254],
        'phone'      => ['label' => 'Teléfono', 'type' => 'tel',   'required' => false, 'max' => 20, 'min_digits' => 7, 'max_digits' => 15],
        'document'   => ['label' => 'Documento de identidad', 'type' => 'text', 'required' => false, 'max' => 20],
        'birth_date' => ['label' => 'Fecha de nacimiento',    'type' => 'date', 'required' => false, 'max' => 10],
        'role'       => ['label' => 'Rol', 'type' => 'select', 'required' => true, 'max' => 0, 'options' => user_roles(), 'default' => USER_ROLE_DEFAULT],
    ];
}

/**
 * Parámetros para el INSERT y el UPDATE de un usuario ya validado. Lo opcional vacío se guarda como NULL,
 * nunca como '': en el UNIQUE de document varios NULL conviven, pero dos '' chocarían (y una fecha '' es
 * un error de MySQL).
 *
 * @param array<string, string> $data Resultado de read_user_input() ya validado.
 * @return array<string, string|null> marcador => valor
 */
function user_db_params(array $data): array
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
 * Qué campo chocó en un error 1062 de MySQL (entrada duplicada), a partir de su mensaje:
 * "Duplicate entry 'x' for key 'users.unique_document'". Null si la clave no es una de las conocidas.
 *
 * @param string $message PDOException::errorInfo[2] (o el mensaje completo).
 */
function duplicate_user_field(string $message): ?string
{
    return match (1 === preg_match("/for key '(?:[^'.]+\\.)?([^']+)'/", $message, $m) ? $m[1] : null) {
        'unique_email' => 'email',
        'unique_document' => 'document',
        default => null,
    };
}

/**
 * Valores vacíos para todos los campos (primera carga del formulario).
 *
 * @param array<string, array<string, mixed>> $fields
 * @return array<string, string>
 */
function empty_user_data(array $fields): array
{
    $data = [];

    foreach ($fields as $fieldName => $definition) {
        $data[$fieldName] = $definition['default'] ?? '';
    }

    return $data;
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
        // Si no llega (o no es texto) un campo con valor por defecto se queda vacío, NO con el valor por
        // defecto: un envío sin rol no debe convertirse en un rol por sorpresa; validate_user() lo rechaza.
        $data[$fieldName] = is_string($value) ? trim($value) : '';
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
 * @param array<string, string> $data Resultado de read_user_input().
 * @param array<string, array<string, mixed>> $fields
 * @param DateTimeImmutable|null $today «Hoy» (UTC) para comparar la fecha de nacimiento; null = ahora. Solo se pasa en las pruebas.
 * @return array<string, string> campo => mensaje (vacío si todo es válido)
 */
function validate_user(array $data, array $fields, ?DateTimeImmutable $today = null): array
{
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
        $error = birth_date_error($data['birth_date'], $today ?? new DateTimeImmutable('now', new DateTimeZone('UTC')));

        if ($error !== null) {
            $errors['birth_date'] = $error;
        }
    }

    // Rol: obligatorio y SOLO uno de la lista blanca (comparación estricta, sin conversiones de tipo).
    if (!array_key_exists($data['role'], $fields['role']['options'])) {
        $errors['role'] = 'Selecciona un rol de la lista.';
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
function birth_date_error(string $value, DateTimeImmutable $today): ?string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
    $problems = DateTimeImmutable::getLastErrors();

    if ($date === false || ($problems !== false && ($problems['warning_count'] > 0 || $problems['error_count'] > 0)) || $date->format('Y-m-d') !== $value) {
        return 'Ingresa una fecha válida con el formato AAAA-MM-DD.';
    }

    if ($date > $today) {
        return 'La fecha de nacimiento no puede ser futura.';
    }

    if ($date < new DateTimeImmutable(BIRTH_DATE_MIN, new DateTimeZone('UTC'))) {
        return 'La fecha de nacimiento no puede ser anterior al 1 de enero de 1900.';
    }

    return null;
}
