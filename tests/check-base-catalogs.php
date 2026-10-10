<?php

declare(strict_types=1);

use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Maxcuso\Roleplay\Api\ApiProblem;
use Maxcuso\Roleplay\Catalog\Shared\CatalogRevision;
use Maxcuso\Roleplay\Catalog\Shared\DefinitionStore;
use Maxcuso\Roleplay\Catalog\Attributes\AttributeCatalog;
use Maxcuso\Roleplay\Catalog\Races\RaceCatalog;
use Maxcuso\Roleplay\Catalog\MagicPaths\MagicPathCatalog;
use Maxcuso\Roleplay\Catalog\ItemTypes\ItemTypeCatalog;
use Maxcuso\Roleplay\Catalog\PerkSources\PerkSourceCatalog;
use Maxcuso\Roleplay\Catalog\SeedBaseCatalogs;

// Flarum и существующий администратор используются только для проверки прав.
$site = require __DIR__.'/../../../site.php';
$container = $site->bootApp()->getContainer();
// Flarum логирует необработанное исключение без ненулевого кода CLI; тест обязан явно завершиться ошибкой.
set_exception_handler(function (\Throwable $error): void {
    fwrite(STDERR, (string) $error."\n");
    exit(1);
});
spl_autoload_register(function (string $class): void {
    $namespace = 'Maxcuso\\Roleplay\\';
    if (!str_starts_with($class, $namespace)) return;
    $path = __DIR__.'/../src/'.str_replace('\\', '/', substr($class, strlen($namespace))).'.php';
    if (is_file($path)) require_once $path;
}, true, true);
$originalDb = $container->make(ConnectionInterface::class);
$admin = User::findOrFail(1);
$admin->assertAdmin();

// Отдельный объект подключения: рабочий префикс форума не меняется.
$testPrefix = 'rpn_'.bin2hex(random_bytes(3)).'_';
if (!preg_match('/^rpn_[0-9a-f]{6}_$/D', $testPrefix) || $testPrefix === $originalDb->getTablePrefix()) {
    throw new RuntimeException('Небезопасный тестовый префикс.');
}
$db = new ($originalDb::class)($originalDb->getPdo(), $originalDb->getDatabaseName(), $testPrefix, $originalDb->getConfig());
$schema = $db->getSchemaBuilder();
$migration = require __DIR__.'/../migrations/2026_10_02_000001_create_base_catalogs.php';
$ranks = require __DIR__.'/../migrations/2026_10_09_000001_add_ranks.php';
$grantMigration = require __DIR__.'/../migrations/2026_10_08_000001_track_creation_grants.php';
$purposeMigration = require __DIR__.'/../migrations/2026_10_08_000002_add_item_purposes.php';

