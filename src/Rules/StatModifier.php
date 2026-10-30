<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Rules;

/** Подготовленный бонус. Resolver проверяет принадлежность, калькулятор — цель и величину. */
final class StatModifier
{
    public function __construct(
        public readonly string $target,
        public readonly int|float $value,
        public readonly array $source,
        public readonly ?string $attackId = null,
        public readonly string $condition = 'always',
        public readonly string $operation = 'add',
        public readonly ?string $stackGroup = null,
    ) {
        CalculatedValue::number($value, true);
        CalculatedValue::source($source);
        if (trim($target) === '' || ($attackId !== null && trim($attackId) === '')
            || !in_array($condition, ['always', 'unarmed'], true)
            || !in_array($operation, ['add', 'percent', 'base_percent', 'tiers'], true)) {
            throw new \DomainException('Проверьте цель, операцию и условие применения бонуса');
        }
    }

    public function contribution(): array
    {
        return ['value' => $this->value, 'source' => $this->source];
    }

    public function appliesTo(string $target, ?string $attackId = null, bool $unarmed = false): bool
    {
        return $this->target === $target
            && ($this->attackId === null || $this->attackId === $attackId)
            && ($this->condition === 'always' || $unarmed);
    }
}