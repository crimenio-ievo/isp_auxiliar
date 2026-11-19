<?php

declare(strict_types=1);

namespace App\Infrastructure\Map;

/**
 * "Seguir rua": consulta um serviço OSRM (URL configurada em provider_settings, chave map.routing.osrm_url) e devolve
 * pontos [lat,lng] intermediários simplificados. Só envia coordenadas; nunca dado de cliente. Qualquer falha devolve
 * null e a tela segue no modo manual. O servidor público (router.project-osrm.org) NÃO é o padrão: limite de 1 req/s,
 * uso não comercial e sem garantia, então a URL fica vazia até alguém configurar uma instância própria.
 */
final class OsrmRouter
{
    public const MAX_POINTS = 400;
    private const PROFILES = ['driving', 'car', 'bike', 'foot', 'walking', 'cycling'];

    /** @param (callable(string): ?string)|null $http devolve o corpo ou null; padrão: file_get_contents com timeout */
    public function __construct(private ?string $baseUrl, private string $profile = 'driving', private $http = null)
    {
    }

    public function enabled(): bool
    {
        return self::validBase($this->baseUrl) !== null;
    }

    public static function validBase(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '' || preg_match('#^https?://[^\s/?\#]+(/[^\s?\#]*)?$#i', $url) !== 1) {
            return null;
        }

        return rtrim($url, '/');
    }

    /**
     * @param array{0: float, 1: float} $from
     * @param array{0: float, 1: float} $to
     * @return list<array{0: float, 1: float}>|null pontos após o início, incluindo o fim (já ajustado à rua)
     */
    public function route(array $from, array $to): ?array
    {
        $base = self::validBase($this->baseUrl);
        $profile = in_array($this->profile, self::PROFILES, true) ? $this->profile : 'driving';
        if ($base === null) {
            return null;
        }
        $url = sprintf('%s/route/v1/%s/%.6f,%.6f;%.6f,%.6f?overview=full&geometries=geojson&steps=false', $base, $profile, $from[1], $from[0], $to[1], $to[0]);
        $body = $this->http !== null ? ($this->http)($url) : $this->fetch($url);
        if (!is_string($body) || strlen($body) > 2_000_000) {
            return null;
        }
        $json = json_decode($body, true);
        $coords = is_array($json) && ($json['code'] ?? '') === 'Ok' ? ($json['routes'][0]['geometry']['coordinates'] ?? null) : null;
        if (!is_array($coords) || count($coords) < 2) {
            return null;
        }
        $pts = [];
        foreach ($coords as $c) {
            if (!is_array($c) || !isset($c[0], $c[1]) || !is_numeric($c[0]) || !is_numeric($c[1])) {
                return null;
            }
            $pts[] = [round((float) $c[1], 6), round((float) $c[0], 6)];
        }
        $tol = 1.0;
        do {
            $simple = MapPath::simplify($pts, $tol);
            $tol *= 2;
        } while (count($simple) > self::MAX_POINTS && $tol < 512);

        return array_slice($simple, 1);
    }

    /** curl quando existir (timeouts e limite de tamanho), senao file_get_contents; sem nenhum dos dois devolve null (modo manual). */
    private function fetch(string $url): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                return null;
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_HTTPHEADER => ['Accept: application/json'], CURLOPT_USERAGENT => 'ISP-Auxiliar-Map',
                CURLOPT_FAILONERROR => true, CURLOPT_MAXFILESIZE => 2_000_000,
            ]);
            $body = curl_exec($ch);
            curl_close($ch);

            return is_string($body) ? $body : null;
        }
        $ctx = stream_context_create(['http' => ['timeout' => 6, 'ignore_errors' => false, 'header' => "Accept: application/json\r\nUser-Agent: ISP-Auxiliar-Map\r\n"]]);
        $body = @file_get_contents($url, false, $ctx, 0, 2_000_001);

        return $body === false ? null : $body;
    }
}
