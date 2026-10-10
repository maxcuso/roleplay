<?php
// Продолжение сценария временной схемы. Рабочие данные и установка расширения не меняются.
use Maxcuso\Roleplay\Character\Calculation\CharacterCalculationBuilder;
use Maxcuso\Roleplay\Character\State\CharacterSnapshot;
use Maxcuso\Roleplay\Rules\DevelopmentRules;

$magicId = (int) $attributeDefinitions['magic']->id;
$effectDefinitions = [];
foreach ([
    'physiology' => ['mechanic' => 'attribute', 'attribute_id' => $physiologyId],
    'magic' => ['mechanic' => 'attribute', 'attribute_id' => $magicId],
    'mana' => ['mechanic' => 'stat', 'stat' => 'mana_max'],
    'all_attacks' => ['mechanic' => 'stat', 'stat' => 'all_attacks'],
    'weapon' => ['mechanic' => 'stat', 'stat' => 'melee_damage'],
    'ambush' => ['mechanic' => 'stat', 'stat' => 'melee_damage', 'operation' => 'tiers'],
    'skill' => ['mechanic' => 'skill_check', 'skill_id' => $skillId],
    'recovery' => ['mechanic' => 'mana_recovery'],
    'text' => ['mechanic' => 'text'],
] as $key => $fields) {
    $effectDefinitions[$key] = ['name' => 'Эффект '.$key, 'operation' => 'add'] + $fields;
    if (isset($fields['operation'])) $effectDefinitions[$key]['operation'] = $fields['operation'];
}
$api('GET', 'effects', [], [], 404);
$linkEffect = fn (string $key, int|float|null $value, array $extra = []) =>
    ['value' => $value] + $extra + $effectDefinitions[$key];
$perkEffects = [
    $linkEffect('physiology', 3), $linkEffect('magic', 3), $linkEffect('all_attacks', 6),
    $linkEffect('ambush', 1, ['condition' => 'manual', 'condition_text' => 'В засаде']),
    $linkEffect('skill', 2), $linkEffect('recovery', 5), $linkEffect('text', null),
];
$api('PUT', 'perks', ['revision' => $revision(), 'fields' => [
    'name' => 'Перк с эффектами', 'effects' => $perkEffects,
]], ['id' => $perkA]);
$itemEffects = [$linkEffect('mana', 4), $linkEffect('weapon', 3, ['scope' => 'item_attack'])];
$api('PUT', 'items', ['revision' => $revision(), 'fields' => [
    'name' => 'Магическая броня-оружие', 'item_type_id' => $typeId, 'is_magical' => true,
    'is_armor' => true, 'is_melee_weapon' => true, 'melee_proficiency_id' => $ownedProficiency,
    'ignored_damage_units' => 3, 'damage_units' => 6,
    'weight' => 1.25, 'effects' => $itemEffects,
]], ['id' => $itemId]);
$anotherPerk = $create('perks', ['name' => 'Другой носитель', 'effects' => [$linkEffect('magic', 7)]]);
$rows = $api('GET', 'perks')['data']['lookups']['perks'];
$byId = array_column($rows, null, 'id');
check($byId[$perkA]['effects'][1]['value'] === 3 && $byId[$anotherPerk]['effects'][0]['value'] === 7,
    'One definition has different magnitudes on different owners');
check($byId[$perkA]['effects'][6]['value'] === null, 'Text effect has no magnitude');

$before = $revision();
$api('PUT', 'items', ['revision' => $before, 'fields' => [
    'name' => 'Не должно сохраниться', 'item_type_id' => $typeId, 'is_magical' => false, 'effects' => $itemEffects,
]], ['id' => $itemId], 422);
check($revision() === $before && $db->table('roleplay_items')->find($itemId)->name === 'Магическая броня-оружие',
    'Invalid attachment rolls back parent and catalogue revision');
$api('POST', 'perks', ['revision' => $revision(), 'fields' => [
    'name' => 'Ошибка', 'effects' => [$linkEffect('text', 1)],
]], [], 422);
$api('POST', 'perks', ['revision' => $revision(), 'fields' => [
    'name' => 'Ошибка', 'effects' => [$linkEffect('magic', 1.5)],
]], [], 422);
$api('POST', 'perks', ['revision' => $revision(), 'fields' => [
    'name' => 'Ошибка', 'effects' => [$linkEffect('mana', 1, ['condition' => 'unarmed'])],
]], [], 422);
$api('POST', 'perks', ['revision' => $revision(), 'fields' => [
    'name' => 'Ошибка', 'effects' => [$linkEffect('mana', 1, ['operation' => 'tiers'])],
]], [], 422);
// Цель встроенного эффекта тоже защищена от удаления.
$api('DELETE', 'skills', ['revision' => $revision()], ['id' => $skillId], 409);

// Проверяем ключевые согласованные примеры на чтении настоящих SQL-привязок.
$db->table('roleplay_character_attributes')->where('character_id', $characterId)->where('attribute_id', $magicId)
    ->update(['base_value' => 5]); // Записанная расовая прибавка 1: приобретённый уровень 6.
