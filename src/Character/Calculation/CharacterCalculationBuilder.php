<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Character\Calculation;

use Maxcuso\Roleplay\Character\State\CharacterSnapshot;
use Maxcuso\Roleplay\Rules\CalculatedValue;
use Maxcuso\Roleplay\Rules\ModifierCalculation;
use Maxcuso\Roleplay\Rules\EffectRules;
use Maxcuso\Roleplay\Rules\CharacterCalculator;
use Maxcuso\Roleplay\Rules\StatModifier;
use Maxcuso\Roleplay\Rules\StatRules;
use Maxcuso\Roleplay\Rules\DevelopmentConfiguration;

/** Подготовка отделена от SQL и развития. Приобретённое состояние остаётся неизменным. */
final class CharacterCalculationBuilder
{
    public function __construct(
        private readonly CharacterCalculator $calculator,
        private readonly StatRules $rules,
        private readonly CharacterEffectResolver $resolver,
        private readonly CharacterAttackBuilder $attackBuilder,
    ) {}

    public static function defaults(?DevelopmentConfiguration $configuration = null): self
    {
        $rules = StatRules::defaults($configuration);
        return new self(new CharacterCalculator($rules), $rules, new CharacterEffectResolver(), new CharacterAttackBuilder());
    }

    /** Доступные атаки готовим здесь, чтобы вызывающий экран не обходил проверку владений. */
    public function calculate(CharacterSnapshot $snapshot): array
    {
        $baseline = $snapshot->baselineInput();
        $attacks = $this->attackBuilder->build($snapshot);
        $effects = $this->resolver->resolve($snapshot);
        [$effective, $attributeValues] = $this->effectiveAttributes($snapshot->attributes, $effects);
        $modifiers = $this->statModifiers($effects, $attacks);
        foreach ($snapshot->equipment as $item) {
            if ($item['definition']['is_armor']) {
                $modifiers[] = new StatModifier('ignored_damage', $item['definition']['ignored_damage_units'], [
                    'kind' => 'item', 'id' => $item['id'], 'definition_id' => $item['item_id'], 'label' => $item['name'],
                ]);
            }
        }
        $result = $this->calculator->calculate($effective, $modifiers, $attacks,
            $baseline['inventory'], $baseline['skillLevels'], acquiredAttributes: $snapshot->attributes);
        $checks = $this->checkEffects($effects, $snapshot);
        foreach ($result['skill_check_bonuses'] as $id => &$bonus) {
            // +1 за ранг сохраняется. Эффект изменяет проверку, но не ранг самого навыка.
            $bonus += $checks['skills'][$id]->value ?? 0;
        }
        unset($bonus);
        return $result + [
            'acquired_attributes' => $snapshot->attributes,
            'effective_attributes' => $effective,
            'attribute_values' => $attributeValues,
            'effects' => $this->presentEffects($effects, $attacks),
            'check_effects' => $checks,
            'action_bonuses' => $this->actionBonuses($effects),
        ];
    }

    private function effectiveAttributes(array $acquired, array $effects): array
    {
        $effective = $acquired;
        $values = [];
        $attributeIds = array_column($acquired, 'id');
        foreach ($effects as $effect) {
            if ($effect['definition']['mechanic'] === 'attribute'
                && !in_array($effect['definition']['attribute_id'], $attributeIds, true)) {
                throw new \DomainException('Эффект ссылается на отсутствующую характеристику.');
            }
        }
        foreach ($acquired as $slug => $attribute) {
            $parts = $attribute['sources'];
            foreach ($effects as $effect) {
                $definition = $effect['definition'];
                if ($effect['condition'] === 'always' && $definition['mechanic'] === 'attribute'
                    && $definition['attribute_id'] === $attribute['id']) {
                    $parts[] = ['value' => $effect['value'], 'source' => $effect['source']];
                }
            }
            // Минимум 1; верхняя граница 25 относится только к приобретённому уровню.
            $values[$slug] = CalculatedValue::sum($parts, minimum: 1);
            $effective[$slug]['level'] = $values[$slug]->value;
            $effective[$slug]['sources'] = $values[$slug]->parts;
        }
        return [$effective, $values];
    }

    private function statModifiers(array $effects, array $attacks): array
    {
        $result = [];
        foreach ($effects as $effect) {
            $definition = $effect['definition'];
            if ($effect['condition'] === 'manual' || $definition['mechanic'] !== 'stat') continue;
            $targets = $definition['stat'] === 'all_attacks' ? $this->rules->statNames('attack') : [$definition['stat']];
            foreach ($targets as $target) {
                if ($effect['scope'] === 'all') {
                    $result[] = $this->modifier($effect, $target);
                    continue;
                }
                foreach ($this->itemAttacks($effect, $attacks) as $attack) {
                    if ($this->rules->attackStat($attack['mode']) === $target) {
                        $result[] = $this->modifier($effect, $target, $attack['id']);
                    }
                }
            }
        }
        return $result;
    }

