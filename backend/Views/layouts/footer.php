<?php

declare(strict_types=1);

$versionInfo = defined('APP_VERSION_INFO') && is_array(APP_VERSION_INFO)
    ? APP_VERSION_INFO
    : [
        'app_name' => 'ISP Auxiliar',
        'app_version' => '0.1.0',
        'build_date' => date('Y-m-d'),
        'modules' => [
            'isp_auxiliar' => '0.1.0',
            'isp_map2' => '0.1.0',
        ],
    ];
?>
<footer class="footer">
    <p>ISP Auxiliar em PHP puro. Base inicial pensada para celular, manutencao simples e crescimento por camadas.</p>
    <p class="footer__version">
        <?= htmlspecialchars((string) ($versionInfo['app_name'] ?? 'ISP Auxiliar'), ENT_QUOTES, 'UTF-8'); ?>
        v<?= htmlspecialchars((string) ($versionInfo['app_version'] ?? '0.1.0'), ENT_QUOTES, 'UTF-8'); ?>
        · build <?= htmlspecialchars((string) ($versionInfo['build_date'] ?? date('Y-m-d')), ENT_QUOTES, 'UTF-8'); ?>
        · módulos:
        <?php
        $modules = [];
        foreach ((array) ($versionInfo['modules'] ?? []) as $module => $version) {
            $modules[] = htmlspecialchars((string) $module, ENT_QUOTES, 'UTF-8') . ' ' . htmlspecialchars((string) $version, ENT_QUOTES, 'UTF-8');
        }
        echo implode(' · ', $modules);
        ?>
    </p>
</footer>
