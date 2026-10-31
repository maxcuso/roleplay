<?php
// Настоящие сервисы и HTTP-контроллер; все записи остаются во временной базе теста.
use Maxcuso\Roleplay\Character\Moderation\CharacterMechanics;
use Maxcuso\Roleplay\Character\State\CharacterGrants;
use Maxcuso\Roleplay\Api\CharacterMechanicsController;

$editId = $createdId;
$db->table('roleplay_characters')->where('id', $editId)->update([
    'status' => 'creating', 'mechanics_status' => 'review',
    'literature_status' => 'approved', 'literature_approved' => true,
]);
$mechanics = new CharacterMechanics($db, $characterOperations);
$editController = new CharacterMechanicsController($mechanics);
$editApi = function (string $method, array $body = [], int $status = 200) use ($editController, $editId, $owner): array {
    $request = \Flarum\Http\RequestUtil::withActor((new \Laminas\Diactoros\ServerRequest())
        ->withMethod($method)->withQueryParams(['id' => $editId])->withParsedBody($body), $owner);
    $response = $editController->handle($request);
    $data = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    if ($response->getStatusCode() !== $status) throw new RuntimeException("Mechanics $method: ".json_encode($data));
    check(true, "Mechanics $method returns $status");
    return $data['data'] ?? $data;
};
$editView = $editApi('GET');
$closedCommand = [
    'operation_key' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
    'revision' => $editView['character_revision'], 'catalog_revision' => $editView['catalog']['revision'],
];
rejected(fn () => $management->change($editId, $moderator, $closedCommand + [
    'action' => 'balance', 'data' => ['resource' => 'gold', 'delta' => 1], 'comment' => 'Проверка закрытой выдачи',
]), 'management_closed');
check(!$profile->read($editId, $moderator)['permissions']['can_manage']
    && !$profile->read($editId, $owner)['permissions']['can_equip'],
    'Pending characters expose neither staff rewards nor owner equipment');

check($editView['draft']['payload']['race_id'] === $startRace && $editView['preview']['valid'],
    'Pending editor restores full original creation choices');
rejected(fn () => $mechanics->read($editId, $visitor), 'character_not_found');
rejected(fn () => $mechanics->read($editId, $moderator), 'character_not_found');
check($profile->read($editId, $owner)['review']['permissions']['can_edit_mechanics'],
    'Owner may edit the whole technical part during review');
check($db->table('roleplay_character_items')->where('character_id', $editId)->where('is_starting', true)->count() === 3,
    'Creation marks starting stacks explicitly');

$previewBody = fn (array $view, array $payload) => [
    'revision' => $view['draft']['revision'], 'character_revision' => $view['character_revision'],
    'catalog_revision' => $view['catalog']['revision'], 'rules_hash' => $view['catalog']['rules_hash'],
    'step' => 'review', 'payload' => $payload,
];
$beforeHeader = (array) $db->table('roleplay_characters')->find($editId);
$beforeEvents = $db->table('roleplay_character_events')->where('character_id', $editId)->count();
$incomplete = $editApi('POST', $previewBody($editView, []));
check(!$incomplete['preview']['valid'] && (array) $db->table('roleplay_characters')->find($editId) === $beforeHeader
    && $db->table('roleplay_character_events')->where('character_id', $editId)->count() === $beforeEvents,
    'Preview of incomplete steps never writes character or journal');
$emptyRequest = \Flarum\Http\RequestUtil::withActor((new \Laminas\Diactoros\ServerRequest())
    ->withMethod('POST')->withQueryParams(['id' => $editId])->withParsedBody($previewBody($editView, [])), $owner);
$wire = json_decode((string) $editController->handle($emptyRequest)->getBody());
check(is_object($wire->data->draft->payload->base_attributes) && is_object($wire->data->draft->payload->loadout),
    'Editor empty maps retain object shape over HTTP');

// Награды намеренно совпадают со стартовым предметом; их стопки должны сохраниться отдельно.
$extraPerk = $create('perks', ['name' => 'Сохраняемая награда', 'enabled' => true]);
$grants = new CharacterGrants($db);
$grants->perk($editId, $extraPerk, $raceSource, ['race_id' => $freeRace, 'race_name' => 'Награда']);
$grants->spell($editId, $ordinarySpellTwo);
$grants->items($editId, $startItem, 1, 2);
$db->table('roleplay_character_balances')->where('character_id', $editId)->where('resource_key', 'gold')->update(['amount' => 37]);
$extraStack = $db->table('roleplay_character_items')->where('character_id', $editId)->where('is_starting', false)->first();
$startingStack = $db->table('roleplay_character_items')->where('character_id', $editId)->where('is_starting', true)->first();
$editView = $editApi('GET');
$changed = $editView['draft']['payload'];
$changed['first_name'] = 'Правка';
$changed['avatar'] = 'avatar-'.$owner->id.'-'.str_repeat('b', 32).'.webp';
$changed['race_id'] = $freeRace;
$changed['skills'] = [$startSkill];
$changed['racial_spell_ids'] = [];
$changed['racial_proficiency_ids'] = [$proficiencyIds['magic-weapon']];
$changed['base_attributes'] = ['physiology' => 2, 'agility' => 3, 'magic' => 1, 'charisma' => 1];
$checked = $editApi('POST', $previewBody($editView, $changed));
check($checked['preview']['valid'], 'Same wizard validates changed race, stats, skills and racial proficiency');
$editCommand = fn (array $view, array $payload, string $key) => [
    'operation_key' => $key, 'revision' => $view['character_revision'],
    'catalog_revision' => $view['catalog']['revision'], 'rules_hash' => $view['catalog']['rules_hash'], 'payload' => $payload,
];
$editKey = '77777777-7777-4777-8777-777777777777';
$editBody = $editCommand($checked, $changed, $editKey);
$topicCount = $db->table('roleplay_test_topics')->count();
$editResult = $editApi('PUT', $editBody);
$edited = $db->table('roleplay_characters')->find($editId);
check($edited->avatar === $changed['avatar']
    && $mechanics->read($editId, $owner)['draft']['payload']['avatar'] === $changed['avatar']
    && $profile->read($editId, $owner)['snapshot']['character']['avatar'] === $changed['avatar'],
    'Mechanics edit persists avatar, restores it on reopening and exposes it in profile');
