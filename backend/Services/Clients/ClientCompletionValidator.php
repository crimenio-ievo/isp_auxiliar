<?php

declare(strict_types=1);

namespace App\Services\Clients;

/**
 * Barreira reutilizável da conclusão de novo cliente.
 * Não consulta nem escreve no MkAuth; apenas normaliza e valida o snapshot final.
 */
final class ClientCompletionValidator
{
    public function normalize(array $data): array
    {
        $normalized = $data;
        $document = preg_replace('/\D+/', '', (string) ($data['cpf_cnpj'] ?? $data['cpf'] ?? '')) ?? '';
        $phone = preg_replace('/\D+/', '', (string) ($data['celular'] ?? $data['telefone_cliente'] ?? $data['telefone'] ?? '')) ?? '';
        $person = strtolower(trim((string) ($data['pessoa'] ?? '')));
        if ($person === '') {
            $person = strlen($document) === 14 ? 'juridica' : (strlen($document) === 11 ? 'fisica' : '');
        }
        $normalized['pessoa'] = $person;
        $normalized['cpf_cnpj'] = $document;
        $normalized['celular'] = $phone;
        $normalized['telefone_cliente'] = $phone;
        $normalized['login'] = strtolower(trim((string) ($data['login'] ?? '')));
        $normalized['nome_completo'] = trim((string) ($data['nome_completo'] ?? $data['nome'] ?? ''));
        $normalized['plano'] = trim((string) ($data['plano'] ?? ''));
        $normalized['cep'] = preg_replace('/\D+/', '', (string) ($data['cep'] ?? '')) ?? '';
        $normalized['endereco'] = trim((string) ($data['endereco'] ?? ''));
        $normalized['numero'] = strtoupper(trim((string) ($data['numero'] ?? '')));
        $normalized['bairro'] = trim((string) ($data['bairro'] ?? ''));
        $normalized['cidade'] = trim((string) ($data['cidade'] ?? ''));
        $normalized['estado'] = strtoupper(trim((string) ($data['estado'] ?? '')));
        $normalized['vencimento'] = str_pad((string) (int) preg_replace('/\D+/', '', (string) ($data['vencimento'] ?? '')), 2, '0', STR_PAD_LEFT);
        $normalized['tipo_instalacao'] = strtolower(trim((string) ($data['tipo_instalacao'] ?? '')));
        $normalized['local_dici'] = strtolower(trim((string) ($data['local_dici'] ?? '')));
        $normalized['coordenadas'] = trim((string) ($data['coordenadas'] ?? ''));

        return $normalized;
    }

