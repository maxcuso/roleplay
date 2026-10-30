<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Catalog\Attributes;

use Maxcuso\Roleplay\Catalog\Shared\AbstractCatalog;
use Maxcuso\Roleplay\Catalog\Shared\FieldValues;

/** Особые поля этого справочника проверяются здесь; хранение делегируется общему классу. */
final class AttributeCatalog extends AbstractCatalog
{
    protected const TABLE = 'attributes';

    public function validate(array $input): array
    {
        FieldValues::allowed($input, ['name', 'description', 'enabled', 'sort_order', 'icon']);
        $common = FieldValues::common($input);
        return $common + ['icon' => FieldValues::icon($input['icon'] ?? null)];
    }
}