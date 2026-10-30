<?php

declare(strict_types=1);

use Flarum\Api\Client;
use Flarum\Api\JsonApi;
use Flarum\Api\Resource\DiscussionResource;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Maxcuso\Roleplay\Events\QuestEvent;

$root = dirname(__DIR__, 3);
if (getenv('ROLEPLAY_TEST_COPY') !== '1' || !is_file($root.'/ROLEPLAY_TEST_INSTALLED')) throw new RuntimeException('Test copy only');
$c = (require $root.'/site.php')->bootApp()->getContainer();
// Только в процессе этого теста: проверяем права, а не паузы между запросами.
$c->extend('flarum.api.throttlers', fn (array $rules) => ['permissionTest' => static fn () => false] + $rules);
set_exception_handler(function (Throwable $e): void {fwrite(STDERR, get_class($e).': '.$e->getMessage().PHP_EOL.$e->getTraceAsString().PHP_EOL); exit(1);});
$db = $c->make(ConnectionInterface::class); $api = $c->make(Client::class);
$member = User::findOrFail(2); $admin = User::findOrFail(1);
function forumCheck(bool $ok, string $message): void {if (!$ok) throw new RuntimeException($message); echo 'OK: '.$message.PHP_EOL;}
function forumJson($response): array {return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);}
$tables = ['quest_events', 'discussions', 'posts'];
$before = []; foreach ($tables as $table) $before[$table] = $db->table($table)->count();
$db->beginTransaction();
try {
    forumCheck($member->can('quest-events.createForum') && !$member->can('quest-events.create') && !$member->can('quest-events.manage'),
        'Member can create ordinary events without roleplay quest or management permission');
    $forum = forumJson($api->withActor($member)->send('GET', '/'));
    $flags = $forum['data']['attributes'] ?? [];
    forumCheck(($flags['canCreateForumEvents'] ?? false) && !($flags['canCreateQuestEvents'] ?? true)
        && !($flags['canManageQuestEvents'] ?? true), 'Forum payload separates ordinary event creation from moderator actions');
    $body = ['title' => 'Временная встреча', 'description' => 'Проверка права создания', 'eventType' => 'forum',
        'minParticipants' => 1, 'maxParticipants' => 3, 'startDelayHours' => 12];
    $create = fn ($input) => $api->withActor($member)->withBody($input)->send('POST', '/quest-events');
    $created = $create($body);
    forumCheck($created->getStatusCode() === 201, 'Ordinary member can create a forum event through the API');
    $quest = $body; $quest['eventType'] = 'quest'; $quest['difficulty'] = 1;
    forumCheck($create($quest)->getStatusCode() === 403, 'Changing API type to quest does not bypass its existing permission');
    $reward = $body; $reward['reward'] = ['experience' => 10];
    forumCheck($create($reward)->getStatusCode() === 422, 'Ordinary forum events cannot advertise roleplay rewards');
    forumCheck($api->withActor($member)->send('GET', '/quest-event-drafts')->getStatusCode() === 200,
        'An ordinary event creator can load their own eligible drafts');
    $topic = $c->make(JsonApi::class)->forResource(DiscussionResource::class)->forEndpoint('create')->process([
        'data' => ['attributes' => ['title' => '[TEST] Forum event permissions', 'content' => 'Temporary event publication test']],
    ], [], ['actor' => $admin]);
    $publish = function ($content) use ($api, $member, $topic) {
        return $api->withActor(User::findOrFail($member->id))->withBody(['data' => ['type' => 'posts', 'attributes' => ['content' => $content],
            'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => (string) $topic->id]]]]])->send('POST', '/posts');
    };
    $code = '[quest title="Встреча участников" min=1 max=3 delay=12 type=forum color=#4387ac]Временное событие[/quest]';
    $published = $publish($code);
    forumCheck($published->getStatusCode() === 201, 'An ordinary member can publish the event BB-code');
    $post = (int) forumJson($published)['data']['id'];
    forumCheck(QuestEvent::where('source_post_id', $post)->where('event_type', 'forum')->exists(), 'Publication creates a linked forum event');
    $count = $db->table('quest_events')->count(); $posts = $db->table('posts')->count();
    $questCode = '[quest title="Запрещённый квест" min=1 max=3 delay=12 type=quest difficulty=1]Нельзя[/quest]';
    $forbidden = $publish($questCode);
    forumCheck($forbidden->getStatusCode() === 403 && $db->table('quest_events')->count() === $count
        && $db->table('posts')->count() === $posts, 'Publishing a forged quest is denied without saving the post (status '.$forbidden->getStatusCode().')');
    $mixed = $publish($code."\n".$questCode);
    forumCheck($mixed->getStatusCode() === 403 && $db->table('quest_events')->count() === $count
        && $db->table('posts')->count() === $posts, 'A mixed forum/quest post rolls back both instead of partially creating an event');
} finally {$db->rollBack();}
foreach ($before as $table => $count) forumCheck($db->table($table)->count() === $count, 'Rollback restores '.$table);
echo 'Forum event creation passed; all fixtures rolled back.'.PHP_EOL;
