<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Representa a resposta HTTP enviada ao navegador.
 */
final class Response
{
    public function __construct(
        private string $body,
        private int $status = 200,
        private array $headers = []
    ) {
    }

    public static function html(string $body, int $status = 200, array $headers = []): self
    {
        $headers['Content-Type'] = $headers['Content-Type'] ?? 'text/html; charset=UTF-8';

        return new self($body, $status, $headers);
    }

    public static function json(array $data, int $status = 200, array $headers = []): self
    {
        $headers['Content-Type'] = $headers['Content-Type'] ?? 'application/json; charset=UTF-8';

        return new self((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), $status, $headers);
    }

    public static function redirect(string $location, int $status = 302): self
    {
        if (str_starts_with($location, '/')) {
            $location = Url::to($location);
        }

        return new self('', $status, ['Location' => $location]);
    }

    public function send(): void
    {
        http_response_code($this->status);

        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }

        echo $this->body;
    }
}
