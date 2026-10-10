<?php
declare(strict_types=1);
// Этот инструмент только преобразует файл. Подключение к базе не требуется.
spl_autoload_register(function (string $class): void {
    $namespace = 'Maxcuso\\Roleplay\\';
    if (!str_starts_with($class, $namespace)) return;
    $path = __DIR__.'/../src/'.str_replace('\\', '/', substr($class, strlen($namespace))).'.php';
    if (is_file($path)) require_once $path;
});
try {
    $file = $argv[1] ?? throw new RuntimeException('Укажи исходный JSON-экспорт.');
    $plan = (new \Maxcuso\Roleplay\Transfer\LegacyCatalogPlan())->build(
        json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR));
    echo json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    exit(1);
}