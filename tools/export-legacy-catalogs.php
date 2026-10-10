<?php
declare(strict_types=1);
// Только игровые определения; ни пользователей, ни персонажей, ни секретов конфигурации в экспорте нет.
$site = require __DIR__.'/../../../site.php';
$container = $site->bootApp()->getContainer();
$db = $container->make(\Illuminate\Database\ConnectionInterface::class);
$tables = ['attributes', 'races', 'race_attribute_bonuses', 'rules', 'skills', 'weapon_proficiencies',
    'magic_paths', 'spells', 'item_types', 'perk_sources', 'perks', 'items', 'effects', 'effect_modifiers',
    'perk_effects', 'item_effects', 'item_properties', 'stats', 'starter_loadout_groups',
    'starter_loadout_options', 'starter_loadout_option_items'];
$result = [];
foreach ($tables as $name) {
    if ($db->getSchemaBuilder()->hasTable('roleplay_'.$name)) {
        $result[$name] = $db->table('roleplay_'.$name)->get()->map(fn ($row) => (array) $row)->all();
    }
}
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";