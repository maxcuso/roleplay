<?php
// Продолжение check-base-catalogs.php. Только временные таблицы; реальных пользователей не создаём.
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\ServerRequest;
use Maxcuso\Roleplay\Creation\CreationDraft;
use Maxcuso\Roleplay\Creation\CreateCharacter;
use Maxcuso\Roleplay\Creation\CreationPayload;
use Maxcuso\Roleplay\Creation\CreationPlanBuilder;
use Maxcuso\Roleplay\Creation\CreationCatalog;
use Maxcuso\Roleplay\Creation\CreationConfigurationStore;
use Maxcuso\Roleplay\Api\CreationDraftController;
use Maxcuso\Roleplay\Api\CreateCharacterController;
use Maxcuso\Roleplay\Api\CreationConfigurationController;

// Старые тестовые персонажи не занимают слот этого сценария.
$db->table('roleplay_characters')->where('user_id', $admin->id)->update(['status' => 'dead']);
foreach ($attributeDefinitions as $attribute) {
    $api('PATCH', 'attributes', ['revision' => $revision(), 'enabled' => true], ['id' => (int) $attribute->id]);
}
$startType = $create('item-types', ['name' => 'Стартовый тип', 'enabled' => true]);
$startItem = $create('items', ['name' => 'Вещь набора', 'item_type_id' => $startType, 'enabled' => true,
    'max_stack' => 2, 'weight' => 0.5]);
$raceSource = $create('sources', ['name' => 'Раса', 'name_source' => 'race', 'enabled' => true]);
$skillSource = $create('sources', ['name' => 'Навык', 'name_source' => 'skill', 'enabled' => true]);
$startPerk = $create('perks', ['name' => 'Общий стартовый перк', 'rarity' => 'epic', 'enabled' => true]);
$nextPerk = $create('perks', ['name' => 'Перк второго уровня', 'enabled' => true]);
$lastPerk = $create('perks', ['name' => 'Перк третьего уровня', 'enabled' => true]);
$startSkill = $create('skills', ['name' => 'Стартовый навык', 'attribute_id' => (int) $attributeDefinitions['charisma']->id,
    'enabled' => true, 'levels' => [['level' => 1, 'perk_id' => $startPerk],
        ['level' => 2, 'perk_id' => $nextPerk], ['level' => 3, 'perk_id' => $lastPerk]]]);
$startPhysSkill = $create('skills', ['name' => 'Другой стартовый навык', 'attribute_id' => $physiologyId,
    'enabled' => true, 'levels' => [['level' => 1, 'perk_id' => $nextPerk],
        ['level' => 2, 'perk_id' => $lastPerk], ['level' => 3, 'perk_id' => $startPerk]]]);
$startPath = $create('paths', ['name' => 'Стартовый путь', 'enabled' => true]);
$fixedSpell = $create('spells', ['name' => 'Фиксированное V', 'magic_path_id' => $startPath, 'level' => 5, 'enabled' => true, 'is_starting' => false]);
$chosenSpell = $create('spells', ['name' => 'Расовое IV', 'magic_path_id' => $startPath, 'level' => 4, 'enabled' => true, 'is_starting' => false]);
$ordinarySpell = $create('spells', ['name' => 'Обычное I', 'magic_path_id' => $startPath, 'level' => 1, 'enabled' => true]);
$ordinarySpellTwo = $create('spells', ['name' => 'Другое I', 'magic_path_id' => $startPath, 'level' => 1, 'enabled' => true]);
$startRace = $create('races', ['name' => 'Стартовая раса', 'enabled' => true, 'min_age' => 12, 'max_age' => 90,
    'rules' => ['fixed_bonuses' => ['physiology' => 2], 'fixed_perk_ids' => [$startPerk],
        'fixed_spell_ids' => [$fixedSpell], 'spell_choice_ids' => [$chosenSpell], 'spell_choice_count' => 1]]);