    private function modifier(array $effect, string $target, ?string $attackId = null): StatModifier
    {
        return new StatModifier($target, $effect['value'], $effect['source'], $attackId,
            $effect['condition'], $effect['definition']['operation'], $effect['definition']['stack_group'] ?? null);
    }

    private function itemAttacks(array $effect, array $attacks): array
    {
        return array_values(array_filter($attacks, fn (array $attack) =>
            ($attack['source']['kind'] ?? null) === 'item' && ($attack['source']['id'] ?? null) === $effect['source']['id']));
    }

    /** Здесь только дополнительные бонусы; бросок и значение характеристики использует будущая проверка. */
    private function checkEffects(array $effects, CharacterSnapshot $snapshot): array
    {
        $parts = ['attributes' => [], 'skills' => []];
        $all = [];
        foreach ($effects as $effect) {
            if ($effect['condition'] !== 'always') continue;
            $definition = $effect['definition'];
            if (!in_array($definition['mechanic'], ['attribute_check', 'skill_check', 'all_checks'], true)) continue;
            $modifier = new StatModifier('check', $effect['value'], $effect['source'],
                operation: $definition['operation'], stackGroup: $definition['stack_group'] ?? null);
            if ($definition['mechanic'] === 'all_checks') {
                $all[] = $modifier;
            } else {
                [$section, $id] = $definition['mechanic'] === 'attribute_check'
                    ? ['attributes', $definition['attribute_id']] : ['skills', $definition['skill_id']];
                $parts[$section][$id][] = $modifier;
            }
        }
        if ($all) {
            // Цели берём из актуального снимка, а не из фиксированного списка в перке.
            foreach ($snapshot->attributes as $attribute) $parts['attributes'][$attribute['id']] ??= [];
            foreach ($snapshot->skills as $skill) $parts['skills'][$skill['id']] ??= [];
        }
        $levels = array_column($snapshot->skills, 'level', 'id');
        foreach ($parts as $kind => &$section) {
            foreach ($section as $id => &$modifiers) {
                // Процент удваивает именно +1 за ранг, не остальные бонусы проверки.
                $base = CalculatedValue::sum([['value' => $kind === 'skills' ? ($levels[$id] ?? 0) : 0,
                    'source' => ['kind' => 'skill_base', 'label' => 'Бонус уровня навыка']]], minimum: null);
                // Общий и адресный бонус проходят один расчёт; группы также не складываются между ними.
                $result = ModifierCalculation::apply($base, [...$all, ...$modifiers], minimum: null);
                $modifiers = CalculatedValue::sum(array_slice($result->parts, 1), minimum: null);
            }
            unset($modifiers);
        }
        unset($section);
        // Для проверки ещё не изученного/нового навыка, которого нет в skills, используем этот резерв.
        $zero = CalculatedValue::sum([], minimum: null);
        return $parts + ['all' => ModifierCalculation::apply($zero, $all, minimum: null)];
    }

    /** Магазин/бой получат числа вместе с источниками. Восстановление не добавляется к максимальной мане. */
    private function actionBonuses(array $effects): array
    {
        $parts = [];
        foreach ($effects as $effect) {
            $mechanic = $effect['definition']['mechanic'];
            if ($effect['condition'] === 'always'
                && in_array($mechanic, ['shop_discount', 'extra_dodges', 'mana_recovery', 'stamina_recovery', 'quest_experience'], true)) {
                $parts[$mechanic][] = ['value' => $effect['value'], 'source' => $effect['source']];
            }
        }
        $result = [];
        foreach ($parts as $mechanic => $values) {
            $result[$mechanic] = CalculatedValue::sum($values, in_array($mechanic, ['shop_discount', 'quest_experience'], true), minimum: null);
        }
        return $result;
    }

    /** Готовый список для будущей кнопки профиля. Условия и внешние действия не выдаём за применённые числа. */
    private function presentEffects(array $effects, array $attacks): array
    {
        foreach ($effects as &$effect) {
            $mechanic = $effect['definition']['mechanic'];
            $attackEffect = $mechanic === 'stat' && EffectRules::attackTarget($effect['definition']['stat']);
            $matchingAttacks = $effect['scope'] === 'item_attack' ? $this->itemAttacks($effect, $attacks) : $attacks;
            if ($attackEffect) {
                $matchingAttacks = array_filter($matchingAttacks, fn (array $attack) =>
                    ($effect['definition']['stat'] === 'all_attacks'
                        || $this->rules->attackStat($attack['mode']) === $effect['definition']['stat'])
                    && ($effect['condition'] !== 'unarmed' || ($attack['unarmed'] ?? false)));
            }
            $effect['application'] = match (true) {
                $effect['condition'] === 'manual' => 'conditional',
                $mechanic === 'text' => 'descriptive',
                in_array($mechanic, ['shop_discount', 'extra_dodges', 'mana_recovery', 'stamina_recovery', 'incoming_damage', 'quest_experience'], true) => 'action',
                $attackEffect && !$matchingAttacks => 'pending_attack',
                $effect['condition'] === 'unarmed' => 'attack_condition',
                default => 'calculated',
            };
        }
        unset($effect);
        return $effects;
    }
}
