<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Catalog\Races;

use Illuminate\Database\Query\Builder;
use Maxcuso\Roleplay\Catalog\Shared\AbstractCatalog;
use Maxcuso\Roleplay\Catalog\Shared\FieldValues;

/** Определение расы. Выдача бонусов персонажу будет отдельной операцией Rules/Creation. */
final class RaceCatalog extends AbstractCatalog
{
    protected const TABLE = 'races';
    protected const INTEGER_FIELDS = ['min_age', 'max_age'];
    protected const JSON_FIELDS = ['rules'];

    public function validate(array $input): array
    {
        FieldValues::allowed($input, ['name', 'description', 'enabled', 'sort_order', 'min_age', 'max_age', 'rules', 'rules_url']);
        $min = FieldValues::integer($input['min_age'] ?? null, 'Возраст от', 0, 1000000, true);
        $max = FieldValues::integer($input['max_age'] ?? null, 'Возраст до', 0, 1000000, true);
        if ($min !== null && $max !== null && $min > $max) FieldValues::invalid('Минимальный возраст превышает максимальный.');
        $url = FieldValues::text($input['rules_url'] ?? null, 'Ссылка на правила', 1000);
        if ($url !== null && (!filter_var($url, FILTER_VALIDATE_URL)
            || !in_array(strtolower(parse_url($url, PHP_URL_SCHEME) ?? ''), ['http', 'https'], true)
            || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null)) {
            FieldValues::invalid('Укажи полную HTTP/HTTPS-ссылку на правила без данных авторизации.');
        }
        return FieldValues::common($input) + [
            'rules_url' => $url,
            'min_age' => $min,
            'max_age' => $max,
            'rules' => json_encode((new RaceRules($this->store))->validate($input['rules'] ?? []), JSON_THROW_ON_ERROR),
        ];
    }

    protected function applyFilters(Builder $query, array $filters): void
    {
        FieldValues::allowed($filters, ['attribute']);
        if (!isset($filters['attribute']) || $filters['attribute'] === '') return;
        $slug = FieldValues::text($filters['attribute'], 'Характеристика', 100, true);
        if (!in_array($slug, array_column($this->store->references('attributes'), 'slug'), true)) {
            FieldValues::invalid('Неизвестная характеристика.');
        }
        // Поиск только среди фиксированных бонусов; JSON-путь передаётся параметром SQL.
        $query->whereRaw('COALESCE(JSON_EXTRACT(rules, ?), 0) <> 0', ['$."fixed_bonuses"."'.$slug.'"']);
    }
}