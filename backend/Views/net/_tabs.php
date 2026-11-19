<?php

declare(strict_types=1);

use App\Core\Url;

/**
 * Abas da area "Rede" (mesmo padrao da tela /rede-fibra). Espera $netTab = chave da aba ativa.
 */
$netTab = $netTab ?? '';
$netTabs = [
    'fibra' => ['/rede-fibra', 'Rede de fibra'],
    'equipamentos' => ['/rede-equipamentos', 'Equipamentos'],
];
?>
<nav class="rc-tabs" aria-label="Rede">
    <?php foreach ($netTabs as $key => [$href, $label]): ?>
        <a class="rc-tab<?= $key === $netTab ? ' is-active' : ''; ?>" href="<?= htmlspecialchars(Url::to($href), ENT_QUOTES, 'UTF-8'); ?>"<?= $key === $netTab ? ' aria-current="page"' : ''; ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></a>
    <?php endforeach; ?>
</nav>
