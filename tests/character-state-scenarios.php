<?php
// Запускается в уже созданной временной схеме check-base-catalogs.php; рабочие персонажи не меняются.
use Maxcuso\Roleplay\Character\State\CharacterStateReader;
use Maxcuso\Roleplay\Character\State\CharacterSnapshotBuilder;
use Maxcuso\Roleplay\Rules\CharacterCalculator;
use Maxcuso\Roleplay\Rules\DevelopmentRules;
use Maxcuso\Roleplay\Rules\StatRules;

$attributeDefinitions = $db->table('roleplay_attributes')->get()->keyBy('slug');
foreach (['physiology' => [4, 2], 'agility' => [3, 0], 'magic' => [2, 1], 'charisma' => [1, 0]] as $slug => [$base, $racial]) {
    $db->table('roleplay_character_attributes')->insert([
        'character_id' => $characterId, 'attribute_id' => $attributeDefinitions[$slug]->id,
        'base_value' => $base, 'racial_bonus' => $racial, 'created_at' => $now, 'updated_at' => $now,
    ]);
}
$db->table('roleplay_character_skills')->insert([
    'character_id' => $characterId, 'skill_id' => $skillId, 'level' => 2,
    'created_at' => $now, 'updated_at' => $now,
]);
$ownedProficiency = (int) $db->table('roleplay_weapon_proficiencies')->where('attribute_id', $physiologyId)->value('id');
$db->table('roleplay_character_weapon_proficiencies')->insert([
    'character_id' => $characterId, 'proficiency_id' => $ownedProficiency,
    'created_at' => $now, 'updated_at' => $now,
]);
$equippedInstance = $db->table('roleplay_character_items')->insertGetId([
    'character_id' => $characterId, 'item_id' => $itemId, 'quantity' => 1, 'equipped' => true,
]);
$stackInstance = $db->table('roleplay_character_items')->insertGetId([
    'character_id' => $characterId, 'item_id' => $itemId, 'quantity' => 2, 'equipped' => false,
]);
$db->table('roleplay_character_items')->insert([
    'character_id' => $characterTwo, 'item_id' => $itemId, 'quantity' => 1, 'equipped' => true,
]);
$db->table('roleplay_character_perks')->where('character_id', $characterId)->update([
    'source_context' => json_encode(['quest_name' => 'Первый квест'], JSON_UNESCAPED_UNICODE),
]);

$stateReader = new CharacterStateReader($db);
$snapshotBuilder = new CharacterSnapshotBuilder($stateReader, DevelopmentRules::defaults());
$snapshot = $snapshotBuilder->build($characterId);
check($snapshot->character['revision'] === 2 && $snapshot->catalogRevision === $revision(), 'Snapshot keeps character and catalogue revisions separately');
check($snapshot->attributes['physiology']['level'] === 6 && $snapshot->attributes['magic']['level'] === 3,
    'Snapshot combines recorded base and racial addition');
check($snapshot->missingAttributes === [] && $snapshot->skills[0]['level'] === 2,
    'Snapshot contains all attributes and the current learned skill level');
check($snapshot->ownedProficiencyIds() === [$ownedProficiency] && !isset($snapshot->proficiencies[0]['level']),
    'Learned proficiency stays binary');
check($snapshot->ownedPerkIds() === [$perkA]
    && $snapshot->perks[0]['source']['context']['quest_name'] === 'Первый квест',
    'Perk has one grant source with parsed context');
check(count($snapshot->inventory) === 2 && count($snapshot->equipment) === 1
    && $snapshot->equipment[0]['id'] === $equippedInstance,
    'Inventory includes equipped instance once and excludes another character inventory');
check($snapshotBuilder->build($characterTwo)->perks[0]['source']['id'] === $sourceId,
    'Same perk keeps a different source for another character');

// Современные настройки справочников не заменяют записанные при создании расовые прибавки.
$changedRules = $rules;
$changedRules['fixed_bonuses'] = ['physiology' => 9];
$api('PUT', 'races', ['revision' => $revision(), 'fields' => ['name' => 'Изменённая раса', 'rules' => $changedRules]], ['id' => $richRace]);
$api('PUT', 'attributes', ['revision' => $revision(), 'fields' => ['name' => 'Физиология', 'icon' => 'fas fa-heart']], ['id' => $physiologyId]);
foreach (['skills' => $skillId, 'proficiencies' => $ownedProficiency, 'perks' => $perkA,
    'sources' => $questSource, 'items' => $itemId, 'item-types' => $typeId] as $key => $id) {
    $api('PATCH', $key, ['revision' => $revision(), 'enabled' => false], ['id' => $id]);
}
$snapshot = $snapshotBuilder->build($characterId);
check($snapshot->attributes['physiology']['level'] === 6 && $snapshot->attributes['physiology']['racial_bonus'] === 2
    && $snapshot->character['race']['name'] === 'Изменённая раса',
    'Race edit updates metadata but preserves recorded racial addition');
check($snapshot->attributes['physiology']['icon'] === 'fas fa-heart', 'Attribute icon comes from the current catalogue');
check(!$snapshot->skills[0]['definition']['enabled'] && !$snapshot->proficiencies[0]['definition']['enabled']
    && !$snapshot->perks[0]['definition']['enabled'] && !$snapshot->perks[0]['source']['definition']['enabled']
    && !$snapshot->inventory[0]['definition']['enabled'] && !$snapshot->inventory[0]['type']['enabled']
    && !$snapshot->spells[0]['path']['enabled'],
    'Disabled definitions remain readable for already owned entities');