function check(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "OK: $message\n";
}
function rejected(callable $action, string $code): void {
    try { $action(); } catch (ApiProblem $error) {
        check($error->problemCode === $code, "Rejected: $code");
        return;
    }
    throw new RuntimeException("Expected rejection: $code");
}
try {
    $migration['up']($schema);
    $ranks['up']($schema);
    $grantMigration['up']($schema);
    $purposeMigration['up']($schema);
    (require __DIR__.'/../migrations/2026_10_09_000002_inline_effects.php')['up']($schema);
    (require __DIR__.'/../migrations/2026_10_10_000001_fractional_item_prices.php')['up']($schema);
    (require __DIR__.'/../migrations/2026_10_10_000003_fractional_spell_distances.php')['up']($schema);
    (require __DIR__.'/../migrations/2026_10_10_000002_roleplayer_statistics.php')['up']($schema);
    check($db->table('roleplay_attributes')->count() === 0, 'Schema contains no artwork or game definitions');
    $store = new DefinitionStore($db, new CatalogRevision($db));
    $attributes = new AttributeCatalog($store);
    $races = new RaceCatalog($store);
    $paths = new MagicPathCatalog($store);
    $types = new ItemTypeCatalog($store);
    $sources = new PerkSourceCatalog($store);
    $seed = new SeedBaseCatalogs($db);
    check($seed->run($admin) === 21, 'Explicit seed adds four attributes, eleven skills and six proficiencies');
    $db->table('roleplay_attributes')->where('slug', 'magic')->update(['icon' => 'fas fa-star']);
    $revision = fn() => (int)$db->table('roleplay_catalog_state')->value('revision');
    $before = $revision();
    check($seed->run($admin) === 0 && $revision() === $before, 'Repeated seed changes neither rows nor revision');
    check($db->table('roleplay_attributes')->where('slug', 'magic')->value('icon') === 'fas fa-star', 'Seed preserves administrator artwork');

    $pathInput = ['name' => 'Путь Пламени', 'icon' => 'fas fa-fire', 'color' => 'BC3D38', 'enabled' => true];
    $pathId = $paths->save(null, $revision(), $pathInput, $admin)['id'];
    $row = $db->table('roleplay_magic_paths')->find($pathId);
    check($row->slug === 'put-plameni' && $row->color === '#BC3D38', 'Transliteration and HEX normalization');
    $second = $paths->save(null, $revision(), $pathInput, $admin)['id'];
    check($db->table('roleplay_magic_paths')->find($second)->slug === 'put-plameni-2', 'Duplicate names have unique stable keys');
    $pathInput['name'] = 'Новое имя';
    $paths->save($pathId, $revision(), $pathInput, $admin);
    check($db->table('roleplay_magic_paths')->find($pathId)->slug === 'put-plameni', 'Rename preserves key');
    $oldRevision = $revision();
    $paths->toggle($pathId, $oldRevision, false, $admin);
    check($paths->list($admin, 'Новое', false)['total'] === 1, 'Search and disabled filter');
    rejected(fn() => $paths->save($pathId, $oldRevision, $pathInput, $admin), 'catalog_revision_conflict');
    rejected(fn() => $paths->save(999999, $revision(), $pathInput, $admin), 'catalog_entry_not_found');
    $before = $revision();
    rejected(fn() => $paths->save(null, $revision(), $pathInput + ['slug' => 'hacked'], $admin), 'invalid_catalog_data');
    check($revision() === $before, 'Failed save does not publish a revision');

    $raceId = $races->save(null, $revision(), ['name' => 'Человек', 'min_age' => 12, 'max_age' => 90], $admin)['id'];
    check((int)$db->table('roleplay_races')->find($raceId)->min_age === 12, 'Race age stored');
    rejected(fn() => $races->save(null, $revision(), ['name' => 'Ошибка', 'min_age' => 90, 'max_age' => 12], $admin), 'invalid_catalog_data');
    foreach ([-1, 0, 2] as $limit) {
        $id = $types->save(null, $revision(), ['name' => 'Тип '.$limit, 'equipment_limit' => $limit], $admin)['id'];
        check((int)$db->table('roleplay_item_types')->find($id)->equipment_limit === $limit, "Equipment limit $limit retained");
    }
    rejected(fn() => $types->save(null, $revision(), ['name' => 'Ошибка', 'equipment_limit' => -2], $admin), 'invalid_catalog_data');
    rejected(fn() => $types->save(null, $revision(), ['name' => 'Ошибка', 'is_weapon' => 'yes'], $admin), 'invalid_catalog_data');
    $sourceId = $sources->save(null, $revision(), [
        'name' => 'Навык', 'name_source' => 'skill', 'icon_source' => 'skill',
        'attribute_icons' => ['magic' => 'fas fa-magic', 'agility' => ''],
    ], $admin)['id'];
    $source = $sources->list($admin)['rows'][0];
    check($source['id'] == $sourceId && $source['attribute_icons'] === ['magic' => 'fas fa-magic'], 'Source overrides round-trip and blanks inherit');
    foreach ([
        ['name' => 'Создание персонажа'],
        ['name' => 'Ошибка', 'name_source' => 'starting'],
        ['name' => 'Ошибка', 'attribute_icons' => ['unknown' => 'fas fa-star']],
        ['name' => 'Ошибка', 'attribute_icons' => ['magic' => []]],
    ] as $invalid) {
        rejected(fn() => $sources->save(null, $revision(), $invalid, $admin), 'invalid_catalog_data');
    }
    try {
        $attributes->list(new User());
        throw new RuntimeException('Guest read accepted');
    } catch (\Flarum\User\Exception\PermissionDeniedException $e) {
        echo "OK: guest cannot read admin catalogue\n";
    }
    try {
        $paths->save(null, $revision(), $pathInput, new User());
        throw new RuntimeException('Guest write accepted');
    } catch (\Flarum\User\Exception\PermissionDeniedException $e) {
        echo "OK: guest cannot write catalogue\n";
    }
    try {
        $db->table('roleplay_races')->where('id', $raceId)->update(['min_age' => 91, 'max_age' => 12]);
        throw new RuntimeException('Database age constraint missing');
    } catch (\Illuminate\Database\QueryException $e) {
        echo "OK: database rejects invalid ages\n";
    }
    $schema->create('roleplay_test_topics', function ($table): void {
        $table->increments('id');
        $table->unsignedInteger('user_id');
        $table->unsignedInteger('tag_id');
        $table->string('title');
        $table->text('content');
        $table->boolean('hidden')->default(false);
    });
    require __DIR__.'/TemporaryApplicationTopics.php';
    $topics = new TemporaryApplicationTopics($db);
    require __DIR__.'/admin-catalog-scenarios.php';
    require __DIR__.'/moderation-scenarios.php';
    require __DIR__.'/forum-event-scenarios.php';
    require __DIR__.'/progression-scenarios.php';
    require __DIR__.'/management-scenarios.php';
    require __DIR__.'/application-scenarios.php';
    require __DIR__.'/mechanics-edit-scenarios.php';
    (require __DIR__.'/../migrations/2026_10_10_000002_roleplayer_statistics.php')['down']($schema);
    $grantMigration['down']($schema);
    $purposeMigration['down']($schema);
    $ranks['down']($schema);
    $migration['down']($schema);
    check(!$schema->hasTable('roleplay_attributes'), 'Migration rollback removes its tables');
    $migration['up']($schema);
    $ranks['up']($schema);
    $grantMigration['up']($schema);
    $purposeMigration['up']($schema);
    (require __DIR__.'/../migrations/2026_10_09_000002_inline_effects.php')['up']($schema);
    (require __DIR__.'/../migrations/2026_10_10_000001_fractional_item_prices.php')['up']($schema);
    (require __DIR__.'/../migrations/2026_10_10_000003_fractional_spell_distances.php')['up']($schema);
    (require __DIR__.'/../migrations/2026_10_10_000002_roleplayer_statistics.php')['up']($schema);
    check($db->table('roleplay_catalog_state')->value('revision') == 1, 'Clean reinstall recreates technical state');
    echo "Base catalogue checks passed.\n";
} finally {
    // Удаляем только таблицы с проверенным уникальным префиксом этого теста.
    foreach (['player_statistics', 'test_topics', 'character_events', 'operations', 'character_balances', 'character_choices', 'creation_drafts', 'actor_states', 'creation_config',
        'character_items', 'character_spells', 'character_perks', 'character_attributes', 'character_skills', 'character_weapon_proficiencies', 'characters', 'ranks', 'perk_effects', 'item_effects', 'effects', 'skill_levels', 'races', 'items', 'skills', 'weapon_proficiencies', 'spells', 'perks', 'perk_sources', 'item_types', 'magic_paths', 'attributes', 'catalog_state'] as $name) {
        $schema->dropIfExists('roleplay_'.$name);
    }
    check($originalDb->getTablePrefix() !== $testPrefix, 'Original connection prefix unchanged');
}
