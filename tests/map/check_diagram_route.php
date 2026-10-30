<?php
// Verificação somente de leitura da nova rota; não cria usuário nem fixtures.
require dirname(__DIR__, 2) . '/backend/bootstrap/app.php';
$app = bootstrapApplication();
$_SESSION = [];
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_METHOD'] = 'GET';
$ok = true;
function routeCheck(string $label, bool $condition): void {
    global $ok;
    $ok = $ok && $condition;
    echo ($condition ? '[OK] ' : '[FALHA] ') . $label . "\n";
}
function readRoute($app, string $path, ?string $role, string $base = ''): array {
    $_SESSION['user'] = $role === null ? null : ['id' => 0, 'login' => 'zz_teste_' . $role, 'role' => $role];
    $_SERVER['SCRIPT_NAME'] = $base . '/index.php';
    $_SERVER['REQUEST_URI'] = $base . $path;
    $request = App\Core\Request::fromGlobals();
    App\Core\Url::setBasePath($request->basePath());
    $response = $app->handle($request);
    $read = static fn (string $property) => (new ReflectionProperty($response, $property))->getValue($response);
    return [$read('status'), $read('headers'), $read('body')];
}
$asset = file_get_contents(dirname(__DIR__, 2) . '/modules/isp_map/diagram.js');
foreach (['', '/subdiretorio'] as $base) {
    [$status, $headers] = readRoute($app, '/mapa/diagram.js', null, $base);
    routeCheck("$base asset sem sessão exige login", $status === 302 && ($headers['Location'] ?? '') === $base . '/login');
    foreach (['technician', 'manager', 'admin'] as $role) {
        [$pageStatus, $pageHeaders, $html] = readRoute($app, '/mapa', $role, $base);
        [$status, $headers, $body] = readRoute($app, '/mapa/diagram.js', $role, $base);
        if ($role === 'technician') {
            routeCheck("$base técnico recebe a mesma restrição de /mapa", $status === $pageStatus && $status === 302 && $headers['Location'] === $pageHeaders['Location'] && $body === '');
            continue;
        }
        routeCheck("$base $role recebe JS completo e sem cache", $status === 200 && $body === $asset && $headers['Content-Type'] === 'application/javascript; charset=UTF-8' && $headers['Cache-Control'] === 'private, no-store');
        $src = '<script src="' . $base . '/mapa/diagram.js"></script>';
        routeCheck("$base $role HTML carrega motor antes da inicialização", $pageStatus === 200 && str_contains($html, $src) && strpos($html, $src) < strpos($html, 'const diagram = window.ISPMapDiagram('));
    }
}
exit($ok ? 0 : 1);
