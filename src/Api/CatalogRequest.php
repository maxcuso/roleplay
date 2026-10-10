<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Api;

use Maxcuso\Roleplay\Catalog\Shared\FieldValues;

/** Транспортные параметры проверяются здесь, а поля формы — в своём справочнике. */
final class CatalogRequest
{
    public static function positive(mixed $value, string $label): int
    {
        if (is_string($value) && ctype_digit($value) && strlen($value) < 19) $value = (int) $value;
        return FieldValues::integer($value, $label, 1, PHP_INT_MAX);
    }

    public static function listing(array $query): array
    {
        $search = FieldValues::text($query['search'] ?? '', 'Поиск', 100) ?? '';
        $enabled = $query['enabled'] ?? '';
        if (!in_array($enabled, ['', '0', '1'], true)) FieldValues::invalid('Неизвестный фильтр статуса.');
        $filters = array_diff_key($query, array_flip(['catalog', 'id', 'search', 'enabled', 'page']));
        return [$search, $enabled === '' ? null : $enabled === '1',
            self::positive($query['page'] ?? 1, 'Страница'), $filters];
    }

    public static function body(mixed $body): array
    {
        if (!is_array($body)) throw new ApiProblem('invalid_request', 'Нужен объект запроса.', 400);
        return $body;
    }
}