foreach (['skills' => $skillId, 'proficiencies' => $ownedProficiency] as $key => $id) {
    $api('DELETE', $key, ['revision' => $revision()], ['id' => $id], 409);
}

// Калькулятор переиспользуется без SQL. Снимок пока готовит только базовый ввод, не эффекты и атаки.
$calculator = new CharacterCalculator(StatRules::defaults());
$baseline = $calculator->calculate(...$snapshot->baselineInput());
check($baseline['inventory_weight']->value === 3.75, 'Equipped instance and two stored items weigh 3.75 without double counting');
check($baseline['stats']['mana_max']->value === 14 && $baseline['stats']['stamina_max']->value === 20,
    'Baseline resources use recorded final attributes');
check($baseline['skill_check_bonuses'][$skillId] === 2, 'Skill check bonus equals its current level');
check($snapshot->jsonSerialize()['pending_calculation'] === ['effects', 'attack_modes'],
    'Snapshot delegates effects and attacks to calculation preparation');
$expectInvalidState = function (callable $action, string $label): void {
    try {
        $action();
    } catch (\DomainException) {
        check(true, $label);
        return;
    }
    throw new RuntimeException('Invalid state was accepted: '.$label);
};
$incomplete = $snapshotBuilder->build($characterTwo);
check(count($incomplete->missingAttributes) === 4, 'Incomplete state reports missing attributes');
$expectInvalidState(fn () => $incomplete->baselineInput(), 'Missing levels are not silently replaced with level one');

$state = $stateReader->read($characterId);
foreach ([
    'Foreign character row rejected' => function (array &$copy) use ($characterTwo): void {
        $copy['inventory'][0]['character_id'] = $characterTwo;
    },
    'Duplicate grant rejected instead of silently choosing a source' => function (array &$copy): void {
        $copy['perks'][] = $copy['perks'][0];
    },
    'Repeated inventory instance rejected' => function (array &$copy): void {
        $copy['inventory'][] = $copy['inventory'][0];
    },
    'Missing definition rejected' => function (array &$copy): void {
        $copy['definitions']['skills'] = [];
    },
    'Attribute above 25 rejected' => function (array &$copy): void {
        $copy['attributes'][0]['base_value'] = 25;
        $copy['attributes'][0]['racial_bonus'] = 1;
    },
    'Skill above III rejected' => function (array &$copy): void {
        $copy['skills'][0]['level'] = 4;
    },
    'Small negative weight rejected before rounding' => function (array &$copy) use ($itemId): void {
        $copy['definitions']['items'][$itemId]['weight'] = -0.0001;
    },
    'Equipped stack rejected' => function (array &$copy): void {
        $copy['inventory'][0]['quantity'] = 2;
    },
] as $label => $corrupt) {
    $copy = $state;
    $corrupt($copy);
    $expectInvalidState(fn () => $snapshotBuilder->fromState($copy), $label);
}
$withoutWeight = $state;
$withoutWeight['definitions']['items'][$itemId]['weight'] = null;
check($snapshotBuilder->fromState($withoutWeight)->inventory[0]['weight'] === 0.0, 'Absent weight defaults to zero');

// Ограничения схемы страхуют запись; не только PHP защищает от дублей.
foreach ([
    ['character_attributes', ['character_id' => $characterId, 'attribute_id' => $physiologyId, 'base_value' => 4,
        'racial_bonus' => 0, 'created_at' => $now, 'updated_at' => $now]],
    ['character_skills', ['character_id' => $characterId, 'skill_id' => $skillId, 'level' => 2,
        'created_at' => $now, 'updated_at' => $now]],
    ['character_weapon_proficiencies', ['character_id' => $characterId, 'proficiency_id' => $ownedProficiency,
        'created_at' => $now, 'updated_at' => $now]],
    ['character_perks', ['character_id' => $characterId, 'perk_id' => $perkA, 'source_id' => $sourceId, 'source_context' => '{}']],
    ['character_skills', ['character_id' => $characterTwo, 'skill_id' => $skillId, 'level' => 4,
        'created_at' => $now, 'updated_at' => $now]],
    ['character_attributes', ['character_id' => $characterTwo, 'attribute_id' => $physiologyId, 'base_value' => 0,
        'racial_bonus' => 1, 'created_at' => $now, 'updated_at' => $now]],
    ['character_attributes', ['character_id' => $characterTwo, 'attribute_id' => $physiologyId, 'base_value' => 1,
        'racial_bonus' => -2, 'created_at' => $now, 'updated_at' => $now]],
    ['character_items', ['character_id' => $characterId, 'item_id' => $itemId, 'quantity' => 2, 'equipped' => true]],
] as [$table, $invalid]) {
    try {
        $db->table('roleplay_'.$table)->insert($invalid);
        throw new RuntimeException('Database accepted invalid character state: '.$table);
    } catch (\Illuminate\Database\QueryException) {
        check(true, 'Database rejects duplicate or invalid state in '.$table);
    }
}
$beforeRead = [$revision(), $db->table('roleplay_characters')->find($characterId)->revision];
$snapshotBuilder->build($characterId);
check($beforeRead === [$revision(), $db->table('roleplay_characters')->find($characterId)->revision],
    'Snapshot reading does not change stored versions');
echo "Character state checks passed.\n";