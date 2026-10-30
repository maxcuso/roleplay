<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Rules;

/** Один порядок для ресурсов, защиты и атак: числа, проценты, затем ступени 3:1. */
final class ModifierCalculation
{
    public static function apply(CalculatedValue $base, array $modifiers, bool $fractional = false,
        ?CalculatedValue $referenceBase = null, ?int $minimum = 0): CalculatedValue
    {
        // Отдельная база исключает оружие и другие прибавки из бонуса «половина базового».
        $referenceBase ??= $base;
        $modifiers = self::nonStacking($modifiers);
        $parts = $base->parts;
        $groups = ['base_percent' => [], 'percent' => [], 'tiers' => []];
        foreach ($modifiers as $modifier) {
            if ($modifier->operation === 'add') $parts[] = $modifier->contribution();
            else $groups[$modifier->operation][] = $modifier;
        }
        $value = CalculatedValue::sum($parts, $fractional, $minimum);
        foreach ($groups as $operation => $group) {
            if (!$group) continue;
            // Проценты складываются. Итог не зависит от порядка выдачи перков.
            $amount = array_sum(array_map(fn (StatModifier $modifier) => $modifier->value, $group));
            $raw = match ($operation) {
                'base_percent' => $value->value + $referenceBase->value * $amount / 100,
                'percent' => $value->value * (1 + $amount / 100),
                'tiers' => $value->value * (3 ** $amount),
            };
            if (!is_finite((float) $raw) || abs($raw) > 1000000000) throw new \DomainException('Переполнение величины эффекта.');
            $next = $fractional ? round($raw, 3)
                : (int) ($operation === 'tiers' ? floor($raw) : round($raw, 0, PHP_ROUND_HALF_UP));
            CalculatedValue::number($next, $fractional);
            $parts = $value->parts;
            $parts[] = ['value' => $next - $value->value, 'source' => [
                'kind' => 'effect_group',
                'label' => match ($operation) {
                    'base_percent' => 'Прибавка от базового значения',
                    'percent' => 'Процентное изменение',
                    'tiers' => 'Изменение ступени урона',
                },
                'operation' => $operation, 'amount' => $amount,
                'effects' => array_map(fn (StatModifier $modifier) => $modifier->source + ['value' => $modifier->value], $group),
            ]];
            $value = CalculatedValue::sum($parts, $fractional, $minimum);
        }
        return $value;
    }

    /** В одной группе применяется наибольшая прибавка. Без группы бонусы складываются как раньше. */
    private static function nonStacking(array $modifiers): array
    {
        $selected = [];
        $groups = [];
        foreach ($modifiers as $modifier) {
            if ($modifier->stackGroup === null) {
                $selected[] = $modifier;
                continue;
            }
            // Цель и операция независимы: группа урона не отменяет бонус к другой проверке.
            $key = json_encode([$modifier->target, $modifier->operation, $modifier->stackGroup], JSON_THROW_ON_ERROR);
            if (!isset($groups[$key])) {
                $groups[$key] = count($selected);
                $selected[] = $modifier;
            } elseif ($modifier->value > $selected[$groups[$key]]->value) {
                $selected[$groups[$key]] = $modifier;
            }
        }
        return $selected;
    }

}