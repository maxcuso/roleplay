<?php
declare(strict_types=1);

use Flarum\Api\{Client, JsonApi};
use Flarum\Api\Resource\DiscussionResource;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Maxcuso\Roleplay\Events\QuestEvent;

$root = dirname(__DIR__, 3);
if (getenv('ROLEPLAY_TEST_COPY') !== '1' || !is_file($root.'/ROLEPLAY_TEST_INSTALLED')) throw new RuntimeException('Test copy only');
$c = (require $root.'/site.php')->bootApp()->getContainer();
set_exception_handler(function (Throwable $e): void {fwrite(STDERR, get_class($e).': '.$e->getMessage().PHP_EOL.$e->getTraceAsString().PHP_EOL); exit(1);});
$db = $c->make(ConnectionInterface::class); $api = $c->make(Client::class); $jsonApi = $c->make(JsonApi::class);
// Только тестовый процесс: частые API-запросы не проверяют скорость публикации обычного игрока.
$c->extend('flarum.api.throttlers', fn (array $rules) => ['checksTest' => static fn () => false] + $rules);
function checkAssert(bool $ok, string $label): void {if (!$ok) throw new RuntimeException($label); echo 'OK: '.$label.PHP_EOL;}
function checkJson($response): array {return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);}
$before = []; foreach (['posts', 'discussions', 'quest_events', 'roleplay_checks', 'roleplay_characters'] as $table) $before[$table] = $db->table($table)->count();
$send = function ($method, $url, $body = null, $actorId = 2) use ($api) {
    parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
    return $api->withActor(User::findOrFail($actorId))->withQueryParams($query)->withBody($body ?? [])->send($method, $url);
};
$create = fn ($title, $content) => $jsonApi->forResource(DiscussionResource::class)->forEndpoint('create')
    ->process(['data' => ['attributes' => ['title' => $title, 'content' => $content]]], [], ['actor' => User::findOrFail(1)]);
