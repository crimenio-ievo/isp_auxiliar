<?php

declare(strict_types=1);

use App\Controllers\ContractController;
use App\Core\Config;
use App\Infrastructure\Contracts\ContractAcceptanceRepository;
use App\Infrastructure\Database\Database;
use App\Services\Contracts\AcceptanceWorkflowService;

require dirname(__DIR__, 2) . '/backend/bootstrap/autoload.php';

// Executa controller e repositório reais sobre PDO em memória, sem conexão.
$pdo = new class extends PDO {
    public array $row = [];
    private ?array $snapshot = null;
    public function __construct() {}
    public function beginTransaction(): bool { $this->snapshot = $this->row; return true; }
    public function inTransaction(): bool { return $this->snapshot !== null; }
    public function commit(): bool { $this->snapshot = null; return true; }
    public function rollBack(): bool { $this->row = $this->snapshot ?? []; $this->snapshot = null; return true; }
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return new class($this, $query) extends PDOStatement {
            public function __construct(private PDO $db, private string $sql) {}
            public function execute(?array $params = null): bool {
                if (str_starts_with($this->sql, 'UPDATE contract_acceptances')) {
                    $this->db->row = array_replace($this->db->row, $params ?? []);
                } elseif (!str_starts_with($this->sql, 'SELECT * FROM contract_acceptances WHERE id =')) {
                    throw new RuntimeException('Consulta fora do escopo do fake.');
                }
                return true;
            }
            public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed { return $this->db->row; }
            public function rowCount(): int { return 1; }
        };
    }
};
$config = new Config(['contracts' => ['acceptance_ttl_hours' => 48]]);
$db = new Database($config);
(new ReflectionProperty(Database::class, 'pdo'))->setValue($db, $pdo);
$controller = (new ReflectionClass(ContractController::class))->newInstanceWithoutConstructor();
foreach (['database' => $db, 'contractAcceptanceRepository' => new ContractAcceptanceRepository($db), 'acceptanceWorkflowService' => new AcceptanceWorkflowService($config)] as $property => $value) {
    (new ReflectionProperty(ContractController::class, $property))->setValue($controller, $value);
}
$prepare = new ReflectionMethod(ContractController::class, 'prepareAcceptanceForDelivery');
$checks = 0;
$assert = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) { throw new RuntimeException($message); }
    $checks++;
};
$oldHash = hash('sha256', 'synthetic-old-token');
$pdo->row = ['id' => 1, 'contract_id' => 1, 'token_hash' => $oldHash, 'token_expires_at' => '2000-01-01 00:00:00', 'status' => 'expirado'];
$result = $prepare->invoke($controller, 1);
$assert($result['rotated'] === true, 'Token expirado não foi renovado.');
$assert($pdo->row['token_hash'] === hash('sha256', $result['token']), 'Hash persistido não corresponde ao token entregue.');
$assert($pdo->row['token_hash'] !== $oldHash, 'Token antigo continuou válido.');
$assert($pdo->row['status'] === 'criado', 'Estado não foi reaberto para confirmação.');
$assert(!$pdo->inTransaction(), 'Transação permaneceu aberta.');
$saved = $pdo->row;
$result = $prepare->invoke($controller, 1);
$assert(!$result['rotated'] && $pdo->row === $saved, 'Reenvio válido alterou token ou prazo.');
$pdo->row['status'] = 'aceito';
$pdo->row['token_expires_at'] = '2000-01-01 00:00:00';
$saved = $pdo->row;
$result = $prepare->invoke($controller, 1);
$assert(!$result['rotated'] && $pdo->row === $saved, 'Aceite concluído foi reaberto.');
foreach (['cancelado', 'revogado'] as $state) {
    $pdo->row['status'] = $state === 'cancelado' ? 'cancelado' : 'criado';
    $pdo->row['revoked_at'] = $state === 'revogado' ? '2000-01-01 00:00:00' : null;
    $saved = $pdo->row;
    $rejected = false;
    try { $prepare->invoke($controller, 1); } catch (RuntimeException) { $rejected = true; }
    $assert($rejected && $saved === $pdo->row && !$pdo->inTransaction(), 'Cancelamento/revogação não foi preservado com rollback.');
}
echo "AcceptanceTokenRotationRegression: {$checks} checks passed; PDO fake, sem banco ou envio.\n";
