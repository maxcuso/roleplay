<?php
declare(strict_types=1);

use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Maxcuso\Roleplay\Catalog\Attributes\AttributeIcons;
use Maxcuso\Roleplay\Catalog\Shared\CatalogRegistry;
use Maxcuso\Roleplay\Api\ApiProblem;

// Критический сценарий новой формы: пакет сохраняется целиком, меняются только иконки.
$site = require __DIR__.'/../../../site.php';
$container = $site->bootApp()->getContainer();
set_exception_handler(function (Throwable $error): void {fwrite(STDERR, (string) $error."\n"); exit(1);});
$original = $container->make(ConnectionInterface::class);
$prefix = 'rpn_'.bin2hex(random_bytes(3)).'_';
if (!preg_match('/^rpn_[0-9a-f]{6}_$/D', $prefix) || $prefix === $original->getTablePrefix()) throw new RuntimeException('Unsafe prefix');
$db = new ($original::class)($original->getPdo(), $original->getDatabaseName(), $prefix, $original->getConfig());
$base = require __DIR__.'/../migrations/2026_10_02_000001_create_base_catalogs.php';
$ranks = require __DIR__.'/../migrations/2026_10_09_000001_add_ranks.php';
$base['up']($db->getSchemaBuilder());
$ranks['up']($db->getSchemaBuilder());
$assert = function (bool $condition, string $message): void {if (!$condition) throw new RuntimeException($message);};
try {
    $actor = User::findOrFail(1);
    $catalog = (new CatalogRegistry($db))->get('attributes');
    $catalog->save(null, 1, ['name' => 'Магия', 'description' => 'Исходное описание', 'enabled' => true, 'sort_order' => 2], $actor);
    $catalog->save(null, 2, ['name' => 'Ловкость'], $actor);
    $icons = new AttributeIcons($db);
    $before = $icons->read($actor);
    $rows = array_map(fn ($row) => ['id' => $row['id'], 'icon' => 'fas fa-star'], $before['rows']);
    $icons->save($actor, 3, $rows);
    $after = $icons->read($actor);
    $assert($after['revision'] === 4 && count(array_filter($after['rows'], fn ($row) => $row['icon'] === 'fas fa-star')) === 2, 'All icons saved with one revision');
    $magic = $db->table('roleplay_attributes')->where('name', 'Магия')->first();
    $assert($magic->description === 'Исходное описание' && (bool) $magic->enabled && (int) $magic->sort_order === 2, 'Unrelated fields preserved');
    $bad = $rows; $bad[0]['icon'] = 'fas fa-bolt'; $bad[1]['icon'] = '<script>';
    try {$icons->save($actor, 4, $bad); throw new RuntimeException('Invalid batch accepted');}
    catch (ApiProblem $expected) {}
    $assert($icons->read($actor) === $after, 'Invalid icon rolls back the whole form');
    try {$icons->save($actor, 3, $rows); throw new RuntimeException('Stale version accepted');}
    catch (ApiProblem $expected) {$assert($expected->status === 409, 'Version conflict');}
    echo "Attribute icons: atomic save, preserved fields, validation rollback and version conflict passed.\n";
} finally {$ranks['down']($db->getSchemaBuilder()); $base['down']($db->getSchemaBuilder());}
