<?php

use Illuminate\Database\Schema\Builder;
use Illuminate\Database\Schema\Blueprint;

/** Справочные поля расы для редактора и будущего вывода в правилах.
 * min_age/max_age остаются прежними: теперь это диапазон продолжительности жизни. */
return [
    'up' => function (Builder $schema): void {
        $schema->table('roleplay_races', function (Blueprint $table): void {
            $table->string('average_height', 255)->nullable();
            $table->text('distinctive_traits')->nullable();
            $table->string('background_color', 7)->nullable();
        });
    },
    'down' => function (Builder $schema): void {
        $schema->table('roleplay_races', fn (Blueprint $table) =>
            $table->dropColumn(['average_height', 'distinctive_traits', 'background_color']));
    },
];
