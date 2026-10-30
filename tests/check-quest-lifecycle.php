<?php

declare(strict_types=1);

use Flarum\Api\Client;
use Flarum\Api\JsonApi;
use Flarum\Api\Resource\DiscussionResource;
use Flarum\Discussion\Discussion;
use Flarum\Http\RequestUtil;
use Flarum\Post\CommentPost;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\ServerRequest;
use Maxcuso\Roleplay\Api\WorkspaceEpisodesController;
use Maxcuso\Roleplay\Events\Api\EventData;
use Maxcuso\Roleplay\Events\EventEditor;
use Maxcuso\Roleplay\Events\EventRegistration;
use Maxcuso\Roleplay\Events\EventStarter;
use Maxcuso\Roleplay\Events\QuestEvent;
use Maxcuso\Roleplay\Events\QuestSettings;

// Полный путь проходит только на тестовой копии, внутри одной откатываемой транзакции.
// Рабочая очередь запуска не вызывается. Даты меняются лишь у собственного события.
$root = dirname(__DIR__, 3);
if (getenv('ROLEPLAY_TEST_COPY') !== '1' || !is_file($root.'/ROLEPLAY_TEST_INSTALLED')) {
    throw new RuntimeException('Run only in the installed Roleplay test copy');
}
$container = (require $root.'/site.php')->bootApp()->getContainer();
set_exception_handler(function (Throwable $error): void {
    fwrite(STDERR, get_class($error).': '.$error->getMessage().PHP_EOL.$error->getTraceAsString().PHP_EOL);
    exit(1);
});
$db = $container->make(ConnectionInterface::class);
$actor = User::findOrFail(1);
$client = $container->make(Client::class);
$settings = $container->make(SettingsRepositoryInterface::class);
$originalSetting = $settings->get(QuestSettings::ENABLED);
$starter = $container->make(EventStarter::class);
$registration = $container->make(EventRegistration::class);

function lifecycleCheck(bool $value, string $label): void
{
    if (!$value) throw new RuntimeException($label);
    echo 'OK: '.$label.PHP_EOL;
}

