<?php

declare(strict_types=1);

// Проверки без БД: порядок, округление, уменьшения и переполнение.
spl_autoload_register(function (string $class): void {
    $prefix = 'Maxcuso\\Roleplay\\';
    if (str_starts_with($class, $prefix)) require_once __DIR__.'/../src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
});
use Maxcuso\Roleplay\Rules\CalculatedValue;
use Maxcuso\Roleplay\Rules\ModifierCalculation;
use Maxcuso\Roleplay\Rules\StatModifier;
use Maxcuso\Roleplay\Rules\StatRules;

function check(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "OK: $message\n";
}
$source = ['kind' => 'effect', 'label' => 'Эффект'];
$base = CalculatedValue::sum([['value' => 9, 'source' => ['kind' => 'attribute', 'label' => 'База']]]);
$modifiers = [
    new StatModifier('melee_damage', 6, $source),
    new StatModifier('melee_damage', 20, $source, operation: 'percent'),
    new StatModifier('melee_damage', 1, $source, operation: 'tiers'),
];
$rules = StatRules::defaults();
$rules->validateModifiers($modifiers);
$final = ModifierCalculation::apply($base, $modifiers);
check($final->value === 54, '(9 + 6) * 1.2 * 3 = 54');
check(ModifierCalculation::apply($base, array_reverse($modifiers))->value === 54, 'Order of grants does not change result');
check(array_sum(array_column($final->parts, 'value')) === $final->value, 'Breakdown still sums to final score');
check(ModifierCalculation::apply($base, [
    new StatModifier('melee_damage', 10, $source, operation: 'percent'),
    new StatModifier('melee_damage', 10, $source, operation: 'percent'),
])->value === 11, 'Percentages add before integer rounding');
check(ModifierCalculation::apply($base, [
    new StatModifier('melee_damage', -1, $source, operation: 'tiers'),
])->value === 3, 'One lower damage tier divides by three');
check(CalculatedValue::sum([['value' => -5, 'source' => $source]], minimum: 1)->value === 1, 'Effective attributes have minimum one');
check(CalculatedValue::sum([['value' => -2, 'source' => $source]], minimum: null)->value === -2, 'Negative check bonuses are retained');
try {
    ModifierCalculation::apply($base, [new StatModifier('melee_damage', 100, $source, operation: 'tiers')]);
    throw new RuntimeException('Overflow accepted');
} catch (DomainException) { check(true, 'Tier overflow cannot become zero through integer cast'); }
// Отделяем базу от плоских прибавок и не повторяем бонусы одной группы.
$half = new StatModifier('melee_damage', 50, $source, operation: 'base_percent', stackGroup: 'unarmed-base-damage');
$rules->validateModifiers([$half]);
check(ModifierCalculation::apply($base, [$half])->value === 14, 'Half of base uses nearest integer rounding');
check(ModifierCalculation::apply($base, [new StatModifier('melee_damage', 3, $source), $half])->value === 17,
    'Base percentage excludes other flat bonuses');
check(ModifierCalculation::apply($base, [$half, $half, $half, $half])->value === 14,
    'Four bonuses in one group apply only once');
$stronger = new StatModifier('melee_damage', 100, $source, operation: 'base_percent', stackGroup: 'unarmed-base-damage');
check(ModifierCalculation::apply($base, [$half, $stronger])->value === 18
    && ModifierCalculation::apply($base, [$stronger, $half])->value === 18,
    'Group takes the highest bonus regardless of order');
$weaponTotal = CalculatedValue::sum([...$base->parts, ['value' => 6, 'source' => $source]]);
check(ModifierCalculation::apply($weaponTotal, [$half], referenceBase: $base)->value === 20,
    'Weapon damage does not become part of reference base');
$skillBase = CalculatedValue::sum([['value' => 2, 'source' => $source]]);
check(ModifierCalculation::apply($skillBase, [new StatModifier('check', 3, $source),
    new StatModifier('check', 100, $source, operation: 'base_percent')], minimum: null)->value === 7,
    'Doubled skill bonus excludes other check modifiers');
echo "Effect operation checks passed.\n";