<?php

declare(strict_types=1);

/**
 * Búsqueda de usuarios (M1). Funciones puras: no tocan la BD ni leen superglobales, así que se
 * prueban solas (src/tests/unit.php). Quien ejecuta la consulta es index.php.
 *
 * Una búsqueda es una petición GET (?q=texto): se puede copiar la URL, guardarla en favoritos y
 * recargar sin que el navegador pregunte si quiere reenviar un formulario.
 */

/** Longitud máxima del término: la de la columna más larga que se busca (email, VARCHAR(254)). */
const SEARCH_TERM_MAX = 254;

/**
 * Limpia lo que el visitante escribió en el campo de búsqueda.
 *
 * Todo lo que no sea texto (?q[]=x) cuenta como "sin búsqueda". Los tabuladores, saltos de línea y
 * caracteres de control se convierten en un espacio, los espacios repetidos se juntan en uno
 * (así "laura   gomez" encuentra a "Laura Gómez") y se recorta a SEARCH_TERM_MAX caracteres, no bytes.
 * Un UTF-8 inválido también cuenta como "sin búsqueda".
 *
 * @param mixed $raw Valor crudo de $_GET['q'] (o null si no llegó).
 * @return string El término limpio, o '' si no hay búsqueda.
 */
function parse_search_term(mixed $raw): string
{
    if (!is_string($raw)) {
        return '';
    }

    $term = preg_replace('/[\p{Cc}\s]+/u', ' ', $raw);

    if ($term === null) {
        return '';
    }

    return trim(mb_substr(trim($term), 0, SEARCH_TERM_MAX, 'UTF-8'));
}

/**
 * Convierte el término en un patrón para LIKE: `%texto%`, con los comodines del propio texto escapados.
 *
 * En LIKE, `%` casa con cualquier cosa y `_` con cualquier carácter, y `\` es el carácter de escape
 * por defecto de MySQL. Si el visitante busca `100%` o `Sub_Real` y no se escapan, `%` y `_` actuarían
 * como comodines y devolverían filas de más. La barra invertida se escapa a la vez que los comodines
 * (con strtr, que no vuelve a leer lo que ya sustituyó, así no se escapa dos veces).
 *
 * Los `%` de los extremos van aquí, dentro del VALOR que se pasa a la consulta preparada, y no
 * pegados al texto SQL: así el dato nunca se mezcla con el código.
 *
 * Requiere que sql_mode de MySQL no incluya NO_BACKSLASH_ESCAPES (por defecto no lo incluye).
 *
 * @param string $term Término ya limpio con parse_search_term().
 * @return string El patrón, p. ej. "100%" -> "%100\%%".
 */
function like_pattern(string $term): string
{
    return '%' . strtr($term, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
}

/**
 * Consulta del listado, con o sin búsqueda.
 *
 * Con texto busca en nombre, apellido, nombre completo ("Laura Gómez"), correo y teléfono. Como PDO
 * trabaja con consultas preparadas de verdad (EMULATE_PREPARES apagado), **no se puede repetir un
 * mismo marcador**: usar `:q` tres veces da el error HY093 "Invalid parameter number". Cada columna
 * lleva su marcador (`:q0`, `:q1`...), y todos reciben el mismo patrón.
 *
 * Lo único que se escribe en el SQL son nombres de columna fijos y números de marcador: nada de lo
 * que el visitante teclea llega al texto de la consulta. MySQL ignora mayúsculas y tildes en
 * `utf8mb4_unicode_ci`, de modo que "gomez" encuentra a "Gómez".
 *
 * @param string $term Término ya limpio con parse_search_term(), o '' para listar todo.
 * @return array{0: string, 1: array<string, string>} [consulta SQL, parámetros para execute()]
 */
function user_list_query(string $term): array
{
    $select = 'SELECT id, name, last_name, email, phone, created_at FROM users';

    if ($term === '') {
        return [$select . ' ORDER BY id DESC', []];
    }

    $searchable = ['name', 'last_name', "CONCAT(name, ' ', last_name)", 'email', 'phone'];
    $pattern = like_pattern($term);
    $conditions = [];
    $params = [];

    foreach ($searchable as $index => $expression) {
        $conditions[] = "$expression LIKE :q$index";
        $params[":q$index"] = $pattern;
    }

    return [$select . ' WHERE ' . implode(' OR ', $conditions) . ' ORDER BY id DESC', $params];
}
