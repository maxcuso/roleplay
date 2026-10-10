<?php

declare(strict_types=1);

// Чистые сценарии: никаких записей в БД и изменения рабочих персонажей.
spl_autoload_register(function (string $class): void {
    $prefix = 'Maxcuso\\Roleplay\\';
    if (str_starts_with($class, $prefix)) {
        require_once __DIR__.'/../src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
    }
});
use Maxcuso\Roleplay\Character\Calculation\CharacterAttackBuilder;
use Maxcuso\Roleplay\Character\Calculation\CharacterCalculationBuilder;
use Maxcuso\Roleplay\Character\State\CharacterSnapshot;

function check(bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
    echo "OK: $message\n";
}
function snapshot(array $inventory = [], array $proficiencyIds = [], array $spells = []): CharacterSnapshot {
    $attributes = [];
    foreach (['physiology' => 10, 'agility' => 10, 'magic' => 6, 'charisma' => 1] as $slug => $level) {
        $attributes[$slug] = ['id' => count($attributes) + 1, 'name' => $slug, 'level' => $level,
            'sources' => [['value' => $level, 'source' => ['kind' => 'attribute_base', 'label' => $slug]]]];
    }
    return new CharacterSnapshot(1, [], $attributes, [],
        array_map(fn (int $id) => ['id' => $id], $proficiencyIds), [], $spells, $inventory,
        array_values(array_filter($inventory, fn (array $item) => $item['equipped'])), []);
}
function item(int $instance, array $definition = [], bool $equipped = true): array {
    return ['id' => $instance, 'item_id' => 100, 'name' => 'Предмет', 'quantity' => 1,
        'weight' => 1, 'equipped' => $equipped, 'definition' => $definition + [
            'is_melee_weapon' => false, 'is_ranged_weapon' => false, 'is_magic_weapon' => false,
            'is_armor' => false, 'is_magical' => false, 'enabled' => false,
            'melee_proficiency_id' => null, 'ranged_proficiency_id' => null, 'magic_proficiency_id' => null,
            'damage_units' => 0, 'ignored_damage_units' => 0, 'effects' => [],
        ]];
}
function spell(int $id, int $damage): array {
    return ['id' => $id, 'grant_id' => $id + 100, 'name' => 'Заклинание',
        'path' => ['id' => 1, 'enabled' => false],
        'definition' => ['magic_path_id' => 1, 'level' => 5, 'damage_units' => $damage,
            'mana_cost' => 999, 'enabled' => false]];
}
$builder = new CharacterAttackBuilder();
$calculator = CharacterCalculationBuilder::defaults();
$empty = snapshot();
$available = $builder->build($empty);
check(count($available) === 1 && $available[0]['unarmed'], 'No weapon gives one unarmed option');
$cloak = item(10, ['is_magical' => true, 'damage_units' => 27]);
$armor = item(11, ['is_armor' => true, 'ignored_damage_units' => 3]);
$result = $calculator->calculate(snapshot([$cloak, $armor]));
check(count($result['attacks']) === 1 && $result['attacks'][0]['id'] === 'unarmed'
    && $result['attacks'][0]['damage']->value === 9, 'Cloak/armor are not weapons despite damage or magical flag');
check($result['stats']['ignored_damage']->value === 6, 'Ordinary armor still adds separate defense');

$sword = item(20, ['is_melee_weapon' => true, 'melee_proficiency_id' => 1, 'damage_units' => 6]);
$result = $calculator->calculate(snapshot([$sword]));
check(!$result['attacks'] && $result['maximum_damage']['melee_damage'] === null,
    'Equipped unusable weapon is excluded and also blocks unarmed fallback');
$stored = $sword;
$stored['equipped'] = false;
check($builder->build(snapshot([$stored]))[0]['id'] === 'unarmed', 'Stored weapon never blocks unarmed option');

$enchanted = item(21, ['is_melee_weapon' => true, 'melee_proficiency_id' => 1,
    'is_magic_weapon' => true, 'magic_proficiency_id' => 2, 'damage_units' => 6, 'is_magical' => true]);
$ordinaryOnly = $builder->build(snapshot([$enchanted], [1]));
$magicOnly = $builder->build(snapshot([$enchanted], [2]));
check(count($ordinaryOnly) === 1 && $ordinaryOnly[0]['mode'] === 'melee'
    && count($magicOnly) === 1 && $magicOnly[0]['mode'] === 'magic',
    'Enchanted sword independently requires ordinary and magical proficiency');
$both = $calculator->calculate(snapshot([$enchanted], [1, 2]));
check(count($both['attacks']) === 2 && $both['attacks'][0]['id'] !== $both['attacks'][1]['id']
    && $both['attacks'][0]['source']['id'] === $both['attacks'][1]['source']['id'],
    'One instance has distinct ordinary/magical attacks but a shared item source');
