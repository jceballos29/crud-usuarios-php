<?php

declare(strict_types=1);

/**
 * Crea un administrador (quien puede iniciar sesión en la aplicación). Solo consola:
 *
 *     bin/as-migrator php scripts/create-admin.php correo@ejemplo.com          # pide la contraseña
 *     bin/as-migrator php scripts/create-admin.php correo@ejemplo.com < clave.txt   # la lee de la entrada
 *     bin/as-migrator php scripts/create-admin.php correo@ejemplo.com 'la-clave'      # por argumento (¡ver abajo!)
 *
 * Se ejecuta con bin/as-migrator (el usuario de MIGRACIONES de la BD) y no con `docker compose exec php ...`: la
 * aplicación se conecta con un usuario que, por mínimo privilegio, NO puede insertar administradores (database/03-users.sh).
 * Así, aunque alguien lograra ejecutar SQL desde la aplicación, no podría darse de alta como administrador.
 *
 * Por qué un script y no un formulario de registro: quien se registrara solo podría administrar a todos.
 * Los administradores los crea quien tiene acceso al servidor.
 *
 * La contraseña como ARGUMENTO queda en el historial del shell y en la lista de procesos (ps) mientras el
 * comando corre: úsalo solo en pruebas. Lo normal es dejar que la pida (sin mostrarla si hay terminal) o
 * pasarla por la entrada estándar. Lo que se guarda en la BD es un hash (bcrypt), nunca la contraseña.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../bootstrap.php';

use App\Auth\AdminAuth;
use App\Database\Connection;
use App\Repository\AdminRepository;

$args = array_slice($argv, 1);

if ($args === [] || count($args) > 2 || in_array($args[0], ['-h', '--help'], true)) {
    fwrite(STDERR, "Uso: php scripts/create-admin.php <correo> [<contraseña>]\n");
    exit($args === [] || count($args) > 2 ? 2 : 0);
}

$email = AdminAuth::normalizeEmail($args[0]);

if (isset($args[1])) {
    $password = $args[1];
    fwrite(STDERR, "Aviso: una contraseña como argumento queda en el historial y en la lista de procesos.\n");
} else {
    $interactive = function_exists('posix_isatty') && posix_isatty(STDIN);

    if ($interactive) {
        fwrite(STDERR, 'Contraseña (no se muestra): ');
        shell_exec('stty -echo 2>/dev/null');
    }

    $line = fgets(STDIN);

    if ($interactive) {
        shell_exec('stty echo 2>/dev/null');
        fwrite(STDERR, "\n");
    }

    if ($line === false) {
        fwrite(STDERR, "No se recibió ninguna contraseña.\n");
        exit(2);
    }

    $password = rtrim($line, "\r\n");
}

$errors = AdminAuth::validateNew($email, $password);

if ($errors !== []) {
    foreach ($errors as $message) {
        fwrite(STDERR, "Error: $message\n");
    }
    exit(1);
}

try {
    (new AdminRepository(Connection::create()))->create($email, password_hash($password, PASSWORD_DEFAULT));
} catch (RuntimeException $error) {
    if ($error instanceof PDOException && ($error->errorInfo[1] ?? null) === 1062) {
        fwrite(STDERR, "Error: ya existe un administrador con ese correo.\n");
        exit(1);
    }

    if ($error instanceof PDOException && in_array($error->errorInfo[1] ?? null, [1142, 1044], true)) {
        fwrite(STDERR, "Error: el usuario de la BD de la aplicación no puede insertar administradores (es lo esperado).\nEjecútalo con el usuario de migraciones: bin/as-migrator php scripts/create-admin.php <correo>\n");
        exit(1);
    }

    fwrite(STDERR, 'Error: no se pudo crear el administrador (' . $error->getMessage() . ")\n");
    exit(1);
}

echo "Administrador creado: $email\n";
