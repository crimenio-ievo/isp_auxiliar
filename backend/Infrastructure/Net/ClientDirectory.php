<?php

declare(strict_types=1);

namespace App\Infrastructure\Net;

/**
 * Leitura (somente leitura) de clientes e sessoes PPPoE para os vinculos de rede. Implementacao real: MkAuthClientDirectory
 * (so SELECT no MkAuth). Existe como interface para os testes nao dependerem do MkAuth.
 */
interface ClientDirectory
{
    /** @return list<array<string,mixed>> linhas normalizadas (login, nome, bairro, cidade, status...) */
    public function search(string $term, int $limit): array;

    /** @return array<string,mixed>|null perfil do cliente (login, nome...) ou null */
    public function find(string $login): ?array;

    /** @return array{referencia: ?string, inicio: ?string, callers: array<string,string>} */
    public function recentCallerMacs(int $days): array;

    /**
     * @param list<string> $logins
     * @return array<string,string> login em minusculas => nome
     */
    public function namesByLogins(array $logins): array;
}
