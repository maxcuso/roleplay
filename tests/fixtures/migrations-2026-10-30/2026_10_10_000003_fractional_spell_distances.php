<?php
declare(strict_types=1);

use Illuminate\Database\Schema\{Blueprint, Builder};

// Только формат метров. Целые дистанции и отсутствие числа у «На себя»/«Касание» сохраняются.
return [
    'up' => function (Builder $schema): void {
        $schema->table('roleplay_spells', function (Blueprint $table): void {
            $table->decimal('distance_value', 10, 3)->unsigned()->nullable()->change();
        });
    },
    'down' => function (Builder $schema): void {
        if ($schema->getConnection()->table('roleplay_spells')->whereRaw('distance_value <> FLOOR(distance_value)')->exists()) {
            throw new RuntimeException('Перед откатом приведи дистанции заклинаний к целым метрам.');
        }
        $schema->table('roleplay_spells', function (Blueprint $table): void {
            $table->unsignedInteger('distance_value')->nullable()->change();
        });
    },
];
