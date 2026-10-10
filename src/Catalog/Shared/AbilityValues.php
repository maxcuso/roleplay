<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Catalog\Shared;

/** Проверки общих полей способностей. Это ещё не игровые расчёты. */
final class AbilityValues
{
    public const UNITS = ['turn', 'minute', 'hour', 'day'];
    public const RARITIES = ['common', 'uncommon', 'rare', 'epic', 'legendary', 'special'];

    public static function option(mixed $value, array $options, string $label): string
    {
        if (!is_string($value) || !in_array($value, $options, true)) FieldValues::invalid($label.': неизвестное значение.');
        return $value;
    }

    public static function number(mixed $value, string $label, float $max = 9999999): float
    {
        if ((!is_float($value) && !is_int($value)) || !is_finite((float) $value) || $value < 0 || $value > $max) {
            FieldValues::invalid($label.': нужно неотрицательное число.');
        }
        return round((float) $value, 3);
    }

    public static function image(mixed $value): ?string
    {
        $image = FieldValues::text($value, 'Изображение', 1000);
        if ($image === null) return null;
        if (str_starts_with($image, '/assets/') && !str_contains($image, '..')
            && !str_contains($image, '\\') && !preg_match('/[\x00-\x20]/', $image)) return $image;
        $parts = parse_url($image);
        if (is_array($parts) && in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            && !empty($parts['host']) && !isset($parts['user']) && !isset($parts['pass'])) return $image;
        FieldValues::invalid('Изображение: нужен адрес HTTP(S) или путь /assets/.');
    }

    public static function reference(DefinitionStore $store, string $catalog, mixed $value): int
    {
        $id = FieldValues::integer($value, 'Связанная запись', 1, PHP_INT_MAX);
        $store->requireIds($catalog, [$id]);
        return $id;
    }

    public static function time(array $input, string $prefix): array
    {
        return [
            $prefix.'_value' => FieldValues::integer($input[$prefix.'_value'] ?? 0, 'Время', 0, 1000000),
            $prefix.'_unit' => self::option($input[$prefix.'_unit'] ?? 'turn', self::UNITS, 'Единица времени'),
        ];
    }
}