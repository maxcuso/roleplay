<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Rules;

/** Общая точка расчёта для будущего мастера, профиля и предпросмотра повышения. */
final class CharacterCalculator
{
    private readonly AttackCalculator $attacks;

    public function __construct(private readonly StatRules $rules)
    {
        $this->attacks = new AttackCalculator($rules);
    }

    /**
     * attributes: slug => ['name' => название, 'level' => итоговый уровень].
     * modifiers: уже применимые эффекты, без повторов одного перка из разных источников.
     * attacks: только доступные варианты. Принадлежность, экипировку и владения проверяет вызывающая подсистема.
     * inventory: весь переносимый инвентарь, включая экипировку; один экземпляр не передаётся дважды.
     * skillLevels: ID/ключ навыка => текущий уровень (0–III).
     * acquiredAttributes: отдельные приобретённые уровни, если attributes уже включают эффекты.
     * Круг магии всегда берётся из приобретённых уровней, не из бонусов.
     * Здесь нет выдач, списаний, запросов к БД, форматирования или чтения описаний.
     */
    public function calculate(array $attributes, array $modifiers = [], array $attacks = [],
        array $inventory = [], array $skillLevels = [], ?array $acquiredAttributes = null): array
    {
        // Без отдельной базы сохраняем прежний строгий вход: приобретённые уровни не выше 25.
        $acquiredAttributes ??= $attributes;
        $this->rules->validateAttributes($acquiredAttributes);
        $this->rules->validateAttributes($attributes, true);
        if (array_diff_key($attributes, $acquiredAttributes) || array_diff_key($acquiredAttributes, $attributes)) {
            throw new \DomainException('Приобретённые и итоговые характеристики должны иметь одинаковые ключи.');
        }
        $this->rules->validateModifiers($modifiers);
        $this->validateAttackList($attacks, $modifiers);

        $stats = [];
        foreach ($this->rules->statNames('scalar') as $stat) {
            $applicable = array_values(array_filter($modifiers, fn (StatModifier $modifier) => $modifier->appliesTo($stat)));
            $stats[$stat] = ModifierCalculation::apply($this->rules->base($stat, $attributes),
                $applicable, $this->rules->fractional($stat));
        }

        $baseDamage = [];
        foreach ($this->rules->statNames('attack') as $stat) {
            $baseDamage[$stat] = $this->rules->base($stat, $attributes);
        }
        $calculatedAttacks = array_map(
            fn (array $attack) => $this->attacks->calculate($attack, $attributes, $modifiers), $attacks);

        return [
            'stats' => $stats,
            'base_damage' => $baseDamage,
            'attacks' => $calculatedAttacks,
            'maximum_damage' => $this->attacks->maximum($calculatedAttacks),
            'magic_circle' => $this->rules->magicCircle($acquiredAttributes),
            'inventory_weight' => $this->inventoryWeight($inventory),
            'skill_check_bonuses' => $this->skillCheckBonuses($skillLevels),
        ];
    }

    /** Вес берётся из конкретных экземпляров: количество × вес, без округления до целых килограммов. */
    private function inventoryWeight(array $inventory): CalculatedValue
    {
        if (!array_is_list($inventory)) {
            throw new \DomainException('Инвентарь должен быть списком.');
        }
        $parts = [];
        $seen = [];
        foreach ($inventory as $item) {
            if (!is_array($item) || !is_int($item['id'] ?? null) || $item['id'] < 1 || isset($seen[$item['id']])
                || !is_string($item['name'] ?? null) || trim($item['name']) === '') {
                throw new \DomainException('В инвентаре нужен уникальный экземпляр с названием.');
            }
            $seen[$item['id']] = true;
            $quantity = $item['quantity'] ?? 1;
            $rawWeight = $item['weight'] ?? 0;
            $weight = CalculatedValue::number($rawWeight, true);
            if (!is_int($quantity) || $quantity < 1 || $rawWeight < 0) {
                throw new \DomainException('Некорректное количество или вес предмета.');
            }
            $parts[] = ['value' => $quantity * $weight, 'source' => [
                'kind' => 'item', 'id' => $item['id'], 'label' => $item['name'],
                'quantity' => $quantity, 'unit_weight' => $weight,
            ]];
        }
        return CalculatedValue::sum($parts, true);
    }

    private function skillCheckBonuses(array $levels): array
    {
        $result = [];
        foreach ($levels as $key => $level) {
            if ((!is_int($key) && !is_string($key)) || (is_int($key) && $key < 1)
                || (is_string($key) && trim($key) === '') || !is_int($level)) {
                throw new \DomainException('Некорректное состояние навыка.');
            }
            $result[$key] = $this->rules->skillCheckBonus($level);
        }
        return $result;
    }

    /** Ошибка в области бонуса не должна молча превращаться в исчезнувшую прибавку. */
    private function validateAttackList(array $attacks, array $modifiers): void
    {
        if (!array_is_list($attacks)) {
            throw new \DomainException('Атаки должны быть списком.');
        }
        $ids = [];
        $stats = [];
        foreach ($attacks as $attack) {
            if (!is_array($attack) || !is_string($attack['id'] ?? null)
                || trim($attack['id']) === '' || in_array($attack['id'], $ids, true)) {
                throw new \DomainException('Варианты атак должны иметь уникальные идентификаторы.');
            }
            $ids[] = $attack['id'];
            if (!is_string($attack['mode'] ?? null)) {
                throw new \DomainException('Атаке нужен способ применения.');
            }
            $stats[$attack['id']] = $this->rules->attackStat($attack['mode']);
        }
        foreach ($modifiers as $modifier) {
            if ($modifier->attackId !== null && !in_array($modifier->attackId, $ids, true)) {
                throw new \DomainException('Бонус ссылается на отсутствующую атаку.');
            }
            if ($modifier->attackId !== null && $stats[$modifier->attackId] !== $modifier->target) {
                throw new \DomainException('Бонус относится к другому виду урона выбранной атаки.');
            }
        }
    }
}