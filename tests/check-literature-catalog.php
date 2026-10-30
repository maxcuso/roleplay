<?php

declare(strict_types=1);

use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Maxcuso\Roleplay\Api\ApiProblem;
use Maxcuso\Roleplay\Catalog\Shared\CatalogRegistry;

// Только отдельные временные таблицы. Книги и персонажи рабочего форума не меняются.
$site = require __DIR__.'/../../../site.php';
$container = $site->bootApp()->getContainer();
set_exception_handler(function (Throwable $error): void {fwrite(STDERR, (string) $error."\n"); exit(1);});
$original = $container->make(ConnectionInterface::class);
$prefix = 'rpn_'.bin2hex(random_bytes(3)).'_';
if (!preg_match('/^rpn_[0-9a-f]{6}_$/D', $prefix) || $prefix === $original->getTablePrefix()) throw new RuntimeException('Unsafe prefix');
$db = new ($original::class)($original->getPdo(), $original->getDatabaseName(), $prefix, $original->getConfig());
$schema = $db->getSchemaBuilder();
$base = require __DIR__.'/fixtures/migrations-2026-10-30/2026_10_02_000001_create_base_catalogs.php';
$migration = require __DIR__.'/fixtures/migrations-2026-10-30/2026_10_30_000003_literature_contents.php';
$assert = function (bool $ok, string $message): void {if (!$ok) throw new RuntimeException($message);};
$reject = function (callable $action, string $code) use ($assert): void {
    try {$action();} catch (ApiProblem $error) {$assert($error->problemCode === $code, 'Wrong rejection: '.$error->problemCode); return;}
    throw new RuntimeException('Missing rejection: '.$code);
};
try {
    $base['up']($schema);
    foreach (['2026_10_08_000002_add_item_purposes', '2026_10_09_000002_inline_effects',
        '2026_10_30_000002_starting_spells'] as $name) (require __DIR__.'/fixtures/migrations-2026-10-30/'.$name.'.php')['up']($schema);
    $actor = User::findOrFail(1);
    $registry = new CatalogRegistry($db);
    $revision = fn () => (int) $db->table('roleplay_catalog_state')->value('revision');
    $create = fn (string $type, array $fields) => $registry->get($type)->save(null, $revision(), $fields, $actor)['id'];
    $type = $create('item-types', ['name' => 'Литература']);
    $now = gmdate('Y-m-d H:i:s');
    $old = $db->table('roleplay_items')->insertGetId(['name' => 'Старая книга', 'slug' => 'old-book', 'item_type_id' => $type,
        'is_literature' => true, 'properties' => '[]', 'effects' => '[]', 'created_at' => $now, 'updated_at' => $now]);
    $migration['up']($schema);
    $oldRow = $registry->definitionsFor('items', [$old])[0];
    $assert($oldRow['literature_contents'] === [] && $oldRow['consumed_on_learning'] === false,
        'Existing books keep empty contents and remain reusable');
    $path = $create('paths', ['name' => 'Пламя', 'enabled' => true]);
    $one = $create('spells', ['name' => 'Искра', 'magic_path_id' => $path, 'enabled' => true]);
    $two = $create('spells', ['name' => 'Пламя', 'magic_path_id' => $path, 'enabled' => false]);
    $contents = [['type' => 'spell', 'knowledge_id' => $one], ['type' => 'spell', 'knowledge_id' => $two]];
    $fields = ['name' => 'Книга пламени', 'item_type_id' => $type, 'enabled' => true, 'is_literature' => true,
        'literature_contents' => $contents];
    $book = $create('items', $fields);
    $row = $registry->definitionsFor('items', [$book])[0];
    $assert($row['literature_contents'] === $contents && !$row['consumed_on_learning'], 'Reusable book contents did not round-trip');
    // Иное название заклинания читается из справочника, а не из сохранённого текста книги.
    $registry->get('spells')->save($one, $revision(), ['name' => 'Новая искра', 'magic_path_id' => $path, 'enabled' => true], $actor);
    $assert($registry->definitionsFor('items', [$book])[0]['literature_contents'] === $contents, 'Spell rename changed stable content links');
    $registry->get('items')->save($book, $revision(), $fields + ['consumed_on_learning' => false], $actor);
    $before = $revision();
    foreach ([
        ['consumed_on_learning' => true],
        ['consumed_on_learning' => true, 'literature_contents' => []],
        ['literature_contents' => [$contents[0], $contents[0]]],
        ['literature_contents' => [['type' => 'recipe', 'knowledge_id' => $one]]],
        ['literature_contents' => [['type' => 'spell', 'knowledge_id' => PHP_INT_MAX]]],
        ['literature_contents' => [['type' => 'spell', 'knowledge_id' => (string) $one]]],
        ['literature_contents' => [['type' => 'spell', 'knowledge_id' => $one, 'price' => 0]]],
    ] as $bad) {
        $reject(fn () => $registry->get('items')->save($book, $revision(), array_replace($fields, $bad), $actor), 'invalid_catalog_data');
        $assert($revision() === $before && $registry->definitionsFor('items', [$book])[0]['literature_contents'] === $contents,
            'Invalid contents changed the item or revision');
    }
    $scroll = $create('items', array_replace($fields, ['name' => 'Свиток искры', 'consumed_on_learning' => true, 'literature_contents' => [$contents[0]]]));
    $assert($registry->definitionsFor('items', [$scroll])[0]['consumed_on_learning'] === true, 'Consumable single-knowledge source failed');
    $workspace = $registry->listForWorkspace('items', $actor, '', null, 1, []);
    $assert(count($workspace['lookups']['spells']) === 2 && count($workspace['lookups']['paths']) === 1, 'Workspace item editor is missing knowledge choices');
    $form = $registry->creationForm('items', $actor);
    $assert(count($form['lookups']['spells']) === 2 && count($form['lookups']['paths']) === 1, 'Moderator creation form is missing knowledge choices');
    $registry->get('items')->save($book, $revision(), array_replace($fields, ['is_literature' => false, 'enabled' => false]), $actor);
    $assert($registry->definitionsFor('items', [$book])[0]['literature_contents'] === $contents, 'Temporarily disabling literature lost its contents');
    $reject(fn () => $registry->get('spells')->remove($one, $revision(), $actor), 'catalog_entry_in_use');
    $reject(fn () => $registry->get('spells')->remove($two, $revision(), $actor), 'catalog_entry_in_use');
    $registry->get('items')->remove($scroll, $revision(), $actor);
    $registry->get('items')->save($book, $revision(), array_replace($fields, ['literature_contents' => []]), $actor);
    $registry->get('spells')->remove($one, $revision(), $actor);
    $registry->get('spells')->remove($two, $revision(), $actor);
    $migration['down']($schema);
    $assert(!$schema->hasColumn('roleplay_items', 'literature_contents') && $db->table('roleplay_items')->where('id', $old)->exists(),
        'Rollback removed existing items');
    echo "Literature catalogue: old items, typed links, multiple sources, reusable/consumable contents, validation rollback, editor lookups and deletion guards passed.\n";
} finally {$base['down']($schema);}
