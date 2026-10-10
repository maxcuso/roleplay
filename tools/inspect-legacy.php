<?php
declare(strict_types=1);
// Запускается из корня установленного форума: php packages/roleplay-new/tools/inspect-legacy.php.
// Печатает только отчёт; данные форума никогда не изменяет.
$site = require __DIR__.'/../../../site.php';
$container = $site->bootApp()->getContainer();
spl_autoload_register(function (string $class): void {
    $prefix = 'Maxcuso\\Roleplay\\';
    if (!str_starts_with($class, $prefix)) return;
    $path = __DIR__.'/../src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
    if (is_file($path)) require_once $path;
}, true, true);

$db = $container->make(\Illuminate\Database\ConnectionInterface::class);
echo json_encode((new \Maxcuso\Roleplay\Transfer\LegacyInspector($db))->inspect(),
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";