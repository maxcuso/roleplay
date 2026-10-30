<?php
declare(strict_types=1);

use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Maxcuso\Roleplay\Api\ApiProblem;
use Maxcuso\Roleplay\Catalog\Shared\CatalogRegistry;
use Maxcuso\Roleplay\Character\Application\ApplicationTopics;
use Maxcuso\Roleplay\Character\Application\LiteratureSynchronizer;
use Maxcuso\Roleplay\Character\Moderation\CharacterReview;

// Только временные таблицы и объекты пользователей в памяти. Темы и аккаунты не создаём.
$site = require __DIR__.'/../../../site.php';
$container = $site->bootApp()->getContainer();
set_exception_handler(function (Throwable $error): void {fwrite(STDERR, (string) $error."\n"); exit(1);});
$original = $container->make(ConnectionInterface::class);
$prefix = 'rpn_'.bin2hex(random_bytes(3)).'_';
if (!preg_match('/^rpn_[0-9a-f]{6}_$/D', $prefix) || $prefix === $original->getTablePrefix()) throw new RuntimeException('Unsafe prefix');
$db = new ($original::class)($original->getPdo(), $original->getDatabaseName(), $prefix, $original->getConfig());
$base = require __DIR__.'/fixtures/migrations-2026-10-30/2026_10_02_000001_create_base_catalogs.php';
$ranks = require __DIR__.'/fixtures/migrations-2026-10-30/2026_10_09_000001_add_ranks.php';
$assert = function (bool $ok, string $message): void {if (!$ok) throw new RuntimeException($message);};
$reject = function (callable $action, string $code) use ($assert): void {
    try {$action();} catch (ApiProblem $error) {$assert($error->problemCode === $code, 'Wrong rejection'); return;}
    throw new RuntimeException('Missing rejection: '.$code);
};
$topics = new class implements ApplicationTopics {
    public bool $available = true;
    public bool $accepted = false;
    public function create(User $actor, int $tagId, string $title, string $content): int {throw new RuntimeException('Not needed');}
    public function inspect(User $actor, int $discussionId): array {
        return ['available' => $this->available, 'accepted' => $this->accepted, 'hash' => 'topic-hash', 'acceptance_user_id' => 1];
    }
};
$owner = new class extends User {
    public function can(string $ability, mixed $arguments = []): bool {return in_array($ability, ['viewForum', 'roleplay.view'], true);}
    public function isAdmin(): bool {return false;}
};
$owner->id = 100; $owner->exists = true;
$admin = User::findOrFail(1);
$base['up']($db->getSchemaBuilder());
(require __DIR__.'/fixtures/migrations-2026-10-30/2026_10_17_000001_race_presentation.php')['up']($db->getSchemaBuilder());
(require __DIR__.'/fixtures/migrations-2026-10-30/2026_10_22_000002_race_quote_image.php')['up']($db->getSchemaBuilder());
(require __DIR__.'/fixtures/migrations-2026-10-30/2026_10_30_000003_literature_contents.php')['up']($db->getSchemaBuilder());
$ranks['up']($db->getSchemaBuilder());
(require __DIR__.'/fixtures/migrations-2026-10-30/2026_10_10_000002_roleplayer_statistics.php')['up']($db->getSchemaBuilder());
try {
    $race = (new CatalogRegistry($db))->get('races')->save(null, 1, ['name' => 'Проверка'], $admin)['id'];
    $now = gmdate('Y-m-d H:i:s');
    $id = $db->table('roleplay_characters')->insertGetId(['user_id' => 100, 'race_id' => $race,
        'first_name' => 'Проверка', 'discussion_id' => 123, 'mechanics_status' => 'review', 'literature_status' => 'draft',
        'created_at' => $now, 'updated_at' => $now]);
    $row = fn () => (array) $db->table('roleplay_characters')->find($id);
    $service = new CharacterReview($db, $topics);
    $sync = new LiteratureSynchronizer($db, $topics);
    $command = fn (string $action = 'submit') => ['operation_key' => \Ramsey\Uuid\Uuid::uuid4()->toString(),
        'revision' => (int) $row()['revision'], 'catalog_revision' => 2, 'part' => 'literature', 'action' => $action];
    $assert($service->present($row(), $owner)['permissions']['can_submit_literature'], 'Owner can submit draft');
    $sync->synchronize(123, $owner);
    $assert($row()['literature_status'] === 'draft' && (int) $row()['revision'] === 1, 'Editing topic does not submit draft');
    $reject(fn () => $service->change($id, $admin, $command()), 'review_forbidden');
    $reject(fn () => $service->change($id, $owner, $command('approve')), 'literature_topic_only');
    $topics->available = false;
    $assert(!$service->present($row(), $owner)['permissions']['can_submit_literature'], 'Missing topic cannot be submitted');
    $reject(fn () => $service->change($id, $owner, $command()), 'application_topic_missing');
    $topics->available = true;
    $submit = $command();
    $result = $service->change($id, $owner, $submit);
    $assert($row()['literature_status'] === 'review' && $row()['mechanics_status'] === 'review' && !$row()['literature_approved'], 'Submit changes only literary queue');
    $assert($service->change($id, $owner, $submit) === $result && $db->table('roleplay_character_events')->count() === 1, 'Exact retry does not duplicate submission');
    $reject(fn () => $service->change($id, $owner, $command()), 'review_state_changed');
    $assert($service->present($row(), $owner)['permissions']['can_withdraw_literature'], 'Owner can withdraw pending literature');
    $reject(fn () => $service->change($id, $admin, $command('withdraw')), 'review_forbidden');
    $withdraw = $command('withdraw');
    $withdrawResult = $service->change($id, $owner, $withdraw);
    $assert($row()['literature_status'] === 'draft' && $row()['mechanics_status'] === 'review', 'Withdrawal preserves technical review');
    $assert($service->change($id, $owner, $withdraw) === $withdrawResult && $db->table('roleplay_character_events')->count() === 2, 'Withdrawal exact retry is recorded once');
    $reject(fn () => $service->change($id, $owner, $command('withdraw')), 'review_state_changed');
    $sync->synchronize(123, $owner);
    $assert($row()['literature_status'] === 'draft', 'Topic observation preserves withdrawn draft');
    $service->change($id, $owner, $command());
    $topics->accepted = true;
    $assert(!$service->present($row(), $owner)['permissions']['can_withdraw_literature'], 'Observed moderator acceptance prevents withdrawal');
    $reject(fn () => $service->change($id, $owner, $command('withdraw')), 'review_state_changed');
    $sync->synchronize(123, $admin);
    $assert($row()['literature_status'] === 'approved' && $row()['status'] === 'creating', 'Topic acceptance does not approve mechanics');
    $reject(fn () => $service->change($id, $owner, $command('withdraw')), 'review_state_changed');
    $db->table('roleplay_characters')->where('id', $id)->update(['mechanics_status' => 'approved']);
    $sync->synchronize(123, $admin);
    $assert($row()['status'] === 'active', 'Both approvals still activate character');
    echo "Literature submission passed: draft preservation, owner submit/withdraw, exact retry and acceptance protection.\n";
} finally {
    $db->getSchemaBuilder()->dropIfExists('roleplay_player_statistics');
    $ranks['down']($db->getSchemaBuilder()); $base['down']($db->getSchemaBuilder());
}
