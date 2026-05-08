<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use App\Infrastructure\Contracts\NotificationLogRepository;
use LogicException;
use RuntimeException;

/**
 * Envio manual de aceite por e-mail com SMTP autenticado.
 *
 * Mantém DRY_RUN por padrão e permite travar o envio em endereço de teste.
 */
final class EmailService
{
    public function __construct(
        private array $config,
        private NotificationLogRepository $notificationLogs
    ) {
    }

    public function sendAcceptanceEmail(
        string $recipient,
        string $subject,
        string $htmlBody,
        string $textBody,
        ?int $contractId = null,
        ?int $acceptanceId = null
    ): array {
        $recipient = trim($recipient);
        $subject = trim($subject);
        $htmlBody = trim($htmlBody);
        $textBody = trim($textBody);
        $enabled = (bool) ($this->config['enabled'] ?? false);
        $dryRun = (bool) ($this->config['dry_run'] ?? true);
        $allowOnlyTest = (bool) ($this->config['allow_only_test_email'] ?? true);
        $testTo = trim((string) ($this->config['test_to'] ?? ''));

        if ($subject === '' || ($htmlBody === '' && $textBody === '')) {
            throw new LogicException('Assunto e conteudo do e-mail sao obrigatorios.');
        }

        $actualRecipient = $recipient;
        $redirectedToTest = false;

        if ($allowOnlyTest) {
            if ($testTo === '') {
                throw new RuntimeException('EMAIL_ALLOW_ONLY_TEST_EMAIL esta ativo, mas EMAIL_TEST_TO nao foi configurado.');
            }

            $actualRecipient = $testTo;
            $redirectedToTest = strcasecmp($recipient, $actualRecipient) !== 0;
        }

        if ($actualRecipient === '') {
            throw new LogicException('Destinatario do e-mail nao foi informado.');
        }

        $lastAttempt = $this->notificationLogs->findLatestForRecipient(
            $contractId,
            $acceptanceId,
            'email',
            'smtp',
            $actualRecipient
        );

        if (is_array($lastAttempt) && in_array((string) ($lastAttempt['status'] ?? ''), ['simulado', 'enviado'], true)) {
            $response = [
                'status' => (string) ($lastAttempt['status'] ?? 'simulado'),
                'provider' => 'smtp',
                'channel' => 'email',
                'dry_run' => $dryRun,
                'enabled' => $enabled,
                'recipient' => $actualRecipient,
                'original_recipient' => $recipient,
                'redirected_to_test' => $redirectedToTest,
                'queued_at' => date('Y-m-d H:i:s'),
                'repeated_attempt' => true,
                'detail' => 'Ja existe um envio preparado anteriormente para este aceite.',
            ];

            $this->notificationLogs->create([
                'contract_id' => $contractId,
                'acceptance_id' => $acceptanceId,
                'channel' => 'email',
                'provider' => 'smtp',
                'recipient' => $actualRecipient,
                'message' => $subject,
                'status' => (string) ($lastAttempt['status'] ?? 'simulado'),
                'provider_response' => $response,
            ]);

            return $response;
        }

        if ($enabled && !$dryRun) {
            $response = $this->dispatchRealEmail($actualRecipient, $subject, $htmlBody, $textBody);
            $response['original_recipient'] = $recipient;
            $response['redirected_to_test'] = $redirectedToTest;

            $this->notificationLogs->create([
                'contract_id' => $contractId,
                'acceptance_id' => $acceptanceId,
                'channel' => 'email',
                'provider' => 'smtp',
                'recipient' => $actualRecipient,
                'message' => $subject,
                'status' => 'enviado',
                'provider_response' => $response,
            ]);

            return $response;
        }

        $response = [
            'status' => 'simulado',
            'provider' => 'smtp',
            'channel' => 'email',
            'dry_run' => true,
            'enabled' => $enabled,
            'recipient' => $actualRecipient,
            'original_recipient' => $recipient,
            'redirected_to_test' => $redirectedToTest,
            'queued_at' => date('Y-m-d H:i:s'),
            'message' => 'Envio de e-mail preparado em modo seguro.',
        ];

        $this->notificationLogs->create([
            'contract_id' => $contractId,
            'acceptance_id' => $acceptanceId,
            'channel' => 'email',
            'provider' => 'smtp',
            'recipient' => $actualRecipient,
            'message' => $subject,
            'status' => 'simulado',
            'provider_response' => $response,
        ]);

        return $response;
    }

