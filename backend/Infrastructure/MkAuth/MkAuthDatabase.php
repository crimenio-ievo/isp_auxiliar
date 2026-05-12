<?php

declare(strict_types=1);

namespace App\Infrastructure\MkAuth;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Acesso de consulta ao banco do MkAuth.
 *
 * Esta camada deve ser usada com um usuario MySQL somente leitura, limitado
 * aos dados que o isp_auxiliar realmente precisa consultar.
 */
final class MkAuthDatabase
{
    private ?PDO $pdo = null;

    public function __construct(
        private string $host,
        private string $port,
        private string $database,
        private string $username,
        private string $password,
        private string $charset = 'utf8mb4',
        private string $hashAlgos = 'sha256,sha1'
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->host !== '' && $this->database !== '' && $this->username !== '';
    }

    public function authenticateUser(string $login, string $password): ?array
    {
        $user = $this->findAccessUserByLogin($login);

        if ($user === null) {
            return null;
        }

        $storedHash = strtolower(trim((string) ($user['sha'] ?? '')));
        if ($storedHash === '') {
            return null;
        }

        if ($this->verifyStoredPassword($password, (string) ($user['sha'] ?? ''))) {
            return $user;
        }

        // A tela oficial do MkAuth envia SHA256(senha) para o backend.
        // O campo sis_acesso.sha guarda o bcrypt desse valor transformado.
        if ($this->verifyStoredPassword(hash('sha256', $password), (string) ($user['sha'] ?? ''))) {
            return $user;
        }

        foreach ($this->candidateHashes($password) as $candidate) {
            if (hash_equals($storedHash, strtolower($candidate))) {
                return $user;
            }
        }

        return null;
    }

    public function findAccessUserByLogin(string $login): ?array
    {
        $rows = $this->fetchAll(
            'SELECT * FROM sis_acesso WHERE LOWER(login) = LOWER(:login) LIMIT 1',
            ['login' => trim($login)]
        );

        return $rows[0] ?? null;
    }

    public function isStrongAdminUser(array $user): bool
    {
        $requiredFields = ['perm_inserir', 'perm_alterar', 'perm_excluir', 'perm_chaminserir', 'perm_chamInserir'];

        foreach ($requiredFields as $field) {
            if (!$this->normalizeBoolean($this->arrayGetCaseInsensitive($user, $field))) {
                return false;
            }
        }

        return true;
    }

    public function remotePermissionSummary(array $user): array
    {
        return [
            'perm_inserir' => $this->normalizeBoolean($this->arrayGetCaseInsensitive($user, 'perm_inserir')),
            'perm_alterar' => $this->normalizeBoolean($this->arrayGetCaseInsensitive($user, 'perm_alterar')),
            'perm_excluir' => $this->normalizeBoolean($this->arrayGetCaseInsensitive($user, 'perm_excluir')),
            'perm_chaminserir' => $this->normalizeBoolean($this->arrayGetCaseInsensitive($user, 'perm_chaminserir')),
            'perm_chamInserir' => $this->normalizeBoolean($this->arrayGetCaseInsensitive($user, 'perm_chamInserir')),
            'perm_cham_inserir' => $this->normalizeBoolean($this->arrayGetCaseInsensitive($user, 'perm_cham_inserir')),
        ];
    }

    public function listAccessUsers(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        return $this->fetchAll(
            'SELECT * FROM sis_acesso ORDER BY login ASC LIMIT ' . (int) $limit
        );
    }

    public function countAccessUsers(): int
    {
        return $this->countRows('SELECT COUNT(*) FROM sis_acesso');
    }

    public function countClients(): int
    {
        return $this->countRows('SELECT COUNT(*) FROM sis_cliente WHERE login IS NOT NULL AND login <> ""');
    }

    public function countActivePlans(): int
    {
        return $this->countRows("SELECT COUNT(*) FROM sis_plano WHERE nome <> '' AND oculto = 'nao'");
    }

    public function clientExistsByLogin(string $login): bool
    {
        return $this->rowExists(
            'SELECT 1 FROM DUAL
             WHERE EXISTS (
                 SELECT 1 FROM sis_cliente WHERE LOWER(login) = LOWER(:login_cliente) LIMIT 1
             )
             OR EXISTS (
                 SELECT 1 FROM sis_adicional
                 WHERE LOWER(login) = LOWER(:login_adicional) OR LOWER(username) = LOWER(:username_adicional)
                 LIMIT 1
             )',
            [
                'login_cliente' => trim($login),
                'login_adicional' => trim($login),
                'username_adicional' => trim($login),
            ]
        );
    }

