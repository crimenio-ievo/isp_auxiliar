<?php

declare(strict_types=1);

namespace App\Infrastructure\MkAuth;

/**
 * Resolve a identidade oficial do plano e confirma a leitura final direto no
 * banco do MkAuth, sem usar o snapshot ou o cache local do ISP Auxiliar.
 */
final class ClientPlanConfirmationService
{
    public function __construct(
        private ClientPlanReadRepository $reader,
        private int $readAttempts = 3,
        private int $retryDelayMilliseconds = 200
    ) {
        $this->readAttempts = max(1, min(5, $this->readAttempts));
        $this->retryDelayMilliseconds = max(0, min(2000, $this->retryDelayMilliseconds));
    }

    public function resolveExpectedPlan(string $selectedPlan): array
    {
        $selectedPlan = trim($selectedPlan);
        if ($selectedPlan === '') {
            throw new \InvalidArgumentException('Selecione um plano válido.');
        }

        foreach ($this->reader->listPlans() as $plan) {
            if (!is_array($plan)) {
                continue;
            }

            $name = trim((string) ($plan['nome'] ?? ''));
            $uuid = trim((string) ($plan['uuid_plano'] ?? ''));
            $code = trim((string) ($plan['codigo'] ?? $plan['id'] ?? ''));
            if (($uuid !== '' && strcasecmp($uuid, $selectedPlan) === 0)
                || ($code !== '' && strcasecmp($code, $selectedPlan) === 0)
                || ($name !== '' && strcasecmp($name, $selectedPlan) === 0)
            ) {
                if ($name === '') {
                    throw new \RuntimeException('O plano selecionado não possui nome oficial para a API do MkAuth.');
                }

                return [
                    'selected' => $selectedPlan,
                    'uuid' => $uuid,
                    'code' => $code,
                    'name' => $name,
                    'value' => trim((string) ($plan['valor'] ?? '')),
                    'technology' => trim((string) ($plan['tecnologia'] ?? '')),
                    'download' => trim((string) ($plan['veldown'] ?? '')),
                    'upload' => trim((string) ($plan['velup'] ?? '')),
                    // A API nativa desta versão recebe o nome oficial; a
                    // confirmação posterior usa UUID quando ele está disponível.
                    'payload_value' => $name,
                ];
            }
        }

        throw new \RuntimeException('O plano selecionado não foi localizado no catálogo atual do MkAuth.');
    }

    public function readClient(string $login): ?array
    {
        $login = trim($login);
        if ($login === '') {
            return null;
        }

        return $this->reader->findClientProfile($login);
    }

    public function confirm(string $login, array $expected): array
    {
        $lastProfile = null;
        $lastError = null;

        for ($attempt = 1; $attempt <= $this->readAttempts; $attempt++) {
            try {
                $lastProfile = $this->readClient($login);
                if (is_array($lastProfile)) {
                    $comparison = $this->compare($expected, $lastProfile);
                    $comparison['attempt'] = $attempt;
                    if (!empty($comparison['confirmed'])) {
                        return $comparison;
                    }
                }
            } catch (\Throwable $exception) {
                $lastError = $exception->getMessage();
            }

            if ($attempt < $this->readAttempts && $this->retryDelayMilliseconds > 0) {
                usleep($this->retryDelayMilliseconds * 1000);
            }
        }

        $comparison = $this->compare($expected, is_array($lastProfile) ? $lastProfile : []);
        $comparison['attempt'] = $this->readAttempts;
        $comparison['read_error'] = $lastError;

        return $comparison;
    }

    private function compare(array $expected, array $profile): array
    {
        $expectedUuid = trim((string) ($expected['uuid'] ?? ''));
        $expectedCode = trim((string) ($expected['code'] ?? ''));
        $expectedName = trim((string) ($expected['name'] ?? ''));
        $observedUuid = trim((string) ($profile['plano_uuid'] ?? $profile['uuid_plano'] ?? ''));
        $observedCode = trim((string) ($profile['plano_codigo'] ?? $profile['codigo_plano'] ?? ''));
        $observedName = trim((string) ($profile['plano_nome'] ?? $profile['plano'] ?? ''));

        $matchedBy = null;
        if ($expectedUuid !== '' && $observedUuid !== '' && strcasecmp($expectedUuid, $observedUuid) === 0) {
            $matchedBy = 'uuid';
        } elseif ($expectedCode !== '' && $observedCode !== '' && strcasecmp($expectedCode, $observedCode) === 0) {
            $matchedBy = 'code';
        } elseif ($expectedName !== '' && $observedName !== '' && strcasecmp($expectedName, $observedName) === 0) {
            // O nome só é fallback quando a leitura oficial não devolve UUID/código.
            $matchedBy = ($expectedUuid === '' || $observedUuid === '') ? 'official_name' : null;
        }

        return [
            'confirmed' => $matchedBy !== null,
            'matched_by' => $matchedBy,
            'expected_uuid' => $expectedUuid,
            'expected_code' => $expectedCode,
            'expected_name' => $expectedName,
            'observed_uuid' => $observedUuid,
            'observed_code' => $observedCode,
            'observed_name' => $observedName,
            'client_uuid' => trim((string) ($profile['uuid_cliente'] ?? '')),
            'source' => 'mkauth_database_readback',
        ];
    }
}
