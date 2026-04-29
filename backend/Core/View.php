<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Renderiza arquivos PHP de view com dados controlados.
 */
final class View
{
    public function __construct(private string $basePath)
    {
    }

    public function render(string $template, array $data = []): string
    {
        $templatePath = $this->basePath . '/' . trim($template, '/') . '.php';

        if (!is_file($templatePath)) {
            throw new RuntimeException("Template nao encontrado: {$template}");
        }

        $renderer = static function (string $__templatePath, array $__data): string {
            extract($__data, EXTR_SKIP);

            ob_start();
            require $__templatePath;

            return (string) ob_get_clean();
        };

        return $renderer($templatePath, $data);
    }
}
