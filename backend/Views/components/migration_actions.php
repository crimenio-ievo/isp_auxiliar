<?php

declare(strict_types=1);

/**
 * Rodapé compartilhado das quatro etapas da migração.
 *
 * Espera $migrationActionBar com back, primary e more. Cada ação aceita:
 * label, tag (a|button), href, type, name, value, class, disabled, hidden,
 * attrs e help.
 */
$migrationActionBar = is_array($migrationActionBar ?? null) ? $migrationActionBar : [];
$escapeAction = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$renderMigrationAction = static function (array $action, string $extraClass = '') use ($escapeAction): void {
    $label = trim((string) ($action['label'] ?? ''));
    if ($label === '') {
        return;
    }

    $tag = (string) ($action['tag'] ?? 'a') === 'button' ? 'button' : 'a';
    $classes = trim('button ' . (string) ($action['class'] ?? '') . ' ' . $extraClass);
    $attributes = is_array($action['attrs'] ?? null) ? $action['attrs'] : [];
    if ($tag === 'a') {
        $attributes['href'] = (string) ($action['href'] ?? '#');
    } else {
        $attributes['type'] = (string) ($action['type'] ?? 'button');
        foreach (['name', 'value'] as $attribute) {
            if (array_key_exists($attribute, $action)) {
                $attributes[$attribute] = (string) $action[$attribute];
            }
        }
        if (!empty($action['disabled'])) {
            $attributes['disabled'] = true;
            $attributes['aria-disabled'] = 'true';
        }
    }
    if (!empty($action['hidden'])) {
        $attributes['hidden'] = true;
    }
    ?>
    <<?= $tag; ?> class="<?= $escapeAction($classes); ?>"<?php foreach ($attributes as $name => $value): ?><?php if ($value === true): ?> <?= $escapeAction($name); ?><?php elseif ($value !== false && $value !== null): ?> <?= $escapeAction($name); ?>="<?= $escapeAction($value); ?>"<?php endif; ?><?php endforeach; ?>><?= $escapeAction($label); ?></<?= $tag; ?>>
    <?php if (trim((string) ($action['help'] ?? '')) !== ''): ?><small class="migration-actions__help"><?= $escapeAction($action['help']); ?></small><?php endif; ?>
<?php };

$primaryAction = is_array($migrationActionBar['primary'] ?? null) ? $migrationActionBar['primary'] : [];
$backAction = is_array($migrationActionBar['back'] ?? null) ? $migrationActionBar['back'] : [];
$moreActions = array_values(array_filter((array) ($migrationActionBar['more'] ?? []), 'is_array'));
?>
<section class="migration-actions" aria-label="Ações da etapa">
    <?php if ($backAction !== []): ?>
        <div class="migration-actions__back">
            <?php $renderMigrationAction($backAction, 'button--ghost'); ?>
        </div>
    <?php endif; ?>
    <div class="migration-actions__right">
        <?php if ($primaryAction !== []): ?>
            <div class="migration-actions__primary">
                <?php $renderMigrationAction($primaryAction, 'migration-actions__primary-button'); ?>
            </div>
        <?php endif; ?>
        <?php if ($moreActions !== []): ?>
            <details class="migration-actions__more" data-migration-actions-menu>
                <summary aria-haspopup="menu" aria-expanded="false">Mais ações</summary>
                <div role="menu"><?php foreach ($moreActions as $moreAction): ?><?php
                    $moreAction['attrs'] = array_merge((array) ($moreAction['attrs'] ?? []), ['role' => 'menuitem']);
                    $renderMigrationAction($moreAction, str_contains((string) ($moreAction['class'] ?? ''), 'button--danger') ? '' : 'button--ghost');
                ?><?php endforeach; ?></div>
            </details>
        <?php endif; ?>
    </div>
</section>
