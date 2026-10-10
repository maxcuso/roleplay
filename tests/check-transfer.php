<?php
declare(strict_types=1);

// Реальная схема в отдельном случайном префиксе. Ни один рабочий справочник не перезаписывается.
$site = require __DIR__.'/../../../site.php';
$container = $site->bootApp()->getContainer();
set_exception_handler(function (Throwable $error): void { fwrite(STDERR, (string) $error."\n"); exit(1); });
spl_autoload_register(function (string $class): void {
    $namespace = 'Maxcuso\\Roleplay\\';
    if (!str_starts_with($class, $namespace)) return;
    $path = __DIR__.'/../src/'.str_replace('\\', '/', substr($class, strlen($namespace))).'.php';
    if (is_file($path)) require_once $path;
}, true, true);
$original = $container->make(\Illuminate\Database\ConnectionInterface::class);
$prefix = 'rpn_'.bin2hex(random_bytes(3)).'_';
$db = new ($original::class)($original->getPdo(), $original->getDatabaseName(), $prefix, $original->getConfig());
$migration = require __DIR__.'/../migrations/2026_10_02_000001_create_base_catalogs.php';
$ranks = require __DIR__.'/../migrations/2026_10_09_000001_add_ranks.php';
function expectTransfer(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "OK: $message\n";
}
$legacy = json_decode(file_get_contents(__DIR__.'/.runtime/legacy-catalogs.json'), true, flags: JSON_THROW_ON_ERROR);
$plan = (new \Maxcuso\Roleplay\Transfer\LegacyCatalogPlan())->build($legacy);
try {
    $migration['up']($db->getSchemaBuilder());
    $ranks['up']($db->getSchemaBuilder());
    $grantMigration = require __DIR__.'/../migrations/2026_10_08_000001_track_creation_grants.php';
    $grantMigration['up']($db->getSchemaBuilder());
    (require __DIR__.'/../migrations/2026_10_08_000002_add_item_purposes.php')['up']($db->getSchemaBuilder());
    (require __DIR__.'/../migrations/2026_10_09_000002_inline_effects.php')['up']($db->getSchemaBuilder());
    (require __DIR__.'/../migrations/2026_10_10_000001_fractional_item_prices.php')['up']($db->getSchemaBuilder());
    (require __DIR__.'/../migrations/2026_10_10_000003_fractional_spell_distances.php')['up']($db->getSchemaBuilder());
    // Ошибка в конце импорта должна отменить в том числе ранее вставленные характеристики/перки.
    $bad = $plan;
    $bad['catalogs']['races'][0]['input']['rules']['fixed_perk_ids'] = [999999];
    try {
        (new \Maxcuso\Roleplay\Transfer\CatalogTransfer($db))->run($bad);
        throw new RuntimeException('Broken reference accepted');
    } catch (\Maxcuso\Roleplay\Api\ApiProblem $error) {
        expectTransfer($db->table('roleplay_attributes')->count() === 0, 'Broken late reference rolls back entire import');
    }
    $result = (new \Maxcuso\Roleplay\Transfer\CatalogTransfer($db))->run($plan);
    foreach (['attributes', 'magic_paths', 'perks', 'spells', 'items', 'races', 'skills', 'weapon_proficiencies'] as $table) {
        expectTransfer($db->table('roleplay_'.$table)->count() === count($legacy[$table]), 'Counts preserved: '.$table);
    }
    foreach (['attributes', 'magic_paths', 'item_types'] as $table) foreach ($legacy[$table] as $old) {
        $new = $db->table('roleplay_'.$table)->find($old['id']);
        expectTransfer($new->icon === $old['icon'] && $new->slug === $old['slug'], 'Identity and icon preserved: '.$table.'/'.$old['id']);
        if ($table === 'magic_paths') expectTransfer(strtolower($new->color) === strtolower($old['color']), 'Path color preserved');
    }
    expectTransfer(!$db->table('roleplay_perk_sources')->where('slug', 'starting')->exists(), 'Starting is excluded as a perk origin');
    expectTransfer($db->table('roleplay_characters')->count() === 0 && $db->table('roleplay_operations')->count() === 0, 'Characters and history are not transferred');
    expectTransfer($db->table('roleplay_skills')->where('enabled', 1)->count() === 0, 'Incomplete skills preserved disabled');
    expectTransfer((int)$db->table('roleplay_skill_levels')->where('skill_id', 9)->where('level', 2)->value('perk_id') === 9, 'Known second-level binding preserved');
    $effect = json_decode($db->table('roleplay_perks')->find(4)->effects, true, flags: JSON_THROW_ON_ERROR)[0];
    expectTransfer($effect['value'] === 3 && $effect['condition'] === 'unarmed', 'Effect value and condition preserved at owner');
    $config = (new \Maxcuso\Roleplay\Creation\CreationConfigurationStore($db))->read();
    expectTransfer(!$config['enabled'] && count($config['loadout']) === 3 && $config['loadout'][2]['automatic'], 'Three loadout groups and automatic clothing transferred');
    $human = json_decode($db->table('roleplay_races')->find(1)->rules, true, flags: JSON_THROW_ON_ERROR);
    $elf = json_decode($db->table('roleplay_races')->find(2)->rules, true, flags: JSON_THROW_ON_ERROR);
    expectTransfer($human['attribute_count'] === 3 && $human['fixed_perk_ids'] === [1], 'Human bonus selection and fixed perk transferred');
    expectTransfer($elf['fixed_bonuses']['magic'] === 1 && $elf['spell_choice_count'] === 1 && count($elf['spell_choice_ids']) === 28, 'Elf bonuses and separate spell choice transferred');
    try {
        (new \Maxcuso\Roleplay\Transfer\CatalogTransfer($db))->run($plan);
        throw new RuntimeException('Repeat import accepted');
    } catch (DomainException $error) {
        expectTransfer($db->table('roleplay_perks')->count() === 13, 'Repeat import refuses nonempty destination without changes');
    }
    file_put_contents(__DIR__.'/.runtime/transfer-report.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    echo "Catalogue transfer checks passed\n";
} finally {
    $ranks['down']($db->getSchemaBuilder());
    $migration['down']($db->getSchemaBuilder());
}