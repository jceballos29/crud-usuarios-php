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