    private function dispatchRealEmail(string $recipient, string $subject, string $htmlBody, string $textBody): array
    {
        $host = trim((string) ($this->config['smtp_host'] ?? ''));
        $port = (int) ($this->config['smtp_port'] ?? 587);
        $username = trim((string) ($this->config['smtp_username'] ?? ''));
        $password = (string) ($this->config['smtp_password'] ?? '');
        $encryption = strtolower(trim((string) ($this->config['smtp_encryption'] ?? 'tls')));
        $from = trim((string) ($this->config['smtp_from'] ?? ''));
        $fromName = trim((string) ($this->config['smtp_from_name'] ?? 'ISP Auxiliar'));

        if ($host === '' || $username === '' || $password === '' || $from === '') {
            throw new RuntimeException('SMTP precisa de host, usuario, senha e remetente configurados.');
        }

        $transport = $encryption === 'ssl' ? 'ssl://' . $host : $host;
        $socket = @stream_socket_client($transport . ':' . $port, $errno, $errstr, 20);

        if (!is_resource($socket)) {
            throw new RuntimeException('Nao foi possivel conectar ao SMTP: ' . $errstr);
        }

        stream_set_timeout($socket, 20);

        $this->expectSmtp($socket, [220]);
        $this->writeSmtp($socket, 'EHLO isp-auxiliar.local');
        $this->expectSmtp($socket, [250]);

        if ($encryption === 'tls') {
            $this->writeSmtp($socket, 'STARTTLS');
            $this->expectSmtp($socket, [220]);

            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                fclose($socket);
                throw new RuntimeException('Nao foi possivel iniciar TLS no SMTP.');
            }

            $this->writeSmtp($socket, 'EHLO isp-auxiliar.local');
            $this->expectSmtp($socket, [250]);
        }

        $this->writeSmtp($socket, 'AUTH LOGIN');
        $this->expectSmtp($socket, [334]);
        $this->writeSmtp($socket, base64_encode($username));
        $this->expectSmtp($socket, [334]);
        $this->writeSmtp($socket, base64_encode($password));
        $this->expectSmtp($socket, [235]);

        $this->writeSmtp($socket, 'MAIL FROM:<' . $from . '>');
        $this->expectSmtp($socket, [250]);
        $this->writeSmtp($socket, 'RCPT TO:<' . $recipient . '>');
        $this->expectSmtp($socket, [250, 251]);
        $this->writeSmtp($socket, 'DATA');
        $this->expectSmtp($socket, [354]);

        $boundary = 'ispaux-' . bin2hex(random_bytes(8));
        $headers = [
            'From: ' . $this->encodeHeaderName($fromName) . ' <' . $from . '>',
            'To: <' . $recipient . '>',
            'Subject: ' . $this->encodeHeaderName($subject),
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];

        $message = implode("\r\n", $headers) . "\r\n\r\n"
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $textBody . "\r\n\r\n"
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $htmlBody . "\r\n\r\n"
            . '--' . $boundary . "--\r\n.";

        $this->writeSmtp($socket, $message);
        $this->expectSmtp($socket, [250]);
        $this->writeSmtp($socket, 'QUIT');
        fclose($socket);

        return [
            'status' => 'enviado',
            'provider' => 'smtp',
            'channel' => 'email',
            'dry_run' => false,
            'enabled' => true,
            'recipient' => $recipient,
            'queued_at' => date('Y-m-d H:i:s'),
            'message' => 'E-mail enviado com sucesso por SMTP autenticado.',
        ];
    }

    private function writeSmtp($socket, string $command): void
    {
        fwrite($socket, $command . "\r\n");
    }

    private function expectSmtp($socket, array $expectedCodes): void
    {
        $response = '';

        while (($line = fgets($socket, 515)) !== false) {
            $response .= $line;

            if (preg_match('/^\d{3}\s/', $line) === 1) {
                break;
            }
        }

        $code = (int) substr($response, 0, 3);

        if (!in_array($code, $expectedCodes, true)) {
            throw new RuntimeException('SMTP retornou erro: ' . trim($response));
        }
    }

    private function encodeHeaderName(string $value): string
    {
        if ($value === '') {
            return '';
        }

        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
}
