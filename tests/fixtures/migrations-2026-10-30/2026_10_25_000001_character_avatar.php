<?php
declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema): void {
        $schema->table('roleplay_characters', function (Blueprint $table): void {
            // Только имя обработанного файла. Произвольные URL в анкете не хранятся.
            $table->string('avatar', 100)->nullable();
        });
    },
    'down' => function (Builder $schema): void {
        $schema->table('roleplay_characters', fn (Blueprint $table) => $table->dropColumn('avatar'));
    },
];