$db->table('roleplay_character_attributes')->where('character_id', $characterId)->where('attribute_id', $physiologyId)
    ->update(['base_value' => 22]); // Расовая прибавка 2: приобретённый уровень 24.
$ordinaryArmor = $create('items', ['name' => 'Обычная броня', 'item_type_id' => $typeId,
    'is_armor' => true, 'ignored_damage_units' => 9]);
$ordinaryInstance = $db->table('roleplay_character_items')->insertGetId([
    'character_id' => $characterId, 'item_id' => $ordinaryArmor, 'quantity' => 1, 'equipped' => true,
]);
$ready = $snapshotBuilder->build($characterId);
$builder = CharacterCalculationBuilder::defaults();

$before = $revision();
$calculated = $builder->calculate($ready);
check($calculated['effective_attributes']['physiology']['level'] === 27
    && $calculated['acquired_attributes']['physiology']['level'] === 24, '24 + 3 gives 27 without changing acquired level');
check($calculated['effective_attributes']['magic']['level'] === 9
    && $calculated['magic_circle'] === 1, 'Magic 6 + 3 changes stats but does not open circle II');
check($calculated['stats']['mana_max']->value === 30 && $calculated['stats']['stamina_max']->value === 62,
    'Attribute effects precede formulas and direct resource bonuses');
check($calculated['stats']['ignored_damage']->value === 30, 'Physiology defense and both magical and ordinary armor add up');
check($calculated['attacks'][0]['damage']->value === 42 && $calculated['attacks'][0]['source']['id'] === $equippedInstance
    && $calculated['attacks'][1]['mode'] === 'magic' && $calculated['attacks'][1]['damage']->value === 23,
    'Item scope uses its real instance and never leaks into the learned spell');
$withoutWeapon = new CharacterSnapshot(...array_replace(get_object_vars($ready), [
    'equipment' => array_values(array_filter($ready->equipment, fn (array $item) => $item['id'] !== $equippedInstance)),
]));
check($builder->calculate($withoutWeapon)['attacks'][0]['damage']->value === 33,
    'Removing weapon restores unarmed damage without scoped item effects');
check($calculated['maximum_damage']['melee_damage']['damage']->value === 42, 'Maximum remains one attack, not sum of weapons');
check($calculated['skill_check_bonuses'][$skillId] === 4 && $ready->skills[0]['level'] === 2,
    'Check effect adds to +1 per rank without advancing skill');
check($calculated['action_bonuses']['mana_recovery']->value === 5, 'Recovery is prepared for actions, not added to maximum mana');
check(count($calculated['effects']) === 9 && $calculated['inventory_weight']->value === 3.75,
    'Stored stack contributes weight but no equipment effects');
check($ready->attributes['magic']['level'] === 6 && $revision() === $before,
    'Calculation does not mutate acquired state or catalogue revision');
check(DevelopmentRules::defaults()->earnedChoices('magic', $calculated['acquired_attributes']['magic']['level'])
    === DevelopmentRules::defaults()->earnedChoices('magic', 6), 'Effects do not earn training choices');
$copy = new CharacterSnapshot(...array_replace(get_object_vars($ready), ['perks' => [$ready->perks[0], $ready->perks[0]]]));
check($builder->calculate($copy)['stats']['mana_max']->value === 30,
    'Duplicated perk reference never applies effects twice');

$withoutProficiencies = new CharacterSnapshot(...array_replace(get_object_vars($ready), ['proficiencies' => []]));
$pending = $builder->calculate($withoutProficiencies);
$weaponEffect = array_values(array_filter($pending['effects'], fn ($effect) => $effect['scope'] === 'item_attack'))[0];
$ambushEffect = array_values(array_filter($pending['effects'], fn ($effect) => $effect['condition'] === 'manual'))[0];
check($weaponEffect['application'] === 'pending_attack' && $ambushEffect['application'] === 'conditional',
    'List distinguishes unavailable attack context from manual conditions');
$secondInstance = $db->table('roleplay_character_items')->insertGetId([
    'character_id' => $characterId, 'item_id' => $itemId, 'quantity' => 1, 'equipped' => true,
]);
$twoItems = $builder->calculate($snapshotBuilder->build($characterId));
check($twoItems['stats']['mana_max']->value === 34 && $twoItems['inventory_weight']->value === 5.0,
    'Different equipped instances stack while stored quantities do not');
check($twoItems['attacks'][0]['damage']->value === 42 && $twoItems['attacks'][1]['damage']->value === 42
    && $twoItems['maximum_damage']['melee_damage']['damage']->value === 42,
    'Two copies keep separate attack IDs, local effects and a maximum instead of summed damage');
$db->table('roleplay_character_items')->where('id', $secondInstance)->delete();

$api('DELETE', 'perks', ['revision' => $revision()], ['id' => $anotherPerk]);
check(!$db->table('roleplay_perks')->where('id', $anotherPerk)->exists(), 'Removing owner removes its embedded effects');
echo "Inline effects and calculation checks passed.\n";
