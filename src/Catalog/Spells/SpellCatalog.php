<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Catalog\Spells;

use Illuminate\Database\Query\Builder;
use Maxcuso\Roleplay\Catalog\Shared\AbstractCatalog;
use Maxcuso\Roleplay\Catalog\Shared\AbilityValues;
use Maxcuso\Roleplay\Catalog\Shared\FieldValues;

final class SpellCatalog extends AbstractCatalog
{
    protected const TABLE = 'spells';
    protected const INTEGER_FIELDS = ['magic_path_id', 'level', 'radius_value', 'duration_value', 'damage_units', 'cast_value', 'cooldown_value', 'mana_cost', 'stamina_cost'];
    protected const DECIMAL_FIELDS = ['distance_value'];
    protected const BOOLEAN_FIELDS = ['is_starting'];

    public function validate(array $input): array
    {
        FieldValues::allowed($input, [
            'name', 'description', 'enabled', 'sort_order', 'magic_path_id', 'level', 'is_starting',
            'distance_mode', 'distance_value', 'radius_value', 'duration_value', 'duration_unit',
            'damage_units', 'cast_mode', 'cast_value', 'cast_unit', 'cooldown_value', 'cooldown_unit',
            'mana_cost', 'stamina_cost', 'other_cost',
        ]);
        $mode = AbilityValues::option($input['distance_mode'] ?? 'self', ['self', 'touch', 'distance'], 'Дистанция');
        $cast = AbilityValues::option($input['cast_mode'] ?? 'instant', ['instant', 'timed'], 'Время накладывания');
        return FieldValues::common($input) + [
            'magic_path_id' => AbilityValues::reference($this->store, 'magic_paths', $input['magic_path_id'] ?? null),
            'level' => FieldValues::integer($input['level'] ?? 1, 'Круг', 1, 100),
            'is_starting' => FieldValues::boolean($input['is_starting'] ?? true, 'Стартовое'),
            'distance_mode' => $mode,
            // Метры могут быть дробными (например, 0,5 м). Для себя/касания число не задаётся.
            'distance_value' => $mode === 'distance' ? AbilityValues::number($input['distance_value'] ?? 0, 'Дистанция', 1000000) : null,
            'radius_value' => FieldValues::integer($input['radius_value'] ?? 0, 'Радиус', 0, 1000000),
            'damage_units' => FieldValues::integer($input['damage_units'] ?? 0, 'Урон', 0, 1000000),
            'cast_mode' => $cast,
            'cast_value' => $cast === 'timed' ? FieldValues::integer($input['cast_value'] ?? 1, 'Время накладывания', 1, 1000000) : null,
            'cast_unit' => AbilityValues::option($input['cast_unit'] ?? 'turn', AbilityValues::UNITS, 'Единица времени'),
            'mana_cost' => FieldValues::integer($input['mana_cost'] ?? 0, 'Мана', 0, 1000000),
            'stamina_cost' => FieldValues::integer($input['stamina_cost'] ?? 0, 'Выносливость', 0, 1000000),
            'other_cost' => FieldValues::text($input['other_cost'] ?? null, 'Другие затраты', 500),
        ] + AbilityValues::time($input, 'duration') + AbilityValues::time($input, 'cooldown');
    }

    protected function applyFilters(Builder $query, array $filters): void
    {
        FieldValues::allowed($filters, ['path', 'level']);
        foreach (['path' => 'magic_path_id', 'level' => 'level'] as $key => $column) {
            if (!isset($filters[$key]) || $filters[$key] === '') continue;
            if (!is_string($filters[$key]) || !ctype_digit($filters[$key])) FieldValues::invalid('Не удалось применить фильтр заклинаний. Попробуйте обновить страницу');
            $value = (int) $filters[$key];
            FieldValues::integer($value, 'Фильтр', 1, $key === 'level' ? 100 : PHP_INT_MAX);
            $query->where($column, $value);
        }
    }
}
