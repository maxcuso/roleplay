<?php

declare(strict_types=1);

// Временная тема и отдельный персонаж только для визуальной проверки локальной копии.
// Запуск: create → проверка в браузере → publish → cleanup.
use Flarum\Api\Client;
use Flarum\Api\JsonApi;
use Flarum\Api\Resource\DiscussionResource;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Maxcuso\Roleplay\Events\QuestEvent;

$root = dirname(__DIR__, 3);
if (getenv('ROLEPLAY_TEST_COPY') !== '1' || !is_file($root.'/ROLEPLAY_TEST_INSTALLED')) throw new RuntimeException('Test copy only');
$c = (require $root.'/site.php')->bootApp()->getContainer();
set_exception_handler(function (Throwable $e): void {fwrite(STDERR, get_class($e).': '.$e->getMessage().PHP_EOL); exit(1);});
$db = $c->make(ConnectionInterface::class); $api = $c->make(Client::class); $actor = User::findOrFail(1);
$file = __DIR__.'/.runtime/episode-completion-ui.json';
$action = $argv[1] ?? '';
if ($action === 'create') {
    if (is_file($file)) throw new RuntimeException('Clean up the previous fixture first');
    $state = $db->transaction(function () use ($c, $db, $actor): array {
        $row = (array) $db->table('roleplay_characters')->orderBy('id')->first();
        $original = $row['id']; unset($row['id']);
        $row['first_name'] = 'Эллиан'; $row['last_name'] = 'Тестовый'; $row['user_id'] = $actor->id;
        $row['status'] = 'active'; $row['mechanics_status'] = 'approved'; $row['literature_status'] = 'approved';
        $row['literature_approved'] = true; $row['discussion_id'] = null; $row['revision'] = 1;
        $character = (int) $db->table('roleplay_characters')->insertGetId($row);
        foreach ($db->table('roleplay_character_attributes')->where('character_id', $original)->get() as $attribute) {
            $fields = (array) $attribute; unset($fields['id']); $fields['character_id'] = $character;
            $db->table('roleplay_character_attributes')->insert($fields);
        }
        foreach (['experience_active', 'experience_total', 'gold'] as $key) $db->table('roleplay_character_balances')->insert([
            'character_id' => $character, 'resource_key' => $key, 'amount' => 100, 'updated_at' => gmdate('Y-m-d H:i:s')]);
        $create = fn ($title, $content) => $c->make(JsonApi::class)->forResource(DiscussionResource::class)->forEndpoint('create')
            ->process(['data' => ['attributes' => ['title' => $title, 'content' => $content]]], [], ['actor' => $actor]);
        $source = $create('[TEST] Карточка визуальной проверки', '[quest title="Переправа через туман" min=1 max=3 delay=12 type=quest difficulty=2]Временная проверка интерфейса[/quest]');
        $event = QuestEvent::where('source_post_id', $source->first_post_id)->firstOrFail();
        $topic = $create('[TEST] Переправа через туман', 'Временная тема для проверки завершения эпизода');
        $event->discussion_id = $topic->id; $event->status = 'active'; $event->save();
        $db->table('quest_event_participants')->insert(['event_id' => $event->id, 'user_id' => $actor->id]);
        $db->table('roleplay_quest_participants')->insert(['event_id' => $event->id, 'user_id' => $actor->id,
            'character_id' => $character, 'character_name' => 'Эллиан Тестовый', 'created_at' => gmdate('Y-m-d H:i:s')]);
        return ['source' => (int) $source->id, 'topic' => (int) $topic->id, 'event' => (int) $event->id, 'character' => $character,
            'statistics' => (array) $db->table('roleplay_player_statistics')->where('user_id', $actor->id)->first()];
    });
    file_put_contents($file, json_encode($state, JSON_THROW_ON_ERROR));
    echo 'http://localhost:8081/d/'.$state['topic'].PHP_EOL;
} elseif (in_array($action, ['publish', 'checks', 'cleanup'], true)) {
    $state = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    // Перед любой очисткой проверяются точные ID и подписи собственных тестовых данных.
    if ($db->table('roleplay_characters')->where('id', $state['character'])->value('last_name') !== 'Тестовый') throw new RuntimeException('Fixture mismatch');
    foreach (['source', 'topic'] as $key) {
        if (!str_starts_with((string) $db->table('discussions')->where('id', $state[$key])->value('title'), '[TEST]')) throw new RuntimeException('Topic mismatch');
    }
    if ($action === 'checks') {
        $skill = $db->table('roleplay_skills')->where('enabled', true)->orderBy('id')->first();
        $prepareCheck = function (string $type, array $payload) use ($api, $actor, $state): string {
            $response = $api->withActor(User::findOrFail($actor->id))->withBody(['operation_key' => (string) \Illuminate\Support\Str::uuid(),
                'discussion_id' => $state['topic'], 'type' => $type, 'payload' => $payload])->send('POST', '/roleplay/checks/plans');
            if ($response->getStatusCode() !== 200) throw new RuntimeException((string) $response->getBody());
            return json_decode((string) $response->getBody(), true)['data']['code'];
        };
        $publishCheck = function (string $code) use ($api, $actor, $state): void {
            $response = $api->withActor(User::findOrFail($actor->id))->withBody(['data' => ['type' => 'posts', 'attributes' => ['content' => $code],
                'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => (string) $state['topic']]]]]])->send('POST', '/posts');
            if ($response->getStatusCode() !== 201) throw new RuntimeException((string) $response->getBody());
        };
        $publishCheck($prepareCheck('request', ['kind' => 'skill', 'target_id' => (int) $skill->id, 'difficulty' => 10,
            'description' => 'Осмотрите следы у переправы', 'target' => 'all', 'characters' => []]));
        foreach ([[5, 20], [30, 0], [null, 0]] as [$difficulty, $modifier]) {
            $publishCheck($prepareCheck('roll', ['kind' => 'skill', 'target_id' => (int) $skill->id, 'difficulty' => $difficulty,
                'character_id' => $state['character'], 'request_id' => null, 'modifier' => $modifier, 'reason' => $modifier ? 'Визуальная проверка' : '']));
        }
        echo 'Temporary request and three roll outcomes published'.PHP_EOL;
    } elseif ($action === 'publish') {
        $plan = $db->table('roleplay_episode_plans')->where('discussion_id', $state['topic'])->orderByDesc('created_at')->first();
        if (!$plan) throw new RuntimeException('Insert the code from the browser first');
        $response = $api->withActor($actor)->withBody(['data' => ['type' => 'posts', 'attributes' => ['content' => '[episode-end='.$plan->id.']'],
            'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => (string) $state['topic']]]]]])->send('POST', '/posts');
        if ($response->getStatusCode() !== 201) throw new RuntimeException((string) $response->getBody());
        echo 'Published temporary receipt'.PHP_EOL;
    } else {
        $db->transaction(function () use ($db, $api, $actor, $state): void {
            foreach (['topic', 'source'] as $key) {
                $response = $api->withActor($actor)->send('DELETE', '/discussions/'.$state[$key]);
                if ($response->getStatusCode() !== 204) throw new RuntimeException((string) $response->getBody());
            }
            $id = $state['character'];
            foreach ($db->getSchemaBuilder()->getTables() as $table) {
                $name = $table['name'];
                if (str_starts_with($name, 'roleplay_') && $db->getSchemaBuilder()->hasColumn($name, 'character_id')) $db->table($name)->where('character_id', $id)->delete();
            }
            $db->table('roleplay_characters')->where('id', $id)->delete();
            $db->table('roleplay_episode_plans')->where('event_id', $state['event'])->delete();
            $db->table('roleplay_episode_completions')->where('event_id', $state['event'])->delete();
            $db->table('roleplay_checks')->whereIn('discussion_id', [$state['source'], $state['topic']])->delete();
            if ($state['statistics']) $db->table('roleplay_player_statistics')->where('user_id', $actor->id)->update($state['statistics']);
            else $db->table('roleplay_player_statistics')->where('user_id', $actor->id)->delete();
        });
        unlink($file);
        echo 'Temporary topics, character and rewards removed'.PHP_EOL;
    }
} elseif ($action === 'audit') {
    echo json_encode([
        'fixture_manifest' => is_file($file),
        'fixture_topics' => $db->table('discussions')->whereIn('title', ['[TEST] Карточка визуальной проверки', '[TEST] Переправа через туман'])->count(),
        'fixture_characters' => $db->table('roleplay_characters')->where('first_name', 'Эллиан')->where('last_name', 'Тестовый')->count(),
        'characters' => $db->table('roleplay_characters')->count(),
        'plans' => $db->table('roleplay_episode_plans')->count(),
        'completions' => $db->table('roleplay_episode_completions')->count(),
        'admin' => $db->table('users')->where('id', 1)->first(['comment_count', 'discussion_count']),
        'native_comment_count' => $actor->refreshCommentCount()->comment_count,
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
} else throw new RuntimeException('Choose create, publish, cleanup or audit');
