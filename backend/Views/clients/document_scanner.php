<?php

declare(strict_types=1);

use App\Core\Url;

$contracts = is_array($contracts ?? null) ? $contracts : [];
$documents = is_array($documents ?? null) ? $documents : [];
$login = (string) ($login ?? '');
ob_start();
?>
<section class="page-header"><div><p class="section-heading__eyebrow">Documentos e contratos</p><h1>Digitalizar contrato</h1><p class="page-description">Fotografe ou selecione páginas, confira a ordem e gere um PDF protegido.</p></div><a class="button button--ghost" href="<?= htmlspecialchars(Url::to('/clientes/detalhe?login=' . rawurlencode($login)), ENT_QUOTES, 'UTF-8'); ?>">Voltar ao cliente</a></section>
<?php if (!empty($flash)): ?><section class="alert alert--<?= htmlspecialchars((string) ($flash['type'] ?? 'success'), ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars((string) ($flash['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></section><?php endif; ?>
<form class="card" method="post" enctype="multipart/form-data" action="<?= htmlspecialchars(Url::to('/clientes/documentos/digitalizar?login=' . rawurlencode($login)), ENT_QUOTES, 'UTF-8'); ?>" data-contract-scanner data-prevent-double-submit>
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) ($csrfToken ?? ''), ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="login" value="<?= htmlspecialchars($login, ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="page_order" value="[]" data-scanner-order><input type="hidden" name="page_rotations" value="{}" data-scanner-rotations>
    <div class="form-grid">
        <label class="field field--span-2"><span>Contrato vinculado</span><select name="contract_id"><option value="0">Sem contrato local específico</option><?php foreach ($contracts as $contract): ?><option value="<?= (int) ($contract['id'] ?? 0); ?>">#<?= (int) ($contract['id'] ?? 0); ?> · <?= htmlspecialchars((string) ($contract['tipo_aceite'] ?? 'contrato'), ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></label>
        <label class="field field--span-2"><span>Páginas ou PDF</span><input type="file" name="pages[]" accept="image/jpeg,image/png,application/pdf" capture="environment" multiple required data-scanner-input><small class="field-help">Até 20 páginas; 12 MB por arquivo; 40 MB no total. PDF deve ser enviado sozinho.</small></label>
    </div>
    <div class="scanner-preview" data-scanner-preview aria-live="polite"></div>
    <label class="checkbox-field"><input type="checkbox" required><span><strong>Confirmei a legibilidade e a ordem das páginas</strong><small>O original só é processado depois desta confirmação.</small></span></label>
    <div class="form-actions"><button class="button" type="submit" data-submit-label="Gerando PDF...">Confirmar e gerar PDF</button></div>
</form>
<section class="card"><div class="section-heading"><p class="section-heading__eyebrow">Histórico</p><h2>Contratos digitalizados</h2></div><div class="document-list"><?php foreach ($documents as $document): ?><a href="<?= htmlspecialchars(Url::to('/clientes/documentos/arquivo?id=' . (int) $document['id']), ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener"><span>PDF</span><strong><?= (int) ($document['page_count'] ?? 1); ?> página(s)<small><?= htmlspecialchars((string) ($document['created_by_login'] ?? ''), ENT_QUOTES, 'UTF-8'); ?> · <?= htmlspecialchars((string) ($document['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></small></strong><span aria-hidden="true">↗</span></a><?php endforeach; ?><?php if ($documents === []): ?><p class="page-description">Nenhum contrato impresso digitalizado.</p><?php endif; ?></div></section>
<?php $content = (string) ob_get_clean(); require __DIR__ . '/../layouts/app.php';
