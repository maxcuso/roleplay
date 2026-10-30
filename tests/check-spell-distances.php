<?php
declare(strict_types=1);

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Maxcuso\Roleplay\Api\ApiProblem;
use Maxcuso\Roleplay\Catalog\Spells\SpellCatalog;
use Maxcuso\Roleplay\Catalog\Shared\{CatalogRevision, DefinitionStore};

// Изолированная таблица: проверяем формат метров, не меняя заклинания форума.
$site = require __DIR__.'/../../../site.php';
$container = $site->bootApp()->getContainer();
set_exception_handler(function (Throwable $error): void {fwrite(STDERR, (string) $error."\n"); exit(1);});
$original = $container->make(ConnectionInterface::class);
$prefix = 'rpn_distance_'.bin2hex(random_bytes(3)).'_';
$db = new ($original::class)($original->getPdo(), $original->getDatabaseName(), $prefix, $original->getConfig());
$schema = $db->getSchemaBuilder();
$migration = require __DIR__.'/fixtures/migrations-2026-10-30/2026_10_10_000003_fractional_spell_distances.php';
$assert = function (bool $ok, string $message): void {if (!$ok) throw new RuntimeException($message);};
try {
    $schema->create('roleplay_spells', function (Blueprint $table): void {
        $table->increments('id'); $table->unsignedInteger('distance_value')->nullable();
    });
    $db->table('roleplay_spells')->insert([['distance_value' => 3], ['distance_value' => null]]);
    $starting = require __DIR__.'/fixtures/migrations-2026-10-30/2026_10_30_000002_starting_spells.php';
    $starting['up']($schema);
    $assert($db->table('roleplay_spells')->where('is_starting', true)->count() === 2, 'Migration must preserve starting availability of existing spells');
    $migration['up']($schema);
    $assert((float) $db->table('roleplay_spells')->where('id', 1)->value('distance_value') === 3.0
        && $db->table('roleplay_spells')->where('id', 2)->value('distance_value') === null, 'Whole/null distances changed');
    $catalog = new SpellCatalog(new DefinitionStore($original, new CatalogRevision($original)));
    $input = ['name' => 'Проверка дистанции', 'magic_path_id' => (int) $original->table('roleplay_magic_paths')->value('id'),
        'distance_mode' => 'distance', 'distance_value' => 0.5];
    $fields = $catalog->validate($input);
    $assert($fields['is_starting'] === true, 'Older input keeps the starting default');
    $nonStarting = $catalog->validate($input + ['is_starting' => false]);
    $assert($catalog->present($nonStarting + ['id' => 100])['is_starting'] === false, 'False must survive validation and presentation');
    foreach ([0, 1, 'false'] as $bad) {
        try {$catalog->validate($input + ['is_starting' => $bad]);}
        catch (ApiProblem $error) {continue;}
        throw new RuntimeException('Non-boolean starting flag accepted');
    }
    $id = $db->table('roleplay_spells')->insertGetId(['distance_value' => $fields['distance_value']]);
    $row = $catalog->present(array_replace($fields, (array) $db->table('roleplay_spells')->find($id)));
    $wire = json_decode(json_encode($row, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    $assert($wire['distance_value'] === 0.5, 'SQL/catalog/JSON truncated fractional distance');
    foreach (['self', 'touch'] as $mode) {
        $fields = $catalog->validate(array_replace($input, ['distance_mode' => $mode]));
        $assert($catalog->present($fields + ['id' => 100])['distance_value'] === null, 'Self/touch must retain null');
    }
    foreach ([-0.5, '0.5', 1000001] as $bad) {
        try {$catalog->validate(array_replace($input, ['distance_value' => $bad]));}
        catch (ApiProblem $error) {continue;}
        throw new RuntimeException('Invalid distance accepted');
    }
    try {$migration['down']($schema); throw new LogicException('Fractional rollback accepted');}
    catch (RuntimeException $error) {
        $assert(!$error instanceof LogicException, 'Rollback must protect fractional distances');
    }
    $db->table('roleplay_spells')->where('id', $id)->delete();
    $migration['down']($schema);
    $assert($db->table('roleplay_spells')->where('id', 2)->value('distance_value') === null, 'Rollback changed null');
    $starting['down']($schema);
    $assert(!$schema->hasColumn('roleplay_spells', 'is_starting'), 'Starting migration rollback failed');
    echo "Spell distances passed: whole/null preservation, 0.5 through SQL/catalog/JSON, validation and safe rollback.\n";
} finally {$schema->dropIfExists('roleplay_spells');}
