<?php

declare(strict_types=1);

/**
 * Autenticación de administradores (M5): reglas de las credenciales y comprobación contra la BD.
 * La parte pura (normalizar, validar) se prueba sola en src/tests/unit.php; authenticate_admin() necesita
 * una conexión y se prueba en src/tests/integration.php. La sesión (cookie, expiración) está en session.php.
 */

/** Mínimo de caracteres de una contraseña nueva (create-admin.php). */
const ADMIN_PASSWORD_MIN = 10;

/**
 * Máximo de BYTES de una contraseña nueva. bcrypt, el algoritmo de PASSWORD_DEFAULT, solo lee los primeros
 * 72 bytes: con una contraseña más larga, lo que pasa del byte 72 no cuenta. Se rechaza en vez de truncarla
 * en silencio. (Una frase de 72 bytes son unas 70 letras: de sobra.)
 */
const ADMIN_PASSWORD_MAX_BYTES = 72;

/** El único mensaje de un login fallido: no distingue «no existe ese correo» de «contraseña incorrecta». */
const LOGIN_FAILED_MESSAGE = 'Credenciales inválidas.';

/**
 * Un hash bcrypt válido, con el mismo coste que PASSWORD_DEFAULT, de una contraseña que nadie conoce. Si el
 * correo no existe, authenticate_admin() verifica contra este hash igualmente: así «correo inexistente» y
 * «contraseña incorrecta» tardan lo mismo (cientos de milisegundos de bcrypt) y la duración no delata qué
 * correos están registrados. Una prueba comprueba que su coste coincide con el actual.
 */
const DUMMY_PASSWORD_HASH = '$2y$12$PUHBPPZY1SEm.nzzijqx0uFuKT1oiUUFD1Qq1VDus1emy/aiZowMe';

/** Correo de un administrador: sin espacios alrededor y en minúsculas (como el de los usuarios). */
function normalize_admin_email(string $email): string
{
    return mb_strtolower(trim($email), 'UTF-8');
}

/**
 * Valida un administrador NUEVO (la contraseña se elige aquí, no al iniciar sesión).
 *
 * @param string $email Correo ya normalizado con normalize_admin_email().
 * @param string $password Contraseña tal cual se escribió (no se recorta: los espacios cuentan).
 * @return array<string, string> campo => mensaje (vacío si todo es válido)
 */
function validate_new_admin(string $email, string $password): array
{
    $errors = [];

    if ($email === '') {
        $errors['email'] = 'El correo es obligatorio.';
    } elseif (mb_strlen($email, 'UTF-8') > 254) {
        $errors['email'] = 'El correo no debe superar los 254 caracteres.';
    } elseif (filter_var($email, FILTER_VALIDATE_EMAIL, FILTER_FLAG_EMAIL_UNICODE) === false) {
        $errors['email'] = 'Ingresa un correo válido.';
    }

    if (str_contains($password, "\0")) {
        $errors['password'] = 'La contraseña no puede contener el carácter NUL.';
    } elseif (preg_match('//u', $password) !== 1) {
        $errors['password'] = 'La contraseña debe ser UTF-8 válido.';
    } elseif (mb_strlen($password, 'UTF-8') < ADMIN_PASSWORD_MIN) {
        $errors['password'] = sprintf('La contraseña debe tener al menos %d caracteres.', ADMIN_PASSWORD_MIN);
    } elseif (strlen($password) > ADMIN_PASSWORD_MAX_BYTES) {
        $errors['password'] = sprintf('La contraseña no puede superar los %d bytes (límite de bcrypt).', ADMIN_PASSWORD_MAX_BYTES);
    }

    return $errors;
}

/**
 * Comprueba un correo y una contraseña contra la tabla admins.
 *
 * Siempre ejecuta password_verify() (también con un intento descartado por NUL o por largo), exista o no el correo (ver DUMMY_PASSWORD_HASH). Si el hash guardado
 * se hizo con un coste o algoritmo anteriores, lo vuelve a calcular con los actuales (password_needs_rehash):
 * así subir el coste de PASSWORD_DEFAULT mejora las contraseñas existentes en cuanto cada administrador
 * entra. Que falle ese UPDATE no impide el acceso.
 *
 * @param PDO $pdo Conexión.
 * @param string $email Correo tal cual se escribió (se normaliza aquí).
 * @param string $password Contraseña tal cual se escribió.
 * @return array{id: int, email: string}|null El administrador, o null si las credenciales no valen.
 * @throws PDOException Si la BD falla (el que llama decide el 503/500).
 */
function authenticate_admin(PDO $pdo, string $email, string $password): ?array
{
    $email = normalize_admin_email($email);

    $stmt = $pdo->prepare('SELECT id, email, password_hash FROM admins WHERE email = :email');
    $stmt->execute([':email' => $email]);
    $row = $stmt->fetch();

    $hash = $row === false ? DUMMY_PASSWORD_HASH : (string) $row['password_hash'];
    // bcrypt trabaja con cadenas de C: un byte NUL corta la contraseña y solo lee hasta él, así que
    // "clave\0cualquier-cosa" verificaría como "clave"; y solo lee 72 bytes, así que lo que sobra pasa
    // desapercibido. Ninguna contraseña creada por validate_new_admin() tiene NUL ni más de 72 bytes,
    // por lo tanto un intento que los tenga no puede ser correcto: se descarta sin ni siquiera comparar.
    $comparable = strlen($password) <= ADMIN_PASSWORD_MAX_BYTES && !str_contains($password, "\0");
    $valid = password_verify($comparable ? $password : '', $hash) && $comparable;

    if ($row === false || !$valid) {
        return null;
    }

    if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
        try {
            $update = $pdo->prepare('UPDATE admins SET password_hash = :hash WHERE id = :id');
            $update->execute([':hash' => password_hash($password, PASSWORD_DEFAULT), ':id' => $row['id']]);
        } catch (PDOException) {
            // No es motivo para negar la entrada: la contraseña es correcta.
        }
    }

    return ['id' => (int) $row['id'], 'email' => (string) $row['email']];
}
