<?php

declare(strict_types=1);

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Maxcuso\Roleplay\Catalog\Items\ItemCatalog;
use Maxcuso\Roleplay\Catalog\Shared\{CatalogRevision, DefinitionStore};
use Maxcuso\Roleplay\Api\ApiProblem;

$site = require __DIR__.'/../../../site.php';
$container = $site->bootApp()->getContainer();
set_exception_handler(function (Throwable $error): void {fwrite(STDERR, (string) $error."\n"); exit(1);});
$original = $container->make(ConnectionInterface::class);
// Изолированная таблица с отдельным префиксом: существующие цены не меняем.
$prefix = 'rpn_price_'.bin2hex(random_bytes(3)).'_';
$db = new ($original::class)($original->getPdo(), $original->getDatabaseName(), $prefix, $original->getConfig());
$schema = $db->getSchemaBuilder();
try {
    $schema->create('roleplay_items', function (Blueprint $table): void {
        $table->increments('id');
        $table->unsignedBigInteger('base_price')->default(0);
    });
    $db->table('roleplay_items')->insert(['base_price' => 5]);
    (require __DIR__.'/../migrations/2026_10_10_000001_fractional_item_prices.php')['up']($schema);
    if ((float) $db->table('roleplay_items')->value('base_price') !== 5.0) {
        throw new RuntimeException('Migration changed the existing whole price');
    }
    $id = $db->table('roleplay_items')->insertGetId(['base_price' => 0.2]);
    if ((float) $db->table('roleplay_items')->where('id', $id)->value('base_price') !== 0.2) {
        throw new RuntimeException('Database truncated fractional price');
    }
    // Проверяем тот же валидатор и представление, которые обслуживают редактор и JSON.
    $catalog = new ItemCatalog(new DefinitionStore($original, new CatalogRevision($original)));
    $input = ['name' => 'Проверка цены', 'item_type_id' => (int) $original->table('roleplay_item_types')->value('id'),
        'base_price' => 0.2];
    $fields = $catalog->validateWithEffects($input);
    $row = $catalog->present($fields + ['id' => 1]);
    $wire = json_decode(json_encode($row, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    if ($fields['base_price'] !== 0.2 || $wire['base_price'] !== 0.2) {
        throw new RuntimeException('Catalog or JSON truncated fractional price');
    }
    foreach ([-0.1, '0.2'] as $bad) {
        try { $catalog->validateWithEffects(array_replace($input, ['base_price' => $bad])); }
        catch (ApiProblem $error) {continue;}
        throw new RuntimeException('Invalid price accepted');
    }
    echo "Item prices: migration preserves whole prices, decimals survive SQL/catalog/JSON; invalid values rejected.\n";
} finally {
    $schema->dropIfExists('roleplay_items');
}
