<?php

declare(strict_types=1);

namespace App\Services\Logs;

/**
 * Dicionário único de eventos de auditoria: traduz `audit_logs.action` para
 * português simples e classifica cada evento em uma categoria da tela de Logs.
 *
 * Substitui as três traduções que existiam separadas (tela /logs, linha do
 * tempo da ficha do cliente e aba de logs do contrato).
 */
final class AuditEventCatalog
{
    public const CONTRACT = 'contrato';
    public const PROVISIONING = 'provisionamento';
    public const PROCESS = 'processos';
    public const SECURITY = 'seguranca';
    public const SETTINGS = 'configuracoes';
    public const TICKET = 'chamados';
    public const OTHER = 'outros';

    /** @return array<string, array{label: string, hint: string}> */
    public static function categories(): array
    {
        return [
            self::CONTRACT => ['label' => 'Contrato e aceite', 'hint' => 'Contratos, aceites, pendências financeiras e mensagens (WhatsApp/e-mail) enviadas ao cliente.'],
            self::PROVISIONING => ['label' => 'Provisionamento e cadastro', 'hint' => 'Cadastro e instalação de clientes no MkAuth, incluindo o diário técnico de confirmação de plano.'],
            self::PROCESS => ['label' => 'Processos operacionais', 'hint' => 'Migração e instalação: etapas, revisões, conclusões e cancelamentos.'],
            self::SECURITY => ['label' => 'Acesso e segurança', 'hint' => 'Logins, consultas ao termo assinado e bloqueios de gravação indevida no MkAuth.'],
            self::SETTINGS => ['label' => 'Configurações e testes', 'hint' => 'Alterações de configuração e testes de conexão feitos pela equipe.'],
            self::TICKET => ['label' => 'Chamados financeiros (MkAuth)', 'hint' => 'Reflexo local das ações em chamados. O histórico completo real fica no MkAuth (sis_suporte/sis_msg), não aqui.'],
            self::OTHER => ['label' => 'Outros eventos', 'hint' => 'Eventos ainda sem categoria própria.'],
        ];
    }

