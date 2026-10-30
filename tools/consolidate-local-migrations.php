<?php

declare(strict_types=1);

/** Только существующая локальная копия. Схема и игровые данные не изменяются.
 * snapshot: резервные копии до правки кода; apply-history: убрать 21 старую запись журнала.
 */
if (PHP_SAPI !== 'cli' || getenv('ROLEPLAY_TEST_COPY') !== '1') throw new RuntimeException('Local CLI only');
$mode = $argv[1] ?? '';
if (!in_array($mode, ['snapshot', 'apply-history'], true)) throw new RuntimeException('Use snapshot or apply-history');
$site = require '/var/www/html/site.php';
$container = $site->bootApp()->getContainer();
set_exception_handler(function (Throwable $error): void { fwrite(STDERR, (string) $error."\n"); exit(1); });
require_once __DIR__.'/../tests/support/SchemaSnapshot.php';
$db = $container->make(\Illuminate\Database\ConnectionInterface::class);
$actor = \Flarum\User\User::findOrFail(1);
$actor->assertAdmin();
$extension = 'maxcuso-roleplay';
$baseline = '2026_10_02_000001_create_base_catalogs';
$fixtureFiles = glob(__DIR__.'/../tests/fixtures/migrations-2026-10-30/*.php');
$expected = array_map(fn ($file) => basename($file, '.php'), $fixtureFiles);
sort($expected);
if (count($expected) !== 22 || $expected[0] !== $baseline) throw new RuntimeException('Unexpected historical migration fixture');
$directory = __DIR__.'/../tests/.runtime/schema-consolidation-2026-10-30';
$manifestPath = $directory.'/snapshot.json';
$history = fn () => $db->table('migrations')->where('extension', $extension)->orderBy('migration')->pluck('migration')->all();
$write = function (string $path, mixed $value): void {
    if (is_file($path)) throw new RuntimeException('Backup already exists: '.basename($path));
    if (file_put_contents($path, json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) throw new RuntimeException('Cannot write backup');
};

if ($mode === 'snapshot') {
    if ($history() !== $expected) throw new RuntimeException('Local migration history is not the complete expected chain');
    if (!is_dir($directory) && !mkdir($directory, 0775, true)) throw new RuntimeException('Cannot create backup directory');
    if (is_file($manifestPath)) throw new RuntimeException('Snapshot already exists');
    $transfer = new \Maxcuso\Roleplay\Transfer\CatalogJsonTransfer($db);
    $write($directory.'/catalogs.json', $transfer->export($actor, 'items', 'all'));
    $write($directory.'/settings.json', [
        'format' => 'roleplay-settings-backup', 'version' => 1,
        'creation' => SchemaSnapshot::rows($db, 'roleplay_creation_config'),
        'development' => SchemaSnapshot::rows($db, 'roleplay_development_config'),
        'permissions' => $db->table('group_permission')->where(function ($query): void {
            $query->where('permission', 'like', 'roleplay.%')->orWhere('permission', 'like', 'quest-events.%');
        })->orderBy('permission')->orderBy('group_id')->get()->all(),
    ]);
    $sql = "-- Local Roleplay snapshot before schema consolidation. Same database/prefix only.\nSET FOREIGN_KEY_CHECKS=0;\n";
    foreach (SchemaSnapshot::tables($db) as $name) {
        $table = $db->getQueryGrammar()->wrapTable($name);
        $create = array_values((array) $db->selectOne('SHOW CREATE TABLE '.$table))[1];
        $sql .= 'DROP TABLE IF EXISTS '.$table.";\n".$create.";\n";
        foreach (SchemaSnapshot::rows($db, $name) as $row) {
            $columns = implode(',', array_map(fn ($column) => $db->getQueryGrammar()->wrap($column), array_keys($row)));
            $values = implode(',', array_map(fn ($value) => $value === null ? 'NULL' : $db->getPdo()->quote((string) $value), array_values($row)));
            $sql .= 'INSERT INTO '.$table.' ('.$columns.') VALUES ('.$values.");\n";
        }
    }
    $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
    if (file_put_contents($directory.'/before.sql', $sql) === false) throw new RuntimeException('Cannot write SQL snapshot');
    $write($manifestPath, [
        'database' => $db->getDatabaseName(), 'prefix' => $db->getTablePrefix(),
        'history' => $db->table('migrations')->where('extension', $extension)->orderBy('migration')->get()->all(),
        'data_hashes' => SchemaSnapshot::hashes($db), 'structure' => SchemaSnapshot::structure($db),
        'sql_sha256' => hash_file('sha256', $directory.'/before.sql'),
        'migration_sha256' => array_combine($expected, array_map(fn ($file) => hash_file('sha256', $file), $fixtureFiles)),
    ]);
    echo 'Snapshot saved: '.count(SchemaSnapshot::tables($db)).' domain tables, '.count($expected)." migration records\n";
    exit;
}

$snapshot = json_decode(file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
if ($snapshot['database'] !== $db->getDatabaseName() || $snapshot['prefix'] !== $db->getTablePrefix()
    || !hash_equals($snapshot['sql_sha256'], hash_file('sha256', $directory.'/before.sql'))) throw new RuntimeException('Snapshot identity mismatch');
if (!is_file($directory.'/verified.json')) throw new RuntimeException('Run the isolated schema equivalence check first');
$verified = json_decode(file_get_contents($directory.'/verified.json'), true, flags: JSON_THROW_ON_ERROR);
$baselinePath = __DIR__.'/../migrations/'.$baseline.'.php';
if (!hash_equals($verified['baseline_sha256'], hash_file('sha256', $baselinePath))) throw new RuntimeException('Baseline changed after verification');
if (count(glob(__DIR__.'/../migrations/*.php')) !== 1) throw new RuntimeException('Expected one active migration');
if (SchemaSnapshot::hashes($db) !== $snapshot['data_hashes'] || SchemaSnapshot::structure($db) !== $snapshot['structure']) {
    throw new RuntimeException('Domain data changed after snapshot; inspect before normalizing history');
}
$db->transaction(function () use ($db, $extension, $expected, $baseline): void {
    $ran = $db->table('migrations')->where('extension', $extension)->orderBy('migration')->lockForUpdate()->pluck('migration')->all();
    if ($ran === [$baseline]) return;
    if ($ran !== $expected) throw new RuntimeException('Unexpected migration history');
    $db->table('migrations')->where('extension', $extension)->whereIn('migration', array_values(array_diff($expected, [$baseline])))->delete();
});
if ($history() !== [$baseline] || SchemaSnapshot::hashes($db) !== $snapshot['data_hashes']) throw new RuntimeException('Post-check failed');
if (!is_file($directory.'/history-normalized.json')) {
    $write($directory.'/history-normalized.json', ['remaining' => $history(), 'all_domain_hashes_unchanged' => true]);
}

// Штатный обновлятор должен пропустить уже установленную базовую миграцию.
// Запускаем только Roleplay: чужие ожидающие миграции форума не затрагиваем.
$installed = $container->make(\Flarum\Extension\ExtensionManager::class)->getExtension($extension);
if ($installed === null) throw new RuntimeException('Roleplay is not installed');
$container->make(\Flarum\Database\Migrator::class)->run(__DIR__.'/../migrations', $installed);
if ($history() !== [$baseline]
    || SchemaSnapshot::hashes($db) !== $snapshot['data_hashes']
    || SchemaSnapshot::structure($db) !== $snapshot['structure']) {
    throw new RuntimeException('Native migrator changed existing data or schema');
}
if (!is_file($directory.'/native-migrator-verified.json')) {
    $write($directory.'/native-migrator-verified.json', ['native_migrator_noop' => true, 'all_domain_hashes_unchanged' => true]);
}
echo "History normalized; domain rows and schema unchanged\n";
