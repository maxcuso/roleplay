<?php

declare(strict_types=1);

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Maxcuso\Roleplay\Transfer\CatalogJsonTransfer;

if (PHP_SAPI !== 'cli' || getenv('ROLEPLAY_TEST_COPY') !== '1') throw new RuntimeException('Local CLI only');
$site = require '/var/www/html/site.php';
$container = $site->bootApp()->getContainer();
set_exception_handler(function (Throwable $error): void { fwrite(STDERR, (string) $error."\n"); exit(1); });
require_once __DIR__.'/support/SchemaSnapshot.php';
$original = $container->make(ConnectionInterface::class);
$admin = \Flarum\User\User::findOrFail(1);
$admin->assertAdmin();
$baselinePath = __DIR__.'/../migrations/2026_10_02_000001_create_base_catalogs.php';
$baseline = require $baselinePath;
$directory = __DIR__.'/.runtime/schema-consolidation-2026-10-30';
if (!is_dir($directory) && !mkdir($directory, 0775, true)) {
    throw new RuntimeException('Cannot create test report directory');
}
$token = bin2hex(random_bytes(3));
$connections = [];
foreach (['old', 'new'] as $kind) {
    $prefix = 'rc_'.$token.($kind === 'old' ? 'o_' : 'n_');
    if ($prefix === $original->getTablePrefix()) throw new RuntimeException('Unsafe test prefix');
    $db = new ($original::class)($original->getPdo(), $original->getDatabaseName(), $prefix, $original->getConfig());
    $db->setTransactionManager(new \Illuminate\Database\DatabaseTransactionsManager());
    $connections[$kind] = $db;
}

