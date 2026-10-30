<?php
use Illuminate\Database\Schema\{Builder, Blueprint};

/** Цитата и иллюстрация хранятся отдельно от основного описания расы. */
return [
    'up' => function (Builder $schema): void {
        $schema->table('roleplay_races', function (Blueprint $table): void {
            $table->text('quote')->nullable();
            $table->string('image', 1000)->nullable();
        });
    },
    'down' => function (Builder $schema): void {
        $schema->table('roleplay_races', fn (Blueprint $table) => $table->dropColumn(['quote', 'image']));
    },
];