$config = ['enabled' => true, 'max_characters' => 10, 'starting_gold' => 10, 'application' => ['tag_id' => 7],
    'perk_sources' => ['race' => $raceSource, 'skill' => $skillSource],
    'loadout' => [
        ['key' => 'gear', 'name' => 'Снаряжение', 'min' => 1, 'max' => 1, 'options' => [
            ['key' => 'kit', 'name' => 'Набор', 'items' => [['item_id' => $startItem, 'quantity' => 5]]],
        ]],
        ['key' => 'clothing', 'name' => 'Одежда', 'automatic' => true, 'options' => [
            ['key' => 'clothes', 'name' => 'Одежда', 'items' => [['item_id' => $startItem, 'quantity' => 1]]],
        ]],
    ]];
$configStore = new CreationConfigurationStore($db);
$configStore->save($admin, $revision(), $config);
$api('DELETE', 'items', ['revision' => $revision()], ['id' => $startItem], 409);
$api('DELETE', 'sources', ['revision' => $revision()], ['id' => $raceSource], 409);

$drafts = new CreationDraft($db);
$creator = new CreateCharacter($db, $topics);
$controllers = ['draft' => new CreationDraftController($drafts), 'create' => new CreateCharacterController($creator),
    'configuration' => new CreationConfigurationController($db)];
$creationApi = function (string $action, string $method, array $body = [], int $expected = 200) use ($controllers, $admin): array {
    $request = RequestUtil::withActor((new ServerRequest())->withMethod($method)->withParsedBody($body), $admin);
    $response = $controllers[$action]->handle($request);
    $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    if ($response->getStatusCode() !== $expected) throw new RuntimeException("$method creation/$action: ".json_encode($payload, JSON_UNESCAPED_UNICODE));
    check(true, "$method creation/$action status $expected");
    return $payload;
};
// Проверяем форму JSON, а не только json_decode(..., true), который не различает {} и [].
$emptyRequest = RequestUtil::withActor((new ServerRequest())->withMethod('GET'), $admin);
$wire = json_decode((string) $controllers['draft']->handle($emptyRequest)->getBody(), flags: JSON_THROW_ON_ERROR);
check(is_object($wire->data->draft->payload->base_attributes)
    && is_object($wire->data->draft->payload->loadout), 'Empty attribute and loadout maps are JSON objects');
$view = $creationApi('draft', 'GET')['data'];
check($view['draft']['id'] === 0 && !$view['preview']['valid'], 'Unstarted wizard returns empty incomplete draft');
$saveBody = fn (array $view, array $payload) => [
    'revision' => $view['draft']['revision'], 'catalog_revision' => $view['catalog']['revision'],
    'rules_hash' => $view['catalog']['rules_hash'], 'step' => 'review', 'payload' => $payload,
];
$testAvatar = 'avatar-'.$admin->id.'-'.str_repeat('a', 32).'.webp';
$payload = ['avatar' => $testAvatar, 'first_name' => '  Новый  ', 'last_name' => '', 'age' => 20, 'race_id' => $startRace,
    'base_attributes' => ['physiology' => 3, 'agility' => 2, 'magic' => 1, 'charisma' => 1],
    'skills' => [$startSkill, $startPhysSkill], 'racial_spell_ids' => [$chosenSpell], 'loadout' => ['gear' => ['kit']]];
$oldBody = $saveBody($view, []);
$partial = $creationApi('draft', 'PUT', $oldBody)['data'];
check($partial['draft']['revision'] === 1 && !$partial['preview']['valid'], 'Incomplete draft saves without grants');
$creationApi('draft', 'PUT', $oldBody, 409);
$view = $creationApi('draft', 'PUT', $saveBody($partial, $payload))['data'];
check($view['draft']['payload']['avatar'] === $testAvatar
    && $view['preview']['review']['snapshot']['character']['avatar'] === $testAvatar, 'Saved draft and review preserve the avatar');
check($view['preview']['valid'] && $view['draft']['payload']['first_name'] === 'Новый', 'Valid draft is normalized and gets full server plan');
check(count($view['preview']['perks']) === 2 && $view['preview']['perks'][0]['source_id'] === $raceSource,
    'Skill overlapping racial perk never duplicates it or changes first source');
