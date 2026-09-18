<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }

    $checks++;
};

$renderHeader = static function (array $releaseInfo) use ($root): string {
    $program = <<<'PHP'
$releaseInfo = json_decode(base64_decode($argv[1]), true, 512, JSON_THROW_ON_ERROR);
require $argv[2] . '/backend/bootstrap/autoload.php';

use App\Core\Url;
use App\Core\View;

Url::setBasePath('/isp_auxiliar_stable/public');
define('APP_RELEASE_INFO', $releaseInfo);
session_start();
$view = new View($argv[2] . '/backend/Views');
echo $view->render('layouts/header', [
    'layoutMode' => 'app',
    'user' => ['name' => 'Gestor', 'role' => 'manager', 'access' => ['can_use_beta' => true]],
    'appName' => 'ISP Auxiliar',
]);
PHP;
    $payload = base64_encode(json_encode($releaseInfo, JSON_THROW_ON_ERROR));
    $command = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($program)
        . ' ' . escapeshellarg($payload) . ' ' . escapeshellarg($root);
    $output = shell_exec($command);

    if (!is_string($output)) {
        throw new RuntimeException('Não foi possível renderizar o cabeçalho isolado.');
    }

    return $output;
};

$singleProduction = $renderHeader([
    'channel' => 'stable',
    'id' => 'official',
    'commit' => str_repeat('a', 40),
    'stable_base_url' => '',
    'beta_base_url' => '',
]);
$assert(!str_contains($singleProduction, 'release-channel-control'), 'Produção única exibiu seletor sem destinos.');
$assert(!str_contains($singleProduction, 'Abrir Beta') && !str_contains($singleProduction, 'Abrir Stable'), 'Produção única exibiu link de versão vazio.');

$dualDestination = $renderHeader([
    'channel' => 'beta',
    'id' => 'beta-test',
    'commit' => str_repeat('b', 40),
    'stable_base_url' => 'https://stable.example.test/isp_auxiliar/public',
    'beta_base_url' => 'https://beta.example.test/isp_auxiliar/public',
]);
$assert(str_contains($dualDestination, 'Canal: <strong>Beta</strong>'), 'Ambiente com dois destinos não exibe o canal Beta.');
$assert(str_contains($dualDestination, 'Abrir Stable'), 'Ambiente com dois destinos não mantém a troca retrocompatível.');

echo "ReleaseChannelHeaderSmoke: {$checks} checks passed; configuração hermética.\n";
