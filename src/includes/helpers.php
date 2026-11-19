<?php

declare(strict_types=1);

/**
 * Obtiene el valor de una variable de entorno
 *
 * @param string $name El nombre de la varibale
 * @return string $value el valor de la variable
 */
function environment(string $name): string
{
    $value = getenv($name);

    if ($value === false || $value === '') {
        throw new RuntimeException("Falta la variable de entorno $name");
    }

    return $value;
}

/**
 * Escapa un valor para su uso seguro en HTML.
 *
 * @param mixed $value El valor a escapar.
 * @return string El valor escapado.
 */
function escape(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Convierte una fecha guardada en UTC (así la guarda la BD) a la zona horaria de la aplicación.
 *
 * Ejemplo (con date.timezone = America/Bogota):
 *     format_utc_datetime('2026-11-19 14:08:36');                     // '2026-11-19 09:08:36'
 *     format_utc_datetime('2026-11-19 14:08:36', 'Europe/Madrid');    // '2026-11-19 15:08:36'
 *
 * @param string $utc Fecha 'Y-m-d H:i:s' en UTC, tal como sale de la BD.
 * @param string|null $timezone Zona de destino; por defecto la de PHP (date.timezone).
 * @return string La fecha en la zona de destino, con el mismo formato. Si la fecha o la zona no
 *                son válidas devuelve el texto original en vez de romper la página.
 */
function format_utc_datetime(string $utc, ?string $timezone = null): string
{
    try {
        $date = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
        $zone = new DateTimeZone($timezone ?? date_default_timezone_get());
    } catch (Exception) {
        return $utc;
    }

    return $date->setTimezone($zone)->format('Y-m-d H:i:s');
}

/**
 * Registra las excepciones en el log.
 *
 * @param string $step El paso en el que ocurrió la excepción.
 * @param Throwable $error La excepción a registrar.
 * @return void
 */
function log_exception(string $step, Throwable $error): void
{
    $requestUri = $_SERVER['REQUEST_URI'] ?? 'CLI';
    $targetError = $error->getPrevious() ?? $error;
    $code = $targetError->getCode();
    $detail = $targetError->getMessage();

    $formattedLog = $code !== 0 && $code !== ''
        ? sprintf('[%s] %s: (%s) %s', $step, $requestUri, $code, $detail)
        : sprintf('[%s] %s: %s', $step, $requestUri, $detail);

    error_log($formattedLog);
}

/**
 * Traduce un fallo de la capa de datos o de la configuración en un código HTTP y un aviso para el
 * visitante, y lo registra en el log. Sustituye al bloque `try/catch` de tres ramas que se repetía
 * en cada página: como DatabaseConnectionException y PDOException extienden RuntimeException, a cada
 * página le basta un solo `catch (RuntimeException)`.
 *
 *  - DatabaseConnectionException (sin conexión)       -> 503, etiqueta [connection]
 *  - PDOException (la consulta falló)                 -> 500, etiqueta [query], con $queryMessage
 *  - cualquier otra RuntimeException (configuración)  -> 500, etiqueta [config]
 *
 * El aviso nunca lleva detalles técnicos: el detalle va solo al log.
 *
 * @param Throwable $error El fallo capturado.
 * @param string $queryMessage Aviso para el caso de un error de consulta (cambia según la página).
 * @return array{0: int, 1: string} [código HTTP, aviso para el visitante]
 */
function describe_failure(Throwable $error, string $queryMessage): array
{
    if ($error instanceof DatabaseConnectionException) {
        log_exception('connection', $error);

        return [503, 'No se pudo conectar a la base de datos. Inténtalo más tarde.'];
    }

    if ($error instanceof PDOException) {
        log_exception('query', $error);

        return [500, $queryMessage];
    }

    log_exception('config', $error);

    return [500, 'Ocurrió un error inesperado. Inténtalo más tarde.'];
}

/**
 * Enlaza los parámetros de una consulta preparada respetando su tipo: los enteros de PHP con
 * PDO::PARAM_INT y todo lo demás como texto. Hace falta para LIMIT y OFFSET: ni `execute($params)` ni
 * `bindValue()` sin tipo valen con consultas emuladas, que escribirían `LIMIT '10'` en el SQL.
 *
 * @param PDOStatement $stmt La consulta ya preparada.
 * @param array<string, string|int> $params Marcador => valor (p. ej. [':limit' => 10]).
 */
function bind_all(PDOStatement $stmt, array $params): void
{
    foreach ($params as $name => $value) {
        $stmt->bindValue($name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
}