check(count($view['preview']['spells']) === 2 && $view['preview']['spell_choices']['ordinary_count'] === 0,
    'Magic 1 receives fixed V and selected racial IV without ordinary starting magic');
check(count(array_filter($view['preview']['choices'], fn (array $choice) => $choice['target_id'] === null)) === 2,
    'Unused physiology/agility proficiency choices remain in plan');
$command = fn (array $view, string $key) => ['operation_key' => $key, 'draft_id' => $view['draft']['id'],
    'draft_revision' => $view['draft']['revision'], 'catalog_revision' => $view['catalog']['revision'],
    'rules_hash' => $view['catalog']['rules_hash']];
// Порядок повторных выборов навыка создаёт все перки I–III, а не только перк последнего уровня.
$catalogForLevels = (new CreationCatalog($db))->read(fn (array $catalog) => $catalog);
$higherCatalog = $catalogForLevels['definitions'];
$higherCatalog['races'][$startRace]['rules']['fixed_bonuses'] = ['charisma' => 14];
$higherPayload = $payload;
$higherPayload['skills'] = [$startSkill, $startSkill, $startSkill];
$higherPlan = (new CreationPlanBuilder())->build((new CreationPayload())->normalize($higherPayload),
    $higherCatalog, $catalogForLevels['configuration']);
check($higherPlan['valid'] && $higherPlan['skills'][0]['level'] === 3 && count($higherPlan['perks']) === 3,
    'Initial sequential skill upgrades keep every perk through III and consume only three choices');
$magicCatalog = $catalogForLevels['definitions'];
$magicCatalog['races'][$startRace]['rules']['fixed_bonuses'] = ['magic' => 2];
$magicPayload = $payload;
$magicPayload['base_attributes'] = ['physiology' => 1, 'agility' => 1, 'magic' => 3, 'charisma' => 2];
$magicPayload['skills'] = [];
$magicPayload['spell_ids'] = [$ordinarySpell, $ordinarySpellTwo];
$magicPlan = (new CreationPlanBuilder())->build((new CreationPayload())->normalize($magicPayload),
    $magicCatalog, $catalogForLevels['configuration']);
check($magicPlan['valid'] && count($magicPlan['spells']) === 4 && count($magicPlan['proficiencies']) === 1
    && $magicPlan['proficiencies'][0]['slug'] === 'magic-weapon',
    'Magic 5 automatically grants its proficiency and two ordinary spells in addition to both racial spells');
// Переключатель ограничивает и список, и серверную проверку обычного выбора.
$nonStartingCatalog = $magicCatalog;
$nonStartingCatalog['spells'][$ordinarySpell]['is_starting'] = false;
$nonStartingPlan = (new CreationPlanBuilder())->build((new CreationPayload())->normalize($magicPayload),
    $nonStartingCatalog, $catalogForLevels['configuration']);
check(!$nonStartingPlan['valid'] && !in_array($ordinarySpell, $nonStartingPlan['spell_choices']['ordinary_ids'], true),
    'Non-starting spell is hidden from the ordinary pool and rejected in a submitted selection');
check(in_array($chosenSpell, $nonStartingPlan['spell_choices']['racial_ids'], true)
    && in_array($fixedSpell, array_column($nonStartingPlan['spells'], 'id'), true),
    'Starting flag does not restrict explicit racial choices or fixed racial grants');
$racialPathCatalog = $nonStartingCatalog;
$racialPathCatalog['races'][$startRace]['rules']['spell_choice_ids'] = [];
$racialPathCatalog['races'][$startRace]['rules']['spell_choice_path_ids'] = [$startPath];
$racialPathPlan = (new CreationPlanBuilder())->build((new CreationPayload())->normalize($magicPayload),
    $racialPathCatalog, $catalogForLevels['configuration']);
check(in_array($ordinarySpell, $racialPathPlan['spell_choices']['racial_ids'], true),
    'Non-starting spells remain available to racial path-based choices');