    /** @var array<string, string> */
    private const LABELS = [
        'auth.login' => 'Login realizado.',
        'auth.remote_admin_detected' => 'Acesso administrativo remoto detectado.',
        'provider_settings.updated' => 'Configurações do provedor atualizadas.',
        'client.provisioned' => 'Cliente enviado ao MkAuth.',
        'client.provision_failed' => 'Falha no envio do cliente ao MkAuth.',
        'client.provision_blocked' => 'Envio do cliente ao MkAuth bloqueado.',
        'client.plan_not_confirmed' => 'Plano do cliente não confirmado no MkAuth.',
        'client.local_finalize_failed' => 'Falha ao finalizar o cadastro local.',
        'client.contact.corrected' => 'Contato do cliente corrigido.',
        'installation.completed' => 'Instalação finalizada.',
        'installation.deleted' => 'Registro local de instalação excluído.',
        'client.acceptance.email.sent' => 'Aceite enviado por e-mail.',
        'client.acceptance.whatsapp.sent' => 'Aceite enviado por WhatsApp.',
        'client.acceptance.dispatch_failed' => 'Falha no envio do aceite.',
        'client.acceptance.email.failed' => 'Falha no envio do aceite por e-mail.',
        'client.acceptance.whatsapp.failed' => 'Falha no envio do aceite por WhatsApp.',
        'contract.created' => 'Contrato criado.',
        'contract.updated' => 'Contrato atualizado.',
        'contract.digital.created' => 'Contrato digital gerado.',
        'contract.digital.dispatch_failed' => 'Falha no envio do contrato digital.',
        'contract.flow_failed' => 'Falha no fluxo do contrato.',
        'contract.templates.sync_failed' => 'Falha ao sincronizar modelos de mensagem.',
        'contract.acceptance.created' => 'Aceite preparado.',
        'contract.acceptance.local_prepared' => 'Aceite local preparado.',
        'contract.acceptance.updated' => 'Aceite atualizado.',
        'contract.acceptance.accepted' => 'Cliente confirmou o aceite.',
        'contract.acceptance.confirmed' => 'Cliente confirmou o aceite.',
        'contract.acceptance.email.sent' => 'Aceite enviado por e-mail.',
        'contract.acceptance.email.failed' => 'Falha no envio do aceite por e-mail.',
        'contract.acceptance.whatsapp.sent' => 'Aceite enviado por WhatsApp.',
        'contract.acceptance.whatsapp.failed' => 'Falha no envio do aceite por WhatsApp.',
        'contract.acceptance.document_validation_failed' => 'CPF/CNPJ informado no aceite não conferiu.',
        'contract.acceptance.document_validation_unavailable' => 'Validação de CPF/CNPJ indisponível no aceite.',
        'contract.signed_term.operator_viewed' => 'Termo assinado consultado por operador.',
        'contract.migration.acceptance.prepared' => 'Assinatura e confirmação preparadas.',
        'contract.financial_task.created' => 'Pendência financeira criada.',
        'contract.financial_task.updated' => 'Pendência financeira atualizada.',
        'contract.financial_task.completed' => 'Pendência financeira concluída.',
        'contract.financial_task.canceled' => 'Pendência financeira cancelada.',
        'contract.upgrade.created' => 'Nova condição de migração preparada.',
        'contract.upgrade.pending_condition_revised' => 'Condição pendente corrigida.',
        'contract.upgrade.correction_started' => 'Substituição da condição iniciada.',
        'contract.upgrade.superseded' => 'Condição anterior substituída.',
        'contract.upgrade.cancelled' => 'Migração cancelada.',
        'contract.upgrade.dispatch_failed' => 'Falha no envio da condição de migração.',
        'contract.upgrade.operational_task.created' => 'Pendência operacional criada.',
        'contract.upgrade.operational_task.failed' => 'Falha ao criar a pendência operacional.',
        'contract.upgrade.technical_task_sync_failed' => 'Falha ao sincronizar a tarefa técnica.',
        'contract.financial.closed' => 'Chamado financeiro encerrado.',
        'financial_task.mkauth_ticket.closed' => 'Chamado financeiro encerrado.',
        'financial_task.mkauth_ticket.checked' => 'Status do chamado verificado no MkAuth.',
        'financial_task.mkauth_ticket.ambiguous' => 'Chamado financeiro ambíguo no MkAuth.',
        'financial_task.mkauth_ticket.not_found' => 'Chamado financeiro não encontrado no MkAuth.',
        'contract.financial_task.ticket.created' => 'Chamado financeiro aberto no MkAuth.',
        'contract.financial_task.ticket.failed' => 'Falha ao abrir chamado financeiro.',
        'contract.financial_task.ticket.duplicate_attempt' => 'Tentativa duplicada de abrir chamado financeiro.',
        'contract.financial_task.ticket.message_inserted' => 'Mensagem registrada no chamado financeiro.',
        'contract.financial_task.ticket.message_failed' => 'Falha ao registrar mensagem no chamado financeiro.',
        'client.financial_task.ticket.auto_created' => 'Chamado financeiro aberto automaticamente.',
        'client.financial_task.ticket.auto_failed' => 'Falha ao abrir chamado financeiro automático.',
        'client.financial_task.ticket.message_inserted' => 'Mensagem registrada no chamado financeiro.',
        'client.financial_task.ticket.message_failed' => 'Falha ao registrar mensagem no chamado financeiro.',
        'client.contact.correction.ticket.created' => 'Chamado de correção de contato aberto.',
        'client.contact.correction.ticket.failed' => 'Falha ao abrir chamado de correção de contato.',
        'client.contact.correction.ticket.message_inserted' => 'Mensagem registrada no chamado de correção de contato.',
        'client.contact.correction.ticket.message_failed' => 'Falha ao registrar mensagem no chamado de correção de contato.',
        'client.contact.correction.dispatch_failed' => 'Falha no envio da correção de contato.',
        'operational_process.completed' => 'Migração concluída.',
        'operational_process.completed_automatically' => 'Processo concluído automaticamente.',
        'operational_process.cancelled' => 'Migração cancelada.',
        'operational_process.migration.draft_started' => 'Rascunho de migração iniciado.',
        'operational_process.migration.revised' => 'Condição da migração revisada.',
        'operational_process.migration.contact_corrected' => 'Contato corrigido durante a migração.',
        'operational_process.step.evidence_updated' => 'Evidência da etapa atualizada.',
        'settings.smtp.test' => 'Teste de conexão de e-mail (SMTP).',
        'settings.evotrix.test' => 'Teste de conexão do WhatsApp (Evotrix).',
        'settings.mkauth.test' => 'Teste de conexão com o MkAuth.',
        'settings.query_ai_models.failed' => 'Falha ao consultar modelos de IA.',
        'settings.test_ai_minimal.failed' => 'Falha no teste de IA.',
        'ai.configuration.saved' => 'Configuração de IA salva.',
        'ai.configuration.failed' => 'Falha ao salvar a configuração de IA.',
        'release.channel.preference_changed' => 'Canal de versão alterado.',
        'manager_user.updated' => 'Usuário gestor atualizado.',
        'clients_v2.list_failed' => 'Falha ao listar clientes no MkAuth.',
    ];

