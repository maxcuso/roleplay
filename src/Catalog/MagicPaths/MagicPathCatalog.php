<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Catalog\MagicPaths;

use Maxcuso\Roleplay\Catalog\Shared\AbstractCatalog;
use Maxcuso\Roleplay\Catalog\Shared\FieldValues;

/** Особые поля этого справочника проверяются здесь; хранение делегируется общему классу. */
final class MagicPathCatalog extends AbstractCatalog
{
    protected const TABLE = 'magic_paths';

    public function validate(array $input): array
    {
        FieldValues::allowed($input, ['name', 'description', 'enabled', 'sort_order', 'icon', 'color']);
        $common = FieldValues::common($input);
        $color = FieldValues::text($input['color'] ?? null, 'Цвет', 7);
        if ($color !== null) {
            $color = '#'.ltrim($color, '#');
            if (!preg_match('/^#[0-9a-f]{6}$/iD', $color)) FieldValues::invalid('Укажите цвет в формате HEX из шести знаков, например #AABBCC');
        }
        return $common + ['icon' => FieldValues::icon($input['icon'] ?? null), 'color' => $color];
    }
}