function consolidationCore(ConnectionInterface $db): void
{
    $schema = $db->getSchemaBuilder();
    foreach (['users', 'discussions', 'posts', 'groups'] as $table) {
        $schema->create($table, fn (Blueprint $table) => $table->increments('id'));
    }
    $schema->create('group_permission', function (Blueprint $table): void {
        $table->unsignedInteger('group_id');
        $table->string('permission', 100);
        $table->unique(['group_id', 'permission']);
    });
    $db->table('groups')->insert(array_map(fn ($id) => ['id' => $id], [1, 2, 3, 4]));
}
function consolidationCheck(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
    echo 'OK: '.$message."\n";
}
function consolidationCatalogs(array $document): array
{
    // Сравниваем формат файла: JSON приводит 1.0 к 1, объекты — к ассоциативным массивам.
    $document = json_decode(json_encode($document, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    $canonical = function (mixed $value) use (&$canonical): mixed {
        if (!is_array($value)) return $value;
        foreach ($value as &$entry) $entry = $canonical($entry);
        unset($entry);
        if (!array_is_list($value)) ksort($value);
        return $value;
    };
    $result = [];
    foreach ((array) $document['catalogs'] as $name => $rows) {
        $result[$name] = [];
        foreach ($rows as $row) $result[$name][$row['key']] = $canonical($row);
        ksort($result[$name]);
    }
    ksort($result);
    return $result;
}

try {
    foreach ($connections as $db) consolidationCore($db);
    foreach (glob(__DIR__.'/fixtures/migrations-2026-10-30/*.php') as $file) (require $file)['up']($connections['old']->getSchemaBuilder());
    $baseline['up']($connections['new']->getSchemaBuilder());
    $old = SchemaSnapshot::structure($connections['old']);
    $new = SchemaSnapshot::structure($connections['new']);
    if ($old !== $new) {
        $differences = [];
        foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $table) {
            if (($old[$table] ?? null) !== ($new[$table] ?? null)) $differences[$table] = ['old' => $old[$table] ?? null, 'new' => $new[$table] ?? null];
        }
        file_put_contents($directory.'/equivalence-diff.json', json_encode($differences, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        throw new RuntimeException('Schema mismatch: '.implode(', ', array_keys($differences)));
    }
    consolidationCheck(count($new) === 35, 'All 35 domain tables match the historical chain: columns, defaults, indexes, foreign keys and checks');
    foreach (['roleplay_creation_config', 'roleplay_development_config', 'roleplay_catalog_state'] as $table) {
        $left = (array) $connections['old']->table($table)->first();
        $right = (array) $connections['new']->table($table)->first();
        unset($left['updated_at'], $right['updated_at']);
        consolidationCheck($left === $right, $table.' initial values preserved');
    }
    $permissions = fn ($db) => $db->table('group_permission')->orderBy('group_id')->orderBy('permission')->get()->toJson();
    consolidationCheck($permissions($connections['old']) === $permissions($connections['new'])
        && $connections['new']->table('group_permission')->count() === 5, 'All five default permission grants match');
    consolidationCheck($connections['new']->table('roleplay_characters')->count() === 0
        && $connections['new']->table('roleplay_items')->count() === 0, 'Fresh installation contains no characters or catalog artwork');

    // Проверяем именно рабочий формат переноса справочников на новую схему.
    $catalogFile = getenv('ROLEPLAY_SCHEMA_CATALOGS') ?: $directory.'/catalogs.json';
    if (is_file($catalogFile)) {
        $document = json_decode(file_get_contents($catalogFile), true, flags: JSON_THROW_ON_ERROR);
    } else {
        // Чистый checkout не требует локального экспорта с данными рабочего форума.
        (new \Maxcuso\Roleplay\Catalog\SeedBaseCatalogs($connections['old']))->run($admin);
        $document = json_decode(json_encode((new CatalogJsonTransfer($connections['old']))
            ->export($admin, 'items', 'all'), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }
    $transfer = new CatalogJsonTransfer($connections['new']);
    $transfer->import($admin, $document, 'update', 2, true);
    $export = $transfer->export($admin, 'items', 'all');
    $expectedCatalogs = consolidationCatalogs($document);
    $actualCatalogs = consolidationCatalogs($export);
    if ($expectedCatalogs !== $actualCatalogs) file_put_contents($directory.'/catalog-diff.json', json_encode(
        ['expected' => $expectedCatalogs, 'actual' => $actualCatalogs], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    consolidationCheck($expectedCatalogs === $actualCatalogs, 'Catalog export imports into the new schema without changing definitions or references');

    // SQLite исполняет новую миграцию, а PostgreSQL проверяем на уровне генерации SQL.
    $sqlite = new \Illuminate\Database\SQLiteConnection(new PDO('sqlite::memory:'), ':memory:', 'sq_', ['driver' => 'sqlite']);
    $sqlite->setTransactionManager(new \Illuminate\Database\DatabaseTransactionsManager());
    $sqlite->getSchemaBuilder()->enableForeignKeyConstraints();
    consolidationCore($sqlite);
    $baseline['up']($sqlite->getSchemaBuilder());
    consolidationCheck($sqlite->getSchemaBuilder()->hasTable('roleplay_checks')
        && $sqlite->getSchemaBuilder()->hasColumn('roleplay_items', 'literature_contents'), 'Full SQLite installation succeeds');
    consolidationCheck($sqlite->table('roleplay_characters')->count() === 0, 'SQLite installation has no test characters');
    $now = gmdate('Y-m-d H:i:s');
    $sqlite->table('roleplay_attributes')->insert(['name' => 'Physiology', 'slug' => 'physiology', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now]);
    foreach (['positive' => ['physiology' => 1], 'negative' => ['physiology' => -1], 'zero' => ['physiology' => 0], 'missing' => []] as $key => $bonuses) {
        $sqlite->table('roleplay_races')->insert(['name' => $key, 'slug' => 'race-'.$key, 'enabled' => true,
            'rules' => json_encode(['fixed_bonuses' => (object) $bonuses], JSON_THROW_ON_ERROR), 'created_at' => $now, 'updated_at' => $now]);
    }
    $raceStore = new \Maxcuso\Roleplay\Catalog\Shared\DefinitionStore($sqlite, new \Maxcuso\Roleplay\Catalog\Shared\CatalogRevision($sqlite));
    $raceCatalog = new \Maxcuso\Roleplay\Catalog\Races\RaceCatalog($raceStore);
    $races = $raceCatalog->list($admin, '', null, 1, ['attribute' => 'physiology']);
    consolidationCheck(array_column($races['rows'], 'slug') === ['race-positive', 'race-negative'], 'SQLite race filter includes nonzero bonuses and excludes zero/missing keys');
    $inspection = (new \Maxcuso\Roleplay\Transfer\LegacyInspector($sqlite))->inspect();
    consolidationCheck($inspection['read_only'] && $inspection['tables']['races']['rows'] === 4, 'Legacy inspector lists prefixed SQLite tables without information_schema');
    $pg = new \Illuminate\Database\PostgresConnection(new PDO('sqlite::memory:'), 'pretend', 'pg_', ['driver' => 'pgsql']);
    $pgSql = $pg->pretend(fn () => $baseline['up']($pg->getSchemaBuilder()));
    $pgText = implode("\n", array_column($pgSql, 'query'));
    consolidationCheck(str_contains($pgText, 'roleplay_checks') && !str_contains(strtoupper($pgText), 'AS SIGNED'), 'PostgreSQL DDL compiles without MySQL casts; live PostgreSQL is not available locally');
    $pgRaces = $pg->table('roleplay_races');
    (new ReflectionMethod($raceCatalog, 'applyFilters'))->invoke($raceCatalog, $pgRaces, ['attribute' => 'physiology']);
    consolidationCheck(!str_contains(strtolower($pgRaces->toSql()), 'json_extract') && str_contains($pgRaces->toSql(), '->'), 'Race filter compiles with PostgreSQL JSON operators');

    foreach ([\Maxcuso\Roleplay\Api\CharacterManagementController::class, \Maxcuso\Roleplay\Api\CharacterMechanicsController::class,
        \Maxcuso\Roleplay\Api\CharacterProfileController::class, \Maxcuso\Roleplay\Api\CharacterEquipmentController::class,
        \Maxcuso\Roleplay\Api\CharacterProgressionController::class, \Maxcuso\Roleplay\Api\CharacterReviewController::class] as $controller) {
        consolidationCheck($container->make($controller) instanceof \Psr\Http\Server\RequestHandlerInterface, basename(str_replace('\\', '/', $controller)).' resolves through Flarum container');
    }
    $profile = $container->make(\Maxcuso\Roleplay\Character\Profile\CharacterProfile::class);
    consolidationCheck((new ReflectionProperty($profile, 'topics'))->getValue($profile) instanceof \Maxcuso\Roleplay\Character\Application\ApplicationTopics, 'Injected profile keeps the forum topic adapter');

    // Проверка удаления только новых RP-таблиц: обычные форумные события сохраняются.
    $baseline['down']($connections['new']->getSchemaBuilder());
    consolidationCheck(SchemaSnapshot::tables($connections['new']) === ['quest_event_participants', 'quest_events'], 'Uninstall removes RP schema and preserves adopted forum events');
    file_put_contents($directory.'/verified.json', json_encode([
        'baseline_sha256' => hash_file('sha256', $baselinePath), 'tables' => 35,
        'mysql_schema_equivalent' => true, 'catalog_roundtrip' => true, 'sqlite_installation' => true,
        'postgresql_sql_compilation' => true, 'live_postgresql' => false,
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo "Consolidated schema checks passed\n";
} finally {
    foreach ($connections as $db) {
        if (!preg_match('/^rc_[0-9a-f]{6}[on]_$/D', $db->getTablePrefix())) throw new RuntimeException('Unsafe cleanup prefix');
        $schema = $db->getSchemaBuilder();
        $schema->disableForeignKeyConstraints();
        try {
            foreach ([...SchemaSnapshot::tables($db), 'group_permission', 'groups', 'posts', 'discussions', 'users'] as $table) $schema->dropIfExists($table);
        } finally { $schema->enableForeignKeyConstraints(); }
    }
}
