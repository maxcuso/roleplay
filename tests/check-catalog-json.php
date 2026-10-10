<?php
declare(strict_types=1);

use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Maxcuso\Roleplay\Catalog\Shared\CatalogRegistry;
use Maxcuso\Roleplay\Transfer\CatalogJsonTransfer;

// Один сквозной сценарий: две независимые временные схемы, настоящий импорт/экспорт и ограничения.
$site = require __DIR__.'/../../../site.php';
$container = $site->bootApp()->getContainer();
set_exception_handler(function (Throwable $error): void {fwrite(STDERR, (string) $error."\n"); exit(1);});
$original = $container->make(ConnectionInterface::class);
$base = require __DIR__.'/../migrations/2026_10_02_000001_create_base_catalogs.php';
$ranks = require __DIR__.'/../migrations/2026_10_09_000001_add_ranks.php';
$purposes = require __DIR__.'/../migrations/2026_10_08_000002_add_item_purposes.php';
$connections = [];
$assert = function (bool $ok, string $message): void {if (!$ok) throw new RuntimeException($message);};
try {
    foreach ([0, 1] as $index) {
        $prefix = 'rpn_'.bin2hex(random_bytes(3)).'_';
        if (!preg_match('/^rpn_[0-9a-f]{6}_$/D', $prefix) || $prefix === $original->getTablePrefix()) throw new RuntimeException('Unsafe prefix');
        $db = new ($original::class)($original->getPdo(), $original->getDatabaseName(), $prefix, $original->getConfig());
        $base['up']($db->getSchemaBuilder());
        $ranks['up']($db->getSchemaBuilder());
        $connections[] = $db;
        $purposes['up']($db->getSchemaBuilder());
    (require __DIR__.'/../migrations/2026_10_09_000002_inline_effects.php')['up']($db->getSchemaBuilder());
    }
    [$source, $target] = $connections;
    $actor = User::findOrFail(1);
    $revision = fn ($db) => (int) $db->table('roleplay_catalog_state')->value('revision');
    $from = new CatalogJsonTransfer($source);
    $to = new CatalogJsonTransfer($target);
    $document = ['format' => 'roleplay-catalogs', 'version' => 1, 'catalogs' => [
        'attributes' => [['key' => 'magic', 'name' => 'Магия', 'enabled' => true]],
        'paths' => [['key' => 'frost', 'name' => 'Мороз', 'enabled' => true]],
        'item-types' => [['key' => 'clothes', 'name' => 'Одежда']],
        'sources' => [['key' => 'race', 'name' => 'Раса', 'name_source' => 'race']],
        'proficiencies' => [['key' => 'magic-weapon', 'name' => 'Магическое оружие', 'attribute_key' => 'magic']],
        'skills' => [['key' => 'alchemy', 'name' => 'Алхимия', 'attribute_key' => 'magic',
            'levels' => [['level' => 1, 'perk_key' => 'nature']]]],
        'effects' => [['key' => 'alchemy-bonus', 'name' => 'Бонус алхимии', 'mechanic' => 'skill_check',
            'operation' => 'add', 'skill_key' => 'alchemy', 'enabled' => false]],
        'perks' => [['key' => 'nature', 'name' => 'Родство с природой', 'image' => 'https://example.com/perk.png',
            'effects' => [['effect_key' => 'alchemy-bonus', 'value' => 2]]]],
        'spells' => [['key' => 'freeze', 'name' => 'Заморозки', 'magic_path_key' => 'frost', 'enabled' => true]],
        'items' => [['key' => 'cloak', 'name' => 'Плащ', 'item_type_key' => 'clothes', 'is_magical' => true,
            'is_ingredient' => true, 'is_literature' => true, 'is_magic_weapon' => true, 'magic_proficiency_key' => 'magic-weapon',
            'properties' => [['description' => 'Шаги тише', 'cooldown_value' => 2, 'cooldown_unit' => 'turn']],
            'effects' => [['effect_key' => 'alchemy-bonus', 'value' => 3]]]],
        'races' => [['key' => 'elf', 'name' => 'Эльф', 'rules' => ['fixed_bonuses' => ['magic' => 1],
            'fixed_perk_keys' => ['nature'], 'spell_choice_path_keys' => ['frost'], 'spell_choice_count' => 1,
            'proficiency_choice_keys' => ['magic-weapon'], 'proficiency_choice_count' => 1]]],
    ]];
    $report = $from->import($actor, $document, 'skip', 1, false);
    $assert(!$report['errors'] && $report['new'] === 10 && !$report['applied'], 'Preview must resolve circular references: '.implode('; ', $report['errors']));
    $assert($source->table('roleplay_items')->count() === 0 && $revision($source) === 1, 'Preview must not persist records or revision');
    $report = $from->import($actor, $document, 'skip', 1, true);
    $assert($report['applied'] && $revision($source) === 2, 'Bulk apply must publish one revision');
    $exported = json_decode(json_encode($from->export($actor, 'items', 'all'), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    $assert($exported['catalogs']['perks'][0]['image'] === 'https://example.com/perk.png', 'External image URL must round-trip');
    $assert(!isset($exported['catalogs']['effects']) && $exported['catalogs']['items'][0]['effects'][0]['skill_key'] === 'alchemy', 'Effect value/reference must be exported');
    $raceExport = json_decode(json_encode($from->export($actor, 'races', 'section'), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    $assert(count($raceExport['catalogs']['spells']) === 1, 'Race path choice must include its spell pool');
    // Сдвигаем ID всех таблиц: файл должен работать без совпадения локальных ID.
    foreach (CatalogRegistry::keys() as $key) $target->statement('ALTER TABLE '.$target->getQueryGrammar()->wrapTable('roleplay_'.CatalogRegistry::tableFor($key)).' AUTO_INCREMENT = 100');
    $report = $to->import($actor, $exported, 'skip', 1, true);
    $assert($report['applied'] && !$report['errors'], 'Export must import into a different installation');
    $again = json_decode(json_encode($to->export($actor, 'items', 'all'), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    $assert($again == $exported, 'All editable fields and nested links must round-trip independently of IDs');
    $report = $to->import($actor, $exported, 'skip', 2, true);
    $assert($report['skipped'] === 10 && $revision($target) === 2, 'Repeated skip import must not create duplicates');
    $oldId = $target->table('roleplay_perks')->where('slug', 'nature')->value('id');
    $update = ['format' => 'roleplay-catalogs', 'version' => 1, 'catalogs' => ['perks' => $exported['catalogs']['perks']]];
    $update['catalogs']['perks'][0]['name'] = 'Новое название';
    $report = $to->import($actor, $update, 'update', 2, true);
    $assert($report['applied'] && $target->table('roleplay_perks')->where('id', $oldId)->value('name') === 'Новое название', 'Update must preserve identity');
    $bad = $document;
    $bad['catalogs']['items'][0]['key'] = 'new-cloak';
    $bad['catalogs']['items'][0]['weight'] = -1;
    $bad['catalogs']['paths'][] = ['key' => 'new-path', 'name' => 'Новый путь'];
    $report = $to->import($actor, $bad, 'skip', 3, true);
    $assert(!$report['applied'] && count($report['errors']) > 0 && $revision($target) === 3, 'Invalid field must roll back the entire package');
    $assert(!$target->table('roleplay_magic_paths')->where('slug', 'new-path')->exists(), 'Valid sibling must also roll back');
    $bad['catalogs']['items'][0]['item_type_key'] = 'missing-type';
    $report = $to->import($actor, $bad, 'skip', 3, false);
    $assert(str_contains(implode(' ', $report['errors']), 'missing-type'), 'Missing references must name their key');
    $guard = ['format' => 'roleplay-catalogs', 'version' => 1, 'catalogs' => ['perks' => $exported['catalogs']['perks']]];
    $guard['catalogs']['perks'][0]['effects'][0]['skill_key'] = 'missing-skill';
    $report = $to->import($actor, $guard, 'update', 3, true);
    $assert(!$report['applied'] && count($report['errors']) > 0, 'Invalid inline target must roll back owner');
    try {$to->import($actor, $exported, 'update', 2, true); throw new RuntimeException('Stale revision accepted');}
    catch (\Maxcuso\Roleplay\Api\ApiProblem $problem) {$assert($problem->status === 409, 'Stale preview must be rejected');}
    try {$to->export(new User(), 'items', 'all'); throw new RuntimeException('Guest export accepted');}
    catch (\Flarum\User\Exception\PermissionDeniedException $expected) {}
    // Поиск включает весь результат, даже когда в таблице несколько страниц.
    $many = ['format' => 'roleplay-catalogs', 'version' => 1, 'catalogs' => ['perks' => []]];
    for ($i = 1; $i <= 31; ++$i) $many['catalogs']['perks'][] = ['key' => 'batch-'.$i, 'name' => 'Пакет '.$i];
    $assert($to->import($actor, $many, 'skip', 3, true)['applied'], 'Batch fixture failed');
    $filtered = $to->export($actor, 'perks', 'filtered', ['search' => 'Пакет']);
    $assert(count($filtered['catalogs']->perks) === 31, 'Filtered export must include all pages');
    echo "Catalog JSON: round-trip, preview rollback, stable keys, updates, guards and all-page filtering passed.\n";
} finally {
    foreach (array_reverse($connections) as $db) {$ranks['down']($db->getSchemaBuilder()); $base['down']($db->getSchemaBuilder());}
}
