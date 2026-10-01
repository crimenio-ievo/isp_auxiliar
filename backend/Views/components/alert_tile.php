<?php

declare(strict_types=1);

/**
 * Bloco de alerta reaproveitável (métricas semânticas da home, ficha do
 * cliente numa próxima rodada, etc.).
 *
 * Espera $alertTile com: label, value, description e variant
 * ('urgente'|'attention'|'queue'|'cautela'|'ok'). 'attention' é exclusiva de
 * estado do cliente (ex.: fatura vencida); 'queue' é fila de tarefa neutra
 * (ex.: aceites aguardando assinatura) — não é um alerta de problema.
 * Com 'href' o bloco inteiro vira link para a lista filtrada.
 * Usa as variáveis CSS alert-* e queue-*.
 */
$alertTile = is_array($alertTile ?? null) ? $alertTile : [];
$variant = (string) ($alertTile['variant'] ?? 'ok');
$variant = in_array($variant, ['urgente', 'attention', 'queue', 'cautela', 'ok'], true) ? $variant : 'ok';
$label = (string) ($alertTile['label'] ?? '');
$value = (string) ($alertTile['value'] ?? '');
$description = (string) ($alertTile['description'] ?? '');
$href = trim((string) ($alertTile['href'] ?? ''));
$tag = $href !== '' ? 'a' : 'article';
?>
<<?= $tag; ?> class="alert-tile alert-tile--<?= htmlspecialchars($variant, ENT_QUOTES, 'UTF-8'); ?><?= $href !== '' ? ' alert-tile--link' : ''; ?>"<?= $href !== '' ? ' href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"' : ''; ?>>
    <p class="alert-tile__label"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></p>
    <strong class="alert-tile__value"><?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?></strong>
    <?php if ($description !== ''): ?>
        <span class="alert-tile__description"><?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8'); ?></span>
    <?php endif; ?>
</<?= $tag; ?>>
