<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Catalog\ItemTypes;

use Maxcuso\Roleplay\Catalog\Shared\AbstractCatalog;
use Maxcuso\Roleplay\Catalog\Shared\FieldValues;

/** Тип задаёт оформление и лимит экипировки. Виды атак определяются самим предметом. */
final class ItemTypeCatalog extends AbstractCatalog
{
    protected const TABLE = 'item_types';
    protected const INTEGER_FIELDS = ['equipment_limit'];

    public function validate(array $input): array
    {
        FieldValues::allowed($input, ['name', 'description', 'enabled', 'sort_order', 'icon', 'equipment_limit']);
        $common = FieldValues::common($input);
        // -1 запрещает экипировку; 0 снимает лимит; положительное число задаёт максимум.
        return $common + [
            'icon' => FieldValues::icon($input['icon'] ?? null),
            'equipment_limit' => FieldValues::integer($input['equipment_limit'] ?? 0, 'В экипировке', -1, 1000000),
        ];
    }
}