// Новые расовые владения: список задаёт раса, уровень характеристики не ограничивает получение.
$proficiencyIds = array_column($catalogForLevels['definitions']['proficiencies'], 'id', 'slug');
$racialCatalog = $catalogForLevels['definitions'];
$racialCatalog['races'][$startRace]['rules']['proficiency_choice_mode'] = 'all';
$racialCatalog['races'][$startRace]['rules']['proficiency_choice_count'] = 1;
$racialPayload = $normalPayload = (new CreationPayload())->normalize($payload);
$racialPayload['racial_proficiency_ids'] = [$proficiencyIds['magic-weapon']];
$racialPlan = (new CreationPlanBuilder())->build($racialPayload, $racialCatalog, $catalogForLevels['configuration']);
check($racialPlan['valid'] && count($racialPlan['proficiencies']) === 1
    && count(array_filter($racialPlan['choices'], fn ($row) => $row['target_id'] === null)) === 2,
    'Racial any-proficiency choice grants magic at Magic 1 without consuming attribute choices');
$restrictedCatalog = $racialCatalog;
// Оба варианта этого отдельного сценария включены, независимо от предыдущих проверок статусов.
$restrictedCatalog['proficiencies'][$proficiencyIds['one-handed']]['enabled'] = true;
$restrictedCatalog['proficiencies'][$proficiencyIds['two-handed']]['enabled'] = true;
$restrictedCatalog['races'][$startRace]['rules']['proficiency_choice_mode'] = 'list';
$restrictedCatalog['races'][$startRace]['rules']['proficiency_choice_ids'] = [$proficiencyIds['one-handed'], $proficiencyIds['two-handed']];
check(!(new CreationPlanBuilder())->build($racialPayload, $restrictedCatalog, $catalogForLevels['configuration'])['valid'],
    'Restricted racial pool rejects magic proficiency');
$racialPayload['racial_proficiency_ids'] = [$proficiencyIds['two-handed']];
$restrictedPlan = (new CreationPlanBuilder())->build($racialPayload, $restrictedCatalog, $catalogForLevels['configuration']);
check($restrictedPlan['valid'], 'Dwarf-like list accepts two-handed proficiency');
$missingRacial = $racialPayload;
$missingRacial['racial_proficiency_ids'] = [];
check(!(new CreationPlanBuilder())->build($missingRacial, $restrictedCatalog, $catalogForLevels['configuration'])['valid'],
    'Required racial choice cannot be skipped');
$duplicateRacial = $racialPayload;
$duplicateRacial['proficiencies'] = [$proficiencyIds['two-handed']];
check(!(new CreationPlanBuilder())->build($duplicateRacial, $restrictedCatalog, $catalogForLevels['configuration'])['valid'],
    'Racial and ordinary choices cannot grant the same proficiency twice');
$automaticRacial = (new CreationPayload())->normalize($magicPayload);
$automaticRacial['racial_proficiency_ids'] = [$proficiencyIds['magic-weapon']];
$magicCatalog['races'][$startRace]['rules']['proficiency_choice_mode'] = 'all';
$magicCatalog['races'][$startRace]['rules']['proficiency_choice_count'] = 1;
check(!(new CreationPlanBuilder())->build($automaticRacial, $magicCatalog, $catalogForLevels['configuration'])['valid'],
    'Already automatic magic proficiency cannot consume racial choice');
$racialOptions = (new \Maxcuso\Roleplay\Creation\CreationOptions())->build($racialPayload, $restrictedCatalog, $restrictedPlan);
check(count($racialOptions['racial_proficiency_ids']) === 2
    && !array_diff($racialOptions['racial_proficiency_ids'], [$proficiencyIds['one-handed'], $proficiencyIds['two-handed']]),
    'Wizard receives only server-defined racial proficiency pool');
$levelOptions = (new \Maxcuso\Roleplay\Creation\CreationOptions())->build(
    (new CreationPayload())->normalize($higherPayload), $higherCatalog, $higherPlan);
$thirdSlot = array_values(array_filter($levelOptions['training_slots'], fn ($row) =>
    $row['kind'] === 'skill' && $row['attribute_id'] === (int) $attributeDefinitions['charisma']->id && $row['ordinal'] === 2))[0];
check(in_array(['id' => $startSkill, 'name' => 'Стартовый навык', 'level' => 3], $thirdSlot['options'], true),
    'Editing third skill choice offers rank III by evaluating its prefix, not final max rank');
