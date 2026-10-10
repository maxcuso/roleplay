<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Catalog\Shared;

use Illuminate\Database\Query\Builder;

/** Навыки и владения связаны с характеристикой; их фильтр и проверка ссылки одинаковы. */
abstract class AttributeBoundCatalog extends AbstractCatalog
{
    protected const INTEGER_FIELDS = ['attribute_id'];

    protected function definitionFields(array $input): array
    {
        return FieldValues::common($input) + [
            'attribute_id' => AbilityValues::reference($this->store, 'attributes', $input['attribute_id'] ?? null),
        ];
    }

    protected function applyFilters(Builder $query, array $filters): void
    {
        FieldValues::allowed($filters, ['attribute']);
        $value = $filters['attribute'] ?? '';
        if ($value === '') {
            return;
        }
        if (!is_string($value) || !ctype_digit($value)) {
            FieldValues::invalid('Некорректный фильтр характеристики.');
        }
        $id = FieldValues::integer((int) $value, 'Характеристика', 1, PHP_INT_MAX);
        $query->where('attribute_id', $id);
    }
}