check($editResult['character_id'] === $editId && $edited->first_name === 'Правка' && (int) $edited->race_id === $freeRace,
    'Save edits the existing character, never creates a replacement');
check($edited->mechanics_status === 'review' && $edited->literature_approved
    && $edited->literature_status === 'approved' && $edited->discussion_id === $beforeHeader['discussion_id']
    && $db->table('roleplay_test_topics')->count() === $topicCount,
    'Full technical edit preserves literary approval, topic and review status');
check($db->table('roleplay_character_items')->find($extraStack->id)->quantity === $extraStack->quantity
    && $db->table('roleplay_character_items')->find($startingStack->id) !== null
    && $db->table('roleplay_character_balances')->where('character_id', $editId)->where('resource_key', 'gold')->value('amount') == 37,
    'Save keeps reward stacks, unchanged starting instances and actual balance');
check($db->table('roleplay_character_perks')->where('character_id', $editId)->where('perk_id', $extraPerk)->exists()
    && $db->table('roleplay_character_spells')->where('character_id', $editId)->where('spell_id', $ordinarySpellTwo)->exists()
    && !$db->table('roleplay_character_spells')->where('character_id', $editId)->where('spell_id', $fixedSpell)->exists(),
    'Changed race removes its starter spell while preserving separately awarded entities');
check($editApi('PUT', $editBody) === $editResult
    && $db->table('roleplay_character_events')->where('character_id', $editId)->where('type', 'mechanics_updated')->count() === 1,
    'Retry of full save never duplicates grants or events');
$wrong = $editBody; $wrong['payload']['age'] = 22;
rejected(fn () => $mechanics->save($editId, $owner, $wrong), 'operation_key_conflict');
$stale = $editBody; $stale['operation_key'] = '88888888-8888-4888-8888-888888888888';
rejected(fn () => $mechanics->save($editId, $owner, $stale), 'character_revision_conflict');
$editView = $editApi('GET');
check($editView['draft']['payload']['first_name'] === 'Правка' && $editView['preview']['valid'],
    'Reopening restores the latest confirmed choices');
file_put_contents(__DIR__.'/.runtime/mechanics-view.json', json_encode($editView, JSON_THROW_ON_ERROR));
$invalid = $editCommand($editView, [], '99999999-9999-4999-8999-999999999999');
rejected(fn () => $mechanics->save($editId, $owner, $invalid), 'creation_incomplete');

// Ошибка журнала в самом конце обязана откатить все заменённые выдачи.
$before = json_encode($snapshotBuilder->build($editId), JSON_THROW_ON_ERROR);
$trigger = $testPrefix.'mechanics_failure';
$table = $db->getQueryGrammar()->wrapTable('roleplay_character_events');
$db->statement("CREATE TRIGGER $trigger BEFORE INSERT ON $table FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'mechanics test failure'");
$failure = $editCommand($editView, $changed, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
$failure['payload']['first_name'] = 'Не сохранять';
try {
    try { $mechanics->save($editId, $owner, $failure); throw new RuntimeException('Forced edit failure ignored'); }
    catch (\Illuminate\Database\QueryException) {
        check(json_encode($snapshotBuilder->build($editId), JSON_THROW_ON_ERROR) === $before,
            'Journal failure rolls back identity, attributes, training and all grants');
    }
} finally { $db->statement("DROP TRIGGER IF EXISTS $trigger"); }
$db->table('roleplay_characters')->where('id', $editId)->update(['mechanics_status' => 'approved']);
rejected(fn () => $mechanics->read($editId, $owner), 'mechanics_edit_closed');
rejected(fn () => $mechanics->save($editId, $owner, $failure), 'mechanics_edit_closed');
check(!$profile->read($editId, $owner)['review']['permissions']['can_edit_mechanics'],
    'Approval immediately closes full editing on server and in profile');
check($mechanics->save($editId, $owner, $editBody) === $editResult,
    'Exact saved-command replay remains safe after approval');

// Проверка обновления существующей схемы: только ожидающие персонажи получают отметку.
$grantMigration['down']($schema);
$grantMigration['up']($schema);
check($db->table('roleplay_character_items')->where('character_id', $editId)->where('is_starting', false)->count() === 0
    && $db->table('roleplay_character_items')->where('character_id', $progressId)->where('is_starting', true)->count() === 0,
    'Migration distinguishes old pending starters from active character rewards');
echo "Full pending mechanics editing, provenance, atomic save and access checks passed.\n";
