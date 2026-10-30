<?php

use Illuminate\Database\Schema\{Builder, Blueprint};

/** Содержимое — ссылки на знания, без копий их названий и игровых параметров. */
return [
    'up' => function (Builder $schema): void {
        $schema->table('roleplay_items', function (Blueprint $table): void {
            $table->text('literature_contents')->nullable();
            $table->boolean('consumed_on_learning')->default(false);
        });
    },
    'down' => function (Builder $schema): void {
        $schema->table('roleplay_items', fn (Blueprint $table) => $table->dropColumn(['literature_contents', 'consumed_on_learning']));
    },
];
