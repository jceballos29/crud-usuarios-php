<?php

declare(strict_types=1);

/**
 * Pruebas de ARQUITECTURA (Mejora 8): no ejecutan código de la aplicación, leen sus archivos y comprueban que cada
 * pieza cumple su sola responsabilidad. Si alguien vuelve a meter SQL en una página o HTML en un repositorio, falla.
 *
 *     docker compose exec php php tests/architecture.php
 *
 * Sale con 0 si todo pasa y con 1 si algo falla.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/harness.php';

$root = dirname(__DIR__);

/** @return list<string> Rutas relativas a src/ de los .php bajo $dir. */
function phpFiles(string $root, string $dir): array
{
    $found = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$dir", FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $found[] = ltrim(substr($file->getPathname(), strlen($root)), '/');
        }
    }

    sort($found);

    return $found;
}

/** El código de un archivo SIN comentarios ni cadenas de documentación (para no contar lo que solo se menciona). */
function code(string $root, string $file): string
{
    $out = '';

    foreach (token_get_all((string) file_get_contents("$root/$file")) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $out .= is_array($token) ? $token[1] : $token;
    }

    return $out;
}

$execution = '/->(prepare|query|exec)\(/';
$sqlText = '/\b(SELECT|INSERT INTO|UPDATE|DELETE FROM)\s/';

section('Solo los repositorios (y los scripts de consola) conocen SQL');
$executes = [];
$hasSqlText = [];

foreach (array_merge(phpFiles($root, 'app'), phpFiles($root, 'public'), phpFiles($root, 'views')) as $file) {
    $source = code($root, $file);

    if (preg_match($execution, $source) === 1) {
        $executes[] = $file;
    }

    if (preg_match($sqlText, $source) === 1) {
        $hasSqlText[] = $file;
    }
}

same('únicos archivos web que EJECUTAN consultas: los dos repositorios y el diagnóstico (SELECT VERSION())', ['app/Controller/StatusController.php', 'app/Repository/AdminRepository.php', 'app/Repository/UserRepository.php'], $executes);
same('únicos que ESCRIBEN SQL: los repositorios, el diagnóstico y UserSearch (que construye la consulta pero no la ejecuta)', ['app/Controller/StatusController.php', 'app/Repository/AdminRepository.php', 'app/Repository/UserRepository.php', 'app/Search/UserSearch.php'], $hasSqlText);
same('ninguna vista toca la BD', [], array_values(array_filter(phpFiles($root, 'views'), static fn(string $f): bool => preg_match('/\$pdo|\bPDO\b|->prepare\(|->query\(/', code($root, $f)) === 1)));
same('ninguna página de public/ toca la BD', [], array_values(array_filter(phpFiles($root, 'public'), static fn(string $f): bool => preg_match('/\$pdo|\bPDO\b|->prepare\(|->query\(/', code($root, $f)) === 1)));
same('ningún controlador toca la BD directamente (solo a través de un repositorio)', [], array_values(array_filter(phpFiles($root, 'app/Controller'), static fn(string $f): bool => $f !== 'app/Controller/StatusController.php' && preg_match('/\$pdo|\bPDO\b|->prepare\(|->query\(|->exec\(/', code($root, $f)) === 1)));

section('Las páginas de public/ son delgadas');
foreach (phpFiles($root, 'public') as $file) {
    $lines = count(file("$root/$file"));
    same("$file tiene solo el arranque y una llamada al controlador ($lines líneas)", true, $lines <= 12 && str_contains(code($root, $file), 'bootstrap.php') && preg_match('/\(new App\\\\Controller\\\\\w+\(new App\\\\Container\(\)\)\)->\w+\(\);/', code($root, $file)) === 1);
}

section('Los repositorios no saben de HTTP ni de HTML');
foreach (['app/Repository/UserRepository.php', 'app/Repository/AdminRepository.php', 'app/Domain/User.php', 'app/Validation/UserValidator.php', 'app/Search/UserSearch.php'] as $file) {
    same("$file no usa superglobales, header(), echo ni http_response_code()", 0, preg_match('/\$_(GET|POST|SERVER|COOKIE|SESSION|REQUEST)\b|\bheader\(|\becho\b|http_response_code\(|\bexit\b|\?>/', code($root, $file)));
}

section('Las vistas no deciden nada de HTTP ni leen la petición');
foreach (phpFiles($root, 'views') as $file) {
    same("$file no lee superglobales ni envía cabeceras", 0, preg_match('/\$_(GET|POST|SERVER|COOKIE|SESSION|REQUEST)\b|\bheader\(|http_response_code\(|\bexit\b/', code($root, $file)));
}

section('Las vistas escapan lo que muestran');
// Cada <?= debe llevar escape() o csrf_field() (HTML ya escapado), o estar en esta lista de salidas que son números o
// textos fijos de la propia vista (nada que venga del visitante ni de la BD sin escapar). Una salida nueva sin escape
// falla aquí y obliga a decidir: escaparla o añadirla a propósito.
$fixedOutputs = [
    "\$pagination['total'] > 0 ? '(' . \$pagination['total'] . ')' : ''",
    "\$pagination['total'] === 1 ? '1 resultado' : \$pagination['total'] . ' resultados'",
    "\$pagination['pages'] > 1 ? ' · ' : '.'",
    "\$pagination['from']", "\$pagination['to']",
    "\$search === '' ? ' de ' . \$pagination['total'] : ''",
    "\$isInactiveView ? 'Usuarios eliminados' : 'Lista de Usuarios'",
    "\$isInactiveView ? 'No hay usuarios eliminados.' : 'No hay usuarios registrados.'",
    "Pagination::VIEW_INACTIVE", "UserSearch::TERM_MAX", "\$number", "\$current",
    "\$hasError ? ' is-invalid' : ''", "\$definition['required'] ? 'required' : ''",
    "(string) \$optionValue === \$data[\$fieldName] ? ' selected' : ''",
    "extension_loaded(\$extension) ? '✔' : '✘'", "extension_loaded(\$extension) ? '' : '(falta)'",
    "\$database['ok'] ? 'success' : 'danger'",
];
foreach (phpFiles($root, 'views') as $file) {
    $unsafe = [];

    foreach (preg_split('/\R/', (string) file_get_contents("$root/$file")) as $n => $line) {
        if (preg_match_all('/<\?=\s*(.+?)\s*\?>/', $line, $matches)) {
            foreach ($matches[1] as $expr) {
                $expr = str_replace('\\', '', $expr);
                $safe = str_starts_with($expr, 'escape(') || $expr === 'csrf_field()' || in_array($expr, array_map(static fn(string $e): string => str_replace('\\', '', $e), $fixedOutputs), true);

                if (!$safe) {
                    $unsafe[] = 'línea ' . ($n + 1) . ': ' . $expr;
                }
            }
        }
    }

    same("$file: toda salida <?= lleva escape() (o es un valor fijo conocido)", [], $unsafe);
}

section('El autoload cubre todas las clases');
foreach (phpFiles($root, 'app') as $file) {
    if (preg_match('/^app\/([A-Z]\w+\/)*([A-Z]\w+)\.php$/', $file, $m) === 1) {
        $class = 'App\\' . str_replace('/', '\\', substr($file, 4, -4));
        same("$class se carga por su nombre (PSR-4)", true, class_exists($class) || interface_exists($class));
    }
}

finish();