$blankPayload = (new CreationPayload())->normalize(['first_name' => 'Имя', 'age' => 20, 'race_id' => $startRace]);
$blankPlan = (new CreationPlanBuilder())->build($blankPayload, $catalogForLevels['definitions'], $catalogForLevels['configuration']);
$blankOptions = (new \Maxcuso\Roleplay\Creation\CreationOptions())->build($blankPayload, $catalogForLevels['definitions'], $blankPlan);
check($blankOptions['step_validity']['basics'] && !$blankOptions['step_validity']['attributes'],
    'Basic step does not require completing future attribute distribution');
$reviewData = json_decode(json_encode((new \Maxcuso\Roleplay\Creation\CreationReview())->build(
    $normalPayload, $catalogForLevels['definitions'], $view['preview'], $catalogForLevels['revision'])), true);
check($reviewData['calculation']['stats']['mana_max']['value'] === 10
    && $reviewData['calculation']['inventory_weight']['value'] === 3
    && count($reviewData['snapshot']['inventory']) === 3 && !$reviewData['snapshot']['equipment'],
    'Wizard review uses shared profile calculator with weight and no automatic equipment');

$linkRace = $create('races', ['name' => 'Раса со ссылкой', 'rules_url' => 'https://questpost.ru/d/29-pravila-rolevoi',
    'rules' => ['proficiency_choice_mode' => 'list', 'proficiency_choice_count' => 1,
        'proficiency_choice_ids' => [$proficiencyIds['one-handed'], $proficiencyIds['two-handed']]]]);
$api('DELETE', 'proficiencies', ['revision' => $revision()], ['id' => $proficiencyIds['one-handed']], 409);
$api('POST', 'races', ['revision' => $revision(), 'fields' => ['name' => 'Небезопасная ссылка', 'rules_url' => 'javascript:alert(1)']], [], 422);
// После изменения справочника сначала читаем новую версию, сохраняя те же намерения игрока.
$view = $creationApi('draft', 'GET')['data'];
$view = $creationApi('draft', 'PUT', $saveBody($view, $payload))['data'];

// Только вымышленные данные временного сценария для проверки собранного клиентского мастера.
file_put_contents(__DIR__.'/.runtime/creation-view.json', json_encode($view, JSON_THROW_ON_ERROR));
$key = '11111111-1111-4111-8111-111111111111';
$body = $command($view, $key);

// Ошибки игрового выбора не уничтожают сохранённый черновик.
$catalogData = (new CreationCatalog($db))->read(fn (array $catalog) => $catalog);
$planBuilder = new CreationPlanBuilder();
$normalizer = new CreationPayload();
$bad = $payload;
$bad['base_attributes']['magic'] = 2;
check(!$planBuilder->build($normalizer->normalize($bad), $catalogData['definitions'], $catalogData['configuration'])['valid'],
    'Forged starting distribution is rejected');
$bad = $payload;
$bad['skills'] = [$startSkill, $startSkill];
check(!$planBuilder->build($normalizer->normalize($bad), $catalogData['definitions'], $catalogData['configuration'])['valid'],
    'Cannot consume more skill choices than acquired');
$bad = $payload;
$bad['racial_spell_ids'] = [$ordinarySpell];
check(!$planBuilder->build($normalizer->normalize($bad), $catalogData['definitions'], $catalogData['configuration'])['valid'],
    'Racial requirement exemption does not bypass its configured spell pool');
$bad = $payload;
$bad['spell_ids'] = [$ordinarySpell, $ordinarySpellTwo];
check(!$planBuilder->build($normalizer->normalize($bad), $catalogData['definitions'], $catalogData['configuration'])['valid'],
    'Ordinary starting spells still require Magic 3');
$bad = $payload;
$bad['loadout']['clothing'] = ['clothes'];
check(!$planBuilder->build($normalizer->normalize($bad), $catalogData['definitions'], $catalogData['configuration'])['valid'],
    'Player cannot select automatic group to duplicate its items');
