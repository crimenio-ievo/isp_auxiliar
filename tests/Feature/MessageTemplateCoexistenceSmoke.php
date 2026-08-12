<?php

declare(strict_types=1);

use App\Core\Env;
use App\Infrastructure\Contracts\MessageTemplateRepository;
use App\Infrastructure\Database\Database;
use App\Infrastructure\Local\LocalRepository;

require dirname(__DIR__, 2) . '/backend/bootstrap/app.php';

$root = dirname(__DIR__, 2);
$app = bootstrapApplication();
$database = new Database($app->config());
$pdo = $database->pdo();
$local = new LocalRepository($database, (string) Env::get('APP_PROVIDER_KEY', 'default'));
$templates = new MessageTemplateRepository($database, $local);
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
};

$migration = (string) file_get_contents($root . '/database/migrations/017_messages_and_scanned_contracts.sql');
$token = '__coexist_' . bin2hex(random_bytes(6));

try {
    $providerColumn = $database->fetchOne(
        'SELECT IS_NULLABLE, COLUMN_TYPE
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column',
        ['table' => 'message_templates', 'column' => 'provider_id']
    );
    $assert(($providerColumn['IS_NULLABLE'] ?? '') === 'YES', 'provider_id deixou de aceitar NULL.');
    $assert(str_contains((string) ($providerColumn['COLUMN_TYPE'] ?? ''), 'bigint'), 'provider_id não possui o tipo esperado.');
    $assert(!str_contains($migration, 'MODIFY provider_id BIGINT UNSIGNED NOT NULL'), 'A migration ainda força provider_id como NOT NULL.');
    $assert(!str_contains($migration, 'DROP INDEX message_templates_name_channel'), 'A migration ainda remove a chave legada.');
    $assert(str_contains($migration, 'AND (SELECT COUNT(*) FROM providers) = 1'), 'O backfill não está protegido contra provedor ambíguo.');

    $indexRows = $database->fetchAll(
        'SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table
           AND INDEX_NAME IN (:legacy, :provider)',
        [
            'table' => 'message_templates',
            'legacy' => 'message_templates_name_channel',
            'provider' => 'message_templates_provider_name_channel',
        ]
    );
    $indexNames = array_values(array_unique(array_column($indexRows, 'INDEX_NAME')));
    $assert(in_array('message_templates_name_channel', $indexNames, true), 'Chave única legada ausente.');
    $assert(in_array('message_templates_provider_name_channel', $indexNames, true), 'Chave única por provedor ausente.');
    $assert(count(array_filter($indexRows, static fn (array $row): bool => (int) $row['NON_UNIQUE'] !== 0)) === 0, 'Uma chave de coexistência não é única.');

    $foreignKey = $database->fetchOne(
        'SELECT DELETE_RULE
         FROM information_schema.REFERENTIAL_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = :table AND CONSTRAINT_NAME = :constraint',
        ['table' => 'message_templates', 'constraint' => 'fk_message_templates_provider']
    );
    $assert(($foreignKey['DELETE_RULE'] ?? '') === 'RESTRICT', 'FK nullable de provider_id ausente ou divergente.');

    $pdo->beginTransaction();

    $legacyInsert = $pdo->prepare(
        'INSERT INTO message_templates
            (name, channel, purpose, body, variables_json, active, created_at, updated_at)
         VALUES
            (:name, :channel, :purpose, :body, NULL, 1, NOW(), NOW())'
    );
    $legacyInsert->execute([
        'name' => $token . '_legacy',
        'channel' => 'email',
        'purpose' => $token . '_purpose',
        'body' => 'Corpo legado inicial',
    ]);
    $legacyId = (int) $pdo->lastInsertId();
    $legacy = $database->fetchOne('SELECT * FROM message_templates WHERE id = :id', ['id' => $legacyId]);
    $assert(is_array($legacy) && $legacy['provider_id'] === null, 'INSERT legado sem provider_id não permaneceu válido.');

    $duplicateBlocked = false;
    try {
        $legacyInsert->execute([
            'name' => $token . '_legacy',
            'channel' => 'email',
            'purpose' => $token . '_duplicate',
            'body' => 'Duplicado',
        ]);
    } catch (PDOException $exception) {
        $duplicateBlocked = (int) ($exception->errorInfo[1] ?? 0) === 1062;
    }
    $assert($duplicateBlocked, 'A unicidade legada (name, channel) não bloqueou duplicidade.');

    $secondProvider = $pdo->prepare(
        'INSERT INTO providers (name, slug, status, created_at, updated_at)
         VALUES (:name, :slug, :status, NOW(), NOW())'
    );
    $secondProvider->execute([
        'name' => 'Coexistence Test Provider',
        'slug' => $token . '_provider',
        'status' => 'inactive',
    ]);
    $pdo->exec(
        'UPDATE message_templates
         SET provider_id = (SELECT MIN(id) FROM providers)
         WHERE provider_id IS NULL
           AND (SELECT COUNT(*) FROM providers) = 1'
    );
    $legacyAfterAmbiguousBackfill = $database->fetchOne(
        'SELECT provider_id FROM message_templates WHERE id = :id',
        ['id' => $legacyId]
    );
    $assert(($legacyAfterAmbiguousBackfill['provider_id'] ?? null) === null, 'Backfill associou linha quando havia mais de um provedor.');

    $assert((int) ($templates->findById($legacyId)['id'] ?? 0) === $legacyId, 'Beta não localizou template legado por ID.');
    $assert((int) ($templates->findByName($token . '_legacy', 'email')['id'] ?? 0) === $legacyId, 'Beta não localizou template legado por nome.');
    $assert((int) ($templates->findByPurpose($token . '_purpose', 'email')['id'] ?? 0) === $legacyId, 'Beta não localizou template legado por finalidade.');

    $ids = $templates->ensureDefaults([
        $token . '_legacy' => [
            'channel' => 'email',
            'purpose' => $token . '_purpose',
            'body' => 'Não deve duplicar',
        ],
    ]);
    $assert((int) ($ids[$token . '_legacy'] ?? 0) === $legacyId, 'ensureDefaults não reutilizou a linha legada.');
    $sameNameCount = $database->fetchOne(
        'SELECT COUNT(*) AS total FROM message_templates WHERE name = :name AND channel = :channel',
        ['name' => $token . '_legacy', 'channel' => 'email']
    );
    $assert((int) ($sameNameCount['total'] ?? 0) === 1, 'ensureDefaults criou template duplicado.');

    $templates->upsertByName($token . '_legacy', [
        'channel' => 'email',
        'purpose' => $token . '_purpose',
        'body' => 'Corpo legado atualizado pela Beta',
        'active' => true,
    ], 'email');
    $updatedLegacy = $database->fetchOne('SELECT provider_id, body FROM message_templates WHERE id = :id', ['id' => $legacyId]);
    $assert(($updatedLegacy['provider_id'] ?? null) === null, 'Beta apropriou indevidamente o template legado.');
    $assert(($updatedLegacy['body'] ?? '') === 'Corpo legado atualizado pela Beta', 'Beta não atualizou o template legado.');

    $templates->saveManaged($legacyId, [
        'subject' => 'Assunto gerenciado',
        'body' => 'Corpo gerenciado',
        'enabled_channels_json' => ['email'],
        'active' => true,
    ], ['id' => null, 'login' => 'coexistence.smoke']);
    $history = $templates->history($legacyId);
    $assert(count($history) === 1, 'Versionamento do template legado não foi gravado.');

    $newId = (int) $templates->create([
        'name' => $token . '_beta',
        'channel' => 'email',
        'purpose' => $token . '_beta_purpose',
        'body' => 'Template novo da Beta',
        'active' => true,
    ]);
    $newTemplate = $database->fetchOne('SELECT provider_id FROM message_templates WHERE id = :id', ['id' => $newId]);
    $assert((int) ($newTemplate['provider_id'] ?? 0) > 0, 'INSERT novo da Beta não informou provider_id.');

    $activeIds = array_map('intval', array_column($templates->listActive('email'), 'id'));
    $assert(in_array($legacyId, $activeIds, true) && in_array($newId, $activeIds, true), 'Listagem Beta não combinou templates legado e novo.');

    $channels = $database->fetchOne('SELECT COUNT(*) AS total FROM notification_channel_registry');
    $assert((int) ($channels['total'] ?? 0) >= 4, 'Registro de canais da 017 está incompleto.');

    $pdo->rollBack();
    echo 'MessageTemplateCoexistenceSmoke OK - ' . $checks . " verificações; transação revertida e nenhuma escrita externa.\n";
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'FALHOU - ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
