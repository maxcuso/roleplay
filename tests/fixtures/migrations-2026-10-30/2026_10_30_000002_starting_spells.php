<?php

use Illuminate\Database\Schema\{Builder, Blueprint};

/** Существующие заклинания сохраняют доступность обычного стартового выбора. */
return [
    'up' => function (Builder $schema): void {
        $schema->table('roleplay_spells', function (Blueprint $table): void {
            $table->boolean('is_starting')->default(true);
        });
    },
    'down' => function (Builder $schema): void {
        $schema->table('roleplay_spells', fn (Blueprint $table) => $table->dropColumn('is_starting'));
    },
];