    public static function label(string $action): string
    {
        $action = trim($action);
        if ($action === '') {
            return 'Evento registrado.';
        }
        if (isset(self::LABELS[$action])) {
            return self::LABELS[$action];
        }
        if (str_starts_with($action, 'operational_process.step.')) {
            return 'Etapa operacional atualizada.';
        }

        return 'Registro operacional atualizado.';
    }

    public static function category(string $action, string $entityType = ''): string
    {
        $action = trim($action);

        if (str_contains($action, '.ticket.') || str_contains($action, 'mkauth_ticket')) {
            return self::TICKET;
        }
        if ($action === 'contract.upgrade.operational_task.created' || $action === 'contract.upgrade.operational_task.failed'
            || $action === 'contract.upgrade.technical_task_sync_failed' || str_starts_with($action, 'operational_process.')) {
            return self::PROCESS;
        }
        if (str_starts_with($action, 'auth.') || $action === 'contract.signed_term.operator_viewed') {
            return self::SECURITY;
        }
        if (str_starts_with($action, 'settings.') || str_starts_with($action, 'provider_settings.')
            || str_starts_with($action, 'ai.') || str_starts_with($action, 'release.')
            || in_array($action, ['installation.deleted', 'manager_user.updated'], true)) {
            return self::SETTINGS;
        }
        if (str_starts_with($action, 'client.acceptance.') || str_starts_with($action, 'contract.')) {
            return self::CONTRACT;
        }
        if (str_starts_with($action, 'client.') || str_starts_with($action, 'installation.')
            || in_array($action, ['create_failed', 'create_recovered_by_readback', 'plan_confirmed', 'plan_not_confirmed', 'started'], true)) {
            return self::PROVISIONING;
        }

        return match ($entityType) {
            'client_contract', 'contract_acceptance', 'financial_task', 'client_acceptance' => self::CONTRACT,
            'client_registration', 'installation_checkpoint' => self::PROVISIONING,
            'operational_process', 'operational_process_step' => self::PROCESS,
            'settings', 'provider', 'release_channel' => self::SETTINGS,
            default => self::OTHER,
        };
    }

    /** Falha deduzida do nome do evento — não existe coluna de severidade em audit_logs. */
    public static function isFailure(string $action): bool
    {
        return (bool) preg_match('/(failed|_failed|blocked|not_found|ambiguous|not_confirmed|validation_unavailable)/', $action);
    }
}