check($both['maximum_damage']['melee_damage']['damage']->value === 15
    && $both['maximum_damage']['magic_damage']['damage']->value === 9,
    'Each mode uses its own attribute without summing the other mode');

$thrown = item(30, ['is_ranged_weapon' => true, 'ranged_proficiency_id' => 3, 'damage_units' => 3]);
$result = $calculator->calculate(snapshot([$thrown], [3]));
check(count($result['attacks']) === 2 && $result['attacks'][0]['stat'] === 'ranged_damage'
    && $result['attacks'][0]['damage']->value === 12, 'Agility has one option and one score for thrown/ranged usage');
$secondSword = item(22, ['is_melee_weapon' => true, 'melee_proficiency_id' => 1, 'damage_units' => 9]);
$result = $calculator->calculate(snapshot([$sword, $secondSword], [1]));
check(count($result['attacks']) === 2 && $result['maximum_damage']['melee_damage']['damage']->value === 18,
    'Two weapons are separate options; maximum is not their sum');

$result = $calculator->calculate(snapshot([], [], [spell(1, 9), spell(2, 0)]));
check(count($result['attacks']) === 2 && $result['maximum_damage']['magic_damage']['damage']->value === 12,
    'Learned damaging spell survives disabled path/high circle/cost; non-damaging spell creates no attack');
check($result['attacks'][1]['source']['definition_id'] === 1 && $result['attacks'][1]['source']['id'] === 101,
    'Spell source distinguishes its catalogue definition and owned grant');
check($result['magic_circle'] === 1, 'Racial spell does not raise acquired magic circle');

$withUnarmedBonus = new CharacterSnapshot(...array_replace(get_object_vars($empty), [
    'perks' => [['id' => 1, 'grant_id' => 1, 'name' => 'Рукопашный бой', 'source' => [],
        'definition' => ['effects' => [['value' => 3, 'condition' => 'unarmed', 'scope' => 'all',
            'mechanic' => 'stat', 'stat' => 'melee_damage', 'operation' => 'add',
            'name' => 'Без оружия', 'description' => null, 'icon' => null]]]]],

]));
check($calculator->calculate($withUnarmedBonus)['attacks'][0]['damage']->value === 12,
    'Unarmed conditional bonus applies when weapon is absent');
$withSwordBonus = new CharacterSnapshot(...array_replace(get_object_vars($withUnarmedBonus), [
    'inventory' => [$sword], 'equipment' => [$sword], 'proficiencies' => [['id' => 1]],
]));
check($calculator->calculate($withSwordBonus)['attacks'][0]['damage']->value === 15,
    'Unarmed conditional bonus never applies to equipped weapon');

// Разные перки одной группы не усиливают друг друга; оружие ближнего боя исключает безоружный бонус.
$halfEffect = ['value' => 50, 'condition' => 'unarmed', 'condition_text' => null, 'scope' => 'all',
    'mechanic' => 'stat', 'stat' => 'melee_damage', 'operation' => 'base_percent',
    'stack_group' => 'unarmed-base-damage', 'name' => null, 'description' => null, 'icon' => null];
$grouped = new CharacterSnapshot(...array_replace(get_object_vars($empty), [
    'perks' => array_map(fn ($id) => ['id' => $id, 'grant_id' => $id, 'name' => 'Перк '.$id, 'source' => [],
        'definition' => ['effects' => [$halfEffect]]], [10, 11, 12, 13]),
]));
check($calculator->calculate($grouped)['attacks'][0]['damage']->value === 14,
    'Inline effects preserve non-stacking group across different perks');
$armedGrouped = new CharacterSnapshot(...array_replace(get_object_vars($grouped), [
    'inventory' => [$sword], 'equipment' => [$sword], 'proficiencies' => [['id' => 1]],
]));
check($calculator->calculate($armedGrouped)['attacks'][0]['damage']->value === 15,
    'Grouped base percentage is absent on weapon attack');

// Проверяем итоговый урон, а не только наличие служебной атаки: прочее оружие
// сохраняет бонус кулакам, но не получает этот бонус к собственной атаке.
$staff = item(31, ['is_magic_weapon' => true, 'magic_proficiency_id' => 2, 'damage_units' => 3]);
foreach ([$thrown, $staff] as $allowedWeapon) {
    $state = new CharacterSnapshot(...array_replace(get_object_vars($grouped), [
        'inventory' => [$allowedWeapon], 'equipment' => [$allowedWeapon], 'proficiencies' => [['id' => 2], ['id' => 3]],
    ]));
    $result = $calculator->calculate($state);
    check($result['maximum_damage']['melee_damage']['damage']->value === 14
        && $result['attacks'][1]['id'] === 'unarmed', 'Non-melee weapon preserves unarmed base-percentage bonus');
    check($result['attacks'][0]['damage']->value === ($allowedWeapon['definition']['is_magic_weapon'] ? 6 : 12),
        'Non-melee weapon attack never receives unarmed bonus');
}
$mixed = new CharacterSnapshot(...array_replace(get_object_vars($grouped), [
    'inventory' => [$thrown, $enchanted], 'equipment' => [$thrown, $enchanted], 'proficiencies' => [['id' => 2], ['id' => 3]],
]));
check($calculator->calculate($mixed)['maximum_damage']['melee_damage'] === null,
    'Mixed melee/magic weapon blocks unarmed bonus even without melee proficiency');
