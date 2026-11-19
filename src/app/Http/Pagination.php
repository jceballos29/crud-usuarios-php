<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Paginación del listado (M2). Funciones puras: no tocan la BD ni leen superglobales, así que se
 * prueban solas (src/tests/unit.php). Quien cuenta y consulta es index.php.
 *
 * La página y la búsqueda forman el "estado del listado". Se llevan en la URL (?q=...&page=...), con
 * los valores por defecto omitidos (la página 1 y la búsqueda vacía), de modo que cada estado tiene
 * una sola URL y se puede copiar, compartir y recargar.
 */
final class Pagination
{
    /** Usuarios por página. */
    public const PER_PAGE = 10;

    /** Vistas del listado (M4): los usuarios visibles y los eliminados (borrado lógico). */
    public const VIEW_ACTIVE = 'active';
    public const VIEW_INACTIVE = 'inactive';

    /**
     * Convierte el parámetro ?view= en una vista. Solo "inactive" (exacto) pide los eliminados; cualquier
     * otra cosa (vacío, otro texto, mayúsculas, un arreglo) es la vista normal. Es una lista blanca de dos.
     *
     * @param mixed $raw Valor crudo de $_GET['view'] (o null si no llegó).
     * @return string Pagination::VIEW_ACTIVE o Pagination::VIEW_INACTIVE.
     */
    public static function parseView(mixed $raw): string
    {
        return $raw === self::VIEW_INACTIVE ? self::VIEW_INACTIVE : self::VIEW_ACTIVE;
    }

    /**
     * Convierte el parámetro ?page= en un número de página.
     *
     * "Si no es válido, la página 1": lo que no sea texto (?page[]=2), el 0, los negativos, los decimales
     * y las letras dan 1. Los ceros a la izquierda se aceptan ("02" es la 2). Un número con tantos dígitos
     * que no cabe en un entero no es un error: está clarísimamente más allá de la última página, así que se
     * devuelve un valor enorme y Pagination::paginate() lo corrige a la última.
     *
     * @param mixed $raw Valor crudo de $_GET['page'] (o null si no llegó).
     * @return int Un número de página >= 1.
     */
    public static function parsePage(mixed $raw): int
    {
        if (!is_string($raw) || !ctype_digit($raw)) {
            return 1;
        }

        $digits = ltrim($raw, '0');

        if ($digits === '') {
            return 1;
        }

        return strlen($digits) > 15 ? PHP_INT_MAX : (int) $digits;
    }

    /**
     * Calcula todo lo que hace falta para pintar una página, **corrigiendo** la página pedida si se pasó
     * del rango: con 35 usuarios hay 4 páginas y ?page=999 muestra la 4, no una página vacía. Con 0
     * usuarios hay una sola página (vacía), nunca cero.
     *
     * Es lo que hace que, al eliminar al último usuario de la última página, se vuelva a la nueva última
     * página y no a una vacía.
     *
     * @param int $total Filas que cumplen el filtro (SELECT COUNT(*)).
     * @param int $requestedPage Página pedida, ya validada con Pagination::parsePage().
     * @param int $perPage Filas por página.
     * @return array{page: int, pages: int, perPage: int, offset: int, total: int, from: int, to: int}
     *         `from` y `to` son el primer y el último número de fila que se muestran (0 y 0 si no hay filas).
     */
    public static function paginate(int $total, int $requestedPage, int $perPage = self::PER_PAGE): array
    {
        $total = max(0, $total);
        $perPage = max(1, $perPage);
        $pages = max(1, intdiv($total + $perPage - 1, $perPage));
        $page = min(max(1, $requestedPage), $pages);
        $offset = ($page - 1) * $perPage;

        return [
            'page' => $page,
            'pages' => $pages,
            'perPage' => $perPage,
            'offset' => $offset,
            'total' => $total,
            'from' => $total === 0 ? 0 : $offset + 1,
            'to' => min($offset + $perPage, $total),
        ];
    }

    /**
     * Los números de página que se enseñan: siempre la primera y la última, y unas cuantas alrededor de
     * la actual. Si entre dos bloques falta una sola página se escribe esa página, y si faltan varias, un
     * hueco (null, que se pinta como "…"). Con 500 páginas, en la 250 sale 1 … 248 249 250 251 252 … 500.
     *
     * @param int $page Página actual (ya corregida por Pagination::paginate()).
     * @param int $pages Total de páginas.
     * @param int $radius Cuántas páginas se enseñan a cada lado de la actual.
     * @return array<int, int|null> Números de página; null es un hueco.
     */
    public static function pageLinks(int $page, int $pages, int $radius = 2): array
    {
        $wanted = array_unique(array_filter(
            array_merge([1, $pages], range($page - $radius, $page + $radius)),
            static fn(int $n): bool => $n >= 1 && $n <= $pages
        ));
        sort($wanted);

        $links = [];
        $previous = null;

        foreach ($wanted as $number) {
            if ($previous !== null && $number - $previous === 2) {
                $links[] = $previous + 1;
            } elseif ($previous !== null && $number - $previous > 2) {
                $links[] = null;
            }

            $links[] = $number;
            $previous = $number;
        }

        return $links;
    }

    /**
     * Parámetros de URL que describen el estado del listado, con los valores por defecto omitidos.
     *
     * @param string $search Término ya limpio con UserSearch::parseTerm().
     * @param int $page Página (>= 1).
     * @param string $view Pagination::VIEW_ACTIVE (se omite) o Pagination::VIEW_INACTIVE.
     * @return array<string, string|int> Vacío si es la vista normal, página 1 y sin búsqueda.
     */
    public static function listState(string $search, int $page, string $view = self::VIEW_ACTIVE): array
    {
        $state = [];

        if ($view === self::VIEW_INACTIVE) {
            $state['view'] = self::VIEW_INACTIVE;
        }

        if ($search !== '') {
            $state['q'] = $search;
        }

        if ($page > 1) {
            $state['page'] = $page;
        }

        return $state;
    }

    /**
     * URL del listado en un estado dado, con parámetros extra delante (por ejemplo ['msg' => 'deleted']).
     * Todo va codificado con http_build_query, así que ni los espacios ni un salto de línea ni un `&` del
     * texto buscado pueden romper la URL ni colar otra cabecera en un `Location:`. En HTML se escribe con
     * escape(), que convierte `&` en `&amp;`.
     *
     * @param string $search Término ya limpio con UserSearch::parseTerm().
     * @param int $page Página (>= 1).
     * @param array<string, string|int> $extra Parámetros extra que van antes del estado.
     * @param string $view Pagination::VIEW_ACTIVE o Pagination::VIEW_INACTIVE.
     * @return string Ruta relativa: "index.php" o "index.php?msg=deleted&q=laura&page=2".
     */
    public static function listUrl(string $search, int $page = 1, array $extra = [], string $view = self::VIEW_ACTIVE): string
    {
        $params = $extra + self::listState($search, $page, $view);

        return 'index.php' . ($params === [] ? '' : '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986));
    }
}
