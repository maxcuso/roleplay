<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Catalog\Proficiencies;

use Maxcuso\Roleplay\Catalog\Shared\AttributeBoundCatalog;
use Maxcuso\Roleplay\Catalog\Shared\FieldValues;

/** Владение не имеет уровней и числового урона: параметры относятся к конкретному предмету. */
final class ProficiencyCatalog extends AttributeBoundCatalog
{
    protected const TABLE = 'weapon_proficiencies';

    public function validate(array $input): array
    {
        FieldValues::allowed($input, ['name', 'description', 'enabled', 'sort_order', 'attribute_id']);
        return $this->definitionFields($input);
    }
}