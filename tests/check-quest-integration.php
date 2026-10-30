<?php

declare(strict_types=1);

/** Только установленная тестовая копия. Все временные данные и настройки откатываются. */
$root = dirname(__DIR__, 3);
if (getenv('ROLEPLAY_TEST_COPY') !== '1' || !is_file($root.'/ROLEPLAY_TEST_INSTALLED')) {
    throw new RuntimeException('Run only in the installed Roleplay test copy.');
}
$container = (require $root.'/site.php')->bootApp()->getContainer();
$db = $container->make(\Illuminate\Database\ConnectionInterface::class);
$types = $container->make(\Maxcuso\Roleplay\Events\Participation\ParticipationTypes::class);
$creator = $container->make(\Maxcuso\Roleplay\Events\EventCreator::class);
$registration = $container->make(\Maxcuso\Roleplay\Events\EventRegistration::class);
$data = $container->make(\Maxcuso\Roleplay\Events\Api\EventData::class);
$settings = $container->make(\Flarum\Settings\SettingsRepositoryInterface::class);
$actor = \Flarum\User\User::findOrFail(1);
$other = \Flarum\User\User::findOrFail(2);
$originalSetting = $settings->get(\Maxcuso\Roleplay\Events\QuestSettings::ENABLED);
function questCheck(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "OK: $message\n";
}
$db->beginTransaction();
try {
    questCheck($types->has('quest'), 'Roleplay registers its quest submodule in the shared engine');
    $client = $container->make(\Flarum\Api\Client::class);
    $forumResponse = $client->withActor($actor)->send('GET', '/');
    $forumJson = json_decode((string) $forumResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
    questCheck($forumResponse->getStatusCode() === 200
        && ($forumJson['data']['attributes']['questEventsRoleplayAvailable'] ?? false) === true,
        'Forum API advertises the connected Roleplay provider');
    $settings->set(\Maxcuso\Roleplay\Events\QuestSettings::ENABLED, '1');
    // Изолируем только участие/статусы аккаунта теста, не удаляем настоящих персонажей.
    $db->table('quest_event_participants')->where('user_id', $actor->id)->delete();
    $db->table('roleplay_quest_participants')->where('user_id', $actor->id)->delete();
    $db->table('roleplay_characters')->where('user_id', $actor->id)->update(['status' => 'archived']);
    $race = $db->table('roleplay_races')->orderBy('id')->value('id');
    questCheck($race !== null, 'Imported race available for temporary characters');
    $post = \Flarum\Post\Post::whereNull('hidden_at')->orderBy('id')->firstOrFail();
    $event = $creator->create($actor, '[TEST] Quest characters', null, 2, 4, 12,
        new DateTimeImmutable('+2 days'), eventType: 'quest', difficulty: 3);
    $event->source_post_id = $post->id; $event->save();
    $rules = $types->get('quest');
    $read = fn () => $data->serialize($event->fresh()->load(['leader', 'participants'])->loadCount('participants'), $actor);
    $reject = function (array $input = []) use ($registration, $event, $actor, $db): void {
        try { $registration->register((int) $event->id, (int) $actor->id, $input); }
        catch (DomainException $e) {
            questCheck(!$db->table('quest_event_participants')->where('event_id', $event->id)->exists()
                && !$db->table('roleplay_quest_participants')->where('event_id', $event->id)->exists(),
                'Rejected registration leaves neither a slot nor a character binding');
            return;
        }
        throw new RuntimeException('Invalid registration accepted');
    };
    questCheck($read()['registrationOptions']['choices'] === [], 'No characters produces no registration choices');
    $reject();
    $character = function (string $name, string $status = 'active', ?int $userId = null) use ($db, $race, $actor): int {
        return (int) $db->table('roleplay_characters')->insertGetId([
            'user_id' => $userId ?? $actor->id, 'race_id' => $race, 'first_name' => $name,
            'status' => $status, 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
    };
    $pending = $character('Pending', 'creating');
    $dead = $character('Dead', 'dead');
    $reject(['characterId' => $pending]);
    $reject(['characterId' => $dead]);
    $one = $character('First active');
    questCheck(count($read()['registrationOptions']['choices']) === 1, 'Only active characters are offered');
    $response = $client->withActor($actor)->withBody([])->send('POST', '/quest-events/'.$event->id.'/participants');
    questCheck($response->getStatusCode() === 201, 'Original registration API accepts a single character without extra input');
    questCheck((int) $db->table('roleplay_quest_participants')->where('event_id', $event->id)->value('character_id') === $one,
        'One character is selected automatically in the same registration');
    $registration->leave((int) $event->id, (int) $actor->id);
    questCheck(!$db->table('roleplay_quest_participants')->where('event_id', $event->id)->exists(), 'Leaving removes the character binding');
    $two = $character('Second active');
    $foreign = $character('Other owner', 'active', (int) $other->id);
    questCheck(count($read()['registrationOptions']['choices']) === 2, 'Multiple active characters require a choice');
    $reject();
    $reject(['characterId' => $foreign]);
    $registration->register((int) $event->id, (int) $actor->id, ['characterId' => $two]);
    questCheck($read()['participants'][0]['character']['id'] === $two, 'API returns the selected character, not a profile selection');
    $db->table('roleplay_characters')->where('id', $two)->update(['first_name' => 'Renamed']);
    questCheck($read()['participants'][0]['character']['name'] === 'Renamed', 'Participant name follows the linked character');
    $registration->removeParticipant($actor, (int) $event->id, (int) $actor->id);
    questCheck(!$db->table('roleplay_quest_participants')->where('event_id', $event->id)->exists(), 'Moderator removal clears the binding');
    // Окно выбора могло устареть между GET и POST.
    $db->table('roleplay_characters')->where('id', $two)->update(['status' => 'archived']);
    $reject(['characterId' => $two]);
    $registration->register((int) $event->id, (int) $actor->id, ['characterId' => $one]);
    $editor = $container->make(\Maxcuso\Roleplay\Events\EventEditor::class);
    $editor->delete($actor, (int) $event->id, \Maxcuso\Roleplay\Events\EventEditor::revision($event->fresh()));
    questCheck(!$db->table('roleplay_quest_participants')->where('event_id', $event->id)->exists(), 'Deleting the event clears additional bindings');
    $forum = $creator->create($actor, '[TEST] Forum without character', null, 2, 4, 12, new DateTimeImmutable('+2 days'));
    $forum->source_post_id = $post->id; $forum->save();
    $db->table('roleplay_characters')->where('user_id', $actor->id)->update(['status' => 'archived']);
    $registration->register((int) $forum->id, (int) $actor->id);
    questCheck($forum->participants()->count() === 1
        && !$db->table('roleplay_quest_participants')->where('event_id', $forum->id)->exists(), 'Ordinary events still accept accounts without characters');
    $disabled = new \Maxcuso\Roleplay\Events\QuestSettings($settings, new \Maxcuso\Roleplay\Events\Participation\ParticipationTypes());
    try { $disabled->assertTypeAllowed('quest'); throw new RuntimeException('Quest enabled without integration'); }
    catch (DomainException $e) { questCheck(true, 'Quest creation is rejected without a Roleplay provider'); }
    $disabled->assertTypeAllowed('forum');
} finally {
    $originalSetting === null ? $settings->delete(\Maxcuso\Roleplay\Events\QuestSettings::ENABLED)
        : $settings->set(\Maxcuso\Roleplay\Events\QuestSettings::ENABLED, $originalSetting);
    $db->rollBack();
}
echo "Quest integration checks passed; fixtures rolled back.\n";
