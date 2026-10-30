<?php

declare(strict_types=1);

use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Maxcuso\Roleplay\Catalog\Shared\CatalogRevision;
use Maxcuso\Roleplay\Catalog\Shared\DefinitionStore;
use Maxcuso\Roleplay\Catalog\ItemTypes\ItemTypeCatalog;
use Maxcuso\Roleplay\Catalog\Items\ItemCatalog;

// Проверяем только новые признаки: запись, чтение, снятие и откат схемы.
$site = require __DIR__.'/../../../site.php';
$container = $site->bootApp()->getContainer();
set_exception_handler(function (Throwable $error): void {fwrite(STDERR, (string) $error."\n"); exit(1);});
$original = $container->make(ConnectionInterface::class);
$prefix = 'rpn_'.bin2hex(random_bytes(3)).'_';
if (!preg_match('/^rpn_[0-9a-f]{6}_$/D', $prefix) || $prefix === $original->getTablePrefix()) throw new RuntimeException('Unsafe prefix');
$db = new ($original::class)($original->getPdo(), $original->getDatabaseName(), $prefix, $original->getConfig());
$schema = $db->getSchemaBuilder();
$base = require __DIR__.'/fixtures/migrations-2026-10-30/2026_10_02_000001_create_base_catalogs.php';
$ranks = require __DIR__.'/fixtures/migrations-2026-10-30/2026_10_09_000001_add_ranks.php';
$purposes = require __DIR__.'/fixtures/migrations-2026-10-30/2026_10_08_000002_add_item_purposes.php';
$base['up']($schema);
    (require __DIR__.'/fixtures/migrations-2026-10-30/2026_10_09_000002_inline_effects.php')['up']($schema);
$ranks['up']($schema);
try {
    $purposes['up']($schema);
    (require __DIR__.'/fixtures/migrations-2026-10-30/2026_10_30_000003_literature_contents.php')['up']($schema);
    $admin = User::findOrFail(1);
    $store = new DefinitionStore($db, new CatalogRevision($db));
    $revision = fn () => (int) $db->table('roleplay_catalog_state')->value('revision');
    $type = (new ItemTypeCatalog($store))->save(null, $revision(), ['name' => 'Материалы'], $admin)['id'];
    $catalog = new ItemCatalog($store);
    $fields = ['name' => 'Лист с рунами', 'item_type_id' => $type, 'is_ingredient' => true, 'is_literature' => true];
    $id = $catalog->save(null, $revision(), $fields, $admin)['id'];
    $row = $catalog->list($admin)['rows'][0];
    if ($row['is_ingredient'] !== true || $row['is_literature'] !== true || $row['is_melee_weapon'] !== false) {
        throw new RuntimeException('Purpose flags did not round-trip independently');
    }
    $catalog->save($id, $revision(), [...$fields, 'is_ingredient' => false], $admin);
    $row = $catalog->list($admin)['rows'][0];
    if ($row['is_ingredient'] !== false || $row['is_literature'] !== true) throw new RuntimeException('Purpose flags cannot be cleared independently');
    $purposes['down']($schema);
    if ($schema->hasColumn('roleplay_items', 'is_ingredient')) throw new RuntimeException('Purpose rollback failed');
    echo "Item purposes: save/read/clear and migration rollback passed.\n";
} finally {
    // Только таблицы уникального тестового подключения. Рабочие определения не изменяются.
    $ranks['down']($schema); $base['down']($schema);
}
