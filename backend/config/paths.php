<?php

declare(strict_types=1);

$rootPath = dirname(__DIR__, 2);

return [
    'root' => $rootPath,
    'views' => $rootPath . '/backend/Views',
    'storage' => $rootPath . '/storage',
    'logs' => $rootPath . '/logs',
    'tmp' => $rootPath . '/tmp',
    'public' => $rootPath . '/public',
];
