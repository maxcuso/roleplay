<?php
declare(strict_types=1);

use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Maxcuso\Roleplay\Api\ApiProblem;
use Maxcuso\Roleplay\Catalog\SeedRanks;
use Maxcuso\Roleplay\Catalog\Shared\CatalogRegistry;
use Maxcuso\Roleplay\Character\Moderation\CharacterManagement;
use Maxcuso\Roleplay\Character\Profile\CharacterProfile;
use Maxcuso\Roleplay\Character\State\{CharacterStateReader, CharacterSnapshotBuilder};
use Maxcuso\Roleplay\Rules\DevelopmentRules;
use Maxcuso\Roleplay\Transfer\CatalogJsonTransfer;

// Все записи остаются в таблицах с уникальным префиксом; аккаунты и темы не создаём.
$site = require __DIR__.'/../../../site.php';
$container = $site->bootApp()->getContainer();
set_exception_handler(function (Throwable $error): void {fwrite(STDERR, (string) $error."\n"); exit(1);});
$original = $container->make(ConnectionInterface::class);
$prefix = 'rpn_'.bin2hex(random_bytes(3)).'_';
if (!preg_match('/^rpn_[0-9a-f]{6}_$/D', $prefix) || $prefix === $original->getTablePrefix()) throw new RuntimeException('Unsafe prefix');
$db = new ($original::class)($original->getPdo(), $original->getDatabaseName(), $prefix, $original->getConfig());
$db->setTransactionManager(new \Illuminate\Database\DatabaseTransactionsManager());
$schema = $db->getSchemaBuilder();
require_once __DIR__.'/support/NotificationServices.php';
$testNotifications = \Maxcuso\Roleplay\Tests\Support\NotificationServices::make();
$characterOperations = new \Maxcuso\Roleplay\Character\Operations\CharacterOperation($db, $testNotifications);
$base = require __DIR__.'/fixtures/migrations-2026-10-30/2026_10_02_000001_create_base_catalogs.php';
$migration = require __DIR__.'/fixtures/migrations-2026-10-30/2026_10_09_000001_add_ranks.php';
$assert = function (bool $ok, string $message): void {if (!$ok) throw new RuntimeException($message);};
$reject = function (callable $action, string $code) use ($assert): void {
    try {$action();} catch (ApiProblem $error) {$assert($error->problemCode === $code, 'Wrong rejection: '.$error->problemCode); return;}
    throw new RuntimeException('Missing rejection: '.$code);
};
$admin = User::findOrFail(1);
$owner = new class extends User {
    public function can(string $ability, mixed $arguments = []): bool {return in_array($ability, ['viewForum', 'roleplay.view'], true);}
    public function isAdmin(): bool {return false;}
};
$owner->id = 100; $owner->exists = true;
$base['up']($schema);
(require __DIR__.'/fixtures/migrations-2026-10-30/2026_10_17_000001_race_presentation.php')['up']($schema);
(require __DIR__.'/fixtures/migrations-2026-10-30/2026_10_22_000002_race_quote_image.php')['up']($schema);
(require __DIR__.'/fixtures/migrations-2026-10-30/2026_10_30_000003_literature_contents.php')['up']($schema);
$migration['up']($schema);
(require __DIR__.'/fixtures/migrations-2026-10-30/2026_10_10_000002_roleplayer_statistics.php')['up']($schema);
(require __DIR__.'/fixtures/migrations-2026-10-30/2026_10_12_000002_development_configuration.php')['up']($schema);
try {
    $revision = fn () => (int) $db->table('roleplay_catalog_state')->value('revision');
    $seed = new SeedRanks($db);
    $assert($seed->run($admin) === 10 && $seed->run($admin) === 0, 'Seed is idempotent');
    $registry = new CatalogRegistry($db);
    $ranks = $registry->get('ranks');
    $rows = $ranks->list($admin)['rows'];
    $assert(array_column($rows, 'level') === range(1, 10), 'Ranks ordered I–X');
    $assert(array_column($rows, 'letter') === ['א','ב','ג','ד','ה','ו','ז','ח','ט','י'], 'Hebrew letters');
    [$alef, $bet] = $rows;
    $ranks->save($alef['id'], $revision(), ['name' => 'Алеф обновлён', 'letter' => 'א', 'level' => 1, 'enabled' => true], $admin);
    $seed->run($admin);
    $assert($ranks->list($admin)['rows'][0]['name'] === 'Алеф обновлён', 'Seed preserves edits');
    $reject(fn () => $ranks->save(null, $revision(), ['name'=>'Повтор','letter'=>'א','level'=>1], $admin), 'rank_level_taken');
    $race = $registry->get('races')->save(null, $revision(), ['name' => 'Проверка'], $admin)['id'];
    $now = gmdate('Y-m-d H:i:s');
    $id = $db->table('roleplay_characters')->insertGetId(['user_id'=>100, 'race_id'=>$race,
        'first_name'=>'Проверка', 'status'=>'active', 'created_at'=>$now, 'updated_at'=>$now]);
    $row = fn () => (array) $db->table('roleplay_characters')->find($id);
    // Полный профиль требует четыре характеристики; они существуют только в этой временной схеме.
    foreach (DevelopmentRules::defaults()->attributeSlugs() as $slug) {
        $attribute = $registry->get('attributes')->save(null, $revision(), ['name'=>$slug, 'enabled'=>true], $admin)['id'];
        $db->table('roleplay_character_attributes')->insert(['character_id'=>$id, 'attribute_id'=>$attribute,
            'base_value'=>3, 'racial_bonus'=>0]);
    }
    $command = fn (?int $rank) => ['operation_key'=>\Ramsey\Uuid\Uuid::uuid4()->toString(),
        'revision'=>(int) $row()['revision'], 'catalog_revision'=>$revision(),
        'action'=>'set_rank', 'data'=>['rank_id'=>$rank], 'comment'=>'Решение модератора'];
    $service = new CharacterManagement($db, $characterOperations);
    $assert(count($service->lookups($id, $admin)['lookups']['ranks']) === 10, 'Lookup includes ranks');
    $reject(fn () => $service->change($id, $owner, $command($alef['id'])), 'management_forbidden');
    $first = $command($alef['id']);
    $service->change($id, $admin, $first);
    $service->change($id, $admin, $first);
    $assert((int) $row()['rank_id'] === $alef['id'] && $db->table('roleplay_character_events')->count() === 1, 'Exact retry is applied once');
    $reject(fn () => $ranks->remove($alef['id'], $revision(), $admin), 'catalog_entry_in_use');
    $service->change($id, $admin, $command($bet['id']));
    $reader = new CharacterStateReader($db);
    $builder = new CharacterSnapshotBuilder($reader, new DevelopmentRules(require __DIR__.'/../resources/rules/development.php'));
    $assert($builder->build($id)->character['rank']['letter'] === 'ב', 'Profile includes rank');
    $profile = (new CharacterProfile($db))->read($id, $admin);
    $assert($profile['snapshot']['character']['rank_id'] === $bet['id']
        && $profile['snapshot']['character']['rank']['letter'] === 'ב', 'Public API retains current rank for grant window');
    $ranks->toggle($bet['id'], $revision(), false, $admin);
    $assert($builder->build($id)->character['rank']['name'] === 'Бет', 'Disabled assigned rank remains visible');
    $service->change($id, $admin, $command(null));
    $reject(fn () => $service->change($id, $admin, $command($bet['id'])), 'rank_unavailable');
    $assert($row()['rank_id'] === null && $db->table('roleplay_character_events')->count() === 3, 'Removal recorded without side effects');
    $events = $db->table('roleplay_character_events')->orderBy('id')->get();
    $change = json_decode($events[1]->payload, true, flags: JSON_THROW_ON_ERROR);
    $assert($change['before']['name'] === 'Алеф обновлён' && $change['after']['name'] === 'Бет', 'History stores both names');
    $db->table('roleplay_characters')->where('id', $id)->update(['status'=>'creating']);
    $reject(fn () => $service->change($id, $admin, $command($alef['id'])), 'management_closed');
    $transfer = new CatalogJsonTransfer($db);
    $document = json_decode(json_encode($transfer->export($admin, 'ranks', 'section'), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    $assert(count($document['catalogs']['ranks']) === 10, 'JSON includes ten ranks');
    $document['catalogs']['ranks'][0]['name'] = 'Алеф из JSON';
    $preview = $transfer->import($admin, $document, 'update', $revision(), false);
    $assert(!$preview['errors'] && $ranks->list($admin)['rows'][0]['name'] === 'Алеф обновлён', 'Preview does not modify ranks');
    $applied = $transfer->import($admin, $document, 'update', $revision(), true);
    $assert($applied['applied'] && $ranks->list($admin)['rows'][0]['name'] === 'Алеф из JSON', 'JSON applies by stable key');
    $ranks->remove($rows[9]['id'], $revision(), $admin);
    $restored = $transfer->import($admin, $document, 'update', $revision(), true);
    $assert($restored['applied'] && $restored['new'] === 1 && $ranks->list($admin)['total'] === 10, 'JSON creates missing rank');
    echo "Ranks passed: defaults, permissions, assignment/removal, retry, profile, history and JSON.\n";
} finally {
    $schema->dropIfExists('roleplay_player_statistics');
    $schema->dropIfExists('roleplay_development_config');
    $migration['down']($schema);
    $base['down']($schema);
}
