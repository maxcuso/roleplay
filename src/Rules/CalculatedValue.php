<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Rules;

/** Числовой результат и его слагаемые. Здесь нет оформления, SQL или изменения персонажа. */
final class CalculatedValue implements \JsonSerializable
{
    private function __construct(
        public readonly int|float $value,
        public readonly array $parts,
    ) {}

    /**
     * @param list<array{value: int|float, source: array}> $parts
     * Отрицательные бонусы разрешены. Если сумма ниже minimum, добавляем явную поправку.
     * Дробные значения округляются до 0.001; minimum=null сохраняет отрицательные бонусы проверок.
     */
    public static function sum(array $parts, bool $fractional = false, ?int $minimum = 0): self
    {
        if (!array_is_list($parts)) {
            throw new \DomainException('Слагаемые должны быть списком.');
        }
        $normalized = [];
        $total = 0;
        foreach ($parts as $part) {
            if (!is_array($part) || !is_array($part['source'] ?? null)) {
                throw new \DomainException('Некорректное слагаемое расчёта.');
            }
            self::source($part['source']);
            $amount = self::number($part['value'] ?? null, $fractional);
            $normalized[] = ['value' => $amount, 'source' => $part['source']];
            $total += $amount;
        }
        $total = self::number($total, $fractional);
        if ($minimum !== null && $total < $minimum) {
            $normalized[] = ['value' => $minimum - $total, 'source' => [
                'kind' => 'limit', 'label' => 'Минимум параметра',
            ]];
            $total = $minimum;
        }
        return new self($total, $normalized);
    }

    /** Одна граница чисел для формул, бонусов и итогов; JSON должен оставаться точным и конечным. */
    public static function number(mixed $value, bool $fractional = false): int|float
    {
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || abs($value) > 1000000000) {
            throw new \DomainException('Число расчёта выходит за допустимые пределы.');
        }
        if (!$fractional && !is_int($value)) {
            throw new \DomainException('Для этого параметра требуется целое число.');
        }
        return $fractional ? round($value, 3) : $value;
    }

    public static function source(array $source): void
    {
        foreach (['kind', 'label'] as $field) {
            if (!is_string($source[$field] ?? null) || trim($source[$field]) === '') {
                throw new \DomainException('У слагаемого должны быть вид источника и подпись.');
            }
        }
    }

    public function jsonSerialize(): array
    {
        return ['value' => $this->value, 'parts' => $this->parts];
    }
}