$publish = fn ($topic, $content, $actorId = 2) => $send('POST', '/posts', ['data' => ['type' => 'posts', 'attributes' => ['content' => $content],
    'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => (string) $topic->id]]]]], $actorId);
$db->beginTransaction();
try {
    checkAssert(\Maxcuso\Roleplay\Checks\CheckAccess::enabled(), 'Existing MagicDice is available without source changes');
    $original = (array) $db->table('roleplay_characters')->orderBy('id')->first();
    $skill = $db->table('roleplay_skills')->where('enabled', true)->orderBy('id')->first();
    $copy = function ($name, $owner) use ($db, $original, $skill): int {
        $row = $original; unset($row['id']); $row['first_name'] = $name; $row['last_name'] = 'Тест'; $row['user_id'] = $owner;
        $row['discussion_id'] = null; $row['status'] = 'active'; $row['mechanics_status'] = 'approved'; $row['literature_status'] = 'approved'; $row['literature_approved'] = true;
        $id = (int) $db->table('roleplay_characters')->insertGetId($row);
        foreach ($db->table('roleplay_character_attributes')->where('character_id', $original['id'])->get() as $r) {
            $a = (array) $r; unset($a['id']); $a['character_id'] = $id;
            if ((int) $a['attribute_id'] === (int) $skill->attribute_id) {$a['base_value'] = 7; $a['racial_bonus'] = 0;}
            $db->table('roleplay_character_attributes')->insert($a);
        }
        $db->table('roleplay_character_skills')->insert(['character_id' => $id, 'skill_id' => $skill->id, 'level' => 2, 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')]);
        foreach (['experience_active', 'experience_total', 'gold'] as $key) $db->table('roleplay_character_balances')->insert(['character_id' => $id, 'resource_key' => $key, 'amount' => 100, 'updated_at' => gmdate('Y-m-d H:i:s')]);
        return $id;
    };
    $one = $copy('Первый', 2); $other = $copy('Второй', 2); $foreign = $copy('Чужой', 1);
    $source = $create('[TEST] Check source', '[quest title="Проверки переправы" min=1 max=3 delay=12 type=quest difficulty=2]Temporary source[/quest]');
    $event = QuestEvent::where('source_post_id', $source->first_post_id)->firstOrFail();
    $topic = $create('[TEST] Check episode', 'Temporary checks'); $event->discussion_id = $topic->id; $event->status = 'active'; $event->save();
    $db->table('quest_event_participants')->insert(['event_id' => $event->id, 'user_id' => 2]);
    $db->table('roleplay_quest_participants')->insert(['event_id' => $event->id, 'user_id' => 2, 'character_id' => $one, 'character_name' => 'Первый Тест', 'created_at' => gmdate('Y-m-d H:i:s')]);
    $query = '?discussion_id='.$topic->id;
    $contextResponse = $send('GET', '/roleplay/checks/context'.$query); $contextJson = checkJson($contextResponse);
    checkAssert($contextResponse->getStatusCode() === 200, 'Context loads: '.json_encode($contextJson['errors'] ?? [], JSON_UNESCAPED_UNICODE));
    $context = $contextJson['data'];
    checkAssert($context['defaultCharacterId'] === $one && count($context['characters']) >= 2, 'Episode participation wins over other active characters: '.json_encode([$one, $context['discussionId'], $context['defaultCharacterId'], array_column($context['characters'], 'id')]));
    $requestPayload = ['kind' => 'skill', 'target_id' => (int) $skill->id, 'difficulty' => 10, 'description' => 'Осмотрите следы <script>alert(1)</script>', 'target' => 'all', 'characters' => []];
    $prepare = function ($type, $payload, $discussionId, $actor = 2, $key = null) use ($send): array {
        $id = $key ?? (string) Str::uuid();
        $response = $send('POST', '/roleplay/checks/plans', ['operation_key' => $id, 'discussion_id' => $discussionId, 'type' => $type, 'payload' => $payload], $actor);
        return [$response, checkJson($response), $id];
    };
    [$prepared, $data, $requestId] = $prepare('request', $requestPayload, (int) $topic->id);
    checkAssert($prepared->getStatusCode() === 200, 'Ordinary player can prepare a request: '.json_encode($data['errors'] ?? [], JSON_UNESCAPED_UNICODE));
    $requestCode = $data['data']['code'];
    checkAssert($db->table('roleplay_checks')->where('id', $requestId)->value('result') === null, 'Preparation creates no dice or published result');
    checkAssert($prepare('request', $requestPayload, (int) $topic->id, 2, $requestId)[0]->getStatusCode() === 200, 'Repeated preparation is idempotent');
    checkAssert($publish($topic, '[quote]'.$requestCode.'[/quote]')->getStatusCode() === 201 && $db->table('roleplay_checks')->where('id', $requestId)->value('post_id') === null, 'Quoted pending requests do not publish');
    $publishedRequest = $publish($topic, $requestCode); $publishedJson = checkJson($publishedRequest);
    checkAssert($publishedRequest->getStatusCode() === 201, 'Request publishes: '.json_encode($publishedJson['errors'] ?? [], JSON_UNESCAPED_UNICODE));
    $requestHtml = checkJson($send('GET', '/posts/'.$publishedJson['data']['id']))['data']['attributes']['contentHtml'];
    checkAssert(str_contains($requestHtml, '>Средне</span>') && str_contains($requestHtml, 'Сделать бросок') && !str_contains($requestHtml, '<script>'), 'Request uses textual difficulty, action button and escaped description');
    $linked = checkJson($send('GET', '/roleplay/checks/context'.$query.'&request_id='.$requestId))['data'];
    checkAssert(count($linked['characters']) === 1 && $linked['defaultCharacterId'] === $one && !isset($linked['request']['description']), 'Request selects the bound character without passing its description into the roll window');
    $roll = ['kind' => 'skill', 'target_id' => (int) $skill->id, 'difficulty' => 10, 'character_id' => $one, 'request_id' => $requestId, 'modifier' => 2, 'reason' => 'Хорошее освещение'];
    checkAssert($prepare('roll', array_replace($roll, ['reason' => '']), (int) $topic->id)[0]->getStatusCode() === 422, 'Additional modifiers require a reason');
    checkAssert($prepare('roll', array_replace($roll, ['character_id' => $foreign]), (int) $topic->id)[0]->getStatusCode() === 403, 'A player cannot roll another account character');
    checkAssert($prepare('roll', array_replace($roll, ['character_id' => $other]), (int) $topic->id)[0]->getStatusCode() === 403, 'All-participant request keeps the character bound to the quest');
    checkAssert($prepare('roll', $roll + ['dice' => [6, 6]], (int) $topic->id)[0]->getStatusCode() === 422, 'Clients cannot submit dice faces');
    [$rollResponse, $rollData, $rollId] = $prepare('roll', $roll, (int) $topic->id);
    checkAssert($rollResponse->getStatusCode() === 200, 'Roll prepares without determining dice: '.json_encode($rollData['errors'] ?? [], JSON_UNESCAPED_UNICODE));
    $code = $rollData['data']['code'];
    checkAssert($publish($topic, $code, 1)->getStatusCode() === 422, 'Another user cannot execute a copied pending roll');
    $db->table('roleplay_character_attributes')->where('character_id', $one)->where('attribute_id', $skill->attribute_id)->update(['base_value' => 8]);
    $published = $publish($topic, "1d20\n".$code."\n1d6"); $publishedData = checkJson($published);
    checkAssert($published->getStatusCode() === 201, 'Roll publishes with ordinary MagicDice formulas: '.json_encode($publishedData['errors'] ?? [], JSON_UNESCAPED_UNICODE));
    $postId = (int) $publishedData['data']['id'];
    $raw = $db->table('roleplay_checks')->where('id', $rollId)->value('result'); $result = json_decode($raw, true);
    checkAssert(count($result['dice']) === 2 && min($result['dice']) >= 1 && max($result['dice']) <= 6 && $result['bonus'] === 5
        && $result['total'] === array_sum($result['dice']) + 7, 'Publication snapshots current attributes, one skill-level bonus, modifier and two real d6');
    checkAssert($result['success'] === ($result['total'] >= 10), 'Success is compared with the chosen difficulty');
    $normalDice = explode(',', $db->table('posts')->where('id', $postId)->value('dice_rolls'));
    checkAssert(count($normalDice) === 2 && (int) $normalDice[0] <= 20 && (int) $normalDice[1] <= 6, 'Existing MagicDice formulas retain their own positions and saved results');
    $html = checkJson($send('GET', '/posts/'.$postId))['data']['attributes']['contentHtml'];
    checkAssert(str_contains($html, 'data-check-first') && !str_contains($html, '>Успех<') && !str_contains($html, '>Провал<'), 'Result includes native-renderer dice data and communicates outcome by color and icon');
    $send('GET', '/posts/'.$postId); $publish($topic, '[quote]'.$code.'[/quote]');
    $countBeforeCopy = $db->table('posts')->count();
    checkAssert($publish($topic, $code)->getStatusCode() === 422 && $db->table('posts')->count() === $countBeforeCopy,
        'A saved roll cannot be presented as a new attempt by copying its code');
    $edit = $send('PATCH', '/posts/'.$postId, ['data' => ['type' => 'posts', 'id' => (string) $postId, 'attributes' => ['content' => 'Edited '.$code]]], 1);
    checkAssert($edit->getStatusCode() === 200 && $db->table('roleplay_checks')->where('id', $rollId)->value('result') === $raw, 'Viewing, quoting and editing preserve the original roll snapshot: '.json_encode([$edit->getStatusCode(), checkJson($edit)['errors'] ?? []], JSON_UNESCAPED_UNICODE));
    $neutral = array_replace($roll, ['kind' => 'attribute', 'target_id' => (int) $skill->attribute_id, 'difficulty' => null]);
    [$r, $d, $neutralId] = $prepare('roll', $neutral, (int) $topic->id);
    checkAssert($r->getStatusCode() === 200 && $publish($topic, $d['data']['code'])->getStatusCode() === 201, 'The player may change suggested conditions and omit difficulty');
    checkAssert(json_decode($db->table('roleplay_checks')->where('id', $neutralId)->value('result'), true)['success'] === null, 'No difficulty produces no success or failure verdict');
    [$r, $d] = $prepare('roll', array_replace($neutral, ['request_id' => null, 'character_id' => $foreign]), null, 1);
    checkAssert($r->getStatusCode() === 200, 'A standalone roll can be prepared before creating a new discussion');
    $newTopic = $create('[TEST] First post check', $d['data']['code']);
    checkAssert($db->table('roleplay_checks')->where('post_id', $newTopic->first_post_id)->exists(), 'The first post of a new topic executes a prepared standalone roll');
} finally {$db->rollBack();}
foreach ($before as $table => $count) checkAssert($db->table($table)->count() === $count, 'Rollback restores '.$table);
echo 'Check request and roll integration passed; all fixtures rolled back.'.PHP_EOL;
