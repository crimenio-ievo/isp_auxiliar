<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Infrastructure\Contracts\MessageTemplateRepository;

final class NotificationTemplateService
{
    public const VARIABLES = [
        'nomecliente', 'nomeresumido', 'documentocliente', 'logincliente',
        'telefonecliente', 'emailcliente', 'planoatual', 'novoplano',
        'valoratual', 'novovalor', 'tecnologiaatual', 'novatecnologia',
        'beneficio', 'fidelidade', 'linkaceite', 'data', 'nomeprovedor',
        'protocoloprocesso',
    ];

    public function __construct(private MessageTemplateRepository $repository)
    {
    }

    public function channels(): array
    {
        return [
            'whatsapp' => ['label' => 'WhatsApp', 'available' => true, 'adapter' => 'evotrix'],
            'email' => ['label' => 'E-mail', 'available' => true, 'adapter' => 'smtp'],
            'sms' => ['label' => 'SMS', 'available' => false, 'adapter' => null],
            'push' => ['label' => 'Aplicativo / push', 'available' => false, 'adapter' => null],
        ];
    }

    public function seedDefaults(): void
    {
        $events = [
            'instalacao_solicitar_aceite' => 'Instalação — solicitar aceite',
            'instalacao_reenviar_aceite' => 'Instalação — reenviar aceite',
            'instalacao_confirmacao_concluida' => 'Instalação — confirmação concluída',
            'migracao_solicitar_aceite' => 'Migração — solicitar aceite',
            'migracao_reenviar_aceite' => 'Migração — reenviar aceite',
            'migracao_confirmacao_concluida' => 'Migração — confirmação concluída',
            'assinatura_avulsa_solicitar' => 'Assinatura avulsa — solicitar',
            'assinatura_avulsa_reenviar' => 'Assinatura avulsa — reenviar',
            'pendencia_operacional' => 'Pendência operacional',
            'chamado_financeiro_aberto' => 'Chamado financeiro aberto',
        ];
        $defaults = [];
        foreach ($events as $event => $label) {
            foreach (['whatsapp', 'email'] as $channel) {
                $name = $event . '_' . $channel;
                $subject = $channel === 'email' ? $label . ' — %nomeprovedor%' : '';
                $body = $this->defaultBody($event, $channel);
                $defaults[$name] = [
                    'channel' => $channel,
                    'purpose' => $event,
                    'description' => $label,
                    'subject' => $subject,
                    'default_subject' => $subject,
                    'body' => $body,
                    'default_body' => $body,
                    'variables_json' => self::VARIABLES,
                    'supported_channels_json' => ['whatsapp', 'email'],
                    'enabled_channels_json' => ['whatsapp', 'email'],
                    'active' => 1,
                ];
            }
        }
        $this->repository->ensureDefaults($defaults);
    }

    public function validate(string $subject, string $body, array $enabledChannels): array
    {
        $errors = [];
        if (trim($body) === '') $errors[] = 'O corpo da mensagem é obrigatório.';
        foreach ($enabledChannels as $channel) {
            $definition = $this->channels()[$channel] ?? null;
            if (!is_array($definition) || empty($definition['available']) || empty($definition['adapter'])) {
                $errors[] = 'O canal ' . $channel . ' não possui adapter disponível.';
            }
        }
        foreach ([$subject, $body] as $content) {
            preg_match_all('/%([a-z0-9_]+)%/i', $content, $matches);
            foreach (array_unique($matches[1] ?? []) as $variable) {
                if (!in_array(strtolower((string) $variable), self::VARIABLES, true)) {
                    $errors[] = 'Variável desconhecida: %' . $variable . '%.';
                }
            }
            if (preg_match('/<\?(?:php|=)|<script\b|javascript:/i', $content) === 1) {
                $errors[] = 'Conteúdo executável não é permitido.';
            }
        }
        return array_values(array_unique($errors));
    }

    public function render(array $template, array $values): array
    {
        $replace = [];
        foreach (self::VARIABLES as $variable) {
            $replace['%' . $variable . '%'] = (string) ($values[$variable] ?? '');
        }
        return [
            'subject' => strtr((string) ($template['subject'] ?? ''), $replace),
            'body' => strtr((string) ($template['body'] ?? ''), $replace),
            'template_snapshot' => [
                'id' => (int) ($template['id'] ?? 0),
                'purpose' => (string) ($template['purpose'] ?? ''),
                'channel' => (string) ($template['channel'] ?? ''),
                'version' => (int) ($template['version'] ?? 1),
                'subject' => (string) ($template['subject'] ?? ''),
                'body' => (string) ($template['body'] ?? ''),
            ],
        ];
    }

    private function defaultBody(string $event, string $channel): string
    {
        $prefix = $channel === 'whatsapp' ? 'Olá, %nomecliente%!' : 'Olá, %nomecliente%.';
        if (str_contains($event, 'migracao')) {
            return $prefix . "\n\nSua alteração de %planoatual% para %novoplano% foi preparada. Confira e confirme pelo link: %linkaceite%\n\nProtocolo: %protocoloprocesso%";
        }
        if (str_contains($event, 'chamado_financeiro')) {
            return $prefix . "\n\nO chamado financeiro do processo %protocoloprocesso% foi registrado.";
        }
        if ($event === 'pendencia_operacional') {
            return $prefix . "\n\nExiste uma pendência no processo %protocoloprocesso%. Nossa equipe entrará em contato.";
        }
        return $prefix . "\n\nConfira e confirme sua solicitação pelo link: %linkaceite%\n\nProtocolo: %protocoloprocesso%";
    }
}
