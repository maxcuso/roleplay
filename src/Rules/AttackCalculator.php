<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Rules;

/** Урон одного оружия никогда не складывается с уроном соседнего оружия. */
final class AttackCalculator
{
    public function __construct(private readonly StatRules $rules) {}

    /**
     * @param array{id: string, mode: string, source: array, damage_units?: int, unarmed?: bool} $attack
     * source описывает оружие/заклинание/безоружную атаку; id различает варианты атак.
     * damage_units — добавка конкретного предмета к базовому урону, а не текст его описания.
     */
    public function calculate(array $attack, array $attributes, array $modifiers = []): array
    {
        $this->rules->validateModifiers($modifiers);
        $this->validateAttack($attack);
        $stat = $this->rules->attackStat($attack['mode']);
        $base = $this->rules->base($stat, $attributes);
        $parts = $base->parts;
        $itemDamage = $attack['damage_units'] ?? 0;
        if ($itemDamage > 0) {
            $parts[] = ['value' => $itemDamage, 'source' => $attack['source']];
        }
        $applicable = array_values(array_filter($modifiers, fn (StatModifier $modifier) =>
            $modifier->appliesTo($stat, $attack['id'], $attack['unarmed'] ?? false)));
        return [
            'id' => $attack['id'],
            'mode' => $attack['mode'],
            'stat' => $stat,
            'source' => $attack['source'],
            'damage' => ModifierCalculation::apply(CalculatedValue::sum($parts), $applicable, referenceBase: $base),
        ];
    }

    /** Для анкеты оставляем максимум отдельной атаки. При равенстве сохраняем первый вариант. */
    public function maximum(array $attacks): array
    {
        $result = array_fill_keys($this->rules->statNames('attack'), null);
        foreach ($attacks as $attack) {
            $stat = $attack['stat'];
            if (!array_key_exists($stat, $result) || !$attack['damage'] instanceof CalculatedValue) {
                throw new \DomainException('Некорректный результат атаки.');
            }
            if ($result[$stat] === null || $attack['damage']->value > $result[$stat]['damage']->value) {
                $result[$stat] = $attack;
            }
        }
        // null означает отсутствие переданных доступных атак, а не нулевой базовый урон.
        return $result;
    }

    /** Вызывается для каждой входящей атаки. Защита не расходуется и не меняет состояние. */
    public function remainingDamage(int $incoming, CalculatedValue $defense): CalculatedValue
    {
        CalculatedValue::number($incoming);
        if ($incoming < 0 || !is_int($defense->value)) {
            throw new \DomainException('Входящий и игнорируемый урон должны быть неотрицательными целыми числами.');
        }
        return CalculatedValue::sum([
            ['value' => $incoming, 'source' => ['kind' => 'attack', 'label' => 'Входящий урон']],
            ['value' => -$defense->value, 'source' => ['kind' => 'defense', 'label' => 'Игнорируемый урон']],
        ]);
    }

    private function validateAttack(array $attack): void
    {
        if (!is_string($attack['id'] ?? null) || trim($attack['id']) === ''
            || !is_string($attack['mode'] ?? null) || !is_array($attack['source'] ?? null)) {
            throw new \DomainException('Атаке нужны идентификатор, способ и источник.');
        }
        CalculatedValue::source($attack['source']);
        $damage = CalculatedValue::number($attack['damage_units'] ?? 0);
        $unarmed = $attack['unarmed'] ?? false;
        if ($damage < 0 || !is_bool($unarmed) || ($unarmed && ($attack['mode'] !== 'melee' || $damage !== 0))) {
            throw new \DomainException('Некорректный урон предмета или безоружная атака.');
        }
    }
}