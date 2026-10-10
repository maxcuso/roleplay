<?php
declare(strict_types=1);

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Maxcuso\Roleplay\Character\Profile\RoleplayerStatistics;
use Maxcuso\Roleplay\Character\State\CharacterBalances;

// Проверяем перенос истории и сохранение опыта только в изолированных временных таблицах.
$site = require __DIR__.'/../../../site.php';
$container = $site->bootApp()->getContainer();
set_exception_handler(function (Throwable $error): void {fwrite(STDERR, (string) $error."\n"); exit(1);});
$original = $container->make(ConnectionInterface::class);
$prefix = 'rpn_player_'.bin2hex(random_bytes(3)).'_';
$db = new ($original::class)($original->getPdo(), $original->getDatabaseName(), $prefix, $original->getConfig());
$schema = $db->getSchemaBuilder();
$assert = function (bool $ok, string $message): void {if (!$ok) throw new RuntimeException($message);};
try {
    $schema->create('roleplay_characters', function (Blueprint $table): void {
        $table->increments('id'); $table->unsignedInteger('user_id'); $table->string('status');
        $table->string('mechanics_status'); $table->boolean('literature_approved');
    });
    $schema->create('roleplay_character_events', function (Blueprint $table): void {
        $table->increments('id'); $table->unsignedInteger('character_id'); $table->string('type');
        $table->text('payload'); $table->dateTime('created_at');
    });
    $schema->create('roleplay_character_balances', function (Blueprint $table): void {
        $table->unsignedInteger('character_id'); $table->string('resource_key');
        $table->unsignedBigInteger('amount'); $table->dateTime('updated_at');
    });
    foreach ([1, 2] as $id) {
        $db->table('roleplay_characters')->insert(['id' => $id, 'user_id' => 123, 'status' => 'active',
            'mechanics_status' => 'approved', 'literature_approved' => true]);
        foreach (['experience_active', 'experience_total'] as $key) {
            $db->table('roleplay_character_balances')->insert(['character_id' => $id, 'resource_key' => $key,
                'amount' => 70, 'updated_at' => '2026-09-10 00:00:00']);
        }
    }
    $event = function (int $id, string $type, array $payload, string $at = '2026-09-10 00:00:00') use ($db): void {
        $db->table('roleplay_character_events')->insert(['character_id' => $id, 'type' => $type,
            'payload' => json_encode($payload), 'created_at' => $at]);
    };
    $event(1, 'character_created', ['plan' => ['balances' => ['experience_total' => 50]]]);
    $event(1, 'character_managed', ['action' => 'reward', 'experience' => 9, 'gold' => 2]);
    $event(1, 'character_managed', ['action' => 'reward', 'experience' => -2]);
    $event(2, 'character_managed', ['action' => 'balance', 'resource' => 'experience_active', 'delta' => 4]);
    $event(2, 'character_managed', ['action' => 'balance', 'resource' => 'gold', 'delta' => 100]);
    $event(1, 'review_decided', ['activated' => true], '2026-09-12 12:00:00');
    $event(2, 'review_decided', ['activated' => true], '2026-09-11 12:00:00');
    (require __DIR__.'/../migrations/2026_10_10_000002_roleplayer_statistics.php')['up']($schema);
    $stats = new RoleplayerStatistics($db);
    $assert($stats->read(123) === ['earned_experience' => 13, 'active_characters' => 2,
        'started_at' => '2026-09-11T12:00:00Z'], 'Backfill must exclude starting XP/removals/gold and use earliest activation');
    $balances = new CharacterBalances($db);
    $db->transaction(function () use ($balances, $stats): void {
        $balances->adjust(1, 'experience_active', 5);
        $balances->adjust(2, 'experience_active', 7);
        $balances->adjust(1, 'experience_active', -3);
        $stats->activated(123, '2026-10-10 12:00:00');
    });
    $assert($stats->read(123)['earned_experience'] === 25, 'Awards from both characters add; spending never subtracts');
    $assert($stats->read(123)['started_at'] === '2026-09-11T12:00:00Z', 'Later activations must preserve first date');
    try {
        $db->transaction(function () use ($balances): void {
            $balances->adjust(1, 'experience_active', 100);
            throw new RuntimeException('Rollback award');
        });
    } catch (RuntimeException $error) {
        $assert($error->getMessage() === 'Rollback award', 'Unexpected transaction error');
    }
    $assert($stats->read(123)['earned_experience'] === 25, 'Failed transaction must roll back player XP too');
    foreach (['character_events', 'character_balances', 'characters'] as $table) {
        $db->table('roleplay_'.$table)->delete();
    }
    $assert($stats->read(123) === ['earned_experience' => 25, 'active_characters' => 0,
        'started_at' => '2026-09-11T12:00:00Z'], 'Deleting all characters must preserve player XP and date');
    $assert($stats->read(999) === ['earned_experience' => 0, 'active_characters' => 0, 'started_at' => null],
        'New player must have empty statistics');
    echo "Roleplayer statistics passed: history backfill, shared awards, rollback, first activation and persistence after deletion.\n";
} finally {
    foreach (['player_statistics', 'character_events', 'character_balances', 'characters'] as $table) {
        $schema->dropIfExists('roleplay_'.$table);
    }
}
