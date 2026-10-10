<?php

declare(strict_types=1);

// Расчёты проверяются в памяти. Flarum, БД, миграции и аккаунты не используются.
spl_autoload_register(function (string $class): void {
    $namespace = 'Maxcuso\\Roleplay\\';
    if (!str_starts_with($class, $namespace)) return;
    $path = __DIR__.'/../src/'.str_replace('\\', '/', substr($class, strlen($namespace))).'.php';
    if (is_file($path)) require_once $path;
});

use Maxcuso\Roleplay\Rules\AttackCalculator;
use Maxcuso\Roleplay\Rules\CalculatedValue;
use Maxcuso\Roleplay\Rules\CharacterCalculator;
use Maxcuso\Roleplay\Rules\DevelopmentRules;
use Maxcuso\Roleplay\Rules\StatModifier;
use Maxcuso\Roleplay\Rules\StatRules;

function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    echo "OK: $message\n";
}
function rejected(callable $action, string $message): void {
    try { $action(); } catch (\DomainException) {
        check(true, $message);
        return;
    }
    throw new RuntimeException('Expected rejection: '.$message);
}
function source(string $kind, string $label, int $id = 1): array {
    return ['kind' => $kind, 'id' => $id, 'label' => $label];
}
$attributes = [
    'physiology' => ['name' => 'Физиология', 'level' => 1],
    'agility' => ['name' => 'Ловкость', 'level' => 1],
    'magic' => ['name' => 'Магия', 'level' => 1],
    'charisma' => ['name' => 'Харизма', 'level' => 1],
];
$rules = StatRules::defaults();
$calculator = new CharacterCalculator($rules);
$attacks = new AttackCalculator($rules);
$first = $calculator->calculate($attributes);
check($first['stats']['mana_max']->value === 10 && $first['stats']['stamina_max']->value === 10, 'Resources begin at 10');
check($first['stats']['carry_capacity']->value == 5 && $first['stats']['ignored_damage']->value === 0, 'First level capacity and defense');
check($first['maximum_damage']['melee_damage'] === null && $first['base_damage']['melee_damage']->value === 1,
    'Missing attacks are distinct from base damage');
foreach ([
    'melee_damage' => ['physiology', [[1,1], [2,3], [8,3], [9,9], [15,9], [16,27]]],
    'ranged_damage' => ['agility', [[2,1], [3,3], [9,3], [10,9], [16,9], [17,27]]],
    'magic_damage' => ['magic', [[4,1], [5,3], [13,3], [14,9]]],
    'ignored_damage' => ['physiology', [[4,0], [5,1], [9,1], [10,3], [16,3], [17,9], [21,9], [22,18], [25,18]]],
] as $stat => [$slug, $cases]) {
    foreach ($cases as [$level, $expected]) {
        $state = $attributes;
        $state[$slug]['level'] = $level;
        check($rules->base($stat, $state)->value === $expected, "$stat threshold $level");
    }
}
foreach ([[2,0], [3,1], [6,1], [7,2], [11,2], [12,3], [15,3], [16,4], [17,4], [18,5], [25,5]] as [$level, $circle]) {
    $state = $attributes;
    $state['magic']['level'] = $level;
    check($rules->magicCircle($state) === $circle, "Magic circle at $level");
}
$state = $attributes;
$state['physiology']['level'] = 9;
$state['magic']['level'] = 25;
$state['agility']['level'] = 3;
$weapons = [
    ['id' => 'sword-a', 'mode' => 'melee', 'damage_units' => 3, 'source' => source('item', 'Меч A', 10)],
    ['id' => 'sword-b', 'mode' => 'melee', 'damage_units' => 9, 'source' => source('item', 'Меч B', 11)],
    ['id' => 'dagger', 'mode' => 'ranged', 'damage_units' => 3, 'source' => source('item', 'Метательный нож', 12)],
    ['id' => 'spell', 'mode' => 'magic', 'damage_units' => 3, 'source' => source('spell', 'Заклинание', 13)],
];
$modifiers = [
    new StatModifier('melee_damage', 6, source('perk', 'Перк')),
    new StatModifier('melee_damage', 3, source('item', 'Свойство меча A', 10), 'sword-a'),
    new StatModifier('mana_max', 2, source('perk', 'Запас маны')),
    new StatModifier('stamina_max', -100, source('effect', 'Истощение')),
    new StatModifier('carry_capacity', 0.125, source('item', 'Сумка')),
];
$inventory = [
    ['id' => 10, 'name' => 'Меч A', 'weight' => 1.25],
    ['id' => 11, 'name' => 'Монеты', 'weight' => 0.1, 'quantity' => 3],
    ['id' => 12, 'name' => 'Без веса', 'weight' => null],
];
$inputSnapshot = serialize([$state, $modifiers, $weapons, $inventory]);
$result = $calculator->calculate($state, $modifiers, $weapons, $inventory, ['unarmed-combat' => 2, 'awareness' => 3]);
check($result['attacks'][0]['damage']->value === 21 && $result['attacks'][1]['damage']->value === 24, 'Weapons calculated separately, scoped bonus stays on its weapon');
check($result['maximum_damage']['melee_damage']['damage']->value === 24
    && $result['maximum_damage']['melee_damage']['id'] === 'sword-b', 'Profile maximum is 24, not weapon sum 45');
