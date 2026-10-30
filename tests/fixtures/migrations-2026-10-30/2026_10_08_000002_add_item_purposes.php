<?php

use Illuminate\Database\Schema\Builder;
use Illuminate\Database\Schema\Blueprint;

/** Только признаки применения. Рецептов и изучения книг в этой версии ещё нет. */
return [
    'up' => function (Builder $schema): void {
        $schema->table('roleplay_items', function (Blueprint $table): void {
            $table->boolean('is_ingredient')->default(false);
            $table->boolean('is_literature')->default(false);
        });
    },
    'down' => function (Builder $schema): void {
        $schema->table('roleplay_items', fn (Blueprint $table) => $table->dropColumn(['is_ingredient', 'is_literature']));
    },
];
