<?php

declare(strict_types=1);

use Flarum\Api\Client;
use Flarum\Api\JsonApi;
use Flarum\Api\Resource\DiscussionResource;
use Flarum\Discussion\Discussion;
use Flarum\Post\CommentPost;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Maxcuso\Roleplay\Episodes\RewardExperience;
use Maxcuso\Roleplay\Events\QuestEvent;

$root = dirname(__DIR__, 3);
if (getenv('ROLEPLAY_TEST_COPY') !== '1' || !is_file($root.'/ROLEPLAY_TEST_INSTALLED')) throw new RuntimeException('Test copy only');
$c = (require $root.'/site.php')->bootApp()->getContainer();
set_exception_handler(function (Throwable $e): void {fwrite(STDERR, get_class($e).': '.$e->getMessage().PHP_EOL.$e->getTraceAsString().PHP_EOL); exit(1);});
$db = $c->make(ConnectionInterface::class); $api = $c->make(Client::class); $jsonApi = $c->make(JsonApi::class);
$actor = User::findOrFail(1); $member = User::findOrFail(2);
function completeCheck(bool $ok, string $label): void {if (!$ok) throw new RuntimeException($label); echo 'OK: '.$label.PHP_EOL;}
function completeJson($response): array {return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);}
$tables = ['discussions', 'posts', 'quest_events', 'roleplay_characters', 'roleplay_operations', 'roleplay_character_events',
    'roleplay_episode_plans', 'roleplay_episode_completions', 'roleplay_character_items', 'roleplay_character_spells', 'roleplay_character_perks'];
