<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Catalog\Perks;

use Maxcuso\Roleplay\Catalog\Shared\EffectBoundCatalog;
use Maxcuso\Roleplay\Catalog\Shared\AbilityValues;
use Maxcuso\Roleplay\Catalog\Shared\FieldValues;

/** Источник относится к конкретной выдаче персонажу, а не к определению перка. */
final class PerkCatalog extends EffectBoundCatalog
{
    protected const TABLE = 'perks';

    public function validate(array $input): array
    {
        FieldValues::allowed($input, ['name', 'description', 'enabled', 'sort_order', 'image', 'rarity', 'effects']);
        return FieldValues::common($input) + [
            'image' => AbilityValues::image($input['image'] ?? null),
            'rarity' => AbilityValues::option($input['rarity'] ?? 'common', AbilityValues::RARITIES, 'Редкость'),
        ];
    }
}