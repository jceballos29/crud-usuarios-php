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
 * Condición WHERE de la búsqueda, compartida por el listado y por el recuento (así los dos siempre
 * hablan de las mismas filas).
 *
 * Con texto busca en nombre, apellido, nombre completo ("Laura Gómez"), correo, teléfono y documento. Como PDO
 * trabaja con consultas preparadas de verdad (EMULATE_PREPARES apagado), **no se puede repetir un
 * mismo marcador**: usar `:q` tres veces da el error HY093 "Invalid parameter number". Cada columna
 * lleva su marcador (`:q0`, `:q1`...), y todos reciben el mismo patrón.
 *
 * Lo único que se escribe en el SQL son nombres de columna fijos y números de marcador: nada de lo
 * que el visitante teclea llega al texto de la consulta. MySQL ignora mayúsculas y tildes en
 * `utf8mb4_unicode_ci`, de modo que "gomez" encuentra a "Gómez".
 *
 * Todas las consultas filtran por `active` (borrado lógico, M4): un usuario eliminado no cuenta, no se
 * lista y no se encuentra, salvo en la vista de eliminados. `active` va como marcador (un entero), igual
 * que el texto buscado: aquí tampoco se escribe en el SQL nada que venga de fuera.
 *
 * @param string $term Término ya limpio con parse_search_term(), o '' para no filtrar por texto.
 * @param bool $active true = usuarios visibles; false = los eliminados.
 * @return array{0: string, 1: array<string, string|int>} [condición " WHERE ...", parámetros]
 */
function user_search_where(string $term, bool $active = true): array
{
    $activeParam = [':active' => $active ? 1 : 0];

    if ($term === '') {
        return [' WHERE active = :active', $activeParam];
    }

    $searchable = ['name', 'last_name', "CONCAT(name, ' ', last_name)", 'email', 'phone', 'document'];
    $pattern = like_pattern($term);
    $conditions = [];
    $params = [];

    foreach ($searchable as $index => $expression) {
        $conditions[] = "$expression LIKE :q$index";
        $params[":q$index"] = $pattern;
    }

    return [' WHERE active = :active AND (' . implode(' OR ', $conditions) . ')', $activeParam + $params];
}

/**
 * Consulta del recuento: cuántos usuarios cumplen la búsqueda (o cuántos hay, sin búsqueda). Hace falta
 * para saber cuántas páginas hay; no se puede deducir de la página que se pide.
 *
 * @param string $term Término ya limpio con parse_search_term(), o ''.
 * @param bool $active true = usuarios visibles; false = los eliminados.
 * @return array{0: string, 1: array<string, string|int>} [consulta SQL, parámetros]
 */
function user_count_query(string $term, bool $active = true): array
{
    [$where, $params] = user_search_where($term, $active);

    return ['SELECT COUNT(*) FROM users' . $where, $params];
}

/**
 * Consulta de UNA página del listado, con o sin búsqueda.
 *
 * Los parámetros de LIMIT y OFFSET son **enteros de PHP** y hay que enlazarlos como tales
 * (bind_all() en helpers.php los enlaza con PDO::PARAM_INT). Con consultas nativas MySQL perdona un
 * texto como "10", pero con consultas emuladas PDO escribiría `LIMIT '10'` y daría un error de sintaxis.
 * Se fuerzan a un valor válido (LIMIT >= 1, OFFSET >= 0) porque MySQL rechaza los negativos.
 *
 * El orden es por `id DESC`, que es único: así dos páginas nunca repiten ni se saltan una fila. Si se
 * ordenara por una columna con valores repetidos haría falta desempatar por id.
 *
 * @param string $term Término ya limpio con parse_search_term(), o ''.
 * @param int $limit Filas por página.
 * @param int $offset Filas que se saltan: (página - 1) * filas por página.
 * @param bool $active true = usuarios visibles; false = los eliminados.
 * @return array{0: string, 1: array<string, string|int>} [consulta SQL, parámetros para bind_all()]
 */
function user_list_query(string $term, int $limit, int $offset, bool $active = true): array
{
    [$where, $params] = user_search_where($term, $active);

    $sql = 'SELECT id, name, last_name, email, phone, document, birth_date, role, created_at FROM users' . $where
        . ' ORDER BY id DESC LIMIT :limit OFFSET :offset';

    return [$sql, $params + [':limit' => max(1, $limit), ':offset' => max(0, $offset)]];
}
