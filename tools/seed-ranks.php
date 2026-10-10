<?php

declare(strict_types=1);

// После установки/обновления: php packages/roleplay-new/tools/seed-ranks.php <ID администратора>.
// Явный ID исключает создание учётных записей и выбор случайного пользователя.
try {
    $id = filter_var($argv[1] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$id) throw new RuntimeException('Укажи ID существующего администратора.');
    $site = require __DIR__.'/../../../site.php';
    $container = $site->bootApp()->getContainer();
    $actor = \Flarum\User\User::findOrFail($id);
    $db = $container->make(\Illuminate\Database\ConnectionInterface::class);
    $count = (new \Maxcuso\Roleplay\Catalog\SeedRanks($db))->run($actor);
    echo "Added ranks: $count. Existing ranks were preserved.\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    exit(1);
}
