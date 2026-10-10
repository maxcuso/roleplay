<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Rules;

/** Один контракт для проверки предметов, редактора и подготовки атак. Здесь нет ID игровых владений. */
final class ItemAttackRules
{
    private static ?array $modes = null;

    /** Дальняя и ближняя атаки метательным оружием не создают два одинаковых варианта от Ловкости. */
    public static function modes(): array
    {
        return self::$modes ??= json_decode(
            file_get_contents(__DIR__.'/../../resources/rules/item-attacks.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }
}