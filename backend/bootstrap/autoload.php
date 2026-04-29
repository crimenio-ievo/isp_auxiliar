<?php

declare(strict_types=1);

/**
 * Autoload simples no estilo PSR-4.
 *
 * A base desta etapa prioriza simplicidade e legibilidade, então mapeamos
 * diretamente os namespaces usados pelo projeto sem depender de Composer.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';

    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $relativePath = str_replace('\\', '/', $relativeClass) . '.php';
    $baseDirectories = [
        dirname(__DIR__) . '/',
    ];

    foreach ($baseDirectories as $baseDirectory) {
        $file = $baseDirectory . $relativePath;

        if (is_file($file)) {
            require_once $file;
            return;
        }
    }
});