$skillEffect = ['value' => 100, 'condition' => 'always', 'condition_text' => null, 'scope' => 'all',
    'mechanic' => 'skill_check', 'stat' => null, 'skill_id' => 8, 'operation' => 'base_percent',
    'name' => null, 'description' => null, 'icon' => null];
$skilled = new CharacterSnapshot(...array_replace(get_object_vars($empty), [
    'skills' => [['id' => 8, 'level' => 2]],
    'perks' => [['id' => 14, 'grant_id' => 14, 'name' => 'Бонус проверки', 'source' => [],
        'definition' => ['effects' => [$skillEffect, array_replace($skillEffect, ['operation' => 'add', 'value' => 3])]]]],
]));
check($calculator->calculate($skilled)['skill_check_bonuses'][8] === 7,
    'Snapshot doubles rank bonus without changing acquired skill or other bonuses');
$conditional = new CharacterSnapshot(...array_replace(get_object_vars($empty), [
    'perks' => [['id' => 15, 'grant_id' => 15, 'name' => 'Сопротивление огню', 'source' => [],
        'definition' => ['effects' => [['mechanic' => 'incoming_damage', 'stat' => null, 'value' => -1,
            'operation' => 'tiers', 'condition' => 'manual', 'condition_text' => 'Урон от огня.', 'scope' => 'all']]]]],
]));
$conditionalResult = $calculator->calculate($conditional);
check($conditionalResult['stats']['ignored_damage']->value === $calculator->calculate($empty)['stats']['ignored_damage']->value
    && $conditionalResult['effects'][0]['application'] === 'conditional',
    'Conditional resistance does not become permanent defense');

// «Все характеристики» — один бонус каждой проверке, включая новые навыки.
$allCheckEffect = ['mechanic' => 'all_checks', 'operation' => 'add', 'value' => 2,
    'stat' => null, 'attribute_id' => null, 'skill_id' => null,
    'condition' => 'always', 'condition_text' => null, 'scope' => 'all'];
$withAllChecks = new CharacterSnapshot(...array_replace(get_object_vars($empty), [
    'skills' => [['id' => 8, 'level' => 2], ['id' => 77, 'level' => 1]],
    'perks' => [['id' => 16, 'grant_id' => 16, 'name' => 'Общий бонус', 'source' => [],
        'definition' => ['effects' => [$allCheckEffect,
            array_replace($skillEffect, ['operation' => 'add', 'value' => 3]),
            array_replace($allCheckEffect, ['mechanic' => 'attribute_check', 'attribute_id' => 1, 'value' => 3])]]]],
]));
$allCheckResult = $calculator->calculate($withAllChecks);
check($allCheckResult['skill_check_bonuses'][8] === 7 && $allCheckResult['skill_check_bonuses'][77] === 3,
    'Universal check bonus applies once to existing and newly added skills with +1 per rank');
check($allCheckResult['check_effects']['attributes'][1]->value === 5
    && $allCheckResult['check_effects']['attributes'][4]->value === 2
    && $allCheckResult['check_effects']['all']->value === 2,
    'All attributes receive global bonus; unlearned skills have fallback without duplicate addition');
check($allCheckResult['effective_attributes'] === $empty->attributes
    && $allCheckResult['stats']['mana_max']->value === $calculator->calculate($empty)['stats']['mana_max']->value
    && $withAllChecks->skills[0]['level'] === 2,
    'Global checks do not change attributes, resources or acquired skill levels');
$manualChecks = new CharacterSnapshot(...array_replace(get_object_vars($empty), [
    'perks' => [['id' => 17, 'grant_id' => 17, 'name' => 'Условный общий бонус', 'source' => [],
        'definition' => ['effects' => [array_replace($allCheckEffect,
            ['condition' => 'manual', 'condition_text' => 'Один раз за арку.'])]]]],
]));
$manualCheckResult = $calculator->calculate($manualChecks);
check($manualCheckResult['check_effects']['all']->value === 0
    && $manualCheckResult['effects'][0]['application'] === 'conditional',
    'Conditional all-check effect stays a single visible effect without permanent bonuses');

foreach ([snapshot([$sword, $sword], [1]), snapshot([], [], [spell(1, 9), spell(1, 9)]),
    snapshot([item(40, ['is_melee_weapon' => true])])] as $invalid) {
    try {
        $builder->build($invalid);
        throw new RuntimeException('Invalid attack snapshot accepted');
    } catch (DomainException) {
        check(true, 'Malformed imported attack state rejected');
    }
}
echo "Available attack checks passed; no database or state mutations.\n";
