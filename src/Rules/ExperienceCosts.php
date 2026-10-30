<?php
declare(strict_types=1);
namespace Maxcuso\Roleplay\Rules;

/** Цена зависит от получаемого уровня, а не от текущего. */
final class ExperienceCosts
{
    public static function forLevel(int $target, ?DevelopmentConfiguration $configuration = null): int
    {
        if ($configuration !== null) return $configuration->cost($target);
        if ($target < 2 || $target > DevelopmentRules::defaults()->maximumAttributeLevel()) {
            throw new \InvalidArgumentException('Уровень вне диапазона приобретения.');
        }
        $cost = 0;
        foreach (require __DIR__.'/../../resources/rules/experience.php' as $minimum => $value) {
            if ($target >= $minimum) $cost = $value;
        }
        return $cost;
    }
}