$before = []; foreach ($tables as $table) $before[$table] = $db->table($table)->count();
$db->beginTransaction();
try {
    completeCheck(RewardExperience::tooltip(10, []) === '10 опыта', 'XP without bonuses has a plain tooltip');
    completeCheck(RewardExperience::tooltip(10, [['percent' => 80], ['percent' => 20]]) === '10 опыта + 100%', 'XP tooltip combines bonuses as requested');
    completeCheck(RewardExperience::tooltip(10, [['percent' => 0.1], ['percent' => 0.2]]) === '10 опыта + 0,3%', 'XP tooltip preserves fractional bonus precision');
    completeCheck(RewardExperience::calculate(15, [['percent' => 10], ['percent' => 20]]) === 19, 'Additive bonuses round XP down');
    completeCheck(RewardExperience::calculate(1000, [['percent' => 0.1], ['percent' => 0.2]]) === 1003, 'Fractional percentages preserve exact integer results');
    $bonusDefinition = ['mechanic' => 'quest_experience', 'operation' => 'percent', 'stat' => null, 'stack_group' => null];
    $bonusLink = ['value' => 12.5, 'condition' => 'always', 'condition_text' => null, 'scope' => 'all'];
    \Maxcuso\Roleplay\Rules\EffectRules::validateLink($bonusDefinition, $bonusLink, 'perks');
    completeCheck(true, 'Quest XP bonus accepts the shared fractional percentage effect');
    foreach ([['value' => -1], ['condition' => 'manual'], ['scope' => 'item_attack']] as $invalidBonus) {
        try {
            \Maxcuso\Roleplay\Rules\EffectRules::validateLink($bonusDefinition, $invalidBonus + $bonusLink, 'perks');
            throw new RuntimeException('Invalid quest XP effect accepted');
        } catch (DomainException) { completeCheck(true, 'Quest XP bonus rejects negative, conditional or weapon-scoped effects'); }
    }
    $original = (array) $db->table('roleplay_characters')->orderBy('id')->first();
    completeCheck(!empty($original), 'Existing complete character is available as a rollback-only fixture template');
    $copyCharacter = function (string $name, int $owner) use ($db, $original): int {
        $row = $original; unset($row['id']);
        $row['first_name'] = $name; $row['last_name'] = 'Тест'; $row['user_id'] = $owner;
        $row['status'] = 'active'; $row['mechanics_status'] = 'approved'; $row['literature_status'] = 'approved';
        $row['literature_approved'] = true; $row['discussion_id'] = null; $row['revision'] = 1;
        $id = (int) $db->table('roleplay_characters')->insertGetId($row);
        foreach ($db->table('roleplay_character_attributes')->where('character_id', $original['id'])->get() as $attribute) {
            $fields = (array) $attribute; unset($fields['id']); $fields['character_id'] = $id;
            $db->table('roleplay_character_attributes')->insert($fields);
        }
        foreach (['experience_active', 'experience_total', 'gold'] as $resource) $db->table('roleplay_character_balances')->insert([
            'character_id' => $id, 'resource_key' => $resource, 'amount' => 100, 'updated_at' => gmdate('Y-m-d H:i:s')]);
        return $id;
    };
    $one = $copyCharacter('Первый', (int) $member->id); $two = $copyCharacter('Второй', (int) $actor->id);
    $createTopic = fn ($title, $content) => $jsonApi->forResource(DiscussionResource::class)->forEndpoint('create')->process([
        'data' => ['attributes' => ['title' => $title, 'content' => $content]],
    ], [], ['actor' => $actor]);
    $makeEpisode = function () use ($db, $createTopic, $actor, $member, $one): array {
        $source = $createTopic('[TEST] Completion source', '[quest title="Награды переправы" min=1 max=3 delay=12 type=quest difficulty=2]Temporary source[/quest]');
        $event = QuestEvent::where('source_post_id', $source->first_post_id)->firstOrFail();
        $topic = $createTopic('[TEST] Completion episode', 'Temporary episode body');
        $event->discussion_id = $topic->id; $event->status = 'active'; $event->save();
        $db->table('quest_event_participants')->insert(['event_id' => $event->id, 'user_id' => $member->id]);
        $db->table('roleplay_quest_participants')->insert(['event_id' => $event->id, 'user_id' => $member->id,
            'character_id' => $one, 'character_name' => 'Первый Тест', 'created_at' => gmdate('Y-m-d H:i:s')]);
        return [$event, $topic];
    };
    [$event, $topic] = $makeEpisode();
    $base = '/roleplay/episodes/'.$topic->id.'/completion';
    $get = fn ($path, $user = null) => $api->withActor($user ?? $actor)->send('GET', $path);
    $contextResponse = $get($base); $context = completeJson($contextResponse)['data'];
    completeCheck($contextResponse->getStatusCode() === 200 && $context['characters'][0]['id'] === $one, 'Context loads the episode-bound character');
    completeCheck($get($base, $member)->getStatusCode() === 403, 'An ordinary participant cannot prepare completion');
    $search = $get($base.'/catalogs/items?search=&page=1'); $selection = completeJson($search)['data'];
    completeCheck($search->getStatusCode() === 200 && count($selection['rows']) <= 30 && $selection['total'] >= count($selection['rows']), 'Catalog search returns a bounded server page');
    completeCheck($get($base.'/catalogs/attributes')->getStatusCode() === 422, 'Other catalogs are unavailable');
    completeCheck($get($base.'/characters/'.$two)->getStatusCode() === 200, 'Another active recipient can be read');
    $perk = (int) $db->table('roleplay_perks')->where('enabled', true)->orderBy('id')->value('id');
    $spell = (int) $db->table('roleplay_spells as s')->join('roleplay_magic_paths as p', 'p.id', '=', 's.magic_path_id')
        ->where('s.enabled', true)->where('p.enabled', true)->orderBy('s.id')->value('s.id');
    $item = (int) $db->table('roleplay_items')->where('enabled', true)->orderBy('id')->value('id');
    $source = (int) $db->table('roleplay_perk_sources')->where('name_source', 'quest')->where('enabled', true)->value('id');
    completeCheck($perk > 0 && $spell > 0 && $item > 0 && $source > 0, 'Real catalogs provide all three reward types and quest source');
    $db->table('roleplay_character_perks')->insert(['character_id' => $one, 'perk_id' => $perk, 'source_id' => $source,
        'source_context' => json_encode(['quest_name' => 'Previous']), 'is_starting' => false]);
    $db->table('roleplay_character_spells')->insert(['character_id' => $one, 'spell_id' => $spell, 'is_starting' => false]);
    $payload = ['event_id' => (int) $event->id, 'discussion_id' => (int) $topic->id, 'revision' => $context['revision'],
        'recipients' => [
            ['character_id' => $one, 'experience' => 15, 'gold' => 7, 'items' => [['id' => $item, 'quantity' => 2]], 'perks' => [$perk], 'spells' => [$spell]],
            ['character_id' => $two, 'experience' => 5, 'gold' => 2, 'items' => [], 'perks' => [$perk], 'spells' => [$spell]],
        ]];
    $key = (string) Str::uuid(); $body = ['operation_key' => $key, 'payload' => $payload];
    $prepare = fn ($url, $body) => $api->withActor($actor)->withBody($body)->send('POST', $url);
    $operations = $db->table('roleplay_operations')->count();
    $response = $prepare($base, $body); $prepared = completeJson($response);
    completeCheck($response->getStatusCode() === 200, 'Preparing a code succeeds: '.json_encode($prepared, JSON_UNESCAPED_UNICODE));
    $code = $prepared['data']['code'];
    completeCheck($code === '[episode-end='.$key.']' && $db->table('roleplay_operations')->count() === $operations && !$topic->fresh()->is_locked,
        'Inserting a code neither awards nor closes');
    completeCheck($prepare($base, $body)->getStatusCode() === 200 && $db->table('roleplay_episode_plans')->where('id', $key)->count() === 1,
        'A repeated prepare uses the same plan');
    $different = $body; $different['payload']['recipients'][0]['gold']++;
    completeCheck($prepare($base, $different)->getStatusCode() === 409, 'An idempotency key cannot change its rewards');
    // Перк и экипировка появляются после подготовки: бонусы считаются при публикации.
    $effect = fn ($value) => ['name' => null, 'description' => null, 'icon' => null, 'mechanic' => 'quest_experience',
        'operation' => 'percent', 'attribute_id' => null, 'skill_id' => null, 'stat' => null, 'value' => $value,
        'condition' => 'always', 'condition_text' => null, 'scope' => 'all', 'stack_group' => null];
    $bonusPerk = (array) $db->table('roleplay_perks')->find($perk); unset($bonusPerk['id']);
    $bonusPerk['slug'] = 'completion-bonus-'.Str::uuid(); $bonusPerk['name'] = 'Тестовый бонус опыта';
    $bonusPerk['effects'] = json_encode([$effect(10)]);
    $bonusPerkId = (int) $db->table('roleplay_perks')->insertGetId($bonusPerk);
    $db->table('roleplay_character_perks')->insert(['character_id' => $one, 'perk_id' => $bonusPerkId, 'source_id' => $source,
        'source_context' => json_encode(['quest_name' => 'Bonus']), 'is_starting' => false]);
    $bonusItem = (array) $db->table('roleplay_items')->find($item); unset($bonusItem['id']);
    $bonusItem['slug'] = 'completion-item-'.Str::uuid(); $bonusItem['name'] = 'Тестовый предмет опыта';
    $bonusItem['is_magical'] = true; $bonusItem['effects'] = json_encode([$effect(20)]);
    $bonusItemId = (int) $db->table('roleplay_items')->insertGetId($bonusItem);
    $db->table('roleplay_character_items')->insert(['character_id' => $one, 'item_id' => $bonusItemId, 'quantity' => 1, 'equipped' => true, 'is_starting' => false]);
    $publish = fn ($topic, $content, $user = null) => $api->withActor($user ?? $actor)->withBody([
        'data' => ['type' => 'posts', 'attributes' => ['content' => $content],
            'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => (string) $topic->id]]]],
    ])->send('POST', '/posts');
    foreach (['[code]'.$code.'[/code]', '[quote]'.$code.'[/quote]'] as $quoted) {
        completeCheck($publish($topic, $quoted)->getStatusCode() === 201 && !$db->table('roleplay_episode_completions')->where('event_id', $event->id)->exists(),
            'Code blocks and quotes do not execute the pending completion');
    }
    $foreign = $publish($topic, $code, $member);
    completeCheck(in_array($foreign->getStatusCode(), [403, 422], true) && !$db->table('roleplay_episode_completions')->where('event_id', $event->id)->exists(),
        'A copied pending code cannot be executed by another account');
    $commentsBefore = (int) $db->table('users')->where('id', $actor->id)->value('comment_count');
    $published = $publish($topic, $code); $publishedJson = completeJson($published);
    completeCheck($published->getStatusCode() === 201, 'Publishing succeeds: '.json_encode($publishedJson['errors'] ?? [], JSON_UNESCAPED_UNICODE));
    $postId = (int) $publishedJson['data']['id'];
    $receipt = $db->table('roleplay_episode_completions')->where('event_id', $event->id)->first();
    $result = json_decode($receipt->result, true, flags: JSON_THROW_ON_ERROR);
    completeCheck($event->fresh()->status === 'completed' && $topic->fresh()->is_locked, 'Publication completes the event and locks the topic together');
    completeCheck((int) $db->table('users')->where('id', $actor->id)->value('comment_count') === $commentsBefore + 1,
        'Nested native locking preserves the author comment count');
    completeCheck($result['recipients'][0]['experience'] === 19 && count($result['recipients'][0]['bonuses']) === 2,
        'Publication uses the new perk and equipped item bonuses and snapshots them');
    completeCheck(count($result['recipients'][0]['skipped']) === 2 && count($result['recipients'][0]['grants']) === 1,
        'Known perk and spell are skipped while item and resources are awarded');
    completeCheck(count($result['recipients'][1]['grants']) === 2, 'A second recipient receives new perk and spell');
    $balance = fn ($id, $resource) => (int) $db->table('roleplay_character_balances')->where('character_id', $id)->where('resource_key', $resource)->value('amount');
    completeCheck($balance($one, 'experience_active') === 119 && $balance($one, 'experience_total') === 119 && $balance($one, 'gold') === 107,
        'Active XP, earned XP and gold use the shared balance mechanism');
    completeCheck($context['title'] === $event->title && $context['title'] !== $topic->title, 'Quest context uses the card title instead of the formatted episode topic');
    $post = $get('/posts/'.$postId); $html = completeJson($post)['data']['attributes']['contentHtml'] ?? '';
    completeCheck(str_contains($html, 'RpEpisodeReceipt') && str_contains($html, '19') && str_contains($html, 'fa-scroll')
        && !str_contains($html, 'Тема закрыта') && !str_contains($html, 'повторно') && !str_contains($html, 'Награды выданы'), 'Published HTML renders the independent receipt without unwanted status or duplicate messages');
    completeCheck(str_contains($html, 'RpEpisodeReceipt-title') && str_contains($html, 'Награды переправы')
        && !str_contains($html, '[TEST] Completion episode'), 'Quest receipt shows the card title without topic metadata');
    $avatarUrl = \Maxcuso\Roleplay\Presentation\CharacterAvatar::url($original['avatar']);
    completeCheck($avatarUrl !== null && str_contains($html, $avatarUrl) && str_contains($html, 'loading="lazy"'),
        'Published receipts render the current character-sheet avatar');
    completeCheck($context['characters'][0]['avatarUrl'] === $avatarUrl, 'Reward selection resolves character avatar filenames into complete URLs');
    completeCheck(str_contains($html, '/roleplay?character='.$one) && str_contains($html, '/roleplay?character='.$two)
        && str_contains($html, '<a class="RpEpisodeReceipt-name"'), 'Names and avatars link to the exact character in the owner profile');
    completeCheck(!str_contains($html, '<details') && !str_contains($html, '<summary') && str_contains($html, 'title="15 опыта + 30%"')
        && str_contains($html, 'title="7 золота"'), 'Rewards display final amounts with calculation tooltips and no disclosure controls');
    $operationsAfter = $db->table('roleplay_operations')->count();
    $get('/posts/'.$postId); $get('/posts/'.$postId);
    completeCheck($db->table('roleplay_operations')->count() === $operationsAfter, 'Viewing the receipt never awards again');
    completeCheck($publish($topic, '[quote]'.$code.'[/quote]')->getStatusCode() === 201
        && $db->table('roleplay_operations')->count() === $operationsAfter, 'Quoting a published receipt cannot award twice');
    $edit = $api->withActor($actor)->withBody(['data' => ['type' => 'posts', 'id' => (string) $postId,
        'attributes' => ['content' => 'Edited text '.$code]]])->send('PATCH', '/posts/'.$postId);
    completeCheck($edit->getStatusCode() === 200 && $db->table('roleplay_operations')->count() === $operationsAfter, 'Editing a completed receipt does not change rewards');
    // Второй получатель ломает начисление: пост, первый получатель и закрытие тоже откатываются.
    [$failedEvent, $failedTopic] = $makeEpisode(); $failedBase = '/roleplay/episodes/'.$failedTopic->id.'/completion';
    $failedContext = completeJson($get($failedBase))['data'];
    $failedPayload = $payload; $failedPayload['event_id'] = (int) $failedEvent->id; $failedPayload['discussion_id'] = (int) $failedTopic->id;
    $failedPayload['revision'] = $failedContext['revision']; $failedKey = (string) Str::uuid();
    $failedCode = completeJson($prepare($failedBase, ['operation_key' => $failedKey, 'payload' => $failedPayload]))['data']['code'];
    $db->table('roleplay_character_balances')->where('character_id', $two)->where('resource_key', 'gold')->delete();
    $xpBefore = $balance($one, 'experience_active'); $itemsBefore = $db->table('roleplay_character_items')->where('character_id', $one)->sum('quantity');
    $postCount = $db->table('posts')->count();
    $failed = $publish($failedTopic, $failedCode);
    completeCheck($failed->getStatusCode() === 422, 'A later recipient failure rejects publication');
    completeCheck($balance($one, 'experience_active') === $xpBefore && $db->table('roleplay_character_items')->where('character_id', $one)->sum('quantity') === $itemsBefore
        && $db->table('roleplay_operations')->count() === $operationsAfter && $db->table('posts')->count() === $postCount,
        'Failure rolls back the post, earlier recipient rewards and character history');
    completeCheck($failedEvent->fresh()->status === 'active' && !$failedTopic->fresh()->is_locked
        && !$db->table('roleplay_episode_completions')->where('event_id', $failedEvent->id)->exists(), 'Failure leaves the episode active and topic open');
    // Выдачи в обычных темах и форумных событиях не завершают и не закрывают их.
    $three = $copyCharacter('Третий', (int) $actor->id);
    foreach (['plain', 'forum'] as $kind) {
        $ordinary = $createTopic('[TEST] General reward '.$kind, 'Ordinary discussion');
        $forumEvent = null;
        if ($kind === 'forum') {
            $card = $createTopic('[TEST] Forum reward source', '[quest title="Встреча" min=1 max=3 delay=12 type=forum]Meeting[/quest]');
            $forumEvent = QuestEvent::where('source_post_id', $card->first_post_id)->firstOrFail();
            $forumEvent->discussion_id = $ordinary->id; $forumEvent->status = 'active'; $forumEvent->save();
        }
        $ordinaryBase = '/roleplay/episodes/'.$ordinary->id.'/completion';
        $ordinaryContext = completeJson($get($ordinaryBase))['data'];
        completeCheck($ordinaryContext['eventId'] === null && $ordinaryContext['isQuest'] === false && $ordinaryContext['characters'] === [],
            'Ordinary '.$kind.' topic opens reward selection without a quest');
        completeCheck($get($ordinaryBase, $member)->getStatusCode() === 403, 'Ordinary rewards still require topic locking permission');
        $person = completeJson($get($ordinaryBase.'/characters/'.$one))['data'];
        completeCheck($person['bonuses'] === [], 'Quest XP bonuses do not appear in ordinary reward selection');
        $ordinaryPayload = ['event_id' => null, 'discussion_id' => (int) $ordinary->id, 'revision' => $ordinaryContext['revision'],
            'recipients' => [['character_id' => $one, 'experience' => 15, 'gold' => 1, 'items' => [], 'perks' => [], 'spells' => []],
                ['character_id' => $three, 'experience' => 0, 'gold' => 0, 'items' => [], 'perks' => [$perk], 'spells' => []]]];
        $xpBefore = $balance($one, 'experience_active');
        foreach ([1, 2] as $round) {
            $ordinaryKey = (string) Str::uuid();
            $ordinaryResponse = $prepare($ordinaryBase, ['operation_key' => $ordinaryKey, 'payload' => $ordinaryPayload]);
            completeCheck($ordinaryResponse->getStatusCode() === 200, 'An ordinary topic prepares independent reward '.$round);
            $ordinaryCode = completeJson($ordinaryResponse)['data']['code'];
            $awardPost = $publish($ordinary, $ordinaryCode); $awardJson = completeJson($awardPost);
            completeCheck($awardPost->getStatusCode() === 201, 'Ordinary reward publication succeeds: '.json_encode($awardJson['errors'] ?? [], JSON_UNESCAPED_UNICODE));
            $ordinaryReceipt = json_decode($db->table('roleplay_episode_completions')->where('plan_id', $ordinaryKey)->value('result'), true);
            completeCheck(!$ordinary->fresh()->is_locked && (!$forumEvent || $forumEvent->fresh()->status === 'active'),
                'An ordinary '.$kind.' topic stays open and the event status stays active');
            completeCheck($ordinaryReceipt['is_quest'] === false && $ordinaryReceipt['recipients'][0]['experience'] === 15
                && $ordinaryReceipt['recipients'][0]['bonuses'] === [] && $balance($one, 'experience_active') === $xpBefore + 15 * $round,
                'Ordinary rewards use base XP without quest multipliers');
            $ordinaryHtml = completeJson($get('/posts/'.$awardJson['data']['id']))['data']['attributes']['contentHtml'];
            completeCheck(str_contains($ordinaryHtml, 'title="15 опыта"') && str_contains($ordinaryHtml, 'title="1 золота"'), 'Ordinary XP and gold tooltips contain the awarded amounts without bonus text');
            completeCheck(str_contains($ordinaryHtml, 'RpEpisodeReceipt') && str_contains($ordinaryHtml, 'Эпизод завершён')
                && !str_contains($ordinaryHtml, 'Награды выданы'), 'All reward panels retain the same existing presentation');
            $operationsBeforeReplay = $db->table('roleplay_operations')->count();
            completeCheck($publish($ordinary, $ordinaryCode)->getStatusCode() === 201
                && $db->table('roleplay_operations')->count() === $operationsBeforeReplay, 'Reposting an ordinary receipt cannot repeat awards');
        }
        $grantSource = $db->table('roleplay_character_perks as cp')->join('roleplay_perk_sources as s', 's.id', '=', 'cp.source_id')
            ->where('cp.character_id', $three)->where('cp.perk_id', $perk)->value('s.name_source');
        completeCheck($grantSource === 'event', 'An ordinary perk grant uses an event source, not a quest source');
        // Подготовленный обычный код не должен молча превратиться в завершение нового квеста.
        $staleKey = (string) Str::uuid();
        $staleCode = completeJson($prepare($ordinaryBase, ['operation_key' => $staleKey, 'payload' => $ordinaryPayload]))['data']['code'];
        [$newQuest, $unusedTopic] = $makeEpisode();
        $newQuest->discussion_id = $ordinary->id; $newQuest->save();
        $beforeStale = $balance($one, 'experience_active'); $beforePosts = $db->table('posts')->count();
        completeCheck($publish($ordinary, $staleCode)->getStatusCode() === 422
            && $balance($one, 'experience_active') === $beforeStale && $db->table('posts')->count() === $beforePosts
            && !$ordinary->fresh()->is_locked && $newQuest->fresh()->status === 'active',
            'Changing a topic into a quest invalidates its old ordinary reward code atomically');
    }
} finally {$db->rollBack();}
foreach ($before as $table => $count) completeCheck($db->table($table)->count() === $count, 'Rollback restores '.$table);
echo 'Episode completion checks passed; fixtures rolled back.'.PHP_EOL;