check($result['attacks'][2]['damage']->value === 6, 'Thrown weapon in melee uses Agility');
check($result['attacks'][3]['damage']->value === 12, 'Spell attack adds its own damage to Magic');
check($result['stats']['mana_max']->value === 60 && $result['stats']['stamina_max']->value === 0, 'Resource bonuses and zero floor');
check($result['stats']['carry_capacity']->value === 45.125 && $result['inventory_weight']->value === 1.55, 'Fractional capacity and quantity times item weight');
check($result['skill_check_bonuses'] === ['unarmed-combat' => 2, 'awareness' => 3], 'Skill level is total check bonus');
check(serialize([$state, $modifiers, $weapons, $inventory]) === $inputSnapshot, 'Inputs remain unchanged');
$payload = json_decode(json_encode($result, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
check($payload['attacks'][0]['damage']['parts'][2]['source']['kind'] === 'perk'
    && $payload['stats']['stamina_max']['parts'][2]['source']['kind'] === 'limit', 'Serialized breakdown preserves sources and clamp explanation');
foreach (array_merge(array_values($result['stats']), [$result['inventory_weight']], array_column($result['attacks'], 'damage')) as $value) {
    check(abs(array_sum(array_column($value->parts, 'value')) - $value->value) < 0.000001, 'Breakdown adds up to final value');
}
$unarmed = ['id' => 'fists', 'mode' => 'melee', 'unarmed' => true, 'source' => source('attack', 'Без оружия')];
$unarmedBonus = new StatModifier('melee_damage', 3, source('perk', 'Безоружный бонус'), null, 'unarmed');
check($attacks->calculate($unarmed, $state, [$unarmedBonus])['damage']->value === 12
    && $attacks->calculate($weapons[0], $state, [$unarmedBonus])['damage']->value === 12, 'Unarmed condition does not apply to weapon');
$shieldState = $attributes;
$shieldState['physiology']['level'] = 22;
$shield = $calculator->calculate($shieldState, [new StatModifier('ignored_damage', 3, source('item', 'Щит'))]);
$defense = $shield['stats']['ignored_damage'];
check($defense->value === 21, 'Shield adds to double-medium physiology defense');
check($attacks->remainingDamage(27, $defense)->value === 6 && $attacks->remainingDamage(27, $defense)->value === 6,
    'Defense reduces each separate attack and is not spent');
check($attacks->remainingDamage(9, $defense)->value === 0, 'Defense never produces negative incoming damage');
$invalid = $state;
$invalid['magic']['level'] = 26;
rejected(fn () => $calculator->calculate($invalid), 'Attribute above 25');
$invalid = $state;
unset($invalid['magic']);
rejected(fn () => $calculator->calculate($invalid), 'Missing attribute is not invented');
rejected(fn () => $calculator->calculate($state, [new StatModifier('unknown', 1, source('perk', 'Ошибка'))]), 'Unknown bonus target');
rejected(fn () => $calculator->calculate($state, [new StatModifier('melee_damage', 0.5, source('perk', 'Ошибка'))]), 'Fractional damage rejected');
rejected(fn () => new StatModifier('mana_max', NAN, source('perk', 'Ошибка')), 'NaN rejected');
rejected(fn () => new StatModifier('mana_max', INF, source('perk', 'Ошибка')), 'Infinity rejected');
rejected(fn () => $calculator->calculate($state, [new StatModifier('melee_damage', 1, source('perk', 'Ошибка'), 'missing')], $weapons), 'Unknown attack scope');
rejected(fn () => $calculator->calculate($state, [new StatModifier('magic_damage', 1, source('perk', 'Ошибка'), 'sword-a')], $weapons), 'Wrong damage target for scoped attack');
rejected(fn () => $calculator->calculate($state, [new StatModifier('mana_max', 1, source('perk', 'Ошибка'), 'sword-a')], $weapons), 'Attack scope on resource rejected');
rejected(fn () => $calculator->calculate($state, [], [$weapons[0], $weapons[0]]), 'Duplicate attack identifiers');
rejected(fn () => $calculator->calculate($state, [], [], [$inventory[0], $inventory[0]]), 'Duplicate inventory instances');
rejected(fn () => $calculator->calculate($state, [], [], [['id' => 1, 'name' => 'Ошибка', 'weight' => -0.0001]]), 'Negative weight rejected before rounding');
rejected(fn () => $calculator->calculate($state, [], [], [], ['skill' => 4]), 'Invalid skill rank');
rejected(fn () => CalculatedValue::sum([['value' => 1000000000, 'source' => source('perk', 'A')], ['value' => 1, 'source' => source('perk', 'B')]]), 'Sum overflow rejected');
$config = require __DIR__.'/../resources/rules/stats.php';
$config['stats']['melee_damage']['steps'] = [1 => 1, 9 => 9, 2 => 3];
rejected(fn () => new StatRules($config, DevelopmentRules::defaults()), 'Unsorted thresholds rejected');
echo "Stat calculator checks passed; no database or state mutations.\n";