$creationApi('draft', 'PUT', $saveBody($view, $payload + ['gold' => 100000]), 422);
$creationApi('create', 'POST', $body + ['plan' => $view['preview']], 400);

// Доступ к чужому черновику: тестовый объект существует только в памяти, таблица users не меняется.
$other = clone $admin;
$other->id = 2147480000;
$otherView = $drafts->read($other);
check($otherView['draft']['id'] === 0, 'Different actor never reads saved private draft');
$otherBody = $body;
try {
    $creator->create($other, $otherBody);
    throw new RuntimeException('Foreign draft accepted');
} catch (\Maxcuso\Roleplay\Api\ApiProblem $problem) {
    check($problem->problemCode === 'creation_draft_conflict', 'Foreign draft rejected at creation');
}
foreach (['draft', 'create', 'configuration'] as $action) {
    try {
        $request = RequestUtil::withActor((new ServerRequest())->withMethod('GET'), new \Flarum\User\User());
        $controllers[$action]->handle($request);
        throw new RuntimeException('Guest creation access accepted');
    } catch (\Flarum\User\Exception\PermissionDeniedException) {
        check(true, 'Guest denied: '.$action);
    }
}

// Изменение настроек делает подтверждение устаревшим. Старый черновик остаётся доступным.
$configStore->save($admin, $revision(), $config);
$creationApi('create', 'POST', $body, 409);
$current = $drafts->read($admin);
$view = $creationApi('draft', 'PUT', $saveBody($current, $payload))['data'];
$body = $command($view, $key);

// Искусственная ошибка только на временной таблице: после неё вся выдача обязана откатиться.
$trigger = $testPrefix.'creation_item_failure';
if (!preg_match('/^rpn_[0-9a-f]{6}_creation_item_failure$/D', $trigger)) throw new RuntimeException('Unsafe test trigger');
$table = $db->getQueryGrammar()->wrapTable('roleplay_character_items');
$db->statement("CREATE TRIGGER $trigger BEFORE INSERT ON $table FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'creation test failure'");
$counts = [];
foreach (['characters', 'operations', 'character_attributes', 'character_skills', 'character_perks', 'character_spells',
    'character_choices', 'character_balances', 'character_events', 'character_items'] as $name) {
    $counts[$name] = $db->table('roleplay_'.$name)->count();
}
try {
    try {
        $creator->create($admin, $body);
        throw new RuntimeException('Forced database error did not happen');
    } catch (\Illuminate\Database\QueryException) {
        foreach ($counts as $name => $count) {
            check($db->table('roleplay_'.$name)->count() === $count, 'Failed creation rolls back '.$name);
        }
        check($db->table('roleplay_creation_drafts')->where('id', $view['draft']['id'])->exists(),
            'Failed creation keeps draft for retry');
    }
} finally {
    $db->statement("DROP TRIGGER IF EXISTS $trigger");
}
$beforeTopics = $db->table('roleplay_test_topics')->count();
$topics->fail = true;
try {
    $creator->create($admin, $body);
    throw new RuntimeException('Topic failure was ignored');
} catch (\RuntimeException $error) {
    check($error->getMessage() === 'Topic creation test failure'
        && $db->table('roleplay_test_topics')->count() === $beforeTopics,
        'Topic error rolls back its own write and character grants');
    foreach ($counts as $name => $count) check($db->table('roleplay_'.$name)->count() === $count, 'Topic failure rolls back '.$name);
} finally { $topics->fail = false; }
$created = $creationApi('create', 'POST', $body, 201)['data'];
$createdId = $created['character_id'];
$createdHeader = $db->table('roleplay_characters')->find($createdId);
check($createdHeader->avatar === $testAvatar, 'Character creation persists the avatar from its saved draft');
$createdTopic = $db->table('roleplay_test_topics')->find($createdHeader->discussion_id);
check($createdHeader->mechanics_status === 'review' && $createdHeader->literature_status === 'draft',
    'Mechanics starts under review; literature remains a draft until submitted');
check((int) $createdTopic->user_id === (int) $admin->id && (int) $createdTopic->tag_id === 7
    && str_contains($createdTopic->content, 'Имя: Новый') && str_contains($createdTopic->content, 'Биография:'),
    'Creation topic belongs to player and contains configured rendered template');
