<?php

declare(strict_types=1);

use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Schema\Blueprint;
use Maxcuso\Roleplay\Character\Profile\CharacterNameSearch;
use Maxcuso\Roleplay\Checks\CheckRead;
use Maxcuso\Roleplay\Episodes\CompletionRead;
use Maxcuso\Roleplay\Notification\CharacterNotifications;
use Maxcuso\Roleplay\Notification\SendCharacterNotification;
use Maxcuso\Roleplay\Presentation\CharacterAvatarUrl;

if (PHP_SAPI !== 'cli' || getenv('ROLEPLAY_TEST_COPY') !== '1') throw new RuntimeException('Local CLI only');
$container = (require dirname(__DIR__, 3).'/site.php')->bootApp()->getContainer();
set_exception_handler(function (Throwable $error): void { fwrite(STDERR, (string) $error.PHP_EOL); exit(1); });
function review111(bool $ok, string $label): void
{
    if (!$ok) throw new RuntimeException($label);
    echo 'OK: '.$label.PHP_EOL;
}
$original = $container->make(ConnectionInterface::class);
$avatars = $container->make(CharacterAvatarUrl::class);
$prefix = 'r111_'.bin2hex(random_bytes(3)).'_';
$mysql = new ($original::class)($original->getPdo(), $original->getDatabaseName(), $prefix, $original->getConfig());
$sqlite = new Illuminate\Database\SQLiteConnection(new PDO('sqlite::memory:'), ':memory:', 'r111_', ['driver' => 'sqlite']);
$connections = ['MariaDB/MySQL' => $mysql, 'SQLite' => $sqlite];
try {
    foreach ($connections as $label => $db) {
        $db->setTransactionManager(new DatabaseTransactionsManager());
        $schema = $db->getSchemaBuilder();
        $schema->create('roleplay_catalog_state', function (Blueprint $table): void {
            $table->integer('id')->primary(); $table->integer('revision');
        });
        $db->table('roleplay_catalog_state')->insert(['id' => 1, 'revision' => 1]);
        $schema->create('roleplay_characters', function (Blueprint $table): void {
            $table->increments('id'); $table->integer('user_id');
            $table->string('first_name'); $table->string('last_name')->nullable();
            $table->string('avatar')->nullable(); $table->string('status');
            $table->boolean('literature_approved'); $table->string('mechanics_status');
        });
        $names = [['Rian', 'Silver'], ['Anna', 'Silver'], ['Percent%Name', 'Underscore_Name'],
            ['Exclaim!Name', 'Slash\\Name'], ['Rian', null], ['Rian', 'Hidden']];
        foreach ($names as $i => [$first, $last]) {
            $db->table('roleplay_characters')->insert(['id' => $i + 1, 'user_id' => 100,
                'first_name' => $first, 'last_name' => $last, 'avatar' => null,
                'status' => 'active', 'literature_approved' => $i !== 5, 'mechanics_status' => 'approved']);
        }
        $reader = new CheckRead($db, $avatars);
        $rewards = new CompletionRead($db, $avatars);
        $actor = new User();
        foreach (['Rian' => [1, 5], 'Silver' => [1, 2], 'Rian Silver' => [1], 'Silver Rian' => [1],
            '%' => [3], '_' => [3], '!' => [4], '\\' => [4], 'Absent' => []] as $search => $expected) {
            $targets = $reader->targets($actor, $search, 1);
            $recipients = $rewards->search('characters', $search, 1, $actor);
            review111(array_column($targets['rows'], 'id') === $expected && $targets['total'] === count($expected), $label.' check targets: '.json_encode($search));
            review111(array_column($recipients['rows'], 'id') === $expected && $recipients['total'] === count($expected), $label.' reward recipients: '.json_encode($search));
        }
    }
    $postgres = new Illuminate\Database\PostgresConnection(new PDO('sqlite::memory:'), 'pretend', '', ['driver' => 'pgsql']);
    $query = $postgres->table('roleplay_characters');
    CharacterNameSearch::apply($query, 'Rian Silver');
    review111(!str_contains(strtoupper($query->toSql()), 'CONCAT') && count($query->getBindings()) === 4,
        'PostgreSQL uses standard bound LIKE predicates without concatenation functions');

    $queue = new class extends Illuminate\Queue\SyncQueue {
        public array $jobs = [];
        public bool $failPush = false;
        public function push($job, $data = '', $queue = null) {
            if ($this->failPush) throw new RuntimeException('Expected queue failure');
            $this->jobs[] = $job;
            return 'test-job';
        }
    };
    $logger = new class extends Psr\Log\AbstractLogger {
        public array $entries = [];
        public function log($level, $message, array $context = []): void { $this->entries[] = [$level, $context]; }
    };
    $notifications = new CharacterNotifications($queue, $logger);
    $character = ['id' => 1, 'user_id' => 123, 'first_name' => 'Rian', 'last_name' => 'Silver'];
    $details = ['action' => 'reward', 'experience' => 10, 'gold' => 0];
    $sqlite->beginTransaction();
    $notifications->afterEvent($sqlite, $character, 'character_managed', $details, 1, 456);
    review111(count($queue->jobs) === 0, 'No delivery before commit');
    $sqlite->rollBack();
    review111(count($queue->jobs) === 0, 'Rollback discards notification callback');
    $sqlite->beginTransaction(); $sqlite->beginTransaction();
    $notifications->afterEvent($sqlite, $character, 'character_managed', $details, 2, 456);
    $sqlite->commit();
    review111(count($queue->jobs) === 0, 'Nested commit waits for the outer transaction');
    $sqlite->commit();
    review111(count($queue->jobs) === 1 && $queue->jobs[0] instanceof SendCharacterNotification, 'Outer commit enqueues exactly one job');
    review111(unserialize(serialize($queue->jobs[0])) instanceof SendCharacterNotification, 'Queued job remains serializable without service dependencies');
    $queue->failPush = true;
    $sqlite->beginTransaction();
    $notifications->afterEvent($sqlite, $character, 'character_managed', $details, 3, 456);
    $sqlite->commit();
    review111(count($logger->entries) === 1 && $sqlite->transactionLevel() === 0, 'Queue failure is logged without undoing or failing the committed operation');
    $nativeQueue = new Illuminate\Queue\SyncQueue();
    $nativeQueue->setContainer($container);
    $nativeQueue->push(unserialize(serialize(new SendCharacterNotification(0, 0, ['characterId' => 0, 'eventId' => 0, 'kind' => 'reward']))));
    review111(true, 'Native queue resolves handle dependencies and safely skips a missing recipient');

    $filename = 'avatar-1-'.str_repeat('a', 32).'.webp';
    review111(str_ends_with($avatars->url($filename), '/assets/roleplay/avatars/'.$filename), 'Injected URL generator resolves stored avatars');
    foreach ([null, '', '../'.$filename, 'https://example.com/'.$filename] as $invalid) {
        review111($avatars->url($invalid) === null, 'Avatar URL rejects empty or external paths');
    }
    foreach ([CharacterNotifications::class, CheckRead::class, CompletionRead::class,
        Maxcuso\Roleplay\Api\CheckController::class, Maxcuso\Roleplay\Api\EpisodeCompletionController::class,
        Maxcuso\Roleplay\Episodes\RenderCompletion::class, Maxcuso\Roleplay\Character\Moderation\CharacterReview::class,
        Maxcuso\Roleplay\Character\Application\LiteratureSynchronizer::class] as $class) {
        review111($container->make($class) instanceof $class, 'Flarum container resolves '.$class);
    }
    echo "v1.1.1 regression checks passed\n";
} finally {
    if (!preg_match('/^r111_[0-9a-f]{6}_$/D', $mysql->getTablePrefix())) throw new RuntimeException('Unsafe cleanup prefix');
    $mysql->getSchemaBuilder()->dropIfExists('roleplay_characters');
    $mysql->getSchemaBuilder()->dropIfExists('roleplay_catalog_state');
}
