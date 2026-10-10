<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Rules;

/** Формулы задаются конфигурацией, а не названиями перков, миграциями или классами иконок. */
final class StatRules
{
    public function __construct(
        private readonly array $configuration,
        private readonly DevelopmentRules $development,
    ) {
        $this->validateConfiguration();
    }

    public static function defaults(): self
    {
        return new self(require __DIR__.'/../../resources/rules/stats.php', DevelopmentRules::defaults());
    }

    public function statNames(?string $kind = null): array
    {
        return array_keys(array_filter($this->configuration['stats'],
            fn (array $definition) => $kind === null || $definition['kind'] === $kind));
    }

    public function fractional(string $stat): bool
    {
        return $this->definition($stat)['fractional'] ?? false;
    }

    /** Принимаем уже собранные уровни, включая расовые бонусы и применимые изменения характеристики. */
    public function validateAttributes(array $attributes, bool $withEffects = false): void
    {
        foreach ($attributes as $slug => $attribute) {
            if (!is_string($slug) || !$this->development->hasAttribute($slug) || !is_array($attribute)
                || !is_string($attribute['name'] ?? null) || trim($attribute['name']) === ''
                || !is_int($attribute['level'] ?? null) || $attribute['level'] < 1
                || $attribute['level'] > ($withEffects ? 1000000000 : $this->development->maximumAttributeLevel())) {
                throw new \DomainException('Некорректное состояние характеристики.');
            }
        }
        $required = array_column($this->configuration['stats'], 'attribute');
        $required[] = $this->configuration['magic_circle']['attribute'];
        if (array_diff($required, array_keys($attributes))) {
            throw new \DomainException('Для расчёта не хватает характеристик.');
        }
    }

    public function base(string $stat, array $attributes): CalculatedValue
    {
        $this->validateAttributes($attributes, true);
        $definition = $this->definition($stat);
        $slug = $definition['attribute'];
        $attribute = $attributes[$slug];
        $value = $definition['type'] === 'linear'
            ? $definition['offset'] + $definition['factor'] * $attribute['level']
            : $this->thresholdValue($definition['steps'], $attribute['level']);
        return CalculatedValue::sum([['value' => $value, 'source' => [
            'kind' => 'attribute', 'key' => $slug, 'level' => $attribute['level'],
            'label' => $attribute['name'].' '.$attribute['level'],
        ]]], $this->fractional($stat));
    }

    public function attackStat(string $mode): string
    {
        $stat = $this->configuration['attack_modes'][$mode] ?? null;
        if ($stat === null) {
            throw new \DomainException('Неизвестный способ атаки.');
        }
        return $stat;
    }

    public function magicCircle(array $attributes): int
    {
        $this->validateAttributes($attributes);
        $rule = $this->configuration['magic_circle'];
        return $this->thresholdValue($rule['steps'], $attributes[$rule['attribute']]['level']);
    }

    public function skillCheckBonus(int $level): int
    {
        if ($level < 0 || $level > $this->development->maximumSkillLevel()) {
            throw new \DomainException('Некорректный уровень навыка.');
        }
        // II даёт +2 всего. Предыдущие прибавки к проверке не суммируются.
        return $level;
    }

    /** Проверка цели общая для скалярного результата и отдельной атаки. */
    public function validateModifiers(array $modifiers): void
    {
        if (!array_is_list($modifiers)) {
            throw new \DomainException('Бонусы должны быть списком.');
        }
        foreach ($modifiers as $modifier) {
            if (!$modifier instanceof StatModifier) {
                throw new \DomainException('Калькулятор принимает подготовленные StatModifier.');
            }
            $definition = $this->definition($modifier->target);
            if ($modifier->condition === 'unarmed' && $modifier->target !== $this->attackStat('melee')) {
                throw new \DomainException('Безоружный бонус относится только к урону в ближнем бою.');
            }
            CalculatedValue::number($modifier->value, in_array($modifier->operation, ['percent', 'base_percent'], true) || $this->fractional($modifier->target));
            if ($modifier->operation === 'base_percent' && $definition['kind'] !== 'attack') {
                throw new \DomainException('Процент от базы применяется только к урону атаки.');
            }
            if ($modifier->operation === 'tiers' && (!EffectRules::damageTarget($modifier->target)
                || !is_int($modifier->value) || abs($modifier->value) > 12)) {
                throw new \DomainException('Ступени разрешены только для урона и защиты.');
            }
            if ($definition['kind'] !== 'attack' && ($modifier->attackId !== null || $modifier->condition !== 'always')) {
                throw new \DomainException('Условие отдельной атаки нельзя применить к общему параметру.');
            }
        }
    }

