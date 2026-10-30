<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Catalog\Shared;

use Maxcuso\Roleplay\Api\ApiProblem;

/** Небольшие проверки общих полей. Специальные правила остаются в справочниках. */
final class FieldValues
{
    public static function allowed(array $input, array $fields): void
    {
        if (array_diff(array_keys($input), $fields)) {
            self::invalid('В форме есть неизвестные поля. Проверьте названия полей и уберите неподдерживаемые');
        }
    }

    public static function text(mixed $value, string $label, int $max = 100, bool $required = false): ?string
    {
        if ($value !== null && !is_string($value)) self::invalid($label.': укажите текст');
        $text = trim($value ?? '');
        if ($required && $text === '') self::invalid('Заполните поле «'.$label.'»');
        if (mb_strlen($text) > $max) self::invalid($label.': сократите значение до допустимой длины');
        return $text === '' ? null : $text;
    }

    public static function integer(mixed $value, string $label, int $min, int $max, bool $nullable = false): ?int
    {
        if ($value === null && $nullable) return null;
        if (!is_int($value) || $value < $min || $value > $max) {
            self::invalid($label.': укажите целое число в допустимых пределах');
        }
        return $value;
    }

    public static function boolean(mixed $value, string $label): bool
    {
        if (!is_bool($value)) self::invalid($label.': укажите значение переключателя — включено или выключено');
        return $value;
    }

    public static function icon(mixed $value): ?string
    {
        $icon = self::text($value, 'Иконка');
        if ($icon !== null && !preg_match('/^[a-zA-Z0-9_-]+(?: +[a-zA-Z0-9_-]+)*$/D', $icon)) {
            self::invalid('Укажите классы иконки Font Awesome без HTML-разметки');
        }
        return $icon;
    }

    public static function common(array $input): array
    {
        return [
            'name' => self::text($input['name'] ?? null, 'Название', 100, true),
            'description' => self::text($input['description'] ?? null, 'Описание', 20000),
            'enabled' => self::boolean($input['enabled'] ?? false, 'Статус'),
            'sort_order' => self::integer($input['sort_order'] ?? 0, 'Порядок', -1000000, 1000000),
        ];
    }

    public static function invalid(string $message): never
    {
        throw new ApiProblem('invalid_catalog_data', $message);
    }
}