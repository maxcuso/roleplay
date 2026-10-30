<?php

declare(strict_types=1);

// Изолированные временные таблицы: свежая установка, принятие старой схемы,
// повторный запуск и сохранение настроенных прав проверяются на настоящей СУБД.
if (getenv('ROLEPLAY_TEST_COPY') !== '1' || !is_file(dirname(__DIR__, 3).'/ROLEPLAY_TEST_INSTALLED')) {
    throw new RuntimeException('Run only in the installed test copy');
}
$c = (require dirname(__DIR__, 3).'/site.php')->bootApp()->getContainer();
set_exception_handler(function (Throwable $e): void { fwrite(STDERR, $e->getMessage().PHP_EOL.$e->getTraceAsString().PHP_EOL); exit(1); });
$db = $c->make(\Illuminate\Database\ConnectionInterface::class);
$connection = new ($db::class)($db->getPdo(), $db->getDatabaseName(), 'rpe_'.bin2hex(random_bytes(3)).'_', $db->getConfig());
$schema = $connection->getSchemaBuilder();
$tables = ['quest_event_participants', 'quest_events', 'group_permission', 'groups', 'posts', 'discussions', 'users'];
$check = function (bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException($label);
    echo "OK: $label\n";
};
try {
    foreach (['users', 'discussions', 'posts', 'groups'] as $table) {
        $schema->create($table, fn (\Illuminate\Database\Schema\Blueprint $t) => $t->increments('id'));
        $connection->table($table)->insert(['id' => 1]);
    }
    $connection->table('groups')->insert(['id' => \Flarum\Group\Group::MODERATOR_ID]);
    $schema->create('group_permission', function (\Illuminate\Database\Schema\Blueprint $t): void {
        $t->unsignedInteger('group_id'); $t->string('permission'); $t->unique(['group_id', 'permission']);
    });
    \Maxcuso\Roleplay\Events\EventSchema::up($schema);
    $check($schema->hasColumn('quest_events', 'start_failed_at') && $schema->hasTable('quest_event_participants'), 'Fresh installation creates complete event schema');
    $check($connection->table('group_permission')->count() === 2, 'Fresh installation grants the original moderator defaults');
    $connection->table('quest_events')->insert([
        'id' => 31, 'title' => 'Existing event', 'description' => 'Keep this content',
        'min_participants' => 1, 'max_participants' => 4, 'start_delay_hours' => 12,
        'leader_id' => 1, 'source_post_id' => 1, 'discussion_id' => 1,
    ]);
    $connection->table('quest_event_participants')->insert(['event_id' => 31, 'user_id' => 1]);
    $connection->table('group_permission')->where('permission', 'quest-events.manage')->delete();
    $before = json_encode([$connection->table('quest_events')->get(), $connection->table('quest_event_participants')->get(), $connection->table('group_permission')->get()]);
    \Maxcuso\Roleplay\Events\EventSchema::up($schema);
    $check($before === json_encode([$connection->table('quest_events')->get(), $connection->table('quest_event_participants')->get(), $connection->table('group_permission')->get()]), 'Adoption and repeated migration preserve event IDs, participants, links and removed permissions');
    $schema->table('quest_events', fn (\Illuminate\Database\Schema\Blueprint $t) => $t->dropColumn('start_failed_at'));
    \Maxcuso\Roleplay\Events\EventSchema::up($schema);
    $check($schema->hasColumn('quest_events', 'start_failed_at') && $connection->table('quest_events')->where('id', 31)->exists(), 'Partial legacy schema gains missing columns without replacing data');
} finally {
    foreach ($tables as $table) $schema->dropIfExists($table);
}
echo "Event schema checks passed; temporary tables removed.\n";