    public function validate(array $data): array
    {
        $data = $this->normalize($data);
        $errors = [];
        $document = (string) $data['cpf_cnpj'];
        $person = (string) $data['pessoa'];

        if (!in_array($person, ['fisica', 'juridica'], true)) {
            $errors['pessoa'] = 'Informe o tipo de pessoa para concluir o cadastro.';
        }
        if ($person === 'juridica' || strlen($document) === 14) {
            if (!$this->isValidCnpj($document) || ($person !== '' && $person !== 'juridica')) {
                $errors['cpf_cnpj'] = 'Informe um CNPJ válido para concluir o cadastro.';
            }
        } elseif (!$this->isValidCpf($document) || ($person !== '' && $person !== 'fisica')) {
            $errors['cpf_cnpj'] = 'Informe um CPF válido para concluir o cadastro.';
        }
        if ($data['nome_completo'] === '') {
            $errors['nome_completo'] = 'Informe o nome completo ou razão social para concluir o cadastro.';
        }
        if ($data['login'] === '' || preg_match('/^[a-z0-9._-]{3,64}$/', (string) $data['login']) !== 1) {
            $errors['login'] = 'Informe um login válido para concluir o cadastro.';
        }
        if ($data['plano'] === '') {
            $errors['plano'] = 'Selecione um plano oficial para concluir o cadastro.';
        }
        if (!in_array(strlen((string) $data['celular']), [10, 11], true)) {
            $errors['celular'] = 'Informe um telefone válido com DDD para concluir o cadastro.';
        }
        foreach (['endereco' => 'endereço', 'numero' => 'número', 'bairro' => 'bairro', 'cidade' => 'cidade'] as $field => $label) {
            if (trim((string) ($data[$field] ?? '')) === '') {
                $errors[$field] = 'Informe o ' . $label . ' para concluir o cadastro.';
            }
        }
        if (preg_match('/^[A-Z]{2}$/', (string) $data['estado']) !== 1) {
            $errors['estado'] = 'Informe um estado válido para concluir o cadastro.';
        }
        $dueDay = (int) $data['vencimento'];
        if ($dueDay < 1 || $dueDay > 31) {
            $errors['vencimento'] = 'Selecione um vencimento válido para concluir o cadastro.';
        }
        if (!in_array((string) $data['tipo_instalacao'], ['fibra', 'radio'], true)) {
            $errors['tipo_instalacao'] = 'Selecione a tecnologia da instalação para concluir o cadastro.';
        }
        if (!in_array((string) $data['local_dici'], ['u', 'r'], true)) {
            $errors['local_dici'] = 'Selecione a classificação do local da instalação.';
        }
        if (!$this->validCoordinates((string) $data['coordenadas'])) {
            $errors['coordenadas'] = 'Informe coordenadas válidas da instalação para concluir o cadastro.';
        }
        if (!in_array(strtolower(trim((string) ($data['tipo_adesao'] ?? ''))), ['cheia', 'promocional', 'isenta'], true)) {
            $errors['tipo_adesao'] = 'Selecione uma condição de adesão válida.';
        }
        if ((int) ($data['parcelas_adesao'] ?? 0) < 1) {
            $errors['parcelas_adesao'] = 'Informe as parcelas da adesão.';
        }
        if ((int) ($data['fidelidade_meses'] ?? 0) < 1) {
            $errors['fidelidade_meses'] = 'Informe a fidelidade contratual em meses.';
        }

        return $errors;
    }

    public function assertComplete(array $data): array
    {
        $normalized = $this->normalize($data);
        $errors = $this->validate($normalized);
        if ($errors !== []) {
            throw new ClientCompletionValidationException($errors);
        }
        return $normalized;
    }

    private function isValidCpf(string $document): bool
    {
        if (strlen($document) !== 11 || preg_match('/^(\d)\1{10}$/', $document)) {
            return false;
        }
        for ($position = 9; $position < 11; $position++) {
            $sum = 0;
            for ($index = 0; $index < $position; $index++) {
                $sum += (int) $document[$index] * (($position + 1) - $index);
            }
            $digit = (10 * $sum) % 11;
            $digit = $digit === 10 ? 0 : $digit;
            if ($digit !== (int) $document[$position]) {
                return false;
            }
        }
        return true;
    }

    private function isValidCnpj(string $document): bool
    {
        if (strlen($document) !== 14 || preg_match('/^(\d)\1{13}$/', $document)) {
            return false;
        }
        foreach ([[5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2], [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]] as $offset => $weights) {
            $sum = 0;
            foreach ($weights as $index => $weight) {
                $sum += (int) $document[$index] * $weight;
            }
            $remainder = $sum % 11;
            $digit = $remainder < 2 ? 0 : 11 - $remainder;
            if ($digit !== (int) $document[12 + $offset]) {
                return false;
            }
        }
        return true;
    }

    private function validCoordinates(string $coordinates): bool
    {
        if (!preg_match('/^\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)\s*$/', $coordinates, $matches)) {
            return false;
        }
        return (float) $matches[1] >= -90 && (float) $matches[1] <= 90
            && (float) $matches[2] >= -180 && (float) $matches[2] <= 180;
    }
}