$repeated = $creationApi('create', 'POST', $body, 201)['data'];
check($created === $repeated && $db->table('roleplay_operations')->where('id', $key)->count() === 1,
    'Retry returns same character without duplicate grants');
check($db->table('roleplay_test_topics')->count() === $beforeTopics + 1, 'Exact creation retry never duplicates topic');
check(!$db->table('roleplay_creation_drafts')->where('user_id', $admin->id)->exists(), 'Success removes draft');
$createdSnapshot = $snapshotBuilder->build($createdId);
check($createdSnapshot->character['age'] === 20 && $createdSnapshot->character['status'] === 'creating'
    && !$createdSnapshot->character['literature_approved'], 'Creation preserves age and starts review without approval');
check($createdSnapshot->balances === ['experience_active' => 0, 'experience_total' => 0, 'gold' => 10],
    'Balances are separate and typed; starting experience is zero');
check(count($createdSnapshot->choices) === 4 && count(array_filter($createdSnapshot->choices,
    fn (array $choice) => $choice['target_id'] === null)) === 2, 'Stored unspent choices survive snapshot reload');
check(count($createdSnapshot->inventory) === 3 && array_sum(array_column($createdSnapshot->inventory, 'quantity')) === 6
    && !$createdSnapshot->equipment, 'Automatic/selected items combine and split into legal stored stacks');
check(count($createdSnapshot->perks) === 2 && $createdSnapshot->perks[0]['rarity'] === 'common'
    && $createdSnapshot->perks[0]['definition']['rarity'] === 'epic', 'Starting rarity override leaves catalogue rarity unchanged');
check($db->table('roleplay_character_events')->where('character_id', $createdId)->count() === 1,
    'One immutable creation event records initial balances and grants');
$changedBody = $body;
$changedBody['draft_revision']++;
$creationApi('create', 'POST', $changedBody, 409);
$creationApi('draft', 'PUT', $saveBody($drafts->read($admin), $payload), 409);
$config['enabled'] = false;
$configStore->save($admin, $revision(), $config);
check($creator->create($admin, $body) === $created, 'Successful replay still works when creation later closes');
$db->table('roleplay_characters')->where('id', $createdId)->update(['status' => 'dead']);
$fresh = $drafts->read($admin);
$view = $drafts->save($admin, $saveBody($fresh, $payload));
$closedBody = $command($view, '22222222-2222-4222-8222-222222222222');
$creationApi('create', 'POST', $closedBody, 403);
// Проверяем не только чистый план, но и сохранение расового владения через реальный API.
$config['enabled'] = true;
$configStore->save($admin, $revision(), $config);
$freeRace = $create('races', ['name' => 'Раса со свободным владением', 'enabled' => true,
    'rules' => ['proficiency_choice_mode' => 'all', 'proficiency_choice_count' => 1]]);
$freePayload = $payload;
$freePayload['race_id'] = $freeRace;
$freePayload['skills'] = [$startSkill];
$freePayload['racial_spell_ids'] = [];
$freePayload['racial_proficiency_ids'] = [$proficiencyIds['magic-weapon']];
$view = $creationApi('draft', 'GET')['data'];
$view = $creationApi('draft', 'PUT', $saveBody($view, $freePayload))['data'];
check($view['preview']['valid'], 'Stored draft accepts unrestricted racial proficiency');
$freeResult = $creationApi('create', 'POST', $command($view, '33333333-3333-4333-8333-333333333333'), 201)['data'];
$freeSnapshot = $snapshotBuilder->build($freeResult['character_id']);
check($freeSnapshot->attributes['magic']['level'] === 1
    && $freeSnapshot->ownedProficiencyIds() === [$proficiencyIds['magic-weapon']]
    && count(array_filter($freeSnapshot->choices, fn ($row) => $row['target_id'] === null)) === 2,
    'Atomic grants persist racial magic proficiency at Magic 1 and leave normal rights unspent');
echo "Creation draft, plan, atomic grants and replay checks passed.\n";
