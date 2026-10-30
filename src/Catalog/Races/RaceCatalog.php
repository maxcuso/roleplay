<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Catalog\Races;

use Illuminate\Database\Query\Builder;
use Maxcuso\Roleplay\Catalog\Shared\AbstractCatalog;
use Maxcuso\Roleplay\Catalog\Shared\FieldValues;
use Maxcuso\Roleplay\Catalog\Shared\AbilityValues;

/** Определение расы. Выдача бонусов персонажу будет отдельной операцией Rules/Creation. */
final class RaceCatalog extends AbstractCatalog
{
    protected const TABLE = 'races';
    protected const INTEGER_FIELDS = ['min_age', 'max_age'];
    protected const JSON_FIELDS = ['rules'];

    public function validate(array $input): array
    {
        FieldValues::allowed($input, ['name', 'description', 'enabled', 'sort_order', 'min_age', 'max_age', 'rules', 'rules_url',
            'average_height', 'distinctive_traits', 'background_color', 'quote', 'image']);
        // Сохраняем ключи для совместимости JSON. Это диапазон продолжительности жизни,
        // а не минимальный возраст персонажа: при создании учитывается только max_age.
        $min = FieldValues::integer($input['min_age'] ?? null, 'Продолжительность жизни: от', 0, 1000000, true);
        $max = FieldValues::integer($input['max_age'] ?? null, 'Продолжительность жизни: до', 0, 1000000, true);
        if ($min !== null && $max !== null && $min > $max) FieldValues::invalid('Начало диапазона продолжительности жизни не должно превышать его конец. Исправьте границы диапазона');
        $color = FieldValues::text($input['background_color'] ?? null, 'Цвет подложки', 7);
        if ($color !== null) {
            $color = '#'.ltrim($color, '#');
            if (!preg_match('/^#[0-9a-f]{6}$/iD', $color)) FieldValues::invalid('Укажите цвет подложки в формате HEX из шести знаков, например #AABBCC');
        }
        $url = FieldValues::text($input['rules_url'] ?? null, 'Ссылка на правила', 1000);
        if ($url !== null && (!filter_var($url, FILTER_VALIDATE_URL)
            || !in_array(strtolower(parse_url($url, PHP_URL_SCHEME) ?? ''), ['http', 'https'], true)
            || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null)) {
            FieldValues::invalid('Укажите полную ссылку на правила с http:// или https://, без логина и пароля в адресе');
        }
        return FieldValues::common($input) + [
            'quote' => FieldValues::text($input['quote'] ?? null, 'Цитата', 20000),
            'image' => AbilityValues::image($input['image'] ?? null),
            'rules_url' => $url,
            'min_age' => $min,
            'max_age' => $max,
            // Рост — текст: допустимы диапазоны, единицы измерения и пояснения.
            'average_height' => FieldValues::text($input['average_height'] ?? null, 'Средний рост', 255),
            'distinctive_traits' => FieldValues::text($input['distinctive_traits'] ?? null, 'Отличительные черты', 20000),
            'background_color' => $color,
            'rules' => json_encode((new RaceRules($this->store))->validate($input['rules'] ?? []), JSON_THROW_ON_ERROR),
        ];
    }

    protected function applyFilters(Builder $query, array $filters): void
    {
        FieldValues::allowed($filters, ['attribute']);
        if (!isset($filters['attribute']) || $filters['attribute'] === '') return;
        $slug = FieldValues::text($filters['attribute'], 'Характеристика', 100, true);
        if (!in_array($slug, array_column($this->store->references('attributes'), 'slug'), true)) {
            FieldValues::invalid('Характеристика не найдена');
        }
        // Query Builder сам выбирает JSON-синтаксис MySQL, PostgreSQL или SQLite.
        // Отсутствующий ключ даёт NULL и не проходит условие, нулевой бонус тоже исключён.
        $query->where('rules->fixed_bonuses->'.$slug, '<>', 0);
    }
}
