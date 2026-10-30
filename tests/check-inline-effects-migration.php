<?php
declare(strict_types=1);

// Переход проверяется только в случайной временной схеме, не на персонажах форума.
$site = require __DIR__.'/../../../site.php';
$container = $site->bootApp()->getContainer();
set_exception_handler(function (Throwable $error): void { fwrite(STDERR, (string) $error."\n"); exit(1); });
$original = $container->make(\Illuminate\Database\ConnectionInterface::class);
$prefix = 'rpn_'.bin2hex(random_bytes(3)).'_';
if (!preg_match('/^rpn_[0-9a-f]{6}_$/D', $prefix) || $prefix === $original->getTablePrefix()) throw new RuntimeException('Unsafe prefix');
$db = new ($original::class)($original->getPdo(), $original->getDatabaseName(), $prefix, $original->getConfig());
$schema = $db->getSchemaBuilder();
$base = require __DIR__.'/fixtures/migrations-2026-10-30/2026_10_02_000001_create_base_catalogs.php';
$transition = require __DIR__.'/fixtures/migrations-2026-10-30/2026_10_09_000002_inline_effects.php';
$assert = function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
try {
    $base['up']($schema);
    $common = ['enabled' => true, 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')];
    $db->table('roleplay_perks')->insert(['id' => 1, 'name' => 'Перк', 'slug' => 'perk'] + $common);
    $db->table('roleplay_item_types')->insert(['id' => 1, 'name' => 'Одежда', 'slug' => 'clothes'] + $common);
    $db->table('roleplay_items')->insert(['id' => 1, 'name' => 'Плащ', 'slug' => 'cloak', 'item_type_id' => 1,
        'is_magical' => true, 'properties' => '[]'] + $common);
    $db->table('roleplay_effects')->insert(['id' => 1, 'name' => 'Мана', 'description' => 'Старое описание',
        'slug' => 'mana', 'icon' => 'fas fa-gem', 'mechanic' => 'stat', 'operation' => 'percent',
        'stat' => 'mana_max'] + array_replace($common, ['enabled' => false]));
    foreach (['perk', 'item'] as $kind) $db->table('roleplay_'.$kind.'_effects')->insert([
        $kind.'_id' => 1, 'effect_id' => 1, 'value' => $kind === 'perk' ? 2.5 : -3,
        'condition' => 'manual', 'condition_text' => 'На солнце', 'scope' => 'all',
    ]);
    $transition['up']($schema);
    $perk = json_decode($db->table('roleplay_perks')->find(1)->effects, true, flags: JSON_THROW_ON_ERROR)[0];
    $item = json_decode($db->table('roleplay_items')->find(1)->effects, true, flags: JSON_THROW_ON_ERROR)[0];
    $assert($perk['value'] === 2.5 && $item['value'] === -3 && $perk['operation'] === 'percent'
        && $perk['condition_text'] === 'На солнце' && $perk['icon'] === 'fas fa-gem', 'Transition lost data');
    $assert(!isset($perk['effect_id']) && !$schema->hasTable('roleplay_effects') && !$schema->hasTable('roleplay_item_effects'), 'Old catalogue remains');
    $assert((int) $db->table('roleplay_catalog_state')->value('revision') === 2, 'Clients must receive a new revision');
    $item['value'] = 10;
    $db->table('roleplay_items')->where('id', 1)->update(['effects' => json_encode([$item], JSON_THROW_ON_ERROR)]);
    $assert(json_decode($db->table('roleplay_perks')->find(1)->effects, true)[0]['value'] === 2.5, 'Owners remain coupled');
    echo "Inline migration preserves values, conditions, icons and disabled bindings; owners independent.\n";
} finally { $base['down']($schema); }