    public function radiusConnectionStatus(string $login): array
    {
        $login = trim($login);

        if ($login === '') {
            return [
                'online' => false,
                'session' => null,
                'last_auth' => null,
            ];
        }

        $session = $this->fetchOne(
            'SELECT username, framedipaddress, nasipaddress, callingstationid, acctstarttime, acctupdatetime
             FROM radacct
             WHERE LOWER(username) = LOWER(:login) AND acctstoptime IS NULL
             ORDER BY acctstarttime DESC
             LIMIT 1',
            ['login' => $login]
        );

        $lastAuth = $this->fetchOne(
            'SELECT username, reply, authdate, ip, mac, ramal
             FROM radpostauth
             WHERE LOWER(username) = LOWER(:login)
             ORDER BY authdate DESC
             LIMIT 1',
            ['login' => $login]
        );

        return [
            'online' => $session !== null,
            'session' => $session,
            'last_auth' => $lastAuth,
        ];
    }

    public function clientExistsByCpfCnpj(string $cpfCnpj): bool
    {
        $digits = preg_replace('/\D+/', '', $cpfCnpj) ?? '';

        if ($digits === '') {
            return false;
        }

        return $this->rowExists(
            'SELECT 1 FROM sis_cliente WHERE REPLACE(REPLACE(REPLACE(cpf_cnpj, ".", ""), "-", ""), "/", "") = :cpf_cnpj LIMIT 1',
            ['cpf_cnpj' => $digits]
        );
    }

    public function findClientByLoginOrCpfCnpj(string $login, string $cpfCnpj): ?array
    {
        $login = trim($login);
        $digits = preg_replace('/\D+/', '', $cpfCnpj) ?? '';

        if ($login === '' && $digits === '') {
            return null;
        }

        $conditions = [];
        $params = [];

        if ($login !== '') {
            $conditions[] = 'LOWER(login) = LOWER(:login)';
            $params['login'] = $login;
        }

        if ($digits !== '') {
            $conditions[] = 'REPLACE(REPLACE(REPLACE(cpf_cnpj, ".", ""), "-", ""), "/", "") = :cpf_cnpj';
            $params['cpf_cnpj'] = $digits;
        }

        $rows = $this->fetchAll(
            'SELECT login, cpf_cnpj, nome, uuid_cliente FROM sis_cliente WHERE ' . implode(' OR ', $conditions) . ' LIMIT 1',
            $params
        );

        return $rows[0] ?? null;
    }

    public function listPlans(int $limit = 300): array
    {
        $limit = max(1, min(500, $limit));

        return $this->fetchAll(
            "SELECT nome, valor, oculto FROM sis_plano WHERE nome <> \"\" AND oculto = 'nao' ORDER BY nome ASC LIMIT " . (int) $limit
        );
    }

    public function listDueDays(): array
    {
        $rows = $this->fetchAll(
            "SELECT venc, COUNT(*) AS total FROM sis_cliente WHERE venc REGEXP '^[0-9][0-9]$' GROUP BY venc ORDER BY CAST(venc AS UNSIGNED)"
        );

        $days = [];

        foreach ($rows as $row) {
            $day = str_pad((string) ((int) ($row['venc'] ?? 0)), 2, '0', STR_PAD_LEFT);

            if ($day !== '00') {
                $days[] = [
                    'day' => $day,
                    'total' => (int) ($row['total'] ?? 0),
                ];
            }
        }

        return $days;
    }

    public function listKnownCities(int $limit = 300): array
    {
        $limit = max(1, min(500, $limit));

        return $this->fetchAll(
            'SELECT cidade AS name, estado AS uf, cidade_ibge AS ibge
             FROM sis_cliente
             WHERE cidade IS NOT NULL AND cidade <> "" AND estado IS NOT NULL AND estado <> ""
             GROUP BY cidade, estado, cidade_ibge
             ORDER BY cidade ASC
             LIMIT ' . (int) $limit
        );
    }

    public function defaultContract(): ?array
    {
        $rows = $this->fetchAll(
            "SELECT codigo, nome FROM sis_contrato
             WHERE ativo = 'sim'
             ORDER BY padrao = 'sim' DESC, id DESC
             LIMIT 1"
        );

        return $rows[0] ?? null;
    }

    public function defaultBillingAccount(): ?array
    {
        $rows = $this->fetchAll(
            "SELECT id, nome, gateway, banco FROM sis_boleto
             WHERE nome = 'GerenciaNet Boleto' OR gateway = 'gnet' OR banco = 'gnet.php'
             ORDER BY nome = 'GerenciaNet Boleto' DESC, id ASC
             LIMIT 1"
        );

        return $rows[0] ?? null;
    }

    public function findLatestTicketMessage(string $ticket): ?array
    {
        $ticket = trim($ticket);

        if ($ticket === '') {
            return null;
        }

        return $this->fetchOne(
            'SELECT id, chamado, msg, tipo, login, atendente, msg_data
             FROM sis_msg
             WHERE chamado = :chamado
             ORDER BY id DESC
             LIMIT 1',
            ['chamado' => $ticket]
        );
    }

