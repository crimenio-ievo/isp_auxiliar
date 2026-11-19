<?php

declare(strict_types=1);

namespace App\Services\Net;

use App\Controllers\MapController;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;

/**
 * Grava nos do mapa (map_nodes/map_ports) pelas MESMAS regras do ISP Map: delega ao MapController
 * (validacao, portas por capacidade, transacao e auditoria `map.*`). Assim o que e criado nas telas de
 * rede aparece no mapa e vice-versa, sem duplicar regra. Exige a sessao do gestor ($_SESSION['user']).
 */
final class MapNodeGateway
{
    public function __construct(private MapController $map)
    {
    }

    /**
     * @param array<string, scalar|null> $fields mesmos campos de POST /api/mapa/nos (node_type, name, lat, lng, parent_node_id, attrs, notes, ports_*)
     * @return array{ok: bool, status: int, message: string, node: ?array<string, mixed>}
     */
    public function create(array $fields): array
    {
        return $this->call('createNode', '/api/mapa/nos', $fields);
    }

    /**
     * @param array<string, scalar|null> $fields
     * @return array{ok: bool, status: int, message: string, node: ?array<string, mixed>}
     */
    public function update(int $nodeId, array $fields): array
    {
        return $this->call('updateNode', '/api/mapa/nos/editar', ['id' => $nodeId] + $fields);
    }

    /**
     * @return array{ok: bool, status: int, message: string, node: ?array<string, mixed>}
     */
    public function delete(int $nodeId): array
    {
        return $this->call('deleteNode', '/api/mapa/nos/excluir', ['id' => $nodeId]);
    }

    /**
     * @param array<string, scalar|null> $fields
     * @return array{ok: bool, status: int, message: string, node: ?array<string, mixed>}
     */
    private function call(string $method, string $path, array $fields): array
    {
        $body = array_filter($fields, static fn ($v): bool => $v !== null);
        $body['_csrf'] = Csrf::token('map');
        /** @var Response $response */
        $response = $this->map->{$method}(new Request('POST', $path, '', [], $body));
        $decoded = json_decode($response->body(), true);
        $decoded = is_array($decoded) ? $decoded : [];
        $node = $decoded['data']['node'] ?? null;

        return [
            'ok' => ($decoded['ok'] ?? false) === true && $response->status() === 200,
            'status' => $response->status(),
            'message' => (string) ($decoded['message'] ?? ''),
            'node' => is_array($node) ? $node : null,
        ];
    }
}
