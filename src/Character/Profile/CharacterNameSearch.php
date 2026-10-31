<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Character\Profile;

use Illuminate\Database\Query\Builder;

/** Поиск по частям имени без функций конкретной СУБД. Все слова должны совпасть. */
final class CharacterNameSearch
{
    public static function apply(Builder $query, string $search): void
    {
        $words = preg_split('/\s+/u', trim($search), -1, PREG_SPLIT_NO_EMPTY);
        foreach ($words ?: [] as $word) {
            // Явный стандартный ESCAPE сохраняет буквальный поиск %, _ и ! на всех СУБД.
            $literal = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $word).'%';
            $query->where(function (Builder $names) use ($literal): void {
                $names->whereRaw("first_name LIKE ? ESCAPE '!'", [$literal])
                    ->orWhereRaw("last_name LIKE ? ESCAPE '!'", [$literal]);
            });
        }
    }
}
