<?php

declare(strict_types=1);

namespace App\Infrastructure\MkAuth;

/**
 * Transforma os campos do formulário interno no payload esperado pelo MkAuth.
 */
final class ClientPayloadMapper
{
    public function map(array $data): array
    {
        $pessoa = strtolower((string) ($data['pessoa'] ?? 'fisica')) === 'juridica' ? 'juridica' : 'fisica';
        $nome = trim((string) ($data['nome_completo'] ?? ''));
        $nomeResumido = trim((string) ($data['nome_resumido'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));
        $login = trim((string) ($data['login'] ?? ''));
        $celular = $this->normalizePhone((string) ($data['celular'] ?? ''));
        $telefone = $this->formatPhoneForMkAuth($celular);
        $documento = preg_replace('/\D+/', '', (string) ($data['cpf_cnpj'] ?? '')) ?? '';
        $cep = preg_replace('/\D+/', '', (string) ($data['cep'] ?? '')) ?? '';
        $cadastro = $this->normalizeDate((string) ($data['cadastro'] ?? date('Y-m-d')));
        $vencimento = $this->normalizeDay((string) ($data['vencimento'] ?? ''));
        $city = trim((string) ($data['cidade'] ?? ''));
        $estado = strtoupper(trim((string) ($data['estado'] ?? '')));
        $codigoIbge = preg_replace('/\D+/', '', (string) ($data['codigo_ibge'] ?? '')) ?: null;
        $tags = strtolower(trim((string) ($data['tags_imprime'] ?? 'nao'))) === 'sim' ? 'imprime' : '';
        $observacao = trim((string) ($data['observacao'] ?? ''));
        $evidenceRef = trim((string) ($data['evidence_ref'] ?? ''));
        $evidenceUrl = trim((string) ($data['evidence_url'] ?? ''));
        $observacaoCompleta = $observacao;

        if ($evidenceUrl !== '') {
            $observacaoCompleta = trim($observacaoCompleta . "\nEvidências ISP Auxiliar: " . $evidenceUrl);
        } elseif ($evidenceRef !== '') {
            $observacaoCompleta = trim($observacaoCompleta . "\nEvidências ISP Auxiliar: " . $evidenceRef);
        }

        $tipoCob = $tags !== '' ? 'carne' : 'titulo';
        $tecnico = trim((string) ($data['tecnico'] ?? ''));
        $personCode = $pessoa === 'juridica' ? 1 : 3;
        $billingAccount = trim((string) ($data['conta_boleto'] ?? '1')) ?: '1';
        $contractCode = trim((string) ($data['contrato'] ?? '1b8e10ae245d7')) ?: '1b8e10ae245d7';
        $coordinates = trim((string) ($data['coordenadas'] ?? ''));
        $operatorLogin = trim((string) ($data['login_atend'] ?? ''));
        $now = date('Y-m-d H:i:s');

        return [
            'nome' => $nome,
            'nome_res' => $nomeResumido !== '' ? $nomeResumido : $this->defaultShortName($nome),
            'login' => $login,
            'senha' => (string) ($data['senha'] ?? '13v0'),
            'email' => $email !== '' ? $email : 'cliente@ievo.com.br',
            'cpf' => $documento,
            'cpf_cnpj' => $documento,
            'cadastro' => $cadastro,
            'tipo' => 'pppoe',
            'aviso' => '[""]',
            'foto' => 'img_nao_disp.gif',
            'ramal' => 'todos',
            'pessoa' => $pessoa,
            'tipo_pessoa' => $personCode,
            'tipo_cliente' => $personCode,
            'acessacen' => 'sim',
            'altsenha' => 'nao',
            'cep' => $cep,
            'endereco' => trim((string) ($data['endereco'] ?? '')),
            'numero' => trim((string) ($data['numero'] ?? 'SN')),
            'bairro' => trim((string) ($data['bairro'] ?? '')),
            'complemento' => trim((string) ($data['complemento'] ?? '')),
            'cidade' => $city,
            'estado' => $estado,
            'cidade_ibge' => $codigoIbge,
            'endereco_res' => trim((string) ($data['endereco'] ?? '')),
            'numero_res' => trim((string) ($data['numero'] ?? 'SN')),
            'bairro_res' => trim((string) ($data['bairro'] ?? '')),
            'cidade_res' => $city,
            'cep_res' => $cep,
            'estado_res' => $estado,
            'complemento_res' => trim((string) ($data['complemento'] ?? '')),
            'telefone' => $telefone,
            'fone' => '',
            'celular' => $telefone,
            'tags' => $tags !== '' ? $tags : null,
            'plano' => trim((string) ($data['plano'] ?? '')),
            'conta' => $billingAccount,
            'tipo_cob' => $tipoCob,
            'mesref' => 'ant',
            'rec_email' => 'sim',
            'sms' => 'sim',
            'zap' => 'sim',
            'pgcorte' => 'sim',
            'pgaviso' => 'sim',
            'tecnico' => $tecnico !== '' ? $tecnico : 'Operador',
            'data_ins' => $now,
            'venc' => $vencimento,
            'conta_cartao' => 0,
            'comodato' => 'sim',
            'observacao' => 'nao',
            'obs' => $observacaoCompleta,
            'rem_obs' => null,
            'data_remover' => 'nao',
            'dias_corte' => '15',
            'user_ip' => $operatorLogin !== '' ? $operatorLogin : null,
            'user_mac' => $operatorLogin !== '' ? $operatorLogin : null,
            'data_ip' => $operatorLogin !== '' ? $now : null,
            'data_mac' => $operatorLogin !== '' ? $now : null,
            'coordenadas' => $coordinates,
            'interface' => null,
            'local_dici' => strtolower((string) ($data['local_dici'] ?? 'u')) === 'r' ? 'r' : 'u',
            'geranfe' => 'sim',
            'gsici' => 1,
            'contrato' => $contractCode,
            'login_atend' => $operatorLogin,
            'usuario_instalador' => $operatorLogin,
            'uuid_cliente' => trim((string) ($data['uuid_cliente'] ?? '')),
        ];
    }

    private function defaultShortName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];

        return $parts[0] ?? $name;
    }

    private function normalizePhone(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return $digits;
    }

    private function formatPhoneForMkAuth(string $digits): string
    {
        $digits = preg_replace('/\D+/', '', $digits) ?? '';

        if ($digits === '') {
            return '';
        }

        $ddd = substr($digits, 0, 2);
        $rest = substr($digits, 2);

        return sprintf('(%s)%s', $ddd, $rest);
    }

    private function normalizeDate(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return date('d/m/Y');
        }

        $timestamp = strtotime($value);

        return $timestamp ? date('d/m/Y', $timestamp) : $value;
    }

    private function normalizeDay(string $value): string
    {
        $day = (int) preg_replace('/\D+/', '', $value);

        if ($day < 1 || $day > 31) {
            $day = 5;
        }

        return str_pad((string) $day, 2, '0', STR_PAD_LEFT);
    }

}
