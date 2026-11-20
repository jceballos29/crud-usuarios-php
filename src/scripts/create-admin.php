<?php

declare(strict_types=1);

/**
 * Da acceso a un administrador (un usuario con rol admin y contraseña, que puede iniciar sesión). Solo consola. Si el
 * correo ya es un usuario lo PROMUEVE; si no, crea uno. (Los demás administradores también se pueden registrar desde
 * la pantalla «Registrar usuario» con el rol Administrador y una contraseña.)
 *
 *     bin/as-migrator php scripts/create-admin.php correo@ejemplo.com          # pide la contraseña
 *     bin/as-migrator php scripts/create-admin.php correo@ejemplo.com < clave.txt   # la lee de la entrada
 *     bin/as-migrator php scripts/create-admin.php correo@ejemplo.com 'la-clave'      # por argumento (¡ver abajo!)
 *
 * Sirve para crear el PRIMER administrador (o recuperar el acceso si nadie puede entrar), cuando todavía no hay
 * quien registre a los demás desde la aplicación. Funciona con el usuario de la aplicación o con el de migraciones; por
 * costumbre se lanza con bin/as-migrator.
 *
 * Por qué un script y no un registro público: nadie se hace administrador solo. Dentro de la aplicación solo otro
 * administrador puede dar ese rol; el primero lo crea quien tiene acceso al servidor.
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
    $created = (new AdminRepository(Connection::create()))->grantAccess($email, AdminAuth::hash($password));
} catch (RuntimeException $error) {
    fwrite(STDERR, 'Error: no se pudo crear el administrador (' . $error->getMessage() . ")\n");
    exit(1);
}

echo $created
    ? "Administrador creado: $email\n"
    : "Ese correo ya era un usuario: ahora es administrador (activo) y tiene esa contraseña: $email\n";