    private function definition(string $stat): array
    {
        $definition = $this->configuration['stats'][$stat] ?? null;
        if ($definition === null) {
            throw new \DomainException('Неизвестный параметр расчёта.');
        }
        return $definition;
    }

    /** Пороги заменяют предыдущий результат, а не добавляются друг к другу. */
    private function thresholdValue(array $steps, int $level): int
    {
        $result = 0;
        foreach ($steps as $minimum => $value) {
            if ($minimum > $level) {
                break;
            }
            $result = $value;
        }
        return $result;
    }

    private function validateConfiguration(): void
    {
        $stats = $this->configuration['stats'] ?? null;
        $modes = $this->configuration['attack_modes'] ?? null;
        $circle = $this->configuration['magic_circle'] ?? null;
        if (!is_array($stats) || !$stats || !is_array($modes) || !$modes || !is_array($circle)) {
            throw new \DomainException('Неполная конфигурация параметров.');
        }
        foreach ($stats as $name => $definition) {
            if (!is_string($name) || trim($name) === '' || !is_array($definition)) {
                throw new \DomainException('Некорректное определение параметра.');
            }
            $this->validateAttributeRule($definition);
            if (!in_array($definition['kind'] ?? null, ['scalar', 'attack'], true)
                || !is_bool($definition['fractional'] ?? false)) {
                throw new \DomainException('Некорректный вид параметра.');
            }
            if (($definition['type'] ?? null) === 'linear') {
                foreach (['offset', 'factor'] as $field) {
                    CalculatedValue::number($definition[$field] ?? null);
                }
                // Линейная база должна оставаться неотрицательной на всём разрешённом диапазоне.
                foreach ([1, $this->development->maximumAttributeLevel()] as $level) {
                    $base = CalculatedValue::number($definition['offset'] + $definition['factor'] * $level);
                    if ($base < 0) {
                        throw new \DomainException('Базовое значение параметра не может быть отрицательным.');
                    }
                }
            } elseif (($definition['type'] ?? null) === 'thresholds') {
                $this->validateSteps($definition['steps'] ?? null);
            } else {
                throw new \DomainException('Неизвестная формула параметра.');
            }
            if ($definition['kind'] === 'attack' && ($definition['fractional'] ?? false)) {
                throw new \DomainException('Урон хранится целым числом.');
            }
        }
        foreach ($modes as $mode => $stat) {
            if (!is_string($mode) || trim($mode) === '' || !is_string($stat)
                || !isset($stats[$stat]) || $stats[$stat]['kind'] !== 'attack') {
                throw new \DomainException('Некорректная привязка способа атаки.');
            }
        }
        $this->validateAttributeRule($circle);
        $this->validateSteps($circle['steps'] ?? null);
    }

    private function validateAttributeRule(array $definition): void
    {
        if (!is_string($definition['attribute'] ?? null) || !$this->development->hasAttribute($definition['attribute'])) {
            throw new \DomainException('В правиле указана неизвестная характеристика.');
        }
    }

    private function validateSteps(mixed $steps): void
    {
        if (!is_array($steps) || array_key_first($steps) !== 1) {
            throw new \DomainException('Таблица порогов должна начинаться с уровня 1.');
        }
        $previous = 0;
        foreach ($steps as $level => $value) {
            if (!is_int($level) || $level <= $previous || $level > $this->development->maximumAttributeLevel()
                || !is_int($value) || $value < 0) {
                throw new \DomainException('Некорректный порог параметра.');
            }
            CalculatedValue::number($value);
            $previous = $level;
        }
    }
}
