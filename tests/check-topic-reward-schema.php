<?php
declare(strict_types=1);

if (getenv('ROLEPLAY_TEST_COPY') !== '1' || !is_file(dirname(__DIR__, 3).'/ROLEPLAY_TEST_INSTALLED')) throw new RuntimeException('Test copy only');
$c = (require dirname(__DIR__, 3).'/site.php')->bootApp()->getContainer();
set_exception_handler(function (Throwable $e): void {fwrite(STDERR, $e->getMessage().PHP_EOL.$e->getTraceAsString().PHP_EOL); exit(1);});
$db = $c->make(\Illuminate\Database\ConnectionInterface::class);
$connection = new ($db::class)($db->getPdo(), $db->getDatabaseName(), 'rpr_'.bin2hex(random_bytes(3)).'_', $db->getConfig());
$schema = $connection->getSchemaBuilder();
$original = require __DIR__.'/fixtures/migrations-2026-10-30/2026_10_29_000001_episode_completion.php';
$upgrade = require __DIR__.'/fixtures/migrations-2026-10-30/2026_10_29_000003_topic_rewards.php';
$check = function (bool $ok, string $label): void {if (!$ok) throw new RuntimeException($label); echo 'OK: '.$label.PHP_EOL;};
try {
    $original['up']($schema);
    $row = ['event_id' => 42, 'plan_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'post_id' => 3, 'actor_user_id' => 1, 'result' => '{"recipients":[]}', 'created_at' => gmdate('Y-m-d H:i:s')];
    $connection->table('roleplay_episode_completions')->insert($row);
    $upgrade['up']($schema);
    $check($connection->table('roleplay_episode_completions')->where('event_id', 42)->value('result') === $row['result'], 'Migration preserves an existing quest receipt');
    foreach (['bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'cccccccc-cccc-4ccc-8ccc-cccccccccccc'] as $id) {
        $connection->table('roleplay_episode_completions')->insert(array_replace($row, ['event_id' => null, 'plan_id' => $id]));
    }
    $check($connection->table('roleplay_episode_completions')->whereNull('event_id')->count() === 2, 'Independent ordinary receipts coexist');
    try {
        $connection->table('roleplay_episode_completions')->insert(array_replace($row, ['plan_id' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd']));
        throw new RuntimeException('Duplicate quest completion accepted');
    } catch (\Illuminate\Database\QueryException) {$check(true, 'A quest still has only one completion');}
    try {$upgrade['down']($schema); throw new LogicException('Unsafe downgrade accepted');}
    catch (RuntimeException $e) {$check($e->getMessage() === 'Cannot downgrade while topic reward records exist', 'Downgrade refuses to discard ordinary rewards');}
    $connection->table('roleplay_episode_completions')->whereNull('event_id')->delete();
    $upgrade['down']($schema);
    $check($connection->table('roleplay_episode_completions')->where('event_id', 42)->value('plan_id') === $row['plan_id'], 'Safe downgrade preserves the original quest receipt');
} finally {$original['down']($schema);}
echo 'Topic reward schema checks passed; temporary tables removed.'.PHP_EOL;