$before = [
    'events' => $db->table('quest_events')->count(),
    'discussions' => $db->table('discussions')->count(),
    'posts' => $db->table('posts')->count(),
    'characters' => $db->table('roleplay_characters')->count(),
    'operations' => $db->table('roleplay_operations')->count(),
];
$db->beginTransaction();
try {
    $settings->set(QuestSettings::ENABLED, '1');
    // Изолируем лимит участия администратора. Исходные записи восстановятся при откате.
    $db->table('roleplay_quest_participants')->where('user_id', $actor->id)->delete();
    $db->table('quest_event_participants')->where('user_id', $actor->id)->delete();
    $race = $db->table('roleplay_races')->orderBy('id')->value('id');
    lifecycleCheck($race !== null, 'A race is available for temporary participants');
    $characters = [];
    foreach (['First lifecycle character', 'Selected lifecycle character'] as $name) {
        $characters[] = (int) $db->table('roleplay_characters')->insertGetId([
            'user_id' => $actor->id, 'race_id' => $race, 'first_name' => $name,
            'status' => 'active', 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }
    $source = $container->make(JsonApi::class)->forResource(DiscussionResource::class)->forEndpoint('create')->process([
        'data' => ['attributes' => [
            'title' => '[TEST] Quest lifecycle source',
            'content' => '[quest title="Lifecycle episode" min=1 max=3 delay=12 type=quest difficulty=2 xp=15 gold=10]A temporary quest description[/quest]',
        ]],
    ], [], ['actor' => $actor]);
    $event = QuestEvent::where('source_post_id', $source->first_post_id)->firstOrFail();
    lifecycleCheck($event->event_type === 'quest' && $event->status === 'recruiting'
        && (int) $event->leader_id === (int) $actor->id && $event->discussion_id === null,
        'Publishing the full quest code creates a recruiting card with one leader');
    lifecycleCheck(CommentPost::findOrFail($source->first_post_id)->content === '[quest='.$event->id.']',
        'The published source stores a reusable card reference');
    $joined = $client->withActor($actor)->withBody(['characterId' => $characters[1]])
        ->send('POST', '/quest-events/'.$event->id.'/participants');
    lifecycleCheck($joined->getStatusCode() === 201, 'The original registration API accepts the chosen character');
    $event->refresh();
    lifecycleCheck($event->scheduled_start_at !== null && $event->discussion_id === null,
        'Reaching the minimum schedules a start without prematurely publishing a topic');
    $binding = $db->table('roleplay_quest_participants')->where('event_id', $event->id)->first();
    lifecycleCheck((int) $binding->character_id === $characters[1], 'Registration binds the selected character, not the first available');

    $event->scheduled_start_at = new DateTimeImmutable('-1 minute');
    $event->save();
    $starter->startIfDue((int) $event->id, $actor, EventEditor::revision($event));
    $event->refresh();
    $episode = Discussion::findOrFail($event->discussion_id);
    $firstPost = CommentPost::findOrFail($episode->first_post_id);
    lifecycleCheck($event->status === 'active' && $event->scheduled_start_at === null,
        'Starting this fixture creates an active linked episode and clears its schedule');
    lifecycleCheck($episode->title === 'Lifecycle episode' && (int) $firstPost->user_id === (int) $event->leader_id
        && str_contains($firstPost->content, 'A temporary quest description')
        && str_contains($firstPost->parsed_content, 'USERMENTION'),
        'The episode uses the title, description, leader authorship and participant mention');
    $card = $container->make(EventData::class)->serialize($event->fresh()->load(['leader', 'participants'])->loadCount('participants'), $actor);
    lifecycleCheck($card['discussionId'] === (int) $episode->id && !$card['registrationOpen'] && !$card['canLeave']
        && $card['participants'][0]['character']['id'] === $characters[1],
        'The active card links the episode and preserves the chosen character');
    $request = RequestUtil::withActor((new ServerRequest())->withQueryParams(['type' => 'quest']), $actor);
    $workspace = json_decode((string) $container->make(WorkspaceEpisodesController::class)->handle($request)->getBody(), true, flags: JSON_THROW_ON_ERROR);
    $row = array_values(array_filter($workspace['data']['rows'], fn ($row) => $row['id'] === (int) $event->id))[0] ?? null;
    lifecycleCheck($row !== null && $row['discussionId'] === (int) $episode->id
        && $row['participants'][0]['character']['id'] === $characters[1],
        'The leader sees this episode and its bound character in My episodes');
    $topicCount = Discussion::count();
    $starter->startIfDue((int) $event->id);
    lifecycleCheck(Discussion::count() === $topicCount && (int) $event->fresh()->discussion_id === (int) $episode->id,
        'Repeating the start creates no duplicate episode');
    $balances = json_encode($db->table('roleplay_character_balances')->orderBy('character_id')->orderBy('resource_key')->get());
    $operations = $db->table('roleplay_operations')->count();
    $closed = $client->withActor($actor)->withBody([
        'data' => ['type' => 'discussions', 'id' => (string) $episode->id, 'attributes' => ['isLocked' => true]],
    ])->send('PATCH', '/discussions/'.$episode->id);
    lifecycleCheck($closed->getStatusCode() === 200 && $event->fresh()->status === 'completed',
        'Native topic locking completes the linked quest');
    lifecycleCheck($balances === json_encode($db->table('roleplay_character_balances')->orderBy('character_id')->orderBy('resource_key')->get())
        && $operations === $db->table('roleplay_operations')->count(),
        'Advertised XP and gold are not awarded by a normal start or manual closure');
    $archiveRequest = $request->withQueryParams(['type' => 'quest', 'status' => 'completed']);
    $archive = json_decode((string) $container->make(WorkspaceEpisodesController::class)->handle($archiveRequest)->getBody(), true, flags: JSON_THROW_ON_ERROR);
    lifecycleCheck(in_array((int) $event->id, array_column($archive['data']['rows'], 'id'), true),
        'The completed quest moves into the leader archive');
} finally {
    $originalSetting === null ? $settings->delete(QuestSettings::ENABLED) : $settings->set(QuestSettings::ENABLED, $originalSetting);
    $db->rollBack();
}
foreach ($before as $name => $count) {
    $table = ['events' => 'quest_events', 'characters' => 'roleplay_characters', 'operations' => 'roleplay_operations'][$name] ?? $name;
    lifecycleCheck($db->table($table)->count() === $count, 'Rollback restores the original '.$name.' count');
}
echo 'Quest lifecycle checks passed; all fixtures rolled back.'.PHP_EOL;