    public function insertTicketMessage(
        string $ticket,
        string $message,
        string $login,
        string $tipo = 'provedor',
        string $atendente = 'API'
    ): ?int {
        $ticket = trim($ticket);
        $message = trim($message);
        $login = trim($login);
        $tipo = trim($tipo) !== '' ? trim($tipo) : 'provedor';
        $atendente = trim($atendente) !== '' ? trim($atendente) : 'API';

        if ($ticket === '' || $message === '') {
            return null;
        }

        $latest = $this->findLatestTicketMessage($ticket);
        if (is_array($latest)) {
            $latestId = (int) ($latest['id'] ?? 0);
            $existingMessage = trim((string) ($latest['msg'] ?? ''));

            if ($latestId > 0 && $existingMessage === '') {
                $this->connection()->prepare(
                    'UPDATE sis_msg
                     SET msg = :msg,
                         tipo = :tipo,
                         login = :login,
                         atendente = :atendente,
                         msg_data = COALESCE(msg_data, NOW())
                     WHERE id = :id'
                )->execute([
                    'id' => $latestId,
                    'msg' => $message,
                    'tipo' => $tipo,
                    'login' => $login,
                    'atendente' => $atendente,
                ]);

                return $latestId;
            }

            if ($existingMessage !== '') {
                return $latestId > 0 ? $latestId : null;
            }
        }

        $this->connection()->prepare(
            'INSERT INTO sis_msg
                (chamado, msg, tipo, login, atendente, msg_data)
             VALUES
                (:chamado, :msg, :tipo, :login, :atendente, NOW())'
        )->execute([
            'chamado' => $ticket,
            'msg' => $message,
            'tipo' => $tipo,
            'login' => $login,
            'atendente' => $atendente,
        ]);

        return (int) $this->connection()->lastInsertId();
    }

    private function connection(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        if (!$this->isConfigured()) {
            throw new RuntimeException('Banco MkAuth nao configurado.');
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $this->host,
            $this->port,
            $this->database,
            $this->charset
        );

        try {
            $this->pdo = new PDO($dsn, $this->username, $this->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $exception) {
            throw new RuntimeException('Falha ao conectar no banco MkAuth: ' . $exception->getMessage(), 0, $exception);
        }

        return $this->pdo;
    }

    private function fetchAll(string $sql, array $params = []): array
    {
        $statement = $this->connection()->prepare($sql);
        $statement->execute($params);

        $rows = $statement->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    private function fetchOne(string $sql, array $params = []): ?array
    {
        $statement = $this->connection()->prepare($sql);
        $statement->execute($params);

        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    private function rowExists(string $sql, array $params = []): bool
    {
        $statement = $this->connection()->prepare($sql);
        $statement->execute($params);

        return (bool) $statement->fetchColumn();
    }

    private function countRows(string $sql, array $params = []): int
    {
        $statement = $this->connection()->prepare($sql);
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }

    private function arrayGetCaseInsensitive(array $array, string $key): mixed
    {
        if (array_key_exists($key, $array)) {
            return $array[$key];
        }

        $lowerKey = strtolower($key);
        foreach ($array as $candidateKey => $value) {
            if (strtolower((string) $candidateKey) === $lowerKey) {
                return $value;
            }
        }

        return null;
    }

    private function normalizeBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes', 'sim', 'on'], true);
    }

    private function candidateHashes(string $password): array
    {
        $algos = array_filter(array_map('trim', explode(',', $this->hashAlgos)));

        if ($algos === []) {
            $algos = ['sha256', 'sha1'];
        }

        $candidates = [];

        foreach ($algos as $algo) {
            if ($algo === '') {
                continue;
            }

            if (in_array($algo, hash_algos(), true)) {
                $candidates[] = hash($algo, $password);
            }
        }

        return $candidates;
    }

    private function verifyStoredPassword(string $password, string $storedHash): bool
    {
        $storedHash = trim($storedHash);

        if ($storedHash === '') {
            return false;
        }

        if (str_starts_with($storedHash, '$2y$') || str_starts_with($storedHash, '$2a$') || str_starts_with($storedHash, '$2b$') || str_starts_with($storedHash, '$argon2')) {
            return password_verify($password, $storedHash);
        }

        if (strlen($storedHash) === 40 && ctype_xdigit($storedHash)) {
            return hash_equals(strtolower($storedHash), sha1($password));
        }

        if (strlen($storedHash) === 64 && ctype_xdigit($storedHash)) {
            return hash_equals(strtolower($storedHash), hash('sha256', $password));
        }

        return false;
    }
}
